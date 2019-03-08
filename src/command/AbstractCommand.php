<?php declare(strict_types=1);

namespace RPQ\Server\Command;

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
    /**
     * @var Redis $redis
     */
    protected $redis;

    /**
     * @var array $config
     */
    protected $config;

    /**
     * @var Client $client
     */
    protected $client;

    /**
     * @var MonoLog $logger
     */
    protected $logger;

    /**
     * @var string $configName
     */
    protected $configName;

    /**
     * @var Queue $queue
     */
    protected $queue;

    /**
     * @var string $queueConfig
     */
    protected $queueConfig;

    /**
     * Executes the command
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->configName = $input->getOption('config');

        // Verify the configuration file provided is valid
        if ($this->configName === null || !\file_exists($this->configName)) {
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
        $this->redis->connect($this->config['redis']['host'], $this->config['redis']['port']);
        $this->redis->echo('Hello RPQ');
        $this->client = new Client($this->redis, $this->config['redis']['namespace']);
        $this->queue = $this->client->getQueue($input->getOption('name') ?? 'default');

        defined('LOGGER_APP_NAME') or define('LOGGER_APP_NAME', 'rpq.' . \bin2hex(random_bytes(4)));

        // If a default logger configuration isn't set, pipe data to stdout
        if (
            !isset($this->config['log']['logger']) ||
            !\file_exists($this->config['log']['logger'])
        ) {
            $this->logger = new Logger(LOGGER_APP_NAME);
            $handler = new StreamHandler('php://stdout', Logger::DEBUG);
            $this->logger->pushHandler($handler, Logger::DEBUG);
        } else {
            // Otherwise use the preconfigured logger
            $this->logger = require $this->config['log']['logger'];
        }

        $this->queueConfig = $this->config['queue']['default'];
        if (isset($this->config['queue'][$this->queue->getName()])) {
            $this->queueConfig = $this->config['queue'][$this->queue->getName()];
        }

        return true;
    }
}