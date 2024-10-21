<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add mirrored index for retrieving and updating claim statemens.
 *
 * The index "idx_for_update" helps for the claiming query statement like
 * "UPDATE SET claimed = ? WHERE groupname = ? AND claimed = 0 AND duedate <= ?".
 * This needs to order a huge chunk of jobs.
 *
 * The index "idx_for_retrieve" helps for the retrieving query statement like
 * "SELECT * FROM WHERE claimed = ? AND groupname = ?".
 * This needs to quickly drill down to a single job.
 */
final class Version20241018152838 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MariaDb1027Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MariaDb1027Platform'."
        );

        $this->addSql('CREATE INDEX idx_for_update ON netlogix_jobqueue_scheduled_job (groupname, claimed, duedate)');
        $this->addSql('CREATE INDEX idx_for_retrieve ON netlogix_jobqueue_scheduled_job (claimed, groupname)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MariaDb1027Platform,
            "Migration can only be executed safely on '\Doctrine\DBAL\Platforms\MariaDb1027Platform'."
        );

        $this->addSql('DROP INDEX idx_for_update ON netlogix_jobqueue_scheduled_job');
        $this->addSql('DROP INDEX idx_for_retrieve ON netlogix_jobqueue_scheduled_job');
    }
}
