<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional\Groups;

use Doctrine\ORM\EntityManagerInterface;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use Netlogix\JobQueue\Scheduled\Tests\Functional\TestCase;

/**
 * The timeouts come from the Testing settings: the default group uses the
 * default of 60 seconds, "configured-group" raises it to 120.
 */
class ResetStaleJobsTest extends TestCase
{
    private const SECONDS_WITHOUT_ACTIVITY = 90;

    /**
     * @test
     */
    public function A_job_idle_longer_than_its_groups_timeout_is_freed(): void
    {
        $this->scheduleRunningJob(Scheduler::DEFAULT_GROUP_NAME, 'default-job');

        $freed = $this->scheduler->resetStaleJobs(Scheduler::DEFAULT_GROUP_NAME);

        self::assertSame(1, $freed);
    }

    /**
     * @test
     */
    public function A_job_within_its_groups_timeout_is_kept(): void
    {
        $this->scheduleRunningJob('configured-group', 'configured-job');

        $freed = $this->scheduler->resetStaleJobs('configured-group');

        self::assertSame(
            0,
            $freed,
            'The group raises the timeout to 120 seconds, so 90 seconds of silence are not stale yet.'
        );
    }

    /**
     * @test
     */
    public function Resetting_one_group_leaves_the_others_alone(): void
    {
        $this->scheduleRunningJob(Scheduler::DEFAULT_GROUP_NAME, 'default-job');
        $this->scheduleRunningJob('additional-group', 'additional-job');

        self::assertSame(1, $this->scheduler->resetStaleJobs(Scheduler::DEFAULT_GROUP_NAME));
        self::assertSame(1, $this->scheduler->resetStaleJobs('additional-group'));
    }

    /**
     * Schedules a job and backdates it into the state resetStaleJobs looks for:
     * running, with its last activity long enough ago.
     *
     * Timestamps are written by the database, so the fixed "Now" of the testing
     * context cannot drift away from the NOW() the query compares against.
     */
    private function scheduleRunningJob(string $groupName, string $identifier): void
    {
        $this->scheduler->schedule(
            ScheduledJob::createNew(
                job: self::getJobQueueJob(),
                queue: self::getQueueName(),
                duedate: self::getDueDate(),
                groupName: $groupName,
                identifier: $identifier
            )
        );

        $tableName = ScheduledJob::TABLE_NAME;

        $entityManager = $this->objectManager->get(EntityManagerInterface::class);
        assert($entityManager instanceof EntityManagerInterface);
        $connection = $entityManager->getConnection();
        $activity = $connection->getDatabasePlatform()
            ->getDateSubSecondsExpression('CURRENT_TIMESTAMP', self::SECONDS_WITHOUT_ACTIVITY);

        $connection->executeStatement(
            /** @lang SQL */ <<<"SQL"
                UPDATE {$tableName}
                SET running = 1,
                    activity = {$activity}
                WHERE identifier = :identifier
                SQL,
            ['identifier' => $identifier]
        );
    }
}
