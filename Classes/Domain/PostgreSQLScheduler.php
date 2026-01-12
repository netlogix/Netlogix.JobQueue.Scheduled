<?php

namespace Netlogix\JobQueue\Scheduled\Domain;

class PostgreSQLScheduler extends AbstractScheduler {

    /**
     * @lang PostgreSQL
     */
    protected const CLAIM_QUERY = <<<PostgreSQL
        UPDATE netlogix_jobqueue_scheduled_job AS j
        SET claimed  = :claimed,
            running  = 2,
            activity = NOW()
        FROM (
            SELECT identifier
            FROM netlogix_jobqueue_scheduled_job
            WHERE duedate <= :now
              AND groupname = :groupname
              AND claimed = ''
              AND running = 0
            ORDER BY duedate ASC
            LIMIT 1
        ) AS delinquents
        WHERE j.identifier = delinquents.identifier
          AND j.claimed = '';

    PostgreSQL;

    /**
     * @lang PostgreSQL
     */
    protected const SELECT_QUERY = <<<PostgreSQL
        SELECT identifier, duedate, queue, job, incarnation, claimed, running
            FROM netlogix_jobqueue_scheduled_job
            WHERE claimed = :claimed
              AND groupname = :groupname
    PostgreSQL;

    /**
     * @lang PostgreSQL
     */
    protected const RELEASE_QUERY = <<<PostgreSQL
        UPDATE netlogix_jobqueue_scheduled_job
            SET running = 1,
                activity = NOW()
            WHERE claimed = :claimed
    PostgreSQL;

}