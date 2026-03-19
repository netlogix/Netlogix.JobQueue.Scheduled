<?php

namespace Netlogix\JobQueue\Scheduled\Service;

class MySQLJobStatusService extends JobStatusService {

    protected const string TOTAL_COUNT_QUERY = <<<MySQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE groupname = :groupName
        MySQL;

    protected const string RUNNING_COUNT_QUERY = <<<MySQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE running = 1
        AND claimed NOT LIKE 'failed(%)'
        AND groupname = :groupName
        AND activity > NOW() - INTERVAL :seconds SECOND
        MySQL;

    protected const string PENDING_COUNT_QUERY = <<<MySQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE ((running = 0
                   AND claimed = '')
          OR running = 2)
        AND groupname = :groupName
        MySQL;

    protected const string STALE_COUNT_QUERY = <<<MySQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE running = 1
        AND claimed NOT LIKE 'failed(%)'
        AND groupname = :groupName
        AND activity <= NOW() - INTERVAL :seconds SECOND
        MySQL;

    protected const string FAILED_COUNT_QUERY = <<<MySQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE claimed LIKE 'failed(%)'
        AND groupname = :groupName
        MySQL;

    protected function fetchOne(string $query, array $params = [], array $types = []) {
        return $this->scheduler->getConnection()->fetchOneReadUncommited($query, $params, $types);
    }

}
