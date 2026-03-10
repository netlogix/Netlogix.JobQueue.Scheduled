<?php

namespace Netlogix\JobQueue\Scheduled\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\PostgreSQL94Platform;
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
        if ($platform instanceof MySqlPlatform) {
            return $this->objectManager->get(MySQLJobStatusService::class);
        }
        if ($platform instanceof PostgreSqlPlatform || $platform instanceof PostgreSQL94Platform) {
            return $this->objectManager->get(PostgreSQLJobStatusService::class);
        }
        throw new \InvalidArgumentException("unsupported database platform " . $this->connection->getDatabasePlatform()->getName());
    }
}
