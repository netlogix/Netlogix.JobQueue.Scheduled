<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial PostgresSQL migration for scheduled jobs table
 */
final class Version20260112110515 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQL100Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\PostgreSQL100Platform'."
        );

        $this->addSql('CREATE TABLE netlogix_jobqueue_scheduled_job (identifier VARCHAR(255) NOT NULL, groupname CHAR(36) DEFAULT \'default\' NOT NULL, duedate TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, activity TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, queue VARCHAR(255) NOT NULL, job BYTEA NOT NULL, incarnation INT DEFAULT 0 NOT NULL, claimed VARCHAR(36) DEFAULT \'\' NOT NULL, running SMALLINT NOT NULL, PRIMARY KEY(identifier))');
        $this->addSql('CREATE INDEX idx_groupname ON netlogix_jobqueue_scheduled_job (groupname, identifier)');
        $this->addSql('CREATE INDEX idx_claimed ON netlogix_jobqueue_scheduled_job (claimed, identifier)');
        $this->addSql('CREATE INDEX idx_for_retrieve ON netlogix_jobqueue_scheduled_job (claimed, groupname, running)');
        $this->addSql('CREATE INDEX idx_for_update ON netlogix_jobqueue_scheduled_job (groupname, claimed, duedate, running)');
        $this->addSql('COMMENT ON COLUMN netlogix_jobqueue_scheduled_job.duedate IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN netlogix_jobqueue_scheduled_job.activity IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQL100Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\PostgreSQL100Platform'."
        );

        $this->addSql('DROP TABLE netlogix_jobqueue_scheduled_job');
    }
}
