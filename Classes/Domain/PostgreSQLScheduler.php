<?php

namespace Netlogix\JobQueue\Scheduled\Domain;

class PostgreSQLScheduler extends AbstractScheduler {

    /**
     * The candidate row MUST be selected in a `MATERIALIZED` CTE, never in an
     * inline `FROM (SELECT ... LIMIT 1 FOR UPDATE SKIP LOCKED)` subquery.
     *
     * PostgreSQL is free to place such an inline subquery on the inner side of
     * a nested-loop join and re-evaluate it once per outer row. Combined with
     * `FOR UPDATE SKIP LOCKED`, every re-evaluation skips the rows already
     * locked by previous iterations and returns the *next* candidate, so the
     * join ends up matching - and claiming - more than the single intended row
     * (all with the same claim value). Whether this happens depends on the
     * query plan, i.e. on table size and statistics, which is why it only
     * surfaces on large production tables and not on small dev databases.
     *
     * `AS MATERIALIZED` forces the candidate selection to be evaluated exactly
     * once, so `LIMIT 1` reliably bounds the update to a single row.
     *
     * @lang PostgreSQL
     */
    protected const CLAIM_QUERY = <<<PostgreSQL
        WITH delinquents AS MATERIALIZED (
            SELECT identifier
            FROM netlogix_jobqueue_scheduled_job
            WHERE duedate <= :now
              AND groupname = :groupname
              AND claimed = ''
              AND running = 0
            ORDER BY duedate ASC
            LIMIT 1
            FOR UPDATE SKIP LOCKED
        )
        UPDATE netlogix_jobqueue_scheduled_job AS j
        SET claimed  = :claimed,
            running  = 2,
            activity = NOW()
        FROM delinquents
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
              AND groupname = :groupname
              AND running = 2
        PostgreSQL;


    /**
     * @lang PostgreSQL
     */
    protected const SCHEDULE_QUERY = <<<PostgreSQL
        INSERT INTO netlogix_jobqueue_scheduled_job
            (groupname, identifier, duedate, activity, queue, job, incarnation, claimed, running)
        VALUES
            (:groupname, :identifier, :duedate, NOW(), :queue, :job, :incarnation, :claimed, :running)
        ON CONFLICT (identifier) DO UPDATE
        SET
            duedate = CASE
                WHEN netlogix_jobqueue_scheduled_job.running = 0
                    THEN LEAST(netlogix_jobqueue_scheduled_job.duedate, EXCLUDED.duedate)
                WHEN netlogix_jobqueue_scheduled_job.running = 1
                    THEN EXCLUDED.duedate
                WHEN netlogix_jobqueue_scheduled_job.running = 2
                    THEN netlogix_jobqueue_scheduled_job.duedate
            END,
            incarnation = EXCLUDED.incarnation,
            queue       = EXCLUDED.queue,
            job         = EXCLUDED.job,
            claimed     = CASE
                WHEN netlogix_jobqueue_scheduled_job.running IN (0, 1)
                    THEN EXCLUDED.claimed
                WHEN netlogix_jobqueue_scheduled_job.running = 2
                    THEN netlogix_jobqueue_scheduled_job.claimed
            END;
        PostgreSQL;

    protected const RESET_STALE_JOBS_QUERY = <<<PostgreSQL
        UPDATE netlogix_jobqueue_scheduled_job
        SET running = 0,
            claimed = '',
            incarnation = incarnation + 1
        WHERE running = 1
          AND claimed NOT LIKE 'failed(%)'
          AND groupname = :groupName
          AND activity < NOW() - make_interval(secs => :seconds)
        PostgreSQL;

}
