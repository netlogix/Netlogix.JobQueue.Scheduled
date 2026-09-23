<?php

namespace Netlogix\JobQueue\Scheduled\Service;

class MySQLJobStatusService extends JobStatusService {

    protected function buildTotalCountQuery(): string
    {
        return /** @lang MySQL */ <<<MySQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE groupname = :groupName
        MySQL;
    }

    protected function buildRunningCountQuery(): string
    {
        return /** @lang MySQL */ <<<MySQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE running = 1
        AND claimed NOT LIKE 'failed(%)'
        AND groupname = :groupName
        AND activity > NOW() - INTERVAL :seconds SECOND
        MySQL;
    }

    protected function buildPendingCountQuery(): string
    {
        return /** @lang MySQL */ <<<MySQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE ((running = 0
                   AND claimed = '')
          OR running = 2)
        AND groupname = :groupName
        MySQL;
    }

    protected function buildStaleCountQuery(): string
    {
        return /** @lang MySQL */ <<<MySQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE running = 1
        AND claimed NOT LIKE 'failed(%)'
        AND groupname = :groupName
        AND activity <= NOW() - INTERVAL :seconds SECOND
        MySQL;
    }

    protected function buildFailedCountQuery(): string
    {
        return /** @lang MySQL */ <<<MySQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE claimed LIKE 'failed(%)'
        AND groupname = :groupName
        MySQL;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, int|string> $types
     */
    protected function fetchOne(string $query, array $params = [], array $types = []): int
    {
        return (int) $this->scheduler->getConnection()->fetchOneReadUncommited($query, $params, $types);
    }

}
