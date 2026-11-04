# CoreAxis CRM Production State

## R5 attendance overnight verification status

Status checked: 2026-10-13

Attendance development checkpoint: `5cbded95ab2e029e6dd4135ac728f5e7cd7db5a0`

### Operator-confirmed read-only forensic result

- The operator reports the corrected read-only comparator returned overall
  PASS (exit code 0) against baseline
  `coreaxis_r5_overnight_forensic_baseline_20261011` and current disposable
  target `coreaxis_r5_attendance_overnight_rerun_20261013_a6d29f4c`.
- The operator confirmed attendance ID 56 matches the expected overnight row.
  Attendance IDs 53–55 and all pre-existing attendance, users, leaves,
  salaries, leave-attendance audit, and attendance-correction audit rows were
  preserved. Schema, indexes, foreign keys, and audit triggers also passed.
- Original source backup SHA-256:
  `4BCE25CCC73B53D23576CEC83C617CFB3CC84CD83857D8B9F384029F94986364`.

### Overnight functional and process status

- The operator previously reported the extracted workflow's functional
  assertions passed for check-in at 2026-10-11 21:50 and checkout at
  2026-10-12 06:05: business date, Sunday Working classification, schedule and
  policy snapshots, 8 hrs 15 mins, row version 1 to 2, and duplicate check-in/
  checkout rejection.
- The forensic PASS independently verifies the expected final row and
  preservation. The earlier PowerShell/PHP integration process exit code was
  not verified; do not report that runner as an overall PASS.
- The current workspace's forensic comparator was adjusted to normalize only
  exact expected integer fields across PHP/MySQL return types and to report
  row-56 mismatch column names. Its database-free tests pass. The local
  comparator was not run from this restricted environment.

### Regression, HTTP coverage, and release limitations

- Seven focused database-free attendance suites passed on 2026-10-13:
  eligibility, schema contract, row-version contract, HTTP workflow contract,
  overnight workflow contract, preservation helpers, and forensic comparison.
  Relevant PHP lint and `git diff --check` passed. These are not authenticated
  HTTP tests.
- Operator-reported authenticated HTTP coverage includes normal login and
  early-window UI/server rejection for a dedicated clone test employee. Duplicate HTTP actions,
  CSRF failure/success, role/ownership authorization, and overnight checkout
  over HTTP remain unverified. Leave-conflict behavior has static contracts
  and separate clone workflow work, but full authenticated HTTP conflict
  coverage remains pending.
- The overnight database-backed workflow operated through the extracted PHP
  workflow in CLI integration, not through an authenticated HTTP session.
- Clone-specific migration candidates currently exist as untracked files under
  `database/migrations/`; they are schema-qualified to
  `coreaxis_crm_runtime_test` and explicitly clone-oriented. They are not
  production deployment migrations and must not be presented or executed as
  such. Production rollout requires separately reviewed production-safe DDL
  and an approved migration procedure.
- No protected database was modified during the current static review. This
  state is not a claim that the application is fully production-ready.

## Payroll integrity exception — duplicate salary-period records

Status checked: 2026-10-03

### Technically verified facts

- A duplicate salary-period business key exists in the database, and both records are marked `Paid`.
- The stored amounts differ. Employee identifiers, payroll periods, record identifiers, and exact amounts are intentionally omitted from this project-state document.
- Available technical/repository evidence does not establish which record is authoritative or explain why both exist.

### Unresolved business decision

The payroll/business owner must reconcile both records against approved payroll and payment evidence and explicitly decide how they should be treated. No technical inference from the amounts, timestamps, or payment status is a substitute for that decision.

Neither record may be deleted, merged, or otherwise resolved without explicit payroll/business-owner reconciliation.

### Blocked database constraint

A `UNIQUE` constraint on `(employee_id, salary_period)` is intentionally blocked while this duplicate remains unresolved. This is a known production-hardening blocker/exception.

### Next required action

Obtain and document the payroll/business owner's reconciliation decision. Only after that decision is implemented through an approved data-resolution process should the duplicate check be repeated and the unique constraint reconsidered.

## R5.1 staging verification and release gate update

Status checked: 2026-10-13

- The operator reports the disposable payroll staging migration and its
  database-backed integration passed, including employee/period uniqueness,
  legacy label CHECK behavior, report and payslip period compatibility, and
  transaction rollback. This is staging evidence, not production migration
  approval.
- The earlier staging duplicate fixture was replaced only in the disposable
  staging environment. Historical duplicate payroll rows in the original
  database/backup remain a separate reconciliation concern; this update does
  not claim they were resolved.
- A database-free payroll review found fixed per-event attendance deductions
  and a Paid transition without payment-transaction evidence. Salary
  generation and payment release are now blocked in the application pending
  approved payroll rules and an auditable payment workflow. Read-only salary
  history remains available. This is a protective release gate, not a finished
  payroll implementation.
- The local database bootstrap still uses a privileged local configuration.
  Production must receive a least-privilege, externally managed connection
  configuration before deployment.
- Clone/staging-specific SQL under `database/migrations/` is not production
  migration material. A separate reviewed production migration package,
  preflight, backup, rollback plan, and execution approval are still required.
- Authenticated HTTP and production deployment checks remain incomplete. The
  overnight forensic preservation PASS is operator-confirmed; the earlier
  integration process exit code remains unverified.

See `RELEASE_READINESS.md` for the current prioritized blocker register and
five-day completion sequence.

## Payroll/leave hardening batch — 2026-10-13

- Added a database-free monthly payroll calculation contract that operates on
  authoritative normalized inputs, exact integer cents, an explicitly
  approved full-period policy, explicit paid/unpaid leave classification,
  calendar rules, and a complete per-day input set. It rejects inactive
  employees, absent currency, missing/inactive shifts, pending or overlapping
  leave, attendance conflicts, unclassified leave types, incomplete dates,
  and duplicate employee-period calculations.
- Added payment-event transition validation and payment-status derivation.
  A payment can be considered settled only with a real external reference;
  the helper does not initiate transfers or mark legacy salary rows Paid.
- Added stage-only R5.1c additive migration candidates for effective-dated
  compensation, employee work schedules, versioned policy/rules/leave
  classification, calculation snapshots, and immutable payment
  transaction/event history. Its preflight and postflight are read-only. No
  migration was executed and no configuration rows were seeded.
- `salary.php` generation and payment release remain server-side disabled
  (HTTP 503 after Admin/POST/CSRF checks). The legacy fixed-deduction code is
  not an approved policy and must not be re-enabled.
- The deterministic calculator is connected to a stage-pinned, read-only
  database preview adapter and Admin-only, CSRF-protected configuration for
  effective-dated compensation, work schedules, policy rules, leave
  classifications, and currency. The adapter requires complete schema and
  constraint gates, complete source data for every day, and one applicable
  version; missing attendance is never inferred as absence. Configuration and
  preview remain unusable outside the exact authorized stage database.
- Database-backed calculation snapshot persistence/approval and payment-event
  recording are not implemented. `salary.php` generation and payment release
  remain disabled. The candidate migration was not executed, so the adapter,
  configuration, and preview have static verification only and are not
  runtime-verified.
- Previous Sprint 1 verification: 13 database-free suites and 64 PHP lint
  checks passed. Sprint 2's initial static run passed all 15 repository
  `tests/*_test.php` suites and PHP lint for all 71 PHP files.
- No database, HTTP endpoint, migration, or payroll mutation has been accessed
  in this Sprint 2 batch.
- `git diff --check` passed; Git reported only LF-to-CRLF working-copy warnings.
  Static verification does not establish MariaDB migration or authenticated
  HTTP behavior.

## Sprint 3 runtime verification attempt — 2026-10-13

- A read-only invocation of the native XAMPP MariaDB client against
  `127.0.0.1:3307` exited with code 1 and MariaDB client error 2002 / Windows
  socket error 10013. This execution environment cannot open the local TCP
  connection; the staging identity and schema were not independently verified.
- The operator-named stage dump is present at
  `C:\xampp\coreaxis_r5_payroll_stage_backup_20261013.sql` (SHA-256
  `BC8054410C736506EC4FBD3A34849C6437C013584C173973A73D24D10394E3C6`).
  Static inspection found eight table definitions but no trigger or routine
  definitions. The operator now reports zero triggers in the stage, so this
  dump inventory may match the current stage; restore recoverability and
  whether attendance audit guards are expected remain unverified.
- No migration, backup, database write, integration runner, or HTTP test was
  executed. The stage migration remains NOT RUN; partial-state status is
  unknown. Operator-side read-only identity/preflight and a verified stage
  backup are required before DDL.
- Static migration review found incomplete adapter FK verification and missing
  policy creator/approver FKs plus missing DB-level distinct-approver checks.
  The candidate was updated to 17 restricted FKs while retaining 9 tables and
  27 triggers. The adapter now verifies each FK target/action and the exact
  legacy salary unique-index columns. This update remains unverified on
  MariaDB; rerun preflight before any authorized DDL.
- Final database-free run after this review passed all 16 repository
  `tests/*_test.php` suites, PHP lint for all 72 PHP files, and
  `git diff --check`. Git emitted only LF-to-CRLF working-copy warnings.

R5.1c migration final review (2026-10-13): the stage-only candidate remains
unexecuted. Static review retained 9 tables, 17 restricted foreign keys, and
27 distinct approval, transition, immutability, and append-only guards. It now
requires one full-value payment transaction per salary, distinct policy
creator/approver IDs, and enabled MariaDB CHECK evaluation in the preflight,
postflight, and read-only adapter. The supplied dump hash is
`BC8054410C736506EC4FBD3A34849C6437C013584C173973A73D24D10394E3C6`, and it
lacks trigger/routine definitions. The operator reports the current stage has zero
triggers, matching the inspected dump's zero-trigger inventory; whether the
attendance audit guards are expected in this particular stage remains to be
confirmed, and the dump's restore has not been verified. Migration execution
is NO-GO pending a verified separate restore test and fresh exact-stage
preflight. The payroll migration does not reference or alter attendance audit
objects. No database connection or write occurred in this review.

## Mega sprint verified state — 2026-10-14

- The current working tree was preserved; no database connection, migration,
  restore, HTTP request, commit, or push was performed in this task.
- The shared database bootstrap no longer embeds the local `root`/blank
  password fallback. It accepts required `COREAXIS_DB_*` values or an
  operator-managed PHP config file outside the Apache document root; it
  rejects `root`, validates database/port input, uses `utf8mb4`, and returns
  generic failure responses. Runtime startup still requires a configured
  dedicated account; connectivity has not been tested.
- Authenticated sessions now expire after 30 minutes of inactivity; malformed
  or future activity timestamps fail closed. This has database-free contract
  coverage but has not been tested through a live browser session.
- Existing static coverage exercises shift-aware attendance, overnight
  checkout, row versions, leave conflict contracts, security output/SQL/CSRF
  contracts, payroll calculation/policy contracts, and migration static
  assertions. These do not verify live HTTP or MariaDB behavior.
- Payroll generation and payment release remain disabled. The stage-only
  payroll migration was not executed in this task; runtime and recovery
  readiness remain unverified. Approved payroll/leave rules, persisted
  calculation approval, external payment evidence, and production migrations
  remain blockers.
- Operator-reported overnight forensic preservation remains PASS. The earlier
  integration process exit code remains unverified. Authenticated overnight
  checkout and full attendance/leave HTTP coverage remain pending.
- Release readiness is NOT READY for production. See `RELEASE_READINESS.md`,
  `SECURITY.md`, `DATABASE_SCHEMA.md`, and `PROJECT_ROADMAP.md`.
- Verification on 2026-10-14 after these changes: all 18 database-free
  `tests/*_test.php` suites passed, PHP lint passed for all 74 PHP files, and
  `git diff --check` passed. Database integration, migrations, and authenticated
  HTTP tests were not run.

## Sprint 2 targeted lifecycle hardening — 2026-10-14

- Employee deactivation now locks the employee row, checks for an open
  attendance record, and refuses the status change until that check-in is
  closed or reviewed. The check-in workflow also locks the employee row, so a
  new check-in cannot race through the deactivation check. This prevents
  stranding an employee who can no longer authenticate to check out.
- Shift assignment and deletion now use the same shift-first transaction lock
  order. Deletion uses bound IDs, verifies the shift is unassigned while
  holding its lock, and reports success only after one row is deleted and the
  transaction commits.
- All 21 database-free repository suites passed; PHP lint passed for all 77
  PHP files and `git diff --check` passed on 2026-10-14. No database or
  migration was run. Authenticated HTTP tests remain unexecuted because the
  local loopback attempt is blocked by Windows socket error 10013 here.
- These targeted changes do not certify cross-module runtime behavior.
  MariaDB constraint coverage, authenticated employee/shift/leave workflows,
  attendance correction authorization, and production-safe migration review
  remain release blockers. Payroll generation and payment release remain
  disabled pending their documented policy, migration, and runtime gates.

## Sprint 3 runtime/payroll checkpoint — 2026-10-14

- Fixed a PHP parse error in the stage payroll adapter's attendance SELECT.
  The query had an unmatched double quote at the end of the SQL string, so the
  adapter could not load at runtime. Added a contract assertion for its query
  concatenation and locking suffix.
- Added exact signed-decimal-to-cents conversion and a stage-only draft /
  independent-approval persistence helper. Draft creation fingerprints inputs,
  writes the legacy salary row and immutable calculation snapshot atomically,
  and rejects non-zero adjustment categories that legacy salary columns cannot
  represent. Approval locks and rechecks the salary row and recalculates the
  source fingerprint before allowing a different active Admin to approve.
  These writes are not exposed by a page or enabled in `salary.php`.
- Added database-free payroll workflow and local runner contracts. The local
  PowerShell runner is pinned to the synthetic HTTP test DB and loopback port,
  checks the external config path and DB identity, prompts for test passwords
  securely, and can smoke-test Admin/Employee login, role denial, invalid-CSRF
  rejection, and logout. It does not create/migrate databases or invoke the
  existing integration writers.
- The runner and workflows have not been executed against MariaDB or HTTP in
  this environment. Session-expiration timing, valid attendance/leave writes,
  employee lifecycle, shift mutation, payroll persistence/approval, and payment
  event persistence remain unverified. The existing database-backed runners
  are pinned to other database names, so they are not called by the synthetic
  runtime runner.
- Payroll remains NOT ENABLED. Payment event rules are present in the
  calculator and migration candidate, but there is no safe ledger-to-legacy
  salary report/payslip integration yet, and the stage-only migration has not
  been deployed to the application DB. No payment is represented as complete.
- Final static verification for this checkpoint: all 23
  `tests/*_test.php` suites passed; PHP lint passed for 80 PHP files;
  `git diff --check` passed. Windows PowerShell 5.1 parser accepted the
  operator runner, and its database-free contract suite passed. No database
  connection, HTTP request, migration, restore, commit, or push occurred.

## Sprint 4 runtime/payroll checkpoint — 2026-10-14

- Corrected payroll presentation so salary slips show the payment status and
  only the payment date recorded on the salary row. Pending rows no longer
  display the current date as if a payment had occurred. Invalid or absent
  dates are shown as not recorded.
- Corrected salary report summary labels and metrics: paid payroll and paid
  employee count are separated from pending payroll and total employees with
  payroll records. Each report row now shows payment status and stored payment
  date. These views still read legacy `salaries` rows; they are not yet backed
  by the candidate payment ledger.
- Added a database-free payment-presentation contract suite. All 24 repository
  `tests/*_test.php` suites passed, PHP lint passed for all 82 PHP files, and
  `git diff --check` passed after the presentation change.
- The read-only runtime verification runner remains pinned to the synthetic
  HTTP target. No application database config, synthetic schema, fixture
  users, or isolated Apache instance was verified in this task. No database
  connection, database creation, migration, authenticated HTTP request, or
  write-capable integration test was performed.
- Draft persistence and independent approval remain implemented only in the
  stage-pinned workflow and have not been run against MariaDB. Payment event
  transition rules have pure calculation tests, but durable payment-event
  persistence and payment-rail evidence verification are incomplete. The
  payroll migration candidate remains unexecuted; payroll generation and
  release remain disabled. Sprint 4 is INCOMPLETE and production release is
  blocked pending approved rules, migration/runtime integration, and an actual
  payment-evidence integration.

## Development workflow baseline — 2026-10-15

- The unchanged enterprise readiness audit is preserved at
  `docs/audits/CoreAxis-Enterprise-Readiness-Audit-20261015.md`; source SHA-256:
  `9398FE9F542FC189E205DB96B171A5EF8F8A5FDD196483BAD509442B299862B0`.
- Mandatory workflow and promotion rules are in `DEVELOPMENT_RULES.md`.
- The protected running checkout remains `C:\xampp\htdocs\coreaxis_crm_R5_WORKING`.
- At this checkpoint the live Apache project route is scoped to the MySQL 8
  development database `coreaxis_crm_dev_http_20261014_6b0f9a21` on
  `127.0.0.1:3306`, using restricted account `coreaxis_dev_http_20261014`.
  No password is recorded here. The original `coreaxis_crm` database was not
  used or modified.
- The in-app browser displayed the application login form, and an anonymous
  request for `dashboard.php` redirected to the login page. Authenticated Admin
  and Employee workflows were not exercised in this setup task.
- Separate source development is established at
  `C:\Users\ATC\AppData\Local\CoreAxis\R5\worktrees\development-20261015`
  on branch `codex/r5-development-20261015`, clean at baseline commit
  `3b19a3f719721e3a2f4f6ea6b12d53140eea0853`. It is not configured in Apache and
  has no development database. Do not attach it to the live Apache route or
  reuse the running database. A distinct development database and URL require
  separate provisioning and approval before any database write or Apache
  routing change.
- See `PROJECT_ROADMAP.md` and `DEVELOPMENT_RULES.md` before the next sprint.

## College demonstration closure — 2026-11-02

This dated checkpoint supersedes older runtime/readiness statements for the
college demonstration; historical enterprise findings remain preserved.

- Final checks executed today: 30/30 database-free suites PASS; 88/88 PHP lint
  PASS; git diff --check PASS. Database integration mutations were not rerun.
- Admin/Employee login and main page walkthroughs have authenticated protected
  browser evidence. Employee Admin-route/profile/salary-slip isolation passed.
- Attendance, overnight checkout, duplicates, Holiday/Sunday Working and Leave
  decisions were previously verified in isolated test environments. The final
  protected walkthrough was read-only; Holiday Working was NOT reverified there.
- Salary counter authenticated PASS: 0 employees paid, 2 Pending records;
  Salary Report agrees (paid amount 0, pending amount 102000). Generation and
  payment release remain intentionally disabled; historical views are supported.
- Dashboard and Employee Leave have responsive viewport metadata. Dashboard
  mobile cards stack, its sidebar no longer overlaps content, and profile-image
  fallback uses existing images/default.png. Admin desktop/406px checks passed;
  Employee Dashboard/Leave were reviewed at 406px. The final static-sidebar
  adjustment was rechecked as Admin, not in a new Employee session.
- Reviewed Admin Dashboard console: zero application errors; four browser
  Tracking Prevention warnings for Font Awesome CDN remain. Icons render.
- Operator-reported manual protected-runtime EMP9902 checks: PASS for active baseline/no open check-in, deactivation, existing-session rejection, valid-password login denial while inactive, and reactivation followed by normal login. Not independently repeated here and not an automated result; this supersedes the earlier inactive-login NOT TESTED note in this checkpoint.
- This is a qualified college-demo-ready checkpoint, not a claim that every
  page is mobile verified. Other pages previously noted as lacking viewport
  metadata remain outside the final Dashboard/Leave fix and should be shown
  on desktop. No protected database writes occurred during closure.
- Existing dirty changes were preserved. No deleted tracked files were found.
  Recent Dashboard/Leave/Salary hunks were reviewed; the accumulated R5 diff
  remains uncommitted and is not represented as a fresh full security audit.
- Submission must exclude temporary reset scripts, runtime evidence, secrets,
  session files and local configurations; see TESTING.md. Do not zip this
  entire working directory indiscriminately.

## Documentation refresh and final browser status — 2026-11-04

This checkpoint updates the college-release documentation. It preserves older
dated sprint findings and does not claim a complete Employee browser tour.

- Fresh checks executed for this refresh: all 31 `tests/*_test.php` suites
  passed, PHP lint passed for all 90 PHP files, and `git diff --check` exited
  0. Git printed existing LF-to-CRLF normalization warnings, not whitespace
  errors. No database integration runner was executed.
- Recent visible Admin browser checks loaded Dashboard, Employees,
  Departments, Attendance, Leave Management, Holidays, Shift Management,
  Shift Assignment, Assigned Shifts, Salary, an existing Salary Slip,
  Attendance Report, Salary Report, Notifications, and Logout confirmation.
  These were read-only page-render checks. Admin Attendance displayed that no
  active shift is assigned, so check-in was unavailable. The final Logout
  button was not used.
- Admin Salary Report showed two salary rows, 0 paid employees, paid amount 0,
  and pending amount 102000. Salary generation and payment remain disabled.
  No salary records were changed.
- Admin Dashboard and Logout confirmation were displayed at a responsive
  406-by-812 viewport. The Dashboard stacked navigation and content without
  visible horizontal overflow. Font Awesome CDN Tracking Prevention warnings
  appeared in Edge; no application JavaScript error was visible in the reviewed
  console. This was not an exhaustive console check of every page.
- An authenticated Employee EMP9902 Dashboard was observed with Employee-only
  navigation and no Admin management links. The remaining Employee pages were
  not completed. Later attempts to continue were stopped when computer control
  could not reliably verify the active tab URL or lost access to the InPrivate
  window. Do not claim Profile, Attendance, Shift, Holiday, Leave, Notification,
  Salary, Salary Slip, or Employee mobile pages passed this final walkthrough.
- Operator reports five manual protected-runtime EMP9902 checks PASS: active baseline/no open check-in, deactivation, existing-session rejection, valid-password login denial while inactive, and reactivation followed by normal login. This user-observed result was not independently repeated here and is not an automated test. It supersedes the earlier inactive-login NOT TESTED statement.
- Holiday Working was verified in prior isolated tests but was not reverified against the protected database in the final browser session. Existing isolated test evidence is historical and was not repeated here.
- No application or database files were changed during this documentation
  refresh. Existing uncommitted work was preserved. The incomplete Employee
  walkthrough and inactive-login result are release limitations, not evidence
  of a proven application defect.

## R5 publication schema and documentation — 2026-11-04

- Added `database/schema_mysql8.sql` as a schema-only MySQL 8 college-core installer. It contains nine InnoDB tables, 13 foreign keys, indexes/check constraints, and four audit immutability triggers; it contains no seed rows, account/grant statements, schema selection, or destructive reset/migration commands. A narrow `.gitignore` exception includes only this reviewed schema file; other SQL exports remain ignored.
- Schema evidence came from two matching synthetic setup DDL sources. The historical data-bearing dump was excluded. No protected or development database was queried, modified, or used for a fresh import in this task.
- Fresh schema import, application bootstrap, initial Admin creation, and end-to-end clean-install execution remain **NOT VERIFIED**. The README documents an operator-selected empty schema, external credentials, least-privilege account, and local secure initial Admin bootstrap; no default credential is published.
- Current source checks for this integration worktree are listed in README.md and TESTING.md: 27 portable database-free suites and 91 PHP files are the expected counts; six integration/review contracts remain NOT RUN. The operator-reported inactive-account checks remain user-observed evidence, not an independent retest.
- Historical salary display/reporting is in the core schema. Salary generation/payment remain disabled. Optional payroll policy/preview pages require a separate stage schema excluded from the installer.
