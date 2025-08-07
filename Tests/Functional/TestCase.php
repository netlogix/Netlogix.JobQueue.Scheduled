<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Neos\Flow\Utility\Now;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Persistence\QueryResultInterface;
use Neos\Flow\Tests\FunctionalTestCase;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use Netlogix\JobQueue\Scheduled\Tests\Fixture\JobQueueJob;

class TestCase extends FunctionalTestCase
{
    protected static $testablePersistenceEnabled = true;

    /**
     * @var Scheduler
     */
    protected $scheduler;

    public function setUp(): void
    {
        parent::setUp();
        $scheduler = $this->objectManager->get(Scheduler::class);
        assert($scheduler instanceof Scheduler);
        $this->scheduler = $scheduler;

        $this->objectManager
            ->get(EntityManagerInterface::class)
            ->getConnection()
            ->executeStatement('DELETE FROM ' . ScheduledJob::TABLE_NAME);
    }

    protected static function getJobQueueJob(): JobQueueJob
    {
        return JobQueueJob::first();
    }

    protected static function getDueDate(): DateTimeImmutable
    {
        // Care for "modified now" in some environments
        $now = Bootstrap::$staticObjectManager->get(Now::class);
        assert($now instanceof Now);
        $now = $now->setTime(
            (int)$now->format('H'),
            (int)$now->format('i'),
            (int)$now->format('s'),
            0
        );

        return $now;
    }

    protected static function getQueueName(): string
    {
        return 'some-queue-name';
    }

    /**
     */
    protected function scheduleJob(): void
    {
        $scheduledJob = new ScheduledJob(
            self::getJobQueueJob(),
            self::getQueueName(),
            self::getDueDate(),
            Scheduler::DEFAULT_GROUP_NAME
        );

        $this->scheduler->schedule($scheduledJob);
    }

    /**
     * @return QueryResultInterface<ScheduledJob>
     */
    protected function findAll(): QueryResultInterface
    {
        return $this->persistenceManager
            ->createQueryForType(ScheduledJob::class)
            ->execute();
    }

    protected function findFirst(): ScheduledJob
    {
        return $this->findAll()->current();
    }
}
