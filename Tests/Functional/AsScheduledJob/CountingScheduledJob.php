<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional\AsScheduledJob;

use Flowpack\JobQueue\Common\Job\JobInterface;
use Flowpack\JobQueue\Common\Queue\Message;
use Flowpack\JobQueue\Common\Queue\QueueInterface;
use Netlogix\JobQueue\Scheduled\AsScheduledJob\ScheduledJobInterface;
use Netlogix\JobQueue\Scheduled\AsScheduledJob\SchedulingInformation;

/**
 * Lives in Tests/Functional so Flow proxies it and CopyToScheduler advises execute().
 */
class CountingScheduledJob implements JobInterface, ScheduledJobInterface
{
    public static int $executions = 0;

    public function execute(QueueInterface $queue, Message $message): bool
    {
        self::$executions++;
        return true;
    }

    public function getLabel(): string
    {
        return 'counting scheduled job';
    }

    public function getSchedulingInformation(): SchedulingInformation
    {
        return new SchedulingInformation('counting-scheduled-job');
    }
}
