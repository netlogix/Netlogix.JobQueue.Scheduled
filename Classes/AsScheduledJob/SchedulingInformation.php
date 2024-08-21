<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\AsScheduledJob;

use DateTimeImmutable;
use Neos\Flow\Annotations as Flow;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;

/**
 * @Flow\Proxy(false))
 */
final class SchedulingInformation
{
    public const QUEUE_NAME = 'netlogix-scheduled-fakequeue';

    private $identifier;

    private $groupName;

    private $dueDate;

    public function __construct(
        string $identifier,
        string $groupName = Scheduler::DEFAULT_GROUP_NAME,
        DateTimeImmutable $dueDate = null
    ) {
        $this->identifier = $identifier;
        $this->groupName = $groupName;
        $this->dueDate = $dueDate;
    }

    public function getIdentifier()
    {
        return $this->identifier;
    }

    public function getGroupName()
    {
        return $this->groupName;
    }

    public function getDueDate(): DateTimeImmutable
    {
        return $this->dueDate ? $this->dueDate : new DateTimeImmutable('now');
    }
}
