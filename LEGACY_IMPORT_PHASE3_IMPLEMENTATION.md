# Phase 3 Legacy Import and Existing-Data Migration

## Status

Phase 3 server-side import and migration foundation implemented.

The Phase 3 structural migration has been applied to loan_management. No outstanding_debtors rows were copied or deleted because the current source table contains zero rows.

## Files

- api/legacy_import.php
- scripts/legacy_import_phase3.sql
- secure/legacy-imports/.htaccess
- LEGACY_IMPORT_PHASE3_IMPLEMENTATION.md

## Import API

All operations require authentication and CSRF protection.

### Upload

POST api/legacy_import.php?action=UPLOAD

Uses multipart form data:

- financial_year_id
- source_file
- csrf_token

Only Admin and Cashier may upload.

Accepted formats:

- CSV
- XLS
- XLSX

Controls:

- 25 MB size limit.
- Extension allow-list.
- MIME/content verification.
- SHA-256 source hash.
- Filename basename normalization.
- Randomized private storage filename.
- Permanent storage under secure/legacy-imports.
- Public directory listing disabled.
- Apache access denied through .htaccess.
- Duplicate source hash/year rejected by a database unique key.

The upload creates a batch only. It does not create financial records.

### Validate

POST api/legacy_import.php?action=VALIDATE

JSON:

    { "import_batch_id": 123, "csrf_token": "..." }

The source is parsed from the retained private file. Row-level records are created in legacy_import_records with:

- normalized identity
- source reference
- amount
- original source row data
- validation state
- error text

The validation phase does not create debtor accounts or ledger entries.

### Confirm

POST api/legacy_import.php?action=CONFIRM

Confirmation is required after preview and before posting. Only validated batches with valid rows may be confirmed.

### Post

POST api/legacy_import.php?action=POST

Posting:

1. Locks the batch.
2. Requires confirmed/previewed state.
3. Rejects closed-year imports.
4. Rechecks valid rows.
5. Resolves or creates the debtor identity.
6. Reuses the one existing account for that debtor.
7. Creates a legacy loan component.
8. Creates an OPENING_BALANCE ledger transaction.
9. Links the import record to debtor/account.
10. Updates batch counts.
11. Writes an audit event.
12. Commits atomically.

A failed post rolls back the transaction. Existing source files and batch records remain available for investigation.

## Duplicate detection

Name alone is never used as a hard duplicate key.

Rules:

- Exact PF plus national ID can resolve an existing identity.
- PF-only or national-ID-only partial matches become POSSIBLE_DUPLICATE when an existing identity is found.
- An exact existing identity may receive a new loan component only when a distinct source reference is supplied.
- Existing source references become DUPLICATE.
- Repeated rows within the same batch are detected.
- A repeated source file for the same financial year is rejected by source hash/year uniqueness.
- Rows without a reliable identity are INVALID.
- Identity conflicts are not silently merged.

## Existing-data migration

POST api/legacy_import.php?action=MIGRATE_EXISTING

Admin only. This operation:

- Reads outstanding_debtors.
- Leaves every original row untouched.
- Resolves the configured financial-year label.
- Uses OUTSTANDING_DEBTOR:<id> as the durable source reference.
- Creates or reuses a normalized debtor.
- Creates or reuses the one account.
- Creates a loan component.
- Posts an opening-balance transaction.
- Attempts to link an existing loan_application using loan_application_id.
- Creates a historical legacy_migrations link where a reliable target exists.
- Flags missing loans and conflicting multiple links for review.
- Uses per-row savepoints so one bad source row does not corrupt other rows.
- Returns migrated, skipped, and error results.

The migration is idempotent through the durable source component reference. It never deletes or marks the original source row.

## Reconciliation

POST api/legacy_import.php?action=RECONCILE_EXISTING

Admin and Accountant only.

A reconciliation run compares each outstanding_debtors row against:

- normalized debtor/account linkage
- source component
- opening ledger amount

Results are persisted in:

- legacy_reconciliation_runs
- legacy_reconciliation_results

Possible result states:

- MATCHED
- MISSING_NORMALIZED
- MISSING_SOURCE
- AMOUNT_MISMATCH
- IDENTITY_MISMATCH
- DUPLICATE_REVIEW

Mismatches are reported, not silently corrected.

## Security

- Source files are stored outside normal public application paths.
- secure/legacy-imports/.htaccess denies direct Apache access.
- No arbitrary source-file download endpoint is provided.
- Upload names are never used as filesystem paths.
- Extension, MIME, size, and hash checks are performed.
- All state changes require authenticated roles and CSRF.
- Prepared statements are used.
- Financial posting occurs inside a database transaction.
- Existing source records are never physically deleted.

For non-Apache deployments, the web server must independently deny access to secure/legacy-imports.

## Compatibility

The existing legacy endpoints remain unchanged in this phase. They are not yet switched to the new import API.

The existing outstanding_debtors table remains available for:

- compatibility
- reconciliation
- historical verification
- future controlled migration

The new API is the controlled path for new Phase 3 imports.

## Validation performed

- PHP syntax validation passed for api/legacy_import.php.
- Phase 3 SQL applied successfully to loan_management.
- legacy_reconciliation_runs exists.
- legacy_reconciliation_results exists.
- Source-hash/year uniqueness index exists.
- outstanding_debtors count is currently zero.
- normalized legacy account count is currently zero.
- normalized legacy transaction count is currently zero.
- No existing financial records were migrated or deleted.

## Phase boundary

UI integration, replacement of old import endpoints, automated browser upload tests, and full historical migration review remain future work. The existing-data migration action is implemented but must be run only after financial-year labels and identity data have been reviewed.

