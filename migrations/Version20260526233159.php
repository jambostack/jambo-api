<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260526233159 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '[no-op] Duplicate of Version20260526233158 — index already created by that migration';
    }

    public function up(Schema $schema): void
    {
        // Duplicate of Version20260526233158 — no-op to avoid duplicate index on fresh install
    }

    public function down(Schema $schema): void
    {
        // no-op
    }
}
