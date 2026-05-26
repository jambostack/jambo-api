<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260523122705 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE api_token (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, token_hash VARCHAR(64) NOT NULL, abilities JSON NOT NULL, last_used_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_7BA2F5EBB3BC57DA (token_hash), INDEX IDX_7BA2F5EB166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE asset_metadata (id INT AUTO_INCREMENT NOT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, media_id INT NOT NULL, UNIQUE INDEX UNIQ_CD7F2E94EA9FDD75 (media_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE collection_template (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, is_singleton TINYINT NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE collection_template_field (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, options JSON DEFAULT NULL, `order` INT NOT NULL, is_required TINYINT NOT NULL, collection_template_id INT NOT NULL, INDEX IDX_6323685C99709339 (collection_template_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE column_setting (id INT AUTO_INCREMENT NOT NULL, visible_columns JSON NOT NULL, user_id INT NOT NULL, collection_id INT NOT NULL, INDEX IDX_940F501CA76ED395 (user_id), INDEX IDX_940F501C514956FD (collection_id), UNIQUE INDEX UNIQ_940F501CA76ED395514956FD (user_id, collection_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_media_relation (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, content_field_value_id INT NOT NULL, media_id INT NOT NULL, INDEX IDX_703932F45F3C6BB (content_field_value_id), INDEX IDX_703932FEA9FDD75 (media_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_relation_field_relation (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, content_field_value_id INT NOT NULL, related_entry_id INT NOT NULL, INDEX IDX_9C2BB72645F3C6BB (content_field_value_id), INDEX IDX_9C2BB7261CB507EF (related_entry_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE media (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, file_name VARCHAR(255) DEFAULT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(100) DEFAULT NULL, file_size INT DEFAULT NULL, alt VARCHAR(255) DEFAULT NULL, caption VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, project_id INT NOT NULL, created_by_id INT DEFAULT NULL, updated_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_6A2CA10CD17F50A6 (uuid), INDEX IDX_6A2CA10C166D1F9C (project_id), INDEX IDX_6A2CA10CB03A8386 (created_by_id), INDEX IDX_6A2CA10C896DBBDE (updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE password_reset_token (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, token VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_6B7BA4B65F37A13B (token), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `permission` (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, label VARCHAR(255) NOT NULL, `group` VARCHAR(50) NOT NULL, UNIQUE INDEX UNIQ_E04992AA5E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project_user (project_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_B4021E51166D1F9C (project_id), INDEX IDX_B4021E51A76ED395 (user_id), PRIMARY KEY (project_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project_template (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, structure JSON NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `role` (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, label VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_57698A6A5E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE role_permissions (role_id INT NOT NULL, permission_id INT NOT NULL, INDEX IDX_1FBA94E6D60322AC (role_id), INDEX IDX_1FBA94E6FED90CCA (permission_id), PRIMARY KEY (role_id, permission_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_roles (user_id INT NOT NULL, role_id INT NOT NULL, INDEX IDX_54FCD59FA76ED395 (user_id), INDEX IDX_54FCD59FD60322AC (role_id), PRIMARY KEY (user_id, role_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE webhook (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, url VARCHAR(500) NOT NULL, events JSON NOT NULL, secret VARCHAR(64) DEFAULT NULL, is_active TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_8A741756D17F50A6 (uuid), INDEX IDX_8A741756166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE webhook_collections (webhook_id INT NOT NULL, collection_id INT NOT NULL, INDEX IDX_D5CAE0875C9BA60B (webhook_id), INDEX IDX_D5CAE087514956FD (collection_id), PRIMARY KEY (webhook_id, collection_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE webhook_log (id INT AUTO_INCREMENT NOT NULL, event VARCHAR(50) NOT NULL, status_code INT DEFAULT NULL, request_payload LONGTEXT DEFAULT NULL, response_body LONGTEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, error_message VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, webhook_id INT NOT NULL, INDEX IDX_736542785C9BA60B (webhook_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE api_token ADD CONSTRAINT FK_7BA2F5EB166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE asset_metadata ADD CONSTRAINT FK_CD7F2E94EA9FDD75 FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE collection_template_field ADD CONSTRAINT FK_6323685C99709339 FOREIGN KEY (collection_template_id) REFERENCES collection_template (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE column_setting ADD CONSTRAINT FK_940F501CA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE column_setting ADD CONSTRAINT FK_940F501C514956FD FOREIGN KEY (collection_id) REFERENCES `collection` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_media_relation ADD CONSTRAINT FK_703932F45F3C6BB FOREIGN KEY (content_field_value_id) REFERENCES content_field_value (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_media_relation ADD CONSTRAINT FK_703932FEA9FDD75 FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_relation_field_relation ADD CONSTRAINT FK_9C2BB72645F3C6BB FOREIGN KEY (content_field_value_id) REFERENCES content_field_value (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_relation_field_relation ADD CONSTRAINT FK_9C2BB7261CB507EF FOREIGN KEY (related_entry_id) REFERENCES content_entry (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE media ADD CONSTRAINT FK_6A2CA10C166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('ALTER TABLE media ADD CONSTRAINT FK_6A2CA10CB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE media ADD CONSTRAINT FK_6A2CA10C896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE project_user ADD CONSTRAINT FK_B4021E51166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_user ADD CONSTRAINT FK_B4021E51A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE role_permissions ADD CONSTRAINT FK_1FBA94E6D60322AC FOREIGN KEY (role_id) REFERENCES `role` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE role_permissions ADD CONSTRAINT FK_1FBA94E6FED90CCA FOREIGN KEY (permission_id) REFERENCES `permission` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_54FCD59FA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_54FCD59FD60322AC FOREIGN KEY (role_id) REFERENCES `role` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE webhook ADD CONSTRAINT FK_8A741756166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE webhook_collections ADD CONSTRAINT FK_D5CAE0875C9BA60B FOREIGN KEY (webhook_id) REFERENCES webhook (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE webhook_collections ADD CONSTRAINT FK_D5CAE087514956FD FOREIGN KEY (collection_id) REFERENCES `collection` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE webhook_log ADD CONSTRAINT FK_736542785C9BA60B FOREIGN KEY (webhook_id) REFERENCES webhook (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE collection ADD deleted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE content_entry ADD created_at DATETIME NOT NULL, ADD updated_at DATETIME NOT NULL, ADD deleted_at DATETIME DEFAULT NULL, ADD created_by_id INT DEFAULT NULL, ADD updated_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE content_entry ADD CONSTRAINT FK_C0E2C9A2B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE content_entry ADD CONSTRAINT FK_C0E2C9A2896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_C0E2C9A2B03A8386 ON content_entry (created_by_id)');
        $this->addSql('CREATE INDEX IDX_C0E2C9A2896DBBDE ON content_entry (updated_by_id)');
        $this->addSql('ALTER TABLE field ADD deleted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD created_at DATETIME NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_token DROP FOREIGN KEY FK_7BA2F5EB166D1F9C');
        $this->addSql('ALTER TABLE asset_metadata DROP FOREIGN KEY FK_CD7F2E94EA9FDD75');
        $this->addSql('ALTER TABLE collection_template_field DROP FOREIGN KEY FK_6323685C99709339');
        $this->addSql('ALTER TABLE column_setting DROP FOREIGN KEY FK_940F501CA76ED395');
        $this->addSql('ALTER TABLE column_setting DROP FOREIGN KEY FK_940F501C514956FD');
        $this->addSql('ALTER TABLE content_media_relation DROP FOREIGN KEY FK_703932F45F3C6BB');
        $this->addSql('ALTER TABLE content_media_relation DROP FOREIGN KEY FK_703932FEA9FDD75');
        $this->addSql('ALTER TABLE content_relation_field_relation DROP FOREIGN KEY FK_9C2BB72645F3C6BB');
        $this->addSql('ALTER TABLE content_relation_field_relation DROP FOREIGN KEY FK_9C2BB7261CB507EF');
        $this->addSql('ALTER TABLE media DROP FOREIGN KEY FK_6A2CA10C166D1F9C');
        $this->addSql('ALTER TABLE media DROP FOREIGN KEY FK_6A2CA10CB03A8386');
        $this->addSql('ALTER TABLE media DROP FOREIGN KEY FK_6A2CA10C896DBBDE');
        $this->addSql('ALTER TABLE project_user DROP FOREIGN KEY FK_B4021E51166D1F9C');
        $this->addSql('ALTER TABLE project_user DROP FOREIGN KEY FK_B4021E51A76ED395');
        $this->addSql('ALTER TABLE role_permissions DROP FOREIGN KEY FK_1FBA94E6D60322AC');
        $this->addSql('ALTER TABLE role_permissions DROP FOREIGN KEY FK_1FBA94E6FED90CCA');
        $this->addSql('ALTER TABLE user_roles DROP FOREIGN KEY FK_54FCD59FA76ED395');
        $this->addSql('ALTER TABLE user_roles DROP FOREIGN KEY FK_54FCD59FD60322AC');
        $this->addSql('ALTER TABLE webhook DROP FOREIGN KEY FK_8A741756166D1F9C');
        $this->addSql('ALTER TABLE webhook_collections DROP FOREIGN KEY FK_D5CAE0875C9BA60B');
        $this->addSql('ALTER TABLE webhook_collections DROP FOREIGN KEY FK_D5CAE087514956FD');
        $this->addSql('ALTER TABLE webhook_log DROP FOREIGN KEY FK_736542785C9BA60B');
        $this->addSql('DROP TABLE api_token');
        $this->addSql('DROP TABLE asset_metadata');
        $this->addSql('DROP TABLE collection_template');
        $this->addSql('DROP TABLE collection_template_field');
        $this->addSql('DROP TABLE column_setting');
        $this->addSql('DROP TABLE content_media_relation');
        $this->addSql('DROP TABLE content_relation_field_relation');
        $this->addSql('DROP TABLE media');
        $this->addSql('DROP TABLE password_reset_token');
        $this->addSql('DROP TABLE `permission`');
        $this->addSql('DROP TABLE project_user');
        $this->addSql('DROP TABLE project_template');
        $this->addSql('DROP TABLE `role`');
        $this->addSql('DROP TABLE role_permissions');
        $this->addSql('DROP TABLE user_roles');
        $this->addSql('DROP TABLE webhook');
        $this->addSql('DROP TABLE webhook_collections');
        $this->addSql('DROP TABLE webhook_log');
        $this->addSql('ALTER TABLE `collection` DROP deleted_at');
        $this->addSql('ALTER TABLE content_entry DROP FOREIGN KEY FK_C0E2C9A2B03A8386');
        $this->addSql('ALTER TABLE content_entry DROP FOREIGN KEY FK_C0E2C9A2896DBBDE');
        $this->addSql('DROP INDEX IDX_C0E2C9A2B03A8386 ON content_entry');
        $this->addSql('DROP INDEX IDX_C0E2C9A2896DBBDE ON content_entry');
        $this->addSql('ALTER TABLE content_entry DROP created_at, DROP updated_at, DROP deleted_at, DROP created_by_id, DROP updated_by_id');
        $this->addSql('ALTER TABLE field DROP deleted_at');
        $this->addSql('ALTER TABLE `user` DROP created_at');
    }
}
