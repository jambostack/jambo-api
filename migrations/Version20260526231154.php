<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260526231154 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lie PasswordResetToken a un projet pour empecher la reutilisation cross-projet';
    }

    public function up(Schema $schema): void
    {
        // Les tokens existants sans projet sont invalides, on les supprime
        $this->addSql('DELETE FROM password_reset_token WHERE 1=1');
        $this->addSql('ALTER TABLE password_reset_token ADD project_id INT NOT NULL');
        $this->addSql('ALTER TABLE password_reset_token ADD CONSTRAINT FK_6B7BA4B6166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_6B7BA4B6166D1F9C ON password_reset_token (project_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE password_reset_token DROP FOREIGN KEY FK_6B7BA4B6166D1F9C');
        $this->addSql('DROP INDEX IDX_6B7BA4B6166D1F9C ON password_reset_token');
        $this->addSql('ALTER TABLE password_reset_token DROP project_id');
    }
}
