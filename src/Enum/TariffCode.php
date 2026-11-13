<?php

namespace App\Enum;

/**
 * The tariff catalogue of the venue — the four pricing categories of
 * docs/DECISIONS.md Q5.
 *
 * This enum is the **single source of truth** for the catalogue: the seed, the
 * production data migration and the pricing matrix all read it, and the
 * database enforces the same list with a CHECK constraint (`ck_tariff_code`).
 * A fifth category is a deliberate, reviewed change — code plus migration —
 * never something the interface can do (docs/DEVELOPMENT.md §4.7).
 *
 * The order of declaration is the order shown by the pricing matrix:
 * Full, Reduced, Group, Invitation.
 */
enum TariffCode: string
{
    case Full = 'FULL';
    case Reduced = 'REDUCED';
    case Group = 'GROUP';
    case Invitation = 'INVITATION';

    /**
     * The English name of the tariff — the exact label shown at the box
     * office and on the tickets. The database materialises the same one as a
     * generated column, so the two can never drift apart.
     */
    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full tariff',
            self::Reduced => 'Reduced tariff',
            self::Group => 'Group tariff',
            self::Invitation => 'Invitation',
        };
    }

    /**
     * Whether the seller must verify a supporting document in presence
     * (docs/DECISIONS.md Q5). Only the reduced tariff requires it.
     */
    public function requiresEligibilityProof(): bool
    {
        return self::Reduced === $this;
    }

    /**
     * Whether the customer may apply the tariff online. Full tariff only —
     * reduced, group and invitation tariffs go through the box office or an
     * administrator (docs/DECISIONS.md Q5).
     */
    public function sellableOnline(): bool
    {
        return self::Full === $this;
    }

    /**
     * The one-sentence rule attached to the category — shown as a tooltip
     * (docs/DECISIONS.md Q5).
     */
    public function rule(): string
    {
        return match ($this) {
            self::Full => 'Everyone — online, box office or administrator',
            self::Reduced => 'A supporting document is verified in person — box office or administrator',
            self::Group => 'Minimum 5 seats in one reservation — box office or administrator',
            self::Invitation => 'By authorization — box office or administrator',
        };
    }
}
