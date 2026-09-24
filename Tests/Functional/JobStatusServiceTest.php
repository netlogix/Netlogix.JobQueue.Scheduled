<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use Netlogix\JobQueue\Scheduled\Service\JobStatusService;

class JobStatusServiceTest extends TestCase
{
    private JobStatusService $jobStatusService;

    public function setUp(): void
    {
        parent::setUp();
        $jobStatusService = $this->objectManager->get(JobStatusService::class);
        assert($jobStatusService instanceof JobStatusService);
        $this->jobStatusService = $jobStatusService;
    }

    /**
     * @test
     */
    public function Every_state_is_counted_in_its_own_bucket(): void
    {
        $this->scheduleJobInState('pending-job', running: 0, claimed: '', secondsSinceActivity: 0);
        $this->scheduleJobInState('running-job', running: 1, claimed: 'some-claim', secondsSinceActivity: 5);
        $this->scheduleJobInState('stale-job', running: 1, claimed: 'other-claim', secondsSinceActivity: 300);
        $this->scheduleJobInState('failed-job', running: 0, claimed: 'failed(boom)', secondsSinceActivity: 0);

        $group = Scheduler::DEFAULT_GROUP_NAME;

        self::assertSame(4, $this->jobStatusService->getTotalJobCount($group), 'total');
        self::assertSame(1, $this->jobStatusService->getPendingJobCount($group), 'pending');
        self::assertSame(1, $this->jobStatusService->getRunningJobCount($group), 'running');
        self::assertSame(1, $this->jobStatusService->getStaleJobCount($group), 'stale');
        self::assertSame(1, $this->jobStatusService->getFailedJobCount($group), 'failed');
    }

    /**
     * @test
     */
    public function Counting_stays_within_the_asked_group(): void
    {
        $this->scheduleJobInState('default-job', running: 0, claimed: '', secondsSinceActivity: 0);
        $this->scheduleJobInState('other-job', running: 0, claimed: '', secondsSinceActivity: 0, groupName: 'additional-group');

        self::assertSame(1, $this->jobStatusService->getTotalJobCount(Scheduler::DEFAULT_GROUP_NAME));
        self::assertSame(1, $this->jobStatusService->getTotalJobCount('additional-group'));
    }

    /**
     * The stale threshold is the group's own staleJobTimeout - 120 seconds for
     * "configured-group" against the default of 60.
     *
     * @test
     */
    public function The_border_between_running_and_stale_follows_the_group(): void
    {
        $this->scheduleJobInState('default-job', running: 1, claimed: 'a-claim', secondsSinceActivity: 90);
        $this->scheduleJobInState('configured-job', running: 1, claimed: 'b-claim', secondsSinceActivity: 90, groupName: 'configured-group');

        self::assertSame(1, $this->jobStatusService->getStaleJobCount(Scheduler::DEFAULT_GROUP_NAME), 'default, Timeout 60');
        self::assertSame(0, $this->jobStatusService->getStaleJobCount('configured-group'), 'configured-group, Timeout 120');
        self::assertSame(1, $this->jobStatusService->getRunningJobCount('configured-group'), 'dort gilt der Job noch als laufend');
    }

    /**
     * @test
     */
    public function A_claim_is_pending_until_it_outlives_the_stale_timeout(): void
    {
        $this->scheduleJobInState('claimed-job', running: 2, claimed: 'a-claim', secondsSinceActivity: 5);
        $this->scheduleJobInState('stuck-job', running: 2, claimed: 'b-claim', secondsSinceActivity: 300);

        $group = Scheduler::DEFAULT_GROUP_NAME;

        self::assertSame(1, $this->jobStatusService->getPendingJobCount($group), 'pending');
        self::assertSame(1, $this->jobStatusService->getStaleJobCount($group), 'stale');
        self::assertSame(0, $this->jobStatusService->getRunningJobCount($group), 'running');
    }

    private function scheduleJobInState(
        string $identifier,
        int $running,
        string $claimed,
        int $secondsSinceActivity,
        string $groupName = Scheduler::DEFAULT_GROUP_NAME
    ): void {
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
            ->getDateSubSecondsExpression('CURRENT_TIMESTAMP', $secondsSinceActivity);

        $connection->executeStatement(
            /** @lang SQL */ <<<"SQL"
                UPDATE {$tableName}
                SET running = :running,
                    claimed = :claimed,
                    activity = {$activity}
                WHERE identifier = :identifier
                SQL,
            [
                'running' => $running,
                'claimed' => $claimed,
                'identifier' => $identifier,
            ]
        );
    }
}
