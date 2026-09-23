<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Domain;

use Neos\Flow\Annotations as Flow;

use function is_array;
use function max;

/**
 * Configuration of one job group, handed out by the GroupRepository.
 *
 * Groups are configured below "Netlogix.JobQueue.Scheduled.groups". Every value
 * falls back to a constant of this class, so a group needs to spell out only
 * what deviates.
 */
#[Flow\Proxy(false)]
final readonly class Group
{
    private const PARALLEL = 1;
    private const POLLING_INTERVAL = 0.1;
    private const PREFORK_SIZE = 0;
    private const CHILD_PROCESS_POLL_INTERVAL = 0.1;
    private const STALE_JOB_TIMEOUT = 60;

    public function __construct(
        private string $name,
        private int $parallel = self::PARALLEL,
        private float $pollingInterval = self::POLLING_INTERVAL,
        private int $preforkSize = self::PREFORK_SIZE,
        private float $childProcessPollInterval = self::CHILD_PROCESS_POLL_INTERVAL,
        private int $staleJobTimeout = self::STALE_JOB_TIMEOUT,
    ) {
    }

    /**
     * @param mixed $configuration A configuration array, or a truthy scalar for all defaults
     */
    public static function fromConfiguration(string $name, mixed $configuration): self
    {
        if (!is_array($configuration)) {
            return new self($name);
        }

        return new self(
            name: $name,
            parallel: max((int) ($configuration['parallel'] ?? self::PARALLEL), 1),
            pollingInterval: (float) ($configuration['pollingInterval'] ?? self::POLLING_INTERVAL),
            preforkSize: max((int) ($configuration['preforkSize'] ?? self::PREFORK_SIZE), 0),
            childProcessPollInterval: (float) ($configuration['childProcessPollInterval'] ?? self::CHILD_PROCESS_POLL_INTERVAL),
            staleJobTimeout: max((int) ($configuration['staleJobTimeout'] ?? self::STALE_JOB_TIMEOUT), 1),
        );
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getParallel(): int
    {
        return $this->parallel;
    }

    public function getPollingInterval(): float
    {
        return $this->pollingInterval;
    }

    public function getPreforkSize(): int
    {
        return $this->preforkSize;
    }

    public function getChildProcessPollInterval(): float
    {
        return $this->childProcessPollInterval;
    }

    public function getStaleJobTimeout(): int
    {
        return $this->staleJobTimeout;
    }
}
