<?php declare(strict_types=1);

namespace RPQ\Server\Process\Handler;

use Amp\Deferred;
use Amp\Failure;
use Amp\Loop;
use Amp\Success;
use Exception;
use Monolog\Logger;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use RPQ\Client;
use RPQ\Queue;
use RPQ\Server\Process\Dispatcher;
use RPQ\Server\Process\Handler\JobHandler;

final class SignalHandler
{
    /**
     * @var Client $client
     */
    private $client;

    /**
     * @var Logger $this->logger
     */
    private $logger;

    /**
     * @var array $config
     */
    private $config;

    /**
     * @var array $args
     */
    private $args = [
        'configFile' => ''
    ];

    /**
     * @var string $queue
     */
    private $queue;

    /**
     * @var Dispatcher $process
     */
    private $dispatcher;

    /**
     * @var JobHandler $jobHandler
     */
    private $jobHandler;

    /**
     * @var array $signals
     */
    private $signals = [
        SIGTERM => 'terminate',
        SIGINT => 'terminate',
        SIGQUIT => 'quit',
        SIGHUP => 'reload',
        SIGUSR1 => 'reserved',
        SIGUSR2 => 'reserved'
    ];

    private $handlers = [];

    /**
     * Constructor
     * @param Dispatcher $dispatcher
     */
    public function __construct(Dispatcher &$dispatcher)
    {
        $this->dispatcher = $dispatcher;
        $this->logger = $this->dispatcher->getLogger();
        $this->client = $this->dispatcher->getClient();
        $this->config = $this->dispatcher->getConfig();
        $this->queue = $this->dispatcher->getQueue();
        $this->args = $this->dispatcher->getArgs();

        $this->jobHandler = new JobHandler(
            $this->client,
            $this->logger,
            $this->queue
        );
    }

    /**
     * Returns signals that should be handled
     * @return array
     */
    public function getSignals()
    {
        return \array_keys($this->signals);
    }

    /**
     * Handles incoming signals
     * @param Dispatcher $processes
     * @param int $signal
     * @return Promise
     */
    public function handle(int $signal)
    {
        // If this signal is already being handled, return a Failure Promise and don't do anything
        if (isset($this->handlers[$signal]) && $this->handlers[$signal] === true) {
            return new Failure(new Exception('Signal is already being processed.'));
        }

        // Indicate that we're handling this signal to block repeat signals
        $this->handlers[$signal] = true;

        // Disable processing of new jobs while we handle the signal
        $this->dispatcher->setIsRunning(false);

        // Call the signal handler from the signal mapping
        $fn = $this->signals[$signal];
        $result = $this->$fn();

        // $result is either going to be `null` or a boolean value
        // If it's `true`, then skip the shutdown sequence
        if ($result === true) {
            unset($this->handlers[$signal]);
            return new Success($result);
        }

        // Wait until all processes have ended before resolving the promise
        $deferred = new Deferred;
        Loop::repeat(1000, function ($watcherId, $callback) use ($deferred, $signal, $result) {
            if (count($this->dispatcher->getProcesses()) === 0) {
                Loop::cancel($watcherId);
                unset($this->handlers[$signal]);
                return $deferred->resolve($result);
            }
        });

        return $deferred->promise();
    }

    /**
     * Placeholder for future signals that we may want to use
     * @return true
     */
    private function reserved()
    {
        $this->logger->info('This signal is reserved for future use.');
        return true;
    }

    /**
     * Fast Shutdown
     *
     * Send CTRL^C (SIGINT) or SIGTERM to initiate a fast shutdown of RPQ
     * During fast shutdown, RPQ will send a SIGKILL signal to all child processes
     * Then push them back onto the priority queue before exiting.
     *
     * This may result in jobs being reprocessed, as the jobs will not have
     * an opportunity to cleanly exit and shutdown themselves.
     *
     * @return null
     */
    private function terminate()
    {
        $this->logger->info('Recieved SIGTERM signal. Running fast shutdown sequence.');
        $this->shutdown(SIGKILL);
        return null;
    }

    /**
     * Graceful Shutdown
     *
     * Send a SIGQUIT to intiate a graceful shutdown of RPQ and all child processes.
     * During graceful shutdown, RPQ will send a SIGTERM signal to all child processes
     * and allow them to opportunity to either finish working, or to cleanly shutdown themselves.
     *
     * Jobs that do not complete, or return a non-zero exit status code will be requeued.
     * Jobs that do complete will not be requeued.
     *
     * @return null
     */
    private function quit()
    {
        $this->logger->info('Recieved SIGQUIT signal. Running graceful shutdown sequence.');
        $this->shutdown(SIGTERM);
        return null;
    }

    /**
     * Graceful reload
     *
     * Runs a self test to verify that the new RPQ configuration is valid.
     * If the configuration is valid, RPQ will spawn a new RPQ instance to begin job processing
     * If the configuration is not valid, RPQ will report the error and will continue processing jobs
     *
     * @return integer
     */
    private function reload()
    {
        $this->logger->info('Recieved SIGHUP signal.');
        $test = $this->spawnRpqInstance(true);

        if ($test === true) {
            $this->logger->debug('Self test passes. Running graceful shutdown sequence.');
            $pid = $this->spawnRpqInstance(false);
            $this->logger->info('New RPQ process has been started.', [
                'pid' => $pid
            ]);
        } else {
            $this->logger->debug('Self test failed. Aborting reload.');
            $this->dispatcher->setIsRunning(true);
        }

        return null;
    }

    /**
     * Executes a self test by running `./rpq queue -t -c <config_file>.
     * This method is blocking until the self test finishes, and the
     * self test will be run within a separate process space.
     *
     * @return mixed
     */
    private function spawnRpqInstance($withTest = false)
    {
        $command = sprintf(
            '%s queue -c %s',
            $_SERVER["SCRIPT_FILENAME"],
            $this->args['configFile']
        );

        if ($withTest) {
            $command = "exec {$command} -t";
        } else {
            $command = "exec {$command} > /dev/null 2>&1 &";
        }

        // Spawns a detached process
        $process = new Process($command);
        $process->disableOutput();
        $process->start();

        if ($withTest) {
            $process->wait();
            $code = $process->getExitCode();

            return $code === 0;
        }

        return yield $process->getPid();
    }

    /**
     * Interal shutdown function
     *
     * @param integer $signal
     * @return void
     */
    private function shutdown($signal = 9)
    {
        // Iterate through all the existing processes, and send the appropriate signal to the process
        foreach ($this->dispatcher->getProcesses() as $pid => $process) {
            $this->logger->debug('Sending signal to process', [
                'signal' => $signal,
                'pid' => $pid,
                'jobId' => $process['id'],
                'queue' => $this->queue->getName()
            ]);

            /**
             * $process['process']->signal($signal)
             * Amp\Process\Process::signal doesn't actually send signals correctly, and is not cross platform
             * Use posix_kill to actually send the signal to the process for handling
             */
            \posix_kill($pid, $signal);

            // If a signal other than SIGKILL was sent to the process, create a deadline timeout and force kill the process if it's still alive after the deadline
            if ($signal !== 9) {
                if ($this->config['deadline_timeout'] !== null) {
                    Loop::delay(((int)$this->config['deadline_timeout'] * 1000), function ($watcherId, $callback) use ($process, $pid) {
                        if ($process['process']->isRunning()) {
                            $this->logger->info('Process has exceeded deadline timeout. Killing', [
                                'pid' => $pid,
                                'jobId' => $process['id'],
                                'queue' => $this->queue->getName()
                            ]);

                            // Send SIGKILL to the process
                            \posix_kill($pid, SIGKILL);
                        }
                    });
                }
            }
        }
    }
}
