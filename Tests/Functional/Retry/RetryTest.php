<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional\Retry;

use DateInterval;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Retry;

class RetryTest extends TestCase
{
    /**
     * @test
     */
    public function Marking_a_job_for_rescheduling_does_not_schedule_it(): void
    {
        $job = new ScheduledJob(
            self::getJobQueueJob(),
            self::getQueueName(),
            $this->now,
            'my-identifier'
        );

        $retry = new Retry($this->scheduler);
        $retry->markJobForRescheduling($job);

        $all = $this->findAll();
        self::assertEmpty($all);
    }

    /**
     * @test
     */
    public function Marking_a_job_for_rescheduling_and_scheduling_all_does_not_schedule_them(): void
    {
        $jobA = new ScheduledJob(
            self::getJobQueueJob(),
            self::getQueueName(),
            $this->now,
            'my-first-identifier'
        );
        $jobB = new ScheduledJob(
            self::getJobQueueJob(),
            self::getQueueName(),
            $this->now,
            'my-second-identifier'
        );

        $retry = new Retry($this->scheduler);
        $retry->injectQueueManager($this->queueManager([]));
        $retry->markJobForRescheduling($jobA);
        $retry->markJobForRescheduling($jobB);
        $retry->scheduleAll();

        $all = $this->findAll();
        self::assertCount(2, $all);
    }

    /**
     * @test
     */
    public function Rescheduling_a_job_only_increases_its_incarnation(): void
    {
        $job = new ScheduledJob(
            self::getJobQueueJob(),
            self::getQueueName(),
            $this->now,
            'my-first-identifier'
        );

        $retry = new Retry($this->scheduler);
        $retry->injectQueueManager($this->queueManager([]));
        $retry->markJobForRescheduling($job);
        $retry->scheduleAll();

        $first = $this->findFirst();
        self::assertEquals(self::zeroInterval(), $job->getDuedate()->diff($first->getDuedate()));
        self::assertEquals($job->getQueueName(), $first->getQueueName());
        self::assertEquals($job->getIncarnation() + 1, $first->getIncarnation());
        self::assertEquals($job->getJob(), $first->getJob());
    }

    /**
     * @test
     */
    public function Expired_jobs_get_removed(): void
    {
        $job = new ScheduledJob(
            self::getJobQueueJob(),
            self::getQueueName(),
            $this->now,
            'my-first-identifier',
            100,
            'claim'
        );
        $this->persistenceManager->add($job);
        $this->persistenceManager->persistAll();

        $retry = new Retry($this->scheduler);
        $retry->injectQueueManager($this->queueManager([
            'scheduledJobs' => [
                'backoffStrategy' => 'linear',
                'numberOfRetries' => 100,
            ],
        ]));
        $retry->markJobForRescheduling($job);
        $retry->scheduleAll();

        $all = $this->findAll();
        self::assertEmpty( $all);
    }
}
