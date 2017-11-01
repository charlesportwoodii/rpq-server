<?php declare(strict_types=1);

namespace RPQ\Queue\Command;

use Exception;
use Redis;
use RPQ\Client;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

abstract class AbstractCommand extends Command
{
    protected $redis;
    protected $config;
    protected $client;
    protected $logger;
    protected $configName;
    protected $queue;
    protected $queueConfig;

    /**
     * Executes the command
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->queue = $input->getOption('name') ?? 'default';
        $this->configName = $input->getOption('config');

        // Verify the configuration file provided is valid
        if ( $this->configName === null || !\file_exists( $this->configName)) {
            $output->writeln("Please specify a valid configuration YAML file");
            return false;
        }

        try {
            $this->config = Yaml::parse(\file_get_contents($this->configName));
        } catch (ParseException $e) {
            $output->writeln("Unable to parse the YAML string: %s", $e->getMessage());
            return false;
        }
        
        $this->redis = new Redis;
        $this->redis->pconnect($this->config['redis']['host'], $this->config['redis']['port']);
        $this->client = new Client($this->redis, $this->config['redis']['namespace']);

        $this->logger = new Logger('rpq');
        $this->logger->pushHandler(new StreamHandler(
            $this->config['log']['file'] ?? 'php://stdout',
            $this->config['log']['level']
        ));

        $this->queueConfig = $this->config['queue']['default'];
        if (isset($this->config['queue'][$this->queue])) {
            $this->queueConfig = $this->config['queue'][$this->queue];
        }

        return true;
    }
}