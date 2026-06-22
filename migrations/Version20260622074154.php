<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260622074154 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée la table personal_access_token (Admin API — PAT user-scoped)';
    }

    public function up(Schema $schema): void
    {
        // Limité volontairement à personal_access_token : les autres diffs
        // (index notification/user) sont du drift de migrations non enregistrées,
        // hors périmètre de cette feature.
        $this->addSql('CREATE TABLE personal_access_token (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, token_hash VARCHAR(64) NOT NULL, token_version INT DEFAULT 1 NOT NULL, scopes JSON NOT NULL, expires_at DATETIME DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_5017171AB3BC57DA (token_hash), INDEX IDX_5017171AA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE personal_access_token ADD CONSTRAINT FK_5017171AA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE personal_access_token DROP FOREIGN KEY FK_5017171AA76ED395');
        $this->addSql('DROP TABLE personal_access_token');
    }
}
