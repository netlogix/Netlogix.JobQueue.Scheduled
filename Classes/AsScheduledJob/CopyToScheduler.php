<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\AsScheduledJob;

use Flowpack\JobQueue\Common\Job\JobInterface;
use Flowpack\JobQueue\Common\Queue\FakeQueue;
use Flowpack\JobQueue\Common\Queue\QueueInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use RuntimeException;

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

    private static bool $forceNormalExecution = false;

    /**
     * Forces every job to not go through the scheduler, but execute immediately.
     * This is useful for testing purposes.
     *
     * @template T
     * @param callable(): T $callable
     * @return T
     */
    public static function forceNormalExecution(callable $callable)
    {
        $executeNormally = self::$forceNormalExecution;
        self::$forceNormalExecution = true;
        try {
            return $callable();
        } finally {
            self::$forceNormalExecution = $executeNormally;
        }
    }

    private static ?JobInterface $dueJob = null;

    /**
     * Executes $job instead of scheduling it, because the scheduler found it due.
     * Every other job, e.g. one queued from within $job, still goes to the scheduler.
     *
     * @template T
     * @param callable(): T $callable
     * @return T
     */
    public static function executeAsDue(JobInterface $job, callable $callable)
    {
        $dueJob = self::$dueJob;
        self::$dueJob = $job;
        try {
            return $callable();
        } finally {
            self::$dueJob = $dueJob;
        }
    }

    /**
     * @Flow\Around("within(Netlogix\JobQueue\Scheduled\AsScheduledJob\ScheduledJobInterface) && within(Flowpack\JobQueue\Common\Job\JobInterface) && method(.*->execute())")
     */
    public function execute(JoinPointInterface $joinPoint): bool
    {
        $proceed = fn() => $joinPoint->getAdviceChain()->proceed($joinPoint);

        $job = $joinPoint->getProxy();
        if (self::$forceNormalExecution || !$job instanceof ScheduledJobInterface || $job === self::$dueJob) {
            return $proceed();
        }

        $queue = $joinPoint->getMethodArgument('queue');
        assert($queue instanceof QueueInterface);

        if ($queue instanceof FakeQueue && $queue->getName() === SchedulingInformation::QUEUE_NAME) {
            // Scheduler workers of earlier releases pass the bare job instead of a WorkerRun.
            // Callers outside the scheduler, e.g. tests, use this queue to execute a job directly.
            return $proceed();
        }

        $schedulingInformation = $job->getSchedulingInformation();
        switch (true) {
            case $schedulingInformation instanceof SchedulingInformation:
                $scheduledJob = ScheduledJob::createNew(
                    $job,
                    SchedulingInformation::QUEUE_NAME,
                    $schedulingInformation->dueDate,
                    $schedulingInformation->groupName,
                    $schedulingInformation->identifier
                );
                $this->scheduler->schedule($scheduledJob);
                return true;
            case $schedulingInformation instanceof ExecuteNormally:
                return $proceed();
            case $schedulingInformation instanceof SkipExecution:
                return true;
        }

        throw new RuntimeException('Unknown scheduling information type: ' . get_class($schedulingInformation), 1731921027);
    }
}
