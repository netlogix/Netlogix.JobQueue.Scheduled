<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Domain;

use InvalidArgumentException;
use Neos\Flow\Annotations as Flow;

use function is_array;
use function sprintf;

/**
 * The single place where "enabled" is decided: a falsy configuration disables a
 * group, an array says so through its "enabled" key, and a truthy scalar - the
 * historic "groupname: true" - enables it with all defaults.
 */
#[Flow\Scope('singleton')]
class GroupRepository
{
    /**
     * @var array<string, Group>
     */
    protected array $groups = [];

    /**
     * @param array{groups?: array<array-key, mixed>} $settings
     */
    public function injectSettings(array $settings): void
    {
        $this->groups = [];
        foreach ($settings['groups'] ?? [] as $name => $configuration) {
            if ($configuration && (!is_array($configuration) || ($configuration['enabled'] ?? true))) {
                $this->groups[$name] = Group::fromConfiguration((string) $name, $configuration);
            }
        }
    }

    public function get(string $name): Group
    {
        return $this->groups[$name] ?? throw new InvalidArgumentException(
            sprintf('Group name "%s" is not active', $name),
            1790160974
        );
    }

    /**
     * @return array<string, Group> All enabled groups, in configuration order
     */
    public function active(): array
    {
        return $this->groups;
    }
}
