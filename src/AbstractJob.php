<?php declare(strict_types=1);
declare(ticks=1);
namespace RPQ\Queue;

use RPQ\Client;

abstract class AbstractJob
{
    /**
     * @var RPQ\Client $client
     */
    protected $client;

    /**
     * @var string $id
     */
    protected $id;

    /**
     * Constructor
     *
     * @param RPQ\Client $cient
     * @param string $id
     */
    public function __construct(Client $client, $id)
    {
        $this->client = $client;
        $this->id = $id;
        
        if (extension_loaded('pcntl')) {
            pcntl_signal(SIGTERM, function($signal) {
                if (\method_exists(static::class, 'shutdown')) {
                    $handled = $this->shutdown();
                    if ($handled === true) {
                        exit(0);
                    } else if ($handled === false) {
                        exit(3);
                    }
                }
            });
            pcntl_async_signals(true);
        }
    }

    /**
     * Abstract perform implementation
     * Job arguments will be passed as an array of `$args`, this method must return an integer exit status code
     * A job is considered successful if the job returns a 0 exit status
     *
     * @param array $args
     * @return int
     */
    public function perform(array $args = []): int {}

    /**
     * Shutdown handler. By default this does nothing.
     *
     * @return void
     */
    protected function shutdown()
    {
        return;
    }
}