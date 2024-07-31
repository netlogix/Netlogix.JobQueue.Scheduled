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
use Netlogix\JobQueue\Scheduled\Domain\Retry;
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
        $oneHourInSeconds = 3600;
        $endTime = $startTime + $oneHourInSeconds;

        while (true) {
            $numberOfHandledJobs = $this->queueDueJobs($groupName);
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
    protected function queueDueJobs(string $groupName): int
    {
        $numberOfHandledJobs = 0;
        $retry = new Retry($this->scheduler);

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
