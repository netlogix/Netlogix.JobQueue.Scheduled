<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional\Service;

use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\RetryableException;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Tests\FunctionalTestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Exercises the retry, reconnect and logging behaviour of
 * Connection::withAutoReconnectAndRetry(). The DBAL connection and the
 * throwable storage are mocked so retryable/connection-lost situations can be
 * provoked deterministically without a real database fault.
 */
class ConnectionTest extends FunctionalTestCase
{
    private DBALConnection $dbal;

    private ThrowableStorageInterface $throwableStorage;

    private TestableConnection $connection;

    public function setUp(): void
    {
        parent::setUp();
        $this->dbal = $this->createMock(DBALConnection::class);
        $this->throwableStorage = $this->createMock(ThrowableStorageInterface::class);
        $this->connection = new TestableConnection($this->dbal, $this->throwableStorage);
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } catch (\Error $error) {
            // FunctionalTestCase::tearDown() fires the "allObjectsPersisted"
            // signal, which in this platform is wired to the Neos
            // ContentRepository search indexer. Without an ElasticSearch
            // backend (as in the test environment) it throws
            // "flush() on null", and the base tearDown only catches
            // \Exception, not \Error. This test persists no domain objects, so
            // the signal is pure noise here.
            if (!str_contains($error->getMessage(), 'flush() on null')) {
                throw $error;
            }
        }
    }

    /**
     * @test
     */
    public function Successful_interactions_are_not_retried_and_nothing_is_logged(): void
    {
        $this->throwableStorage->expects(self::never())->method('logThrowable');

        $calls = 0;
        $result = $this->connection->run(function () use (&$calls) {
            $calls++;
            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(1, $calls);
    }

    /**
     * @test
     */
    public function Retryable_exceptions_are_retried_and_logged_until_success(): void
    {
        $this->throwableStorage->expects(self::once())->method('logThrowable');

        $exception = $this->retryableException();
        $calls = 0;
        $result = $this->connection->run(function () use (&$calls, $exception) {
            $calls++;
            if ($calls === 1) {
                throw $exception;
            }
            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(2, $calls);
    }

    /**
     * @test
     *
     * Verifies point 1: the final, exhausted attempt is rethrown to the caller
     * but is NOT logged again here (it would be logged 6 times otherwise).
     */
    public function Exhausted_retryable_exceptions_are_rethrown_and_the_final_attempt_is_not_logged(): void
    {
        $this->throwableStorage->expects(self::exactly(TestableConnection::MAX_RETRIES))->method('logThrowable');

        $exception = $this->retryableException();
        $calls = 0;
        try {
            $this->connection->run(function () use (&$calls, $exception) {
                $calls++;
                throw $exception;
            });
            self::fail('Expected the exhausted retryable exception to be rethrown.');
        } catch (Throwable $caught) {
            self::assertSame($exception, $caught);
        }

        self::assertSame(TestableConnection::MAX_RETRIES + 1, $calls);
    }

    /**
     * @test
     *
     * Verifies point 2: a lost connection is fully re-established (close() then
     * connect()) before the next attempt.
     */
    public function Connection_lost_triggers_close_and_connect_before_the_next_attempt(): void
    {
        $this->dbal->expects(self::once())->method('close');
        $this->dbal->expects(self::once())->method('connect');
        $this->throwableStorage->expects(self::once())->method('logThrowable');

        $exception = $this->connectionLostException();
        $calls = 0;
        $result = $this->connection->run(function () use (&$calls, $exception) {
            $calls++;
            if ($calls === 1) {
                throw $exception;
            }
            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(2, $calls);
    }

    /**
     * @test
     */
    public function Log_context_is_merged_into_the_logged_additional_data(): void
    {
        $captured = null;
        $this->throwableStorage
            ->expects(self::once())
            ->method('logThrowable')
            ->willReturnCallback(function (Throwable $throwable, array $additionalData) use (&$captured) {
                $captured = $additionalData;
                return '';
            });

        $exception = $this->retryableException();
        $calls = 0;
        $this->connection->run(
            function () use (&$calls, $exception) {
                $calls++;
                if ($calls === 1) {
                    throw $exception;
                }
                return 'ok';
            },
            fn (Throwable $throwable, int $incarnation) => ['step' => 'claim', 'groupName' => 'some-group']
        );

        self::assertSame(0, $captured['incarnation']);
        self::assertSame('claim', $captured['step']);
        self::assertSame('some-group', $captured['groupName']);
    }

    /**
     * @test
     */
    public function Non_retryable_exceptions_are_rethrown_immediately_without_logging(): void
    {
        $this->throwableStorage->expects(self::never())->method('logThrowable');
        $this->dbal->expects(self::never())->method('close');

        $exception = new RuntimeException('not retryable');
        $calls = 0;
        try {
            $this->connection->run(function () use (&$calls, $exception) {
                $calls++;
                throw $exception;
            });
            self::fail('Expected the non-retryable exception to be rethrown.');
        } catch (RuntimeException $caught) {
            self::assertSame($exception, $caught);
        }

        self::assertSame(1, $calls);
    }

    private function retryableException(): RetryableException
    {
        return new class ('retryable') extends RuntimeException implements RetryableException {
        };
    }

    private function connectionLostException(): ConnectionLost
    {
        // ConnectionLost is final and its constructor expects a driver
        // exception, so build a bare instance for the instanceof check.
        return (new ReflectionClass(ConnectionLost::class))->newInstanceWithoutConstructor();
    }
}
