<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\AsScheduledJob;


use DateTimeImmutable;
use Flowpack\JobQueue\Common\Job\JobInterface;
use Flowpack\JobQueue\Common\Queue\FakeQueue;
use Flowpack\JobQueue\Common\Queue\Message;
use Flowpack\JobQueue\Common\Queue\QueueInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;

/**
 * @Flow\Aspect
 * @Flow\Scope("singleton")
 */
class CopyToScheduler
{
    private Scheduler $scheduler;

    public function injectScheduler(Scheduler $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    /**
     * @Flow\Around("within(Netlogix\JobQueue\Scheduled\AsScheduledJob\ScheduledJobInterface) && within(Flowpack\JobQueue\Common\Job\JobInterface) && method(.*->execute())")
     */
    public function execute(JoinPointInterface $joinPoint): bool
    {
        $job = $joinPoint->getProxy();
        assert($job instanceof JobInterface);

        $queue = $joinPoint->getMethodArgument('queue');
        assert($queue instanceof QueueInterface);

        if ($queue instanceof FakeQueue && $queue->getName() === SchedulingInformation::QUEUE_NAME) {
            // This is the call done by the scheduling worker, so actually work on the job instead of scheduling it.
            return $joinPoint->getAdviceChain()->proceed($joinPoint);
        }

        $scheduledJob = $this->convertMessageToScheduledJob($job);
        if (!$scheduledJob) {
            // The job decided to not provide scheduling information, so don't schedule it.
            return $joinPoint->getAdviceChain()->proceed($joinPoint);
        }

        $this->scheduler->schedule($scheduledJob);

        return true;
    }

    protected function convertMessageToScheduledJob(
        ScheduledJobInterface $job
    ): ?ScheduledJob {
        assert($job instanceof JobInterface);
        $schedulingInformation = $job->getSchedulingInformation();
        if ($schedulingInformation === null) {
            return null;
        }
        return new ScheduledJob(
            $job,
            SchedulingInformation::QUEUE_NAME,
            $schedulingInformation->getDueDate(),
            $schedulingInformation->getGroupName(),
            $schedulingInformation->getIdentifier()
        );
    }
}
