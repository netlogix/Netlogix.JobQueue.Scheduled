<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Fixture;

use Flowpack\JobQueue\Common\Job\JobInterface;
use Flowpack\JobQueue\Common\Queue\Message;
use Flowpack\JobQueue\Common\Queue\QueueInterface;

final class JobQueueJob implements JobInterface
{
    private $label;

    private function __construct(string $label)
    {
        $this->label = $label;
    }

    public static function first(): self
    {
        return new static('first');
    }

    public static function second(): self
    {
        return new static('second');
    }

    public function execute(QueueInterface $queue, Message $message): bool
    {
        return true;
    }

    public function getLabel(): string
    {
        return $this->label;
    }
}
