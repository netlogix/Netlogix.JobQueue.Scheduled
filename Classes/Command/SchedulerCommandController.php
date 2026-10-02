<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Command;

use Closure;
use Doctrine\DBAL\Exception as DatabaseException;
use Flowpack\JobQueue\Common\Job\JobManager;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Log\ThrowableStorageInterface;
use Netlogix\JobQueue\Polling\PollScheduler;
use Netlogix\JobQueue\Pool\Pool;
use Netlogix\JobQueue\Scheduled\Domain\Group;
use Netlogix\JobQueue\Scheduled\Domain\GroupRepository;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\SchedulingCoordinator;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use Netlogix\JobQueue\Scheduled\Service\Connection;
use React\ChildProcess\Process;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

use function array_filter;
use function array_map;
use function array_sum;
use function array_values;
use function explode;
use function hrtime;
use function implode;
use function max;
use function min;

class SchedulerCommandController extends CommandController
{
    private const THIRTY_MINUTES_IN_SECONDS = 1800;

    // Covers the loop sleeping in the retry backoff of Connection while the database comes back.
    private const KILL_MARGIN_IN_SECONDS = 10;

    protected Scheduler $scheduler;

    protected JobManager $jobManager;

    protected ThrowableStorageInterface $throwableStorage;

    protected Connection $connection;

    protected GroupRepository $groupRepository;

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

    public function injectGroupRepository(GroupRepository $groupRepository): void
    {
        $this->groupRepository = $groupRepository;
    }

    /**
     * Reset stale jobs that have not changed for too long.
     *
     * Each group is freed with its own staleJobTimeout, so this runs one
     * statement per group instead of a single bundled one.
     *
     * @param array $groupNames Free jobs of these groups only, comma separated; all active groups if empty
     * @phpstan-param list<string> $groupNames
     */
    public function resetStaleJobsCommand(array $groupNames = []): void
    {
        $freed = 0;
        foreach ($this->resolveGroups($groupNames) as $group) {
            $freed += $this->scheduler->resetStaleJobs($group->getName());
        }

        if ($freed) {
            $this->outputLine('Freed ' . $freed . ' stale jobs.');
        }
    }

    /**
     * Fetch due jobs and schedule them, then wait and retry.
     * This is probably not the best way of polling for changes
     *
     * @param array $groupNames Handle jobs of these groups only, comma separated; all active groups if empty
     * @phpstan-param list<string> $groupNames
     * @param bool $outputResults Write child process output to the console
     * @param int $stopPollingAfter Once this many seconds have passed, keep polling until no job is running, then exit; 0 never exits
     */
    public function pollForIncomingJobsCommand(
        array $groupNames = [],
        bool $outputResults = false,
        int $stopPollingAfter = self::THIRTY_MINUTES_IN_SECONDS
    ): void {
        $groups = $this->resolveGroups($groupNames);
        if ($groups === []) {
            $this->outputLine('No active job groups configured.');
            return;
        }

        $loop = Loop::get();
        $pollScheduler = null;
        $pools = array_map(
            static fn (Group $group) => Pool::create(
                outputResults: $outputResults,
                preforkSize: $group->getPreforkSize(),
                childProcessPollInterval: $group->getChildProcessPollInterval()
            ),
            $groups
        );

        // Check for new jobs in the database and schedule as much as the pools have capacity for.
        // Capacity check and slot occupation happen synchronously inside queueDueJobs, so the
        // periodic poll and the immediate poll on job completion can never exceed a group's capacity.
        //
        // One scheduler for all groups: a group's pollingInterval is an upper bound of the waiting
        // time, so the smallest one of them satisfies every group.
        $pollScheduler = PollScheduler::create(
            loop: $loop,
            tryToPickUpWork: $this->survivingDatabaseErrors(
                function () use ($loop, $groups, $pools, &$pollScheduler): void {
                    $this->queueDueJobs(
                        loop: $loop,
                        groups: $groups,
                        pools: $pools,
                        pollScheduler: $pollScheduler
                    );
                }
            ),
            hasCapacity: function () use ($groups, $pools): bool {
                return self::groupsWithCapacity($groups, $pools) !== [];
            },
            interval: self::pollingInterval($groups)
        );
        $pollScheduler->start();

        // Keep the database connection alive
        $ping = $loop->addPeriodicTimer(
            interval: 30,
            callback: $this->survivingDatabaseErrors(function () {
                $this->scheduler->ping();
            })
        );

        // Once the timeout is reached, keep polling until the first moment no job is running, then stop the loop.
        // Stopping the poll right away would leave free slots idle while the last jobs drain.
        if ($stopPollingAfter) {
            $loop->addTimer(
                interval: $stopPollingAfter,
                callback: function () use ($loop, $pollScheduler, $ping, $pools) {
                    $checkForPoolsToClear = null;
                    $checkForPoolsToClear = $loop->addPeriodicTimer(
                        interval: 1,
                        callback: function () use ($loop, $pollScheduler, $ping, $pools, &$checkForPoolsToClear) {
                            if (self::countRunningJobs($pools) !== 0) {
                                return;
                            }
                            $pollScheduler->stop();
                            $loop->cancelTimer($ping);
                            if ($checkForPoolsToClear !== null) {
                                $loop->cancelTimer($checkForPoolsToClear);
                            }
                            // Idle prefork workers keep timers on the loop, so it would never return.
                            foreach ($pools as $pool) {
                                $pool->shutdownObject();
                            }
                        }
                    );
                }
            );
        }

        $loop->run();
    }

    /**
     * @param list<string> $groupNames Empty means every active group
     * @return array<string, Group>
     */
    protected function resolveGroups(array $groupNames): array
    {
        // Flow's CLI hands over "--group-names=a,b" as the single value "a,b".
        $groupNames = array_values(array_filter(
            array_map('trim', explode(',', implode(',', $groupNames))),
            static fn (string $groupName): bool => $groupName !== ''
        ));
        if ($groupNames === []) {
            return $this->groupRepository->active();
        }

        $groups = [];
        foreach ($groupNames as $groupName) {
            $groups[$groupName] = $this->groupRepository->get($groupName);
        }

        return $groups;
    }

    /**
     * @param array<string, Group> $groups
     * @param array<string, Pool> $pools
     * @param ?PollScheduler $pollScheduler Re-poll immediately once a slot frees up
     * @return int Number of handled jobs
     */
    protected function queueDueJobs(
        LoopInterface $loop,
        array $groups,
        array $pools,
        ?PollScheduler $pollScheduler = null
    ): int {
        $numberOfHandledJobs = 0;
        $retry = new SchedulingCoordinator($this->scheduler);

        // Recomputed on every pass: the job just started may have filled up its own group.
        while (($available = self::groupsWithCapacity($groups, $pools)) !== []) {
            $claimedAt = hrtime(true);
            $next = $this->scheduler->next(...array_map(static fn (Group $group) => $group->getName(), $available));

            if (!$next) {
                return $numberOfHandledJobs;
            }

            $numberOfHandledJobs++;

            $process = $pools[$next->getGroupName()]->runPayload(
                payload: $next->getSerializedJob(),
                queueName: $next->getQueueName()
            );

            $ping = $this->keepActive(
                loop: $loop,
                process: $process,
                job: $next,
                group: $groups[$next->getGroupName()],
                claimedAt: $claimedAt
            );

            $process->on(Pool::EVENT_EXIT, function () use ($loop, $retry, $ping, $pollScheduler, &$numberOfHandledJobs) {
                $loop->cancelTimer($ping);
                $numberOfHandledJobs--;
                if ($numberOfHandledJobs === 0) {
                    $this->survivingDatabaseErrors(fn () => $retry->scheduleAll())();
                }
                // A slot just freed up - pick up the next due job without waiting for the next periodic tick.
                $pollScheduler?->requestImmediatePoll();
            });
            $process->on(Pool::EVENT_SUCCESS, fn () => $this->releaseEventually($loop, $next));
            $process->on(Pool::EVENT_ERROR, fn () => $retry->markJobForRescheduling($next));
        }

        return $numberOfHandledJobs;
    }

    /**
     * Writes the job's activity every second while its process runs.
     *
     * Once the last successful write is older than the group's staleJobTimeout,
     * resetStaleJobs may already have freed the job for another run, so the
     * process gets terminated instead.
     *
     * @param int $claimedAt hrtime() taken before the claim, which wrote the first activity
     */
    protected function keepActive(
        LoopInterface $loop,
        Process $process,
        ScheduledJob $job,
        Group $group,
        int $claimedAt
    ): TimerInterface {
        $lastActivity = $claimedAt;
        $failing = false;
        $killAfter = max($group->getStaleJobTimeout() - self::KILL_MARGIN_IN_SECONDS, $group->getStaleJobTimeout() / 2);

        return $loop->addPeriodicTimer(
            interval: 1,
            callback: function () use ($process, $job, $killAfter, &$lastActivity, &$failing) {
                if (!$process->isRunning()) {
                    return;
                }
                if ($failing && (hrtime(true) - $lastActivity) / 1e9 >= $killAfter) {
                    $process->terminate();
                    return;
                }
                $attempt = hrtime(true);
                try {
                    $this->scheduler->activity($job);
                    $lastActivity = $attempt;
                    $failing = false;
                } catch (DatabaseException $exception) {
                    $this->throwableStorage->logThrowable($exception);
                    $failing = true;
                }
            }
        );
    }

    /**
     * A lost release lets resetStaleJobs free the job, which then runs a second time.
     */
    protected function releaseEventually(LoopInterface $loop, ScheduledJob $job): void
    {
        $timer = null;
        $attempt = function () use ($loop, $job, &$timer, &$attempt): void {
            try {
                $this->scheduler->release($job);
            } catch (DatabaseException $exception) {
                $this->throwableStorage->logThrowable($exception);
                $timer ??= $loop->addPeriodicTimer(interval: 1, callback: $attempt);
                return;
            }
            if ($timer !== null) {
                $loop->cancelTimer($timer);
            }
        };
        $attempt();
    }

    /**
     * An uncaught exception ends $loop->run() and orphans every running job, so
     * database failures are logged instead. Anything else still ends the process.
     */
    protected function survivingDatabaseErrors(Closure $callback): Closure
    {
        return function (...$arguments) use ($callback): void {
            try {
                $callback(...$arguments);
            } catch (DatabaseException $exception) {
                $this->throwableStorage->logThrowable($exception);
            }
        };
    }

    /**
     * @param array<string, Group> $groups
     * @param array<string, Pool> $pools
     * @return list<Group>
     */
    protected static function groupsWithCapacity(array $groups, array $pools): array
    {
        return array_values(array_filter(
            $groups,
            static fn (Group $group) => $pools[$group->getName()]->count() < $group->getParallel()
        ));
    }

    /**
     * @param array<string, Pool> $pools
     */
    protected static function countRunningJobs(array $pools): int
    {
        return array_sum(array_map(static fn (Pool $pool) => $pool->count(), $pools));
    }

    /**
     * @param array<string, Group> $groups
     */
    protected static function pollingInterval(array $groups): float
    {
        return min(array_map(static fn (Group $group) => $group->getPollingInterval(), $groups));
    }
}
