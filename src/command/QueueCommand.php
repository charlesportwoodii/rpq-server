<?php declare(strict_types=1);

namespace RPQ\Server\Command;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

use Redis;
use RPQ\Client;
use RPQ\Server\Command\AbstractCommand;
use RPQ\Server\Process\Dispatcher as ProcessDispatcher;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
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
            ->setDefinition(new InputDefinition([
                new InputOption('config', 'c', InputOption::VALUE_REQUIRED, 'A YAML configuration file'),
                new InputOption('t', 't', InputOption::VALUE_NONE, 'Test configuration and exit'),
                new InputOption('name', null, InputOption::VALUE_OPTIONAL, 'The queue name to work with. Defaults to `default`.'),
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

        // If the self-test was called, immediately return
        if ($input->getOption('t') === true) {
            return 0;
        }

        \file_put_contents($this->config['pid'], \getmypid());

        $this->startProcessQueue();
    }

    /**
     * Starts a new RPQ queue in process mode
     * @return void
     */
    private function startProcessQueue()
    {
        $this->logger->info('Starting queue in process mode');
        $dispatcher = new ProcessDispatcher(
            $this->client,
            $this->logger,
            $this->queue,
            \array_merge($this->queueConfig, ['process' => $this->config['process']]),
            [
                'configFile' => $this->configName
            ]
        );

        $dispatcher->start();
    }

    /**
     * Starts a new RPQ queue in streaming mode
     * @todo: Document + implement
     * @return void
     */
    private function startStreamQueue() {}
}