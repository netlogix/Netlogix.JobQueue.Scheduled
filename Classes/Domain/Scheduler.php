<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Neos\Flow\Utility\Algorithms;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\DueDateCalculation\TimeBaseForDueDateCalculation;
use Netlogix\JobQueue\Scheduled\Service\Connection;

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

        $statement = '
            SELECT 1 FROM ' . ScheduledJob::TABLE_NAME . '
            WHERE identifier = :identifier
            AND groupname = :groupname
            AND claimed = ""
       ';

        return $this->dbal->fetchOne($statement, ['identifier' => $identifier, 'groupname' => $groupName]) !== false;
    }

    public function next(string $groupName): ?ScheduledJob
    {
        $this->validateGroupName($groupName);
        $claim = Algorithms::generateUUID();

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
        '
            UPDATE (SELECT identifier
                    FROM ' . ScheduledJob::TABLE_NAME . '
                    WHERE duedate <= :now
                      AND groupname = :groupname
                      AND claimed = ""
                    ORDER BY duedate ASC
                    LIMIT 1) AS delinquents
                INNER JOIN ' . ScheduledJob::TABLE_NAME . '
                USING (identifier)
            SET claimed = :claimed
            WHERE claimed = ""
        ';
        $this->dbal
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
            );

        $select = /** @lang MySQL */ '
            SELECT identifier, duedate, queue, job, incarnation, claimed
            FROM ' . ScheduledJob::TABLE_NAME . '
            WHERE claimed = :claimed
            AND groupname = :groupname
        ';
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

        return new ScheduledJob(
            unserialize($row['job']),
            $row['queue'],
            new DateTimeImmutable($row['duedate']),
            $groupName,
            (string)$row['identifier'],
            (int)$row['incarnation'],
            (string)$row['claimed']
        );
    }

    public function release(ScheduledJob $job): void
    {
        if ($job->getClaimed() === '') {
            throw new InvalidArgumentException('Cannot release unclaimed jobs', 1657027508);
        }
        $delete = /** @lang MySQL */ '
            DELETE FROM ' . ScheduledJob::TABLE_NAME . '
            WHERE groupname = :groupname
                  AND identifier = :identifier
                  AND claimed = :claimed
        ';
        $this->dbal
            ->executeQuery(
                $delete,
                [
                    'groupname' => $job->getGroupName(),
                    'identifier' => $job->getIdentifier(),
                    'claimed' => $job->getClaimed(),
                ]
            );
    }

    public function fail(ScheduledJob $job, string $reason): void
    {
        if ($job->getClaimed() === '') {
            throw new InvalidArgumentException('Cannot fail unclaimed jobs', 1718808398);
        }

        $update = /** @lang MySQL */ '
            UPDATE ' . ScheduledJob::TABLE_NAME . '
            SET claimed = :failed
            WHERE identifier = :identifier
                  AND claimed = :claimed
            LIMIT 1
        ';
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
                ]
            );
    }

    protected function scheduleJob(ScheduledJob $job): void
    {
        $this->validateGroupName($job->getGroupName());

        $statement = /** @lang MySQL */ '
            INSERT INTO ' . ScheduledJob::TABLE_NAME . '
                (groupname, identifier, duedate, queue, job, incarnation, claimed)
            VALUES (:groupname, :identifier, :duedate, :queue, :job, :incarnation, :claimed)
            ON DUPLICATE KEY
                UPDATE duedate = IF(:duedate < duedate, :duedate, duedate),
                       incarnation = :incarnation,
                       queue    = :queue,
                       job      = :job,
                       claimed  = :claimed
       ';
        $this->dbal
            ->executeQuery(
                $statement,
                [
                    'groupname' => $job->getGroupName(),
                    'identifier' => $job->getIdentifier(),
                    'duedate' => $job->getDuedate(),
                    'queue' => $job->getQueueName(),
                    'job' => serialize($job->getJob()),
                    'incarnation' => $job->getIncarnation(),
                    'claimed' => $job->getClaimed(),
                ],
                [
                    'groupname' => Types::STRING,
                    'identifier' => Types::STRING,
                    'duedate' => Types::DATETIME_IMMUTABLE,
                    'queue' => Types::STRING,
                    'job' => Types::BLOB,
                    'incarnation' => Types::INTEGER,
                    'claimed' => Types::STRING,
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
}
