<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260617160000 extends AbstractMigration
{
    public function getDescription(): string { return 'Index composite content_field_value(content_entry_id, field_id)'; }
    public function up(Schema $schema): void {
        $this->addSql('CREATE INDEX IDX_CFV_ENTRY_FIELD ON content_field_value (content_entry_id, field_id)');
    }
    public function down(Schema $schema): void {
        $this->addSql('DROP INDEX IDX_CFV_ENTRY_FIELD ON content_field_value');
    }
}
