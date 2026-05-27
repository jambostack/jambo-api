<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260526195319 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE end_user (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, name VARCHAR(255) DEFAULT NULL, avatar_url VARCHAR(500) DEFAULT NULL, status VARCHAR(20) NOT NULL, custom_fields JSON DEFAULT NULL, email_verified_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, token_version INT NOT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_A3515A0DD17F50A6 (uuid), INDEX IDX_A3515A0D166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE end_user ADD CONSTRAINT FK_A3515A0D166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE end_user DROP FOREIGN KEY FK_A3515A0D166D1F9C');
        $this->addSql('DROP TABLE end_user');
    }
}
