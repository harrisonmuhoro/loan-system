<?php
declare(strict_types=1);

/** Static regression checks for the Phase 12 auditability controls. */
$root = dirname(__DIR__);
$sql = file_get_contents($root . '/scripts/legacy_auditability_phase12.sql');
$import = file_get_contents($root . '/api/legacy_import.php');
$ledger = file_get_contents($root . '/api/legacy_ledger.php');

function phase12Assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

phase12Assert(str_contains($sql, 'legacy_import_validation_attempts'), 'validation attempts are append-only modeled');
phase12Assert(str_contains($sql, 'legacy_import_validation_rows'), 'validation row history is modeled');
phase12Assert(str_contains($sql, 'legacy_import_records_before_delete'), 'import records have database deletion protection');
phase12Assert(str_contains($sql, 'legacy_duplicate_resolutions'), 'duplicate resolutions are persisted');
phase12Assert(str_contains($sql, 'legacy_dispute_events'), 'dispute lifecycle events are persisted');
phase12Assert(str_contains($sql, 'legacy_audit_events ADD COLUMN financial_year_id'), 'audit events carry financial-year context');
phase12Assert(str_contains($sql, 'Approval identity and timestamp cannot be reused'), 'approval identity reuse is blocked');
phase12Assert(!str_contains($import, 'DELETE FROM legacy_import_records'), 'revalidation does not delete prior import records');
phase12Assert(str_contains($import, 'beginValidationAttempt'), 'revalidation starts a new attempt');
phase12Assert(str_contains($import, 'RESOLVE_DUPLICATE'), 'duplicate decisions have a controlled API action');
phase12Assert(str_contains($import, 'Duplicate decision recorded without merging'), 'duplicate resolution preserves source records');
phase12Assert(str_contains($ledger, 'UPDATE_DISPUTE_STATUS'), 'dispute status changes use a server-side action');
phase12Assert(str_contains($ledger, 'legacy_dispute_events'), 'dispute status changes append an event and audit record');

echo "PHASE 12 STATIC AUDITABILITY TESTS PASSED\n";
