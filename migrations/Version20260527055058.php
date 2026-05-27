<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260527055058 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les colonnes logo_dark, logo_light, icon_dark, icon_light dans app_settings (logos/icônes dual-mode)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE app_settings ADD logo_dark_file_name VARCHAR(255) DEFAULT NULL, ADD logo_light_file_name VARCHAR(255) DEFAULT NULL, ADD icon_dark_file_name VARCHAR(255) DEFAULT NULL, ADD icon_light_file_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE app_settings DROP logo_dark_file_name, DROP logo_light_file_name, DROP icon_dark_file_name, DROP icon_light_file_name');
    }
}
