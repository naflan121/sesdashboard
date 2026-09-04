<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Indexes for the two hot read paths.
 *
 * The activity list orders every project's emails by timestamp DESC and filters on a date
 * range; the dashboard groups email events by day and event type over a date range. Both
 * degrade into full scans once a project has a meaningful number of rows.
 *
 * Note: no index is added for the activity search box — it uses LIKE '%term%', which no
 * B-tree can serve. That needs a FULLTEXT index and a query change.
 */
final class Version20260904120100 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Index email.timestamp and email_event(timestamp, event)';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE INDEX idx_email_timestamp ON email (timestamp)');
        $this->addSql('CREATE INDEX idx_email_event_timestamp_event ON email_event (timestamp, event)');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('DROP INDEX idx_email_timestamp ON email');
        $this->addSql('DROP INDEX idx_email_event_timestamp_event ON email_event');
    }
}
