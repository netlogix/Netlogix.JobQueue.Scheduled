<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional;

use DateTimeImmutable;
use InvalidArgumentException;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;

class FailTest extends TestCase
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
    public function Claimed_jobs_can_be_failed(): void
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

        $this->scheduler->fail($job, 'Some great reason');

        $all = $this->findAll();
        self::assertCount(1, $all);

        $first = $all[0];
        self::assertInstanceOf(ScheduledJob::class, $first);
        self::assertEquals('failed(Some great reason)', $first->getClaimed());
    }

    /**
     * @test
     */
    public function Cannot_fail_unclaimed_jobs(): void
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
        self::expectExceptionCode(1718808398);

        $this->scheduler->fail($job, 'Some great reason');
    }
}
