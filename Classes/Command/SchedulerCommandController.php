<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Command;

use Flowpack\JobQueue\Common\Job\JobManager;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Log\ThrowableStorageInterface;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Retry;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use RuntimeException;

/**
 * @Flow\Scope("singleton")
 */
class SchedulerCommandController extends CommandController
{
    /**
     * @var Scheduler
     */
    protected $scheduler;

    /**
     * @var JobManager
     */
    protected $jobManager;

    /**
     * @var ThrowableStorageInterface
     */
    protected $throwableStorage;

    public function injectScheduler(Scheduler $scheduler): void
    {
        $this->scheduler = $scheduler;
    }

    public function injectJobManager(JobManager $jobManager): void
    {
        $this->jobManager = $jobManager;
    }

    public function injectThrowableStorageInterface(ThrowableStorageInterface $throwableStorage): void
    {
        $this->throwableStorage = $throwableStorage;
    }

    /**
     * Fetch due jobs and schedule them for execution.
     */
    public function queueDueJobsCommand(): void
    {
        $this->queueDueJobs();
    }

    /**
     * Fetch due jobs and schedule them, then wait and retry.
     * This is probably not the best way of polling for changes
     */
    public function pollForIncomingJobsCommand(): void
    {
        $startTime = time();
        $oneHourInSeconds = 3600;
        $endTime = $startTime + $oneHourInSeconds;

        while (true) {
            $numberOfHandledJobs = $this->queueDueJobs();
            if (time() >= $endTime) {
                return;
            }
            if ($numberOfHandledJobs === 0) {
                sleep(1);
            }
        }
    }

    /**
     * @return int Number of handled jobs
     */
    protected function queueDueJobs(): int
    {
        $numberOfHandledJobs = 0;
        $retry = new Retry($this->scheduler);

        while ($next = $this->scheduler->next()) {
            try {
                $this->jobManager->queue(
                    $next->getQueueName(),
                    $next->getJob()
                );
                $numberOfHandledJobs++;
            } catch (\Throwable $throwable) {
                $this->throwableStorage->logThrowable($throwable);
                $retry->markJobForRescheduling($next);
            }
        }

        $retry->scheduleAll();
        return $numberOfHandledJobs;
    }
}
