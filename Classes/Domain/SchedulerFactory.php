<?php

namespace Netlogix\JobQueue\Scheduled\Domain;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySqlPlatform;
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
        // PostgreSQL94Platform and its siblings all descend from PostgreSqlPlatform,
        // so testing the base class covers every PostgreSQL version.
        $className = match (true) {
            $platform instanceof MySqlPlatform => MySQLScheduler::class,
            $platform instanceof PostgreSqlPlatform => PostgreSQLScheduler::class,
            default => null,
        };

        if ($className !== null) {
            $instance = $this->objectManager->get($className);
            assert($instance instanceof Scheduler);

            return $instance;
        }
        throw new InvalidArgumentException("unsupported database platform " . $this->connection->getDatabasePlatform()->getName());
    }

}