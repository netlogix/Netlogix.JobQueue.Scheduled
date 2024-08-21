<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional;

use DateTimeImmutable;
use InvalidArgumentException;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;

class ReleaseTest extends TestCase
{
    /**
     * @var DateTimeImmutable
     */
    protected $now;

    public function setUp(): void
    {
        parent::setUp();

        $this->now = self::getDueDate();
    }

    /**
     * @test
     */
    public function Claimed_jobs_can_be_released(): void
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
        $this->persistenceManager->clearState();

        $this->scheduler->release($job);

        $all = $this->findAll();
        self::assertEmpty($all);
    }

    /**
     * @test
     */
    public function Cannot_remove_unclaimed_jobs(): void
    {
        $job = new ScheduledJob(
            self::getJobQueueJob(),
            self::getQueueName(),
            $this->now,
            Scheduler::DEFAULT_GROUP_NAME,
            'my-first-identifier',
            100
        );

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionCode(1657027508);

        $this->scheduler->release($job);
    }
}
