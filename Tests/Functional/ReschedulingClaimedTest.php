<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Neos\Flow\Utility\Now;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;

class ReschedulingClaimedTest extends TestCase
{
    /**
     * @test
     */
    public function Scheduling_already_claimed_jobs_resets_the_claim_but_keeps_the_running_flag(): void
    {
        // create a new job
        $this->scheduler->schedule($this->job());

        // make sure it's claimed
        $first = $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME);
        self::assertInstanceOf(ScheduledJob::class, $first);
        assert($first instanceof ScheduledJob);

        // reschedule to set the claim to empty but keep the running flag
        $this->scheduler->schedule($this->job());

        $row = $this->objectManager
            ->get(EntityManagerInterface::class)
            ->getConnection()
            ->fetchAllAssociative('SELECT incarnation, claimed, running FROM ' . ScheduledJob::TABLE_NAME);

        self::assertEquals(
            [
                [
                    'incarnation' => 0,
                    'claimed' => '',
                    'running' => 1,
                ],
            ],
            $row
        );
    }

    /**
     * @test
     */
    public function Claiming_only_works_for_jobs_that_are_not_running_even_if_jobs_are_unclaimed(): void
    {
        // create a new job
        $this->scheduler->schedule($this->job());

        // make sure it's claimed
        $first = $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME);
        self::assertInstanceOf(ScheduledJob::class, $first);
        assert($first instanceof ScheduledJob);

        // reschedule to set the claim to empty but keep the running flag
        $this->scheduler->schedule($this->job());

        // now the job cannot be claimed again
        $second = $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME);
        self::assertNull($second);

        // release unsets the claim and unsets the running flag
        $this->scheduler->release($first);

        // re-reclaim works again
        $third = $this->scheduler->next(Scheduler::DEFAULT_GROUP_NAME);
        self::assertInstanceOf(ScheduledJob::class, $third);
        assert($third instanceof ScheduledJob);
    }

    protected function job(): ScheduledJob
    {
        return ScheduledJob::createNew(
            job: self::getJobQueueJob(),
            queue: self::getQueueName(),
            duedate: $this->objectManager->get(Now::class),
            groupName: Scheduler::DEFAULT_GROUP_NAME,
            identifier: 'my-first-identifier'
        );
    }
}
