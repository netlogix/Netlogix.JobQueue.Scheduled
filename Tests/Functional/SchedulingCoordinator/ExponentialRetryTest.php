<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional\SchedulingCoordinator;

use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\SchedulingCoordinator;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;

class ExponentialRetryTest extends TestCase
{
    /**
     * @test
     */
    public function Jobs_with_no_retries_dont_get_rescheduled(): void
    {
        $job = ScheduledJob::createInternal(
            job: self::getJobQueueJob(),
            queue: self::getQueueName(),
            duedate: $this->now,
            groupName: Scheduler::DEFAULT_GROUP_NAME,
            identifier: 'my-first-identifier',
            incarnation: 0,
            claimed: 'some-claim',
            running: false
        );

        $retry = new SchedulingCoordinator($this->scheduler);
        $retry->injectQueueManager($this->queueManager([
            'scheduledJobs' => [
                'backoffStrategy' => 'exponential',
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
        $initialJob = ScheduledJob::createInternal(
            job: self::getJobQueueJob(),
            queue: self::getQueueName(),
            duedate: $this->now,
            groupName: Scheduler::DEFAULT_GROUP_NAME,
            identifier: 'my-first-identifier',
            incarnation: $incarnation,
            claimed: 'first-claim',
            running: false
        );
        $this->scheduler->schedule($initialJob);

        $job = ScheduledJob::createInternal(
            job: self::getJobQueueJob(),
            queue: self::getQueueName(),
            duedate: $this->now,
            groupName: Scheduler::DEFAULT_GROUP_NAME,
            identifier: 'my-first-identifier',
            incarnation: $incarnation,
            claimed: 'second-claim',
            running: false
        );

        $retry = new SchedulingCoordinator($this->scheduler);
        $retry->injectQueueManager($this->queueManager([
            'scheduledJobs' => [
                'backoffStrategy' => 'exponential',
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
        $initialJob = ScheduledJob::createInternal(
            job: self::getJobQueueJob(),
            queue: self::getQueueName(),
            duedate: $this->now,
            groupName: Scheduler::DEFAULT_GROUP_NAME,
            identifier: 'my-first-identifier',
            incarnation: 0,
            claimed: 'first-claim',
            running: false
        );
        $this->scheduler->schedule($initialJob);

        $job = ScheduledJob::createInternal(
            job: self::getJobQueueJob(),
            queue: self::getQueueName(),
            duedate: $this->now,
            groupName: Scheduler::DEFAULT_GROUP_NAME,
            identifier: 'my-first-identifier',
            incarnation: $incarnation,
            claimed: 'second-claim',
            running: false
        );

        $retry = new SchedulingCoordinator($this->scheduler);
        $retry->injectQueueManager($this->queueManager([
            'scheduledJobs' => [
                'backoffStrategy' => 'exponential',
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
     * @dataProvider provideIncarnations
     */
    public function Retry_adds_increasing_date_intervals(int $incarnation, int $delayInSeconds): void
    {
        $retryInterval = 100;

        $initialJob = ScheduledJob::createInternal(
            job: self::getJobQueueJob(),
            queue: self::getQueueName(),
            duedate: $this->now,
            groupName: Scheduler::DEFAULT_GROUP_NAME,
            identifier: 'my-first-identifier',
            incarnation: 0,
            claimed: '',
            running: true
        );
        $this->scheduler->schedule($initialJob);

        $job = ScheduledJob::createInternal(
            job: self::getJobQueueJob(),
            queue: self::getQueueName(),
            duedate: $this->now,
            groupName: Scheduler::DEFAULT_GROUP_NAME,
            identifier: 'my-first-identifier',
            incarnation: $incarnation,
            claimed: 'second-claim',
            running: false
        );
        $retry = new SchedulingCoordinator($this->scheduler);
        $retry->injectQueueManager($this->queueManager([
            'scheduledJobs' => [
                'backoffStrategy' => 'exponential',
                'retryInterval' => $retryInterval,
            ],
        ]));
        $retry->markJobForRescheduling($job);
        $retry->scheduleAll();

        $first = $this->findFirst();
        self::assertEquals(
            self::numberOfSecondsInterval($delayInSeconds),
            $first->getDuedate()->diff($this->now)
        );
    }

    /**
     * @return \Generator<{incarnation: int, delayInSeconds: int}>
     */
    public static function provideIncarnations(): \Generator
    {
        yield 'first retry' => [
            'incarnation' => 0,
            'delayInSeconds' => 100
        ];

        yield 'second retry' => [
            'incarnation' => 1,
            'delayInSeconds' => 2 * 100
        ];

        yield 'tenths retry' => [
            'incarnation' => 10,
            'delayInSeconds' => 1024 * 100 // (2^10) * 100
        ];

        yield 'fifteenths retry' => [
            'incarnation' => 15,
            'delayInSeconds' => 32768 * 100 // (2^15) * 100
        ];
    }

    /**
     * @test
     */
    public function Retries_are_skipped_after_limit_is_reached(): void
    {
        $job = ScheduledJob::createInternal(
            job: self::getJobQueueJob(),
            queue: self::getQueueName(),
            duedate: $this->now,
            groupName: Scheduler::DEFAULT_GROUP_NAME,
            identifier: 'my-first-identifier',
            incarnation: 10,
            claimed: 'some-claim',
            running: false
        );

        $retry = new SchedulingCoordinator($this->scheduler);
        $retry->injectQueueManager($this->queueManager([
            'scheduledJobs' => [
                'backoffStrategy' => 'exponential',
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
