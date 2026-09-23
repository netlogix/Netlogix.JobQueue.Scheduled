<?php

namespace Netlogix\JobQueue\Scheduled\Service;

use Doctrine\DBAL\Types\Types;
use Neos\Flow\Annotations as Flow;
use Netlogix\JobQueue\Scheduled\Domain\Group;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;

#[Flow\Scope("singleton")]
abstract class JobStatusService {

    abstract protected function buildTotalCountQuery(): string;

    /**
     * Counts jobs that are running and still reporting activity, so the group's
     * staleJobTimeout decides where "running" ends and "stale" begins.
     */
    abstract protected function buildRunningCountQuery(): string;

    abstract protected function buildPendingCountQuery(): string;

    abstract protected function buildStaleCountQuery(): string;

    abstract protected function buildFailedCountQuery(): string;

    #[Flow\Inject]
    protected Scheduler $scheduler;

    public function getTotalJobCount(string $groupName): int {
        return $this->fetchOne(
            $this->buildTotalCountQuery(),
            [
                'groupName' => $groupName
            ],
            [
                'groupName' => Types::STRING
            ]
        );
    }

    public function getRunningJobCount(string $groupName): int {
        return $this->fetchOne(
            $this->buildRunningCountQuery(),
            [
                'groupName' => $groupName,
                'seconds' => Group::get($groupName)->getStaleJobTimeout()
            ],
            [
                'groupName' => Types::STRING,
                'seconds' => Types::INTEGER
            ]
        );
    }

    public function getPendingJobCount(string $groupName): int {
        return $this->fetchOne(
            $this->buildPendingCountQuery(),
            [
                'groupName' => $groupName
            ],
            [
                'groupName' => Types::STRING
            ]
        );
    }

    public function getStaleJobCount(string $groupName): int {
        return $this->fetchOne(
            $this->buildStaleCountQuery(),
            [
                "groupName" => $groupName,
                "seconds" => Group::get($groupName)->getStaleJobTimeout()
            ],
            [
                "groupName" => Types::STRING,
                "seconds" => Types::INTEGER
            ]
        );
    }

    public function getFailedJobCount(string $groupName): int {
        return $this->fetchOne(
            $this->buildFailedCountQuery(),
            [
                'groupName' => $groupName
            ],
            [
                'groupName' => Types::STRING
            ]
        );
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, int|string> $types
     */
    protected function fetchOne(string $query, array $params = [], array $types = []): int
    {
        return (int) $this->scheduler->getConnection()->fetchOne($query, $params, $types);
    }

}
