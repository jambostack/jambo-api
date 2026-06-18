<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618100000 extends AbstractMigration
{
    public function getDescription(): string { return 'Ajout colonnes 2FA sur user et end_user'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD two_factor_enabled TINYINT(1) DEFAULT 0 NOT NULL, ADD two_factor_method VARCHAR(10) DEFAULT NULL, ADD two_factor_secret VARCHAR(255) DEFAULT NULL, ADD two_factor_backup_codes JSON DEFAULT NULL, ADD two_factor_confirmed_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE `end_user` ADD two_factor_enabled TINYINT(1) DEFAULT 0 NOT NULL, ADD two_factor_method VARCHAR(10) DEFAULT NULL, ADD two_factor_secret VARCHAR(255) DEFAULT NULL, ADD two_factor_backup_codes JSON DEFAULT NULL, ADD two_factor_confirmed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP two_factor_enabled, DROP two_factor_method, DROP two_factor_secret, DROP two_factor_backup_codes, DROP two_factor_confirmed_at');
        $this->addSql('ALTER TABLE `end_user` DROP two_factor_enabled, DROP two_factor_method, DROP two_factor_secret, DROP two_factor_backup_codes, DROP two_factor_confirmed_at');
    }
}
