<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional\Command;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Exception as DatabaseException;
use LogicException;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Tests\FunctionalTestCase;
use Netlogix\JobQueue\Scheduled\Command\SchedulerCommandController;
use Netlogix\JobQueue\Scheduled\Domain\Group;
use Netlogix\JobQueue\Scheduled\Domain\Model\ScheduledJob;
use Netlogix\JobQueue\Scheduled\Domain\Scheduler;
use Netlogix\JobQueue\Scheduled\Service\Connection;
use Netlogix\JobQueue\Scheduled\Tests\Fixture\JobQueueJob;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use ReflectionMethod;
use Throwable;

class DatabaseFailureTest extends FunctionalTestCase
{
    private LoopInterface $loop;

    private ScheduledJob $job;

    public function setUp(): void
    {
        parent::setUp();
        $this->loop = new StreamSelectLoop();
        $this->job = ScheduledJob::createNew(
            job: JobQueueJob::first(),
            queue: 'some-queue-name',
            duedate: new DateTimeImmutable(),
            groupName: Scheduler::DEFAULT_GROUP_NAME
        );
    }

    /**
     * @test
     */
    public function A_job_whose_activity_cannot_be_written_beyond_the_stale_timeout_gets_terminated(): void
    {
        $process = $this->runningProcess();
        $controller = $this->controller(
            activity: fn () => throw new DatabaseException('database gone')
        );

        $this->keepActive($controller, $process, staleJobTimeout: 2);
        $process->on('exit', fn () => $this->loop->stop());
        $this->loop->addTimer(10, fn () => $this->loop->stop());
        $this->loop->run();

        self::assertFalse($process->isRunning());
        self::assertTrue($process->isTerminated());
    }

    /**
     * @test
     */
    public function A_job_whose_activity_gets_written_keeps_running(): void
    {
        $process = $this->runningProcess();
        $controller = $this->controller();

        $this->keepActive($controller, $process, staleJobTimeout: 2);
        $this->loop->addTimer(3.5, fn () => $this->loop->stop());
        $this->loop->run();

        self::assertTrue($process->isRunning());
        $process->terminate();
    }

    /**
     * @test
     */
    public function A_failed_release_gets_repeated_until_it_succeeds(): void
    {
        $releases = 0;
        $controller = $this->controller(
            release: function () use (&$releases) {
                if (++$releases === 1) {
                    throw new DatabaseException('database gone');
                }
                $this->loop->stop();
            }
        );

        (new ReflectionMethod($controller, 'releaseEventually'))->invoke($controller, $this->loop, $this->job);
        $this->loop->addTimer(5, fn () => $this->loop->stop());
        $this->loop->run();

        self::assertSame(2, $releases);
    }

    /**
     * @test
     */
    public function Errors_other_than_database_errors_still_escape(): void
    {
        $controller = $this->controller();
        $guarded = (new ReflectionMethod($controller, 'survivingDatabaseErrors'))
            ->invoke($controller, fn () => throw new LogicException('bug'));

        $this->expectException(LogicException::class);
        $guarded();
    }

    private function runningProcess(): Process
    {
        $process = new Process('exec sleep 30');
        $process->start($this->loop);

        return $process;
    }

    private function keepActive(SchedulerCommandController $controller, Process $process, int $staleJobTimeout): void
    {
        (new ReflectionMethod($controller, 'keepActive'))->invoke(
            $controller,
            $this->loop,
            $process,
            $this->job,
            new Group(name: Scheduler::DEFAULT_GROUP_NAME, staleJobTimeout: $staleJobTimeout),
            hrtime(true)
        );
    }

    private function controller(?Closure $activity = null, ?Closure $release = null): SchedulerCommandController
    {
        $controller = $this->objectManager->get(SchedulerCommandController::class);
        assert($controller instanceof SchedulerCommandController);
        $controller->injectScheduler(new class ($activity, $release) implements Scheduler {
            public function __construct(private ?Closure $activity, private ?Closure $release)
            {
            }

            public function schedule(ScheduledJob $job, ScheduledJob ...$jobs): void
            {
            }

            public function isScheduled(string $groupName, string $identifier): bool
            {
                return false;
            }

            public function ping(): void
            {
            }

            public function next(string $groupName, string ...$furtherGroupNames): ?ScheduledJob
            {
                return null;
            }

            public function release(ScheduledJob $job): void
            {
                $this->release?->__invoke();
            }

            public function fail(ScheduledJob $job, string $reason): void
            {
            }

            public function activity(ScheduledJob $job): void
            {
                $this->activity?->__invoke();
            }

            public function resetStaleJobs(string $groupName): int
            {
                return 0;
            }

            public function getConnection(): Connection
            {
                throw new LogicException('not needed');
            }
        });
        $controller->injectThrowableStorageInterface(new class implements ThrowableStorageInterface {
            /**
             * @param array<string, mixed> $options
             */
            public static function createWithOptions(array $options): ThrowableStorageInterface
            {
                return new self();
            }

            /**
             * @param array<mixed> $additionalData
             */
            public function logThrowable(Throwable $throwable, array $additionalData = [])
            {
                return '';
            }

            public function setRequestInformationRenderer(Closure $requestInformationRenderer)
            {
                return $this;
            }

            public function setBacktraceRenderer(Closure $backtraceRenderer)
            {
                return $this;
            }
        });

        return $controller;
    }
}
