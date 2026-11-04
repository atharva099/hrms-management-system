# Business rules and policy ownership

## Attendance

Check-in eligibility is evaluated against the assigned active shift and its
configured early allowance and late cutoff. Overnight attendance is keyed to
the selected shift business date and snapshots schedule and policy values.
Sunday/holiday classification is separate from time eligibility. Checkout of
an existing open row uses its stored schedule and optimistic row version.

## Leave

Leave approval and attendance-conflict decisions are explicit Admin actions
with CSRF and audit handling. The current legacy leave type is not an
authoritative paid/unpaid payroll classification. Payroll must block until HR
approves effective-dated classifications and calendar treatment.

## Payroll

The deterministic calculator accepts explicit approved policy, compensation,
schedule, attendance, leave, and calendar inputs and uses integer cents. No
deduction rate, entitlement, holiday rule, or currency may be inferred. INR is
only a configurable choice in the stage-only setup. Salary generation and
payment release remain disabled until persistence, approvals, real payment
evidence, and runtime verification exist.

## Passwords

The shared password validator requires 12–72 bytes using `strlen()`; password
input is not trimmed or silently changed. Admin employee creation, Admin
password reset/edit, and Employee self-service change use this policy and
`password_hash(PASSWORD_DEFAULT)`. A blank password on Admin edit preserves
the current hash, and Employee self-service still verifies the current
password before changing it.

## Demonstration and verification status — 2026-11-04

The final browser demonstration is read-only. Do not check in/out, submit leave,
approve/reject requests, change employee/department/shift/holiday data, mark
notifications read, or generate/pay salary as part of the walkthrough.
Employee-specific data remains scoped to the signed-in employee; Admin
management actions remain role-restricted. A page-render check does not prove
that its data-changing workflow works.

Current evidence includes prior isolated Attendance and Leave workflows,
Admin page rendering, and the Employee EMP9902 Dashboard. The full Employee
page walkthrough and inactive EMP9904 authentication test remain unverified.
Salary history and reports are read-only; salary generation/payment remain
disabled rather than completed business workflows.
