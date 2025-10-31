<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Neos\Flow\Utility\Algorithms;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\DueDateCalculation\TimeBaseForDueDateCalculation;
use Netlogix\JobQueue\Scheduled\Service\Connection;
use Netlogix\Retry\Retry;

use function array_filter;
use function in_array;
use function sprintf;

class Scheduler
{
    public const DEFAULT_GROUP_NAME = 'default';

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
    protected $timeBaseForDueDateCalculation;

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
              AND claimed = ""
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
        $tableName = ScheduledJob::TABLE_NAME;

        $update =
        /**
         * Step 1: Create a derived table using the "idx_for_update" index
         *         that only contains one row.
         * Step 2: Join that row against the actual job to be claimed on
         *         the primary key column.
         * Step 3: UPDATE that row with the claim value.
         *
         * Otherwise, MySQL would use the "idx_groupname" index, fetch
         * millions of rows, use a temp table to sort those millions
         * and limit the result to the one row to be claimed.
         *
         * @lang MySQL
         */
        <<<"MySQL"
            UPDATE (SELECT identifier
                    FROM {$tableName}
                    WHERE duedate <= :now
                      AND groupname = :groupname
                      AND claimed = ""
                      AND running = 0
                    ORDER BY duedate ASC
                    LIMIT 1) AS delinquents
                INNER JOIN {$tableName}
                USING (identifier)
            SET claimed = :claimed,
                running = 1,
                activity = NOW()
            WHERE claimed = ""
            MySQL;
        (new Retry())
            /**
             * @see http://backoffcalculator.com/?attempts=5&rate=1&interval=0.5
             */
            ->withExponentialBackoff(retryInterval: 0.5, maxRetries: 5)
            ->onExceptionsOfType(RetryableException::class)
            ->task(fn() => $this->dbal
                ->executeQuery(
                    $update,
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

        $select = /** @lang MySQL */ <<<MySQL
            SELECT identifier, duedate, queue, job, incarnation, claimed, running
            FROM {$tableName}
            WHERE claimed = :claimed
              AND groupname = :groupname
            MySQL;
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
            ->fetch();

        if (!$row) {
            return null;
        }

        return ScheduledJob::createInternal(
            job: $row['job'],
            queue: $row['queue'],
            duedate: new DateTimeImmutable($row['duedate']),
            groupName: $groupName,
            identifier: (string)$row['identifier'],
            incarnation: (int)$row['incarnation'],
            claimed: (string)$row['claimed'],
            running: (bool)$row['running']
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
            $free = /** @lang MySQL */ <<<"MySQL"
                UPDATE {$tableName}
                SET running = 0,
                    activity = NOW()
                WHERE groupname = :groupname
                  AND identifier = :identifier
                  AND claimed = ""
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
        $tableName = ScheduledJob::TABLE_NAME;

        $update = /** @lang MySQL */ <<<"MySQL"
            UPDATE {$tableName}
            SET claimed = :failed,
                running = 0,
                activity = NOW()
            WHERE identifier = :identifier
              AND claimed = :claimed
            LIMIT 1
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
    }

    public function activity(ScheduledJob $job): void
    {
        $tableName = ScheduledJob::TABLE_NAME;

        $update = /** @lang MySQL */ <<<"MySQL"
            UPDATE {$tableName}
            SET activity = NOW()
            WHERE identifier = :identifier
              AND claimed = :claimed
            LIMIT 1
        MySQL;
        $this->dbal
            ->executeQuery(
                $update,
                [
                    'identifier' => $job->getIdentifier(),
                    'claimed' => $job->getClaimed(),
                ],
                [
                    'identifier' => Types::STRING,
                    'claimed' => Types::STRING,
                ]
            );
    }

    protected function scheduleJob(ScheduledJob $job): void
    {
        $this->validateGroupName($job->getGroupName());
        $tableName = ScheduledJob::TABLE_NAME;

        $statement = /** @lang MySQL */ <<<MySQL
            INSERT INTO {$tableName}
                (groupname, identifier, duedate, activity, queue, job, incarnation, claimed, running)
            VALUES (:groupname, :identifier, :duedate, NOW(), :queue, :job, :incarnation, :claimed, :running)
            ON DUPLICATE KEY
                UPDATE
                    duedate = CASE
                           WHEN running = 1
                               -- If the job is already running, this is "another one",
                               -- so schedule the next one according to its own date.
                               THEN :duedate
                           WHEN duedate < :duedate
                               -- If this reschedules a waiting job, use the lower value
                               THEN duedate
                           ELSE
                               :duedate
                       END,
                       incarnation = :incarnation,
                       queue    = :queue,
                       job      = :job,
                       claimed  = :claimed
            MySQL;

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
                        'running' => $job->isRunning() ? 1 : 0,
                    ],
                    [
                        'groupname' => Types::STRING,
                        'identifier' => Types::STRING,
                        'duedate' => Types::DATETIME_IMMUTABLE,
                        'queue' => Types::STRING,
                        'job' => Types::BLOB,
                        'incarnation' => Types::INTEGER,
                        'claimed' => Types::STRING,
                        'running' => Types::BOOLEAN,
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
