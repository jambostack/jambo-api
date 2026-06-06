<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add jwt_access_ttl and jwt_refresh_ttl columns to project table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD jwt_access_ttl INT DEFAULT NULL, ADD jwt_refresh_ttl INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project DROP jwt_access_ttl, DROP jwt_refresh_ttl');
    }
}
