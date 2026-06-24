<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624070523 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add SEO columns on ContentEntry + new entities: SeoRevision, Redirect, RedirectRevision, Form, FormSubmission';
    }

    public function up(Schema $schema): void
    {
        // SEO columns on ContentEntry
        $this->addSql('ALTER TABLE content_entry ADD meta_title VARCHAR(255) DEFAULT NULL, ADD meta_description LONGTEXT DEFAULT NULL, ADD slug VARCHAR(255) DEFAULT \'\' NOT NULL, ADD canonical_url VARCHAR(512) DEFAULT NULL, ADD og_image VARCHAR(36) DEFAULT NULL, ADD seo_score INT DEFAULT NULL, ADD seo_scored_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_COLLECTION_SLUG_LOCALE ON content_entry (collection_id, slug, locale)');

        // SeoRevision
        $this->addSql('CREATE TABLE seo_revision (id INT AUTO_INCREMENT NOT NULL, entry_id INT NOT NULL, meta_title VARCHAR(255) DEFAULT NULL, meta_description LONGTEXT DEFAULT NULL, slug VARCHAR(255) NOT NULL, canonical_url VARCHAR(512) DEFAULT NULL, og_image VARCHAR(36) DEFAULT NULL, seo_score INT DEFAULT NULL, changed_by_id INT DEFAULT NULL, changed_at DATETIME NOT NULL, INDEX IDX_8CC4B4EEBA364942 (entry_id), INDEX IDX_8CC4B4EE828AD0A0 (changed_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE seo_revision ADD CONSTRAINT FK_8CC4B4EEBA364942 FOREIGN KEY (entry_id) REFERENCES content_entry (id)');
        $this->addSql('ALTER TABLE seo_revision ADD CONSTRAINT FK_8CC4B4EE828AD0A0 FOREIGN KEY (changed_by_id) REFERENCES `user` (id)');

        // Redirect
        $this->addSql('CREATE TABLE redirect (id INT AUTO_INCREMENT NOT NULL, uuid BINARY(16) NOT NULL, project_id INT NOT NULL, from_path VARCHAR(512) NOT NULL, to_path VARCHAR(512) NOT NULL, http_code INT NOT NULL, is_pattern TINYINT(1) NOT NULL, is_enabled TINYINT(1) NOT NULL, hits INT NOT NULL, last_hit_at DATETIME DEFAULT NULL, is_auto TINYINT(1) NOT NULL, source_entry_id INT DEFAULT NULL, created_by_id INT NOT NULL, updated_by_id INT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_C30C9E2BD17F50A6 (uuid), INDEX IDX_C30C9E2B166D1F9C (project_id), INDEX IDX_C30C9E2BF9167F90 (source_entry_id), INDEX IDX_C30C9E2BB03A8386 (created_by_id), INDEX IDX_C30C9E2B896DBBDE (updated_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE redirect ADD CONSTRAINT FK_C30C9E2B166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');
        $this->addSql('ALTER TABLE redirect ADD CONSTRAINT FK_C30C9E2BF9167F90 FOREIGN KEY (source_entry_id) REFERENCES content_entry (id)');
        $this->addSql('ALTER TABLE redirect ADD CONSTRAINT FK_C30C9E2BB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE redirect ADD CONSTRAINT FK_C30C9E2B896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id)');

        // RedirectRevision
        $this->addSql('CREATE TABLE redirect_revision (id INT AUTO_INCREMENT NOT NULL, redirect_id INT NOT NULL, from_path VARCHAR(512) NOT NULL, to_path VARCHAR(512) NOT NULL, http_code INT NOT NULL, changed_by_id INT NOT NULL, changed_at DATETIME NOT NULL, INDEX IDX_29D1EC7FB42D874D (redirect_id), INDEX IDX_29D1EC7F828AD0A0 (changed_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE redirect_revision ADD CONSTRAINT FK_29D1EC7FB42D874D FOREIGN KEY (redirect_id) REFERENCES redirect (id)');
        $this->addSql('ALTER TABLE redirect_revision ADD CONSTRAINT FK_29D1EC7F828AD0A0 FOREIGN KEY (changed_by_id) REFERENCES `user` (id)');

        // Form (without uuid, title, description, is_published)
        $this->addSql('CREATE TABLE form (id INT AUTO_INCREMENT NOT NULL, project_id INT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, fields JSON NOT NULL, steps JSON DEFAULT NULL, settings JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_5288FD4F166D1F9C (project_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE form ADD CONSTRAINT FK_5288FD4F166D1F9C FOREIGN KEY (project_id) REFERENCES project (id)');

        // FormSubmission (without uuid, step, ab_variant, read_at)
        $this->addSql('CREATE TABLE form_submission (id INT AUTO_INCREMENT NOT NULL, form_id INT NOT NULL, data JSON NOT NULL, metadata JSON NOT NULL, is_complete TINYINT(1) NOT NULL, is_spam TINYINT(1) NOT NULL, is_read TINYINT(1) NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_D2C216675FF69B7D (form_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE form_submission ADD CONSTRAINT FK_D2C216675FF69B7D FOREIGN KEY (form_id) REFERENCES form (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE form_submission DROP FOREIGN KEY FK_D2C216675FF69B7D');
        $this->addSql('ALTER TABLE form DROP FOREIGN KEY FK_5288FD4F166D1F9C');
        $this->addSql('ALTER TABLE redirect_revision DROP FOREIGN KEY FK_29D1EC7FB42D874D');
        $this->addSql('ALTER TABLE redirect_revision DROP FOREIGN KEY FK_29D1EC7F828AD0A0');
        $this->addSql('ALTER TABLE redirect DROP FOREIGN KEY FK_C30C9E2B166D1F9C');
        $this->addSql('ALTER TABLE redirect DROP FOREIGN KEY FK_C30C9E2BF9167F90');
        $this->addSql('ALTER TABLE redirect DROP FOREIGN KEY FK_C30C9E2BB03A8386');
        $this->addSql('ALTER TABLE redirect DROP FOREIGN KEY FK_C30C9E2B896DBBDE');
        $this->addSql('ALTER TABLE seo_revision DROP FOREIGN KEY FK_8CC4B4EEBA364942');
        $this->addSql('ALTER TABLE seo_revision DROP FOREIGN KEY FK_8CC4B4EE828AD0A0');
        $this->addSql('DROP TABLE form_submission');
        $this->addSql('DROP TABLE form');
        $this->addSql('DROP TABLE redirect_revision');
        $this->addSql('DROP TABLE redirect');
        $this->addSql('DROP TABLE seo_revision');
        $this->addSql('DROP INDEX UNIQ_COLLECTION_SLUG_LOCALE ON content_entry');
        $this->addSql('ALTER TABLE content_entry DROP meta_title, DROP meta_description, DROP slug, DROP canonical_url, DROP og_image, DROP seo_score, DROP seo_scored_at');
    }
}
