<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Store the SES message tags (configuration set, source IP, from domain) on the email.
 */
final class Version20260904120000 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Add SES tag columns to email';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE email
            ADD configuration_set VARCHAR(255) DEFAULT NULL,
            ADD source_ip VARCHAR(45) DEFAULT NULL,
            ADD from_domain VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE email
            DROP configuration_set,
            DROP source_ip,
            DROP from_domain');
    }
}
