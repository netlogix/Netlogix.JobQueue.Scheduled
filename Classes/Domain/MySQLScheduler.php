<?php

namespace Netlogix\JobQueue\Scheduled\Domain;

class MySQLScheduler extends Scheduler {

    protected const SCHEDULE_QUERY = /** @lang MySQL */ <<<MySQL
        INSERT INTO netlogix_jobqueue_scheduled_job
            (groupname, identifier, duedate, activity, queue, job, incarnation, claimed, running)
        VALUES (:groupname, :identifier, :duedate, NOW(), :queue, :job, :incarnation, :claimed, :running)
        ON DUPLICATE KEY
            UPDATE
                duedate = CASE
                       WHEN running = 1
                           -- If the job is already running, this is "another one",
                           -- so schedule the next one according to its own date.
                           THEN :duedate
                       WHEN duedate < :duedate
                           -- If this reschedules a waiting job, use the lower value
                           THEN duedate
                       ELSE
                           :duedate
                   END,
                   incarnation = :incarnation,
                   queue    = :queue,
                   job      = :job,
                   claimed  = :claimed
        MySQL;

    /**
     * Step 1: Create a derived table using the "idx_for_update" index
     *         that only contains one row.
     * Step 2: Join that row against the actual job to be claimed on
     *         the primary key column.
     * Step 3: UPDATE that row with the claim value.
     *
     * Otherwise, MySQL would use the "idx_groupname" index, fetch
     * millions of rows, use a temp table to sort those millions
     * and limit the result to the one row to be claimed.
     *
     */
    protected const NEXT_QUERY = /** @lang MySQL */ <<<MySQL
        UPDATE (SELECT identifier
                FROM netlogix_jobqueue_scheduled_job
                WHERE duedate <= :now
                  AND groupname = :groupname
                  AND claimed = ""
                  AND running = 0
                ORDER BY duedate ASC
                LIMIT 1) AS delinquents
            INNER JOIN netlogix_jobqueue_scheduled_job
            USING (identifier)
        SET claimed = :claimed,
            running = 1,
            activity = NOW()
        WHERE claimed = ""
       MySQL;

}