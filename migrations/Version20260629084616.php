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
        return 'Add personal_access_token and share entities';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE personal_access_token (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, token_hash VARCHAR(64) NOT NULL, token_version INT DEFAULT 1 NOT NULL, scopes JSON NOT NULL, expires_at DATETIME DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_5017171AB3BC57DA (token_hash), INDEX IDX_5017171AA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE share (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, last_accessed_at DATETIME DEFAULT NULL, view_count INT DEFAULT 0 NOT NULL, entry_id INT NOT NULL, project_id INT NOT NULL, created_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_EF069D5AD17F50A6 (uuid), UNIQUE INDEX UNIQ_EF069D5AB3BC57DA (token_hash), INDEX IDX_EF069D5ABA364942 (entry_id), INDEX IDX_EF069D5A166D1F9C (project_id), INDEX IDX_EF069D5AB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE personal_access_token ADD CONSTRAINT FK_5017171AA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE share ADD CONSTRAINT FK_EF069D5ABA364942 FOREIGN KEY (entry_id) REFERENCES content_entry (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE share ADD CONSTRAINT FK_EF069D5A166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE share ADD CONSTRAINT FK_EF069D5AB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE personal_access_token DROP FOREIGN KEY FK_5017171AA76ED395');
        $this->addSql('ALTER TABLE share DROP FOREIGN KEY FK_EF069D5ABA364942');
        $this->addSql('ALTER TABLE share DROP FOREIGN KEY FK_EF069D5A166D1F9C');
        $this->addSql('ALTER TABLE share DROP FOREIGN KEY FK_EF069D5AB03A8386');
        $this->addSql('DROP TABLE personal_access_token');
        $this->addSql('DROP TABLE share');
    }
}
