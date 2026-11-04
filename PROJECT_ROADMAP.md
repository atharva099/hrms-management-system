# R5 completion roadmap

## Active college-release scope — checked 2026-11-04

The active goal is a professional, demonstrable college-level HRMS. New
enterprise/SaaS controls and production infrastructure are postponed and are
not college-release requirements. Current integration-worktree checks pass
(27/27 portable database-free suites, 91/91 PHP lint, and `git diff --check`).
Six integration/review contracts were not run. Admin page rendering and an
Employee Dashboard have authenticated browser evidence; the complete Employee
page walkthrough remains unverified. The schema-only installer is static-reviewed
but has not been imported into a fresh database.

Shortest remaining completion path:

1. Complete a read-only Employee browser walkthrough when the existing
   authenticated protected tab is reliably available. Do not submit forms.
2. Use the safe demo flow in `TESTING.md`; do not repeat already verified
   database mutations.
3. Package only reviewed application, asset, test, and documentation files.
   Exclude local credentials, reset helpers, generated evidence, and unreviewed
   database fixtures.

Salary generation and payment remain disabled. The enterprise roadmap below is
preserved as historical research, not as the active college completion plan.

## Postponed enterprise roadmap — P0 database and payroll safety

1. Provision managed `COREAXIS_DB_*` settings and a least-privilege runtime DB
   account; verify the application can connect without an administrator user.
2. Reconcile historical duplicate employee-period salary rows against payment
   evidence; never auto-delete or merge them.
3. Obtain approved, effective-dated leave/payroll/calendar rules and currency.
4. Review a production-safe migration package; stage-only candidates are not
   eligible for production deployment.
5. Implement persisted payroll drafts, independent approval, immutable
   snapshots, and payment-event recording with real external evidence before
   enabling salary mutations.

## Postponed enterprise roadmap — P1 runtime verification

1. Execute the authenticated HTTP matrix for sessions, CSRF, role/ownership,
   attendance, leave conflicts, reports, and disabled payroll actions against
   an isolated deployment-equivalent clone.
2. Verify attendance concurrency, correction authorization/audit, holiday and
   weekly-off interactions, and report/payroll duration semantics in the DB.
3. Rehearse backup restoration and production migration recovery.

## Postponed enterprise roadmap — P2 release quality

Run full regression and accessibility/responsive review, then prepare a scoped
release candidate only after all P0/P1 evidence is recorded. Do not claim
production readiness from static checks alone.

## Workflow baseline and audit — 2026-10-15

- Enterprise readiness audit (preserved unchanged):
  `docs/audits/CoreAxis-Enterprise-Readiness-Audit-20261015.md`.
- Mandatory development, testing, promotion, rollback, and documentation rules:
  `DEVELOPMENT_RULES.md`.
- The protected runtime checkout currently uses the isolated MySQL 8 development
  database documented in `PROJECT_STATE.md`. Keep new development on a separate
  clean worktree and a separately approved database/URL; never point write tests
  at the running application's database.
- Authenticated workflow coverage is still pending unless a later verified
  checkpoint records actual HTTP evidence.
