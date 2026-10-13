<?php

/**
 * Database-free static contract checks for the attendance HTTP workflow.
 * This verifies source structure only; it does not send HTTP requests,
 * connect to MariaDB, or prove the complete browser/session behavior.
 */

$root = dirname(__DIR__);
$assert = static function ($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
};
$read = static function ($relative) use ($root, $assert) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $source = file_get_contents($path);
    $assert($source !== false, "{$relative} is readable");
    return $source;
};
$before = static function ($source, $first, $second, $message) use ($assert) {
    $a = strpos($source, $first);
    $b = strpos($source, $second);
    $assert($a !== false && $b !== false && $a < $b, $message);
};

$attendance = $read('attendance.php');
$workflow = $read('includes/attendance_workflow.php');
$assert(strpos($attendance, 'auth_require_authenticated($conn)') !== false, 'attendance requires an active authenticated account');
$before($attendance, 'auth_require_authenticated($conn)', 'if (isset($_POST[\'checkin\']) || isset($_POST[\'checkout\']))', 'authenticated identity is established before attendance mutation handling');
$before($attendance, 'if (($_SERVER[\'REQUEST_METHOD\'] ?? \'\') !== \'POST\')', 'if (!csrf_validate_token($_POST[\'csrf_token\'] ?? null))', 'attendance rejects non-POST before validating CSRF');
$before($attendance, 'if (!csrf_validate_token($_POST[\'csrf_token\'] ?? null))', 'attendance_workflow_checkin($conn, $employee_id, $application_now)', 'attendance validates CSRF before dispatching mutation workflow');
$assert(strpos($attendance, "new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'))") !== false, 'HTTP controller supplies actual Asia/Kolkata server time');
$assert(strpos($attendance, 'auth_require_authenticated($conn)') !== false && strpos($attendance, 'attendance_workflow_checkin($conn, $employee_id, $application_now)') !== false, 'workflow receives employee identity only from authenticated controller');
$assert(strpos($workflow, 'attendance_find_current_open_checkout_candidates($conn, $employee_id, $now_sql, true)') !== false, 'checkout uses the shared bounded candidate selector under lock');
$assert(strpos($workflow, 'count($open_candidates) > 1') !== false, 'ambiguous checkout candidates are rejected');
$assert(strpos($workflow, 'WHERE id = ? AND row_version = ? AND check_out_at IS NULL') !== false, 'checkout uses a stale-version and still-open conditional update');
$assert(strpos($workflow, 'attendance_checkin_eligibility(') !== false, 'check-in uses the shared shift-aware eligibility engine');
$assert(strpos($workflow, '$business_date = $checkin_decision[\'business_date\'];') !== false, 'check-in uses the engine-selected business date');
$assert(strpos($workflow, 'attendance_shift_schedule($business_date, (string) $shift[\'in_time\'], (string) $shift[\'out_time\'])') !== false, 'check-in snapshots the assigned shift schedule for the selected business date');
$assert(strpos($attendance, 'attendance_has_checkin_policy_schema($conn)') !== false, 'check-in policy schema is checked fail-closed');
$assert(strpos($attendance, 'if (isset($_POST[\'checkin\']) && !$checkin_policy_schema_ready)') !== false, 'missing policy schema rejects POST check-in');
$assert(strpos($workflow, 'empty($checkin_decision[\'eligible\'])') !== false, 'POST check-in rejects ineligible windows before insertion');
$assert(strpos($workflow, 'early_checkin_minutes_snapshot,') !== false && strpos($workflow, 'late_checkin_cutoff_minutes_snapshot, status)') !== false, 'check-in stores both applied policy snapshots');
$assert(strpos($workflow, 'scheduled_start_at, scheduled_end_at,') !== false && strpos($workflow, 'late_after_at, shift_id_snapshot, shift_name_snapshot,') !== false, 'check-in stores schedule, late-threshold and shift identity snapshots');
$assert(strpos($attendance, 'attendance_checkin_policy_message($checkin_decision)') !== false && strpos($workflow, 'attendance_checkin_policy_message($checkin_decision)') !== false, 'UI and workflow use safe eligibility messages');
$assert(strpos($workflow, 'WHERE employee_id = ? AND attendance_date = ? LIMIT 1 FOR UPDATE') !== false, 'check-in locks and checks the employee business-date key');
$assert(strpos($workflow, 'mysqli_begin_transaction($conn)') !== false && strpos($workflow, 'mysqli_commit($conn)') !== false && strpos($workflow, 'mysqli_rollback($conn)') !== false, 'workflow owns matching transaction, commit and rollback paths');
$assert(strpos($workflow, 'DateTimeImmutable $now') !== false, 'workflow requires an explicit immutable timestamp');
$assert(strpos($workflow, '$_GET') === false && strpos($workflow, '$_POST') === false && strpos($workflow, '$_COOKIE') === false && strpos($workflow, '$_SERVER') === false, 'workflow has no request-controlled clock override');
$assert(strpos($attendance, 'csrf_field()') !== false, 'attendance form emits the shared CSRF field');
$assert(strpos($attendance, 'auth_current_employee_id') === false, 'attendance does not accept browser-supplied employee identity');

$time = $read('includes/attendance_time.php');
$assert(strpos($time, 'function attendance_find_current_open_checkout_candidates') !== false, 'shared checkout selector exists');
$assert(strpos($time, 'attendance_date BETWEEN ? AND ?') !== false, 'checkout SQL is bounded to current and previous business dates');
$assert(strpos($time, 'check_in_at <= ?') !== false && strpos($time, 'scheduled_end_at >= check_in_at') !== false, 'checkout SQL bounds candidate timestamps');
$assert(strpos($time, 'scheduled_end_at IS NOT NULL') !== false, 'rows without a scheduled-end snapshot are excluded');
$assert(strpos($time, 'attendance_is_current_open_checkout_candidate($row, $now)') !== false, 'candidate rows receive strict calendar-window validation');
$assert(strpos($time, '$business_date !== $today && $business_date !== $yesterday') !== false, 'historical business dates are excluded by the selector');
$assert(strpos($time, 'function attendance_shift_schedule') !== false && strpos($time, '$end_time <= $in_value') !== false, 'schedule helper supports next-day overnight ends');

$auto = $read('auto_absent.php');
$assert(strpos($auto, 'auth_require_admin($conn)') !== false, 'auto-absence is Admin-only');
$before($auto, 'if (($_SERVER[\'REQUEST_METHOD\'] ?? \'\') !== \'POST\')', 'if (!csrf_validate_token($_POST[\'csrf_token\'] ?? null))', 'auto-absence enforces POST before CSRF validation');
$before($auto, 'if (!csrf_validate_token($_POST[\'csrf_token\'] ?? null))', 'attendance_schedule_completed(', 'auto-absence validates CSRF before processing attendance');
$assert(strpos($auto, 'if (!attendance_schedule_completed($attendance[\'scheduled_end_at\'], $now->format(\'Y-m-d H:i:s\')))') !== false, 'open attendance is evaluated against its stored scheduled boundary');
$assert(strpos($auto, 'AND row_version = ? AND check_in_at IS NOT NULL AND check_out_at IS NULL') !== false, 'auto-absence update is conditional on the observed version and open state');
$assert(strpos($auto, '$already_half_day') !== false, 'auto-absence skips the exact existing no-op state');

$resolve = str_replace(["\r\n", "\r"], "\n", $read('resolve_leave_conflict.php'));
$before($resolve, 'auth_require_admin($conn)', 'if (($_SERVER[\'REQUEST_METHOD\'] ?? \'\') !== \'POST\')', 'conflict resolution authorizes Admin before request processing');
$before($resolve, 'if (($_SERVER[\'REQUEST_METHOD\'] ?? \'\') !== \'POST\')', 'if (!csrf_validate_token($_POST[\'csrf_token\'] ?? null))', 'conflict resolution enforces POST before CSRF');
$before($resolve, 'if (!csrf_validate_token($_POST[\'csrf_token\'] ?? null))', '$parse_positive_id = static function', 'conflict resolution validates CSRF before parsing mutation fields');
$assert(strpos($resolve, "SELECT id, employee_id, from_date, to_date, status\n         FROM leaves WHERE id = ? FOR UPDATE") !== false, 'conflict resolution locks the pending leave');
$assert(strpos($resolve, 'SELECT id, employee_id, attendance_date, check_in, check_out, check_in_at,') !== false && strpos($resolve, 'ORDER BY attendance_date, id FOR UPDATE') !== false, 'conflict resolution locks selected attendance conflict rows');
$assert(strpos($resolve, 'AND row_version = ?') !== false, 'Absent-to-Leave conversion checks the observed attendance version');
$assert(strpos($resolve, 'leaveConflictAuditAppend($conn, $resolution_event)') !== false, 'conflict decision audit is part of the request transaction');
$assert(strpos($resolve, "'reject_leave', 'approve_preserve_attendance', 'convert_absent_to_leave'") !== false, 'only explicit supported conflict decisions are accepted');

$auth = $read('includes/auth.php');
$assert(strpos($auth, 'function auth_require_authenticated($conn)') !== false && strpos($auth, 'function csrf_validate_token($submitted_token)') !== false, 'workflow relies on shared active-account and CSRF helpers');

echo "Static attendance HTTP workflow contract checks completed; no HTTP or database operations were performed.\n";
