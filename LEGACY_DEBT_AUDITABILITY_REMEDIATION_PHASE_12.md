# Legacy Debt Auditability & Data Integrity Remediation — Phase 12

## Scope

This phase addresses auditability and historical-data integrity only. It does not redesign the UI or change ledger accounting rules.

## Defects and root causes

- Revalidation deleted `legacy_import_records`, destroying the previous validation result.
- Import records did not have a database-level delete guard.
- Possible duplicate decisions had no durable, non-destructive resolution record.
- Audit events did not explicitly carry financial-year context.
- Dispute history had no append-only event model.
- Approval identity could be changed or reused after approval without a dedicated integrity guard.

## Remediation

`api/legacy_import.php` now creates an append-only validation attempt and stores every row result in `legacy_import_validation_rows`. The original `legacy_import_records` rows are created only on the first attempt and are never deleted during revalidation. A controlled `RESOLVE_DUPLICATE` action records the decision, actor, reason, timestamp, and optional candidate without merging or deleting source records.

The SQL migration adds immutable validation-attempt history, immutable validation-row history, duplicate-resolution decisions, dispute events, and an explicit `financial_year_id` on structured audit events. Database triggers reject deletion or mutation of these historical records and reject deletion of legacy import records.

`api/legacy_ledger.php` now exposes the controlled `UPDATE_DISPUTE_STATUS` action. It requires the new `legacy.dispute.manage` permission, requires a reason, records the old and new status, and writes both a dispute event and a structured audit event in one transaction. Payments remain independent of dispute state.

## Schema, constraints, and triggers

Migration: `scripts/legacy_auditability_phase12.sql`

- `legacy_import_validation_attempts` and `legacy_import_validation_rows` preserve every validation run.
- `legacy_duplicate_resolutions` preserves same-person, different-person, and unresolved decisions.
- `legacy_dispute_events` preserves dispute status, reason, resolution, actor, timestamp, and financial impact.
- `legacy.dispute.manage` is granted to the existing staff roles through the migration and is enforced server-side.
- `legacy_import_records`, validation history, duplicate decisions, dispute events, and audit events are protected against ordinary deletion or mutation.
- Approval identity and approval timestamp become immutable once recorded; terminal action requests cannot be moved to another state.
- Existing source batch metadata remains immutable and source files retain their batch linkage and generated storage path.

## Audit model

Import validation, duplicate resolution, reconciliation, ledger actions, migration, and approval operations use `legacy_audit_events`. The event model retains actor, entity, action, reason, before/after data, request linkage, timestamp, and now an explicit financial-year field where supplied by the operation.

## Duplicate-resolution model

Identity normalization may identify a possible duplicate, but the system does not automatically merge ambiguous people. Authorized import personnel must record `SAME_PERSON`, `DIFFERENT_PERSON`, or `UNRESOLVED` with a reason. The source rows remain intact and each decision is append-only.

## Tests

Passed `tests/legacy_auditability_phase12_test.php`, including checks for:

- append-only validation attempts and rows;
- import-record delete protection;
- non-destructive duplicate resolution;
- dispute event storage;
- financial-year audit context;
- approval reuse protection;
- removal of the validation-time physical delete.

Changed PHP files should also pass the repository PHP syntax checks before applying the SQL migration.

## Residual risks

- The SQL migration must be applied to the target database before the new revalidation and duplicate-resolution paths are used.
- Existing historical audit events have a nullable financial-year field; they remain intact and can be backfilled through a separately authorized reconciliation exercise if required.
- A full production-data duplicate review remains an operational reconciliation activity; this phase deliberately does not merge people automatically.

## Status

`PHASE 12 COMPLETE`

The Phase 12 auditability and data-integrity controls are implemented and statically verified. No Phase 13 or Phase 14 work is included.
