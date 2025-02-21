<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional\AsScheduledJob;

use DateTimeImmutable;
use Flowpack\JobQueue\Common\Job\JobInterface;
use Flowpack\JobQueue\Common\Queue\FakeQueue;
use Flowpack\JobQueue\Common\Queue\Message;
use Flowpack\JobQueue\Common\Queue\QueueInterface;
use Neos\Flow\Aop\Advice\AdviceChain;
use Neos\Flow\Aop\JoinPoint;
use Netlogix\JobQueue\Scheduled\AsScheduledJob\CopyToScheduler;
use Netlogix\JobQueue\Scheduled\AsScheduledJob\ExecuteNormally;
use Netlogix\JobQueue\Scheduled\AsScheduledJob\ScheduledJobInterface;
use Netlogix\JobQueue\Scheduled\AsScheduledJob\SchedulingInformation;
use Netlogix\JobQueue\Scheduled\AsScheduledJob\SkipExecution;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use Netlogix\JobQueue\Scheduled\Tests\Functional\TestCase;

class CopyToSchedulerTest extends TestCase
{
    /**
     * @test
     */
    public function ScheduledJobs_on_the_internal_fake_queue_are_not_redirected(): void
    {
        $job = $this->createMock(TestingScheduledJobInterface::class);

        $joinPoint = $this->createMock(JoinPoint::class);
        $joinPoint
            ->expects(self::once())
            ->method('getProxy')
            ->willReturn($job);

        $fakeQueue = $this->createMock(FakeQueue::class);
        $fakeQueue
            ->expects(self::once())
            ->method('getName')
            ->willReturn(SchedulingInformation::QUEUE_NAME);

        $joinPoint
            ->expects(self::once())
            ->method('getMethodArgument')
            ->withAnyParameters('queue')
            ->willReturn($fakeQueue);

        $adviceChain = $this->createMock(AdviceChain::class);
        $adviceChain
            ->expects(self::once())
            ->method('proceed')
            ->willReturn(true);

        $joinPoint
            ->expects(self::once())
            ->method('getAdviceChain')
            ->willReturn($adviceChain);

        $copyToSchedulerAspect = new CopyToScheduler();
        $copyToSchedulerAspect->execute($joinPoint);
    }

    /**
     * @test
     */
    public function Jobs_providing_ExecuteNormally_as_scheduling_information_are_not_redirected(): void
    {
        $job = $this->createMock(TestingScheduledJobInterface::class);

        $joinPoint = $this->createMock(JoinPoint::class);
        $joinPoint
            ->expects(self::once())
            ->method('getProxy')
            ->willReturn($job);

        $queue = $this->createMock(QueueInterface::class);

        $joinPoint
            ->expects(self::once())
            ->method('getMethodArgument')
            ->withAnyParameters('queue')
            ->willReturn($queue);

        $adviceChain = $this->createMock(AdviceChain::class);
        $adviceChain
            ->expects(self::once())
            ->method('proceed')
            ->willReturn(true);

        $joinPoint
            ->expects(self::once())
            ->method('getAdviceChain')
            ->willReturn($adviceChain);

        $job
            ->expects(self::once())
            ->method('getSchedulingInformation')
            ->willReturn(new ExecuteNormally());

        $copyToSchedulerAspect = new CopyToScheduler();
        $copyToSchedulerAspect->execute($joinPoint);
    }

    /**
     * @test
     */
    public function Jobs_providing_SkipExecution_as_scheduling_information_are_not_executed(): void
    {
        $job = $this->createMock(TestingScheduledJobInterface::class);

        $joinPoint = $this->createMock(JoinPoint::class);
        $joinPoint
            ->expects(self::once())
            ->method('getProxy')
            ->willReturn($job);

        $queue = $this->createMock(QueueInterface::class);

        $joinPoint
            ->expects(self::once())
            ->method('getMethodArgument')
            ->withAnyParameters('queue')
            ->willReturn($queue);

        $adviceChain = $this->createMock(AdviceChain::class);
        $adviceChain
            ->expects(self::never())
            ->method('proceed');

        $joinPoint
            ->expects(self::never())
            ->method('getAdviceChain');

        $job
            ->expects(self::once())
            ->method('getSchedulingInformation')
            ->willReturn(new SkipExecution());

        $copyToSchedulerAspect = new CopyToScheduler();
        $result = $copyToSchedulerAspect->execute($joinPoint);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function Jobs_with_schedulingInformation_get_redirected_to_the_scheduler(): void
    {
        $job = $this->createMock(TestingScheduledJobInterface::class);

        $joinPoint = $this->createMock(JoinPoint::class);
        $joinPoint
            ->expects(self::once())
            ->method('getProxy')
            ->willReturn($job);

        $queue = $this->createMock(QueueInterface::class);

        $joinPoint
            ->expects(self::once())
            ->method('getMethodArgument')
            ->withAnyParameters('queue')
            ->willReturn($queue);

        $schedulingInformation = new SchedulingInformation(
            'identifier-223638b6-684b-49aa-8529-68040ef66679',
            'group-5cbaf1ca-b09d-4a0c-99d8-ea2ef5a6b142',
            new DateTimeImmutable('2024-07-24T16:59:01+02:00')
        );

        $job
            ->expects(self::once())
            ->method('getSchedulingInformation')
            ->willReturn($schedulingInformation);

        $scheduler = $this->createMock(Scheduler::class);
        $scheduler
            ->expects(self::once())
            ->method('schedule')
            ->willReturnCallback(function (ScheduledJob $scheduledJob) use ($schedulingInformation) {
                self::assertEquals(SchedulingInformation::QUEUE_NAME, $scheduledJob->getQueueName());
                self::assertEquals($schedulingInformation->identifier, $scheduledJob->getIdentifier());
                self::assertEquals($schedulingInformation->groupName, $scheduledJob->getGroupName());
                self::assertEquals($schedulingInformation->dueDate, $scheduledJob->getDueDate());
            });

        $copyToSchedulerAspect = new CopyToScheduler();
        $copyToSchedulerAspect->injectScheduler($scheduler);
        $copyToSchedulerAspect->execute($joinPoint);
    }

    /**
     * @test
     */
    public function Using_the_scheduler_even_for_scheduled_jobs_can_be_omitted(): void
    {
        $job = new class implements ScheduledJobInterface, JobInterface {

            public $executed = false;

            public function execute(QueueInterface $queue, Message $message): bool
            {
                $this->executed = true;
                return true;
            }

            public function getLabel(): string
            {
                return '';
            }

            public function getSchedulingInformation(): SchedulingInformation|ExecuteNormally|SkipExecution
            {
                return new SchedulingInformation('b86dcb7c-aefd-4078-8612-d856899f88bc');
            }
        };

        $joinPoint = $this->createMock(JoinPoint::class);
        $joinPoint
            ->expects(self::once())
            ->method('getProxy')
            ->willReturn($job);

        $adviceChain = $this->createMock(AdviceChain::class);
        $adviceChain
            ->expects(self::once())
            ->method('proceed')
            ->willReturnCallback(
                fn () => $job->execute(
                    $this->createMock(QueueInterface::class),
                    $this->createMock(Message::class)
                )
            );

        $joinPoint
            ->expects(self::once())
            ->method('getAdviceChain')
            ->willReturn($adviceChain);

        $copyToSchedulerAspect = new CopyToScheduler();

        CopyToScheduler::forceNormalExecution(
        // This is the part where no scheduler is used.
            fn () => $copyToSchedulerAspect->execute($joinPoint)
        );

        self::assertTrue($job->executed);
    }
}
