<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional\Groups;

use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Tests\Functional\TestCase;

class SchedulingTest extends TestCase
{
    /**
     * @test
     */
    public function Scheduling_works_for_different_groups(): void
    {
        $scheduledJob = fn(string $groupName) => $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: self::getJobQueueJob(),
                queue: self::getQueueName(),
                duedate: self::getDueDate(),
                groupName: $groupName
            )
        );
        $scheduledJob('default');
        $scheduledJob('additional-group');

        $this->expectNotToPerformAssertions();
    }

    /**
     * @test
     */
    public function Scheduling_on_arbitrary_group_identifiers_fails(): void
    {
        self::expectExceptionCode(1721393320);
        self::expectExceptionMessage('Group name "non-existing-group" is not active');

        $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: self::getJobQueueJob(),
                queue: self::getQueueName(),
                duedate: self::getDueDate(),
                groupName: 'non-existing-group'
            )
        );
    }
}
