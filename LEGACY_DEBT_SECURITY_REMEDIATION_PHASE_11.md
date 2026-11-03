# Legacy Debt Security Remediation — Phase 11

## Scope

This phase addressed the security findings identified in the Phase 9 review and the normal-loan endpoints that expose the same records. Financial ledger semantics were not changed.

## Implemented controls

### Normal-loan IDOR and PII exposure

Affected files: `api/loan_applications.php`, `api/loans.php`, `api/payments.php`, and `api/payment_schedule.php`.

Loanee reads now use the authenticated database-backed `users.user_id` as the ownership boundary. PF number and national ID are no longer accepted as alternate ownership proofs. Staff roles are read from the active database user record rather than trusted from a client-controlled session role value. New applications are linked to the authenticated user.

### CSRF

Normal-loan application mutations require a valid session CSRF token. Existing loan, payment, and schedule mutation routes retain their server-side token checks. Missing and invalid tokens are rejected before the mutation is processed.

### Production database credentials

When `APP_ENV=production`, configuration now fails closed unless all database settings are explicitly provided. Root and blank-password credentials are rejected. Development defaults remain available only when the application is explicitly running outside production.

### Error leakage

The affected loan-application and payment endpoints now return stable generic messages while technical details remain in server logs. Configuration failure does not return database credentials or connection details.

### Source-file protection

The private `secure` directory now denies static access through its own access rule. Legacy source files retain generated names and are stored under the private import directory. A deployment must keep this directory outside the public document root where the web server supports that layout.

### Destructive data deletion

`api/clear-data.php` is disabled with HTTP 410. It can no longer delete logs, users, loans, funding records, or other data through a browser request.

### Cross-origin exposure

Wildcard credentialed CORS headers were removed from the affected normal-loan APIs. Session-authenticated browser APIs are expected to be called same-origin.

## Tests

Passed PHP syntax checks for all changed PHP files.

Passed static Phase 11 checks in `tests/security_phase11_test.php`.

Passed local unauthenticated smoke requests:

- `/api/loan_applications.php` returned HTTP 401;
- `/api/clear-data.php` returned HTTP 410.

Passed authenticated horizontal-access tests in the isolated `legacy_test_phase10` database using a loanee session that did not own the target record:

- `/api/loan_applications.php` returned an empty owner-scoped result;
- `/api/loans.php?id=P11-IDOR-TEST` returned HTTP 404 with no PHP warning or fatal-error page;
- `/api/payments.php?application_id=P11-IDOR-TEST` returned HTTP 403;
- `/api/payment_schedule.php?application_id=P11-IDOR-TEST` returned HTTP 403;
- a missing CSRF token on a loan-application mutation returned HTTP 403;
- a valid CSRF token permitted the test-database mutation.

The isolated live run also confirmed the disabled bulk-delete endpoint and did not modify the production database.

Passed production configuration smoke test: with `APP_ENV=production` and missing credentials, configuration terminated with `Application configuration is unavailable` and did not connect using a root/blank-password fallback.

## Residual risks

- Other legacy administrative endpoints outside the normal-loan and Phase 11 findings may require later review.
- Apache access rules must be preserved in deployment; storage outside the public document root is preferred.
- A complete production-like concurrency/load assessment and a dedicated source-file download test should still be run in the deployment environment.
- Phase 12, Phase 13, and Phase 14 remain separate work.

## Status

`PHASE 11 COMPLETE`

The identified Phase 11 defects were remediated and the required isolated authenticated ownership, CSRF, configuration, error-handling, and destructive-endpoint checks passed. Remaining risks are documented above and are not unresolved critical financial-integrity vulnerabilities in the remediated scope.
