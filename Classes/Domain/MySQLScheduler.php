<?php

namespace Netlogix\JobQueue\Scheduled\Domain;

class MySQLScheduler extends AbstractScheduler  {

     /**
     * Step 1: Insert the "claimed" value without locking.
     *
     * Step 1.1: Create a derived table using the "idx_for_update" index
     *           that only contains one row.
     * Step 1.2: Join that row against the actual job to be claimed on
     *           the primary key column.
     * Step 1.3: UPDATE that row with the claim value.
     *
     * Otherwise, MySQL would use the "idx_groupname" index, fetch
     * millions of rows, use a temp table to sort those millions,
     * and limit the result to the one row to be claimed.
     *
     * @lang MySQL
     */
    protected const CLAIM_QUERY = <<<MySQL
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
                running = 2,
                activity = NOW()
            WHERE claimed = ""
            MySQL;

    /**
     * Step 2: Find the row in the database.
     *
     * @lang MySQL
     */
    protected const SELECT_QUERY = <<<MySQL
        SELECT identifier, duedate, queue, job, incarnation, claimed, running
            FROM netlogix_jobqueue_scheduled_job
            WHERE claimed = :claimed
              AND groupname = :groupname
        MySQL;

    /**
     * Step 3: Unlock the row and allow parallel processes to overwrite the "claimed" value
     *
     * @lang MySQL
     */
    protected const RELEASE_QUERY = <<<MySQL
        UPDATE netlogix_jobqueue_scheduled_job
            SET running = 1,
                activity = NOW()
            WHERE claimed = :claimed
              AND groupname = :groupname
              AND running = 2
        MySQL;

    /**
     * `running = 0`:
     *
     * - Meaning: The existing job is currently pending.
     * - Set claimed to empty, which should be the case anyway.
     * - Use the lesser due date to avoid pushing jobs further and further into the future
     *
     * `running = 1`:
     *
     * - Meaning: The existing job is currently running.
     * - Set claimed to empty to cause a re-run, once the current run finishes.
     * - Use the upcoming due date, the current job is running anyway.
     *
     * `running = 2`:
     *
     * - Meaning: The existing job in its warmup phase.
     * - Keeping claimed "as is" is mandatory for the current run to pick up.
     * - The due date doesn't matter because once finished, the current run will vanish.
     *
     * @lang MySQL
     */
    protected const SCHEDULE_QUERY = <<<MySQL
            INSERT INTO netlogix_jobqueue_scheduled_job
                (groupname, identifier, duedate, activity, queue, job, incarnation, claimed, running)
            VALUES (:groupname, :identifier, :duedate, NOW(), :queue, :job, :incarnation, :claimed, :running)
            ON DUPLICATE KEY
                UPDATE
                    duedate        = CASE
                           WHEN running = 0
                               THEN IF(duedate < :duedate, duedate, :duedate)
                           WHEN running = 1
                               THEN :duedate
                           WHEN running = 2
                               THEN duedate
                       END,
                       incarnation = :incarnation,
                       queue       = :queue,
                       job         = :job,
                       claimed     = CASE
                           WHEN running = 0
                               THEN :claimed
                           WHEN running = 1
                               THEN :claimed
                           WHEN running = 2
                               THEN claimed
                       END
            MySQL;

}