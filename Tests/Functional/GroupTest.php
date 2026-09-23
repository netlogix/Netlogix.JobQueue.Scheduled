<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional;

use InvalidArgumentException;
use Neos\Flow\Tests\FunctionalTestCase;
use Netlogix\JobQueue\Scheduled\Domain\GroupRepository;

use function array_keys;

class GroupTest extends FunctionalTestCase
{
    private GroupRepository $groupRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $groupRepository = $this->objectManager->get(GroupRepository::class);
        assert($groupRepository instanceof GroupRepository);
        $this->groupRepository = $groupRepository;
    }

    /**
     * @test
     */
    public function A_group_configured_as_true_uses_every_default(): void
    {
        $group = $this->groupRepository->get('additional-group');

        self::assertSame('additional-group', $group->getName());
        self::assertSame(1, $group->getParallel());
        self::assertSame(0.1, $group->getPollingInterval());
        self::assertSame(0, $group->getPreforkSize());
        self::assertSame(0.1, $group->getChildProcessPollInterval());
        self::assertSame(60, $group->getStaleJobTimeout());
    }

    /**
     * @test
     */
    public function Configured_values_win_over_the_defaults(): void
    {
        $group = $this->groupRepository->get('configured-group');

        self::assertSame(3, $group->getParallel());
        self::assertSame(2.5, $group->getPollingInterval());
        self::assertSame(1, $group->getPreforkSize());
        self::assertSame(0.5, $group->getChildProcessPollInterval());
        self::assertSame(120, $group->getStaleJobTimeout());
    }

    /**
     * @test
     */
    public function Falsy_and_disabled_groups_are_not_active(): void
    {
        // A clone: "new" on a singleton proxy would replace the instance every other test gets.
        $groupRepository = clone $this->groupRepository;
        $groupRepository->injectSettings(['groups' => [
            'truthy-scalar' => true,
            'empty-array' => [],
            'null-value' => null,
            'falsy-scalar' => false,
            'without-enabled' => ['parallel' => 2],
            'enabled-true' => ['enabled' => true],
            'enabled-false' => ['enabled' => false, 'parallel' => 2],
        ]]);

        self::assertSame(
            ['truthy-scalar', 'without-enabled', 'enabled-true'],
            array_keys($groupRepository->active())
        );
    }

    /**
     * @test
     */
    public function A_group_disabled_by_a_falsy_value_cannot_be_fetched(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionCode(1790160974);

        $this->groupRepository->get('falsy-group');
    }

    /**
     * @test
     */
    public function A_group_disabled_by_its_enabled_key_cannot_be_fetched(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionCode(1790160974);

        $this->groupRepository->get('disabled-group');
    }

    /**
     * @test
     */
    public function An_unknown_group_cannot_be_fetched(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionCode(1790160974);

        $this->groupRepository->get('never-configured-group');
    }
}
