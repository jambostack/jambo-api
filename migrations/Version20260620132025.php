<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260620132025 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout des colonnes preview_url, preview_mode, preview_enabled à la table project';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD preview_url VARCHAR(512) DEFAULT NULL, ADD preview_mode VARCHAR(20) NOT NULL, ADD preview_enabled TINYINT(1) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project DROP preview_url, DROP preview_mode, DROP preview_enabled');
    }
}
