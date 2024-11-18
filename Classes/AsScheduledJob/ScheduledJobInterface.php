<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\AsScheduledJob;

/**
 * Jobs implementing this interface will be moved into the scheduler
 */
interface ScheduledJobInterface
{
    /**
     * Returns data on how to convert this job into a ScheduledJob:
     * * SchedulingInformation: behaves as before. Allows to reschedule a job
     * * ExecuteNormally: will execute the job as defined in the original queue
     * * SkipExecution: will not execute the job and simply return true
     *
     * This can be used to enable or disable scheduling for a given
     * job or job type either by job content or by configuration.
     *
     * @return SchedulingInformation|ExecuteNormally|SkipExecution
     */
    public function getSchedulingInformation(): SchedulingInformation|ExecuteNormally|SkipExecution;
}
