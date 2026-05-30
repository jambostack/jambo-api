<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260530050848 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE custom_domain (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, domain VARCHAR(253) NOT NULL, verification_token VARCHAR(64) NOT NULL, verified TINYINT NOT NULL, ssl_status VARCHAR(20) NOT NULL, verified_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, hosted_app_id INT NOT NULL, UNIQUE INDEX UNIQ_9304CA0FD17F50A6 (uuid), UNIQUE INDEX UNIQ_9304CA0FA7A91E0B (domain), INDEX IDX_9304CA0FC6ADDEA2 (hosted_app_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE deploy_token (id INT AUTO_INCREMENT NOT NULL, provider VARCHAR(20) NOT NULL, encrypted_token LONGTEXT NOT NULL, expires_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_29EA97A1A76ED395 (user_id), UNIQUE INDEX uniq_deploy_token_user_provider (user_id, provider), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE email_log (id INT AUTO_INCREMENT NOT NULL, `to` VARCHAR(255) NOT NULL, subject VARCHAR(255) NOT NULL, sent_at DATETIME NOT NULL, error LONGTEXT DEFAULT NULL, project_id INT NOT NULL, INDEX IDX_6FB4883166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE hosted_app (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, subdomain VARCHAR(100) NOT NULL, container_id VARCHAR(100) DEFAULT NULL, image_ref LONGTEXT DEFAULT NULL, internal_port INT NOT NULL, status VARCHAR(20) NOT NULL, last_error LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, workbench_project_id INT NOT NULL, UNIQUE INDEX UNIQ_952F981D17F50A6 (uuid), UNIQUE INDEX UNIQ_952F981C1D5962E (subdomain), INDEX IDX_952F98184BAD40 (workbench_project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project_mailer_settings (id INT AUTO_INCREMENT NOT NULL, host VARCHAR(255) NOT NULL, port INT NOT NULL, username VARCHAR(255) NOT NULL, encrypted_password LONGTEXT NOT NULL, encryption VARCHAR(10) NOT NULL, from_email VARCHAR(255) NOT NULL, from_name VARCHAR(255) NOT NULL, enabled TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_1A8D404C166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE custom_domain ADD CONSTRAINT FK_9304CA0FC6ADDEA2 FOREIGN KEY (hosted_app_id) REFERENCES hosted_app (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE deploy_token ADD CONSTRAINT FK_29EA97A1A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE email_log ADD CONSTRAINT FK_6FB4883166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE hosted_app ADD CONSTRAINT FK_952F98184BAD40 FOREIGN KEY (workbench_project_id) REFERENCES workbench_project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_mailer_settings ADD CONSTRAINT FK_1A8D404C166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE site_domain DROP FOREIGN KEY `FK_D707B05484BAD40`');
        $this->addSql('ALTER TABLE workbench_env_var DROP FOREIGN KEY `FK_46D19BB084BAD40`');
        $this->addSql('DROP TABLE site_domain');
        $this->addSql('DROP TABLE workbench_env_var');
        $this->addSql('ALTER TABLE app_settings ADD deploy_integrations JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE workbench_project DROP published_at');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE site_domain (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, domain VARCHAR(253) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, is_primary TINYINT NOT NULL, created_at DATETIME NOT NULL, workbench_project_id INT NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_D707B05484BAD40 (workbench_project_id), UNIQUE INDEX UNIQ_D707B054A7A91E0B (domain), UNIQUE INDEX UNIQ_D707B054D17F50A6 (uuid), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE workbench_env_var (id INT AUTO_INCREMENT NOT NULL, key_name VARCHAR(120) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, value LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, is_secret TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, workbench_project_id INT NOT NULL, INDEX IDX_46D19BB084BAD40 (workbench_project_id), UNIQUE INDEX uniq_workbench_env_var (workbench_project_id, key_name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE site_domain ADD CONSTRAINT `FK_D707B05484BAD40` FOREIGN KEY (workbench_project_id) REFERENCES workbench_project (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workbench_env_var ADD CONSTRAINT `FK_46D19BB084BAD40` FOREIGN KEY (workbench_project_id) REFERENCES workbench_project (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE custom_domain DROP FOREIGN KEY FK_9304CA0FC6ADDEA2');
        $this->addSql('ALTER TABLE deploy_token DROP FOREIGN KEY FK_29EA97A1A76ED395');
        $this->addSql('ALTER TABLE email_log DROP FOREIGN KEY FK_6FB4883166D1F9C');
        $this->addSql('ALTER TABLE hosted_app DROP FOREIGN KEY FK_952F98184BAD40');
        $this->addSql('ALTER TABLE project_mailer_settings DROP FOREIGN KEY FK_1A8D404C166D1F9C');
        $this->addSql('DROP TABLE custom_domain');
        $this->addSql('DROP TABLE deploy_token');
        $this->addSql('DROP TABLE email_log');
        $this->addSql('DROP TABLE hosted_app');
        $this->addSql('DROP TABLE project_mailer_settings');
        $this->addSql('ALTER TABLE app_settings DROP deploy_integrations');
        $this->addSql('ALTER TABLE workbench_project ADD published_at DATETIME DEFAULT NULL');
    }
}
