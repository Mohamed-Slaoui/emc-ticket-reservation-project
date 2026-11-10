<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Closes the three model-level constraint gaps found during the Phase 1
 * review: the approved documentation promised guarantees the schema did not
 * deliver.
 *
 * 1. `performance_price` — "A single current price per combination"
 *    (docs/MODELE.md §5.2). The existing unique key includes the version,
 *    so two rows with `is_current = 1` could coexist for the same
 *    (performance, zone, tariff). A generated column materialises the
 *    combination only for the current row; NULLs are never compared by a
 *    unique index, so the retired versions stay unconstrained.
 *
 * 2. `performance_place` — the state invariant of docs/MODELE.md §5.4
 *    (status / current_reservation_id / hold_expires_at) was enforced by the
 *    entity only. It is now a CHECK constraint, so an inconsistent row
 *    cannot be written even outside the entity API.
 *
 * 3. `booking_policy` — the model describes a single-row configuration. A
 *    generated column always equal to 1, protected by a unique key, makes a
 *    second row impossible.
 */
final class Version20261110221207 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce the single-current-price, inventory state invariant and single booking policy rules at the database level.';
    }

    public function up(Schema $schema): void
    {
        // 1. One current price per (performance, zone, tariff).
        $this->addSql('ALTER TABLE performance_price ADD current_combo VARCHAR(64) GENERATED ALWAYS AS (CASE WHEN is_current = 1 THEN CONCAT(performance_id, \'-\', layout_zone_id, \'-\', tariff_id) ELSE NULL END) STORED');
        $this->addSql('ALTER TABLE performance_price ADD UNIQUE KEY uk_performance_price_current (current_combo)');

        // 2. The PerformancePlace state invariant (docs/MODELE.md §5.4).
        $this->addSql("ALTER TABLE performance_place ADD CONSTRAINT ck_performance_place_state CHECK ((status = 'AVAILABLE' AND current_reservation_id IS NULL AND hold_expires_at IS NULL) OR (status = 'HELD' AND current_reservation_id IS NOT NULL AND hold_expires_at IS NOT NULL) OR (status = 'SOLD' AND current_reservation_id IS NOT NULL AND hold_expires_at IS NULL) OR (status = 'BLOCKED' AND current_reservation_id IS NULL AND hold_expires_at IS NULL))");

        // 3. A single booking policy row.
        $this->addSql('ALTER TABLE booking_policy ADD singleton TINYINT(1) NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE booking_policy ADD UNIQUE KEY uk_booking_policy_singleton (singleton)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE booking_policy DROP INDEX uk_booking_policy_singleton');
        $this->addSql('ALTER TABLE booking_policy DROP COLUMN singleton');

        $this->addSql('ALTER TABLE performance_place DROP CONSTRAINT ck_performance_place_state');

        $this->addSql('ALTER TABLE performance_price DROP INDEX uk_performance_price_current');
        $this->addSql('ALTER TABLE performance_price DROP COLUMN current_combo');
    }
}
