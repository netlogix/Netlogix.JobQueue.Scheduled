<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Domain;

use InvalidArgumentException;
use Netlogix\JobQueue\Pool\Pool;
use WeakReference;

use function array_filter;
use function array_keys;
use function array_values;
use function is_array;
use function max;
use function sprintf;

/**
 * One job group: its name, its configuration and its pool of worker processes.
 *
 * Groups are configured below "Netlogix.JobQueue.Scheduled.groups". Every value
 * falls back to a constant of this class, so a group needs to spell out only
 * what deviates.
 */
class Group
{
    private const PARALLEL = 1;
    private const POLLING_INTERVAL = 0.1;
    private const PREFORK_SIZE = 0;
    private const CHILD_PROCESS_POLL_INTERVAL = 0.1;
    private const STALE_JOB_TIMEOUT = 60;

    /**
     * Groups are handed out weakly, so that callers which only need the
     * configuration - resetting stale jobs, counting jobs for metrics - do not
     * keep one alive.
     *
     * @var array<string, WeakReference<static>>
     */
    private static array $instances = [];

    /**
     * A group that owns a pool keeps itself alive. The pool registers itself as
     * a shutdown object and outlives the group anyway; were the group collected,
     * the next lookup would build a second pool and a second set of workers.
     *
     * @var array<string, static>
     */
    private static array $pinned = [];

    protected bool $enabled = true;

    protected int $parallel = self::PARALLEL;

    protected float $pollingInterval = self::POLLING_INTERVAL;

    protected int $preforkSize = self::PREFORK_SIZE;

    protected float $childProcessPollInterval = self::CHILD_PROCESS_POLL_INTERVAL;

    protected int $staleJobTimeout = self::STALE_JOB_TIMEOUT;

    protected ?Pool $pool = null;

    public function __construct(protected string $name)
    {
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function injectSettings(array $settings): void
    {
        $configuration = $settings['groups'][$this->name] ?? null;

        $this->enabled = self::isEnabled($configuration);
        if (!is_array($configuration)) {
            return;
        }

        $this->parallel = max((int) ($configuration['parallel'] ?? self::PARALLEL), 1);
        $this->pollingInterval = (float) ($configuration['pollingInterval'] ?? self::POLLING_INTERVAL);
        $this->preforkSize = max((int) ($configuration['preforkSize'] ?? self::PREFORK_SIZE), 0);
        $this->childProcessPollInterval = (float) ($configuration['childProcessPollInterval'] ?? self::CHILD_PROCESS_POLL_INTERVAL);
        $this->staleJobTimeout = max((int) ($configuration['staleJobTimeout'] ?? self::STALE_JOB_TIMEOUT), 1);
    }

    /**
     * Named groups are looked up, never constructed: a second instance of the
     * same name would carry a second pool.
     *
     * Must use "new static" - within the proxied class, "self" would refer to
     * the unproxied original and skip the settings injection.
     */
    public static function get(string $name): static
    {
        $group = self::$pinned[$name] ?? (self::$instances[$name] ?? null)?->get();

        if ($group === null) {
            /** @phpstan-ignore-next-line Flow proxies this class, so it cannot be final */
            $group = new static($name);
            if (!$group->enabled) {
                throw new InvalidArgumentException(
                    sprintf('Group name "%s" is not active', $name),
                    1790160974
                );
            }
            self::$instances[$name] = WeakReference::create($group);
        }

        return $group;
    }

    /**
     * Names of all enabled groups, in configuration order.
     *
     * This is the single place where "enabled" is decided. Callers pass their
     * own settings instead of reaching for the configuration statically.
     *
     * @param array<string, mixed> $groups Contents of "Netlogix.JobQueue.Scheduled.groups"
     * @return list<string>
     */
    public static function activeNames(array $groups): array
    {
        return array_values(array_keys(array_filter($groups, self::isEnabled(...))));
    }

    /**
     * A falsy configuration disables a group, an array says so through its
     * "enabled" key, and a truthy scalar - the historic "groupname: true" -
     * enables it with all defaults.
     */
    private static function isEnabled(mixed $configuration): bool
    {
        if (!$configuration) {
            return false;
        }
        if (!is_array($configuration)) {
            return true;
        }

        return (bool) ($configuration['enabled'] ?? true);
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

    public function getStaleJobTimeout(): int
    {
        return $this->staleJobTimeout;
    }

    /**
     * The pool is built on first access. Building it starts "preforkSize" worker
     * processes right away, which callers that only read the configuration must
     * not trigger.
     *
     * $outputResults only takes effect while the pool is being built.
     */
    public function getPool(bool $outputResults = false): Pool
    {
        if ($this->pool === null) {
            $this->pool = Pool::create(
                outputResults: $outputResults,
                preforkSize: $this->preforkSize,
                childProcessPollInterval: $this->childProcessPollInterval
            );
            self::$pinned[$this->name] = $this;
        }

        return $this->pool;
    }

    /**
     * Answers without building a pool - the poll scheduler asks this on every
     * tick, and a pool built here would defeat the lazy construction.
     */
    public function hasCapacity(): bool
    {
        return $this->countRunningJobs() < $this->parallel;
    }

    public function countRunningJobs(): int
    {
        return $this->pool?->count() ?? 0;
    }
}
