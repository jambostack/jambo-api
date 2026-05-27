<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260527003553 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée la table app_settings (singleton id=1 pour nom, logo et favicon de l\'application)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_settings (id INT NOT NULL, app_name VARCHAR(100) NOT NULL, logo_file_name VARCHAR(255) DEFAULT NULL, favicon_file_name VARCHAR(255) DEFAULT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE app_settings');
    }
}
