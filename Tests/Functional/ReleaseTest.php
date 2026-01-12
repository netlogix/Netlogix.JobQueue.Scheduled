<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional;

use DateTimeImmutable;
use InvalidArgumentException;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;

use function serialize;

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
        $job = ScheduledJob::createInternal(
            job: self::getJobQueueJob(),
            queue: self::getQueueName(),
            duedate: $this->now,
            groupName: Scheduler::DEFAULT_GROUP_NAME,
            identifier: 'my-first-identifier',
            incarnation: 100,
            claimed: 'claim',
            running: false
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
        $job = ScheduledJob::createInternal(
            job: self::getJobQueueJob(),
            queue: self::getQueueName(),
            duedate: $this->now,
            groupName: Scheduler::DEFAULT_GROUP_NAME,
            identifier: 'my-first-identifier',
            incarnation: 100,
            claimed: '',
            running: false
        );

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionCode(1657027508);

        $this->scheduler->release($job);
    }
}
