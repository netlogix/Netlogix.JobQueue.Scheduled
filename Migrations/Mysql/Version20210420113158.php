<?php

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schedule Flowpack JobQueue jobs
 */
class Version20210420113158 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != 'mysql',
            'Migration can only be executed safely on "mysql".');

        $this->addSql(<<<'MySQL'
CREATE TABLE netlogix_jobqueue_scheduled_job
(
    identifier VARCHAR(255) NOT NULL,
    duedate    DATETIME     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
    queue      VARCHAR(255) NOT NULL,
    job        LONGTEXT     NOT NULL COMMENT '(DC2Type:object)',
    PRIMARY KEY (identifier)
) DEFAULT CHARACTER SET utf8mb4
  COLLATE `utf8mb4_unicode_ci`
  ENGINE = InnoDB
MySQL
);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() != 'mysql',
            'Migration can only be executed safely on "mysql".');

        $this->addSql('DROP TABLE netlogix_jobqueue_scheduled_job');
    }
}
