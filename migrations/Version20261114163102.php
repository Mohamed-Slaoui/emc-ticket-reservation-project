<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-account tariff permissions for the box office.
 *
 * The box office holds rows of the counter flow — it is the only role
 * besides the administrator that **may** sell the reduced and invitation
 * tariffs (docs/DECISIONS.md Q5, BR-11). The permissions describe the
 * authorization of that particular seller, nothing more.
 *
 * The two columns are added with `DEFAULT 0`: existing accounts (including
 * the administrator) keep working — the administrator never consults them,
 * and box-office sellers default to having no authorization, which is the
 * safest reading until the administrator grants it.
 *
 * The seed fixtures grant both permissions to the demo box-office account so
 * the demo keeps demonstrating the full flow.
 */
final class Version20261114163102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the tariff permissions of the box office (reduced, invitation).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user ADD can_sell_reduced_tariff TINYINT DEFAULT 0 NOT NULL, ADD can_sell_invitation_tariff TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user DROP can_sell_reduced_tariff, DROP can_sell_invitation_tariff');
    }
}
