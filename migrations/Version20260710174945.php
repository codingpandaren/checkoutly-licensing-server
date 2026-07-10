<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260710174945 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE license (id INT AUTO_INCREMENT NOT NULL, license_id VARCHAR(32) NOT NULL, tier VARCHAR(20) DEFAULT \'pro\' NOT NULL, license_key LONGTEXT NOT NULL, stripe_subscription_id VARCHAR(191) DEFAULT NULL, stripe_customer_id VARCHAR(191) DEFAULT NULL, status VARCHAR(20) DEFAULT \'incomplete\' NOT NULL, revoked TINYINT DEFAULT 0 NOT NULL, registered_domain VARCHAR(191) DEFAULT NULL, expires_at INT DEFAULT 0 NOT NULL, last_seen_domain VARCHAR(191) DEFAULT NULL, last_seen_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_5768F419A76ED395 (user_id), UNIQUE INDEX uniq_license_license_id (license_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, oauth_provider VARCHAR(20) NOT NULL, oauth_id VARCHAR(191) NOT NULL, display_name VARCHAR(191) DEFAULT NULL, stripe_customer_id VARCHAR(191) DEFAULT NULL, roles JSON NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_user_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE license ADD CONSTRAINT FK_5768F419A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE license DROP FOREIGN KEY FK_5768F419A76ED395');
        $this->addSql('DROP TABLE license');
        $this->addSql('DROP TABLE `user`');
    }
}
