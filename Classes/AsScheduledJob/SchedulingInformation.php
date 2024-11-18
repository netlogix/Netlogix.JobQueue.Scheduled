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

    public function __construct(
        private string $identifier,
        private string $groupName = Scheduler::DEFAULT_GROUP_NAME,
        private ?DateTimeImmutable $dueDate = null
    ) {
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getGroupName(): string
    {
        return $this->groupName;
    }

    public function getDueDate(): DateTimeImmutable
    {
        return $this->dueDate ?? new DateTimeImmutable('now');
    }
}
