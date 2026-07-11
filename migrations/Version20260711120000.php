<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Managed-cron booster: one recovery_schedule row per licensed shop, pinged by
 * app:recovery:tick to fire abandoned-cart reminders on low-traffic stores.
 */
final class Version20260711120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create recovery_schedule table for the managed-cron booster';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE recovery_schedule (
            id INT AUTO_INCREMENT NOT NULL,
            license_id INT NOT NULL,
            enabled TINYINT(1) DEFAULT 0 NOT NULL,
            callback_url VARCHAR(400) DEFAULT \'\' NOT NULL,
            callback_token VARCHAR(128) DEFAULT \'\' NOT NULL,
            interval_minutes INT DEFAULT 15 NOT NULL,
            next_due_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            last_run_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            last_status VARCHAR(32) DEFAULT NULL,
            last_http_code INT DEFAULT NULL,
            consecutive_failures INT DEFAULT 0 NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_recovery_schedule_license (license_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE recovery_schedule ADD CONSTRAINT FK_recovery_schedule_license FOREIGN KEY (license_id) REFERENCES license (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recovery_schedule DROP FOREIGN KEY FK_recovery_schedule_license');
        $this->addSql('DROP TABLE recovery_schedule');
    }
}
