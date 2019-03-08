<?php declare(strict_types=1);

namespace RPQ\Server\Command;

use Exception;
use Redis;
use RPQ\Client;
use RPQ\Client\Exception\JobNotFoundException;
use RPQ\Server\Command\AbstractCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
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
             ->setDefinition(new InputDefinition([
                new InputOption('config', 'c', InputOption::VALUE_REQUIRED, 'A YAML configuration file'),
                new InputOption('jobId', null, InputOption::VALUE_REQUIRED, 'A Job UUID'),
                new InputOption('name', null, InputOption::VALUE_REQUIRED, 'The queue name to work with. Defaults to `default`.'),
             ]));
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

        try {
            $job = $this->queue->getJob($input->getOption('jobId'));
            $class = $job->getWorkerClass();
            if (!\class_exists($class)) {
                throw new Exception("Unable to find worker class {$class}");
            }

            if (!\is_subclass_of($class, 'RPQ\Server\AbstractJob')) {
                throw new Exception('Job does not implement RPQ\Server\AbstractJob');
            }

            $task = new $class($this->client, $job->getId());

            return $task->perform($job->getArgs());
        } catch (JobNotFoundException $e) {
            $this->logger->error('Unable to fetch job from Redis.', [
                'jobId' => $job->getId(),
                'queueName' => $this->queue->getName(),
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return -1;
        } catch (Exception $e) {
            $this->logger->error('An error occured when executing the job', [
                'jobId' => $job->getId(),
                'workerClass' => $job->getWorkerClass(),
                'queueName' => $this->queue->getName(),
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
