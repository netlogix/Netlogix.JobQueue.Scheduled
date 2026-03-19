<?php

namespace Netlogix\JobQueue\Scheduled\Service;

use Doctrine\DBAL\Types\Types;
use Neos\Flow\Annotations as Flow;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;

#[Flow\Scope("singleton")]
abstract class JobStatusService {

    protected const string TOTAL_COUNT_QUERY = "";
    protected const string RUNNING_COUNT_QUERY = "";
    protected const string PENDING_COUNT_QUERY = "";
    protected const string STALE_COUNT_QUERY = "";
    protected const string FAILED_COUNT_QUERY = "";

    #[Flow\Inject]
    protected Scheduler $scheduler;

    public function getTotalJobCount(string $groupName): int {
        return $this->fetchOne(
            static::TOTAL_COUNT_QUERY,
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
            static::RUNNING_COUNT_QUERY,
            [
                'groupName' => $groupName,
                'seconds' => $this->scheduler->getStaleJobTimeoutSeconds()
            ],
            [
                'groupName' => Types::STRING,
                'seconds' => Types::INTEGER
            ]
        );
    }

    public function getPendingJobCount(string $groupName): int {
        return $this->fetchOne(
            static::PENDING_COUNT_QUERY,
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
            static::STALE_COUNT_QUERY,
            [
                "groupName" => $groupName,
                "seconds" => $this->scheduler->getStaleJobTimeoutSeconds()
            ],
            [
                "groupName" => Types::STRING,
                "seconds" => Types::INTEGER
            ]
        );
    }

    public function getFailedJobCount(string $groupName): int {
        return $this->fetchOne(
            static::FAILED_COUNT_QUERY,
            [
                'groupName' => $groupName
            ],
            [
                'groupName' => Types::STRING
            ]
        );
    }

    protected function fetchOne(string $query, array $params = [], array $types = []) {
        return $this->scheduler->getConnection()->fetchOne($query, $params, $types);
    }

}
