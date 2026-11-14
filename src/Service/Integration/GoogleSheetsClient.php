<?php

namespace App\Service\Integration;

/**
 * The only door to Google Sheets for the outbox worker (docs/WORKFLOWS.md
 * §7.3). The MVP ships the simulated one; a real client plugs in through
 * the same alias in config/services.yaml.
 */
interface GoogleSheetsClient
{
    /**
     * Appends one row to the named sheet. The call must be idempotent with
     * respect to the idempotency key: a repeated call for the same key must
     * not create a duplicate row.
     */
    public function appendRow(string $sheet, array $row, string $idempotencyKey): void;
}