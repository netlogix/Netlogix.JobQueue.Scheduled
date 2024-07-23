# Netlogix.JobQueue.Scheduled

This package provides a PDO based scheduler for JobQueue jobs.

There are two main goals:

1. Schedule a Flowpack JobQueue job for later execution.
2. Have a single job only scheduled once.


## Installation
```bash
composer require netlogix/jobqueue-scheduled
```

## Schedule jobs

The first goal of this package is to have a way to put any kind of JobQueue job  on hold
and mark it for later execution.
In contrast to e.g. "Defer" annotated methods or default behavior of JobQueue jobs, there
is a time aspect to it.
Scheduled jobs are not executed immediately but at a time specified while scheduling.

Regular job queue jobs need to be serializable. That's just an implementation detail of
how Flowpack FakeQueue and t3n RabbitQueue work.

To schedule an existing Flowpack job, just wrap it in a Scheduled Job and pass it to
the scheduler.

```php
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use Flowpack\JobQueue\Common\Job\JobInterface;

assert($scheduler instanceof Scheduler);
assert($jobqueueJob instanceof JobInterface);

$dueDate = new \DateTimeImmutable('now + 1 minute');
$queueName = 'default';

$schedulerJob = new ScheduledJob(
    $jobqueueJob,
    $queueName,
    $dueDate
);
$scheduler->schedule($schedulerJob);
```


## Jobs with unique identifiers

The second goal of this package is to avoid duplicate schedules.

Certain jobs don't calculate a specific computation but just "run to the end".
An example of those are the catchup jobs of the Neos.EventSourcing package.
Queuing triggers the catchup call of
some event listeners.

Queuing another catchup job while the first one is running is good because there might
be additonal changes.

Queueing another catchup job while the previous one has is not even started is
unnecessary because the first one will already catch up to the end.

```php
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use Flowpack\JobQueue\Common\Job\JobInterface;

assert($scheduler instanceof Scheduler);
assert($jobqueueJob instanceof JobInterface);

$dueDate = new \DateTimeImmutable('now + 1 minute');
$queueName = 'default';

$jobIdentifier = 'event-sourcing-catchup-' . $eventListenerName;

$schedulerJob = new ScheduledJob(
    $jobqueueJob,
    $queueName,
    $dueDate,
    $jobIdentifier
);
$scheduler->schedule($schedulerJob);
```

The "schedule" will only schedule a new job if the specified identifier is not already
scheduled.
If there are conflicts between the existing due date and the one provided by the new
job the earliest value is taken.


## Queue scheduled jobs

A scheduled job lives in the database and is not processed any further until queueing
happens.

This is currently done via cronjobs.

```crontab
* * * * *   ./flow scheduler:queueduejobs
```

The internal scheduling mechanism will make sure only those jobs are passed from the
scheduler to the job queue which are "due" according to their individual due date
values.


## Automatically schedule jobs

Some jobs originate from foreign applications. An example would be one flow app
putting a job into a RabbitMQ and another flow app consuming it.

Previously the only implementation would be a regular jobqueue worker, which neither
provides a way to delay execution nor a deduplication feature.

Now every job can simply implement the `ScheduledJobInterface`. When is `execute()`
is triggered, it's now moved over to a scheduled jobs queue.

The job itself must provide all necessary details about how to schedule its execution.

```php
abstract class AutoScheduledJob implements ScheduledJobInterface, JobInterface {
    public function getSchedulingInformation(): ?SchedulingInformation
    {
        return SchedulingInformation(
            '97528fab-c199-4f87-b1a5-4074f1e98749',
            'default-grouo',
            new DateTimeImmutable('now')
        );
    }
}
```
