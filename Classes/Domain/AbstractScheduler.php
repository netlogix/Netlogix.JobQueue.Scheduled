<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Neos\Flow\Utility\Algorithms;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\DueDateCalculation\TimeBaseForDueDateCalculation;
use Netlogix\JobQueue\Scheduled\Service\Connection;
use Neos\Flow\Annotations as Flow;
use Throwable;

use function array_fill_keys;
use function array_keys;
use function array_values;
use function implode;
use function in_array;
use function sprintf;

abstract class AbstractScheduler implements Scheduler
{
    /**
     * @var Connection
     */
    protected $dbal;

    /**
     * @var string[]
     */
    protected array $activeGroupNames = [self::DEFAULT_GROUP_NAME];

    /**
     * @var TimeBaseForDueDateCalculation
     */
    protected TimeBaseForDueDateCalculation $timeBaseForDueDateCalculation;

    /**
     * Step 1 of claiming a job: tag one due row with the claim value.
     *
     * One branch per group, each limited to its own oldest due job, combined by
     * UNION ALL and narrowed down to a single row afterwards. Every branch is an
     * equality lookup on "groupname" and therefore keeps using idx_for_update,
     * which a "groupname IN (…)" would give up: duedate is only ordered within
     * one group, so sorting across groups would need a filesort.
     *
     * Group names are bound as :g0 … :gn, see claimParameters().
     */
    abstract protected function buildClaimQuery(string ...$groupNames): string;

    /**
     * Step 2 of claiming a job: read the row that carries the claim value.
     *
     * Filters on the claim value alone - which group was hit is unknown until
     * the row has been read, and a claim is a UUID.
     */
    abstract protected function buildSelectQuery(): string;

    /**
     * Step 3 of claiming a job: unlock the row so parallel processes may
     * overwrite the claim value again. Unrelated to release().
     */
    abstract protected function buildReleaseQuery(): string;

    abstract protected function buildScheduleQuery(): string;

    abstract protected function buildResetStaleJobsQuery(): string;

    protected function buildIsScheduledQuery(): string
    {
        $tableName = ScheduledJob::TABLE_NAME;

        return /** @lang MySQL */ <<<"MySQL"
            SELECT 1 FROM {$tableName}
            WHERE identifier = :identifier
              AND groupname = :groupname
              AND claimed = ''
            MySQL;
    }

    protected function buildDeleteJobQuery(): string
    {
        $tableName = ScheduledJob::TABLE_NAME;

        return /** @lang MySQL */ <<<"MySQL"
            DELETE FROM {$tableName}
            WHERE groupname = :groupname
              AND identifier = :identifier
              AND claimed = :claimed
            MySQL;
    }

    /**
     * Fallback of release(): the row was rescheduled while running, so it must
     * not be deleted but freed for the next run.
     */
    protected function buildFreeJobQuery(): string
    {
        $tableName = ScheduledJob::TABLE_NAME;

        return /** @lang MySQL */ <<<"MySQL"
            UPDATE {$tableName}
            SET running = 0,
                activity = NOW()
            WHERE groupname = :groupname
              AND identifier = :identifier
              AND claimed = ''
            MySQL;
    }

    protected function buildFailQuery(): string
    {
        $tableName = ScheduledJob::TABLE_NAME;

        return /** @lang MySQL */ <<<"MySQL"
            UPDATE {$tableName}
            SET claimed = :failed,
                running = 0,
                activity = NOW()
            WHERE identifier = :identifier
            MySQL;
    }

    protected function buildActivityQuery(): string
    {
        $tableName = ScheduledJob::TABLE_NAME;

        return /** @lang MySQL */ <<<"MySQL"
            UPDATE {$tableName}
            SET activity = NOW()
            WHERE identifier = :identifier
            MySQL;
    }

    public function injectConnection(Connection $connection): void
    {
        $this->dbal = $connection;
    }

    public function injectTimeBaseForDueDateCalculation(TimeBaseForDueDateCalculation $timeBaseForDueDateCalculation
    ): void {
        $this->timeBaseForDueDateCalculation = $timeBaseForDueDateCalculation;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function injectSettings(array $settings): void
    {
        $this->activeGroupNames = Group::activeNames($settings['groups'] ?? []);
        if (!$this->activeGroupNames) {
            $this->activeGroupNames = [self::DEFAULT_GROUP_NAME];
        }
    }

    public function schedule(ScheduledJob $job, ScheduledJob ...$jobs): void
    {
        $jobs = func_get_args();
        foreach ($jobs as $job) {
            $this->scheduleJob($job);
        }
    }

    public function isScheduled(string $groupName, string $identifier): bool
    {
        $this->validateGroupName($groupName);

        $statement = $this->buildIsScheduledQuery();

        return $this->dbal->fetchOne($statement, ['identifier' => $identifier, 'groupname' => $groupName]) !== false;
    }

    public function ping(): void
    {
        $this->dbal->ping();
    }

    public function next(string $groupName, string ...$furtherGroupNames): ?ScheduledJob
    {
        // array_values(): named arguments end up in the variadic as string keys,
        // which would break the positional :g0 … :gn binding.
        $groupNames = array_values([$groupName, ...$furtherGroupNames]);
        foreach ($groupNames as $name) {
            $this->validateGroupName($name);
        }
        $claim = Algorithms::generateUUID();

        $groupParameters = self::claimParameters($groupNames);

        $this->dbal
            ->executeQuery(
                sql: $this->buildClaimQuery(...$groupNames),
                params: [
                    'now' => $this->timeBaseForDueDateCalculation->getNow(),
                    'claimed' => $claim,
                    ...$groupParameters,
                ],
                types: [
                    'now' => Types::DATETIME_IMMUTABLE,
                    'claimed' => Types::STRING,
                    ...array_fill_keys(array_keys($groupParameters), Types::STRING),
                ],
                logContext: fn (Throwable $throwable, int $incarnation) => [
                    'claim' => $claim,
                    'groupName' => implode(', ', $groupNames),
                    'step' => 'claim',
                ]
            );

        $row = $this->dbal
            ->executeQuery(
                sql: $this->buildSelectQuery(),
                params: [
                    'claimed' => $claim,
                ],
                types: [
                    'claimed' => Types::STRING,
                ]
            )
            ->fetchAssociative();

        if (!$row) {
            return null;
        }

        $this->dbal
            ->executeQuery(
                sql: $this->buildReleaseQuery(),
                params: [
                    'claimed' => $claim,
                ],
                types: [
                    'claimed' => Types::STRING,
                ],
                logContext: fn (Throwable $throwable, int $incarnation) => [
                    'claim' => $claim,
                    'groupName' => (string) $row['groupname'],
                    'step' => 'release',
                ]
            );

        return ScheduledJob::createInternal(
            job: $row['job'],
            queue: $row['queue'],
            duedate: new DateTimeImmutable($row['duedate']),
            groupName: (string) $row['groupname'],
            identifier: (string) $row['identifier'],
            incarnation: (int) $row['incarnation'],
            claimed: (string) $row['claimed'],
            running: (int) $row['running']
        );
    }

    /**
     * Binds group names as :g0 … :gn, matching the branches of the claim query.
     *
     * @param list<string> $groupNames
     * @return array<string, string>
     */
    protected static function claimParameters(array $groupNames): array
    {
        $parameters = [];
        foreach ($groupNames as $index => $groupName) {
            $parameters['g' . $index] = $groupName;
        }

        return $parameters;
    }

    public function release(ScheduledJob $job): void
    {
        if ($job->getClaimed() === '') {
            throw new InvalidArgumentException('Cannot release unclaimed jobs', 1657027508);
        }
        $delete = $this->buildDeleteJobQuery();
        $deleteResult = $this->dbal
            ->executeQuery(
                sql: $delete,
                params: [
                    'groupname' => $job->getGroupName(),
                    'identifier' => $job->getIdentifier(),
                    'claimed' => $job->getClaimed(),
                ]
            );
        if ($deleteResult->rowCount() === 0) {
            $free = $this->buildFreeJobQuery();
            $this->dbal
                ->executeQuery(
                    sql: $free,
                    params: [
                        'groupname' => $job->getGroupName(),
                        'identifier' => $job->getIdentifier(),
                    ]
                );
        }
    }

    public function fail(ScheduledJob $job, string $reason): void
    {
        if ($job->getClaimed() === '') {
            throw new InvalidArgumentException('Cannot fail unclaimed jobs', 1718808398);
        }

        $reason = substr($reason, 0, 36);

        $update = $this->buildFailQuery();
        $this->dbal
            ->executeQuery(
                sql: $update,
                params: [
                    'identifier' => $job->getIdentifier(),
                    'claimed' => $job->getClaimed(),
                    'failed' => sprintf('failed(%s)', $reason),
                ],
                types: [
                    'identifier' => Types::STRING,
                    'claimed' => Types::STRING,
                    'failed' => Types::STRING,
                ]
            );

        $this->emitFailed($job, $reason);
    }

    /**
     * @param ScheduledJob $job
     * @param string $reason
     * @return void
     * @Flow\Signal
     */
    public function emitFailed(ScheduledJob $job, string $reason): void
    {
    }

    public function activity(ScheduledJob $job): void
    {
        $update = $this->buildActivityQuery();
        $this->dbal
            ->executeQuery(
                sql: $update,
                params: [
                    'identifier' => $job->getIdentifier(),
                ],
                types: [
                    'identifier' => Types::STRING,
                ]
            );
    }

    /**
     * Reset stale jobs of one group that have not changed for too long.
     *
     * One statement per group: the threshold is the group's own staleJobTimeout,
     * and a single UPDATE cannot apply a different one per row.
     *
     * @return int Number of freed jobs
     * @throws Exception
     */
    public function resetStaleJobs(string $groupName): int
    {
        return $this->dbal
            ->executeQuery(
                sql: $this->buildResetStaleJobsQuery(),
                params: [
                    'groupName' => $groupName,
                    'seconds' => max(Group::get($groupName)->getStaleJobTimeout(), 1),
                ],
                types: [
                    'groupName' => Types::STRING,
                    'seconds' => Types::SMALLINT,
                ],
            )->rowCount();
    }

    protected function scheduleJob(ScheduledJob $job): void
    {
        $this->validateGroupName($job->getGroupName());
        $statement = $this->buildScheduleQuery();

        $this->dbal
            ->executeQuery(
                sql: $statement,
                params: [
                    'groupname' => $job->getGroupName(),
                    'identifier' => $job->getIdentifier(),
                    'duedate' => $job->getDuedate(),
                    'queue' => $job->getQueueName(),
                    'job' => $job->getSerializedJob(),
                    'incarnation' => $job->getIncarnation(),
                    'claimed' => $job->getClaimed(),
                    'running' => $job->getRunning(),
                ],
                types: [
                    'groupname' => Types::STRING,
                    'identifier' => Types::STRING,
                    'duedate' => Types::DATETIME_IMMUTABLE,
                    'queue' => Types::STRING,
                    'job' => Types::BLOB,
                    'incarnation' => Types::INTEGER,
                    'claimed' => Types::STRING,
                    'running' => Types::INTEGER,
                ],
                logContext: fn (Throwable $throwable, int $incarnation) => [
                    'groupName' => $job->getGroupName(),
                    'step' => 'schedule',
                ]
            );
        // TODO: Find a way to "trigger queueing" without cronjobs. Maybe "dynamic cronjobs" like "at".
        // TODO: On Shutdown: Add queueing job to job queue.
    }

    protected function validateGroupName(string $groupName): void
    {
        if (!in_array($groupName, $this->activeGroupNames, true)) {
            throw new InvalidArgumentException(\sprintf('Group name "%s" is not active', $groupName), 1721393320);
        }
    }

    public function getConnection(): Connection
    {
        return $this->dbal;
    }

}
