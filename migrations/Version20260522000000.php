<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema — creates base tables required by all subsequent migrations.
 * Tables created here are intentionally minimal: later migrations add columns
 * (deleted_at, created_at, etc.) via ALTER TABLE statements.
 */
final class Version20260522000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: project, user, collection, field, content_entry, content_field_value';
    }

    public function up(Schema $schema): void
    {
        // Nettoyage des tables orphelines issues d'anciennes versions
        // (project_user existait quand Project avait un ManyToMany direct avec User)
        $this->addSql('DROP TABLE IF EXISTS project_user');

        // project
        $this->addSql('CREATE TABLE project (
            id INT AUTO_INCREMENT NOT NULL,
            uuid BINARY(16) NOT NULL,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            default_locale VARCHAR(10) NOT NULL DEFAULT \'en\',
            locales JSON NOT NULL,
            disk VARCHAR(50) NOT NULL DEFAULT \'public\',
            public_api TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE INDEX UNIQ_2FB3D0EED17F50A6 (uuid),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');

        // user
        $this->addSql('CREATE TABLE `user` (
            id INT AUTO_INCREMENT NOT NULL,
            uuid BINARY(16) NOT NULL,
            email VARCHAR(180) NOT NULL,
            roles JSON NOT NULL,
            password VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            locale VARCHAR(5) NOT NULL DEFAULT \'en\',
            UNIQUE INDEX UNIQ_8D93D649D17F50A6 (uuid),
            UNIQUE INDEX UNIQ_8D93D649E7927C74 (email),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');

        // collection (no deleted_at — added by Version20260523122705)
        $this->addSql('CREATE TABLE `collection` (
            id INT AUTO_INCREMENT NOT NULL,
            uuid BINARY(16) NOT NULL,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            is_singleton TINYINT(1) NOT NULL DEFAULT 0,
            `order` INT NOT NULL DEFAULT 0,
            project_id INT NOT NULL,
            UNIQUE INDEX UNIQ_FC5C0E5CD17F50A6 (uuid),
            INDEX IDX_FC5C0E5C166D1F9C (project_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE `collection`
            ADD CONSTRAINT FK_FC5C0E5C166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');

        // field (no deleted_at — added by Version20260523122705)
        $this->addSql('CREATE TABLE field (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            type VARCHAR(50) NOT NULL,
            options JSON DEFAULT NULL,
            `order` INT NOT NULL DEFAULT 0,
            is_required TINYINT(1) NOT NULL DEFAULT 0,
            collection_id INT NOT NULL,
            INDEX IDX_5BF54558514956FD (collection_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE field
            ADD CONSTRAINT FK_5BF54558514956FD FOREIGN KEY (collection_id) REFERENCES `collection` (id) ON DELETE CASCADE');

        // content_entry (without created_at/updated_at/deleted_at/created_by/updated_by — added by Version20260523122705)
        $this->addSql('CREATE TABLE content_entry (
            id INT AUTO_INCREMENT NOT NULL,
            uuid BINARY(16) NOT NULL,
            locale VARCHAR(10) NOT NULL DEFAULT \'en\',
            status VARCHAR(50) NOT NULL DEFAULT \'draft\',
            project_id INT NOT NULL,
            collection_id INT NOT NULL,
            UNIQUE INDEX UNIQ_C0E2C9A2D17F50A6 (uuid),
            INDEX IDX_C0E2C9A2166D1F9C (project_id),
            INDEX IDX_C0E2C9A2514956FD (collection_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE content_entry
            ADD CONSTRAINT FK_C0E2C9A2166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE,
            ADD CONSTRAINT FK_C0E2C9A2514956FD FOREIGN KEY (collection_id) REFERENCES `collection` (id) ON DELETE CASCADE');

        // content_field_value
        $this->addSql('CREATE TABLE content_field_value (
            id INT AUTO_INCREMENT NOT NULL,
            field_type VARCHAR(50) NOT NULL,
            text_value LONGTEXT DEFAULT NULL,
            number_value DECIMAL(14,6) DEFAULT NULL,
            boolean_value TINYINT(1) DEFAULT NULL,
            date_value DATE DEFAULT NULL,
            datetime_value DATETIME DEFAULT NULL,
            json_value JSON DEFAULT NULL,
            content_entry_id INT NOT NULL,
            field_id INT NOT NULL,
            INDEX IDX_B7B6C21AB3C01A8E (content_entry_id),
            INDEX IDX_B7B6C21A443707B0 (field_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE content_field_value
            ADD CONSTRAINT FK_B7B6C21AB3C01A8E FOREIGN KEY (content_entry_id) REFERENCES content_entry (id) ON DELETE CASCADE,
            ADD CONSTRAINT FK_B7B6C21A443707B0 FOREIGN KEY (field_id) REFERENCES field (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_field_value DROP FOREIGN KEY FK_B7B6C21AB3C01A8E');
        $this->addSql('ALTER TABLE content_field_value DROP FOREIGN KEY FK_B7B6C21A443707B0');
        $this->addSql('ALTER TABLE content_entry DROP FOREIGN KEY FK_C0E2C9A2166D1F9C');
        $this->addSql('ALTER TABLE content_entry DROP FOREIGN KEY FK_C0E2C9A2514956FD');
        $this->addSql('ALTER TABLE field DROP FOREIGN KEY FK_5BF54558514956FD');
        $this->addSql('ALTER TABLE `collection` DROP FOREIGN KEY FK_FC5C0E5C166D1F9C');
        $this->addSql('DROP TABLE content_field_value');
        $this->addSql('DROP TABLE content_entry');
        $this->addSql('DROP TABLE field');
        $this->addSql('DROP TABLE `collection`');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE project');
    }
}
