<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260529093752 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add workbench_project table';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE workbench_project (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, name VARCHAR(255) NOT NULL, framework VARCHAR(50) NOT NULL, files JSON NOT NULL, generated_prompt LONGTEXT DEFAULT NULL, deploy_status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, project_id INT NOT NULL, created_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_6813CDC7D17F50A6 (uuid), INDEX IDX_6813CDC7166D1F9C (project_id), INDEX IDX_6813CDC7B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE workbench_project ADD CONSTRAINT FK_6813CDC7166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workbench_project ADD CONSTRAINT FK_6813CDC7B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE workbench_project DROP FOREIGN KEY FK_6813CDC7166D1F9C');
        $this->addSql('ALTER TABLE workbench_project DROP FOREIGN KEY FK_6813CDC7B03A8386');
        $this->addSql('DROP TABLE workbench_project');
    }
}
