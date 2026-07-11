<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * API gateway usage metering: per-license, per-feature, per-month counter.
 */
final class Version20260711090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create api_usage table for gateway quota metering';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_usage (
            id INT AUTO_INCREMENT NOT NULL,
            license_id INT NOT NULL,
            period VARCHAR(6) NOT NULL,
            feature VARCHAR(32) NOT NULL,
            count INT DEFAULT 0 NOT NULL,
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_api_usage_license (license_id),
            UNIQUE INDEX uniq_api_usage_license_period_feature (license_id, period, feature),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE api_usage ADD CONSTRAINT FK_api_usage_license FOREIGN KEY (license_id) REFERENCES license (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_usage DROP FOREIGN KEY FK_api_usage_license');
        $this->addSql('DROP TABLE api_usage');
    }
}
