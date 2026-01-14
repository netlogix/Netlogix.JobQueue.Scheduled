<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Service;

use Doctrine\DBAL\Cache\QueryCacheProfile;
use Doctrine\DBAL\Connection as DBALConnection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManagerInterface;

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

    public function fetchOne(string $query, array $params = [], array $types = [])
    {
        return $this->withAutoReconnectAndRetry(function () use ($query, $params, $types) {
            return $this->dbal->fetchOne($query, $params, $types);
        });
    }

    public function executeQuery($sql, array $params = [], $types = [], ?QueryCacheProfile $qcp = null)
    {
        return $this->withAutoReconnectAndRetry(function () use ($sql, $params, $types, $qcp) {
            return $this->dbal->executeQuery($sql, $params, $types, $qcp);
        });
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
     * @template T
     * @param callable(): T $dbalInteraction
     * @return T
     */
    protected function withAutoReconnectAndRetry(callable $dbalInteraction)
    {
        try {
            return $dbalInteraction();
        } catch (ConnectionLost) {
            $this->dbal->connect();
            return $dbalInteraction();
        } catch (RetryableException) {
            return $dbalInteraction();
        }
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

    public function getDbal(): DBALConnection {
        return $this->dbal;
    }
}
