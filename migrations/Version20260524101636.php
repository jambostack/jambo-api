<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260524101636 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace project_user junction table with project_member entity';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE project_member (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, invitation_token VARCHAR(64) DEFAULT NULL, token_expires_at DATETIME DEFAULT NULL, joined_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, project_id INT NOT NULL, user_id INT DEFAULT NULL, role_id INT DEFAULT NULL, invited_by_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_6740113233FC351A (invitation_token), INDEX IDX_67401132166D1F9C (project_id), INDEX IDX_67401132A76ED395 (user_id), INDEX IDX_67401132D60322AC (role_id), INDEX IDX_67401132A7B4A7E3 (invited_by_id), UNIQUE INDEX uniq_project_email (project_id, email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE project_member ADD CONSTRAINT FK_67401132166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_member ADD CONSTRAINT FK_67401132A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_member ADD CONSTRAINT FK_67401132D60322AC FOREIGN KEY (role_id) REFERENCES `role` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE project_member ADD CONSTRAINT FK_67401132A7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        // Migrate existing data from project_user (if the table exists)
        $this->addSql("INSERT INTO project_member (project_id, user_id, email, status, joined_at, created_at)
            SELECT pu.project_id, pu.user_id, u.email, 'active', NOW(), NOW()
            FROM project_user pu
            JOIN `user` u ON u.id = pu.user_id
        ");
        $this->addSql('ALTER TABLE project_user DROP FOREIGN KEY `FK_B4021E51166D1F9C`');
        $this->addSql('ALTER TABLE project_user DROP FOREIGN KEY `FK_B4021E51A76ED395`');
        $this->addSql('DROP TABLE project_user');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE project_user (project_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_B4021E51A76ED395 (user_id), INDEX IDX_B4021E51166D1F9C (project_id), PRIMARY KEY (project_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE project_user ADD CONSTRAINT `FK_B4021E51166D1F9C` FOREIGN KEY (project_id) REFERENCES project (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_user ADD CONSTRAINT `FK_B4021E51A76ED395` FOREIGN KEY (user_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE project_member DROP FOREIGN KEY FK_67401132166D1F9C');
        $this->addSql('ALTER TABLE project_member DROP FOREIGN KEY FK_67401132A76ED395');
        $this->addSql('ALTER TABLE project_member DROP FOREIGN KEY FK_67401132D60322AC');
        $this->addSql('ALTER TABLE project_member DROP FOREIGN KEY FK_67401132A7B4A7E3');
        $this->addSql('DROP TABLE project_member');
    }
}
