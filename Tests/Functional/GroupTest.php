<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional;

use InvalidArgumentException;
use Neos\Flow\Tests\FunctionalTestCase;
use Netlogix\JobQueue\Scheduled\Domain\Group;
use ReflectionProperty;

class GroupTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function A_group_configured_as_true_uses_every_default(): void
    {
        $group = Group::get('additional-group');

        self::assertSame('additional-group', $group->getName());
        self::assertSame(1, $group->getParallel());
        self::assertSame(0.1, $group->getPollingInterval());
        self::assertSame(60, $group->getStaleJobTimeout());
    }

    /**
     * @test
     */
    public function Configured_values_win_over_the_defaults(): void
    {
        $group = Group::get('configured-group');

        self::assertSame(3, $group->getParallel());
        self::assertSame(2.5, $group->getPollingInterval());
        self::assertSame(120, $group->getStaleJobTimeout());
    }

    /**
     * @test
     */
    public function Falsy_and_disabled_groups_are_not_active(): void
    {
        $activeNames = Group::activeNames([
            'truthy-scalar' => true,
            'empty-array' => [],
            'null-value' => null,
            'falsy-scalar' => false,
            'without-enabled' => ['parallel' => 2],
            'enabled-true' => ['enabled' => true],
            'enabled-false' => ['enabled' => false, 'parallel' => 2],
        ]);

        self::assertSame(['truthy-scalar', 'without-enabled', 'enabled-true'], $activeNames);
    }

    /**
     * @test
     */
    public function A_group_disabled_by_a_falsy_value_cannot_be_fetched(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionCode(1790160974);

        Group::get('falsy-group');
    }

    /**
     * @test
     */
    public function A_group_disabled_by_its_enabled_key_cannot_be_fetched(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionCode(1790160974);

        Group::get('disabled-group');
    }

    /**
     * @test
     */
    public function An_unknown_group_cannot_be_fetched(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionCode(1790160974);

        Group::get('never-configured-group');
    }

    /**
     * @test
     */
    public function The_same_name_yields_the_same_group(): void
    {
        $group = Group::get('additional-group');

        self::assertSame($group, Group::get('additional-group'));
    }

    /**
     * @test
     */
    public function Asking_for_capacity_does_not_build_a_pool(): void
    {
        $group = Group::get('configured-group');

        self::assertTrue($group->hasCapacity());
        self::assertSame(0, $group->countRunningJobs());

        $pool = new ReflectionProperty(Group::class, 'pool');

        self::assertNull(
            $pool->getValue($group),
            'The pool must stay unbuilt, otherwise every poll tick would start prefork workers.'
        );
    }
}
