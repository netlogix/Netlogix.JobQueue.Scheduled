<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Domain;

use Flowpack\JobQueue\Common\Exception;
use Flowpack\JobQueue\Common\Queue\QueueManager;
use LogicException;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\DueDateCalculation\TimeBaseForDueDateCalculation;

class SchedulingCoordinator
{
    const DEFAULT_BACKOFF_STRATEGY = 'linear';
    const DEFAULT_NUMBER_OF_RETRIES = -1;
    const DEFAULT_RETRY_INTERVAL = 0;
    const DEFAULT_KEEP_FAILED_JOBS = false;

    private const DEFAULT_BEHAVIOR = [
        'backoffStrategy' => self::DEFAULT_BACKOFF_STRATEGY,
        'numberOfRetries' => self::DEFAULT_NUMBER_OF_RETRIES,
        'retryInterval' => self::DEFAULT_RETRY_INTERVAL
    ];

    /**
     * @var Scheduler
     */
    protected Scheduler $scheduler;

    /**
     * @var QueueManager
     */
    protected QueueManager $queueManager;

    /**
     * @var TimeBaseForDueDateCalculation
     */
    protected TimeBaseForDueDateCalculation $timeBaseForDueDateCalculation;

    /** @var ScheduledJob[] */
    protected $jobs = [];

    public function __construct(Scheduler $scheduler)
    {
        $this->scheduler = $scheduler;
    }

    public function injectQueueManager(QueueManager $queueManager): void
    {
        $this->queueManager = $queueManager;
    }

    public function injectTimeBaseForDueDateCalculation(TimeBaseForDueDateCalculation $timeBaseForDueDateCalculation
    ): void {
        $this->timeBaseForDueDateCalculation = $timeBaseForDueDateCalculation;
    }

    public function markJobForRescheduling(ScheduledJob $job): void
    {
        $this->jobs[] = $job;
    }

    public function scheduleAll(): void
    {
        if (!$this->jobs) {
            return;
        }

        foreach ($this->jobs as $job) {
            $retryConfiguration = $this->getRetryConfigurationForJob($job);

            $nextIncarnation = $job->getIncarnation() + 1;
            $numberOfRetries = (int)($retryConfiguration['numberOfRetries'] ?? self::DEFAULT_NUMBER_OF_RETRIES);
            if ($numberOfRetries === 0) {
                $this->scheduler->release($job);
                continue;
            } elseif ($numberOfRetries > 0 && $nextIncarnation > $numberOfRetries) {
                $keepFailedJobs = (bool)($retryConfiguration['keepFailedJobs'] ?? self::DEFAULT_KEEP_FAILED_JOBS);
                if ($keepFailedJobs) {
                    $this->scheduler->fail($job, 'retries exhausted');
                } else {
                    $this->scheduler->release($job);
                }
                continue;
            }

            switch ($retryConfiguration['backoffStrategy']) {
                case 'linear':
                    $retryInterval = (int)($retryConfiguration['retryInterval'] ?? self::DEFAULT_RETRY_INTERVAL);
                    $nextDueDate = $this
                        ->timeBaseForDueDateCalculation
                        ->getNow()
                        ->modify(\sprintf('+ %d seconds', $retryInterval));
                    break;
                case 'exponential':
                    $retryInterval = (int)($retryConfiguration['retryInterval'] ?? self::DEFAULT_RETRY_INTERVAL);
                    $backoff = pow(2, $job->getIncarnation()) * $retryInterval;
                    $nextDueDate = $this
                        ->timeBaseForDueDateCalculation
                        ->getNow()
                        ->modify(\sprintf('+ %d seconds', $backoff));
                    break;
                default:
                    throw new LogicException(
                        \sprintf('Backoff strategy "%s" is not implemented', $retryConfiguration['backoffStrategy']),
                        1655217685
                    );
            }

            $newJob = new ScheduledJob(
                $job->getJob(),
                $job->getQueueName(),
                $nextDueDate,
                $job->getGroupName(),
                $job->getIdentifier(),
                $nextIncarnation
            );

            $this->scheduler->schedule($newJob);
        }
    }

    /**
     * @param ScheduledJob $job
     * @return array{backoffStrategy: string, numberOfRetries: int, retryInterval: int}
     * @throws Exception
     */
    protected function getRetryConfigurationForJob(ScheduledJob $job): array
    {
        $queueSettings = $this->queueManager->getQueueSettings($job->getQueueName())['scheduledJobs'] ?? [];
        /** @phpstan-ignore-next-line */
        return \array_merge(self::DEFAULT_BEHAVIOR, $queueSettings);
    }
}
