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