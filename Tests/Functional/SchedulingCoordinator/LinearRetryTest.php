<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional\SchedulingCoordinator;

use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\SchedulingCoordinator;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;

class LinearRetryTest extends TestCase
{
    /**
     * @test
     */
    public function Jobs_with_no_retries_dont_get_rescheduled(): void
    {
        $job = new ScheduledJob(
            self::getJobQueueJob(),
            self::getQueueName(),
            $this->now,
            Scheduler::DEFAULT_GROUP_NAME,
            'my-first-identifier',
            0,
            'some-claim'
        );

        $retry = new SchedulingCoordinator($this->scheduler);
        $retry->injectQueueManager($this->queueManager([
            'scheduledJobs' => [
                'backoffStrategy' => 'linear',
                'numberOfRetries' => 0,
            ],
        ]));
        $retry->markJobForRescheduling($job);
        $retry->scheduleAll();

        $all = $this->findAll();
        self::assertEmpty($all);
    }

    /**
     * @test
     */
    public function Jobs_with_negative_retries_get_retried_infinitely(): void
    {
        $incarnation = pow(2, 15);
        $initialJob = new ScheduledJob(
            self::getJobQueueJob(),
            self::getQueueName(),
            $this->now,
            Scheduler::DEFAULT_GROUP_NAME,
            'my-first-identifier',
            0,
            'first-claim'
        );
        $this->scheduler->schedule($initialJob);

        $job = new ScheduledJob(
            self::getJobQueueJob(),
            self::getQueueName(),
            $this->now,
            Scheduler::DEFAULT_GROUP_NAME,
            'my-first-identifier',
            $incarnation,
            'second-claim'
        );

        $retry = new SchedulingCoordinator($this->scheduler);
        $retry->injectQueueManager($this->queueManager([
            'scheduledJobs' => [
                'backoffStrategy' => 'linear',
                'numberOfRetries' => -1,
            ],
        ]));
        $retry->markJobForRescheduling($job);
        $retry->scheduleAll();

        $all = $this->findAll();
        self::assertCount(1, $all);
    }

    /**
     * @test
     */
    public function Default_interval_is_zero(): void
    {
        $incarnation = pow(2, 15);
        $initialJob = new ScheduledJob(
            self::getJobQueueJob(),
            self::getQueueName(),
            $this->now,
            Scheduler::DEFAULT_GROUP_NAME,
            'my-first-identifier',
            0,
            'first-claim'
        );
        $this->scheduler->schedule($initialJob);

        $job = new ScheduledJob(
            self::getJobQueueJob(),
            self::getQueueName(),
            $this->now,
            Scheduler::DEFAULT_GROUP_NAME,
            'my-first-identifier',
            $incarnation,
            'second-claim'
        );

        $retry = new SchedulingCoordinator($this->scheduler);
        $retry->injectQueueManager($this->queueManager([
            'scheduledJobs' => [
                'backoffStrategy' => 'linear',
            ],
        ]));
        $retry->markJobForRescheduling($job);
        $retry->scheduleAll();

        $first = $this->findFirst();
        self::assertEquals(
            self::zeroInterval(),
            $first->getDuedate()->diff($this->now)
        );
    }

    /**
     * @test
     */
    public function Retry_adds_the_retryInterval_in_seconds(): void
    {
        $retryInterval = 100;

        $incarnation = pow(2, 15);
        $initialJob = new ScheduledJob(
            self::getJobQueueJob(),
            self::getQueueName(),
            $this->now,
            Scheduler::DEFAULT_GROUP_NAME,
            'my-first-identifier',
            0,
            'first-claim',
            true
        );
        $this->scheduler->schedule($initialJob);

        $job = new ScheduledJob(
            self::getJobQueueJob(),
            self::getQueueName(),
            $this->now,
            Scheduler::DEFAULT_GROUP_NAME,
            'my-first-identifier',
            $incarnation,
            'second-claim'
        );

        $retry = new SchedulingCoordinator($this->scheduler);
        $retry->injectQueueManager($this->queueManager([
            'scheduledJobs' => [
                'backoffStrategy' => 'linear',
                'retryInterval' => $retryInterval,
            ],
        ]));
        $retry->markJobForRescheduling($job);
        $retry->scheduleAll();

        $first = $this->findFirst();
        self::assertEquals(
            self::numberOfSecondsInterval($retryInterval),
            $first->getDuedate()->diff($this->now)
        );
    }

    /**
     * @test
     */
    public function Retries_are_skipped_after_limit_is_reached(): void
    {
        $job = new ScheduledJob(
            self::getJobQueueJob(),
            self::getQueueName(),
            $this->now,
            Scheduler::DEFAULT_GROUP_NAME,
            'my-first-identifier',
            10,
            'some-claim'
        );

        $retry = new SchedulingCoordinator($this->scheduler);
        $retry->injectQueueManager($this->queueManager([
            'scheduledJobs' => [
                'backoffStrategy' => 'linear',
                'retryInterval' => 100,
                'numberOfRetries' => 10,
            ],
        ]));
        $retry->markJobForRescheduling($job);
        $retry->scheduleAll();

        $all = $this->findAll();
        self::assertEmpty($all);
    }
}
