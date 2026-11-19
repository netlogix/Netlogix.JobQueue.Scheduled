<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional\Command;

use DateTimeImmutable;
use Neos\Flow\Tests\FunctionalTestCase;
use Netlogix\JobQueue\Pool\Pool;
use Netlogix\JobQueue\Scheduled\AsScheduledJob\WorkerRun;
use Netlogix\JobQueue\Scheduled\Command\SchedulerCommandController;
use Netlogix\JobQueue\Scheduled\Domain\Group;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use Netlogix\JobQueue\Scheduled\Tests\Fixture\JobQueueJob;
use React\ChildProcess\Process;
use React\EventLoop\StreamSelectLoop;
use ReflectionMethod;

class WorkerPayloadTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function Due_jobs_reach_the_worker_wrapped_in_a_WorkerRun(): void
    {
        $job = ScheduledJob::createNew(
            job: JobQueueJob::first(),
            queue: 'some-queue-name',
            duedate: new DateTimeImmutable(),
            groupName: Scheduler::DEFAULT_GROUP_NAME
        );

        $scheduler = $this->createMock(Scheduler::class);
        $scheduler
            ->method('next')
            ->willReturnOnConsecutiveCalls($job, null);

        $payloads = [];
        $pool = $this->createMock(Pool::class);
        $pool
            ->method('runPayload')
            ->willReturnCallback(function (string $payload) use (&$payloads): Process {
                $payloads[] = $payload;
                return new Process('true');
            });

        $controller = $this->objectManager->get(SchedulerCommandController::class);
        assert($controller instanceof SchedulerCommandController);
        $controller->injectScheduler($scheduler);

        (new ReflectionMethod($controller, 'queueDueJobs'))->invoke(
            $controller,
            new StreamSelectLoop(),
            [Scheduler::DEFAULT_GROUP_NAME => new Group(name: Scheduler::DEFAULT_GROUP_NAME)],
            [Scheduler::DEFAULT_GROUP_NAME => $pool]
        );

        self::assertCount(1, $payloads);
        $workerRun = unserialize($payloads[0]);
        self::assertInstanceOf(WorkerRun::class, $workerRun);
        self::assertSame('first', $workerRun->getLabel());
    }
}
