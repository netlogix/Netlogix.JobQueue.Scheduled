<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\AsScheduledJob;

use Flowpack\JobQueue\Common\Job\JobInterface;
use Flowpack\JobQueue\Common\Queue\Message;
use Flowpack\JobQueue\Common\Queue\QueueInterface;
use Neos\Flow\Annotations as Flow;
use RuntimeException;

use function unserialize;

/**
 * The payload the scheduler hands to its workers. It marks the wrapped job as due, so
 * CopyToScheduler executes it no matter which queue it was scheduled to.
 */
#[Flow\Proxy(false)]
final class WorkerRun implements JobInterface
{
    private ?JobInterface $job = null;

    public function __construct(
        private readonly string $serializedJob
    ) {
    }

    public function execute(QueueInterface $queue, Message $message): bool
    {
        $job = $this->job();

        return CopyToScheduler::executeAsDue($job, fn () => $job->execute($queue, $message));
    }

    public function getLabel(): string
    {
        return $this->job()->getLabel();
    }

    private function job(): JobInterface
    {
        if ($this->job === null) {
            $job = unserialize($this->serializedJob);
            if (!$job instanceof JobInterface) {
                throw new RuntimeException('The scheduled payload is not a job', 1795102310);
            }
            $this->job = $job;
        }

        return $this->job;
    }
}
