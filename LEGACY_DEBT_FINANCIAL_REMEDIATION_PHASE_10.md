# Legacy Debt Financial Remediation — Phase 10

## Status

`PHASE 10 COMPLETE`

Phase 10 remediated the financial-integrity defects identified by the Phase 9 audit. Verification was performed in the isolated `legacy_test_phase10` database. No production data was changed.

## Defects remediated

### Closed-year authorization

Closed-year payments and manual interest/penalty postings now require a server-side authorization that is:

- linked to the legacy account and financial year;
- bound to the operation and exact amount;
- requested and approved by different users;
- approved for the permitted closed year;
- consumed once after the transaction is inserted.

The database trigger rejects fabricated, pending, mismatched, self-approved, and replayed authorizations. The API exposes request and approve actions through the existing permission model.

Existing maker/checker action requests remain the authorization source for reversals and adjustments. Their approved request, account, year, posting type, amount, and target transaction are checked by the database before a closed-year ledger entry is accepted.

### Migration accounting

Migration now posts an immutable `MIGRATION` credit to the legacy ledger for the approved transferred balance. The legacy account and all historical entries remain available, while the legacy ledger’s collectible balance becomes zero and the linked normal loan carries the collectible exposure.

The migration ledger entry is linked through the migration reference and normal-loan application reference. It is created in the same database transaction as the normal loan and relationship updates.

### Payment reversal and Collections Funding Account

An approved legacy payment reversal now creates both:

1. an immutable debtor-side `PAYMENT_REVERSAL` transaction; and
2. a `refund` transaction and matching balance reduction in the linked Collections Funding Account.

Both operations execute inside the existing transaction. A missing funding link, missing funding account, duplicate refund, or funding write failure rolls back the debtor-side reversal as well.

### Duplicate migration protection

Migration request and execution now lock the legacy account and inspect:

- an existing direct normal-loan link;
- PF number;
- national ID;
- reliable existing user linkage;
- existing migration relationships.

An ambiguous or inconsistent identity match is refused for manual reconciliation. A direct link must exist and have the approved balance when it is reused. A database uniqueness constraint prevents one normal loan from being linked to multiple legacy accounts.

### Reporting and double counting

`legacy_dashboard.php` now reports active collectible legacy balance separately from migrated historical balance. Migrated legacy accounts contribute to historical visibility and migrated counts, but not to the active collectible legacy total.

The Phase 10 `v_unified_loans` definition includes normal loans once and includes only legacy accounts that are not marked `MIGRATED`. This keeps the migrated normal exposure visible without presenting the legacy historical balance as a second collectible loan.

## Files changed

- `api/legacy_ledger.php`
  - validates post-close authorization;
  - adds post-close request/approval actions;
  - reverses the linked funding-account effect with the debtor reversal.
- `api/legacy_migration.php`
  - checks existing normal-loan identity matches;
  - refuses ambiguous or inconsistent matches;
  - posts the immutable migration ledger entry.
- `api/legacy_dashboard.php`
  - separates active collectible and migrated historical balances.
- `api/funds.php`
  - reports funding refunds and net transaction totals.
- `scripts/legacy_financial_remediation_phase10.sql`
  - adds post-close authorization tables, constraints, triggers, permissions, reversal uniqueness, and the corrected unified-loan view.
- `tests/legacy_financial_integrity_phase10_test.php`
  - verifies the Phase 10 financial invariants in an isolated database.

## Database changes

The additive migration creates:

- `legacy_post_close_authorizations`;
- `legacy_post_close_action_consumptions`;
- immutable authorization triggers;
- a unique `(parent_transaction_id, transaction_type)` key for payment reversals;
- `legacy.post_close.request` and `legacy.post_close.approve` permissions;
- a corrected `v_unified_loans` view;
- a unique link constraint for normal loans attached to legacy accounts.

Apply the SQL after Phases 1–7, first against a backup or isolated database:

```text
scripts/legacy_financial_remediation_phase10.sql
```

The SQL is additive. It does not delete debtors, accounts, transactions, payments, migrations, or audit history.

## Accounting semantics

The legacy ledger remains the historical source of truth. Migration does not rewrite old entries. The new `MIGRATION` credit is a compensating transfer entry that removes the legacy collectible exposure after the normal loan is created for the same approved amount. Historical ledger entries remain visible for reconciliation.

The balance relationship is:

```text
legacy historical ledger + migration transfer credit = zero collectible legacy balance
normal loan balance = approved transferred collectible balance
```

Payments dated before the migration date continue to post to the legacy ledger. Payments on or after the migration date continue to route to the linked normal loan.

## Tests performed

PHP syntax checks passed for all changed PHP files.

The isolated Phase 10 invariant suite passed:

- fake closed-year authorization rejected;
- unapproved authorization rejected;
- self-approval rejected by the database;
- amount mismatch rejected;
- valid authorization consumed exactly once;
- authorization replay rejected;
- debtor and funding balances reconciled after reversal;
- duplicate reversal rejected by the database;
- migration created a `MIGRATION` ledger entry;
- legacy history remained present;
- migrated collectible balance appeared once in the normal loan;
- the unified view omitted the migrated legacy collectible row;
- one permanent migration relationship remained.

The existing isolated legacy tests also passed:

- Phase 2 ledger tests;
- Phase 3 import tests;
- Phase 4 RBAC tests;
- Phase 5 migration tests.

## Remaining risks

- The full Phase 13 regression suite and Phase 14 independent audit remain outside this phase.
- The production database still requires deployment-specific least-privilege credentials; that was explicitly excluded from Phase 10.
- Ambiguous identity matches are refused and require a later reconciliation workflow; this phase does not invent that workflow.
- The additive SQL migration must be applied to the target database before the new endpoints and triggers are available there.
