<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets an account be deleted even when it is the author of the last change to
 * the booking policy.
 *
 * `booking_policy.updated_by_id` remembers **who** changed the hold duration
 * last — a piece of audit information, not a link that keeps the account
 * alive. The column is already nullable, so the reference is simply cleared
 * when the account disappears.
 *
 * The other two foreign keys on `user` are left untouched on purpose:
 *
 * - `reservation.created_by_id` is NOT NULL: the author of a sale must
 *   survive, so an account that appears on a reservation can only be
 *   disabled — never deleted;
 * - `reservation.customer_id` protects the buyer of a reservation for the
 *   same reason.
 */
final class Version20261112154148 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Clear the booking policy author reference when the account is deleted.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking_policy DROP FOREIGN KEY FK_4BE73EE8896DBBDE');
        $this->addSql('ALTER TABLE booking_policy ADD CONSTRAINT FK_4BE73EE8896DBBDE FOREIGN KEY (updated_by_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking_policy DROP FOREIGN KEY FK_4BE73EE8896DBBDE');
        $this->addSql('ALTER TABLE booking_policy ADD CONSTRAINT FK_4BE73EE8896DBBDE FOREIGN KEY (updated_by_id) REFERENCES user (id)');
    }
}
