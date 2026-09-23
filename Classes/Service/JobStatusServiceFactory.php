<?php

namespace Netlogix\JobQueue\Scheduled\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;

use Neos\Flow\Annotations as Flow;

class JobStatusServiceFactory {

    #[Flow\Inject]
    protected Connection $connection;

    #[Flow\Inject]
    protected ObjectManagerInterface $objectManager;

    public function create(): JobStatusService
    {
        $platform = $this->connection->getDatabasePlatform();
        // PostgreSQL94Platform and its siblings all descend from PostgreSqlPlatform,
        // so testing the base class covers every PostgreSQL version.
        $className = match (true) {
            $platform instanceof MySqlPlatform => MySQLJobStatusService::class,
            $platform instanceof PostgreSqlPlatform => PostgreSQLJobStatusService::class,
            default => null,
        };

        if ($className !== null) {
            $instance = $this->objectManager->get($className);
            assert($instance instanceof JobStatusService);

            return $instance;
        }
        throw new \InvalidArgumentException("unsupported database platform " . $this->connection->getDatabasePlatform()->getName());
    }
}
