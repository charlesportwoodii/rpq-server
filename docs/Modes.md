# Processing Modes

RPQ supports two different job processing modes: Process and Stream. These two different modes offer different performance charactaristics, and allow you to tune RPQ's performance to better suite your system. Each mode has it's own pros and cons. This document can assist you in determining the best mode for your system.

## Process

In process mode, RPQ will spawn one queue process which listens for incoming jobs, and will create a new-subprocess for each job, up to a limit of `queue:<name>:max_jobs` parallel processes. At most, `queue:<name>:max_jobs` + 1 rpq processes will be running on your system.

Process mode is recommended for light to medium job queues, where you do not anticipate a constant influx of jobs that require processing.

### Pros

- Scales to `queue:<name>:max_jobs` sub-processes, making effecient use for CPU cycles for job queues with light to medium load. New processes are spawned only when jobs are running.
- Signals can be sent directly to the working sub-process. From the CLI it's easy to identify what jobs are running.
- Changes to a worker-process take effect immediately once a new worker starts, as PHP does a complete code reload when a new process is started.

### Cons

- Job queues that have constant jobs being added incur a significant startup penalty by PHP.

## Stream

> This mode is not yet implemented
In streaming mode, RPQ will spawn a single queue process which listens for incoming jobs, and will spawn `queue:<name>:max_jobs` worker processes. Worker processes will listen for stream requests from the main RPQ queue process to process a job, and will with locks to ensure that each worker is performing only a single job.

Stream mode is recommended for heavy job queues where new jobs are constantly being added and processed around the clock.

### Pros

- Heavy job queues incur no startup penalty for starting a new queue. New jobs can start immediately, without needing to wait for PHP.

### Cons

- `queue:<name>:max_jobs` is system dependant. In _process_ mode, your `queue:<name>:max_jobs` setting is dependant upon how many cores/threads you can utilize before you hit a CPU bottleneck. In _stream_ mode, `queue:<name>:max_jobs` sub-processes are immediately spawned and listen for incoming events. On systems with light job queues, this may result in wasted CPU resources while RPQ's event listener waits for incoming jobs.