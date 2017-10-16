<?php declare(strict_types=1);

namespace RPQ\Queue\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

use Redis;
use RPQ\Client;
use RPQ\Queue\Dispatcher;

/**
 * Command line interface to RPQ
 * @package RPQ\Queue
 */
final class QueueCommand extends Command
{
    /**
     * Configures a new console command
     * @return void
     */
    protected function configure()
    {
        $this->setName('queue')
            ->setHidden(true)
            ->setDescription('Initializes a new queue processor')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'A YAML configuration file')
            ->addOption('name', null, InputOption::VALUE_OPTIONAL, 'The queue name to work with. Defaults to `default`.');
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
        $redis->connect($config['redis']['host'], $config['redis']['port']);
        
        $client = new Client($redis, $config['redis']['namespace']);

        $queueConfig = $config['queue']['default'];
        if (isset($config['queue'][$queue])) {
            $queueConfig = $config['queue'][$queue];
        }

        // Starts a new worker dispatcher
        $dispatcher = new Dispatcher($client, $output, $queueConfig);
        $dispatcher->start();
    }
}