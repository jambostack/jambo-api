<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618190001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add oauth_providers JSON column to app_settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_settings ADD oauth_providers JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_settings DROP oauth_providers');
    }
}
