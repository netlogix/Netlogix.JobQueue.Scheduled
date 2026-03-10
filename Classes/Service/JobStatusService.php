<?php

namespace Netlogix\JobQueue\Scheduled\Service;

use Doctrine\DBAL\Types\Types;
use Neos\Flow\Annotations as Flow;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;

#[Flow\Scope("singleton")]
abstract class JobStatusService {

    #[Flow\Inject]
    protected Scheduler $scheduler;

    public function getTotalJobCount(string $groupName): int {
        $tableName = ScheduledJob::TABLE_NAME;
        $query = <<<MySQL
            SELECT COUNT(*) FROM {$tableName}
            WHERE groupname = :groupName
            MySQL;
        return $this->fetchOne(
            $query,
            [
                'groupName' => $groupName
            ],
            [
                'groupName' => Types::STRING
            ]
        );
    }

    public function getRunningJobCount(string $groupName): int {
        $tableName = ScheduledJob::TABLE_NAME;
        $query = <<<MySQL
            SELECT COUNT(*) FROM {$tableName}
            WHERE running = 1
            AND claimed NOT LIKE 'failed(%)'
            AND groupname = :groupName
            AND activity > NOW() - INTERVAL 2 SECOND
            MySQL;
        return $this->fetchOne(
            $query,
            [
                'groupName' => $groupName
            ],
            [
                'groupName' => Types::STRING
            ]
        );
    }

    public function getPendingJobCount(string $groupName): int {
        $tableName = ScheduledJob::TABLE_NAME;
        $query = <<<MySQL
            SELECT COUNT(*) FROM {$tableName}
            WHERE ((running = 0
                       AND claimed = '')
              OR running = 2)
            AND groupname = :groupName
            MySQL;
        return $this->fetchOne(
            $query,
            [
                'groupName' => $groupName
            ],
            [
                'groupName' => Types::STRING
            ]
        );
    }

    public function getStaleJobCount(string $groupName, int $minutes): int {
        $tableName = ScheduledJob::TABLE_NAME;
        $query = <<<MySQL
            SELECT COUNT(*) FROM {$tableName}
            WHERE running = 1
            AND claimed NOT LIKE 'failed(%)'
            AND groupname = :groupName
            AND activity < NOW() - INTERVAL :minutes MINUTE
            MySQL;
        return $this->fetchOne(
            $query,
            [
                "groupName" => $groupName,
                "minutes" => $minutes
            ],
            [
                "groupName" => Types::STRING,
                "minutes" => Types::INTEGER
            ]
        );
    }

    public function getFailedJobCount(string $groupName): int {
        $tableName = ScheduledJob::TABLE_NAME;
        $query = <<<MySQL
            SELECT COUNT(*) FROM {$tableName}
            WHERE claimed LIKE 'failed(%)'
            AND groupname = :groupName
            MySQL;
        return $this->fetchOne(
            $query,
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
