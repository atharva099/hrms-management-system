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
