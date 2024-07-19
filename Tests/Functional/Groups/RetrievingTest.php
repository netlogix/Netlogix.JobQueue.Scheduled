<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional\Groups;

use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use Netlogix\JobQueue\Scheduled\Tests\Functional\TestCase;

class RetrievingTest extends TestCase
{
    /**
     * @test
     */
    public function Known_groups_can_be_worked_on(): void
    {
        $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME);
        $this->scheduler->next('additional-group');

       $this->expectNotToPerformAssertions();
    }

    /**
     * @test
     */
    public function Unknown_groups_can_be_worked_on(): void
    {
        self::expectExceptionCode(1721393320);
        self::expectExceptionMessage('Group name "non-existing-group" is not active');

        $this->scheduler->next('non-existing-group');
    }

    /**
     * @test
     */
    public function Additional_groups_dont_interfere_with_default_group_jobs(): void
    {
        $this->scheduler->schedule(
            new ScheduledJob(
                self::getJobQueueJob(),
                self::getQueueName(),
                self::getDueDate(),
                'default',
                'default-job-identifier'
            )
        );

        $this->scheduler->schedule(
            new ScheduledJob(
                self::getJobQueueJob(),
                self::getQueueName(),
                self::getDueDate(),
                'additional-group',
                'additional-job-identifier'
            )
        );

        $job = $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME);
        $notAJob = $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME);

        self::assertInstanceOf(ScheduledJob::class, $job);
        self::assertEquals('default-job-identifier', $job->getIdentifier());

        self::assertNull($notAJob);
    }

    /**
     * @test
     */
    public function Default_group_jobs_are_not_covered_by_additional_group_workers(): void
    {
        $this->scheduler->schedule(
            new ScheduledJob(
                self::getJobQueueJob(),
                self::getQueueName(),
                self::getDueDate(),
                'default',
                'default-job-identifier'
            )
        );

        $this->scheduler->schedule(
            new ScheduledJob(
                self::getJobQueueJob(),
                self::getQueueName(),
                self::getDueDate(),
                'additional-group',
                'additional-job-identifier'
            )
        );

        $job = $this->scheduler->next('additional-group');
        $notAJob = $this->scheduler->next('additional-group');

        self::assertInstanceOf(ScheduledJob::class, $job);
        self::assertEquals('additional-job-identifier', $job->getIdentifier());

        self::assertNull($notAJob);
    }
}
