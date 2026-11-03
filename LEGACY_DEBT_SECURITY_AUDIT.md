# Legacy Debt Security & Financial Integrity Audit — Phase 7

## Scope

Audited the Phase 1–6 legacy debt subsystem, including the ledger, imports, migration workflow, RBAC, dashboard, payment routing, audit history, retained source files, and retired legacy endpoints.

## Current result

`PHASE 7 COMPLETE`. The code-level bypasses are closed, the Phase 7 SQL is active in production, and the same controls passed in the isolated test database.

## Findings

### CRITICAL — resolved in code

Older endpoints could bypass the controlled workflow:

- `api/debtors.php`
- `api/import-debtors.php`
- `api/import-legacy-loans.php`
- `api/migrate_outstanding_to_loans.php`

Some of these paths could create direct normal loans, delete source debtors, or delete imported legacy loans. They now return HTTP 410 and direct users to the controlled Phase 3–5 workflow.

### HIGH — resolved

The Phase 7 database hardening triggers are implemented in `scripts/legacy_security_phase7.sql` and verified in the isolated test database. They prevent:

- deleting or modifying posted legacy transactions;
- deleting or modifying posted payment details;
- deleting action requests or migration history;
- modifying or deleting audit events;
- changing retained import source metadata;
- deleting import batches.

The script is now imported into `loan_management`; all nine required immutability triggers were confirmed active during final validation.

### MEDIUM — controlled / documented

- Source-file protection uses Apache `.htaccess`; production must serve `secure/legacy-imports` through Apache or an equivalent deny rule. The application stores randomized filenames and does not expose a download endpoint.
- The application database account should not use MySQL `root` in production. Use a least-privilege account that cannot alter triggers or schema.

### LOW / INFORMATIONAL

- The legacy UI uses inline JavaScript because the existing application does not have a shared frontend build pipeline. Values inserted into the new UI are HTML-escaped before rendering.
- Excel MIME validation allows the standard XLSX ZIP MIME type; PhpSpreadsheet remains the parser authority and malformed workbooks fail validation.

## Attack tests performed

- Unauthenticated and unauthorized access checks through permission-gated APIs.
- Database-backed role resolution rather than trusting the session role or request role.
- Loanee permission denial and cashier write-off denial.
- Maker/checker self-approval rejection.
- Duplicate payment and duplicate migration protection.
- Negative/zero amount validation.
- Posted ledger update/delete attempts.
- Executed migration balance/date update attempts.
- Audit update/delete attempts.
- Import batch source-path tampering and deletion attempts.
- Action-request deletion attempt.
- CSRF enforcement on state-changing endpoints.
- Prepared-statement review of new endpoints and dynamic SQL review.
- Path traversal and executable-extension rejection in import handling.
- 25 MB upload limit and MIME/extension matching.
- Migration and payment row-lock review for concurrent posting/migration.
- Import confirmation bypass fixed: posting now requires `CONFIRMED`, not merely `PREVIEWED`.

## Evidence

The isolated database blocked all destructive attempts with database errors, and the Phase 6 dashboard/account/audit API smoke test passed. PHP syntax and `git diff --check` also passed.

## Final validation

- Production trigger inventory passed.
- Retired bypass endpoints returned HTTP 410:
  `debtors.php`, `import-debtors.php`, `import-legacy-loans.php`, and `migrate_outstanding_to_loans.php`.
- PHP syntax and `git diff --check` passed.
