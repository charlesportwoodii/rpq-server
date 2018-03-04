# Controlling RPQ

RPQ can be controlled with signals. The process ID of the master process is written to the PID file defined in the configuration file. By default this file is located at `./rpq.pid`. The main RPQ process supports the following signals:

| Signal | Description |
| ------ | ----------- |
| `TERM`, `INT` | Fast shutdown |
| `QUIT` | Graceful shutdown |
| `HUP` | Reload |

## Fast Shutdown `TERM` or `INT`

When `TERM` or `INT` are sent to the main process, RPQ will immediately stop polling for new jobs. `KILL` will be sent to all active worker processes. All worker processes will immediately stop processing, and RPQ will shut down.

Active jobs will be pushed back onto the priority queue for re-processing.

> This may result in workers being in an unfinished state, and work being duplicated.

## Graceful Shutdown `QUIT`

When `QUIT` is sent to the main process, RPQ will immediately stop polling for new jobs. `TERM` will be sent to worker processes. Workers should stop whatever work they are doing and clean up if possible. Jobs that do not return a 0 exit status code will be requeued automatically. Jobs that do complete will not be requeued.

If a `deadline_timeout` is declared within the queue configuration, a `KILL` signal will be sent to the job if it is still running `deadline_timeout` seconds after `TERM` is sent to the process.

Once all workers have finished their work, the main process will shut down.

> Note that the main process will remain active until all workers have finished. If you wish to immediately cancel all active jobs, use `TERM` or `INT`.

## Reload `HUP`

When `HUP` is sent to the main process, RPQ perform the following actions:

- RPQ will run a self-test to verify that the configuration file syntax and attributes are correct.
- RPQ will stop polling for new jobs.
- If the self-test reports that the configuration file is _invalid_, RPQ will report an error, and will re-enabling job processing.
- If the self-test reports that the configuration file is _valid, RPQ
- RPQ will spawn a new instance of itself with the new configuration file. The old RPQ process will remain active until all worker processes have finished processing, then will shutdown.

> Note that the new master process cannot re-attach to `stdout`. If you are using the default logger configuration, or `php://stdout`, you will lose access to log data. It is recommended to use file based logging to prevent loss of log data.

> Note that since each new worker runs in it's own process, reloading the master process is only necessary if you wish for configuration changes to be applied to the master process without downtime.