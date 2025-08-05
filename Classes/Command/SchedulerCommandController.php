<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Command;

use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\ORM\EntityManagerInterface;
use Flowpack\JobQueue\Common\Job\JobManager;
use Flowpack\JobQueue\Common\Queue\FakeQueue;
use Flowpack\JobQueue\Common\Queue\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Log\ThrowableStorageInterface;
use Netlogix\JobQueue\Scheduled\AsScheduledJob\SchedulingInformation;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\SchedulingCoordinator;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;

/**
 * @Flow\Scope("singleton")
 */
class SchedulerCommandController extends CommandController
{
    private const TEN_MINUTES_IN_SECONDS = 600;

    protected $stopPollingAfter = self::TEN_MINUTES_IN_SECONDS;

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

    public function injectEntityManager(EntityManagerInterface $entityManager): void
    {
        /*
         * Find a better way to keep connections open. This might only work for MySQL.
         */
        $entityManager
            ->getConnection()
            ->exec('SET SESSION wait_timeout = 3600;');
    }

    /**
     * Fetch due jobs and schedule them for execution.
     */
    public function queueDueJobsCommand(string $groupName): void
    {
        $this->queueDueJobs($groupName);
    }

    /**
     * Fetch due jobs and schedule them, then wait and retry.
     * This is probably not the best way of polling for changes
     */
    public function pollForIncomingJobsCommand(string $groupName): void
    {
        $startTime = time();
        $endTime = $startTime + $this->stopPollingAfter;

        while (true) {
            $numberOfHandledJobs = $this->queueDueJobs($groupName, $endTime);
            if (time() >= $endTime) {
                return;
            }
            if ($numberOfHandledJobs === 0) {
                sleep(1);
            }
        }
    }

    /**
     * @param string $groupName Handle only jobs of this group
     * @param int $endTime Stop handling new jobs once this time is reached
     * @return int Number of handled jobs
     * @throws ConnectionLost
     */
    protected function queueDueJobs(string $groupName, int $endTime): int
    {
        $numberOfHandledJobs = 0;
        $retry = new SchedulingCoordinator($this->scheduler);

        while ($next = $this->scheduler->next($groupName)) {
            try {
                if ($next->getQueueName() === SchedulingInformation::QUEUE_NAME) {
                    $this->executeLocally($next);
                } else {
                    $this->executeInQueue($next);
                }
                $numberOfHandledJobs++;
                $this->scheduler->release($next);
            } catch(ConnectionLost $e) {
                // Assuming we're running as supervisor process: Kill and restart
                // The task in question might be stuck because they are claimed and we cannot release them
                throw $e;
            } catch (\Throwable $throwable) {
                $this->throwableStorage->logThrowable($throwable);
                $retry->markJobForRescheduling($next);
            }
            if (time() >= $endTime) {
                return $numberOfHandledJobs;
            }
        }

        $retry->scheduleAll();
        return $numberOfHandledJobs;
    }

    protected function executeLocally(ScheduledJob $scheduledJob): void
    {
        $job = $scheduledJob->getJob();
        $message = new Message('3429a80d-1c21-433d-8d9f-82468b53fb2b', $job, 0);
        $job->execute(new FakeQueue(SchedulingInformation::QUEUE_NAME), $message);
    }

    protected function executeInQueue(ScheduledJob $next): void
    {
        $this->jobManager->queue(
            $next->getQueueName(),
            $next->getJob()
        );
    }
}
