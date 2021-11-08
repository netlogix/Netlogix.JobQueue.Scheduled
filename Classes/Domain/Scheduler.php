<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Domain;

use DateTimeImmutable;
use Doctrine\Common\Persistence\ObjectManager as DoctrineObjectManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager as DoctrineEntityManager;
use Neos\Flow\Utility\Now;
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

    public function injectDoctrineObjectManager(DoctrineObjectManager $entityManager): void
    {
        assert($entityManager instanceof DoctrineEntityManager);
        $this->dbal = $entityManager->getConnection();
    }

    public function injectNow(TimeBaseForDueDateCalculation $timeBaseForDueDateCalculation): void
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
       ';

        return $this->dbal->fetchOne($statement, ['identifier' => $identifier]) !== false;
    }

    public function next(): ?ScheduledJob
    {
        $statement = '
            SELECT identifier, duedate, queue, job
            FROM ' . ScheduledJob::TABLE_NAME . '
            WHERE duedate <= :now
            ORDER BY duedate ASC
            LIMIT 1
        ';
        $row = $this->dbal
            ->executeQuery(
                $statement,
                [
                    'now' => $this->timeBaseForDueDateCalculation->getNow()
                ],
                [
                    'now' => Types::DATETIME_IMMUTABLE
                ]
            )
            ->fetch();

        if (!$row) {
            return null;
        }
        // TODO: Claim jobs instead of deleting for retry
        $this->dbal
            ->delete(ScheduledJob::TABLE_NAME, ['identifier' => $row['identifier']]);

        return new ScheduledJob(
            unserialize($row['job']),
            $row['queue'],
            new DateTimeImmutable($row['duedate']),
            $row['identifier']
        );
    }

    protected function scheduleJob(ScheduledJob $job): void
    {
        $statement = '
            INSERT INTO ' . ScheduledJob::TABLE_NAME . '
                (identifier, duedate, queue, job)
            VALUES (:identifier, :duedate, :queue, :job)
            ON DUPLICATE KEY
                UPDATE duedate = IF(:duedate < duedate, :duedate, duedate),
                       queue    = :queue,
                       job      = :job
       ';
        $this->dbal
            ->executeQuery(
                $statement,
                [
                    'identifier' => $job->getIdentifier(),
                    'duedate' => $job->getDuedate(),
                    'queue' => $job->getQueueName(),
                    'job' => serialize($job->getJob())
                ],
                [
                    'identifier' => Types::STRING,
                    'duedate' => Types::DATETIME_IMMUTABLE,
                    'queue' => Types::STRING,
                    'job' => Types::BLOB
                ]
            );
        // TODO: Find a way to "trigger queueing" without cronjobs. Maybe "dynamic cronjobs" like "at".
        // TODO: On Shutdown: Add queueing job to job queue.
    }
}
