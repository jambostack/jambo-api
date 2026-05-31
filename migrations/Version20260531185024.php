<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260531185024 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE api_token (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, token_hash VARCHAR(64) NOT NULL, token_version INT DEFAULT 1 NOT NULL, abilities JSON NOT NULL, last_used_at DATETIME DEFAULT NULL, expires_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_7BA2F5EBB3BC57DA (token_hash), INDEX IDX_7BA2F5EB166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE app_settings (id INT NOT NULL, app_name VARCHAR(100) NOT NULL, logo_file_name VARCHAR(255) DEFAULT NULL, logo_dark_file_name VARCHAR(255) DEFAULT NULL, logo_light_file_name VARCHAR(255) DEFAULT NULL, icon_dark_file_name VARCHAR(255) DEFAULT NULL, icon_light_file_name VARCHAR(255) DEFAULT NULL, favicon_file_name VARCHAR(255) DEFAULT NULL, updated_at DATETIME DEFAULT NULL, ai_providers JSON DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE asset_metadata (id INT AUTO_INCREMENT NOT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, media_id INT NOT NULL, UNIQUE INDEX UNIQ_CD7F2E94EA9FDD75 (media_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE audit_log (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, tool_name VARCHAR(100) NOT NULL, input JSON DEFAULT NULL, output JSON DEFAULT NULL, status VARCHAR(20) NOT NULL, error_message LONGTEXT DEFAULT NULL, created_by VARCHAR(100) DEFAULT NULL, source VARCHAR(50) DEFAULT NULL, duration_ms INT DEFAULT NULL, created_at DATETIME NOT NULL, project_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_F6E1C0F5D17F50A6 (uuid), INDEX IDX_F6E1C0F5166D1F9C (project_id), INDEX IDX_F6E1C0F5166D1F9C8B8E8428 (project_id, created_at), INDEX IDX_F6E1C0F585613E4D (tool_name), INDEX IDX_F6E1C0F5DE12AB56 (created_by), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `collection` (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, is_singleton TINYINT NOT NULL, `order` INT NOT NULL, deleted_at DATETIME DEFAULT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_FC4D6532D17F50A6 (uuid), INDEX IDX_FC4D6532166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE collection_template (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, is_singleton TINYINT NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE collection_template_field (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, options JSON DEFAULT NULL, `order` INT NOT NULL, is_required TINYINT NOT NULL, collection_template_id INT NOT NULL, INDEX IDX_6323685C99709339 (collection_template_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE column_setting (id INT AUTO_INCREMENT NOT NULL, visible_columns JSON NOT NULL, user_id INT NOT NULL, collection_id INT NOT NULL, INDEX IDX_940F501CA76ED395 (user_id), INDEX IDX_940F501C514956FD (collection_id), UNIQUE INDEX UNIQ_940F501CA76ED395514956FD (user_id, collection_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_entry (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, locale VARCHAR(10) NOT NULL, status VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, published_at DATETIME DEFAULT NULL, project_id INT NOT NULL, collection_id INT NOT NULL, created_by_id INT DEFAULT NULL, updated_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_C0E2C9A2D17F50A6 (uuid), INDEX IDX_C0E2C9A2166D1F9C (project_id), INDEX IDX_C0E2C9A2514956FD (collection_id), INDEX IDX_C0E2C9A2B03A8386 (created_by_id), INDEX IDX_C0E2C9A2896DBBDE (updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_field_value (id INT AUTO_INCREMENT NOT NULL, field_type VARCHAR(50) NOT NULL, text_value LONGTEXT DEFAULT NULL, number_value NUMERIC(14, 6) DEFAULT NULL, boolean_value TINYINT DEFAULT NULL, date_value DATE DEFAULT NULL, datetime_value DATETIME DEFAULT NULL, json_value JSON DEFAULT NULL, content_entry_id INT NOT NULL, field_id INT NOT NULL, INDEX IDX_65FA6449E881A3AD (content_entry_id), INDEX IDX_65FA6449443707B0 (field_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_media_relation (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, content_field_value_id INT NOT NULL, media_id INT NOT NULL, INDEX IDX_703932F45F3C6BB (content_field_value_id), INDEX IDX_703932FEA9FDD75 (media_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_relation_field_relation (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, content_field_value_id INT NOT NULL, related_entry_id INT NOT NULL, INDEX IDX_9C2BB72645F3C6BB (content_field_value_id), INDEX IDX_9C2BB7261CB507EF (related_entry_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE content_version (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, snapshot JSON NOT NULL, label VARCHAR(255) DEFAULT NULL, version_number INT NOT NULL, created_at DATETIME NOT NULL, content_entry_id INT NOT NULL, created_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_60140A70D17F50A6 (uuid), INDEX IDX_60140A70E881A3AD (content_entry_id), INDEX IDX_60140A70B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE deployment (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, commit_sha VARCHAR(40) NOT NULL, branch VARCHAR(100) DEFAULT NULL, environment VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, image_ref LONGTEXT DEFAULT NULL, preview_url VARCHAR(500) DEFAULT NULL, run_url VARCHAR(500) DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, project_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_EB1255BED17F50A6 (uuid), INDEX IDX_EB1255BE166D1F9C (project_id), INDEX idx_deployment_project_started (project_id, started_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE email_log (id INT AUTO_INCREMENT NOT NULL, `to` VARCHAR(255) NOT NULL, subject VARCHAR(255) NOT NULL, sent_at DATETIME NOT NULL, error LONGTEXT DEFAULT NULL, project_id INT NOT NULL, INDEX IDX_6FB4883166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE end_user (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, name VARCHAR(255) DEFAULT NULL, avatar_url VARCHAR(500) DEFAULT NULL, status VARCHAR(20) NOT NULL, custom_fields JSON DEFAULT NULL, email_verified_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, token_version INT NOT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_A3515A0DD17F50A6 (uuid), INDEX IDX_A3515A0D166D1F9C (project_id), UNIQUE INDEX uniq_end_user_project_email (project_id, email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE end_user_field (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, options JSON DEFAULT NULL, `order` INT NOT NULL, is_required TINYINT NOT NULL, is_system TINYINT NOT NULL, project_id INT NOT NULL, INDEX IDX_B77FE68E166D1F9C (project_id), UNIQUE INDEX uniq_end_user_field_project_slug (project_id, slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE field (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, options JSON DEFAULT NULL, `order` INT NOT NULL, is_required TINYINT NOT NULL, deleted_at DATETIME DEFAULT NULL, collection_id INT NOT NULL, INDEX IDX_5BF54558514956FD (collection_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE media (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, file_name VARCHAR(255) DEFAULT NULL, original_name VARCHAR(255) DEFAULT NULL, mime_type VARCHAR(100) DEFAULT NULL, file_size INT DEFAULT NULL, alt VARCHAR(255) DEFAULT NULL, caption VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME DEFAULT NULL, project_id INT NOT NULL, created_by_id INT DEFAULT NULL, updated_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_6A2CA10CD17F50A6 (uuid), INDEX IDX_6A2CA10C166D1F9C (project_id), INDEX IDX_6A2CA10CB03A8386 (created_by_id), INDEX IDX_6A2CA10C896DBBDE (updated_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE password_reset_token (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, token VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_6B7BA4B65F37A13B (token), INDEX IDX_6B7BA4B6166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `permission` (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, label VARCHAR(255) NOT NULL, `group` VARCHAR(50) NOT NULL, UNIQUE INDEX UNIQ_E04992AA5E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, default_locale VARCHAR(10) NOT NULL, locales JSON NOT NULL, disk VARCHAR(50) NOT NULL, public_api TINYINT NOT NULL, UNIQUE INDEX UNIQ_2FB3D0EED17F50A6 (uuid), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project_mailer_settings (id INT AUTO_INCREMENT NOT NULL, host VARCHAR(255) NOT NULL, port INT NOT NULL, username VARCHAR(255) NOT NULL, encrypted_password LONGTEXT NOT NULL, encryption VARCHAR(10) NOT NULL, from_email VARCHAR(255) NOT NULL, from_name VARCHAR(255) NOT NULL, enabled TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_1A8D404C166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project_member (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, invitation_token VARCHAR(64) DEFAULT NULL, token_expires_at DATETIME DEFAULT NULL, joined_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, project_id INT NOT NULL, user_id INT DEFAULT NULL, role_id INT DEFAULT NULL, invited_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_6740113233FC351A (invitation_token), INDEX IDX_67401132166D1F9C (project_id), INDEX IDX_67401132A76ED395 (user_id), INDEX IDX_67401132D60322AC (role_id), INDEX IDX_67401132A7B4A7E3 (invited_by_id), UNIQUE INDEX uniq_project_email (project_id, email), UNIQUE INDEX uniq_project_user (project_id, user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project_template (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, structure JSON NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `role` (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, label VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_57698A6A5E237E06 (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE role_permissions (role_id INT NOT NULL, permission_id INT NOT NULL, INDEX IDX_1FBA94E6D60322AC (role_id), INDEX IDX_1FBA94E6FED90CCA (permission_id), PRIMARY KEY (role_id, permission_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE studio_chat_message (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, role VARCHAR(20) NOT NULL, content LONGTEXT NOT NULL, schema_data JSON DEFAULT NULL, created_at DATETIME NOT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_FFA3FF2FD17F50A6 (uuid), INDEX IDX_FFA3FF2F166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, locale VARCHAR(5) DEFAULT \'en\' NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_8D93D649D17F50A6 (uuid), UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_roles (user_id INT NOT NULL, role_id INT NOT NULL, INDEX IDX_54FCD59FA76ED395 (user_id), INDEX IDX_54FCD59FD60322AC (role_id), PRIMARY KEY (user_id, role_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE webhook (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, url VARCHAR(500) NOT NULL, events JSON NOT NULL, secret LONGTEXT DEFAULT NULL, is_active TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_8A741756D17F50A6 (uuid), INDEX IDX_8A741756166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE webhook_collections (webhook_id INT NOT NULL, collection_id INT NOT NULL, INDEX IDX_D5CAE0875C9BA60B (webhook_id), INDEX IDX_D5CAE087514956FD (collection_id), PRIMARY KEY (webhook_id, collection_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE webhook_log (id INT AUTO_INCREMENT NOT NULL, event VARCHAR(50) NOT NULL, status_code INT DEFAULT NULL, request_payload LONGTEXT DEFAULT NULL, response_body LONGTEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, error_message VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, webhook_id INT NOT NULL, INDEX IDX_736542785C9BA60B (webhook_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE api_token ADD CONSTRAINT FK_7BA2F5EB166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE asset_metadata ADD CONSTRAINT FK_CD7F2E94EA9FDD75 FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F5166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('ALTER TABLE `collection` ADD CONSTRAINT FK_FC4D6532166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('ALTER TABLE collection_template_field ADD CONSTRAINT FK_6323685C99709339 FOREIGN KEY (collection_template_id) REFERENCES collection_template (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE column_setting ADD CONSTRAINT FK_940F501CA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE column_setting ADD CONSTRAINT FK_940F501C514956FD FOREIGN KEY (collection_id) REFERENCES `collection` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_entry ADD CONSTRAINT FK_C0E2C9A2166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('ALTER TABLE content_entry ADD CONSTRAINT FK_C0E2C9A2514956FD FOREIGN KEY (collection_id) REFERENCES `collection` (id)');
        $this->addSql('ALTER TABLE content_entry ADD CONSTRAINT FK_C0E2C9A2B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE content_entry ADD CONSTRAINT FK_C0E2C9A2896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE content_field_value ADD CONSTRAINT FK_65FA6449E881A3AD FOREIGN KEY (content_entry_id) REFERENCES content_entry (id)');
        $this->addSql('ALTER TABLE content_field_value ADD CONSTRAINT FK_65FA6449443707B0 FOREIGN KEY (field_id) REFERENCES field (id)');
        $this->addSql('ALTER TABLE content_media_relation ADD CONSTRAINT FK_703932F45F3C6BB FOREIGN KEY (content_field_value_id) REFERENCES content_field_value (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_media_relation ADD CONSTRAINT FK_703932FEA9FDD75 FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_relation_field_relation ADD CONSTRAINT FK_9C2BB72645F3C6BB FOREIGN KEY (content_field_value_id) REFERENCES content_field_value (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_relation_field_relation ADD CONSTRAINT FK_9C2BB7261CB507EF FOREIGN KEY (related_entry_id) REFERENCES content_entry (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_version ADD CONSTRAINT FK_60140A70E881A3AD FOREIGN KEY (content_entry_id) REFERENCES content_entry (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_version ADD CONSTRAINT FK_60140A70B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE deployment ADD CONSTRAINT FK_EB1255BE166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE email_log ADD CONSTRAINT FK_6FB4883166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE end_user ADD CONSTRAINT FK_A3515A0D166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE end_user_field ADD CONSTRAINT FK_B77FE68E166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE field ADD CONSTRAINT FK_5BF54558514956FD FOREIGN KEY (collection_id) REFERENCES `collection` (id)');
        $this->addSql('ALTER TABLE media ADD CONSTRAINT FK_6A2CA10C166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('ALTER TABLE media ADD CONSTRAINT FK_6A2CA10CB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE media ADD CONSTRAINT FK_6A2CA10C896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE password_reset_token ADD CONSTRAINT FK_6B7BA4B6166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_mailer_settings ADD CONSTRAINT FK_1A8D404C166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_member ADD CONSTRAINT FK_67401132166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_member ADD CONSTRAINT FK_67401132A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_member ADD CONSTRAINT FK_67401132D60322AC FOREIGN KEY (role_id) REFERENCES `role` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_member ADD CONSTRAINT FK_67401132A7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE role_permissions ADD CONSTRAINT FK_1FBA94E6D60322AC FOREIGN KEY (role_id) REFERENCES `role` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE role_permissions ADD CONSTRAINT FK_1FBA94E6FED90CCA FOREIGN KEY (permission_id) REFERENCES `permission` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE studio_chat_message ADD CONSTRAINT FK_FFA3FF2F166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_54FCD59FA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_54FCD59FD60322AC FOREIGN KEY (role_id) REFERENCES `role` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE webhook ADD CONSTRAINT FK_8A741756166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE webhook_collections ADD CONSTRAINT FK_D5CAE0875C9BA60B FOREIGN KEY (webhook_id) REFERENCES webhook (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE webhook_collections ADD CONSTRAINT FK_D5CAE087514956FD FOREIGN KEY (collection_id) REFERENCES `collection` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE webhook_log ADD CONSTRAINT FK_736542785C9BA60B FOREIGN KEY (webhook_id) REFERENCES webhook (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_token DROP FOREIGN KEY FK_7BA2F5EB166D1F9C');
        $this->addSql('ALTER TABLE asset_metadata DROP FOREIGN KEY FK_CD7F2E94EA9FDD75');
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY FK_F6E1C0F5166D1F9C');
        $this->addSql('ALTER TABLE `collection` DROP FOREIGN KEY FK_FC4D6532166D1F9C');
        $this->addSql('ALTER TABLE collection_template_field DROP FOREIGN KEY FK_6323685C99709339');
        $this->addSql('ALTER TABLE column_setting DROP FOREIGN KEY FK_940F501CA76ED395');
        $this->addSql('ALTER TABLE column_setting DROP FOREIGN KEY FK_940F501C514956FD');
        $this->addSql('ALTER TABLE content_entry DROP FOREIGN KEY FK_C0E2C9A2166D1F9C');
        $this->addSql('ALTER TABLE content_entry DROP FOREIGN KEY FK_C0E2C9A2514956FD');
        $this->addSql('ALTER TABLE content_entry DROP FOREIGN KEY FK_C0E2C9A2B03A8386');
        $this->addSql('ALTER TABLE content_entry DROP FOREIGN KEY FK_C0E2C9A2896DBBDE');
        $this->addSql('ALTER TABLE content_field_value DROP FOREIGN KEY FK_65FA6449E881A3AD');
        $this->addSql('ALTER TABLE content_field_value DROP FOREIGN KEY FK_65FA6449443707B0');
        $this->addSql('ALTER TABLE content_media_relation DROP FOREIGN KEY FK_703932F45F3C6BB');
        $this->addSql('ALTER TABLE content_media_relation DROP FOREIGN KEY FK_703932FEA9FDD75');
        $this->addSql('ALTER TABLE content_relation_field_relation DROP FOREIGN KEY FK_9C2BB72645F3C6BB');
        $this->addSql('ALTER TABLE content_relation_field_relation DROP FOREIGN KEY FK_9C2BB7261CB507EF');
        $this->addSql('ALTER TABLE content_version DROP FOREIGN KEY FK_60140A70E881A3AD');
        $this->addSql('ALTER TABLE content_version DROP FOREIGN KEY FK_60140A70B03A8386');
        $this->addSql('ALTER TABLE deployment DROP FOREIGN KEY FK_EB1255BE166D1F9C');
        $this->addSql('ALTER TABLE email_log DROP FOREIGN KEY FK_6FB4883166D1F9C');
        $this->addSql('ALTER TABLE end_user DROP FOREIGN KEY FK_A3515A0D166D1F9C');
        $this->addSql('ALTER TABLE end_user_field DROP FOREIGN KEY FK_B77FE68E166D1F9C');
        $this->addSql('ALTER TABLE field DROP FOREIGN KEY FK_5BF54558514956FD');
        $this->addSql('ALTER TABLE media DROP FOREIGN KEY FK_6A2CA10C166D1F9C');
        $this->addSql('ALTER TABLE media DROP FOREIGN KEY FK_6A2CA10CB03A8386');
        $this->addSql('ALTER TABLE media DROP FOREIGN KEY FK_6A2CA10C896DBBDE');
        $this->addSql('ALTER TABLE password_reset_token DROP FOREIGN KEY FK_6B7BA4B6166D1F9C');
        $this->addSql('ALTER TABLE project_mailer_settings DROP FOREIGN KEY FK_1A8D404C166D1F9C');
        $this->addSql('ALTER TABLE project_member DROP FOREIGN KEY FK_67401132166D1F9C');
        $this->addSql('ALTER TABLE project_member DROP FOREIGN KEY FK_67401132A76ED395');
        $this->addSql('ALTER TABLE project_member DROP FOREIGN KEY FK_67401132D60322AC');
        $this->addSql('ALTER TABLE project_member DROP FOREIGN KEY FK_67401132A7B4A7E3');
        $this->addSql('ALTER TABLE role_permissions DROP FOREIGN KEY FK_1FBA94E6D60322AC');
        $this->addSql('ALTER TABLE role_permissions DROP FOREIGN KEY FK_1FBA94E6FED90CCA');
        $this->addSql('ALTER TABLE studio_chat_message DROP FOREIGN KEY FK_FFA3FF2F166D1F9C');
        $this->addSql('ALTER TABLE user_roles DROP FOREIGN KEY FK_54FCD59FA76ED395');
        $this->addSql('ALTER TABLE user_roles DROP FOREIGN KEY FK_54FCD59FD60322AC');
        $this->addSql('ALTER TABLE webhook DROP FOREIGN KEY FK_8A741756166D1F9C');
        $this->addSql('ALTER TABLE webhook_collections DROP FOREIGN KEY FK_D5CAE0875C9BA60B');
        $this->addSql('ALTER TABLE webhook_collections DROP FOREIGN KEY FK_D5CAE087514956FD');
        $this->addSql('ALTER TABLE webhook_log DROP FOREIGN KEY FK_736542785C9BA60B');
        $this->addSql('DROP TABLE api_token');
        $this->addSql('DROP TABLE app_settings');
        $this->addSql('DROP TABLE asset_metadata');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE `collection`');
        $this->addSql('DROP TABLE collection_template');
        $this->addSql('DROP TABLE collection_template_field');
        $this->addSql('DROP TABLE column_setting');
        $this->addSql('DROP TABLE content_entry');
        $this->addSql('DROP TABLE content_field_value');
        $this->addSql('DROP TABLE content_media_relation');
        $this->addSql('DROP TABLE content_relation_field_relation');
        $this->addSql('DROP TABLE content_version');
        $this->addSql('DROP TABLE deployment');
        $this->addSql('DROP TABLE email_log');
        $this->addSql('DROP TABLE end_user');
        $this->addSql('DROP TABLE end_user_field');
        $this->addSql('DROP TABLE field');
        $this->addSql('DROP TABLE media');
        $this->addSql('DROP TABLE password_reset_token');
        $this->addSql('DROP TABLE `permission`');
        $this->addSql('DROP TABLE project');
        $this->addSql('DROP TABLE project_mailer_settings');
        $this->addSql('DROP TABLE project_member');
        $this->addSql('DROP TABLE project_template');
        $this->addSql('DROP TABLE `role`');
        $this->addSql('DROP TABLE role_permissions');
        $this->addSql('DROP TABLE studio_chat_message');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE user_roles');
        $this->addSql('DROP TABLE webhook');
        $this->addSql('DROP TABLE webhook_collections');
        $this->addSql('DROP TABLE webhook_log');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
