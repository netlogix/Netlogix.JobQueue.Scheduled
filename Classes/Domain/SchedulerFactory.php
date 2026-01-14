<?php

namespace Netlogix\JobQueue\Scheduled\Domain;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\PostgreSQL94Platform;
use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use InvalidArgumentException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;

class SchedulerFactory
{

    #[Flow\Inject]
    protected Connection $connection;

    #[Flow\Inject]
    protected ObjectManagerInterface $objectManager;

    public function create(): Scheduler
    {
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof MySqlPlatform) {
            return $this->objectManager->get(MySQLScheduler::class);
        }
        if ($platform instanceof PostgreSqlPlatform || $platform instanceof PostgreSQL94Platform) {
            return $this->objectManager->get(PostgreSQLScheduler::class);
        }
        throw new InvalidArgumentException("unsupported database platform " . $this->connection->getDatabasePlatform()->getName());
    }

}