<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Command;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\PostgreSQL94Platform;
use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Doctrine\DBAL\Types\Types;
use Flowpack\JobQueue\Common\Job\JobManager;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Log\ThrowableStorageInterface;
use Netlogix\JobQueue\Polling\PollScheduler;
use Netlogix\JobQueue\Pool\Pool;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\SchedulingCoordinator;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use Netlogix\JobQueue\Scheduled\Service\Connection;

class SchedulerCommandController extends CommandController
{
    private const TEN_MINUTES_IN_SECONDS = 600;

    protected Scheduler $scheduler;

    protected JobManager $jobManager;

    protected ThrowableStorageInterface $throwableStorage;

    protected Connection $connection;

    public function injectScheduler(Scheduler $scheduler): void
    {
        $this->scheduler = $scheduler;
    }

    public function injectJobManager(JobManager $jobManager): void
    {
        $this->jobManager = $jobManager;
    }

    public function injectThrowableStorageInterface(ThrowableStorageInterface $throwableStorage): void
    {
        $this->throwableStorage = $throwableStorage;
    }

    public function injectConnection(Connection $connection): void
    {
        $this->connection = $connection;
    }

    /**
     * Reset stale jobs that have not changed for too long.
     *
     * @param string $groupName Free jobs in this group only
     * @param ?int $minutes @deprecated Use staleJobTimeout configuration setting instead.
     */
    public function resetStaleJobsCommand(
        string $groupName,
        ?int $minutes = 10
    ): void {
        $freed = $this->scheduler->resetStaleJobs($groupName, $minutes);
        if ($freed) {
            $this->outputLine('Freed ' . $freed . ' stale jobs.');
        }
    }

    /**
     * Fetch due jobs and schedule them, then wait and retry.
     * This is probably not the best way of polling for changes
     *
     * @param string $groupName Handle jobs in this group only
     * @param bool $outputResults Write child process output to the console
     * @param int $parallel Number of jobs to handle in parallel
     * @param int $preforkSize Number of jobs to already boot up without having a job waiting
     * @param int $stopPollingAfter Stop polling after this many seconds
     * @param float $pollingIntervalInSeconds How often to check for new jobs in seconds
     */
    public function pollForIncomingJobsCommand(
        string $groupName,
        bool $outputResults = false,
        int $parallel = 1,
        int $preforkSize = 0,
        int $stopPollingAfter = self::TEN_MINUTES_IN_SECONDS,
        float $pollingIntervalInSeconds = 0.1
    ): void {
        $parallel = max($parallel, 1);
        Pool::create(
            outputResults: $outputResults,
            preforkSize: $preforkSize
        )
            ->runLoop(function (Pool $pool) use ($groupName, $parallel, $stopPollingAfter, $pollingIntervalInSeconds): void {
                $scheduler = null;

                // Check for new jobs in the database and schedule as much as the pool has capacity for.
                // Capacity check and slot occupation happen synchronously inside queueDueJobs, so the
                // periodic poll and the immediate poll on job completion can never exceed $parallel.
                $scheduler = PollScheduler::create(
                    loop: $pool->eventLoop,
                    tryToPickUpWork: function () use ($pool, $groupName, $parallel, &$scheduler): void {
                        $this->queueDueJobs(pool: $pool, groupName: $groupName, parallel: $parallel, pollScheduler: $scheduler);
                    },
                    hasCapacity: fn () => count($pool) < $parallel,
                    interval: $pollingIntervalInSeconds
                );
                $scheduler->start();

                // Keep the database connection alive
                $ping = $pool->eventLoop->addPeriodicTimer(
                    interval: 30,
                    callback: function () use (&$ping) {
                        $this->scheduler->ping();
                    }
                );

                // Once the timeout is reached, wait until the final jobs are done and stop the loop
                if ($stopPollingAfter) {
                    $pool->eventLoop->addTimer(
                        interval: $stopPollingAfter,
                        callback: function () use ($pool, $scheduler, $ping) {
                            $scheduler->stop();
                            $checkForPoolToClear = $pool->eventLoop->addPeriodicTimer(
                                interval: 1,
                                callback: function () use ($pool, $ping, &$checkForPoolToClear) {
                                    if (count($pool) === 0) {
                                        $pool->eventLoop->cancelTimer($ping);
                                        $pool->eventLoop->cancelTimer($checkForPoolToClear);
                                    }
                                }
                            );
                        }
                    );
                }
            });
    }

    /**
     * @param Pool $pool A pool of subprocesses waiting for new jobs to execute
     * @param string $groupName Handle only jobs of this group
     * @param int $parallel Number of jobs to handle in parallel
     * @param ?PollScheduler $pollScheduler Re-poll immediately once a slot frees up
     * @return int Number of handled jobs
     */
    protected function queueDueJobs(Pool $pool, string $groupName, int $parallel, ?PollScheduler $pollScheduler = null): int
    {
        $numberOfHandledJobs = 0;
        $retry = new SchedulingCoordinator($this->scheduler);

        while (count($pool) < $parallel) {
            $next = $this->scheduler->next($groupName);

            if (!$next) {
                return $numberOfHandledJobs;
            }

            $numberOfHandledJobs++;

            $process = $pool->runPayload(payload: $next->getSerializedJob(), queueName: $next->getQueueName());

            $ping = $pool->eventLoop->addPeriodicTimer(
                interval: 1,
                callback: function () use ($process, $next) {
                    if ($process->isRunning()) {
                        $this->scheduler->activity($next);
                    }
                }
            );

            $process->on(Pool::EVENT_EXIT, function () use ($pool, $retry, $ping, $pollScheduler, &$numberOfHandledJobs) {
                $pool->eventLoop->cancelTimer($ping);
                $numberOfHandledJobs--;
if ($numberOfHandledJobs === 0) {
    $retry->scheduleAll();
}
                // A slot just freed up - pick up the next due job without waiting for the next periodic tick.
                $pollScheduler?->requestImmediatePoll();
            });
            $process->on(Pool::EVENT_SUCCESS, fn () => $this->scheduler->release($next));
            $process->on(Pool::EVENT_ERROR, fn () => $retry->markJobForRescheduling($next));
        }
        return $numberOfHandledJobs;
    }
}
