<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Platforms\MariaDb1027Platform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add "running" flag to jobs, in addition to the already existing
 * "claimed" tag. Jobs now get "claimed" as well as marked as "running"
 * when working on them starts. Re-scheduling a claimed job empties the
 * "claimed" tag but keeps the "running" flag as is. This results in a
 * "one more, please" situation but prevents the additional job from
 * being claimed again as long as the previous one is still being
 * worked on.
 */
final class Version20250806123506 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MariaDb1027Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MariaDb1027Platform'."
        );

        $this->addSql('DROP INDEX idx_for_retrieve ON netlogix_jobqueue_scheduled_job');
        $this->addSql('DROP INDEX idx_for_update ON netlogix_jobqueue_scheduled_job');
        $this->addSql('ALTER TABLE netlogix_jobqueue_scheduled_job ADD running TINYINT(1) NOT NULL');
        $this->addSql('CREATE INDEX idx_for_retrieve ON netlogix_jobqueue_scheduled_job (claimed, groupname, running)');
        $this->addSql('CREATE INDEX idx_for_update ON netlogix_jobqueue_scheduled_job (groupname, claimed, duedate, running)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MariaDb1027Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MariaDb1027Platform'."
        );

        $this->addSql('DROP INDEX idx_for_retrieve ON netlogix_jobqueue_scheduled_job');
        $this->addSql('DROP INDEX idx_for_update ON netlogix_jobqueue_scheduled_job');
        $this->addSql('ALTER TABLE netlogix_jobqueue_scheduled_job DROP running');
        $this->addSql('CREATE INDEX idx_for_retrieve ON netlogix_jobqueue_scheduled_job (claimed, groupname)');
        $this->addSql('CREATE INDEX idx_for_update ON netlogix_jobqueue_scheduled_job (groupname, claimed, duedate)');
    }
}
