<?php

namespace App\Tests\Unit\Enum;

use App\Enum\EventStatus;
use App\Enum\EventType;
use App\Enum\InventoryStatus;
use App\Enum\OutboxStatus;
use App\Enum\PaymentMethod;
use App\Enum\PaymentStatus;
use App\Enum\PerformanceStatus;
use App\Enum\PlaceType;
use App\Enum\RefundStatus;
use App\Enum\ReservationItemStatus;
use App\Enum\ReservationStatus;
use App\Enum\SalesChannel;
use App\Enum\TicketStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The enumerations are part of the approved model (docs/MODELE.md §4.4).
 * Their backing values are **persisted in the database** as strings, so a
 * change would silently break the historical data: they are therefore
 * asserted explicitly.
 */
class EnumValuesTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, list<string>}>
     */
    public static function enumProvider(): iterable
    {
        yield 'PlaceType' => [PlaceType::class, ['SEAT', 'STANDING_SLOT']];
        yield 'EventType' => [EventType::class, ['CONCERT', 'THEATRE', 'CONFERENCE', 'OTHER']];
        yield 'EventStatus' => [EventStatus::class, ['DRAFT', 'PUBLISHED', 'ARCHIVED']];
        yield 'PerformanceStatus' => [PerformanceStatus::class, ['SCHEDULED', 'CANCELLED', 'COMPLETED']];
        yield 'InventoryStatus' => [InventoryStatus::class, ['AVAILABLE', 'HELD', 'SOLD', 'BLOCKED']];
        yield 'ReservationStatus' => [ReservationStatus::class, ['HELD', 'CONFIRMED', 'EXPIRED', 'CANCELLED']];
        yield 'ReservationItemStatus' => [ReservationItemStatus::class, ['HELD', 'CONFIRMED', 'EXPIRED', 'CANCELLED']];
        yield 'SalesChannel' => [SalesChannel::class, ['ONLINE', 'BOX_OFFICE']];
        yield 'PaymentMethod' => [PaymentMethod::class, ['ONLINE', 'CASH', 'CARD_TERMINAL']];
        yield 'PaymentStatus' => [PaymentStatus::class, ['PENDING', 'SUCCEEDED', 'FAILED']];
        yield 'RefundStatus' => [RefundStatus::class, ['PENDING', 'SUCCEEDED', 'FAILED']];
        yield 'TicketStatus' => [TicketStatus::class, ['VALID', 'USED', 'VOID']];
        yield 'OutboxStatus' => [OutboxStatus::class, ['PENDING', 'PROCESSING', 'SENT', 'FAILED']];
    }

    /**
     * @param class-string $enum
     * @param list<string> $expected
     */
    #[DataProvider('enumProvider')]
    public function test_enum_exposes_exactly_the_approved_values(string $enum, array $expected): void
    {
        $values = array_map(static fn ($case) => $case->value, $enum::cases());

        self::assertSame($expected, $values);
    }

    /**
     * @param class-string $enum
     * @param list<string> $expected
     */
    #[DataProvider('enumProvider')]
    public function test_enum_can_be_rebuilt_from_its_persisted_value(string $enum, array $expected): void
    {
        foreach ($expected as $value) {
            $case = $enum::tryFrom($value);

            self::assertNotNull($case, sprintf('%s::tryFrom(%s) must resolve.', $enum, $value));
            self::assertSame($value, $case->value);
        }

        self::assertNull($enum::tryFrom('NOT_A_VALID_VALUE'));
    }

    /**
     * The case names are used in the code; renaming one would break the
     * readability of the domain, so the mapping is asserted too.
     */
    public function test_case_names_are_stable(): void
    {
        self::assertSame('Available', InventoryStatus::Available->name);
        self::assertSame('StandingSlot', PlaceType::StandingSlot->name);
        self::assertSame('BoxOffice', SalesChannel::BoxOffice->name);
        self::assertSame('CardTerminal', PaymentMethod::CardTerminal->name);
        self::assertSame('Succeeded', PaymentStatus::Succeeded->name);
        self::assertSame('Void', TicketStatus::Void->name);
    }
}
