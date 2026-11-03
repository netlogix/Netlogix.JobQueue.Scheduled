<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional\Groups;

use Netlogix\JobQueue\Scheduled\Domain\GroupRepository;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use Netlogix\JobQueue\Scheduled\Tests\Functional\TestCase;

class MultipleGroupsTest extends TestCase
{
    /**
     * @test
     */
    public function The_oldest_job_of_all_given_groups_is_claimed(): void
    {
        // The older job deliberately sits in the group given last: a claim that
        // only looked at the first branch would return the younger one.
        $this->scheduleJobIn(Scheduler::DEFAULT_GROUP_NAME, 'younger-job', 60);
        $this->scheduleJobIn('additional-group', 'older-job', 600);

        $job = $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME, 'additional-group');

        self::assertInstanceOf(ScheduledJob::class, $job);
        self::assertEquals('older-job', $job->getIdentifier());
        self::assertEquals('additional-group', $job->getGroupName());
    }

    /**
     * @test
     */
    public function Every_given_group_is_emptied(): void
    {
        $this->scheduleJobIn(Scheduler::DEFAULT_GROUP_NAME, 'default-job', 600);
        $this->scheduleJobIn('additional-group', 'additional-job', 60);

        $first = $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME, 'additional-group');
        $second = $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME, 'additional-group');
        $third = $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME, 'additional-group');

        self::assertEquals('default-job', $first?->getIdentifier());
        self::assertEquals('additional-job', $second?->getIdentifier());
        self::assertNull($third);
    }

    /**
     * @test
     */
    public function Groups_that_were_not_asked_for_are_left_alone(): void
    {
        $this->scheduleJobIn('additional-group', 'additional-job', 600);

        $job = $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME);

        self::assertNull($job);
    }

    /**
     * @test
     */
    public function Every_given_group_is_validated(): void
    {
        self::expectExceptionCode(1721393320);
        self::expectExceptionMessage('Group name "non-existing-group" is not active');

        $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME, 'non-existing-group');
    }

    /**
     * @test
     */
    public function The_default_group_is_not_active_once_every_group_is_disabled(): void
    {
        $groupRepository = clone $this->objectManager->get(GroupRepository::class);
        $groupRepository->injectSettings(['groups' => [Scheduler::DEFAULT_GROUP_NAME => false]]);
        $this->scheduler->injectGroupRepository($groupRepository);

        self::expectExceptionCode(1721393320);

        $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME);
    }

    private function scheduleJobIn(string $groupName, string $identifier, int $secondsDue): void
    {
        $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: self::getJobQueueJob(),
                queue: self::getQueueName(),
                duedate: self::getDueDate()->modify(sprintf('- %d seconds', $secondsDue)),
                groupName: $groupName,
                identifier: $identifier
            )
        );
    }
}
