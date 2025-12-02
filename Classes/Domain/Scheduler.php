<?php

namespace Netlogix\JobQueue\Scheduled\Domain;

use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;

interface Scheduler {

    public function schedule(ScheduledJob $job, ScheduledJob ...$jobs): void;

    public function isScheduled(string $groupName, string $identifier): bool;

    public function ping(): void;

    public function next(string $groupName): ?ScheduledJob;

    public function release(ScheduledJob $job): void;

    public function fail(ScheduledJob $job, string $reason): void;

    public function activity(ScheduledJob $job): void;

}