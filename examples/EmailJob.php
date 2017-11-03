<?php declare(strict_types=1);

require './vendor/autoload.php';

use RPQ\Server\AbstractJob;
use Swift_Mailer;
use Swift_Message;
use Swift_SmtpTransport;

/**
 * This class serves as an example for how to send a single email
 */
final class EmailJob extends AbstractJob
{
    /**
     * Sends a single email
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
     *      'subject' => 'Hello Doctor!',
     *      'message' => 'What are you doing for Christmas?',
     *      'from' => [
     *          'email' => 'clara.oswald@tardis.io',
     *          'name' => 'Clara Oswald
     *      ],
     *      'to' => [
     *          'email' => 'doctor@tardis.io',
     *          'name' => 'Doctor'
     *      ]
     * ];
     * @return int
     */
    public function perform(array $args = []): int
    {
        // Create a transport
        $transport = new Swift_SmtpTransport(
            $args['smtp']['host'],
            $args['smtp']['port']
        );
        $transport->setUsername($args['smtp']['username']);
        $transport->setPassword($args['smtp']['password']);
      
        // Create a message
        $mailer = new Swift_Mailer($transport);
        $message = (new Swift_Message($args['subject']))
            ->setFrom([$args['from']['email'] => $args['from']['email']])
            ->setTo([$args['to']['email'] => $args['to']['name']])
            ->setBody($args['message']);
        
        // Send the message
        $result = $mailer->send($message);

        // The email was successfully sent if $mail->send returns 1, indicating 1 email was sent
        return $result === 1;
    }
}