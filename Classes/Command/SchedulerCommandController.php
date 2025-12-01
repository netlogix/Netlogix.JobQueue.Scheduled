<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Command;

use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Doctrine\DBAL\Types\Types;
use Flowpack\JobQueue\Common\Job\JobManager;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Log\ThrowableStorageInterface;
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
     * @param int $minutes Count jobs as stale if their last activity was more than these many minutes ago
     * @throws Exception
     */
    public function resetStaleJobsCommand(
        string $groupName,
        int $minutes = 10
    ): void {
        $tableName = ScheduledJob::TABLE_NAME;
        if ($this->connection->getDbal()->getDatabasePlatform() instanceof MySqlPlatform) {
            $sql = <<<MySQL
                UPDATE {$tableName}
                SET running = 0,
                    claimed = '',
                    incarnation = incarnation + 1
                WHERE running = 1
                  AND claimed NOT LIKE 'failed(%)'
                  AND groupname = :groupName
                  AND activity < NOW() - INTERVAL :minutes MINUTE
            MySQL;
        }
        else if ($this->connection->getDbal()->getDatabasePlatform() instanceof PostgreSqlPlatform) {
            $sql = <<<PostgreSQL
                UPDATE {$tableName}
                SET running = 0,
                    claimed = '',
                    incarnation = incarnation + 1
                WHERE running = 1
                  AND claimed NOT LIKE 'failed(%)'
                  AND groupname = :groupName
                  AND activity < NOW() - make_interval(mins => :minutes)
            PostgreSQL;
        } else {
            throw new \RuntimeException("unsupported database platform " . $this->connection->getDbal()->getDatabasePlatform()->getName());
        }

        $freed = $this->connection->executeQuery(
            sql: $sql,
            params: [
                'groupName' => $groupName,
                'minutes' => max($minutes, 1),
            ],
            types: [
                'groupName' => Types::STRING,
                'minutes' => Types::SMALLINT,
            ],
        )->rowCount();

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
            ->runLoop(function (Pool $pool) use ($groupName, $parallel, $stopPollingAfter, $pollingIntervalInSeconds, &$queueDueJobs): void {
                // Check for new jobs in the database and schedule as much as the pool has capacity for
                $queueDueJobs = $pool->eventLoop->addPeriodicTimer(
                    interval: $pollingIntervalInSeconds,
                    callback: function () use ($pool, $groupName, $parallel) {
                        $this->queueDueJobs(pool: $pool, groupName: $groupName, parallel: $parallel);
                    }
                );

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
                        callback: function () use ($pool, $queueDueJobs, $ping) {
                            $pool->eventLoop->cancelTimer($queueDueJobs);
                            $checkForPoolToClear = $pool->eventLoop->addPeriodicTimer(
                                interval: 1,
                                callback: function () use ($pool, $ping, &$checkForPoolToClear) {
                                    if (count($pool) === 0) {
                                        $pool->eventLoop->cancelTimer($ping);
                                        $pool->eventLoop->cancelTimer($checkForPoolToClear);
                                        $pool->eventLoop->stop();
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
     * @return int Number of handled jobs
     */
    protected function queueDueJobs(Pool $pool, string $groupName, int $parallel): int
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

            $process->on(Pool::EVENT_EXIT, function () use ($pool, $retry, $ping, &$numberOfHandledJobs) {
                $pool->eventLoop->cancelTimer($ping);
                $numberOfHandledJobs--;
                if ($numberOfHandledJobs === 0) {
                    ($numberOfHandledJobs === 0) && $retry->scheduleAll();
                }
            });
            $process->on(Pool::EVENT_SUCCESS, fn () => $this->scheduler->release($next));
            $process->on(Pool::EVENT_ERROR, fn () => $retry->markJobForRescheduling($next));
        }
        return $numberOfHandledJobs;
    }
}
