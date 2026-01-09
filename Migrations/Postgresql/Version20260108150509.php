<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Changes claimed column type to VARCHAR to avoid issues with Postgres string padding
 */
final class Version20260108150509 extends AbstractMigration
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

        $this->addSql('DROP INDEX path');
        $this->addSql('DROP INDEX parentpath');
        $this->addSql('CREATE INDEX path ON neos_contentrepository_domain_model_nodedata (path)');
        $this->addSql('CREATE INDEX parentpath ON neos_contentrepository_domain_model_nodedata (parentpath)');
        $this->addSql('ALTER TABLE netlogix_jobqueue_scheduled_job ALTER claimed TYPE VARCHAR(36)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQL100Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\PostgreSQL100Platform'."
        );

        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP INDEX parentpath');
        $this->addSql('DROP INDEX path');
        $this->addSql('CREATE INDEX parentpath ON neos_contentrepository_domain_model_nodedata (parentpath)');
        $this->addSql('CREATE INDEX path ON neos_contentrepository_domain_model_nodedata (path)');
        $this->addSql('ALTER TABLE netlogix_jobqueue_scheduled_job ALTER claimed TYPE CHAR(36)');
    }
}
