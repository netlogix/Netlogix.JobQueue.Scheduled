<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional;

use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;

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
                self::getDueDate()
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
                self::getDueDate()
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
                self::getDueDate()
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
                self::getDueDate()
            )
        );

        $scheduledJob = $this->findFirst();

        self::assertEquals(
            self::getDueDate(),
            $scheduledJob->getDuedate()
        );
    }
}
