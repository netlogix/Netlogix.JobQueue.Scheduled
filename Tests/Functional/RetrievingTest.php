<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional;

use DateTimeImmutable;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;

class RetrievingTest extends TestCase
{
    /**
     * @var DateTimeImmutable
     */
    protected $now;

    public function setUp(): void
    {
        parent::setUp();

        $this->now = self::getDueDate();
    }

    /**
     * @test
     */
    public function Without_scheduled_jobs_there_can_be_none_retrieved(): void
    {
        $job = $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME);

        self::assertNull($job);
    }

    /**
     * @test
     */
    public function Without_scheduled_jobs_none_can_be_found_by_identifier(): void
    {
        self::assertFalse($this->scheduler->isScheduled(Scheduler::DEFAULT_GROUP_NAME, 'my-identifier'));
    }

    /**
     * @test
     */
    public function Scheduled_jobs_can_be_found_by_identifier(): void
    {
        $this->scheduler->schedule(
            new ScheduledJob(
                self::getJobQueueJob(),
                self::getQueueName(),
                $this->now->modify('+ 1 day'),
                Scheduler::DEFAULT_GROUP_NAME,
                'my-identifier'
            )
        );

        self::assertTrue($this->scheduler->isScheduled(Scheduler::DEFAULT_GROUP_NAME, 'my-identifier'));
    }

    /**
     * @test
     */
    public function Future_due_jobs_can_not_be_retrieved_yet(): void
    {
        $this->scheduler->schedule(
            new ScheduledJob(
                self::getJobQueueJob(),
                self::getQueueName(),
                $this->now->modify('+ 1 day'),
            Scheduler::DEFAULT_GROUP_NAME
            )
        );

        $retrievedJob = $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME);

        self::assertNull($retrievedJob);
    }

    /**
     * @test
     */
    public function Past_due_jobs_can_be_retrieved(): void
    {
        $scheduledJob = new ScheduledJob(
            self::getJobQueueJob(),
            self::getQueueName(),
            $this->now->modify('- 1 day'),
            Scheduler::DEFAULT_GROUP_NAME
        );
        $this->scheduler->schedule($scheduledJob);

        $retrievedJob = $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME);

        self::assertInstanceOf(ScheduledJob::class, $retrievedJob);
        assert($retrievedJob instanceof ScheduledJob);
        self::assertEquals($scheduledJob->getIdentifier(), $retrievedJob->getIdentifier());
    }

    /**
     * @test
     */
    public function Scheduled_jobs_can_be_retrieved_only_once(): void
    {
        $this->scheduler->schedule(
            new ScheduledJob(
                self::getJobQueueJob(),
                self::getQueueName(),
                self::getDueDate(),
                Scheduler::DEFAULT_GROUP_NAME
            )
        );

        $retrievedJob1 = $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME);
        $retrievedJob2 = $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME);

        self::assertNotNull($retrievedJob1);
        self::assertNull($retrievedJob2);
    }

    /**
     * @test
     */
    public function Scheduled_jobs_are_claimed(): void
    {
        $scheduledJob = new ScheduledJob(
            self::getJobQueueJob(),
            self::getQueueName(),
            $this->now->modify('- 1 day'),
            Scheduler::DEFAULT_GROUP_NAME
        );
        $this->scheduler->schedule($scheduledJob);

        $retrievedJob = $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME);

        self::assertInstanceOf(ScheduledJob::class, $retrievedJob);
        assert($retrievedJob instanceof ScheduledJob);
        self::assertNotEquals('', $retrievedJob->getClaimed());
    }
}
