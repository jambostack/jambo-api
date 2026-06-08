<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260608092322 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Multi-stockage : ProjectStorageProfile, StorageRule, modifs Media/Project';
    }

    public function up(Schema $schema): void
    {
        // ── DDL (auto-généré) ─────────────────────────────────────────
        $this->addSql('CREATE TABLE project_storage_profile (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, name VARCHAR(100) NOT NULL, driver VARCHAR(20) NOT NULL, priority INT NOT NULL, enabled TINYINT NOT NULL, is_default TINYINT NOT NULL, s3_key VARCHAR(255) DEFAULT NULL, s3_secret LONGTEXT DEFAULT NULL, s3_region VARCHAR(50) DEFAULT NULL, s3_bucket VARCHAR(255) DEFAULT NULL, s3_endpoint VARCHAR(255) DEFAULT NULL, s3_use_path_style TINYINT NOT NULL, base_url VARCHAR(255) DEFAULT NULL, root_path VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_CE5AEC9DD17F50A6 (uuid), INDEX IDX_CE5AEC9D166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE storage_rule (id INT AUTO_INCREMENT NOT NULL, mime_type_pattern VARCHAR(100) DEFAULT NULL, extension VARCHAR(20) DEFAULT NULL, max_size INT DEFAULT NULL, priority INT NOT NULL, project_id INT NOT NULL, storage_profile_id INT NOT NULL, INDEX IDX_4FF567D9166D1F9C (project_id), INDEX IDX_4FF567D95CAD1436 (storage_profile_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE project_storage_profile ADD CONSTRAINT FK_CE5AEC9D166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE storage_rule ADD CONSTRAINT FK_4FF567D9166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE storage_rule ADD CONSTRAINT FK_4FF567D95CAD1436 FOREIGN KEY (storage_profile_id) REFERENCES project_storage_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE media ADD storage_paths JSON DEFAULT NULL, ADD storage_profile_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE media ADD CONSTRAINT FK_6A2CA10C5CAD1436 FOREIGN KEY (storage_profile_id) REFERENCES project_storage_profile (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_6A2CA10C5CAD1436 ON media (storage_profile_id)');
        $this->addSql('ALTER TABLE project ADD storage_strategy VARCHAR(20) NOT NULL, CHANGE disk disk VARCHAR(50) DEFAULT NULL');

        // ── Migration des données existantes ──────────────────────────
        // 1 profil local hérité par projet existant
        $this->addSql("
            INSERT INTO project_storage_profile
                (project_id, uuid, name, driver, priority, enabled, is_default, root_path, created_at, updated_at)
            SELECT
                id, UUID_TO_BIN(UUID()), 'Local (hérité)', 'local', 0, 1, 1,
                CONCAT('public/uploads/media/', id),
                NOW(), NOW()
            FROM project
        ");

        // Associer les médias existants au profil local créé
        $this->addSql("
            UPDATE media m
            INNER JOIN project_storage_profile p ON p.project_id = m.project_id
            SET m.storage_profile_id = p.id,
                m.storage_paths = JSON_OBJECT(BIN_TO_UUID(p.uuid), CONCAT('projects/', m.project_id, '/', m.file_name))
        ");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE project_storage_profile DROP FOREIGN KEY FK_CE5AEC9D166D1F9C');
        $this->addSql('ALTER TABLE storage_rule DROP FOREIGN KEY FK_4FF567D9166D1F9C');
        $this->addSql('ALTER TABLE storage_rule DROP FOREIGN KEY FK_4FF567D95CAD1436');
        $this->addSql('DROP TABLE project_storage_profile');
        $this->addSql('DROP TABLE storage_rule');
        $this->addSql('ALTER TABLE media DROP FOREIGN KEY FK_6A2CA10C5CAD1436');
        $this->addSql('DROP INDEX IDX_6A2CA10C5CAD1436 ON media');
        $this->addSql('ALTER TABLE media DROP storage_paths, DROP storage_profile_id');
        $this->addSql('ALTER TABLE project DROP storage_strategy, CHANGE disk disk VARCHAR(50) NOT NULL');
    }
}
