<?php

namespace Netlogix\JobQueue\Scheduled\Domain;

use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Service\Connection;

interface Scheduler {

    public const DEFAULT_GROUP_NAME = 'default';

    /**
     * Schedules jobs, deduplicated by their identifier.
     *
     * A job that is already being executed is a case of its own: scheduling its identifier
     * again during that run outlives the run.
     */
    public function schedule(ScheduledJob $job, ScheduledJob ...$jobs): void;

    public function isScheduled(string $groupName, string $identifier): bool;

    public function ping(): void;

    /**
     * Claims the oldest due job of any of the given groups.
     *
     * At least one group is mandatory: resolving "no argument" to "all groups"
     * belongs to the caller, which knows which of them still have capacity.
     */
    public function next(string $groupName, string ...$furtherGroupNames): ?ScheduledJob;

    public function release(ScheduledJob $job): void;

    public function fail(ScheduledJob $job, string $reason): void;

    public function activity(ScheduledJob $job): void;

    public function resetStaleJobs(string $groupName): int;

    public function getConnection(): Connection;
}
