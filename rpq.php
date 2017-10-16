

<?php
// Hunt for an autoloader
$paths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php'
];

foreach ($paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

use Symfony\Component\Console\Application;
use RPQ\Queue\Command\QueueCommand;

// Starts a new Symfony\Console application with the queue command
$app = new Application();
$queueCommand = new QueueCommand;

$app->add($queueCommand);
$app->setDefaultCommand($queueCommand->getName(), true);
$app->run();
