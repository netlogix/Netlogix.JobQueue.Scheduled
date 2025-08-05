<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional\SchedulingCoordinator;

use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\SchedulingCoordinator;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;

class SchedulingTest extends TestCase
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
            Scheduler::DEFAULT_GROUP_NAME,
            'my-identifier'
        );

        $retry = new SchedulingCoordinator($this->scheduler);
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
            Scheduler::DEFAULT_GROUP_NAME,
            'my-first-identifier'
        );
        $jobB = new ScheduledJob(
            self::getJobQueueJob(),
            self::getQueueName(),
            $this->now,
            Scheduler::DEFAULT_GROUP_NAME,
            'my-second-identifier'
        );

        $retry = new SchedulingCoordinator($this->scheduler);
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
            Scheduler::DEFAULT_GROUP_NAME,
            'my-first-identifier'
        );

        $retry = new SchedulingCoordinator($this->scheduler);
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
            Scheduler::DEFAULT_GROUP_NAME,
            'my-first-identifier',
            100,
            'claim'
        );
        $this->persistenceManager->add($job);
        $this->persistenceManager->persistAll();

        $retry = new SchedulingCoordinator($this->scheduler);
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

    /**
     * @test
     */
    public function Expired_jobs_are_kept_if_the_queue_has_it_configured(): void
    {
        $job = new ScheduledJob(
            self::getJobQueueJob(),
            self::getQueueName(),
            $this->now,
            Scheduler::DEFAULT_GROUP_NAME,
            'my-first-identifier',
            100,
            'claim'
        );
        $this->persistenceManager->add($job);
        $this->persistenceManager->persistAll();

        $retry = new SchedulingCoordinator($this->scheduler);
        $retry->injectQueueManager($this->queueManager([
            'scheduledJobs' => [
                'backoffStrategy' => 'linear',
                'numberOfRetries' => 100,
                'keepFailedJobs' => true,
            ],
        ]));
        $retry->markJobForRescheduling($job);
        $retry->scheduleAll();

        $this->persistenceManager->clearState();

        $all = $this->findAll();
        self::assertCount(1, $all);

        $first = $all[0];
        self::assertInstanceOf(ScheduledJob::class, $first);
        self::assertEquals('my-first-identifier', $first->getIdentifier());
        self::assertEquals('failed(retries exhausted)', $first->getClaimed());
    }

    /**
     * @test
     */
    public function Scheduling_a_resh_job_resets_the_incarnation_count(): void
    {
        $this->scheduler->schedule(
            new ScheduledJob(
                self::getJobQueueJob(),
                self::getQueueName(),
                self::getDueDate(),
                Scheduler::DEFAULT_GROUP_NAME,
                'some-identifier',
                1234567
            )
        );

        $this->scheduler->schedule(
            new ScheduledJob(
                self::getJobQueueJob(),
                self::getQueueName(),
                self::getDueDate(),
                Scheduler::DEFAULT_GROUP_NAME,
                'some-identifier'
            )
        );

        $scheduledJob = $this->findFirst();

        self::assertEquals(
            0,
            $scheduledJob->getIncarnation()
        );
    }

    /**
     * @test
     */
    public function Rescheduling_a_job_does_not_reset_the_incarnation_count(): void
    {
        $this->scheduler->schedule(
            new ScheduledJob(
                self::getJobQueueJob(),
                self::getQueueName(),
                self::getDueDate(),
                Scheduler::DEFAULT_GROUP_NAME,
                'some-identifier',
                100
            )
        );

        $this->scheduler->schedule(
            new ScheduledJob(
                self::getJobQueueJob(),
                self::getQueueName(),
                self::getDueDate(),
                Scheduler::DEFAULT_GROUP_NAME,
                'some-identifier',
                1000
            )
        );

        $scheduledJob = $this->findFirst();

        self::assertEquals(
            1000,
            $scheduledJob->getIncarnation()
        );
    }
}
