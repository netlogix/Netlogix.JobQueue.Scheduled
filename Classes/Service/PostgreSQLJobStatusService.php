<?php

namespace Netlogix\JobQueue\Scheduled\Service;

class PostgreSQLJobStatusService extends JobStatusService {

    protected const string TOTAL_COUNT_QUERY = <<<PostgreSQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE groupname = :groupName
        PostgreSQL;

    protected const string RUNNING_COUNT_QUERY = <<<PostgreSQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE running = 1
        AND claimed NOT LIKE 'failed(%)'
        AND groupname = :groupName
        AND activity > NOW() - make_interval(secs => :seconds)
        PostgreSQL;

    protected const string PENDING_COUNT_QUERY = <<<PostgreSQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE ((running = 0
                   AND claimed = '')
          OR running = 2)
        AND groupname = :groupName
        PostgreSQL;

    protected const string STALE_COUNT_QUERY = <<<PostgreSQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE running = 1
        AND claimed NOT LIKE 'failed(%)'
        AND groupname = :groupName
        AND activity <= NOW() - make_interval(secs => :seconds)
        PostgreSQL;

    protected const string FAILED_COUNT_QUERY = <<<PostgreSQL
        SELECT COUNT(*) FROM netlogix_jobqueue_scheduled_job
        WHERE claimed LIKE 'failed(%)'
        AND groupname = :groupName
        PostgreSQL;

}
