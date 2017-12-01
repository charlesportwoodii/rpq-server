<?php declare(strict_types=1);

namespace RPQ\Queue\Command;

use Exception;
use Redis;
use RPQ\Client;
use RPQ\Client\Exception\JobNotFoundException;
use RPQ\Queue\Command\AbstractCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

final class ProcessWorkerCommand extends AbstractCommand
{
    /**
     * Configures a new console command
     * @return void
     */
    protected function configure()
    {
        $this->setName('worker/process')
             ->setHidden(true)
             ->setDescription('Runs a given worker')
             ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'A YAML configuration file')
             ->addOption('jobId', null, InputOption::VALUE_REQUIRED, 'A Job UUID')
             ->addOption('name', null, InputOption::VALUE_REQUIRED, 'The queue name to work with. Defaults to `default`.');
    }

    /**
     * Executes the command
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!parent::execute($input, $output)) {
            return 1;
        }

        $hash = explode(':', $input->getOption('jobId'));
        $jobId = $hash[count($hash) - 1];

        try {
            $jobDetails = $this->client->getJobById($jobId);
            $class = $jobDetails['workerClass'];
            if (!\class_exists($class)) {
                throw new Exception("Unable to find worker class {$class}");
            }

            if (!\is_subclass_of($class, 'RPQ\Queue\AbstractJob')) {
                throw new Exception('Job does not implement RPQ\Queue\AbstractJob');
            }

            $job = new $class($this->client, $jobId);

            return $job->perform($jobDetails['args']);
        } catch (JobNotFoundException $e) {
            $this->logger->error('Unable to fetch job from Redis.', [
                'jobId' => $input->getOption('jobId'),
                'queueName' => $this->queue,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return -1;
        } catch (Exception $e) {
            $this->logger->error('An error occured when executing the job', [
                'jobId' => $input->getOption('jobId'),
                'workerClass' => $jobDetails['workerClass'],
                'queueName' => $this->queue,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return -1;
        }

        return 0;
    }
}