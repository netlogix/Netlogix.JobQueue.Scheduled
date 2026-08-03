<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional;

use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\DBAL\Exception\DeadlockException;
use Neos\Flow\Log\ThrowableStorageInterface;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use Netlogix\JobQueue\Scheduled\Tests\Functional\Service\TestableConnection;

class SchedulingTest extends TestCase
{
    /**
     * @test
     */
    public function Without_scheduling_there_is_no_ScheduledJob(): void
    {
        $all = $this->findAll();
        self::assertCount(0, $all);
    }

    /**
     * @test
     */
    public function A_scheduled_job_gets_persisted(): void
    {
        $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: self::getJobQueueJob(),
                queue: self::getQueueName(),
                duedate: self::getDueDate(),
                groupName: Scheduler::DEFAULT_GROUP_NAME
            )
        );

        $all = $this->findAll();
        self::assertCount(1, $all);
    }

    /**
     * @test
     * @depends A_scheduled_job_gets_persisted
     */
    public function Scheduled_jobs_contain_JobQueue_jobs(): void
    {
        $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: self::getJobQueueJob(),
                queue: self::getQueueName(),
                duedate: self::getDueDate(),
                groupName: Scheduler::DEFAULT_GROUP_NAME
            )
        );

        $scheduledJob = $this->findFirst();

        self::assertEquals(
            self::getJobQueueJob(),
            $scheduledJob->getJob()
        );
    }

    /**
     * @test
     * @depends A_scheduled_job_gets_persisted
     */
    public function Scheduled_jobs_contain_queue_names(): void
    {
        $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: self::getJobQueueJob(),
                queue: self::getQueueName(),
                duedate: self::getDueDate(),
                groupName: Scheduler::DEFAULT_GROUP_NAME
            )
        );

        $scheduledJob = $this->findFirst();

        self::assertEquals(
            self::getQueueName(),
            $scheduledJob->getQueueName()
        );
    }

    /**
     * @test
     * @depends A_scheduled_job_gets_persisted
     */
    public function Scheduled_jobs_contain_due_dates(): void
    {
        $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: self::getJobQueueJob(),
                queue: self::getQueueName(),
                duedate: self::getDueDate(),
                groupName: Scheduler::DEFAULT_GROUP_NAME
            )
        );

        $scheduledJob = $this->findFirst();

        self::assertEquals(
            self::getDueDate(),
            $scheduledJob->getDuedate()
        );
    }

    /**
     * @test
     * @depends A_scheduled_job_gets_persisted
     */
    public function Scheduling_jobs_retries_RetryableExceptions(): void
    {
        // Retrying is the Connection's responsibility (see ConnectionTest), so
        // we drive the real retry over a DBAL connection that always deadlocks
        // instead of mocking the Connection away. The schedule query is
        // attempted once plus one per retry, then the exception propagates.
        $dbal = self::createMock(DBALConnection::class);
        $dbal->expects(self::exactly(TestableConnection::MAX_RETRIES + 1))
            ->method('executeQuery')
            ->willThrowException(self::createStub(DeadlockException::class));

        $connection = new TestableConnection($dbal, self::createMock(ThrowableStorageInterface::class));
        $this->scheduler->injectConnection($connection);

        $this->expectException(DeadlockException::class);
        $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: self::getJobQueueJob(),
                queue: self::getQueueName(),
                duedate: self::getDueDate(),
                groupName: Scheduler::DEFAULT_GROUP_NAME
            )
        );
    }
}
