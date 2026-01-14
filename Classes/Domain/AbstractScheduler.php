<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Neos\Flow\Utility\Algorithms;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\DueDateCalculation\TimeBaseForDueDateCalculation;
use Netlogix\JobQueue\Scheduled\Service\Connection;
use Netlogix\Retry\Retry;
use Neos\Flow\Annotations as Flow;

use function array_filter;
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

    protected const CLAIM_QUERY = "";
    protected const SELECT_QUERY = "";
    protected const RELEASE_QUERY = "";
    protected const SCHEDULE_QUERY = "";
    protected const RESET_STALE_JOBS_QUERY = "";

    public function injectConnection(Connection $connection): void
    {
        $this->dbal = $connection;
    }

    public function injectTimeBaseForDueDateCalculation(TimeBaseForDueDateCalculation $timeBaseForDueDateCalculation): void
    {
        $this->timeBaseForDueDateCalculation = $timeBaseForDueDateCalculation;
    }

    public function injectSettings(array $settings)
    {
        $activeGroupNames = array_filter($settings['groupNames'] ?? []);
        $this->activeGroupNames = array_keys($activeGroupNames);
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
        $tableName = ScheduledJob::TABLE_NAME;

        $statement = <<<"MySQL"
            SELECT 1 FROM {$tableName}
            WHERE identifier = :identifier
              AND groupname = :groupname
              AND claimed = ''
            MySQL;

        return $this->dbal->fetchOne($statement, ['identifier' => $identifier, 'groupname' => $groupName]) !== false;
    }

    public function ping(): void
    {
        $this->dbal->ping();
    }

    public function next(string $groupName): ?ScheduledJob
    {
        $this->validateGroupName($groupName);
        $claim = Algorithms::generateUUID();

        $claimQuery = static::CLAIM_QUERY;
        (new Retry())
            /**
             * @see http://backoffcalculator.com/?attempts=5&rate=1&interval=0.5
             */
            ->withExponentialBackoff(retryInterval: 0.5, maxRetries: 5)
            ->onExceptionsOfType(RetryableException::class)
            ->task(fn() => $this->dbal
                ->executeQuery(
                    $claimQuery,
                    [
                        'now' => $this->timeBaseForDueDateCalculation->getNow(),
                        'groupname' => $groupName,
                        'claimed' => $claim,
                    ],
                    [
                        'now' => Types::DATETIME_IMMUTABLE,
                        'groupname' => Types::STRING,
                        'claimed' => Types::STRING,
                    ]
                ));

        $select = static::SELECT_QUERY;

        $row = $this->dbal
            ->executeQuery(
                $select,
                [
                    'groupname' => $groupName,
                    'claimed' => $claim,
                ],
                [
                    'groupname' => Types::STRING,
                    'claimed' => Types::STRING,
                ]
            )
            ->fetchAssociative();

        if (!$row) {
            return null;
        }

        $release = static::RELEASE_QUERY;

        (new Retry())
            /**
             * @see http://backoffcalculator.com/?attempts=5&rate=1&interval=0.5
             */
            ->withExponentialBackoff(retryInterval: 0.5, maxRetries: 5)
            ->onExceptionsOfType(RetryableException::class)
            ->task(fn() => $this->dbal
                ->executeQuery(
                    $release,
                    [
                        'groupname' => $groupName,
                        'claimed' => $claim,
                    ],
                    [
                        'groupname' => Types::STRING,
                        'claimed' => Types::STRING,
                    ]
                ));

        return ScheduledJob::createInternal(
            job: $row['job'],
            queue: $row['queue'],
            duedate: new DateTimeImmutable($row['duedate']),
            groupName: $groupName,
            identifier: (string)$row['identifier'],
            incarnation: (int)$row['incarnation'],
            claimed: (string)$row['claimed'],
            running: (int)$row['running']
        );
    }

    public function release(ScheduledJob $job): void
    {
        if ($job->getClaimed() === '') {
            throw new InvalidArgumentException('Cannot release unclaimed jobs', 1657027508);
        }
        $tableName = ScheduledJob::TABLE_NAME;

        $delete = /** @lang MySQL */ <<<"MySQL"
            DELETE FROM {$tableName}
            WHERE groupname = :groupname
              AND identifier = :identifier
              AND claimed = :claimed
            MySQL;
        $deleteResult = $this->dbal
            ->executeQuery(
                $delete,
                [
                    'groupname' => $job->getGroupName(),
                    'identifier' => $job->getIdentifier(),
                    'claimed' => $job->getClaimed(),
                ]
            );
        if ($deleteResult->rowCount() === 0) {
            $free = /** @lang MySQL */ <<<MySQL
                UPDATE {$tableName}
                SET running = 0,
                    activity = NOW()
                WHERE groupname = :groupname
                  AND identifier = :identifier
                  AND claimed = ''
            MySQL;
            $this->dbal
                ->executeQuery(
                    $free,
                    [
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

        $tableName = ScheduledJob::TABLE_NAME;

        $update = /** @lang MySQL */ <<<MySQL
            UPDATE {$tableName}
            SET claimed = :failed,
                running = 0,
                activity = NOW()
            WHERE identifier = :identifier
        MySQL;
        $this->dbal
            ->executeQuery(
                $update,
                [
                    'identifier' => $job->getIdentifier(),
                    'claimed' => $job->getClaimed(),
                    'failed' => sprintf('failed(%s)', $reason),
                ],
                [
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
    public function emitFailed(ScheduledJob $job, string $reason): void {}

    public function activity(ScheduledJob $job): void
    {
        $tableName = ScheduledJob::TABLE_NAME;

        $update = /** @lang MySQL */ <<<"MySQL"
            UPDATE {$tableName}
            SET activity = NOW()
            WHERE identifier = :identifier
            MySQL;
        $this->dbal
            ->executeQuery(
                $update,
                [
                    'identifier' => $job->getIdentifier(),
                ],
                [
                    'identifier' => Types::STRING,
                ]
            );
    }

    /**
     * Reset stale jobs that have not changed for too long.
     *
     * @param string $groupName Free jobs in this group only
     * @param int $minutes Count jobs as stale if their last activity was more than these many minutes ago
     * @throws Exception
     * @return int Number of freed jobs
     */
    public function resetStaleJobs(string $groupName, int $minutes): int {
        return $this->dbal->executeQuery(
            sql: static::RESET_STALE_JOBS_QUERY,
            params: [
                'groupName' => $groupName,
                'minutes' => max($minutes, 1),
            ],
            types: [
                'groupName' => Types::STRING,
                'minutes' => Types::SMALLINT,
            ],
        )->rowCount();
    }

    protected function scheduleJob(ScheduledJob $job): void
    {
        $this->validateGroupName($job->getGroupName());
        $statement = static::SCHEDULE_QUERY;

        (new Retry())
            /**
             * @see http://backoffcalculator.com/?attempts=5&rate=1&interval=0.1
             */
            ->withExponentialBackoff(retryInterval: 0.05, maxRetries: 5)
            ->onExceptionsOfType(RetryableException::class)
            ->task(fn() => $this->dbal
                ->executeQuery(
                    $statement,
                    [
                        'groupname' => $job->getGroupName(),
                        'identifier' => $job->getIdentifier(),
                        'duedate' => $job->getDuedate(),
                        'queue' => $job->getQueueName(),
                        'job' => $job->getSerializedJob(),
                        'incarnation' => $job->getIncarnation(),
                        'claimed' => $job->getClaimed(),
                        'running' => $job->getRunning(),
                    ],
                    [
                        'groupname' => Types::STRING,
                        'identifier' => Types::STRING,
                        'duedate' => Types::DATETIME_IMMUTABLE,
                        'queue' => Types::STRING,
                        'job' => Types::BLOB,
                        'incarnation' => Types::INTEGER,
                        'claimed' => Types::STRING,
                        'running' => Types::INTEGER,
                    ]
                ));
        // TODO: Find a way to "trigger queueing" without cronjobs. Maybe "dynamic cronjobs" like "at".
        // TODO: On Shutdown: Add queueing job to job queue.
    }

    protected function validateGroupName(string $groupName): void
    {
        if (!in_array($groupName, $this->activeGroupNames, true)) {
            throw new InvalidArgumentException(\sprintf('Group name "%s" is not active', $groupName), 1721393320);
        }
    }
}
