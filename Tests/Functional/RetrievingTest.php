<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional;

use DateTimeImmutable;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;

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
        $job = $this->scheduler->next();

        self::assertNull($job);
    }

    /**
     * @test
     */
    public function Without_scheduled_jobs_none_can_be_found_by_identifier(): void
    {
        self::assertFalse($this->scheduler->isScheduled('my-identifier'));
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
                'my-identifier'
            )
        );

        self::assertTrue($this->scheduler->isScheduled('my-identifier'));
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
                $this->now->modify('+ 1 day')
            )
        );

        $retrievedJob = $this->scheduler->next();

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
            $this->now->modify('- 1 day')
        );
        $this->scheduler->schedule($scheduledJob);

        $retrievedJob = $this->scheduler->next();

        self::assertInstanceOf(ScheduledJob::class, $retrievedJob);
        self::assertEquals($scheduledJob, $retrievedJob);
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
                self::getDueDate()
            )
        );

        $retrievedJob1 = $this->scheduler->next();
        $retrievedJob2 = $this->scheduler->next();

        self::assertNotNull($retrievedJob1);
        self::assertNull($retrievedJob2);
    }
}
