<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional\AsScheduledJob;

use Flowpack\JobQueue\Common\Queue\Message;
use Flowpack\JobQueue\Common\Queue\QueueInterface;
use Netlogix\JobQueue\Scheduled\AsScheduledJob\WorkerRun;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use Netlogix\JobQueue\Scheduled\Tests\Functional\TestCase;

class WorkerRunTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        CountingScheduledJob::$executions = 0;
    }

    /**
     * @test
     */
    public function A_worker_run_executes_a_scheduled_job_whatever_queue_it_was_scheduled_to(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue
            ->method('getName')
            ->willReturn('queue-with-own-retry-settings');

        // The pool passes the worker run serialized to its child process.
        $workerRun = unserialize(serialize(new WorkerRun(serialize(new CountingScheduledJob()))));
        assert($workerRun instanceof WorkerRun);

        $result = $workerRun->execute($queue, new Message('message-identifier', ''));

        self::assertTrue($result);
        self::assertSame(1, CountingScheduledJob::$executions);
        self::assertFalse($this->scheduler->isScheduled(Scheduler::DEFAULT_GROUP_NAME, 'counting-scheduled-job'));
    }
}
