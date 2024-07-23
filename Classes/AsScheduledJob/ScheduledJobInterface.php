<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\AsScheduledJob;


use Flowpack\JobQueue\Common\Job\JobInterface;

/**
 * Jobs implementing this interface will be moved into the scheduler
 */
interface ScheduledJobInterface
{
    /**
     * Returns data on how to convert this job into a ScheduledJob
     *
     * If no scheduling information is returned, the job must not
     * be converted into a scheduled job.
     *
     * This can be used to enable or disable scheduling for a given
     * job or job type either by job content or by configuration.
     *
     * @return SchedulingInformation|null
     */
    public function getSchedulingInformation(): ?SchedulingInformation;
}
