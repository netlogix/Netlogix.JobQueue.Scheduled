<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\AsScheduledJob;

use DateTimeImmutable;
use Neos\Flow\Annotations as Flow;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;

/**
 * @Flow\Proxy(false))
 */
final readonly class SchedulingInformation
{
    public const QUEUE_NAME = 'netlogix-scheduled-fakequeue';

    public DateTimeImmutable $dueDate;

    public function __construct(
        public string $identifier,
        public string $groupName = Scheduler::DEFAULT_GROUP_NAME,
        ?DateTimeImmutable $dueDate = null
    ) {
        $this->dueDate = $dueDate ?? new DateTimeImmutable('now');
    }
}
