<?php

namespace Netlogix\JobQueue\Scheduled\Domain;

use function array_keys;
use function implode;

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
     * Each group needs its own CTE: PostgreSQL rejects `FOR UPDATE` in a query
     * that carries a `UNION`, so the lock cannot sit on the combined select. As
     * a result one row per group is locked although only one of them is
     * claimed. With autocommit the transaction ends with the statement, and
     * competing pollers skip the locked rows and take the next oldest.
     */
    protected function buildClaimQuery(string ...$groupNames): string
    {
        $candidates = [];
        $branches = [];
        foreach (array_keys($groupNames) as $index) {
            $candidates[] = <<<PostgreSQL
                c{$index} AS MATERIALIZED (
                    SELECT identifier, duedate
                    FROM netlogix_jobqueue_scheduled_job
                    WHERE duedate <= :now
                      AND groupname = :g{$index}
                      AND claimed = ''
                      AND running = 0
                    ORDER BY duedate ASC
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
                )
                PostgreSQL;
            $branches[] = "SELECT identifier, duedate FROM c{$index}";
        }
        $candidates = implode(",\n", $candidates);
        $branches = implode("\n                UNION ALL\n                ", $branches);

        return /** @lang PostgreSQL */ <<<PostgreSQL
        WITH {$candidates},
        delinquents AS (
            SELECT identifier
            FROM (
                {$branches}
            ) AS candidates
            ORDER BY duedate ASC
            LIMIT 1
        )
        UPDATE netlogix_jobqueue_scheduled_job AS j
        SET claimed  = :claimed,
            running  = 2,
            activity = NOW()
        FROM delinquents
        WHERE j.identifier = delinquents.identifier
          AND j.claimed = '';
        PostgreSQL;
    }

    protected function buildSelectQuery(): string
    {
        return /** @lang PostgreSQL */ <<<PostgreSQL
        SELECT identifier, groupname, duedate, queue, job, incarnation, claimed, running
            FROM netlogix_jobqueue_scheduled_job
            WHERE claimed = :claimed
        PostgreSQL;
    }

    protected function buildReleaseQuery(): string
    {
        return /** @lang PostgreSQL */ <<<PostgreSQL
        UPDATE netlogix_jobqueue_scheduled_job
            SET running = 1,
                activity = NOW()
            WHERE claimed = :claimed
              AND running = 2
        PostgreSQL;
    }


    protected function buildScheduleQuery(): string
    {
        return /** @lang PostgreSQL */ <<<PostgreSQL
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
    }

    protected function buildResetStaleJobsQuery(): string
    {
        return /** @lang PostgreSQL */ <<<PostgreSQL
        UPDATE netlogix_jobqueue_scheduled_job
        SET running = 0,
            claimed = '',
            incarnation = incarnation + 1
        WHERE running IN (1, 2)
          AND claimed NOT LIKE 'failed(%'
          AND groupname = :groupName
          AND activity < NOW() - make_interval(secs => :seconds)
        PostgreSQL;
    }

}
