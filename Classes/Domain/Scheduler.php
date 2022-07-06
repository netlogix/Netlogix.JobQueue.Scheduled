<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Neos\Flow\Utility\Algorithms;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\DueDateCalculation\TimeBaseForDueDateCalculation;

class Scheduler
{
    /**
     * @var Connection
     */
    protected $dbal;

    /**
     * @var TimeBaseForDueDateCalculation
     */
    protected $timeBaseForDueDateCalculation;

    public function injectEntityManager(EntityManagerInterface $entityManager): void
    {
        $this->dbal = $entityManager->getConnection();
    }

    public function injectTimeBaseForDueDateCalculation(TimeBaseForDueDateCalculation $timeBaseForDueDateCalculation): void
    {
        $this->timeBaseForDueDateCalculation = $timeBaseForDueDateCalculation;
    }

    public function schedule(ScheduledJob $job, ScheduledJob ...$jobs): void
    {
        $jobs = func_get_args();
        foreach ($jobs as $job) {
            $this->scheduleJob($job);
        }
    }

    public function isScheduled(string $identifier): bool
    {
        $statement = '
            SELECT 1 FROM ' . ScheduledJob::TABLE_NAME . '
            WHERE identifier = :identifier
            AND claimed = ""
       ';

        return $this->dbal->fetchOne($statement, ['identifier' => $identifier]) !== false;
    }

    public function next(): ?ScheduledJob
    {
        $claim = Algorithms::generateUUID();

        $update = /** @lang MySQL */ '
            UPDATE ' . ScheduledJob::TABLE_NAME . '
            SET claimed = :claimed
            WHERE duedate <= :now
                  AND claimed = ""
            ORDER BY duedate ASC
            LIMIT 1
        ';
        $this->dbal
            ->executeQuery(
                $update,
                [
                    'now' => $this->timeBaseForDueDateCalculation->getNow(),
                    'claimed' => $claim,
                ],
                [
                    'now' => Types::DATETIME_IMMUTABLE,
                    'claimed' => Types::STRING,
                ]
            );

        $select = /** @lang MySQL */ '
            SELECT identifier, duedate, queue, job, incarnation, claimed
            FROM ' . ScheduledJob::TABLE_NAME . '
            WHERE claimed = :claimed
        ';
        $row = $this->dbal
            ->executeQuery(
                $select,
                [
                    'claimed' => $claim,
                ],
                [
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
            WHERE identifier = :identifier
                  AND claimed = :claimed
        ';
        $this->dbal
            ->executeQuery(
                $delete,
                [
                    'identifier' => $job->getIdentifier(),
                    'claimed' => $job->getClaimed(),
                ]
            );

    }

    protected function scheduleJob(ScheduledJob $job): void
    {
        $statement = '
            INSERT INTO ' . ScheduledJob::TABLE_NAME . '
                (identifier, duedate, queue, job, incarnation, claimed)
            VALUES (:identifier, :duedate, :queue, :job, :incarnation, :claimed)
            ON DUPLICATE KEY
                UPDATE duedate = IF(:duedate < duedate, :duedate, duedate),
                       incarnation = IF(:incarnation < incarnation, :incarnation, incarnation),
                       queue    = :queue,
                       job      = :job,
                       claimed  = :claimed
       ';
        $this->dbal
            ->executeQuery(
                $statement,
                [
                    'identifier' => $job->getIdentifier(),
                    'duedate' => $job->getDuedate(),
                    'queue' => $job->getQueueName(),
                    'job' => serialize($job->getJob()),
                    'incarnation' => $job->getIncarnation(),
                    'claimed' => $job->getClaimed(),
                ],
                [
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
}
