<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260527081543 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE end_user_field (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, type VARCHAR(50) NOT NULL, options JSON DEFAULT NULL, `order` INT NOT NULL, is_required TINYINT NOT NULL, is_system TINYINT NOT NULL, project_id INT NOT NULL, INDEX IDX_B77FE68E166D1F9C (project_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE end_user_field ADD CONSTRAINT FK_B77FE68E166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE end_user_field DROP FOREIGN KEY FK_B77FE68E166D1F9C');
        $this->addSql('DROP TABLE end_user_field');
    }
}
