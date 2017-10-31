<?php declare(strict_types=1);

namespace RPQ\Queue\Command;

use Exception;
use Redis;
use RPQ\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

final class WorkerCommand extends Command
{
    /**
     * Configures a new console command
     * @return void
     */
    protected function configure()
    {
        $this->setName('worker')
             ->setHidden(true)
             ->setDescription('Runs a given worker')
             ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'A YAML configuration file')
             ->addOption('id', null, InputOption::VALUE_REQUIRED, 'A Job UUID')
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
        $queue = $input->getOption('name') ?? 'default';
        $configName = $input->getOption('config');

        // Verify the configuration file provided is valid
        if ($configName === null || !\file_exists($configName)) {
            $output->writeln("Please specify a valid configuration YAML file");
            return 1;
        }

        try {
            $config = Yaml::parse(\file_get_contents($configName));
        } catch (ParseException $e) {
            $output->writeln("Unable to parse the YAML string: %s", $e->getMessage());
            return 1;
        }
        
        $redis = new Redis;
        $redis->pconnect($config['redis']['host'], $config['redis']['port']);
        $client = new Client($redis, $config['redis']['namespace']);

        $hash = explode(':', $input->getOption('id'));
        $jobId = $hash[count($hash) - 1];
        $jobDetails = $client->getJobById($jobId);

        try {
            $class = '\\' . $jobDetails['workerClass'];

            if (!\class_exists($class)) {
                throw new Exception("Unable to find worker class {$class}");
            }
            $job = new $class($jobId);

            return $job->perform($jobDetails['args']);
        } catch (Exception $e) {
            $this->output->writeln($e->getMessage());
        }
        return 0;
    }
}