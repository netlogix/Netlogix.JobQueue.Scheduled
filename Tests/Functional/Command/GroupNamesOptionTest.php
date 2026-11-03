<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional\Command;

use Neos\Flow\Tests\FunctionalTestCase;
use Netlogix\JobQueue\Scheduled\Command\SchedulerCommandController;
use ReflectionMethod;

use function array_keys;
use function is_array;

class GroupNamesOptionTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function Comma_separated_group_names_are_split(): void
    {
        self::assertSame(
            ['configured-group', 'additional-group'],
            array_keys($this->resolveGroups(['configured-group, additional-group,']))
        );
    }

    /**
     * @test
     */
    public function A_repeated_option_names_one_group_each(): void
    {
        self::assertSame(
            ['configured-group', 'additional-group'],
            array_keys($this->resolveGroups(['configured-group', 'additional-group']))
        );
    }

    /**
     * @param list<string> $groupNames
     * @return array<string, mixed>
     */
    private function resolveGroups(array $groupNames): array
    {
        $controller = $this->objectManager->get(SchedulerCommandController::class);
        $groups = (new ReflectionMethod($controller, 'resolveGroups'))->invoke($controller, $groupNames);
        assert(is_array($groups));

        return $groups;
    }
}
