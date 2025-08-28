<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional;

use Doctrine\DBAL\Exception\DeadlockException;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use Netlogix\JobQueue\Scheduled\Service\Connection;

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
            new ScheduledJob(
                self::getJobQueueJob(),
                self::getQueueName(),
                self::getDueDate(),
                Scheduler::DEFAULT_GROUP_NAME
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
            new ScheduledJob(
                self::getJobQueueJob(),
                self::getQueueName(),
                self::getDueDate(),
                Scheduler::DEFAULT_GROUP_NAME
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
            new ScheduledJob(
                self::getJobQueueJob(),
                self::getQueueName(),
                self::getDueDate(),
                Scheduler::DEFAULT_GROUP_NAME
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
            new ScheduledJob(
                self::getJobQueueJob(),
                self::getQueueName(),
                self::getDueDate(),
                Scheduler::DEFAULT_GROUP_NAME
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
        $connection = self::createMock(Connection::class);
        $connection->expects(self::any())
            ->method('executeQuery')
            ->willThrowException(self::createStub(DeadlockException::class));

        $this->scheduler->injectConnection($connection);

        $start = microtime(true);
        try {
            $this->scheduler->schedule(
                new ScheduledJob(
                    self::getJobQueueJob(),
                    self::getQueueName(),
                    self::getDueDate(),
                    Scheduler::DEFAULT_GROUP_NAME
                )
            );
        } catch (DeadlockException $e) {
        }
        $end = microtime(true);
        $delta = $end - $start;

        self::assertInstanceOf(DeadlockException::class, $e);

        // guesstimated value is about 1.5
        self::assertGreaterThan(1, $delta);
        self::assertLessThan(2, $delta);
    }
}
