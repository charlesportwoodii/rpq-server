# Configuration

RPQ uses a [YAML](http://www.yaml.org/) based configuration file for control. The configuration file is broken up into the following sections. For an example of a configuration please see `default.yml`. Note that your configuration will always be super-imposed over `default.yml` to ensure certain configuration options are defined.

## Redis

RPQ uses Redis as the backend store for the priority queue. The YAML configuration allows you to specify the host, port, and namespace you wish to poll for jobs in.

```yaml
redis:
  host: "127.0.0.1"
  port: 6379
  namespace: "rpq"
```

## Process Control

The PID is defined by the `pid`. The mode specifies whether `process` or `stream` mode is used. For more information on these different modes, and how it affects how jobs are scheduled reference [Modes](Modes.md).

```yaml
pid: /path/to/rpq.pid
```

## Modes
RPQ supports two different modes, `proces` and `stream`.

### Process
Process mode is explicitly declared by setting the `mode` to `process`.

```yaml
mode: process
```

By default, RPQ will use it's own internal worker command. When using a third-party framework, that framework may implement it's own worker command to handle the worker process.

If you are using a third-party framework, you can specify the entry script for the command. By default this is `null`, which means RPQ's own internal command will be used.

```yaml
process:
  script: /path/to/script/filename
```

If the CLI command name differs, it can be explicitly declared.
```yaml
process:
  command: 'worker/process'
```

By default, `--config /path/to/rpq.yaml` will be included. This can be excluded if your framework handles loading the configuration file at runtime.

```yaml
process:
  config: true|false
```

## Logging

RPQ uses [Monolog](https://github.com/Seldaek/monolog) to log mesages, which provides great flexability when routing messages.

```yaml
log:
  logger: "/var/www/logs/application.log"
  level: "\\Monolog\\Logger::DEBUG"
```

| Option | Default Value | Description |
|:------:|---------------|-------------|
| logger | `null` | The full path name to your logger configuration file. This should be a PHP file that returns an instance of `\Monolog\Logger`. |
| level | `"\\Monolog\\Logger::DEBUG"` | The Monolog log level. |

> If `log:logger` is not defined, or the file does not exists or cannot be found, RPQ will stream data to STDOUT.

RPQ supports the logging levels described by [RFC 5424](https://tools.ietf.org/html/rfc5424). These log levels are:

| Log Level | Code | Description |
|:---------:|:---:|-------------|
| DEBUG     | 100 | Detailed debugging information |
| INFO      | 200 | Interesting events |
| NOTICE    | 250 | Normal but significant events |
| WARNING   | 300 | Exceptional occurrences that are not errors |
| ERROR     | 400 | Runtime errors that do not require immediate action, but should be logged. |
| CRITICAL  | 500 | Critical conditions |
| ALERT     | 550 | Action must be taken immediately |
| EMERGENCY | 600 | Emergency |

If `log:file` is set to `null`, logs will be redirected to `STDOUT`, otherwise RPQ will attempt to write to the logfile at the location listed.

## Queue Configuration

While RPQ only supports processing a single job queue at a time, multiple job queues may be defined in the configuration, and multiple RPQ instances can be spawned against the same configuration file.

```yaml
queue:
  default:
    max_jobs: 20
    poll_interval: 100
    deadline_timeout: 30
```

By default, the `default` queue will be used. Each queue may be defined by a plain text name, and supports the following options:

| Queue Option | Description |
|:------------:|-------------|
| max_jobs | The integer maximum amount of jobs that may be run concurrently. By default, 20 jobs will be permitted to run concurrently. |
| poll_interval | The integer interval in milliseconds at which new jobs will be pulled from Redis. By default, Redis will be polled every 100ms. |
| deadline_timeout | The integer amount in seconds, after a worker recieves a `SIGTERM` signal, that the queue process will send a `SIGKILL` signal, if the worker is still alive. |