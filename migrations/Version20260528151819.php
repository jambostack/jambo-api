<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260528151819 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE audit_log (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, tool_name VARCHAR(100) NOT NULL, input JSON DEFAULT NULL, output JSON DEFAULT NULL, status VARCHAR(20) NOT NULL, error_message LONGTEXT DEFAULT NULL, created_by VARCHAR(100) DEFAULT NULL, source VARCHAR(50) DEFAULT NULL, duration_ms INT DEFAULT NULL, created_at DATETIME NOT NULL, project_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_F6E1C0F5D17F50A6 (uuid), INDEX IDX_F6E1C0F5166D1F9C (project_id), INDEX IDX_F6E1C0F5166D1F9C8B8E8428 (project_id, created_at), INDEX IDX_F6E1C0F585613E4D (tool_name), INDEX IDX_F6E1C0F5DE12AB56 (created_by), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F5166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY FK_F6E1C0F5166D1F9C');
        $this->addSql('DROP TABLE audit_log');
    }
}
