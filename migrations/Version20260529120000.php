<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260529120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add published_at column to content_entry';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_entry ADD published_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('UPDATE content_entry SET published_at = updated_at WHERE status = \'published\' AND published_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_entry DROP published_at');
    }
}
