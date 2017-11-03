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

The PID is defined by the `pid`.

```yaml
pid: /path/to/rpq.pid
```

## Logging

RPQ uses a stream based logger to log to either a file or `STDOUT`.

```yaml
log:
  level: DEBUG
  file: null
```

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