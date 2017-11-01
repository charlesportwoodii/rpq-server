<?php declare(strict_types=1);

namespace RPQ\Queue\Command;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use Redis;
use RPQ\Client;
use RPQ\Queue\Command\AbstractCommand;
use RPQ\Queue\Dispatcher;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Command line interface to RPQ
 * @package RPQ\Queue
 */
final class QueueCommand extends AbstractCommand
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
        if (!parent::execute($input, $output)) {
            return 1;
        }
        
        \file_put_contents($this->config['pid'], getmypid());

        // Starts a new worker dispatcher
        $dispatcher = new Dispatcher(
            $this->client,
            $this->logger,
            $this->queueConfig,
            [
                'queueName' => $this->queue,
                'configFile' => $this->configName
            ]
        );

        $dispatcher->start();
    }
}