<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260617150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Workflows editoriaux : settings sur collection, assigned_to sur content_entry';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE collection ADD settings JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE content_entry ADD assigned_to_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE content_entry ADD CONSTRAINT FK_CONTENT_ENTRY_ASSIGNED_TO FOREIGN KEY (assigned_to_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_CONTENT_ENTRY_ASSIGNED_TO ON content_entry (assigned_to_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_entry DROP FOREIGN KEY FK_CONTENT_ENTRY_ASSIGNED_TO');
        $this->addSql('DROP INDEX IDX_CONTENT_ENTRY_ASSIGNED_TO ON content_entry');
        $this->addSql('ALTER TABLE content_entry DROP assigned_to_id');
        $this->addSql('ALTER TABLE collection DROP settings');
    }
}
