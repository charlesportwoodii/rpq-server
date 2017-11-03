# RPQ: Redis Priority Queue

RPQ is a simple, efficient, prioritized background job queue for PHP.

-----------

RPQ is a background processor for PHP, written in pure PHP. RPQ enables your application to asynchronously execute background tasks into a priority queue stored in Redis. Jobs can be prioritized, allowing for more important jobs to run first. RPQ uses multi-processing to handle multiple jobs at the same time. It exposes a simple API to create job handlers.

> Note that this project is very much in the Alpha quality stage. There are bugs, and things may not be working correctly.

## Requirements

RPQ supports PHP 7.1+, and requires Redis 3.0.3+, and pcntl.

> Amp\Process\Process is not compatible Windows.

## Installation

RPQ can be added to your project via composer.

```
composer require rpq/server
```

For information about how to create jobs, and schedule tasks take a look at the [Getting Started Guide](docs/Getting%20Started.md). The [docs](docs) directory also contains more information on specific aspects of RPQ. The [examples](examples/) directory also contains a few job worker examples which you can use as reference.

## License

RPQ is licensed under the BSD 3-Clause license. See [LICENSE](LICENSE) for more details.