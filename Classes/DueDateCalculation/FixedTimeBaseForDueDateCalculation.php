<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\DueDateCalculation;

use DateTimeImmutable;
use Neos\Flow\Utility\Now;

class FixedTimeBaseForDueDateCalculation implements TimeBaseForDueDateCalculation
{
    /**
     * @var Now
     */
    private $now;

    public function injectNow(Now $now): void
    {
        $this->now = $now;
    }

    public function getNow(): DateTimeImmutable
    {
        return $this->now;
    }
}
