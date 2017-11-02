<?php declare(strict_types=1);

namespace RPQ\Queue\Handler;

use Amp\Loop;
use Exception;
use Monolog\Logger;
use RPQ\Client;
use RPQ\Queue\Handler\JobHandler;

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
     * @var string $queue
     */
    private $queue;

    /**
     * @var array $process
     */
    private $processes = [];

    /**
     * @var JobHandler $jobHandler
     */
    private $jobHandler;

    /**
     * Constructor
     *
     * @param Logger $logger
     * @param Client $client
     * @param string $queue
     */
    public function __construct(Logger $logger, Client $client, $queue)
    {
        $this->logger = $logger;
        $this->client = $client;
        $this->queue = $queue;
        $this->jobHandler = new JobHandler(
            $this->client,
            $this->logger,
            $this->queue
        );
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
     * @param string $watcherId
     * @param array $processes
     * @return 0
     */
    public function terminate($watcherId, array $processes)
    {
        $this->logger->info('Recieved SIGTERM signal. Running fast shutdown sequence');
        $this->processes = $processes;
        $this->shutdown(9, $watcherId, true);
        exit(0);
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
     * @param string $watcherId
     * @param array $processes
     * @return 0
     */
    public function quit($watcherId, array $processes)
    {
        $this->logger->info('Recieved SIGQUIT signal. Running graceful shutdown sequence');
        $this->processes = $processes;
        $this->shutdown(15, $watcherId);
        exit(0);
    }

    /**
     * Reload
     * 
     * @param string $watcherId
     * @param array $processes
     * @return 0
     */
    public function reload($watcherId, array $processes)
    {
        $this->processes = $processes;
        exit(0);
    }

    /**
     * Interal shutdown function
     *
     * @param integer $signal
     * @param string $watcherId
     * @return void
     */
    private function shutdown($signal = 9, $watcherId)
    {
        // Disable RPQ polling of Redis
        Loop::disable($watcherId);

        // Iterate through all the existing processes, and send the appropriate signal to the process
        foreach($this->processes as $pid => $process) {
            $this->logger->debug('Sending signal to process', [
                'signal' => $signal,
                'pid' => $pid,
                'jobId' => $process['id'],
                'queue' => $this->queue
            ]);
            $process['process']->signal($signal);

            // Handle the job exit
            $this->jobHandler->exit($process['id'], $pid, -1, true);
            unset($this->processes[$pid]);
        }

        // Cancel the main outer loop completely
        return Loop::cancel($watcherId);
    }
}