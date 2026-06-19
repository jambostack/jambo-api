<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260619065139 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create automation and automation_run tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE automation (
            id INT AUTO_INCREMENT NOT NULL,
            uuid BINARY(16) NOT NULL,
            project_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            debug_mode TINYINT(1) NOT NULL DEFAULT 0,
            trigger_type VARCHAR(50) NOT NULL,
            trigger_config JSON DEFAULT NULL,
            conditions JSON DEFAULT NULL,
            action_type VARCHAR(50) NOT NULL,
            action_config JSON DEFAULT NULL,
            last_run_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX UNIQ_AUTOMATION_UUID (uuid),
            INDEX IDX_AUTOMATION_PROJECT (project_id),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE automation_run (
            id INT AUTO_INCREMENT NOT NULL,
            automation_id INT NOT NULL,
            status VARCHAR(20) NOT NULL,
            trigger_payload JSON DEFAULT NULL,
            condition_results JSON DEFAULT NULL,
            action_input JSON DEFAULT NULL,
            action_output JSON DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME DEFAULT NULL,
            duration_ms INT DEFAULT NULL,
            INDEX IDX_AUTOMATION_RUN_AUTO (automation_id),
            INDEX IDX_AUTOMATION_RUN_STATUS (status),
            PRIMARY KEY (id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE automation ADD CONSTRAINT FK_AUTOMATION_PROJECT FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE automation_run ADD CONSTRAINT FK_AUTOMATION_RUN_AUTO FOREIGN KEY (automation_id) REFERENCES automation (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE automation_run DROP FOREIGN KEY FK_AUTOMATION_RUN_AUTO');
        $this->addSql('ALTER TABLE automation DROP FOREIGN KEY FK_AUTOMATION_PROJECT');
        $this->addSql('DROP TABLE automation_run');
        $this->addSql('DROP TABLE automation');
    }
}
