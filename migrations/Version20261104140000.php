<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create OAuth project_auth tables if not present and add family_id / parent_token_hash to project_auth_refresh_tokens for reuse detection and family revocation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS project_auth_clients (
            id INT AUTO_INCREMENT NOT NULL,
            client_id VARCHAR(64) NOT NULL,
            client_secret VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            redirect_uris JSON NOT NULL,
            allowed_grant_types JSON NOT NULL,
            allowed_scopes JSON NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            project_id INT NOT NULL,
            UNIQUE INDEX UNIQ_D9C2E1F619EB6921 (client_id),
            INDEX IDX_D9C2E1F6166D1F9C (project_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS project_auth_sessions (
            id INT AUTO_INCREMENT NOT NULL,
            session_id VARCHAR(64) NOT NULL,
            end_user_id INT NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            last_active_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_6F479C99613FECDF (session_id),
            INDEX IDX_6F479C99A76ED395 (end_user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS project_auth_authorization_codes (
            id INT AUTO_INCREMENT NOT NULL,
            code_hash VARCHAR(64) NOT NULL,
            client_id INT NOT NULL,
            end_user_id INT NOT NULL,
            redirect_uri VARCHAR(512) NOT NULL,
            scopes JSON NOT NULL,
            code_challenge VARCHAR(128) DEFAULT NULL,
            code_challenge_method VARCHAR(10) DEFAULT NULL,
            is_used TINYINT(1) NOT NULL,
            expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_BC7DBF20D76F8790 (code_hash),
            INDEX IDX_BC7DBF2019EB6921 (client_id),
            INDEX IDX_BC7DBF20A76ED395 (end_user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE IF NOT EXISTS project_auth_refresh_tokens (
            id INT AUTO_INCREMENT NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            client_id INT DEFAULT NULL,
            end_user_id INT NOT NULL,
            scopes JSON NOT NULL,
            is_revoked TINYINT(1) NOT NULL,
            family_id VARCHAR(64) DEFAULT NULL,
            parent_token_hash VARCHAR(64) DEFAULT NULL,
            expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_868F0D7BB3BC57DA (token_hash),
            INDEX IDX_868F0D7B19EB6921 (client_id),
            INDEX IDX_868F0D7BA76ED395 (end_user_id),
            INDEX idx_part_family (family_id),
            INDEX idx_part_user_revoked (end_user_id, is_revoked),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        // Resilience: if project_auth_refresh_tokens was pre-existing, adjust columns
        $this->addSql('SET @col_family = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "project_auth_refresh_tokens" AND COLUMN_NAME = "family_id")');
        $this->addSql('SET @sql_family = IF(@col_family = 0, "ALTER TABLE project_auth_refresh_tokens ADD family_id VARCHAR(64) DEFAULT NULL, ADD parent_token_hash VARCHAR(64) DEFAULT NULL", "SELECT 1")');
        $this->addSql('PREPARE stmt_family FROM @sql_family');
        $this->addSql('EXECUTE stmt_family');
        $this->addSql('DEALLOCATE PREPARE stmt_family');

        $this->addSql('ALTER TABLE project_auth_refresh_tokens MODIFY client_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
    }
}
