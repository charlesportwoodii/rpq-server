<?php declare(strict_types=1);

namespace RPQ\Queue;

use Amp\ByteStream\Message;
use Amp\Loop;
use Amp\Process\Process;
use Exception;
use Monolog\Logger;
use RPQ\Client;

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
        'queueName' => 'default',
        'configFile' => ''
    ];

    /**
     * @var array $process
     */
    private $processes = [];

    /**
     * @var Logger $this->logger
     */
    private $logger;

    /**
     * Constructor
     * @param Client $client
     * @param Logger $this->logger
     * @param array $config
     * @param array $args
     */
    public function __construct(Client $client, Logger $logger, array $config = [], array $args = [])
    {
        $this->client = $client;
        $this->config = $config;
        $this->args = $args;
        $this->logger = $logger;
    }

    /**
     * Starts an Amp\Loop event loop and begins polling Redis for new jobs
     * @return void
     */
    public function start()
    {
        Loop::run(function () {
            $this->logger->info(sprintf("RPQ is now started, and is listening for new jobs every %d ms", $this->config['poll_interval']), [
                'queueName' => $this->args['queueName']
            ]);

            Loop::repeat($msInterval = $this->config['poll_interval'], function ($watcherId, $callback) {
                // Only allow `max_jobs` to run
                if (count($this->processes) === $this->config['max_jobs']) {
                    return;
                }

                // ZPOP a job from the priority queue
                $id = $this->client->pop();
                if ($id !== null) {
                    // Spawn a new worker process to handle the job
                    $command = "{$_SERVER["SCRIPT_FILENAME"]} worker -c {$this->args['configFile']} --id {$id } --name {$this->args['queueName']}";                    

                    $process = new Process($command);
                    $process->start();
                    
                    // Grab the PID and push it onto the process stack
                    $pid = $process->getPid();
                    $this->logger->info('Started worker', [
                        'pid' => $pid,
                        'command' => $command,
                        'queueName' => $this->args['queueName']
                    ]);

                    $this->processes[$pid] = null;

                    // Stream any output from the worker in realtime
                    $stream = $process->getStdout();
                    while ($chunk = yield $stream->read()) {
                        $this->logger->info($chunk, [
                            'pid' => $pid,
                            'jobId' => $id,
                            'queueName' => $this->args['queueName']
                        ]);
                    }

                    // When the job is done, it will emit an exit status code
                    $code = yield $process->join();

                    $this->handleProcessExit($id, $pid, $code);
                }
            });
        });
    }

    /**
     * Handles process end, and requeuing if necessary
     *
     * @param string $id
     * @param int $pid
     * @param int $code
     * @return void
     */
    private function handleProcessExit($id, $pid, $code)
    {
        // Remove the job from the process queue so new jobs can run
        unset($this->processes[$pid]);

        $hash = explode(':', $id);
        $jobId = $hash[count($hash) - 1];
        $jobDetails = $this->client->getJobById($jobId);

        $this->logger->info('Job ended', [
            'exitCode' => $code,
            'pid' => $pid,
            'jobId' => $id,
            'queueName' => $this->args['queueName']
        ]);

        // If the job ended successfully, remove the data from redis
        if ($code === 0) {
            $this->logger->info('Job succeeded and is now complete', [
                'exitCode' => $code,
                'pid' => $pid,
                'jobId' => $id,
                'queueName' => $this->args['queueName']
            ]);

            $this->client->getRedis()->hdel($id);
            return;
        } else {
            // If the job didn't end successfully, requeue it if necessary
            $retry = (int)$jobDetails['retry'];
            if ($retry > 0) {
                $this->logger->info('Rescheduling job due to previous failure', [
                    'exitCode' => $code,
                    'pid' => $pid,
                    'jobId' => $id,
                    'queueName' => $this->args['queueName']
                ]);

                // If a retry is specified, repush the job back onto the queue with the same Job ID
                $this->client->push($jobDetails['workerClass'], $jobDetails['args'], $retry - 1, (float)$jobDetails['priority'], $this->args['queueName'], $jobId);
                
                return;
            } else {
                $this->logger->info('Job failed', [
                    'exitCode' => $code,
                    'pid' => $pid,
                    'jobId' => $id,
                    'queueName' => $this->args['queueName']
                ]);

                $this->client->getRedis()->hdel($id);
                return;
            }
        }

        return;
    }
}