<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional\AsScheduledJob;

use Flowpack\JobQueue\Common\Job\JobInterface;
use Netlogix\JobQueue\Scheduled\AsScheduledJob\ScheduledJobInterface;

interface TestingScheduledJobInterface extends JobInterface, ScheduledJobInterface {

}
