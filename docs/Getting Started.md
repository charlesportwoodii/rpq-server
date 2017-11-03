# Getting Started Guide

This document provides a brief overview of how to use RPQ within your project.

## Installation

RPQ can be added to your project via composer.

```
composer require rpq/server
```

> Note that you may need to adjust your `minimum-stability` settings until RPQ has a proper tag.

Once RPQ has been added to your project, you should create a configuration file as outlined in [Configuration](Configuration.md).

## Starting RPQ

RPQ can be started by running the following command:

```bash
./vendor/bin/rpq queue -c /path/to/config.yml
```

If you wish to use a queue configuration other than `default`, use the `--name` CLI arguement.

```bash
./vendor/bin/rpq queue -c /path/to/config.yml --name <queueName>
```

RPQ will automatically start listening for new jobs sent to Redis.

> Since RPQ created sub-processes, when developing new job classes you _do not_ need to to restart RPQ.

## Creating Job Classes

All job classes extends from `RPQ\Queue\AbstractJob`, and at minimum must implement the following method:

```php
public function perform(array $args = []): int
```

This method _must_ return an integer status code. If the return code is `0`, RPQ will consider the job to be successful, otherwise RPQ will consider the job to be failed, and will log the error appropriately, and/or requeue it in the priority queue per it's retry settings.

In general, as long as your perform method returns an integer status code at some point, the job can call any other PHP class you may require.

### Logging

The `STDOUT` and `STDERR` outputs of the worker will _automatically_ be logged by the main queue process to the main logger declared within your configuration.

Each worker process also has direct access to it's own Monolog logger which it can use to write it's own messages.

```php
$this->logger->info('Logging within my worker', [ 'id' => $this->id ]);
```

### Job Identification

The current job ID assigned to your job can be queried by getting the protected `$id` property.

```php
$jobId = $this->id;
```

### Shutdown handler

If the main RPQ process recieves a `SIGQUIT` signal, it will send a `SIGTERM` signal to all worker processes. Worker processes can handle this signal by registering a shutdown method.

```php
private function shutdown() {}
```

Within this function you can register shutdown behavior.

If this method returns `true`, then RPQ will assume that the shutdown handled handled, and will return with exit code `0`. RPQ _will not_ requeue your job if this methods returns `true`.

If this method returns `false`, the worker process will return an exit code `3`. RPQ will _automatically_ requeue your job.

If this method is not explicitly declared within your job, _or_ if you wish to ignore the `SIGTERM` signal, your job will continue processing.

> Note that if queue configuration declares a `deadline_timeout`, a `SIGKILL` will be sent to the job `dealine_timeout` seconds after the initial `SIGTERM` signal to sent. It is important to register a shutdown handler for your job to avoid jobs being forcefully killed and requeued.

> Note that in order for `pcntl` to listen to jobs, you should `declare(ticks=1)` within your Job listener.


## Sending Jobs to RPQ

Jobs can be enqueued to RPQ via  `rpq/client`, which is pre-bundled with `rpq/server`. If RPQ is running on a different system from your application, `rpq/client` can be included via composer.

```bash
composer require rpq/client
```

Jobs can be pushed to RPQ using their fully-qualified class name, as illustrated in the following example.

```php
$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);

$rpq = new RQP\Client($redis);
$rpq->push('My\Namespaced\Job');
```

> More information on `rpq/client` can be found at https://github.com/charlesportwoodii/rpq-client.