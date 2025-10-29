<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Platforms\MariaDb1027Platform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The new "activity" column contains the time the scheduler interacted with
 * the scheduled child process for the last time. It's an indicator for stale
 * children.
 */
final class Version20251028165143 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MariaDb1027Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MariaDb1027Platform'."
        );

        $this->addSql(
            sql: <<<'MySQL'
                ALTER TABLE netlogix_jobqueue_scheduled_job
                    ADD activity DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'
                MySQL
        );
        $this->addSql(
            sql: <<<'MySQL'
                UPDATE netlogix_jobqueue_scheduled_job
                    SET activity = NOW()
                    WHERE activity = '0000-00-00 00:00:00'
                MySQL
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MariaDb1027Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MariaDb1027Platform'."
        );

        $this->addSql(
            sql: <<<'MySQL'
                ALTER TABLE netlogix_jobqueue_scheduled_job
                    DROP activity
                MySQL
        );
    }
}
