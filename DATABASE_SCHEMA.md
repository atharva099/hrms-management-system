# MySQL 8 college-core schema

## Evidence and verification boundary

`database/schema_mysql8.sql` is the publication installer and authoritative DDL for this core schema. It was assembled from the `CREATE TABLE` and append-only audit-trigger definitions in two matching, synthetic-only MySQL 8 setup artifacts. Their normalized table DDL matched: 9 tables, 13 foreign keys, and 4 triggers. The older data-bearing dump was not used.

This is source evidence, not a live database introspection or import. The installer passed static checks for schema-only content. It has **not** been imported into a fresh database in this integration worktree, so first-install runtime compatibility remains unverified. Exact types, nullability, defaults, checks, index definitions, engine, and charset are in the installer; this inventory summarizes its objects.

The script requires an operator-selected, newly created empty schema. It does not create/select a database or user, grant privileges, seed accounts/data, or perform reset/drop/alter operations. Review the whole script and target before import. MySQL 8.0.16+ is required for enforced CHECK constraints; the source DDL was prepared for MySQL 8.0.46. InnoDB and `utf8mb4` are used.

## Tables and columns

- `departments`: `id`, `department_name`, `status`, `created_at`.
- `shifts`: `id`, `shift_name`, `in_time`, `out_time`, `late_after`, `status`, `early_checkin_minutes`, `late_checkin_cutoff_minutes`, `created_at`.
- `users`: `id`, `employee_id`, `full_name`, `password`, `department`, `designation`, `shift_time`, `salary`, `incentive`, `joining_date`, `role`, `status`, `phone`, `email`, `shift_id`.
- `attendance`: `id`, `employee_id`, `attendance_date`, `check_in`, `check_in_at`, `check_out`, `check_out_at`, `scheduled_start_at`, `scheduled_end_at`, `late_after_at`, `status`, `working_hours`, `row_version`, `shift_id_snapshot`, `shift_name_snapshot`, `early_checkin_minutes_snapshot`, `late_checkin_cutoff_minutes_snapshot`.
- `holidays`: `id`, `holiday_name`, `holiday_date`.
- `leaves`: `id`, `employee_id`, `leave_type`, `from_date`, `to_date`, `reason`, `status`, `applied_on`, `employee_notification_status`.
- `salaries`: `id`, `employee_id`, `salary_month`, `salary_period`, `basic_salary`, `incentive`, `late_deduction`, `halfday_deduction`, `absent_deduction`, `final_salary`, `created_at`, `payment_status`, `payment_date`.
- `leave_attendance_audit`: `id`, `correlation_id`, `event_type`, `leave_id`, `employee_id`, `attendance_id`, `conflicting_leave_id`, `attendance_date`, `conflict_type`, `resolution_decision`, `previous_leave_status`, `new_leave_status`, `previous_attendance_status`, `new_attendance_status`, `previous_check_in`, `previous_check_out`, `previous_check_in_at`, `previous_check_out_at`, `previous_scheduled_end_at`, `previous_working_hours`, `actor_user_id`, `actor_employee_id`, `reason`, `created_at`.
- `attendance_correction_audit`: `id`, `event_uuid`, `correlation_id`, `event_type`, `correction_type`, `recorded_at`, `attendance_id`, `employee_user_id`, `actor_user_id`, `employee_id_snapshot`, `actor_employee_id_snapshot`, `actor_role_snapshot`, `reason`, `evidence_type`, `evidence_reference`, `before_row_version`, `after_row_version`, `before_employee_id`, `after_employee_id`, `before_attendance_date`, `after_attendance_date`, `before_check_in`, `after_check_in`, `before_check_out`, `after_check_out`, `before_check_in_at`, `after_check_in_at`, `before_check_out_at`, `after_check_out_at`, `before_scheduled_start_at`, `after_scheduled_start_at`, `before_scheduled_end_at`, `after_scheduled_end_at`, `before_late_after_at`, `after_late_after_at`, `before_shift_id_snapshot`, `after_shift_id_snapshot`, `before_shift_name_snapshot`, `after_shift_name_snapshot`, `before_status`, `after_status`, `before_working_hours`, `after_working_hours`, `payroll_impact`, `salary_id`, `salary_period`, `salary_status_snapshot`, `payroll_reconciliation_ref`.

## Constraints and indexes

All tables have integer primary keys (`INT` except the two audit tables, which use `BIGINT`). Named unique constraints are:

- `uq_departments_name` on `departments.department_name`.
- `uq_shifts_name` on `shifts.shift_name`.
- `uq_users_employee_id` and `uq_users_email` on the corresponding `users` columns.
- `uq_attendance_employee_date` on `(employee_id, attendance_date)`.
- `uq_holidays_date` on `holiday_date`.
- `uq_salaries_employee_period` on `(employee_id, salary_period)`.
- `uq_aca_event_uuid` on `attendance_correction_audit.event_uuid`.
- `uq_aca_attendance_after_version` on `(attendance_id, after_row_version)`.

Other named indexes: `idx_users_shift_id`; `idx_leaves_employee_id`; `idx_leaves_employee_dates_status`; `idx_salaries_employee_id`; `idx_laa_leave_attendance`; `idx_laa_employee_date`; `idx_laa_conflicting_leave`; `idx_laa_actor_created`; `idx_laa_correlation`; `idx_aca_correlation_id`; `idx_aca_employee_date`; `idx_aca_actor_recorded`; `idx_aca_salary_period`.

The 13 foreign keys are: `users.shift_id` → `shifts.id`; `attendance.employee_id` → `users.employee_id`; `leaves.employee_id` → `users.employee_id`; `salaries.employee_id` → `users.employee_id`; `fk_users_shift_id_shifts`; `fk_attendance_employee`; `fk_leaves_employee`; `fk_salaries_employee`; `fk_laa_actor`, `fk_laa_attendance`, `fk_laa_conflicting_leave`, `fk_laa_employee`, `fk_laa_leave`; and `fk_aca_attendance`, `fk_aca_employee_user`, `fk_aca_actor_user`, `fk_aca_salary`. Audit references use RESTRICT behavior as declared in the installer.

Named CHECK constraints are `chk_shifts_early_checkin_range`, `chk_shifts_late_cutoff_range`, `chk_shifts_checkin_windows_do_not_overlap`, `chk_users_role`, `chk_users_status`, `chk_attendance_row_version`, `chk_attendance_policy_snapshot_pair_window`, `chk_leaves_date_order`, `chk_salaries_period_contract`, `chk_salaries_payment_status`, `chk_aca_version_increases`, `chk_aca_reason_nonblank_length`, `chk_aca_evidence_type_nonblank`, `chk_aca_evidence_reference_nonblank`, and `chk_aca_payroll_reconciliation_ref`. They cover shift check-in windows, supported roles/statuses, attendance row version and snapshot pairing, leave date ordering, salary period/payment status, and correction audit version/evidence requirements. The four triggers reject UPDATE/DELETE on both leave-attendance and attendance-correction audit tables.

## Business/schema compatibility notes

- Attendance stores both time-of-day fields and full `DATETIME` instants/schedule snapshots. The latter represent overnight shifts and next-calendar-day checkout. `row_version` supports optimistic concurrency; shift policy snapshots preserve the applied schedule context.
- Leave decisions can create Attendance effects and append-only audit evidence. The foreign keys and triggers protect those references/history from ordinary destructive changes.
- `salary_period` is the canonical first-of-month business key; `salary_month` remains for legacy-period compatibility. Employee-period uniqueness prevents duplicate current-period rows. `payment_status` is limited to `Pending` and `Paid`.
- Salary generation and payment are disabled. The schema’s salary records support historical views; they do not prove a payment workflow.
- `payroll_policy.php` and `payroll_preview.php` depend on a separate payroll-stage database and tables not included here. Stage candidate migrations are not part of the college installer and must not be run against this schema.
- No employee, salary, account, or fixture rows are included. There is no default Admin or password. See `README.md` for external DB configuration and the CLI-only one-time Admin bootstrap. The bootstrap validates this schema and does not seed default credentials. It has not been run against a fresh database; fresh-install runtime verification remains NOT VERIFIED.
