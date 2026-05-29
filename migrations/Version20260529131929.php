<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260529131929 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE custom_domain (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, domain VARCHAR(253) NOT NULL, verification_token VARCHAR(64) NOT NULL, verified TINYINT NOT NULL, ssl_status VARCHAR(20) NOT NULL, verified_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, hosted_app_id INT NOT NULL, UNIQUE INDEX UNIQ_9304CA0FD17F50A6 (uuid), UNIQUE INDEX UNIQ_9304CA0FA7A91E0B (domain), INDEX IDX_9304CA0FC6ADDEA2 (hosted_app_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE hosted_app (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, subdomain VARCHAR(100) NOT NULL, container_id VARCHAR(100) DEFAULT NULL, image_ref LONGTEXT DEFAULT NULL, internal_port INT NOT NULL, status VARCHAR(20) NOT NULL, last_error LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, workbench_project_id INT NOT NULL, UNIQUE INDEX UNIQ_952F981D17F50A6 (uuid), UNIQUE INDEX UNIQ_952F981C1D5962E (subdomain), INDEX IDX_952F98184BAD40 (workbench_project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE custom_domain ADD CONSTRAINT FK_9304CA0FC6ADDEA2 FOREIGN KEY (hosted_app_id) REFERENCES hosted_app (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE hosted_app ADD CONSTRAINT FK_952F98184BAD40 FOREIGN KEY (workbench_project_id) REFERENCES workbench_project (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE custom_domain DROP FOREIGN KEY FK_9304CA0FC6ADDEA2');
        $this->addSql('ALTER TABLE hosted_app DROP FOREIGN KEY FK_952F98184BAD40');
        $this->addSql('DROP TABLE custom_domain');
        $this->addSql('DROP TABLE hosted_app');
    }
}
