<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename end_user.name to username, make passwords nullable, add social ID columns';
    }

    public function up(Schema $schema): void
    {
        // Rename name → username
        $this->addSql('ALTER TABLE end_user CHANGE name username VARCHAR(255) DEFAULT NULL');

        // Update EndUserField system field slug
        $this->addSql("UPDATE end_user_field SET slug = 'username', name = 'Username' WHERE slug = 'name'");

        // Password nullable
        $this->addSql('ALTER TABLE `user` MODIFY password VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE end_user MODIFY password VARCHAR(255) DEFAULT NULL');

        // Social IDs sur User
        $this->addSql('ALTER TABLE `user` ADD google_id VARCHAR(255) DEFAULT NULL, ADD microsoft_id VARCHAR(255) DEFAULT NULL, ADD github_id VARCHAR(255) DEFAULT NULL, ADD gitlab_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_user_google_id ON `user` (google_id)');
        $this->addSql('CREATE INDEX IDX_user_microsoft_id ON `user` (microsoft_id)');
        $this->addSql('CREATE INDEX IDX_user_github_id ON `user` (github_id)');
        $this->addSql('CREATE INDEX IDX_user_gitlab_id ON `user` (gitlab_id)');

        // Social IDs sur EndUser
        $this->addSql('ALTER TABLE end_user ADD google_id VARCHAR(255) DEFAULT NULL, ADD microsoft_id VARCHAR(255) DEFAULT NULL, ADD github_id VARCHAR(255) DEFAULT NULL, ADD gitlab_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_end_user_google_id ON end_user (google_id)');
        $this->addSql('CREATE INDEX IDX_end_user_microsoft_id ON end_user (microsoft_id)');
        $this->addSql('CREATE INDEX IDX_end_user_github_id ON end_user (github_id)');
        $this->addSql('CREATE INDEX IDX_end_user_gitlab_id ON end_user (gitlab_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE end_user_field SET slug = 'name', name = 'Name' WHERE slug = 'username'");
        $this->addSql('ALTER TABLE end_user CHANGE username name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` MODIFY password VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE end_user MODIFY password VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE `user` DROP google_id, DROP microsoft_id, DROP github_id, DROP gitlab_id');
        $this->addSql('ALTER TABLE end_user DROP google_id, DROP microsoft_id, DROP github_id, DROP gitlab_id');
    }
}
