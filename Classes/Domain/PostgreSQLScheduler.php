<?php

namespace Netlogix\JobQueue\Scheduled\Domain;

class PostgreSQLScheduler extends AbstractScheduler {

    protected const SCHEDULE_QUERY = /** @lang PostgreSQL */ <<<PostgreSQL
        INSERT INTO netlogix_jobqueue_scheduled_job
            (groupname, identifier, duedate, activity, queue, job, incarnation, claimed, running)
        VALUES (:groupname, :identifier, :duedate, NOW(), :queue, :job, :incarnation, :claimed, :running)
        ON CONFLICT (identifier) DO UPDATE
            SET
                duedate = CASE
                    WHEN netlogix_jobqueue_scheduled_job.running = TRUE
                        THEN EXCLUDED.duedate
                    WHEN netlogix_jobqueue_scheduled_job.duedate < EXCLUDED.duedate
                        THEN netlogix_jobqueue_scheduled_job.duedate
                    ELSE
                        EXCLUDED.duedate
                END,
                incarnation = :incarnation,
                queue    = :queue,
                job      = :job,
                claimed  = :claimed
       PostgreSQL;

    protected const NEXT_QUERY = /** @lang PostgreSQL */ <<<PostgreSQL
        WITH delinquents AS (
            SELECT identifier
            FROM netlogix_jobqueue_scheduled_job
            WHERE duedate   <= :now
              AND groupname  = :groupname
              AND claimed    = ''
              AND running    = FALSE
            ORDER BY duedate ASC
            LIMIT 1
            FOR UPDATE SKIP LOCKED
        )
        UPDATE netlogix_jobqueue_scheduled_job AS j
        SET claimed  = :claimed,
            running  = TRUE,
            activity = NOW()
        FROM delinquents
        WHERE j.identifier = delinquents.identifier
            AND j.claimed = '';
    PostgreSQL;

    protected function getSqlTrue(): mixed
    {
        return "TRUE";
    }

    protected function getSqlFalse(): mixed
    {
        return "FALSE";
    }

}