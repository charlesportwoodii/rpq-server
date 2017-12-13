<?php declare(strict_types=1);

namespace RPQ\Server\Process;

use Amp\ByteStream\Message;
use Amp\Loop;
use Amp\Process\Process;
use Exception;
use Monolog\Logger;
use RPQ\Client;
use RPQ\Queue;
use RPQ\Server\Process\Handler\JobHandler;
use RPQ\Server\Process\Handler\SignalHandler;

/**
 * Dispatcher polls Redis for new jobs, and then starts the requested job
 * @package RPQ\Queue
 */
final class Dispatcher
{
    /**
     * @var Client $client
     */
    private $client;

    /*
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
     * @var array $process
     */
    private $processes = [];

    /**
     * @var Logger $logger
     */
    private $logger;

    /**
     * @var Queue $queue
     */
    private $queue;

    /**
     * @var SignalHandler $signalHandler
     */
    private $signalHandler;

    /**
     * @var JobHandler $jobHandler
     */
    private $jobHandler;

    /**
     * @var bool $isRunning;
     */
    private $isRunning = true;

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
     * @param Client $client
     * @param Logger $this->logger
     * @param array $config
     * @param array $args
     */
    public function __construct(Client $client, Logger $logger, Queue $queue, array $config = [], array $args = [])
    {
        $this->client = $client;
        $this->config = $config;
        $this->args = $args;
        $this->logger = $logger;
        $this->queue = $queue;
        $this->signalHandler = new SignalHandler(
            $this->logger,
            $this->client,
            $this->config,
            $this->queue
        );
        $this->jobHandler = new JobHandler(
            $this->client, 
            $this->logger,
            $this->queue
        );
    }

    /**
     * Starts an Amp\Loop event loop and begins polling Redis for new jobs
     * @return void
     */
    public function start()
    {
        Loop::run(function () {
            $this->logger->info(sprintf("RPQ is now started, and is listening for new jobs every %d ms", $this->config['poll_interval']), [
                'queue' => $this->queue->getName()
            ]);
            
            // Register signal handling
            foreach ($this->signals as $signal => $fn) {
                $this->logger->debug('Registering signal', [
                    'signal' => $signal,
                    'fn' => $fn
                ]);

                Loop::onSignal($signal, function() use ($fn) {
                    if (!$this->isRunning) {
                        return;
                    }
                    $this->isRunning = false;
                    return $this->signalHandler->$fn($this->processes);
                });
            }
            
            // Main polling loop
            Loop::repeat($msInterval = $this->config['poll_interval'], function ($watcherId, $callback) {
                // If a signal has been recieved to stop running, allow the process pool to drain completely before shutting down
                if (!$this->isRunning) {
                    if (count($this->processes) === 0) {
                        Loop::cancel($watcherId);
                        exit(0);
                    }
                    return;
                }

                // Pushes scheduled jobs onto the main queue
                $this->queue->rescheduleJobs($this->queue->getName(), (string)time());

                // Only allow `max_jobs` to run
                if (count($this->processes) === $this->config['max_jobs']) {
                    return;
                }

                // ZPOP a job from the priority queue
                $job = $this->queue->pop();

                if ($job !== null) {
                    // Spawn a new worker process to handle the job
                    $command = sprintf('exec %s %s --jobId=%s --name=%s',
                        ($this->config['process']['script'] ?? $_SERVER["SCRIPT_FILENAME"]),
                        $this->config['process']['command'],
                        $job->getId(),
                        $this->queue->getName()
                    );

                    if ($this->config['process']['config'] === true) {
                        $command .= " --config={$this->args['configFile']}";
                    }              

                    $process = new Process($command);
                    $process->start();
                    
                    // Grab the PID and push it onto the process stack
                    $pid = $process->getPid();
                    $this->logger->info('Started worker', [
                        'pid' => $pid,
                        'command' => $command,
                        'id' => $job->getId(),
                        'queue' => $this->queue->getName()
                    ]);

                    $this->processes[$pid] = [
                        'process' => $process,
                        'id' => $job->getId()
                    ];

                    // Stream any output from the worker in realtime
                    $stream = $process->getStdout();
                    while ($chunk = yield $stream->read()) {
                        $this->logger->info($chunk, [
                            'pid' => $pid,
                            'jobId' => $job->getId(),
                            'queue' => $this->queue->getName()
                        ]);
                    }

                    // When the job is done, it will emit an exit status code
                    $code = yield $process->join();
                    
                    $this->jobHandler->exit($job->getId(), $pid, $code);
                    unset($this->processes[$pid]);
                }
            });
        });
    }
}