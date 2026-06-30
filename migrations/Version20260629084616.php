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
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE form (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, fields JSON NOT NULL, steps JSON DEFAULT NULL, settings JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id INT NOT NULL, INDEX IDX_5288FD4F166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE form_submission (id INT AUTO_INCREMENT NOT NULL, data JSON NOT NULL, metadata JSON NOT NULL, is_complete TINYINT NOT NULL, is_spam TINYINT NOT NULL, is_read TINYINT NOT NULL, created_at DATETIME NOT NULL, form_id INT NOT NULL, INDEX IDX_D2C216675FF69B7D (form_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE personal_access_token (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, token_hash VARCHAR(64) NOT NULL, token_version INT DEFAULT 1 NOT NULL, scopes JSON NOT NULL, expires_at DATETIME DEFAULT NULL, last_used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_5017171AB3BC57DA (token_hash), INDEX IDX_5017171AA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE redirect (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, from_path VARCHAR(512) NOT NULL, to_path VARCHAR(512) NOT NULL, http_code INT NOT NULL, is_pattern TINYINT NOT NULL, is_enabled TINYINT NOT NULL, hits INT NOT NULL, last_hit_at DATETIME DEFAULT NULL, is_auto TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id INT NOT NULL, source_entry_id INT DEFAULT NULL, created_by_id INT NOT NULL, updated_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_C30C9E2BD17F50A6 (uuid), INDEX IDX_C30C9E2B166D1F9C (project_id), INDEX IDX_C30C9E2BF9167F90 (source_entry_id), INDEX IDX_C30C9E2BB03A8386 (created_by_id), INDEX IDX_C30C9E2B896DBBDE (updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE redirect_revision (id INT AUTO_INCREMENT NOT NULL, from_path VARCHAR(512) NOT NULL, to_path VARCHAR(512) NOT NULL, http_code INT NOT NULL, changed_at DATETIME NOT NULL, redirect_id INT NOT NULL, changed_by_id INT NOT NULL, INDEX IDX_29D1EC7FB42D874D (redirect_id), INDEX IDX_29D1EC7F828AD0A0 (changed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE seo_revision (id INT AUTO_INCREMENT NOT NULL, meta_title VARCHAR(255) DEFAULT NULL, meta_description LONGTEXT DEFAULT NULL, slug VARCHAR(255) NOT NULL, canonical_url VARCHAR(512) DEFAULT NULL, og_image VARCHAR(36) DEFAULT NULL, seo_score INT DEFAULT NULL, changed_at DATETIME NOT NULL, entry_id INT NOT NULL, changed_by_id INT DEFAULT NULL, INDEX IDX_8CC4B4EEBA364942 (entry_id), INDEX IDX_8CC4B4EE828AD0A0 (changed_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE share (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, last_accessed_at DATETIME DEFAULT NULL, view_count INT DEFAULT 0 NOT NULL, entry_id INT NOT NULL, project_id INT NOT NULL, created_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_EF069D5AD17F50A6 (uuid), UNIQUE INDEX UNIQ_EF069D5AB3BC57DA (token_hash), INDEX IDX_EF069D5ABA364942 (entry_id), INDEX IDX_EF069D5A166D1F9C (project_id), INDEX IDX_EF069D5AB03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE form ADD CONSTRAINT FK_5288FD4F166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('ALTER TABLE form_submission ADD CONSTRAINT FK_D2C216675FF69B7D FOREIGN KEY (form_id) REFERENCES form (id)');
        $this->addSql('ALTER TABLE personal_access_token ADD CONSTRAINT FK_5017171AA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE redirect ADD CONSTRAINT FK_C30C9E2B166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('ALTER TABLE redirect ADD CONSTRAINT FK_C30C9E2BF9167F90 FOREIGN KEY (source_entry_id) REFERENCES content_entry (id)');
        $this->addSql('ALTER TABLE redirect ADD CONSTRAINT FK_C30C9E2BB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE redirect ADD CONSTRAINT FK_C30C9E2B896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE redirect_revision ADD CONSTRAINT FK_29D1EC7FB42D874D FOREIGN KEY (redirect_id) REFERENCES redirect (id)');
        $this->addSql('ALTER TABLE redirect_revision ADD CONSTRAINT FK_29D1EC7F828AD0A0 FOREIGN KEY (changed_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE seo_revision ADD CONSTRAINT FK_8CC4B4EEBA364942 FOREIGN KEY (entry_id) REFERENCES content_entry (id)');
        $this->addSql('ALTER TABLE seo_revision ADD CONSTRAINT FK_8CC4B4EE828AD0A0 FOREIGN KEY (changed_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE share ADD CONSTRAINT FK_EF069D5ABA364942 FOREIGN KEY (entry_id) REFERENCES content_entry (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE share ADD CONSTRAINT FK_EF069D5A166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE share ADD CONSTRAINT FK_EF069D5AB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE form DROP FOREIGN KEY FK_5288FD4F166D1F9C');
        $this->addSql('ALTER TABLE form_submission DROP FOREIGN KEY FK_D2C216675FF69B7D');
        $this->addSql('ALTER TABLE personal_access_token DROP FOREIGN KEY FK_5017171AA76ED395');
        $this->addSql('ALTER TABLE redirect DROP FOREIGN KEY FK_C30C9E2B166D1F9C');
        $this->addSql('ALTER TABLE redirect DROP FOREIGN KEY FK_C30C9E2BF9167F90');
        $this->addSql('ALTER TABLE redirect DROP FOREIGN KEY FK_C30C9E2BB03A8386');
        $this->addSql('ALTER TABLE redirect DROP FOREIGN KEY FK_C30C9E2B896DBBDE');
        $this->addSql('ALTER TABLE redirect_revision DROP FOREIGN KEY FK_29D1EC7FB42D874D');
        $this->addSql('ALTER TABLE redirect_revision DROP FOREIGN KEY FK_29D1EC7F828AD0A0');
        $this->addSql('ALTER TABLE seo_revision DROP FOREIGN KEY FK_8CC4B4EEBA364942');
        $this->addSql('ALTER TABLE seo_revision DROP FOREIGN KEY FK_8CC4B4EE828AD0A0');
        $this->addSql('ALTER TABLE share DROP FOREIGN KEY FK_EF069D5ABA364942');
        $this->addSql('ALTER TABLE share DROP FOREIGN KEY FK_EF069D5A166D1F9C');
        $this->addSql('ALTER TABLE share DROP FOREIGN KEY FK_EF069D5AB03A8386');
        $this->addSql('DROP TABLE form');
        $this->addSql('DROP TABLE form_submission');
        $this->addSql('DROP TABLE personal_access_token');
        $this->addSql('DROP TABLE redirect');
        $this->addSql('DROP TABLE redirect_revision');
        $this->addSql('DROP TABLE seo_revision');
        $this->addSql('DROP TABLE share');
    }
}
