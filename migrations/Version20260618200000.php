<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add settings JSON column to project table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD settings JSON DEFAULT NULL AFTER storage_strategy');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project DROP settings');
    }
}
