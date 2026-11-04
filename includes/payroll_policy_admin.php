<?php

declare(strict_types=1);

require_once __DIR__ . '/payroll_database.php';

const COREAXIS_PAYROLL_POLICY_CURRENCY_DEFAULT = 'INR';
const COREAXIS_PAYROLL_POLICY_RULE_CODES = [
    'late', 'half_day', 'absent', 'paid_leave', 'unpaid_leave',
    'holiday_work', 'weekly_off_work', 'holiday_nonworkday', 'weekly_off_nonworkday',
];

function coreaxis_payroll_policy_admin_actor(mysqli $conn, string $employeeId): int
{
    $rows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
        $conn,
        "SELECT id FROM users WHERE employee_id = ? AND role = 'Admin' AND status = 'Active'",
        's', [$employeeId]
    ));
    if (count($rows) !== 1) { coreaxis_payroll_db_fail('active_admin_identity_required'); }
    return (int) $rows[0]['id'];
}

function coreaxis_payroll_policy_create_draft(mysqli $conn, int $actorId, array $input): int
{
    coreaxis_payroll_db_assert_stage($conn);
    coreaxis_payroll_db_require_schema($conn);
    $code = $input['policy_code'] ?? null;
    $version = filter_var($input['version_no'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    $from = $input['effective_from'] ?? null;
    $through = $input['effective_through'] ?? null;
    $currency = $input['currency'] ?? null;
    if (!is_string($code) || preg_match('/^[A-Z0-9][A-Z0-9_-]{0,63}$/D', $code) !== 1
        || $version === false || !coreaxis_payroll_is_iso_date($from) || !coreaxis_payroll_is_iso_date($through)
        || $from > $through || !is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
        coreaxis_payroll_db_fail('payroll_policy_configuration_invalid');
    }
    $rulesInput = $input['adjustment_amounts'] ?? null;
    if (!is_array($rulesInput) || count($rulesInput) !== count(COREAXIS_PAYROLL_POLICY_RULE_CODES)) {
        coreaxis_payroll_db_fail('payroll_policy_rules_incomplete');
    }
    $rules = [];
    foreach (COREAXIS_PAYROLL_POLICY_RULE_CODES as $ruleCode) {
        if (!array_key_exists($ruleCode, $rulesInput)) { coreaxis_payroll_db_fail('payroll_policy_rules_incomplete'); }
        $cents = coreaxis_payroll_amount_to_cents($rulesInput[$ruleCode]);
        if ($cents === false) { coreaxis_payroll_db_fail('payroll_policy_rule_amount_invalid'); }
        $rules[$ruleCode] = $cents;
    }
    foreach (['late','half_day','absent','unpaid_leave'] as $codeToDeduct) {
        if ($rules[$codeToDeduct] > 0) { coreaxis_payroll_db_fail('payroll_policy_rule_sign_invalid'); }
    }
    foreach (['paid_leave','holiday_work','weekly_off_work'] as $codeToEarn) {
        if ($rules[$codeToEarn] < 0) { coreaxis_payroll_db_fail('payroll_policy_rule_sign_invalid'); }
    }
    if (!is_bool($input['leave_counts_on_holidays'] ?? null)
        || !is_bool($input['leave_counts_on_weekly_offs'] ?? null)) {
        coreaxis_payroll_db_fail('payroll_policy_calendar_flags_required');
    }
    $classifications = $input['leave_classifications'] ?? null;
    if (!is_array($classifications) || count($classifications) < 1) {
        coreaxis_payroll_db_fail('payroll_leave_classification_required');
    }
    foreach ($classifications as $leaveType => $classification) {
        if (!is_string($leaveType) || trim($leaveType) === '' || strlen($leaveType) > 100
            || !in_array($classification, ['Paid','Unpaid'], true)) {
            coreaxis_payroll_db_fail('payroll_leave_classification_invalid');
        }
    }
    if (!mysqli_begin_transaction($conn)) { coreaxis_payroll_db_fail('payroll_policy_transaction_unavailable'); }
    try {
        $actor = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn, "SELECT id FROM users WHERE id = ? AND role = 'Admin' AND status = 'Active'", 'i', [$actorId]
        ));
        if (count($actor) !== 1) { coreaxis_payroll_db_fail('active_admin_identity_required'); }
        $stmt = mysqli_prepare($conn,
            "INSERT INTO payroll_policy_versions
             (policy_code,version_no,effective_from,effective_through,currency,policy_status,
              leave_counts_on_holidays,leave_counts_on_weekly_offs,created_by_user_id)
             VALUES (?,?,?,?,?,'Draft',?,?,?)"
        );
        if (!$stmt) { coreaxis_payroll_db_fail('payroll_policy_write_unavailable'); }
        $holidayFlag = (int) $input['leave_counts_on_holidays'];
        $weeklyFlag = (int) $input['leave_counts_on_weekly_offs'];
        if (!mysqli_stmt_bind_param($stmt, 'sisssiii', $code, $version, $from, $through, $currency, $holidayFlag, $weeklyFlag, $actorId)
            || !mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); coreaxis_payroll_db_fail('payroll_policy_write_unavailable'); }
        $policyId = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        $ruleStmt = mysqli_prepare($conn, 'INSERT INTO payroll_policy_rules (policy_version_id,rule_code,adjustment_cents) VALUES (?,?,?)');
        if (!$ruleStmt) { coreaxis_payroll_db_fail('payroll_policy_write_unavailable'); }
        foreach ($rules as $ruleCode => $amountCents) {
            if (!mysqli_stmt_bind_param($ruleStmt, 'isi', $policyId, $ruleCode, $amountCents)
                || !mysqli_stmt_execute($ruleStmt)) { mysqli_stmt_close($ruleStmt); coreaxis_payroll_db_fail('payroll_policy_write_unavailable'); }
        }
        mysqli_stmt_close($ruleStmt);
        $leaveStmt = mysqli_prepare($conn, 'INSERT INTO payroll_leave_classifications (policy_version_id,leave_type,pay_classification) VALUES (?,?,?)');
        if (!$leaveStmt) { coreaxis_payroll_db_fail('payroll_policy_write_unavailable'); }
        foreach ($classifications as $leaveType => $classification) {
            if (!mysqli_stmt_bind_param($leaveStmt, 'iss', $policyId, $leaveType, $classification)
                || !mysqli_stmt_execute($leaveStmt)) { mysqli_stmt_close($leaveStmt); coreaxis_payroll_db_fail('payroll_policy_write_unavailable'); }
        }
        mysqli_stmt_close($leaveStmt);
        if (!mysqli_commit($conn)) { coreaxis_payroll_db_fail('payroll_policy_write_unavailable'); }
        return $policyId;
    } catch (Throwable $error) {
        mysqli_rollback($conn);
        throw $error;
    }
}

/** Approve an immutable policy version after distinct-admin and overlap checks. */
function coreaxis_payroll_policy_approve(mysqli $conn, int $actorId, int $policyId, string $reason): void
{
    coreaxis_payroll_db_assert_stage($conn);
    coreaxis_payroll_db_require_schema($conn);
    $reason = trim($reason);
    if ($policyId < 1 || $reason === '' || strlen($reason) > 500) {
        coreaxis_payroll_db_fail('payroll_policy_approval_input_invalid');
    }
    $lockResult = mysqli_query($conn, "SELECT GET_LOCK('coreaxis_payroll_policy_approval_stage', 10) AS acquired");
    $lockRow = $lockResult ? mysqli_fetch_assoc($lockResult) : null;
    if ($lockResult) { mysqli_free_result($lockResult); }
    if (!is_array($lockRow) || (int) ($lockRow['acquired'] ?? 0) !== 1) {
        coreaxis_payroll_db_fail('payroll_policy_approval_lock_unavailable');
    }
    if (!mysqli_begin_transaction($conn)) { mysqli_query($conn, "SELECT RELEASE_LOCK('coreaxis_payroll_policy_approval_stage')"); coreaxis_payroll_db_fail('payroll_policy_transaction_unavailable'); }
    try {
        $rows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn,
            "SELECT id,created_by_user_id,effective_from,effective_through,policy_status
             FROM payroll_policy_versions WHERE id = ? FOR UPDATE",
            'i', [$policyId]
        ));
        if (count($rows) !== 1 || $rows[0]['policy_status'] !== 'Draft'
            || (int) $rows[0]['created_by_user_id'] === $actorId) {
            coreaxis_payroll_db_fail('payroll_policy_requires_distinct_admin_approval');
        }
        $actor = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn, "SELECT id FROM users WHERE id = ? AND role = 'Admin' AND status = 'Active'", 'i', [$actorId]
        ));
        if (count($actor) !== 1) { coreaxis_payroll_db_fail('active_admin_identity_required'); }
        $overlap = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn,
            "SELECT id FROM payroll_policy_versions WHERE policy_status = 'Approved'
             AND effective_from <= ? AND effective_through >= ? LIMIT 1 FOR UPDATE",
            'ss', [$rows[0]['effective_through'], $rows[0]['effective_from']]
        ));
        if ($overlap) { coreaxis_payroll_db_fail('approved_payroll_policy_period_overlap'); }
        $stmt = mysqli_prepare($conn,
            "UPDATE payroll_policy_versions SET policy_status='Approved',approved_by_user_id=?,approved_at=NOW(),approval_reason=?
             WHERE id=? AND policy_status='Draft'"
        );
        if (!$stmt) { coreaxis_payroll_db_fail('payroll_policy_write_unavailable'); }
        if (!mysqli_stmt_bind_param($stmt, 'isi', $actorId, $reason, $policyId)
            || !mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) !== 1) {
            mysqli_stmt_close($stmt);
            coreaxis_payroll_db_fail('payroll_policy_write_unavailable');
        }
        mysqli_stmt_close($stmt);
        if (!mysqli_commit($conn)) { coreaxis_payroll_db_fail('payroll_policy_write_unavailable'); }
    } catch (Throwable $error) {
        mysqli_rollback($conn);
        throw $error;
    } finally {
        mysqli_query($conn, "SELECT RELEASE_LOCK('coreaxis_payroll_policy_approval_stage')");
    }
}

function coreaxis_payroll_schedule_create_draft(mysqli $conn, int $actorId, array $input): int
{
    coreaxis_payroll_db_assert_stage($conn);
    coreaxis_payroll_db_require_schema($conn);
    $employeeId = $input['employee_id'] ?? null;
    $version = filter_var($input['version_no'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    $shiftId = filter_var($input['shift_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    $from = $input['effective_from'] ?? null;
    $through = $input['effective_through'] ?? null;
    $weekdays = $input['weekdays'] ?? null;
    if (!is_string($employeeId) || preg_match('/^EMP[0-9]{3,17}$/iD', $employeeId) !== 1
        || $version === false || $shiftId === false || !coreaxis_payroll_is_iso_date($from)
        || !coreaxis_payroll_is_iso_date($through) || $from > $through
        || !is_array($weekdays) || count($weekdays) < 1) {
        coreaxis_payroll_db_fail('payroll_schedule_configuration_invalid');
    }
    $mask = 0;
    $seenWeekdays = [];
    foreach ($weekdays as $weekday) {
        $weekday = filter_var($weekday, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7]]);
        if ($weekday === false || isset($seenWeekdays[$weekday])) { coreaxis_payroll_db_fail('payroll_schedule_configuration_invalid'); }
        $seenWeekdays[$weekday] = true;
        $mask |= 1 << ($weekday - 1);
    }
    if (!mysqli_begin_transaction($conn)) { coreaxis_payroll_db_fail('payroll_schedule_transaction_unavailable'); }
    try {
        $employeeRows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn, "SELECT id FROM users WHERE employee_id=? AND role='Employee' AND status='Active'", 's', [$employeeId]
        ));
        $shiftRows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn, "SELECT id,shift_name,in_time,out_time FROM shifts WHERE id=? AND status='Active'", 'i', [$shiftId]
        ));
        if (count($employeeRows) !== 1 || count($shiftRows) !== 1) {
            coreaxis_payroll_db_fail('payroll_schedule_employee_or_shift_unavailable');
        }
        $stmt = mysqli_prepare($conn,
            "INSERT INTO payroll_work_schedule_versions
             (employee_user_id,version_no,effective_from,effective_through,shift_id,shift_name_snapshot,
              in_time_snapshot,out_time_snapshot,weekdays_mask,schedule_status,created_by_user_id)
             VALUES (?,?,?,?,?,?,?,?,?,'Draft',?)"
        );
        if (!$stmt) { coreaxis_payroll_db_fail('payroll_schedule_write_unavailable'); }
        $employeeUserId = (int) $employeeRows[0]['id'];
        $shiftName = $shiftRows[0]['shift_name'];
        $inTime = $shiftRows[0]['in_time'];
        $outTime = $shiftRows[0]['out_time'];
        if (!mysqli_stmt_bind_param($stmt, 'iississsii', $employeeUserId, $version, $from, $through, $shiftId, $shiftName, $inTime, $outTime, $mask, $actorId)
            || !mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); coreaxis_payroll_db_fail('payroll_schedule_write_unavailable'); }
        $scheduleId = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        if (!mysqli_commit($conn)) { coreaxis_payroll_db_fail('payroll_schedule_write_unavailable'); }
        return $scheduleId;
    } catch (Throwable $error) {
        mysqli_rollback($conn);
        throw $error;
    }
}

function coreaxis_payroll_schedule_approve(mysqli $conn, int $actorId, int $scheduleId, string $reason): void
{
    coreaxis_payroll_db_assert_stage($conn);
    coreaxis_payroll_db_require_schema($conn);
    $reason = trim($reason);
    if ($scheduleId < 1 || $reason === '' || strlen($reason) > 500) {
        coreaxis_payroll_db_fail('payroll_schedule_approval_input_invalid');
    }
    $lock = mysqli_query($conn, "SELECT GET_LOCK('coreaxis_payroll_schedule_approval_stage',10) AS acquired");
    $lockRow = $lock ? mysqli_fetch_assoc($lock) : null;
    if ($lock) { mysqli_free_result($lock); }
    if (!is_array($lockRow) || (int) ($lockRow['acquired'] ?? 0) !== 1) {
        coreaxis_payroll_db_fail('payroll_schedule_approval_lock_unavailable');
    }
    if (!mysqli_begin_transaction($conn)) { mysqli_query($conn, "SELECT RELEASE_LOCK('coreaxis_payroll_schedule_approval_stage')"); coreaxis_payroll_db_fail('payroll_schedule_transaction_unavailable'); }
    try {
        $rows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn,
            "SELECT id,employee_user_id,created_by_user_id,effective_from,effective_through,schedule_status
             FROM payroll_work_schedule_versions WHERE id=? FOR UPDATE",
            'i', [$scheduleId]
        ));
        if (count($rows) !== 1 || $rows[0]['schedule_status'] !== 'Draft'
            || (int) $rows[0]['created_by_user_id'] === $actorId) {
            coreaxis_payroll_db_fail('payroll_schedule_requires_distinct_admin_approval');
        }
        $overlap = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn,
            "SELECT id FROM payroll_work_schedule_versions WHERE employee_user_id=? AND schedule_status='Approved'
             AND effective_from<=? AND effective_through>=? LIMIT 1 FOR UPDATE",
            'iss', [(int) $rows[0]['employee_user_id'], $rows[0]['effective_through'], $rows[0]['effective_from']]
        ));
        if ($overlap) { coreaxis_payroll_db_fail('payroll_schedule_period_overlap'); }
        $actor = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn, "SELECT id FROM users WHERE id=? AND role='Admin' AND status='Active'", 'i', [$actorId]
        ));
        if (count($actor) !== 1) { coreaxis_payroll_db_fail('active_admin_identity_required'); }
        $stmt = mysqli_prepare($conn,
            "UPDATE payroll_work_schedule_versions SET schedule_status='Approved',approved_by_user_id=?,approved_at=NOW(),approval_reason=?
             WHERE id=? AND schedule_status='Draft'"
        );
        if (!$stmt) { coreaxis_payroll_db_fail('payroll_schedule_write_unavailable'); }
        if (!mysqli_stmt_bind_param($stmt, 'isi', $actorId, $reason, $scheduleId)
            || !mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) !== 1) {
            mysqli_stmt_close($stmt);
            coreaxis_payroll_db_fail('payroll_schedule_write_unavailable');
        }
        mysqli_stmt_close($stmt);
        if (!mysqli_commit($conn)) { coreaxis_payroll_db_fail('payroll_schedule_write_unavailable'); }
    } catch (Throwable $error) {
        mysqli_rollback($conn);
        throw $error;
    } finally {
        mysqli_query($conn, "SELECT RELEASE_LOCK('coreaxis_payroll_schedule_approval_stage')");
    }
}

function coreaxis_payroll_compensation_create_draft(mysqli $conn, int $actorId, array $input): int
{
    coreaxis_payroll_db_assert_stage($conn);
    coreaxis_payroll_db_require_schema($conn);
    $employeeId = $input['employee_id'] ?? null;
    $version = filter_var($input['version_no'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    $from = $input['effective_from'] ?? null;
    $through = $input['effective_through'] ?? null;
    $currency = $input['currency'] ?? null;
    $basic = coreaxis_payroll_amount_to_cents($input['basic_salary'] ?? null);
    $incentive = coreaxis_payroll_amount_to_cents($input['incentive'] ?? null);
    if (!is_string($employeeId) || preg_match('/^EMP[0-9]{3,17}$/iD', $employeeId) !== 1
        || $version === false || !coreaxis_payroll_is_iso_date($from) || !coreaxis_payroll_is_iso_date($through)
        || $from > $through || !is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
        || $basic === false || $incentive === false || $basic < 0 || $incentive < 0) {
        coreaxis_payroll_db_fail('payroll_compensation_configuration_invalid');
    }
    if (!mysqli_begin_transaction($conn)) { coreaxis_payroll_db_fail('payroll_compensation_transaction_unavailable'); }
    try {
        $employeeRows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn, "SELECT id FROM users WHERE employee_id=? AND role='Employee' AND status='Active'", 's', [$employeeId]
        ));
        if (count($employeeRows) !== 1) { coreaxis_payroll_db_fail('payroll_compensation_employee_unavailable'); }
        $employeeUserId = (int) $employeeRows[0]['id'];
        $stmt = mysqli_prepare($conn,
            "INSERT INTO payroll_compensation_versions
             (employee_user_id,version_no,effective_from,effective_through,basic_salary_cents,incentive_cents,currency,compensation_status,created_by_user_id)
             VALUES (?,?,?,?,?,?,?,'Draft',?)"
        );
        if (!$stmt) { coreaxis_payroll_db_fail('payroll_compensation_write_unavailable'); }
        if (!mysqli_stmt_bind_param($stmt, 'iissiisi', $employeeUserId, $version, $from, $through, $basic, $incentive, $currency, $actorId)
            || !mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); coreaxis_payroll_db_fail('payroll_compensation_write_unavailable'); }
        $id = (int) mysqli_insert_id($conn);
        mysqli_stmt_close($stmt);
        if (!mysqli_commit($conn)) { coreaxis_payroll_db_fail('payroll_compensation_write_unavailable'); }
        return $id;
    } catch (Throwable $error) {
        mysqli_rollback($conn);
        throw $error;
    }
}

function coreaxis_payroll_compensation_approve(mysqli $conn, int $actorId, int $compensationId, string $reason): void
{
    coreaxis_payroll_db_assert_stage($conn);
    coreaxis_payroll_db_require_schema($conn);
    $reason = trim($reason);
    if ($compensationId < 1 || $reason === '' || strlen($reason) > 500) {
        coreaxis_payroll_db_fail('payroll_compensation_approval_input_invalid');
    }
    $lock = mysqli_query($conn, "SELECT GET_LOCK('coreaxis_payroll_compensation_approval_stage',10) AS acquired");
    $lockRow = $lock ? mysqli_fetch_assoc($lock) : null;
    if ($lock) { mysqli_free_result($lock); }
    if (!is_array($lockRow) || (int) ($lockRow['acquired'] ?? 0) !== 1) {
        coreaxis_payroll_db_fail('payroll_compensation_approval_lock_unavailable');
    }
    if (!mysqli_begin_transaction($conn)) {
        mysqli_query($conn, "SELECT RELEASE_LOCK('coreaxis_payroll_compensation_approval_stage')");
        coreaxis_payroll_db_fail('payroll_compensation_transaction_unavailable');
    }
    try {
        $rows = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn,
            "SELECT id,employee_user_id,created_by_user_id,effective_from,effective_through,compensation_status
             FROM payroll_compensation_versions WHERE id=? FOR UPDATE",
            'i', [$compensationId]
        ));
        if (count($rows) !== 1 || $rows[0]['compensation_status'] !== 'Draft'
            || (int) $rows[0]['created_by_user_id'] === $actorId) {
            coreaxis_payroll_db_fail('payroll_compensation_requires_distinct_admin_approval');
        }
        $overlap = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn,
            "SELECT id FROM payroll_compensation_versions WHERE employee_user_id=? AND compensation_status='Approved'
             AND effective_from<=? AND effective_through>=? LIMIT 1 FOR UPDATE",
            'iss', [(int) $rows[0]['employee_user_id'], $rows[0]['effective_through'], $rows[0]['effective_from']]
        ));
        if ($overlap) { coreaxis_payroll_db_fail('payroll_compensation_period_overlap'); }
        $actor = coreaxis_payroll_db_rows(coreaxis_payroll_db_execute(
            $conn, "SELECT id FROM users WHERE id=? AND role='Admin' AND status='Active'", 'i', [$actorId]
        ));
        if (count($actor) !== 1) { coreaxis_payroll_db_fail('active_admin_identity_required'); }
        $stmt = mysqli_prepare($conn,
            "UPDATE payroll_compensation_versions SET compensation_status='Approved',approved_by_user_id=?,approved_at=NOW(),approval_reason=?
             WHERE id=? AND compensation_status='Draft'"
        );
        if (!$stmt) { coreaxis_payroll_db_fail('payroll_compensation_write_unavailable'); }
        if (!mysqli_stmt_bind_param($stmt, 'isi', $actorId, $reason, $compensationId)
            || !mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) !== 1) {
            mysqli_stmt_close($stmt);
            coreaxis_payroll_db_fail('payroll_compensation_write_unavailable');
        }
        mysqli_stmt_close($stmt);
        if (!mysqli_commit($conn)) { coreaxis_payroll_db_fail('payroll_compensation_write_unavailable'); }
    } catch (Throwable $error) {
        mysqli_rollback($conn);
        throw $error;
    } finally {
        mysqli_query($conn, "SELECT RELEASE_LOCK('coreaxis_payroll_compensation_approval_stage')");
    }
}
