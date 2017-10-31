<?php declare(strict_types=1);

namespace RPQ\Queue;

use Amp\ByteStream\Message;
use Amp\Loop;
use Amp\Process\Process;
use Exception;
use RPQ\Client;
use Symfony\Component\Console\Output\OutputInterface;

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
     * @var OutputInterface $output
     */
    private $output;

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
     * Constructor
     * @param Client $client
     * @param OutputInterface $output
     * @param array $config
     */
    public function __construct(Client $client, OutputInterface $output, array $config = [], array $args = [])
    {
        $this->client = $client;
        $this->config = $config;
        $this->output = $output;
        $this->args = $args;
    }

    /**
     * Starts an Amp\Loop event loop and begins polling Redis for new jobs
     * @return void
     */
    public function start()
    {
        Loop::run(function () {
            $this->output->writeln(sprintf("[{$this->args['queueName']}] RPQ is now started, and is listening for new jobs every %d ms", $this->config['poll_interval']));
            Loop::repeat($msInterval = $this->config['poll_interval'], function ($watcherId, $callback) {
                // Only allow `max_jobs` to run
                if (count($this->processes) === $this->config['max_jobs']) {
                    return;
                }

                // ZPOP a job from the priority queue
                $id = $this->client->pop();
                if ($id !== null) {
                    // Spawn a new worker process to handle the job
                    $command = "./rpq worker -c {$this->args['configFile']} --id {$id } --name {$this->args['queueName']}";

                    $process = new Process($command);
                    $process->start();
                    
                    // Grab the PID and push it onto the process stack
                    $pid = $process->getPid();
                    if ($this->args['debug']) {
                        $this->output->writeln("[{$this->args['queueName']}] Starting worker with PID: {$pid} | $command");    
                    }

                    $this->processes[$pid] = null;

                    // Stream any output from the worker in realtime
                    $stream = $process->getStdout();
                    while ($chunk = yield $stream->read()) {
                        $this->output->writeln("[{$this->args['queueName']}][{$id}] {$chunk}");
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

        if ($this->args['debug']) {
            $this->output->writeln("[{$this->args['queueName']}][{$id}] PID: {$pid} ended with exit code {$code}");
        }

        // If the job ended successfully, remove the data from redis
        if ($code === 0) {
            if ($this->args['debug']) {
                $this->output->writeln("[{$this->args['queueName']}][{$id}] is now complete, and has been removed from Redis");
            }
            $this->client->getRedis()->hdel($id);
            return;
        } else {
            // If the job didn't end successfully, requeue it if necessary
            $retry = (int)$jobDetails['retry'];
            if ($retry > 0) {
                if ($this->args['debug']) {
                    $this->output->writeln("[{$this->args['queueName']}][{$id}] Rescheduling Job");
                }
                // If a retry is specified, repush the job back onto the queue with the same Job ID
                $this->client->push($jobDetails['workerClass'], $jobDetails['args'], $retry - 1, (float)$jobDetails['priority'], $this->args['queueName'], $jobId);
                
                return;
            } else {
                if ($this->args['debug']) {
                    $this->output->writeln("[{$this->args['queueName']}][{$id}] is now complete, and has been removed from Redis (out of retries)");
                }
                $this->client->getRedis()->hdel($id);
                return;
            }
        }

        return;
    }
}