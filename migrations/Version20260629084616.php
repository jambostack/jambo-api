<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260629084616 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Consolidation migration - tables already provisioned in previous migrations';
    }

    public function up(Schema $schema): void
    {
        // Tables personal_access_token, share, form and form_submission were already created in previous migrations.
    }

    public function down(Schema $schema): void
    {
    }
}
