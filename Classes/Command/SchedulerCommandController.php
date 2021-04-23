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
        $reschedule = [];
        $exceptions = [];
        while ($next = $this->scheduler->next()) {
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
    }

}
