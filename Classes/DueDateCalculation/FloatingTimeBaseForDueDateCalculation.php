<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\DueDateCalculation;

use DateTimeImmutable;

class FloatingTimeBaseForDueDateCalculation implements TimeBaseForDueDateCalculation
{
    public function getNow(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
