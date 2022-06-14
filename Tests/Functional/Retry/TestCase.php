<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional\Retry;

use DateInterval;
use DateTimeImmutable;
use Flowpack\JobQueue\Common\Queue\QueueManager;
use Netlogix\JobQueue\Scheduled\Tests\Functional\TestCase as JobQueueTestCase;

class TestCase extends JobQueueTestCase
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

    protected function queueManager(array $configuration): QueueManager
    {
        $manager = $this->getMockBuilder(QueueManager::class)
            ->getMock();
        $manager
            ->method('getQueueSettings')
            ->with(self::getQueueName())
            ->willReturn($configuration);
        return $manager;
    }

    protected function zeroInterval()
    {
        return $this->numberOfSecondsInterval(0);
    }

    protected function numberOfSecondsInterval(int $numberOfSeconds): DateInterval
    {
        $denormalizedDiff = DateInterval::createFromDateString($numberOfSeconds . ' seconds');
        $earlier = new DateTimeImmutable();
        $later = $earlier->add($denormalizedDiff);
        $normalizedDiff = $later->diff($earlier);
        return $normalizedDiff;
    }
}
