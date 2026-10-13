# R5 8E: Attendance HTTP workflow test plan

This is an operator-reviewed preparation document. The checks in
`attendance_http_workflow_contract_test.php` are source-level checks only. The
operator has separately reported a small set of read-only HTTP checks below;
they are not evidence that authenticated or write-capable workflows were
tested.

## Current workflow entry points

- `attendance.php` calls `auth_require_authenticated($conn)` before rendering or
  processing. It takes the employee identity from the authenticated session,
  accepts mutations only from POST, validates the shared CSRF token, and starts
  a transaction before check-in/checkout work. Check-in locks the active user
  and assigned active shift, derives the shift business date, checks an
  existing attendance row and approved leave, and inserts the timestamp-backed
  row. Checkout obtains all candidates from
  `attendance_find_current_open_checkout_candidates()`, rejects ambiguity,
  validates timestamp order, then conditionally updates one still-open row
  using its observed `row_version`.
- `includes/attendance_time.php` computes overnight shift ends on the following
  date. Checkout candidates must be for today or yesterday, have a valid
  check-in and scheduled end, and fit the current/previous business-date window.
  The page and POST handler use the same candidate selector. Rows missing
  `scheduled_end_at` are excluded.
- `auto_absent.php` is Admin-only, POST-only, CSRF-protected, and uses the
  assigned active shift's schedule end. It skips holidays/Sundays and open rows
  without a trustworthy scheduled-end snapshot; versioned updates skip the
  exact no-op state.
- `resolve_leave_conflict.php` is Admin-only, POST-only, and CSRF-protected. It
  validates the reason/decision, locks leave and attendance rows, checks
  current state and overlap, limits conversion to work-evidence-free Absent
  rows, and appends conflict audit events inside the transaction. No test may
  use the known baseline contradiction as a fixture.
- `includes/auth.php` authenticates through the normal login flow, stores
  employee ID and role in the session, rechecks active status on protected
  routes, and provides the shared CSRF token functions.

## HTTP test method

Run only after an operator has confirmed a fresh disposable clone and a
synchronized runtime application copy. Authenticate through `index.php` and
`login.php`; retain a separate cookie jar per Employee and Admin session. Read
CSRF tokens from actual forms and submit them unchanged. Do not set session
variables, call PHP page code directly, fake time, or invoke application
helpers as a substitute for requests.

Use a dedicated clone-only Employee with an active assigned shift and a
dedicated clone-only Admin. Do not mutate existing accounts, leave records,
attendance rows, payroll rows, or audit history. Any added fixture identities
must use unique test IDs, generated password hashes, and the clone's valid
department/shift relationships. Keep credentials in the operator process
environment or an approved local secret store; never put passwords in source,
logs, command arguments, or this repository.

Because the PHP endpoints use the real clock, same-day attendance tests may run
only when the chosen active shift scenario can be exercised without colliding
with an existing business-date row. The overnight case is a two-stage test:
check in after the actual 22:00 shift start, retain the authenticated cookie
jar securely, then check out only after the real calendar date crosses and the
stored scheduled end is reached. If the time window is unavailable, mark it
NOT RUN/PENDING; never substitute fabricated timestamps. Auto-absence likewise
requires the actual applicable schedule boundary and its existing 23:00 guard.

**Cleanup rule:** these tests run only on a disposable clone. Do not issue
generic DELETE cleanup against application tables. Capture a verified clone
backup before setup; after any write-capable suite, recreate the disposable
clone from that backup and verify its baseline counts and protected-table
fingerprints. This also removes test audit events without editing append-only
history. If clone recreation is unavailable, do not run a test that can write.

## Cases to execute after operator approval

| Case | Normal HTTP path | Expected result / evidence |
|---|---|---|
| Employee login and identity | GET login form, POST to `login.php`, then GET `attendance.php` | Successful authenticated session; DB row created only for fixture employee, never an ID supplied by the browser. |
| Invalid credentials / inactive fixture | Normal login form and `login.php` | Login rejected; protected attendance route redirects to login; no row changes. |
| Unauthorized role | Employee session requests Admin leave management and POSTs conflict/auto-absence routes | Admin-only access denied; no attendance, leave, or audit changes. |
| GET mutation attempts | GET `attendance.php?checkin=1` / `?checkout=1`, GET `auto_absent.php`, GET `resolve_leave_conflict.php` | No mutation. Auto-absence and conflict-resolution GET return 405; attendance GET only renders the page. |
| Missing/invalid CSRF | POST attendance, auto-absence, and conflict resolution without token and with an altered token | 403; row count and row snapshots unchanged. |
| Normal check-in | Employee attendance form, current applicable shift date | One fixture attendance row; its employee/date, actual `check_in_at`, shift-derived `scheduled_end_at`, and legacy time are consistent. |
| Duplicate/concurrent check-in | Repeat and then issue two simultaneous valid POSTs for the same employee/business date | Second request is rejected; unique key permits exactly one row. Capture both responses and exact pair count. |
| Normal checkout | Same authenticated Employee, actual open row | Same row updated once; positive timestamp duration, preserved business date, one `row_version` increment. |
| Duplicate/concurrent checkout and stale writer | Repeat and then issue two simultaneous checkout POSTs against one open row | At most one request succeeds; the conditional version/open-state update prevents a second increment or overwrite. This tests endpoint concurrency, not an arbitrary stale version input (the page exposes no version parameter). |
| Overnight checkout | Two-stage real-clock test for active 22:00–06:00 shift | Start-date business row has next-day scheduled end; after midnight, same row closes with next-day checkout and reasonable duration; no next-day duplicate. |
| Stale historical candidate | Read-only inspect fixture/baseline old open rows, then normal checkout attempt with no current eligible row | Historical rows, especially rows without scheduled-end snapshots, remain unchanged and are not closed. |
| Multiple eligible candidates | Requires isolated test fixture setup that does not touch baseline rows | Checkout UI reports review-needed and POST refuses arbitrary selection. Only run if duplicate fixture rows can be created safely in the clone and then clone restored. |
| Inactive/no-shift account | Dedicated clone-only inactive/no-shift identities through normal login/request flow | Inactive login/active-account recheck denies access; active no-shift check-in is rejected; no attendance row created. |
| Auto-absence before/after boundary | Admin session; POST to `auto_absent.php` using CSRF from an authenticated Admin form | Before boundary, no change. After actual shift end and guard, only eligible business dates are processed and row_version advances once for a real change. Do not run before boundary or on skipped holiday/weekly-off dates. |
| Leave conflict | Employee submits a valid leave through `leave.php`; Admin uses visible conflict form in `manage_leave.php` and POSTs `resolve_leave_conflict.php` | Exercise reject/preserve/Absent-to-Leave only on dedicated current-date fixtures; assert leave/attendance state and audit event in clone. Do not use known historic conflict. Restore clone afterward. |
| Rollback/failure path | Use malformed IDs, unsupported decisions, stale leave state, and invalid reason through normal HTTP requests | Safe 4xx/redirect response; no partial leave/attendance/audit state. Avoid inducing DB failures against any shared environment. |

For every request, record method, endpoint, expected/actual status or redirect,
response marker (without session cookies or tokens), and database before/after
counts plus test-row snapshots. Never log password, password hash, session
cookie, CSRF token, or raw personal/payroll data.

## Current prerequisites and limits

## Separate overnight CLI workflow verification

The operator reports the extracted workflow's database-backed overnight
functional assertions passed: check-in at 2026-10-11 21:50 and checkout at
2026-10-12 06:05, business date, Sunday Working classification, 8 hrs 15 mins,
shift/policy snapshots, row-version progression, and duplicate-action
rejection. The first preservation verifier exited nonzero. The operator then
confirmed the corrected read-only forensic comparator returned overall PASS:
attendance ID 56 matches the expected overnight row, IDs 53–55 are unchanged,
and pre-existing attendance, user, leave, salary, and audit rows match the
forensic baseline. The earlier integration process exit code remains
unverified, so this evidence does not establish an overall runner PASS.

The original failed-run evidence database
`coreaxis_r5_attendance_overnight_rerun_20261013_a6d29f4c` and forensic
baseline remain preserved. Do not rerun the write-capable workflow against
either database. Any new integration run requires a fresh, uniquely named
disposable clone and explicit operator authorization.

- The isolated runtime copy exists. A text-only inspection confirms its
  `includes/db.php` targets
  `127.0.0.1:3307/coreaxis_crm_runtime_test`; it was not executed for this
  verification. SHA-256 comparisons now show exact workspace/runtime matches
  for `attendance.php`, `auto_absent.php`, `resolve_leave_conflict.php`,
  `includes/attendance_time.php`, `includes/auth.php`, and
  `includes/leave_conflicts.php`.
- The operator reports these read-only HTTP checks passed: a static image
  returned HTTP 200; the PHP login page rendered; `/.env`, `/.git/config`, and
  `/coreaxis_crm.sql` returned HTTP 403. No login was submitted and no
  authenticated or mutating flow was tested. Directory listing and other
  backup/config extensions have not been independently verified over HTTP.
- The standalone Apache config binds to `127.0.0.1:8081`, denies access by
  default outside the runtime `DocumentRoot`, disables `Indexes`, and denies
  `.env` variants, Git metadata, and configured dump/backup/config extensions.
  These are config-level checks, not proof of the currently active listener.
- The PHP session path is a dedicated test directory and the session name is
  `COREAXIS_R5_TESTSESSID`; the main app uses PHP's default session name because
  its auth helper does not override it. Cookie scope does not include port, so
  use `127.0.0.1` consistently (not `localhost`) and retain separate cookie
  jars. The different host-only cookie name reduces collision risk with the
  main app; it does not isolate against another local app deliberately using
  the same name and host.
- During this verification, a foreground Apache start failed with Windows
  socket error 10048 (`127.0.0.1:8081` already in use). `Get-NetTCPConnection`
  and `netstat -ano` did not identify a listener in this execution context, and
  no `httpd.exe` process was visible afterward. Do not start authenticated
  tests until an operator resolves the port ownership/reservation and confirms
  the active listener belongs to this standalone config. Do not remove the PID
  file or terminate an unidentified process as a workaround.
- Apache's log confirms worker startup on the earlier run, but the access log
  is empty in the current verification snapshot. The latest log also contains
  a prior malformed `/login.php**` path error; it does not indicate source
  exposure. PHP logs a missing `pdo_sqlite` extension warning. The app uses
  `mysqli`, so that warning is unrelated to its configured MySQL connector,
  though it should be cleaned up if SQLite functionality is later required.
- No database connection was made during this verification. The prior GET to
  `login.php` is not a suitable health check: it includes `db.php` and executes
  a prepared `SELECT` even on GET, so it can wait on database connectivity.
  Prefer the static `/images/logo.png` health check first. Only after the
  listener and static response are confirmed should an operator test PHP pages.
- The 22:00-to-06:00 test cannot be completed in one session at an arbitrary
  time. Keep it pending until the real check-in and following-day checkout
  windows are available.
- The static checks do not test HTTP status behavior, cookies, CSRF handling in
  a browser, MariaDB transactions, or concurrent request timing. Those remain
  clone-only operator-run integration checks.
