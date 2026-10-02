<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Service;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\ORM\EntityManagerInterface;
use Neos\Flow\Log\ThrowableStorageInterface;
use Netlogix\Retry\Retry;
use Throwable;

/**
 * This connection uses the same database credentials as the FLOW
 * entity manager but create a new connection instance.
 *
 * All SQL queries issued by the scheduler are meant to be atomic.
 * Having them buried within application transactions hinders
 * the synchronization of multiple parallel scheduler instances.
 *
 * Since there can be some time between one scheduler call and another
 * one, having to reconnect is to be expected.
 */
class Connection
{
    /**
     * @var DBALConnection
     */
    protected $dbal;

    /**
     * @var ThrowableStorageInterface
     */
    protected ThrowableStorageInterface $throwableStorage;

    /**
     * @see http://backoffcalculator.com/?attempts=5&rate=1&interval=0.5
     */
    protected float $retryInterval = 0.5;

    protected int $maxRetries = 5;

    public function injectThrowableStorage(ThrowableStorageInterface $throwableStorage): void
    {
        $this->throwableStorage = $throwableStorage;
    }

    /**
     * Use the same database credentials as the entity manager but create
     * a new connection. All SQL queries issued by the scheduler are meant
     * to be atomic. Having the buried within application transactions hinders
     * the synchronization of multiple parallel scheduler instances.
     */
    public function injectEntityManager(EntityManagerInterface $entityManager): void
    {
        $this->dbal = clone $entityManager->getConnection();
        $this->dbal->close();
        $this->dbal->setAutoCommit(true);
        $this->dbal->connect();
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, int|string> $types
     */
    public function fetchOne(string $query, array $params = [], array $types = [], ?callable $logContext = null): mixed
    {
        return $this->withAutoReconnectAndRetry(function () use ($query, $params, $types) {
            return $this->dbal->fetchOne($query, $params, $types);
        }, logContext: $logContext);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, int|string> $types
     */
    public function fetchOneReadUncommited(
        string $query,
        array $params = [],
        array $types = [],
        ?callable $logContext = null
    ): mixed {
        return $this->withAutoReconnectAndRetry(dbalInteraction: function () use ($query, $params, $types) {
            $previous = $this->dbal->getTransactionIsolation();
            try {
                $this->dbal->setTransactionIsolation(TransactionIsolationLevel::READ_UNCOMMITTED);
                return $this->dbal->transactional(function () use ($query, $params, $types) {
                    return $this->dbal->fetchOne($query, $params, $types);
                });
            } finally {
                $this->dbal->setTransactionIsolation($previous);
            }
        }, logContext: $logContext);
    }

    /**
     * @param string $sql
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     * @param QueryCacheProfile|null $qcp
     * @param null|callable(Throwable $throwable, int $incarnation): array<string, mixed> $logContext
     * @return mixed
     */
    public function executeQuery(
        string $sql,
        array $params = [],
        array $types = [],
        ?QueryCacheProfile $qcp = null,
        ?callable $logContext = null
    ) {
        return $this->withAutoReconnectAndRetry(function () use ($sql, $params, $types, $qcp) {
            return $this->dbal->executeQuery($sql, $params, $types, $qcp);
        }, logContext: $logContext);
    }

    public function ping(): void
    {
        $this->dbal->fetchOne($this->dbal->getDatabasePlatform()->getDummySelectSQL());
    }

    /**
     * Try and retry an SQL query in case of connection timeouts or retryable exceptions.
     * This avoids multiple PING requests in rapid succession. Since all query performed
     * are meant to be atomic anyway, there should be no lost data and no data
     * duplication.
     *
     * RetryableExceptions (deadlocks, lock wait timeouts, …) and lost
     * connections are retried with exponential backoff, and every failure that
     * is followed by another attempt is logged. The final, exhausted failure is
     * rethrown to the caller instead (and logged there). The optional
     * $logContext callable may enrich the data that is logged for every
     * retried throwable, e.g. to add the current step, claim or group name.
     *
     * @template T
     * @param callable(): T $dbalInteraction
     * @param null|callable(Throwable $throwable, int $incarnation): array<string, mixed> $logContext
     * @return T
     */
    protected function withAutoReconnectAndRetry(callable $dbalInteraction, ?callable $logContext = null)
    {
        $maxRetries = $this->maxRetries;

        return (new Retry())
            ->withExponentialBackoff(retryInterval: $this->retryInterval, maxRetries: $maxRetries)
            ->onExceptionsOfType(RetryableException::class, ConnectionLost::class)
            ->onError(function (Throwable $throwable, int $incarnation, bool $shouldConsider) use ($logContext, $maxRetries) {
                if (!$shouldConsider) {
                    return;
                }
                if ($incarnation >= $maxRetries) {
                    // The retry budget is exhausted, so this throwable will be
                    // rethrown to the caller and logged upstream. There is no
                    // next attempt to prepare or log for.
                    return;
                }
                if ($throwable instanceof ConnectionLost) {
                    // Force a fresh connection before the next attempt. A bare
                    // connect() is a no-op while DBAL still holds the stale
                    // handle, so close() first.
                    $this->dbal->close();
                    $this->dbal->connect();
                }
                $additionalData = ['incarnation' => $incarnation];
                if ($logContext !== null) {
                    $additionalData = [...$additionalData, ...$logContext($throwable, $incarnation)];
                }
                $this->throwableStorage->logThrowable($throwable, $additionalData);
            })
            ->task($dbalInteraction);
    }

    /**
     * Try and retry an SQL query in case of connection timeouts. This avoids
     * multiple PING requests in rapid succession. Since all query performed
     * are meant to be atomic anyway, there should be no lost data and no data
     * duplication.
     *
     * @template T
     * @param callable(): T $dbalInteraction
     * @return T
     * @deprecated Use withAutoReconnectAndRetry instead. Will be removed at some point.
     */
    protected function withAutoReconnect(callable $dbalInteraction)
    {
        return $this->withAutoReconnectAndRetry($dbalInteraction);
    }

    public function getDbal(): DBALConnection
    {
        return $this->dbal;
    }
}
