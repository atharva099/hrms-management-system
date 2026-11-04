# CoreAxis CRM R5 Release Readiness

Status date: 2026-10-13. This register reflects repository inspection and
operator-reported staging evidence. No protected database or HTTP endpoint was
accessed during this review.

## Release blockers

| Priority | Status | Evidence and impact | Required action |
|---|---|---|---|
| P0 — Payroll policy and payment integrity | Partially implemented; release blocked | Payroll generation and payment release still return 503. A new pure monthly calculator requires approved explicit rules, paid/unpaid leave classifications, full-period source inputs, active employee/shift data, and exact currency. A stage-pinned additive schema candidate describes snapshots, policy approval, and immutable payment events; it has not been executed or wired to HTTP/database workflows. The live users schema has no authoritative currency field. | HR/payroll owners must approve effective rules, leave classification, calendar treatment, and currency. Review/execute a separately prepared migration only on an authorized disposable stage; add a DB adapter, authorization/CSRF workflow, and DB-backed tests. Reconcile historical duplicate employee-period records against payment evidence. Keep both salary mutations disabled. |
| P0 — Database deployment credentials | Partially remediated; runtime not configured | `includes/db.php` now requires `COREAXIS_DB_*` settings, rejects the MariaDB `root` account and empty password, and does not contain credentials. The local runtime has not been configured or tested with a dedicated application account. | Provision deployment-managed secrets and least-privilege grants; verify connection behavior in an isolated deployment environment. |
| P0 — Production migration package | Blocked | Current untracked SQL is explicitly pinned to clone/staging schema names. It is not safe for production execution. | Prepare separate versioned production migrations with read-only preflight, backup/recovery, dependency review, and tested forward/rollback procedures. |
| P1 — Authenticated HTTP verification | Pending | Static contracts and operator-reported isolated HTTP checks exist, but complete login/session, CSRF, role/ownership, duplicate actions, leave conflict, report, and payroll-disable behavior have not all been exercised as real authenticated requests. | Run the focused HTTP matrix only on a separately authorized disposable clone; capture sanitized response and before/after evidence. |
| P1 — Attendance production rollout | Pending | Operator confirmed the overnight final row and preservation comparator PASS. The earlier integration runner exit code is unverified; attendance policy migrations remain clone-oriented. | Review production DDL and rollout/backout, then run authenticated HTTP and database-backed concurrency cases on isolated deployment-equivalent infrastructure. |
| P1 — Leave/payroll policy integration | Blocked by business rules and runtime integration | Existing `leaves.leave_type` is free text without an authoritative paid/unpaid column. The new calculator blocks pending/overlapping/unclassified leave and requires explicit holiday/weekly-off behavior, but no leave-data adapter or approved classifications exist. | Approve leave classification and treatment with HR/payroll owners; add a production-safe leave classification migration and integration tests. Do not infer deductions from leave names or attendance labels. |
| P1 — Production transport and session operations | Pending | Session cookies are HttpOnly and SameSite=Lax; strict session IDs are now required. Secure-cookie behavior depends on HTTPS being correctly terminated and visible to PHP. | Require HTTPS, configure trusted proxy handling if used, and verify cookie attributes and session behavior in the production topology. |

## Verified evidence

- Operator reports the disposable payroll-period migration and integration
  passed period uniqueness, CHECK behavior, report/payslip compatibility, and
  rollback checks. This does not certify a production migration.
- Operator reports the overnight forensic comparison passed and preserved the
  baseline records; attendance ID 56 matched the expected result. The earlier
  integration process exit code remains unverified.
- The prior attendance review recorded seven database-free attendance suites
  plus payroll contracts. In the current batch all 13 repository
  `*_test.php` suites passed, and PHP lint passed for 64 PHP files. These
  checks do not establish live database or authenticated HTTP behavior.
- The payroll hardening batch adds an exact-cent, deterministic calculation
  contract, payment-event validation/status derivation, a read-only staging
  preflight/postflight, and an additive stage-pinned schema candidate. These
  were statically reviewed only; the SQL was not parsed/executed by MariaDB
  and no mutation is enabled. Passing unit tests does not establish migration
  or database-backed workflow safety.
- No database connection, migration, HTTP request, commit, or push was made.

## Five-day release sprint

1. **Day 1 — Payroll rules and safety:** Approve leave/attendance deduction
   policy and payment lifecycle; keep salary mutations disabled until the
   policy and audit design are implemented and reviewed.
2. **Day 2 — Production database package:** Provision managed secrets and
   least-privilege grants for the fail-closed environment-driven bootstrap. Produce
   production-safe attendance/payroll migrations and test them on a fresh
   restore, including migration failure recovery.
3. **Day 3 — Runtime workflow verification:** Run authenticated HTTP tests for
   login/session, CSRF, authorization, attendance, leave conflicts, reports,
   and the disabled payroll actions on an isolated deployment-equivalent
   environment. Verify rejected requests leave records unchanged.
4. **Day 4 — Cross-module integrity:** Test employee lifecycle, shifts,
   holidays, weekly offs, leave approvals, salary-period uniqueness, reports,
   payment audit, and concurrency. Resolve only evidence-backed failures.
5. **Day 5 — Release candidate:** Run full lint/regression, restore-and-recovery
   rehearsal, security review, migration approval, and a scoped Git review.
   Ship only if all P0/P1 gates have evidence and payroll policy owners approve.

## Immediate next batch

Define the approved payroll rule and payment-audit contract with the HR/payroll
owner, then implement it in isolation while keeping payroll generation and
release blocked. In parallel, prepare the least-privilege deployment
configuration; do not point the application at a production database until
that configuration is reviewed.

## Payroll migration review gate — 2026-10-13

- Static review retains the stage-pinned candidate's 9 tables, 17 restricted
  foreign keys, and 27 guard/audit triggers. No trigger was removed: separate
  immutability, state-transition, actor/approval, and append-only guards cover
  distinct write surfaces; row CHECKs overlap only for defense in depth. Two
  secondary payment indexes covered by unique leading-column indexes were
  removed. A payment transaction is now unique per salary and must equal the approved
  calculation amount. Partial payments/installments are not represented.
- The preflight detects partial payroll-prefixed table/trigger state. Preflight,
  postflight, and the read-only adapter require `@@check_constraint_checks = 1`;
  MariaDB permits CHECK-expression evaluation to be disabled, so object
  presence alone is not proof of enforcement.
- Static review indicates MariaDB 10.4-compatible InnoDB, CHECK, FK, and
  trigger syntax, but MariaDB has not parsed or executed the candidate in this
  task. DDL commits implicitly; failure requires preserving and inspecting the
  stage, never retrying the full file or assuming rollback.
- The reported stage dump hash is
  `BC8054410C736506EC4FBD3A34849C6437C013584C173973A73D24D10394E3C6`; it has
  no trigger/routine definitions. The operator also reports zero triggers in
  the current stage, so this is not by itself proof of a dump mismatch, but
  restoration remains unverified and expected attendance-audit trigger state
  must be resolved. **Migration execution: NO-GO** pending a verified restore
  test, exact-target preflight, and resolution of the audit-baseline question.
- No database connection, DDL, database write, or HTTP request occurred in
  this review. All 16 database-free suites passed, PHP lint passed for 72 files,
  and `git diff --check` passed. These checks do not establish runtime DDL
  compatibility or enable payroll generation/payment.

## Sprint 2 payroll integration update — 2026-10-13

Implemented but not runtime-verified:

- Added an Admin-only, CSRF-protected stage configuration page for effective-
  dated compensation, explicit weekday/shift rosters, and versioned policy and
  leave classifications. A different active Admin must approve each version;
  effective-range overlaps are serialized by MariaDB named locks.
- Added a read-only database adapter pinned to
  `coreaxis_r5_payroll_period_stage_20261013` at local port 3307. It verifies
  required InnoDB tables, foreign keys, candidate triggers, payroll-period
  unique key and CHECK constraint, then loads compensation, shifts/rosters,
  attendance, leave, holidays, and one policy under a read-only consistent
  snapshot. Missing schedule/attendance/policy data blocks calculation.
- Added a read-only Admin preview page. The existing salary generation and
  payment release gates remain disabled.
- Expanded the stage-only SQL candidate to nine new tables, 17 foreign keys,
  and 27 guards, including effective-dated immutable compensation and work
  schedules. The accompanying runbook documents backup, preflight, execution,
  postflight, and partial-DDL recovery. The candidate was not executed.

Still blocked:

- Calculation snapshot persistence, calculation approval endpoint, and
  database-backed payment event recording are not implemented. No real
  external payment rail is integrated; a database event alone cannot prove a
  transfer.
- No current MariaDB stage preflight/postflight, adapter integration, or
  authenticated HTTP test was run in this batch. The policy and roster
  configuration pages have only static checks and remain stage-only.
- Payroll generation/payment cannot be enabled. A separate review must verify
  all MariaDB 10.4.32 DDL/triggers, seed approved effective-dated compensation,
  roster and policy records using business-approved values, test two-Admin
  approval and read-only preview, then implement and test persisted draft,
  approval, and payment event transactions.

Sprint 2 final database-free verification (2026-10-13): all 16
`tests/*_test.php` suites passed, PHP lint passed for 72 files, and
`git diff --check` passed (Git emitted only LF-to-CRLF working-copy warnings).
No database connection, migration, or authenticated HTTP request was made.
These checks do not change the blocked payroll release status.

Sprint 3 runtime gate (2026-10-13): the read-only native MariaDB client returned
error 2002 / Windows socket error 10013 for `127.0.0.1:3307`; database identity
and schema could not be read from this execution environment. The supplied
stage dump exists and hashes to
`BC8054410C736506EC4FBD3A34849C6437C013584C173973A73D24D10394E3C6`, but
static inspection found no trigger or routine definitions. The operator now
reports the current stage also has zero triggers, so the dump inventory may
match; restore recoverability and whether attendance audit guards are expected
remain unverified. The candidate
migration and database/HTTP integration tests were NOT RUN. Do not execute DDL
until an operator makes and verifies a complete stage backup and reruns the
exact-target read-only preflight.

Migration review follow-up: the static candidate now includes two policy actor
foreign keys and enforces distinct creators/approvers in the compensation,
schedule, and policy approval triggers. Its reviewed inventory is 9 tables,
17 foreign keys, and 27 triggers. Adapter validation checks each FK target and
referential action, plus the exact legacy salary-period unique-index columns.
These edits have not been run against MariaDB; rerun the read-only preflight
immediately before one-time stage DDL.

Final static verification after the review fixes: all 16
`tests/*_test.php` suites passed, PHP lint passed for 72 files, and
`git diff --check` passed. No database or HTTP runtime was tested.

## 2026-10-14 mega-sprint update

- Shared DB configuration is environment-driven and rejects privileged `root`
  credentials; actual app-account grants and runtime connection have not been
  verified. Authenticated sessions now have a 30-minute idle expiry with
  database-free tests.
- Database-free suite/lint results for this update are recorded in the current
  task report. No MariaDB connection, migration, HTTP request, or database
  mutation was performed.
- Payroll persistence, approval, and payment recording remain unavailable;
  payroll generation/release remain disabled pending approved policy,
  migration execution, and runtime integration. Production release remains
  blocked.
- Verification on 2026-10-14: 18 database-free contract suites and PHP lint for
  all 74 PHP files passed; `git diff --check` passed. No database or HTTP
  execution occurred.
