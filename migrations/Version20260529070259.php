<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260529070259 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create page_template table for Jambo Studio Page Builder persistence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE page_template (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, sections JSON NOT NULL, generated_code LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id INT NOT NULL, created_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_17FF18A3D17F50A6 (uuid), INDEX IDX_17FF18A3166D1F9C (project_id), INDEX IDX_17FF18A3B03A8386 (created_by_id), UNIQUE INDEX uniq_page_template_project_slug (project_id, slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE page_template ADD CONSTRAINT FK_17FF18A3166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE page_template ADD CONSTRAINT FK_17FF18A3B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE page_template DROP FOREIGN KEY FK_17FF18A3166D1F9C');
        $this->addSql('ALTER TABLE page_template DROP FOREIGN KEY FK_17FF18A3B03A8386');
        $this->addSql('DROP TABLE page_template');
    }
}
