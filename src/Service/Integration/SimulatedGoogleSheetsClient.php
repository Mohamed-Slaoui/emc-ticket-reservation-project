<?php

namespace App\Service\Integration;

/**
 * Simulated Google Sheets client (docs/DECISIONS.md A1 style: no external
 * provider is imposed). Records the rows it receives in memory so the
 * outbox worker can be exercised without credentials; a real client
 * (google/apiclient) replaces the alias in config/services.yaml.
 */
class SimulatedGoogleSheetsClient implements GoogleSheetsClient
{
    /** @var array<string, list<array<string, mixed>>> */
    private static array $rows = [];

    public function appendRow(string $sheet, array $row, string $idempotencyKey): void
    {
        if (!isset(self::$rows[$sheet])) {
            self::$rows[$sheet] = [];
        }

        // Idempotent: a repeated key never duplicates a row.
        foreach (self::$rows[$sheet] as $existing) {
            if (($existing['_idempotency'] ?? null) === $idempotencyKey) {
                return;
            }
        }

        self::$rows[$sheet][] = array_merge(['_idempotency' => $idempotencyKey], $row);
    }

    /** @return list<array<string, mixed>> */
    public static function rowsFor(string $sheet): array
    {
        return self::$rows[$sheet] ?? [];
    }

    public static function reset(): void
    {
        self::$rows = [];
    }
}