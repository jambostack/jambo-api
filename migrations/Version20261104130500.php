<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104130500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add password_changed_at column to end_user table for password rotation tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE end_user ADD password_changed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE end_user DROP password_changed_at');
    }
}
