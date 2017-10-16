<?php declare(strict_types=1);

namespace RPQ\Queue;

use Symfony\Component\Console\Output\OutputInterface;

use RPQ\Client;
use Amp\Loop;
use Amp\Parallel\Forking\Fork;

use Exception;

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
     * Constructor
     * @param Client $client
     * @param OutputInterface $output
     * @param array $config
     */
    public function __construct(Client $client, OutputInterface $output, array $config = [])
    {
        $this->client = $client;
        $this->config = $config;
        $this->output = $output;
    }

    /**
     * Starts an Amp\Loop event loop and begins polling Redis for new jobs
     * @return void
     */
    public function start()
    {
        Loop::run(function () {
            
            $this->output->writeln(sprintf("RPQ is now started, and is listening for new jobs every %d ms", $this->config['poll_interval']));
            Loop::repeat($msInterval = $this->config['poll_interval'], function ($watcherId, $callback) {
                $job = $this->client->pop();
                if ($job !== null) {
                    try {
                        $staticClass = "\\" . $job['class'];

                        if (!class_exists($staticClass)) {
                            throw new Exception("Request task could not be found");
                        }

                    } catch (Exception $e) {
                        $this->output->writeln(sprintf("%s",
                            $e->getMessage()
                        ));
                    }
                }
            });
        });
    }
}