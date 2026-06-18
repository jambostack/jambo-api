<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618190438 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create media_folder table and add folder_id to media';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE media_folder (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            position INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME DEFAULT NULL,
            parent_id INT DEFAULT NULL,
            project_id INT NOT NULL,
            INDEX IDX_MEDIA_FOLDER_PARENT (parent_id),
            INDEX IDX_MEDIA_FOLDER_PROJECT (project_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE media_folder ADD CONSTRAINT FK_MEDIA_FOLDER_PARENT FOREIGN KEY (parent_id) REFERENCES media_folder (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE media_folder ADD CONSTRAINT FK_MEDIA_FOLDER_PROJECT FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE media ADD folder_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE media ADD CONSTRAINT FK_MEDIA_FOLDER FOREIGN KEY (folder_id) REFERENCES media_folder (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_MEDIA_FOLDER ON media (folder_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP FOREIGN KEY FK_MEDIA_FOLDER');
        $this->addSql('DROP INDEX IDX_MEDIA_FOLDER ON media');
        $this->addSql('ALTER TABLE media DROP folder_id');

        $this->addSql('ALTER TABLE media_folder DROP FOREIGN KEY FK_MEDIA_FOLDER_PARENT');
        $this->addSql('ALTER TABLE media_folder DROP FOREIGN KEY FK_MEDIA_FOLDER_PROJECT');
        $this->addSql('DROP TABLE media_folder');
    }
}
