<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Introduce different groups for scheduled jobs where every group needs its own worker.
 */
final class Version20240719144459 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() != 'mysql',
            'Migration can only be executed safely on "mysql".'
        );

        $this->addSql('ALTER TABLE netlogix_jobqueue_scheduled_job ADD groupname CHAR(36) DEFAULT \'default\' NOT NULL');
        $this->addSql('CREATE INDEX idx_groupname ON netlogix_jobqueue_scheduled_job (groupname, identifier)');
        $this->addSql('CREATE INDEX idx_claimed ON netlogix_jobqueue_scheduled_job (claimed, identifier)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() != 'mysql',
            'Migration can only be executed safely on "mysql".'
        );

        $this->addSql('DROP INDEX idx_groupname ON netlogix_jobqueue_scheduled_job');
        $this->addSql('DROP INDEX idx_claimed ON netlogix_jobqueue_scheduled_job');
        $this->addSql('ALTER TABLE netlogix_jobqueue_scheduled_job DROP groupname');
    }
}
