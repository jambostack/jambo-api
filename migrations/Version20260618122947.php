<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260618122947 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add validation_rules JSON column to field table';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('field')->hasColumn('validation_rules'),
            'Column validation_rules already exists'
        );

        $this->addSql('ALTER TABLE field ADD validation_rules JSON DEFAULT NULL AFTER options');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            !$schema->getTable('field')->hasColumn('validation_rules'),
            'Column validation_rules does not exist'
        );

        $this->addSql('ALTER TABLE field DROP validation_rules');
    }
}
