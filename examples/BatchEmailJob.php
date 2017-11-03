<?php
declare(strict_types=1);
declare(ticks=1);

require './vendor/autoload.php';

use Redis;
use RPQ\Server\AbstractJob;
use RPQ\Client\Client;
use Swift_Mailer;
use Swift_Message;
use Swift_SmtpTransport;

final class BatchEmailJob extends AbstractJob
{
    /**
     * Indicate if we're to shut down
     * @var boolean $shutdown
     */
    private $shutdown = false;

    /**
     * An array of all of our messages
     * @var array $messages
     */
    private $messages;

    /**
     * The initial arguements sent to `perform`
     * @var array $args
     */
    private $args = [];

    /**
     * Sends batches of emails in a loop.
     *
     * `$args` is an array of details pushed into Redis. In this example `$args` has the following
     * structure
     * @param array $args = [
     *      'smtp' => [
     *          'host' => '127.0.0.1',
     *          'port' => 25
     *          'username' => 'smtp_username',
     *          'password' => 'smtp_password'
     *      ],
     *      // This is an array of messages, where each message is it's own array
     *      'messages' => [
     *          [
     *              'subject' => 'Hello Doctor!',
     *              'message' => 'What are you doing for Christmas?',
     *              'from' => [
     *              'email' => 'clara.oswald@tardis.io',
     *                 'name' => 'Clara Oswald
     *              ],
     *              'to' => [
     *                 'email' => 'doctor@tardis.io',
     *                 'name' => 'Doctor'
     *              ]
     *          ],
     *          [ ... ] // More messages
     *      ]
     * ];
     * @return int
     */
    public function perform(array $args = []): int
    {
        // Store our args
        $this->args = $args;
        unset($this->args['messages']);

        // Create a new transport
        $transport = new Swift_SmtpTransport(
            $args['smtp']['host'],
            $args['smtp']['port']
        );
        $transport->setUsername($args['smtp']['username']);
        $transport->setPassword($args['smtp']['password']);

        // Make a copy of all of our messages, and interate over each message
        $this->messages = $args['messages'];
        foreach ($messages as &$message) {
            // If shutdown is called, abort what we are doing.
            if ($shutdown) {
                break;
            }

            // Create a message
            $mailer = new Swift_Mailer($transport);
            $m = (new Swift_Message($message['subject']))
                ->setFrom([$message['from']['email'] => $message['from']['email']])
                ->setTo([$message['to']['email'] => $message['to']['name']])
                ->setBody($message['message']);
            
            // Send the message, and indicate if it was sent
            $message['sent'] = ($mailer->send($m) === 1);
        }

        return 0;
    }

    /**
     * Register a shutdown handler
     * @return bool
     */
    private function shutdown()
    {
        // Indicate to our main loop that we should stop processing additonal messages
        $this->shutdown = true;
        
        // Get a list of all the messages that have not yet been handled
        $this->args['messages'] = array_filter($this->messages, function($message) {
            if (!isset($message['sent']) || $message['sent'] === false) {
                return $message;
            }
        });

        $redis = new Redis;
        $client = new Client($redis);

        // Push the unfinished jobs back onto the priority queue with a priority of 100 
        // So they get processed as soon as possible.
        $client->push(static::class, $this->args, 1, 100);

        // Indicate that we've handled SIGTERM, and are ready to shut down
        return true;
    }
}