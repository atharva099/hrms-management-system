# Security controls and deployment gates

- Shared DB configuration is injected with `COREAXIS_DB_*` environment
  variables or an operator-managed PHP config file outside the Apache document
  root. It rejects missing/empty credentials and the MariaDB `root` user, and
  returns generic connection errors. Production still needs provisioned,
  reviewed least-privilege grants and secret management.
- Authentication refreshes the active account/role from the database. Session
  IDs are strict, cookie-only, HttpOnly, SameSite=Lax, and rotate at login.
  Authenticated sessions now expire after 30 minutes of inactivity. Production
  HTTPS and proxy-aware secure-cookie behavior must be verified.
- State-changing attendance, leave, notification, and payroll actions use
  server-side authorization and CSRF checks in the reviewed flows.
- Payroll generation and release are deliberately disabled. Stage policy
  setup and preview do not constitute a payment workflow.
- New or changed passwords use the shared 12–72 byte validator
  (`strlen()`, no trimming) and `password_hash(PASSWORD_DEFAULT)`. Existing
  hashes are not rewritten automatically; blank Admin edit input leaves the
  current password unchanged.

Security contracts are static/database-free. Authenticated HTTP verification,
deployment grants, HTTPS topology, and production migration tests remain
required before release.

## College demonstration status — 2026-11-04

The college demonstration uses a local protected runtime, not a public
production deployment. Recent read-only browser evidence confirms Admin page
rendering and one authenticated Employee Dashboard with role-specific
navigation. Full Employee route coverage remains unverified. The operator reports
five manual protected-runtime checks for EMP9902 passed, including rejection of
the valid password while inactive and rejection of an existing session after
deactivation. This is user-observed runtime evidence, not independently retested
or an automated result in this update. Do not infer full route coverage from it.

The reviewed browser console showed Font Awesome CDN Tracking Prevention
warnings; icons rendered, and no application JavaScript error was visible in
the pages inspected. This is a browser warning, not evidence that the external
CDN is available in every browser. Keep account passwords, external DB
configuration, reset helpers, session material, and runtime logs out of any
submission archive. No credentials are stored in this document.

Fresh-install note: the published college schema contains no accounts or default credentials. `tools/bootstrap_admin.php` is CLI-only and `tools/bootstrap_admin.ps1` collects the password with hidden input and transports the payload to PHP over stdin. The flow rejects non-empty `COREAXIS_DB_MODE`, database config paths inside the project, MySQL versions below 8.0.16, root accounts, a mismatched database name, incomplete schema, or any existing user. It validates the shared 12–72 byte password policy, uses `password_hash(PASSWORD_DEFAULT)`, acquires a schema-scoped advisory lock, and performs one prepared insert in a transaction. It does not update or upsert. This source-level implementation is covered by a database-free contract test; fresh-schema runtime behavior remains NOT VERIFIED. If an existing user is present, use the authorized account-management process or a separately reviewed operator recovery procedure; never delete existing accounts to force bootstrap.
