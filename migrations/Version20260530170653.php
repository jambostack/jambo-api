<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260530170653 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE email_log (id INT AUTO_INCREMENT NOT NULL, `to` VARCHAR(255) NOT NULL, subject VARCHAR(255) NOT NULL, sent_at DATETIME NOT NULL, error LONGTEXT DEFAULT NULL, project_id INT NOT NULL, INDEX IDX_6FB4883166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE project_mailer_settings (id INT AUTO_INCREMENT NOT NULL, host VARCHAR(255) NOT NULL, port INT NOT NULL, username VARCHAR(255) NOT NULL, encrypted_password LONGTEXT NOT NULL, encryption VARCHAR(10) NOT NULL, from_email VARCHAR(255) NOT NULL, from_name VARCHAR(255) NOT NULL, enabled TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_1A8D404C166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE studio_chat_message (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, role VARCHAR(20) NOT NULL, content LONGTEXT NOT NULL, `schema` JSON DEFAULT NULL, created_at DATETIME NOT NULL, project_id INT NOT NULL, UNIQUE INDEX UNIQ_FFA3FF2FD17F50A6 (uuid), INDEX IDX_FFA3FF2F166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE workbench_message (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, role VARCHAR(20) NOT NULL, content LONGTEXT NOT NULL, files_generated JSON DEFAULT NULL, created_at DATETIME NOT NULL, workbench_project_id INT NOT NULL, UNIQUE INDEX UNIQ_F11D2D56D17F50A6 (uuid), INDEX IDX_F11D2D5684BAD40 (workbench_project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE workbench_snapshot (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, files JSON NOT NULL, snapshot_number INT NOT NULL, created_at DATETIME NOT NULL, workbench_project_id INT NOT NULL, UNIQUE INDEX UNIQ_6EB82D44D17F50A6 (uuid), INDEX IDX_6EB82D4484BAD40 (workbench_project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE email_log ADD CONSTRAINT FK_6FB4883166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_mailer_settings ADD CONSTRAINT FK_1A8D404C166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE studio_chat_message ADD CONSTRAINT FK_FFA3FF2F166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workbench_message ADD CONSTRAINT FK_F11D2D5684BAD40 FOREIGN KEY (workbench_project_id) REFERENCES workbench_project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workbench_snapshot ADD CONSTRAINT FK_6EB82D4484BAD40 FOREIGN KEY (workbench_project_id) REFERENCES workbench_project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE page_template DROP FOREIGN KEY `FK_17FF18A3166D1F9C`');
        $this->addSql('ALTER TABLE page_template DROP FOREIGN KEY `FK_17FF18A3B03A8386`');
        $this->addSql('DROP TABLE page_template');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE page_template (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, name VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, slug VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, sections JSON NOT NULL, generated_code LONGTEXT CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_0900_ai_ci`, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id INT NOT NULL, created_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_17FF18A3D17F50A6 (uuid), UNIQUE INDEX uniq_page_template_project_slug (project_id, slug), INDEX IDX_17FF18A3166D1F9C (project_id), INDEX IDX_17FF18A3B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE page_template ADD CONSTRAINT `FK_17FF18A3166D1F9C` FOREIGN KEY (project_id) REFERENCES project (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE page_template ADD CONSTRAINT `FK_17FF18A3B03A8386` FOREIGN KEY (created_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE email_log DROP FOREIGN KEY FK_6FB4883166D1F9C');
        $this->addSql('ALTER TABLE project_mailer_settings DROP FOREIGN KEY FK_1A8D404C166D1F9C');
        $this->addSql('ALTER TABLE studio_chat_message DROP FOREIGN KEY FK_FFA3FF2F166D1F9C');
        $this->addSql('ALTER TABLE workbench_message DROP FOREIGN KEY FK_F11D2D5684BAD40');
        $this->addSql('ALTER TABLE workbench_snapshot DROP FOREIGN KEY FK_6EB82D4484BAD40');
        $this->addSql('DROP TABLE email_log');
        $this->addSql('DROP TABLE project_mailer_settings');
        $this->addSql('DROP TABLE studio_chat_message');
        $this->addSql('DROP TABLE workbench_message');
        $this->addSql('DROP TABLE workbench_snapshot');
    }
}
