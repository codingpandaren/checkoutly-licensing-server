<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260715120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the download table for first-party download tracking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE download (
            id INT AUTO_INCREMENT NOT NULL,
            version VARCHAR(20) NOT NULL,
            ip_hash VARCHAR(64) DEFAULT NULL,
            country VARCHAR(2) DEFAULT NULL,
            referer VARCHAR(255) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_download_created_at (created_at),
            INDEX idx_download_version (version),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE download');
    }
}
