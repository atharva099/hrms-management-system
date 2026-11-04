# Testing

## Current integration-worktree verification — 2026-11-04

- Portable `tests/*_test.php` suite: **28/28 PASS**, including static contracts for the CLI-only Admin bootstrap.
- PHP lint across the current integration worktree: **93/93 PASS**. The 89-path staged publication snapshot predates the new bootstrap files and is not a verification of these unstaged additions.
- `git diff --check`: **PASS**.
- Six integration/review contracts: **NOT RUN** and not included in the staged publication tree because their machine-specific setup, migration SQL, or runners are excluded. No result is claimed for those checks.
- No database operation was performed. A local PHP HTTP request to `tools/bootstrap_admin.php` returned **404 Not Found**, confirming the web guard rejects execution before database configuration is loaded.
- Fresh database installation and Admin bootstrap runtime: **NOT VERIFIED**. `database/schema_mysql8.sql` passed static inventory and sensitive-content checks but was not imported into a fresh MySQL database. The bootstrap contract test inspects source only; it does not connect to MySQL or create an account. Do not claim clean-install verification from these source tests.

Run the portable tests with the PHP executable shipped with XAMPP:

```powershell
Get-ChildItem tests -Filter '*_test.php' | ForEach-Object { & C:\xampp\php\php.exe $_.FullName; if ($LASTEXITCODE -ne 0) { throw "Test failed: $($_.Name)" } }
```

The separate database-backed `*_integration.php` runners may write to their explicitly guarded disposable targets. Do not run them against the protected CRM, a shared test database, or a development database. The six `.integration.php` files under `tests/integration/` are source-review contracts whose excluded dependencies must be separately reviewed before execution.

## Runtime evidence and limits

The operator reports five manual protected-runtime checks for EMP9902 passed: active baseline with no open check-in, deactivation, rejection of the existing session, valid-password login rejection while inactive, and reactivation followed by normal login. This is user-observed runtime evidence, not an automated result or independent retest.

Authenticated Admin page rendering was observed. Employee browser evidence confirms the Dashboard and Employee-specific navigation, but the complete Employee page walkthrough remains unverified. Protected-runtime Holiday Working was not reverified in the final browser session. Previous isolated Attendance/Leave HTTP evidence remains historical and is not a fresh-install test.

Salary generation/payment are disabled; historical salary display is distinct from a payment workflow. The optional payroll-policy/preview pages require a separate stage schema/configuration and are not installed by the college schema script.

## Safe demonstration

Admin: sign in with a locally provisioned synthetic account; show Dashboard, Employees/profile, Shifts, Attendance/Report, Holidays, Leave decisions, Notifications, Salary and reports. Explain that Salary history is available while generation/payment are disabled. Keep the walkthrough read-only.

Employee: sign in with a locally provisioned active synthetic account; show own Dashboard/profile, Attendance, assigned shift, Holidays, Leave form/history, Notifications and own historical slip. Do not submit forms, check in/out, mark notifications read, or alter records during a read-only demonstration.

No default account or password is included. Keep runtime configuration and locally generated account hashes outside Git.
