<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional;

use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use Netlogix\JobQueue\Scheduled\Tests\Fixture\JobQueueJob;

class UniqueJobsTest extends TestCase
{
    /**
     * @test
     */
    public function Scheduled_jobs_can_specify_job_identifiers(): void
    {
        $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: self::getJobQueueJob(),
                queue: self::getQueueName(),
                duedate: self::getDueDate(),
                groupName: Scheduler::DEFAULT_GROUP_NAME,
                identifier: self::getJobIdentifier()
            )
        );

        $scheduledJob = $this->findFirst();

        self::assertEquals(
            self::getJobIdentifier(),
            $scheduledJob->getIdentifier()
        );
    }

    /**
     * @test
     */
    public function Multiple_jobs_with_conflicting_identifiers_get_only_persisted_once(): void
    {
        $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: self::getJobQueueJob(),
                queue: self::getQueueName(),
                duedate: self::getDueDate(),
                groupName: Scheduler::DEFAULT_GROUP_NAME,
                identifier: self::getJobIdentifier()
            )
        );

        $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: self::getJobQueueJob(),
                queue: self::getQueueName(),
                duedate: self::getDueDate(),
                groupName: Scheduler::DEFAULT_GROUP_NAME,
                identifier: self::getJobIdentifier()
            )
        );

        $all = $this->findAll();
        self::assertCount(1, $all);
    }

    /**
     * @test
     */
    public function Persisting_the_same_job_twice_overrules_the_existing_JobQueue_job(): void
    {
        $firstJob = self::getJobQueueJob();
        $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: self::getJobQueueJob(),
                queue: self::getQueueName(),
                duedate: self::getDueDate(),
                groupName: Scheduler::DEFAULT_GROUP_NAME,
                identifier: self::getJobIdentifier()
            )
        );

        $secondJob = JobQueueJob::second();
        $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: $secondJob,
                queue: self::getQueueName(),
                duedate: self::getDueDate(),
                groupName: Scheduler::DEFAULT_GROUP_NAME,
                identifier: self::getJobIdentifier()
            )
        );

        $retrievedJob = $this->findFirst();

        self::assertEquals($secondJob, $retrievedJob->getJob());
        self::assertNotEquals($firstJob, $retrievedJob->getJob());
    }

    /**
     * @test
     */
    public function Persisting_the_same_job_twice_overrules_the_existing_queue_name(): void
    {
        $firstQueueName = self::getQueueName();
        $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: self::getJobQueueJob(),
                queue: $firstQueueName,
                duedate: self::getDueDate(),
                groupName: Scheduler::DEFAULT_GROUP_NAME,
                identifier: self::getJobIdentifier()
            )
        );

        $secondQueueName = 'another-queue';
        $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: self::getJobQueueJob(),
                queue: $secondQueueName,
                duedate: self::getDueDate(),
                groupName: Scheduler::DEFAULT_GROUP_NAME,
                identifier: self::getJobIdentifier()
            )
        );

        $retrievedJob = $this->findFirst();

        self::assertEquals($secondQueueName, $retrievedJob->getQueueName());
        self::assertNotEquals($firstQueueName, $retrievedJob->getQueueName());
    }

    /**
     * @test
     */
    public function Persisting_the_same_job_twice_overrules_the_existing_due_date_if_the_new_one_is_before_the_existing_one(
    ): void
    {
        $firstDueDate = new \DateTimeImmutable('2020-01-01 00:00:00');
        $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: self::getJobQueueJob(),
                queue: self::getQueueName(),
                duedate: $firstDueDate,
                groupName: Scheduler::DEFAULT_GROUP_NAME,
                identifier: self::getJobIdentifier()
            )
        );

        $secondDueDate = $firstDueDate->modify('- 1 second');
        $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: self::getJobQueueJob(),
                queue: self::getQueueName(),
                duedate: $secondDueDate,
                groupName: Scheduler::DEFAULT_GROUP_NAME,
                identifier: self::getJobIdentifier()
            )
        );

        $retrievedJob = $this->findFirst();

        self::assertEquals($secondDueDate, $retrievedJob->getDuedate());
        self::assertNotEquals($firstDueDate, $retrievedJob->getDuedate());
    }

    /**
     * @test
     */
    public function Persisting_the_same_job_twice_overrules_the_existing_due_date_if_the_new_one_is_after_the_existing_one(
    ): void
    {
        $firstDueDate = new \DateTimeImmutable('2020-01-01 00:00:00');
        $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: self::getJobQueueJob(),
                queue: self::getQueueName(),
                duedate: $firstDueDate,
                groupName: Scheduler::DEFAULT_GROUP_NAME,
                identifier: self::getJobIdentifier()
            )
        );

        $secondDueDate = $firstDueDate->modify('+ 1 second');
        $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: self::getJobQueueJob(),
                queue: self::getQueueName(),
                duedate: $secondDueDate,
                groupName: Scheduler::DEFAULT_GROUP_NAME,
                identifier: self::getJobIdentifier()
            )
        );

        $retrievedJob = $this->findFirst();

        self::assertNotEquals($secondDueDate, $retrievedJob->getDuedate());
        self::assertEquals($firstDueDate, $retrievedJob->getDuedate());
    }

    protected static function getJobIdentifier(): string
    {
        return 'some-unique-identifier';
    }
}
