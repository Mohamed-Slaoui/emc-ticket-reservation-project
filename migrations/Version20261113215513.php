<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use App\Enum\TariffCode;

/**
 * Makes the tariff catalogue a **fixed, database-enforced** set of the four
 * approved pricing categories (docs/DECISIONS.md Q5), and — this is the part
 * production gets for free — it **installs the catalogue** into the database.
 *
 * Today, only the development fixtures created the tariffs. A production
 * database built from migrations alone would therefore have had an empty
 * `tariff` table: no tariff to price, no matrix, no way to publish an event.
 * The migrations must carry the catalogue, because they are what production runs.
 *
 * The catalogue is closed at the database level:
 *
 * 1. `code` is limited to the four approved codes with a CHECK constraint
 *    (MySQL 8 enforces it), so no fifth category can ever be inserted.
 * 2. `name` is a generated column computed from the code — the app never
 *    writes it, it cannot drift and it cannot be edited, exactly like
 *    `performance_price.current_combo`.
 * 3. `is_active` and `requires_eligibility_proof` are gone: the four tariffs
 *    are always in effect, and the eligibility rule is derived from the code
 *    in the application (`TariffCode::requiresEligibilityProof()`).
 *
 * The statements that derive from the enum — the CHECK list and the
 * generated expression — are built from it, so this migration cannot fall
 * behind a catalogue change in code.
 */
final class Version20261113215513 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix the tariff catalogue at the four approved categories, computed at the database level, and install it for production.';
    }

    /**
     * The codes of the catalogue, quoted and comma-separated — built from the enum.
     */
    private static function codes(): string
    {
        return implode(', ', array_map(static fn (TariffCode $code): string => "'" . $code->value . "'", TariffCode::cases()));
    }

    /**
     * The CASE expression that derives the label from the code — built from the enum.
     */
    private static function labelExpression(): string
    {
        $branches = implode(' ', array_map(
            static fn (TariffCode $code): string => sprintf("WHEN '%s' THEN '%s'", $code->value, addslashes($code->label())),
            TariffCode::cases(),
        ));

        return sprintf("CASE code %s END", $branches);
    }

    public function up(Schema $schema): void
    {
        // 1. Normalize the legacy codes (French) so this migration is also safe
        //    on a database seeded before the rename.
        $this->addSql("UPDATE tariff SET code = 'FULL' WHERE code = 'PLEIN'");
        $this->addSql("UPDATE tariff SET code = 'REDUCED' WHERE code = 'REDUIT'");
        $this->addSql("UPDATE tariff SET code = 'GROUP' WHERE code = 'GROUPE'");

        // 2. Drop the mutable columns — the four tariffs are always in effect, and
        //    the eligibility rule is a property of the code.
        $this->addSql('ALTER TABLE tariff DROP COLUMN is_active, DROP COLUMN requires_eligibility_proof');

        // 3. Re-create the name as a generated column computed from the code. The old
        //    generated values are discarded and rebuilt — the label never carried
        //    information of its own.
        $this->addSql('ALTER TABLE tariff DROP COLUMN name');
        $this->addSql(sprintf(
            'ALTER TABLE tariff ADD name VARCHAR(100) GENERATED ALWAYS AS (%s) STORED',
            static::labelExpression(),
        ));

        // 4. The code is limited to the four approved categories.
        $this->addSql(sprintf(
            'ALTER TABLE tariff ADD CONSTRAINT ck_tariff_code CHECK (code IN (%s))',
            static::codes(),
        ));

        // 5. Install the catalogue. Idempotent and safe: existing rows are left alone.
        foreach (TariffCode::cases() as $case) {
            $this->addSql(sprintf(
                'INSERT INTO tariff (code) SELECT %s FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM tariff WHERE code = %s)',
                "'" . $case->value . "'",
                "'" . $case->value . "'",
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tariff DROP CHECK ck_tariff_code');

        // The name can go back to a plain column, seeded from the catalogue's labels.
        $this->addSql('ALTER TABLE tariff DROP COLUMN name');
        $this->addSql("ALTER TABLE tariff ADD name VARCHAR(100) NOT NULL DEFAULT '' AFTER code");
        foreach (TariffCode::cases() as $case) {
            $this->addSql(sprintf("UPDATE tariff SET name = '%s' WHERE code = '%s'", addslashes($case->label()), $case->value));
        }

        $this->addSql('ALTER TABLE tariff ADD is_active TINYINT NOT NULL DEFAULT 1 AFTER name');
        $this->addSql('ALTER TABLE tariff ADD requires_eligibility_proof TINYINT NOT NULL DEFAULT 0 AFTER is_active');

        // Only the reduced tariff required a supporting document; the others are in effect.
        foreach (TariffCode::cases() as $case) {
            $this->addSql(sprintf(
                "UPDATE tariff SET requires_eligibility_proof = %d, is_active = 1 WHERE code = '%s'",
                $case->requiresEligibilityProof() ? 1 : 0,
                $case->value,
            ));
        }
    }
}
