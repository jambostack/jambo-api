<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260529114916 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE deploy_token (id INT AUTO_INCREMENT NOT NULL, provider VARCHAR(20) NOT NULL, encrypted_token LONGTEXT NOT NULL, expires_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_29EA97A1A76ED395 (user_id), UNIQUE INDEX uniq_deploy_token_user_provider (user_id, provider), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE deploy_token ADD CONSTRAINT FK_29EA97A1A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE deploy_token DROP FOREIGN KEY FK_29EA97A1A76ED395');
        $this->addSql('DROP TABLE deploy_token');
    }
}
