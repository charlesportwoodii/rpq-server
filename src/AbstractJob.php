<?php declare(strict_types=1);
declare(ticks=1);
namespace RPQ\Queue;

use Monolog\Logger;

abstract class AbstractJob
{
    /**
     * @var Logger $logger
     */
    protected $logger;

    /**
     * @var string $id
     */
    protected $id;

    /**
     * Constructor
     *
     * @param Logger $logger
     * @param string $id
     */
    public function __construct(Logger $logger, $id)
    {
        $this->logger = $logger;
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