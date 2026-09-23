<?php

namespace Netlogix\JobQueue\Scheduled\Service;

class PostgreSQLJobStatusService extends JobStatusService {

    protected function buildTotalCountQuery(): string
    {
        return /** @lang PostgreSQL */ <<<PostgreSQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE groupname = :groupName
        PostgreSQL;
    }

    protected function buildRunningCountQuery(): string
    {
        return /** @lang PostgreSQL */ <<<PostgreSQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE running = 1
        AND claimed NOT LIKE 'failed(%)'
        AND groupname = :groupName
        AND activity > NOW() - make_interval(secs => :seconds)
        PostgreSQL;
    }

    protected function buildPendingCountQuery(): string
    {
        return /** @lang PostgreSQL */ <<<PostgreSQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE ((running = 0
                   AND claimed = '')
          OR running = 2)
        AND groupname = :groupName
        PostgreSQL;
    }

    protected function buildStaleCountQuery(): string
    {
        return /** @lang PostgreSQL */ <<<PostgreSQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE running = 1
        AND claimed NOT LIKE 'failed(%)'
        AND groupname = :groupName
        AND activity <= NOW() - make_interval(secs => :seconds)
        PostgreSQL;
    }

    protected function buildFailedCountQuery(): string
    {
        return /** @lang PostgreSQL */ <<<PostgreSQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE claimed LIKE 'failed(%)'
        AND groupname = :groupName
        PostgreSQL;
    }

}
