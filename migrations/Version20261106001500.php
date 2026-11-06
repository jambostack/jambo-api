<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106001500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_quarantined and quarantine_reason columns to media table for asset security and quarantine control';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media ADD is_quarantined TINYINT(1) DEFAULT 0 NOT NULL, ADD quarantine_reason VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP is_quarantined, DROP quarantine_reason');
    }
}
