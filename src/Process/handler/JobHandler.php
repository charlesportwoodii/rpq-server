<?php declare(strict_types=1);

namespace RPQ\Server\Process\Handler;

use Monolog\Logger;
use RPQ\Client;
use RPQ\Exception\JobNotFoundException;
use RPQ\Queue;

final class JobHandler
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
     * @var Queue $queue
     */
    private $queue;

    /**
     * Constructor
     *
     * @param Client $client
     * @param Logger $logger
     * @param string $this->queue
     */
    public function __construct(Client $client, Logger $logger, Queue $queue)
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->queue = $queue;
    }

    /**
     * Handles process end, and requeuing if necessary
     *
     * @param string $id
     * @param int $pid
     * @param int $code
     * @param bool $forceRetry
     * @return void
     */
    public function exit($id, $pid, $code, $forceRetry = false)
    {
        $this->logger->info('Job ended', [
            'exitCode' => $code,
            'pid' => $pid,
            'jobId' => $id,
            'queue' => $this->queue->getName()
        ]);

        $hash = explode(':', $id);
        $jobId = $hash[count($hash) - 1];
        try {
            $job = $this->queue->getJob($jobId);
        } catch (JobNotFoundException $e) {
            $this->logger->info('Unable to process job exit code or retry status. Job data unavailable', [
                'exitCode' => $code,
                'pid' => $pid,
                'jobId' => $job->getId(),
                'queue' => $this->queue->getName()
            ]);
            return true;
        }

        // If the job ended successfully, remove the data from redis
        if ($code === 0) {
            $this->logger->info('Job succeeded and is now complete', [
                'exitCode' => $code,
                'pid' => $pid,
                'jobId' => $job->getId(),
                'queue' => $this->queue->getName()
            ]);

            return $this->client->getRedis()->hdel($id);
        } else {

            $retry = $job->getRetry();

            // If force retry was specified, force this job to retry indefinitely
            if ($forceRetry === true) {
                $retry = true;
            }

            if ($retry === true || $retry > 0) {
                $this->logger->info('Rescheduling job', [
                    'exitCode' => $code,
                    'pid' => $pid,
                    'jobId' => $job->getId(),
                    'queue' => $this->queue->getName()
                ]);

                // If a retry is specified, repush the job back onto the queue with the same Job ID
                return $this->queue->push(
                    $job->getWorkerClass(),
                    $job->getArgs(),
                    \gettype($retry) === 'boolean' ? (bool)$retry : (int)($retry - 1),
                    (float)$job->getPriority(),
                    null, 
                    $job->getId()
                );
            } else {
                $this->logger->info('Job failed', [
                    'exitCode' => $code,
                    'pid' => $pid,
                    'jobId' => $job->getId(),
                    'queue' => $this->queue->getName()
                ]);

                return $this->client->getRedis()->del($job->getId());
            }
        }

        return;
    }
}