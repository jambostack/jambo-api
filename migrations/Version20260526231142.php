<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260526231142 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '[no-op] Superseded by Version20260526231154 which adds the safety DELETE before ALTER TABLE';
    }

    public function up(Schema $schema): void
    {
        // Superseded by Version20260526231154 — no-op to avoid duplicate column on fresh install
    }

    public function down(Schema $schema): void
    {
        // no-op
    }
}
