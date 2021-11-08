<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Command;

use Flowpack\JobQueue\Common\Job\JobManager;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;

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

    public function injectScheduler(Scheduler $scheduler): void
    {
        $this->scheduler = $scheduler;
    }

    public function injectJobManager(JobManager $jobManager): void
    {
        $this->jobManager = $jobManager;
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
        $reschedule = [];
        $exceptions = [];

        $numberOfHandledJobs = 0;

        while ($next = $this->scheduler->next()) {
            $numberOfHandledJobs++;
            try {
                $this->jobManager->queue(
                    $next->getQueueName(),
                    $next->getJob()
                );
            } catch (\Throwable $e) {
                // TODO: Back off strategy
                $reschedule[] = $next;
                $exceptions[] = $e;
            }
        }
        if ($reschedule) {
            $this->scheduler->schedule(... $reschedule);
            // TODO: Log all throwables
            throw $exceptions[0];
        }

        return $numberOfHandledJobs;
    }
}
