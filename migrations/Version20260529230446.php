<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260529230446 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE site_domain (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, domain VARCHAR(253) NOT NULL, is_primary TINYINT NOT NULL, created_at DATETIME NOT NULL, workbench_project_id INT NOT NULL, UNIQUE INDEX UNIQ_D707B054D17F50A6 (uuid), UNIQUE INDEX UNIQ_D707B054A7A91E0B (domain), INDEX IDX_D707B05484BAD40 (workbench_project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE workbench_env_var (id INT AUTO_INCREMENT NOT NULL, key_name VARCHAR(120) NOT NULL, value LONGTEXT NOT NULL, is_secret TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, workbench_project_id INT NOT NULL, INDEX IDX_46D19BB084BAD40 (workbench_project_id), UNIQUE INDEX uniq_workbench_env_var (workbench_project_id, key_name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE site_domain ADD CONSTRAINT FK_D707B05484BAD40 FOREIGN KEY (workbench_project_id) REFERENCES workbench_project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workbench_env_var ADD CONSTRAINT FK_46D19BB084BAD40 FOREIGN KEY (workbench_project_id) REFERENCES workbench_project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE custom_domain DROP FOREIGN KEY `FK_9304CA0FC6ADDEA2`');
        $this->addSql('ALTER TABLE deploy_token DROP FOREIGN KEY `FK_29EA97A1A76ED395`');
        $this->addSql('ALTER TABLE hosted_app DROP FOREIGN KEY `FK_952F98184BAD40`');
        $this->addSql('DROP TABLE custom_domain');
        $this->addSql('DROP TABLE deploy_token');
        $this->addSql('DROP TABLE hosted_app');
        $this->addSql('ALTER TABLE app_settings DROP deploy_integrations');
        $this->addSql('ALTER TABLE workbench_project ADD published_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE custom_domain (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, domain VARCHAR(253) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, verification_token VARCHAR(64) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, verified TINYINT NOT NULL, ssl_status VARCHAR(20) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, verified_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, hosted_app_id INT NOT NULL, INDEX IDX_9304CA0FC6ADDEA2 (hosted_app_id), UNIQUE INDEX UNIQ_9304CA0FA7A91E0B (domain), UNIQUE INDEX UNIQ_9304CA0FD17F50A6 (uuid), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE deploy_token (id INT AUTO_INCREMENT NOT NULL, provider VARCHAR(20) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, encrypted_token LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, expires_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_29EA97A1A76ED395 (user_id), UNIQUE INDEX uniq_deploy_token_user_provider (user_id, provider), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE hosted_app (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, subdomain VARCHAR(100) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, container_id VARCHAR(100) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`, image_ref LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`, internal_port INT NOT NULL, status VARCHAR(20) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, last_error LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, workbench_project_id INT NOT NULL, INDEX IDX_952F98184BAD40 (workbench_project_id), UNIQUE INDEX UNIQ_952F981C1D5962E (subdomain), UNIQUE INDEX UNIQ_952F981D17F50A6 (uuid), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE custom_domain ADD CONSTRAINT `FK_9304CA0FC6ADDEA2` FOREIGN KEY (hosted_app_id) REFERENCES hosted_app (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE deploy_token ADD CONSTRAINT `FK_29EA97A1A76ED395` FOREIGN KEY (user_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE hosted_app ADD CONSTRAINT `FK_952F98184BAD40` FOREIGN KEY (workbench_project_id) REFERENCES workbench_project (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE site_domain DROP FOREIGN KEY FK_D707B05484BAD40');
        $this->addSql('ALTER TABLE workbench_env_var DROP FOREIGN KEY FK_46D19BB084BAD40');
        $this->addSql('DROP TABLE site_domain');
        $this->addSql('DROP TABLE workbench_env_var');
        $this->addSql('ALTER TABLE app_settings ADD deploy_integrations JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE workbench_project DROP published_at');
    }
}
