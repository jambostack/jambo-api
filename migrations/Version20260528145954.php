<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260528145954 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE content_version (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, snapshot JSON NOT NULL, label VARCHAR(255) DEFAULT NULL, version_number INT NOT NULL, created_at DATETIME NOT NULL, content_entry_id INT NOT NULL, created_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_60140A70D17F50A6 (uuid), INDEX IDX_60140A70E881A3AD (content_entry_id), INDEX IDX_60140A70B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE content_version ADD CONSTRAINT FK_60140A70E881A3AD FOREIGN KEY (content_entry_id) REFERENCES content_entry (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE content_version ADD CONSTRAINT FK_60140A70B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE content_version DROP FOREIGN KEY FK_60140A70E881A3AD');
        $this->addSql('ALTER TABLE content_version DROP FOREIGN KEY FK_60140A70B03A8386');
        $this->addSql('DROP TABLE content_version');
    }
}
