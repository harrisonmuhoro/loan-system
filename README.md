# Loan Management System

A PHP/MySQL web application for managing staff loan applications, approvals, disbursements, repayments, funding, notifications, users, reports, and legacy debtor records.

The application is designed to run locally under Apache, such as WampServer, and uses Nairobi time (`Africa/Nairobi`).

## Main features

- Staff and loanee loan applications
- Mortgage and car loan workflows
- Admin, cashier, accountant, and loanee portals
- Loan approval, rejection, forwarding, dispatch, and closure
- Payment recording and payment schedules
- Funding account and funding transaction management
- Notifications and activity logging
- CSV/Excel import support
- Legacy debtor import, reporting, export, and migration to normal loans
- Database backup and restore endpoints
- CSRF-protected authenticated API requests

## Technology stack

- PHP 7.4+
- MySQL/MariaDB
- Apache with PHP enabled
- PDO for database access
- JavaScript and browser Fetch API
- CSS with responsive portal styles
- PhpSpreadsheet for Excel files
- Smalot PDF Parser for PDF parsing

## Project structure

```text
api/          JSON API endpoints
classes/      Domain/service classes
config/       Database and application configuration
css/          Portal stylesheets
helpers/      Shared response and notification helpers
js/           Portal JavaScript
lib/          File parsing and shared libraries
middleware/   Request middleware and rate limiting
models/       Data models
pages/        Admin, cashier, accountant, and loanee portal pages
scripts/      Maintenance and database scripts
tests/        Existing test utilities
*.sql         Database schemas and views
index.php     Login entry point
composer.json PHP dependencies
```

## Portals and roles

### Admin

The Admin portal manages users, settings, reports, activity, loans, and Legacy Debtors.

### Cashier

The Cashier portal processes loan applications, imports legacy debtors, and can initiate migration of outstanding debtors into loan applications.

### Accountant

The Accountant portal reviews applications, processes accounting decisions, records payments, and views debtor-related information.

### Loanee

The Loanee portal allows staff to apply for loans and view relevant application or repayment information.

Access is controlled by the session role and API authorization checks. The exact permissions are enforced in both the portal JavaScript and PHP API endpoints.

## Local installation

### Requirements

- WampServer or another Apache/PHP/MySQL environment
- PHP 7.4 or newer
- MySQL/MariaDB
- Composer
- PHP extensions required by PDO, PhpSpreadsheet, and PDF parsing

### 1. Place the project in the web root

For WampServer, place the project at:

```text
D:\wamp64\www\loan-system
```

The local URL is normally:

```text
http://localhost/loan-system/
```

### 2. Create the database

Create a database named `loan_management`, then import one of the supplied schemas:

- `loan_management_schema.sql`
- `loan_management_schema_fixed.sql`
- `loan_management_schema_mysql8.sql`

Use the schema that matches the installed MySQL/MariaDB version. The schemas create the main tables, indexes, foreign keys, and reporting views.

### 3. Configure the database

Update `config/config.php` for the local database server:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'loan_management');
define('DB_USER', 'root');
define('DB_PASS', '');
```

Set the environment appropriately during development:

```php
define('ENVIRONMENT', 'development');
```

Use `production` on a deployed server so detailed errors are not displayed.

### 4. Install PHP dependencies

From the project directory:

```bash
composer install
```

This installs PhpSpreadsheet and Smalot PDF Parser. The generated `vendor/` directory is intentionally excluded from Git.

### 5. Start Apache and MySQL

Start Apache and MySQL in WampServer, then open:

```text
http://localhost/loan-system/
```

Register or provision an initial user, then sign in through the main login page.

## Important configuration notes

- The application timezone is configured as `Africa/Nairobi`.
- The application expects MySQL connections using `utf8mb4`.
- Sessions and CSRF tokens are used throughout the authenticated portals.
- Apache must allow `.htaccess` overrides for the included security and error-page rules.
- The application writes runtime logs to `logs/` and other portal-specific log directories.

## Legacy debtor workflow

Legacy debtors are stored in the `outstanding_debtors` table.

1. A Cashier selects a financial year.
2. A CSV or Excel file is uploaded.
3. The browser validates and previews the records.
4. `api/debtors.php` validates the request and inserts or updates records.
5. Admin can view totals grouped by financial year.
6. Admin or Cashier can migrate pending records to `loan_applications`.

The importer uses `(financial_year, name)` as the database uniqueness key. Re-importing the same debtor and year updates the existing record.

The main files for this workflow are:

- `pages/admin.php`
- `pages/cashier.php`
- `js/admin.js`
- `js/cashier.js`
- `api/debtors.php`
- `api/migrate_outstanding_to_loans.php`

Review the migration behavior before using it in production. The current migration endpoint creates a normal loan record and removes the original outstanding-debtor row after migration. If historical visibility is required, retain the row and use `migrated_to_loan` and `loan_application_id` as the linkage.

## API overview

The `api/` directory contains JSON endpoints for:

- Authentication and logout
- Loans and applications
- Payments and schedules
- Debtor import and migration
- Funding
- Notifications
- Users and staff
- Activity logs
- Settings and preferences
- Backup, restore, and diagnostics

Most state-changing requests require a valid authenticated session and CSRF token. API responses generally use a structure similar to:

```json
{
  "success": true,
  "message": "Operation completed",
  "data": {}
}
```

## Database model highlights

Important tables include:

- `users`
- `loan_applications`
- `payments`
- `payment_schedules`
- `outstanding_debtors`
- `funding_accounts`
- `funding_transactions`
- `notifications`
- `activity_log`
- `api_keys`
- `system_settings`

Reporting views are also defined in the SQL schema, including unified loan and active-loan views.

## Security and deployment checklist

Before deploying:

- Change the database credentials.
- Rotate any API keys or secrets that have ever been committed.
- Keep `.env`, `secure/`, logs, uploads, and backups outside version control.
- Enable HTTPS and set secure session cookies.
- Use a dedicated database user instead of MySQL `root`.
- Set `ENVIRONMENT` to `production`.
- Disable public access to backup, restore, diagnostic, and maintenance endpoints unless explicitly required.
- Review Apache permissions and prevent directory listing.
- Create a tested database backup before schema or migration changes.

## Development checks

Useful checks include:

```bash
composer install
git status
```

For PHP syntax checks:

```bash
php -l path/to/file.php
```

Test the complete workflow after changes: login, role access, loan creation, approval, payment entry, debtor import, debtor migration, export, and logout.

## Current repository notes

- The repository contains both source code and local/runtime artifacts from development.
- Existing tracked files are not automatically removed by `.gitignore`; sensitive files already committed must be removed from Git tracking separately.
- The database schemas are source artifacts and should remain versioned.
- Avoid committing generated logs, uploaded files, database dumps, credentials, or dependency directories.


