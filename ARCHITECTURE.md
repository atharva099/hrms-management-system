# Architecture

CoreAxis CRM is a server-rendered PHP application using MySQLi. Shared authentication/session and CSRF controls live in `includes/auth.php`; deployment connection handling is in `includes/db.php`. Attendance actions in `attendance.php` use `includes/attendance_workflow.php` and `includes/attendance_time.php`. Navigation and page styles are shared through `includes/navigation.php` and `css/`.

The college core schema is documented in `DATABASE_SCHEMA.md` and installed, on an operator-selected empty MySQL 8 schema, with `database/schema_mysql8.sql`. That script contains only the nine core table definitions and four append-only audit triggers. It does not seed accounts, configure credentials, create a database, or install the optional payroll stage. A fresh import has not been executed in this publication worktree.

The core Payroll pages show historical salary records and reports. Salary generation/payment remain disabled. `includes/payroll_calculation.php`, the stage-only database adapter, policy administration, and preview are separate from the legacy Salary UI and are not established as a deployed payment workflow. The policy/preview pages require their own stage configuration/schema, which is not included in the college installer.

## College release verification — 2026-11-04

The integrated source has 27/27 portable database-free tests passing, 91/91 PHP files linting, and a passing `git diff --check`. Six integration/review contracts are classified separately and were not run because their external setup or migration sources are excluded. These results are source checks, not fresh-install or live-database verification.

Admin read-only browser evidence covers core pages; Employee evidence confirms Dashboard and Employee-specific navigation. A complete Employee page-by-page browser walkthrough remains unverified. The operator reports five manual inactive-account checks for EMP9902 passed; that remains user-observed, not an automated or independent retest. See `PROJECT_STATE.md` and `TESTING.md` for limitations.