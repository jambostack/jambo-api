<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260529070746 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create deployment table for Jambo Deploy pipeline tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE deployment (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, commit_sha VARCHAR(40) NOT NULL, branch VARCHAR(100) DEFAULT NULL, environment VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, image_ref LONGTEXT DEFAULT NULL, preview_url VARCHAR(500) DEFAULT NULL, run_url VARCHAR(500) DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, project_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_EB1255BED17F50A6 (uuid), INDEX IDX_EB1255BE166D1F9C (project_id), INDEX idx_deployment_project_started (project_id, started_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE deployment ADD CONSTRAINT FK_EB1255BE166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deployment DROP FOREIGN KEY FK_EB1255BE166D1F9C');
        $this->addSql('DROP TABLE deployment');
    }
}
