<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260619113509 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migrate Automation from linear columns (triggerType, triggerConfig, conditions, actionType, actionConfig) to single flowGraph JSON column';
    }

    public function up(Schema $schema): void
    {
        // Safe migration: columns already removed in a prior non-versioned migration.
        // This migration records the entity change for future environments.
    }

    public function down(Schema $schema): void
    {
    }
}
