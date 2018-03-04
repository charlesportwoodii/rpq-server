<?php declare(strict_types=1);

namespace RPQ\Server\Process\Handler;

use Amp\Loop;
use Amp\Deferred;
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
        SIGHUP => 'reload'
    ];

    /**
     * Constructor
     *
     * @param Logger $logger
     * @param Client $client
     * @param array $config
     * @param string $queue
     */
    public function __construct(Logger $logger, Client $client, array $config, Queue $queue, array $args = [])
    {
        $this->logger = $logger;
        $this->client = $client;
        $this->config = $config;
        $this->queue = $queue;
        $this->args = $args;
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
     * 
     * @param Dispatcher $processes
     * @param int $signal
     * @return Promise
     */
    public function handle(Dispatcher &$dispatcher, int $signal)
    {
        $deferred = new Deferred;

        // Only call a signal handler if it is registered with this class
        if (isset($this->signals[$signal])) {
            // Bind the processes that were passed to a local object
            $this->dispatcher = $dispatcher;

            // Grab the method name to call
            $fn = $this->signals[$signal];

            // Call the method
            $result = $this->$fn();

            // Need to set a watcher for PID's on non-shutdown tasks
            // @todo

            // Set a 1s loop timer to check that all processes are dead before we exit from this method
            // We do this to avoid starting RPQ before jobs the job state is reset to avoid running the same job
            // within this RPQ server instance and the new one that will be spanwed, as we do not want the same job running 
            // multiple times
            Loop::repeat($msDelay = 1000, function ($watcherId, $callback) use ($deferred, $signal, $result) {
                if (count($this->dispatcher->getProcesses()) === 0) {
                    Loop::cancel($watcherId);  
                    // If the signal we're handling is a RELOAD signal, and it returned successfully
                    if ($signal === SIGHUP && $result === 0) {
                        // Spawn a detached RPQ instance
                        $pid = $this->spawnRpqInstance(false);
                        $this->logger->debug('New RPQ process has been started.', [
                            'pid' => $pid
                        ]);
                    }
                    return $deferred->resolve($result);
                }
            });
        } else {
            // By default resolve our promise to null immediately
            $deferred->resolve(null);
        }

        // Return a promise to be handled in the dispatcher
        return $deferred->promise();
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
    public function terminate()
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
    public function quit()
    {
        $this->logger->info('Recieved SIGQUIT signal. Running graceful shutdown sequence.');
        $this->shutdown(SIGTERM);
        return null;
    }

    /**
     * Reload
     * 
     * @return integer
     */
    public function reload()
    {
        $this->logger->info('Recieved SIGHUP signal.');
        $test = $this->spawnRpqInstance(true);

        if ($test === true) {
            $this->logger->debug('Self test passes. Running graceful shutdown sequence.');
            return 0;
        } else {
            $this->logger->debug('Self test failed. Aborting reload');
            return 1;
        }
    }

    /**
     * Executes a self test by running `./rpq queue -t -c <config_file>.
     *   This method is blocking until the self test finishes, and the 
     *   self test will be run within a separate process space.
     * 
     * @return mixed
     */
    private function spawnRpqInstance($withTest = false)
    {
        $command = sprintf('%s queue -c %s',
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

        return $process->getPid();
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
        foreach($this->dispatcher->getProcesses() as $pid => $process) {
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
                    Loop::delay($msDelay = ((int)$this->config['deadline_timeout'] * 1000), function($watcherId, $callback) use ($process, $pid) {
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