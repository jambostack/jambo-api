<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260624014013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute oidc_sub + oidc_issuer sur User et EndUser (SSO OIDC v1.18)';
    }

    public function up(Schema $schema): void
    {
        // Limité volontairement à user et end_user : les autres diffs
        // (index notification, rename index user) sont du drift de migrations non enregistrées,
        // hors périmètre de cette feature.
        $this->addSql('ALTER TABLE `user` ADD oidc_sub VARCHAR(255) DEFAULT NULL, ADD oidc_issuer VARCHAR(512) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_oidc ON `user` (oidc_sub, oidc_issuer)');
        $this->addSql('ALTER TABLE end_user ADD oidc_sub VARCHAR(255) DEFAULT NULL, ADD oidc_issuer VARCHAR(512) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_end_user_oidc ON end_user (oidc_sub, oidc_issuer)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_user_oidc ON `user`');
        $this->addSql('ALTER TABLE `user` DROP oidc_sub, DROP oidc_issuer');
        $this->addSql('DROP INDEX uniq_end_user_oidc ON end_user');
        $this->addSql('ALTER TABLE end_user DROP oidc_sub, DROP oidc_issuer');
    }
}
