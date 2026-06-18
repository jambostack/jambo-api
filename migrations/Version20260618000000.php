<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Commentaires + Notifications'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE comment (
            id INT AUTO_INCREMENT NOT NULL,
            uuid BINARY(16) NOT NULL,
            body TEXT NOT NULL,
            status VARCHAR(20) DEFAULT \'open\' NOT NULL,
            entry_id INT NOT NULL,
            author_id INT NOT NULL,
            parent_id INT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_COMMENT_UUID (uuid),
            INDEX IDX_COMMENT_ENTRY (entry_id),
            INDEX IDX_COMMENT_PARENT (parent_id),
            INDEX IDX_COMMENT_AUTHOR (author_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_COMMENT_ENTRY FOREIGN KEY (entry_id) REFERENCES content_entry (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_COMMENT_AUTHOR FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_COMMENT_PARENT FOREIGN KEY (parent_id) REFERENCES comment (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE notification (
            id INT AUTO_INCREMENT NOT NULL,
            uuid BINARY(16) NOT NULL,
            type VARCHAR(30) NOT NULL,
            title VARCHAR(255) NOT NULL,
            body TEXT DEFAULT NULL,
            link VARCHAR(500) NOT NULL,
            recipient_id INT NOT NULL,
            read_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_NOTIFICATION_UUID (uuid),
            INDEX IDX_NOTIF_RECIPIENT_READ (recipient_id, read_at, created_at),
            INDEX IDX_NOTIF_RECIPIENT_CREATED (recipient_id, created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_NOTIFICATION_RECIPIENT FOREIGN KEY (recipient_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_COMMENT_PARENT');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_COMMENT_ENTRY');
        $this->addSql('ALTER TABLE comment DROP FOREIGN KEY FK_COMMENT_AUTHOR');
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_NOTIFICATION_RECIPIENT');
        $this->addSql('DROP TABLE comment');
        $this->addSql('DROP TABLE notification');
    }
}
