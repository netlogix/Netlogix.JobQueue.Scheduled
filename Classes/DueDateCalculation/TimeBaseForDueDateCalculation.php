<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\DueDateCalculation;

use DateTimeImmutable;

interface TimeBaseForDueDateCalculation
{
    public function getNow(): DateTimeImmutable;
}
