<?php

require_once __DIR__ . '/attendance_time.php';

/** Load only the authenticated employee's current active assigned shift. */
function attendance_load_active_checkin_shift($conn, $employee_id, $for_update = false)
{
    $sql = "SELECT s.id AS shift_id, s.shift_name, s.in_time, s.out_time, s.late_after,
                   s.early_checkin_minutes, s.late_checkin_cutoff_minutes
            FROM users u INNER JOIN shifts s ON s.id = u.shift_id
            WHERE u.employee_id = ? AND u.status = 'Active' AND s.status = 'Active'";
    if ($for_update) {
        $sql .= ' FOR UPDATE';
    }
    $statement = mysqli_prepare($conn, $sql);
    if (!$statement || !mysqli_stmt_bind_param($statement, 's', $employee_id) || !mysqli_stmt_execute($statement)) {
        if ($statement) {
            mysqli_stmt_close($statement);
        }
        return ['ok' => false, 'shift' => null];
    }
    $result = mysqli_stmt_get_result($statement);
    if (!$result) {
        mysqli_stmt_close($statement);
        return ['ok' => false, 'shift' => null];
    }
    $shift = mysqli_fetch_assoc($result) ?: null;
    mysqli_stmt_close($statement);
    return ['ok' => true, 'shift' => $shift];
}

/** Safe, fixed user messages for policy decisions. */
function attendance_checkin_policy_message($decision)
{
    $reason = is_array($decision) ? ($decision['rejection_reason'] ?? '') : '';
    if ($reason === 'before_early_checkin_window') {
        $opens = $decision['permitted_window']['start'] ?? null;
        return $opens ? 'Check-in is not available yet. The check-in window opens at ' . $opens . '.' : 'Check-in is not available yet.';
    }
    if ($reason === 'after_late_checkin_cutoff') {
        return 'The check-in window for this shift has closed. Please contact HR if an exception is needed.';
    }
    if ($reason === 'ambiguous_business_date' || $reason === 'overlapping_business_date_windows') {
        return 'The shift check-in window is ambiguous. Please contact HR.';
    }
    if ($reason === 'invalid_policy') {
        return 'The assigned shift check-in policy is invalid. Please contact HR.';
    }
    if ($reason !== '') {
        return 'The assigned shift schedule is invalid or unavailable. Please contact HR.';
    }
    return '';
}

/**
 * Execute an authenticated caller's check-in operation transactionally.
 * $now is an internal dependency; the HTTP layer must supply server time and
 * must never source it from request data.
 */
function attendance_workflow_checkin($conn, $employee_id, DateTimeImmutable $now)
{
    $now = $now->setTimezone(new DateTimeZone('Asia/Kolkata'));
    $now_sql = $now->format('Y-m-d H:i:s');
    $time_sql = $now->format('H:i:s');
    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'http_status' => 500, 'message' => 'Attendance could not be processed.'];
    }

    try {
        // Lock the authenticated user's row to serialize check-in with the
        // other attendance writers until a database unique key is added.
        $shift_lookup = attendance_load_active_checkin_shift($conn, $employee_id, true);
        if (!$shift_lookup['ok']) {
            throw new RuntimeException('shift lookup failed');
        }
        $shift = $shift_lookup['shift'];
        if (!$shift) {
            throw new DomainException('An active shift is not assigned. Please contact HR.');
        }
        $checkin_decision = attendance_checkin_eligibility(
            $now_sql,
            (string) $shift['in_time'],
            (string) $shift['out_time'],
            $shift['early_checkin_minutes'],
            $shift['late_checkin_cutoff_minutes']
        );
        if (empty($checkin_decision['eligible'])) {
            throw new DomainException(attendance_checkin_policy_message($checkin_decision));
        }

        $business_date = $checkin_decision['business_date'];
        $schedule = attendance_shift_schedule($business_date, (string) $shift['in_time'], (string) $shift['out_time']);
        $late_threshold = attendance_shift_late_threshold($business_date, (string) $shift['late_after']);
        $shift_id = filter_var((string) $shift['shift_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $early_policy = attendance_checkin_policy_minutes($shift['early_checkin_minutes']);
        $late_policy = attendance_checkin_policy_minutes($shift['late_checkin_cutoff_minutes']);
        if (!$schedule || !$late_threshold || $shift_id === false || $early_policy === false || $late_policy === false || trim((string) $shift['shift_name']) === '') {
            throw new DomainException('The assigned shift policy or schedule is invalid. Please contact HR.');
        }

        $existing_statement = mysqli_prepare($conn, 'SELECT id FROM attendance WHERE employee_id = ? AND attendance_date = ? LIMIT 1 FOR UPDATE');
        if (!$existing_statement || !mysqli_stmt_bind_param($existing_statement, 'ss', $employee_id, $business_date) || !mysqli_stmt_execute($existing_statement)) {
            if ($existing_statement) {
                mysqli_stmt_close($existing_statement);
            }
            throw new RuntimeException('attendance lookup failed');
        }
        $existing_result = mysqli_stmt_get_result($existing_statement);
        $existing = $existing_result ? mysqli_fetch_assoc($existing_result) : null;
        mysqli_stmt_close($existing_statement);
        if ($existing) {
            throw new DomainException('Attendance is already marked for this shift date.');
        }

        $leave_statement = mysqli_prepare($conn, "SELECT id FROM leaves WHERE employee_id = ? AND status = 'Approved' AND ? BETWEEN from_date AND to_date LIMIT 1");
        if (!$leave_statement || !mysqli_stmt_bind_param($leave_statement, 'ss', $employee_id, $business_date) || !mysqli_stmt_execute($leave_statement)) {
            if ($leave_statement) {
                mysqli_stmt_close($leave_statement);
            }
            throw new RuntimeException('leave lookup failed');
        }
        $leave_result = mysqli_stmt_get_result($leave_statement);
        $on_leave = $leave_result && mysqli_num_rows($leave_result) > 0;
        mysqli_stmt_close($leave_statement);
        if ($on_leave) {
            throw new DomainException('You are already on approved leave for this shift date.');
        }

        $status = (new DateTimeImmutable($business_date, new DateTimeZone('Asia/Kolkata')))->format('l') === 'Sunday'
            ? 'Sunday Working'
            : (attendance_is_late($business_date, $now_sql, (string) $shift['late_after']) ? 'Late' : 'Present');
        $scheduled_start = $schedule['start']->format('Y-m-d H:i:s');
        $scheduled_end = $schedule['end']->format('Y-m-d H:i:s');
        $late_after_at = $late_threshold->format('Y-m-d H:i:s');
        $insert = mysqli_prepare(
            $conn,
            'INSERT INTO attendance
             (employee_id, attendance_date, check_in, check_in_at, scheduled_start_at, scheduled_end_at,
              late_after_at, shift_id_snapshot, shift_name_snapshot, early_checkin_minutes_snapshot,
              late_checkin_cutoff_minutes_snapshot, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$insert) {
            throw new RuntimeException('attendance insert preparation failed');
        }
        $shift_name = (string) $shift['shift_name'];
        if (!mysqli_stmt_bind_param(
            $insert,
            'sssssssisiis',
            $employee_id,
            $business_date,
            $time_sql,
            $now_sql,
            $scheduled_start,
            $scheduled_end,
            $late_after_at,
            $shift_id,
            $shift_name,
            $early_policy,
            $late_policy,
            $status
        )) {
            mysqli_stmt_close($insert);
            throw new RuntimeException('attendance insert binding failed');
        }
        $inserted = mysqli_stmt_execute($insert);
        $insert_error = mysqli_stmt_errno($insert);
        $inserted_rows = mysqli_stmt_affected_rows($insert);
        mysqli_stmt_close($insert);
        if (!$inserted && $insert_error === 1062) {
            throw new DomainException('Attendance is already marked for this shift date.');
        }
        if (!$inserted || $inserted_rows !== 1) {
            throw new RuntimeException('attendance insert failed');
        }
        if (!mysqli_commit($conn)) {
            throw new RuntimeException('attendance commit failed');
        }
        return ['ok' => true, 'message' => 'Check-In Successful', 'business_date' => $business_date];
    } catch (Throwable $error) {
        mysqli_rollback($conn);
        return [
            'ok' => false,
            'message' => ($error instanceof DomainException) ? $error->getMessage() : 'Attendance could not be processed. Please try again.'
        ];
    }
}

/** Execute checkout against the single bounded open candidate transactionally. */
function attendance_workflow_checkout($conn, $employee_id, DateTimeImmutable $now)
{
    $now = $now->setTimezone(new DateTimeZone('Asia/Kolkata'));
    $now_sql = $now->format('Y-m-d H:i:s');
    $time_sql = $now->format('H:i:s');
    if (!mysqli_begin_transaction($conn)) {
        return ['ok' => false, 'http_status' => 500, 'message' => 'Attendance could not be processed.'];
    }

    try {
        $open_candidates = attendance_find_current_open_checkout_candidates($conn, $employee_id, $now_sql, true);
        if ($open_candidates === false) {
            throw new RuntimeException('open attendance lookup failed');
        }
        if (count($open_candidates) > 1) {
            throw new DomainException('More than one current open attendance record needs review. Please contact HR.');
        }
        $open = $open_candidates[0] ?? null;
        if (!$open || !($check_in_at = attendance_format_datetime($open['check_in_at']))) {
            throw new DomainException('No valid open check-in record was found.');
        }
        $expected_version = attendance_row_version_value($open);
        if ($expected_version === false) {
            throw new DomainException('The open attendance record needs review before checkout.');
        }
        $total_seconds = attendance_elapsed_seconds($check_in_at, $now_sql);
        if ($total_seconds === false) {
            throw new DomainException('Checkout time must be after check-in time.');
        }
        $total_hours = (int) floor($total_seconds / 3600);
        $total_minutes = (int) floor(($total_seconds % 3600) / 60);
        $working_hours = $total_hours . ' hrs ' . $total_minutes . ' mins';
        $status = attendance_status_after_checkout($open['status'], $total_seconds);
        $update = mysqli_prepare($conn, 'UPDATE attendance SET check_out = ?, check_out_at = ?, working_hours = ?, status = ?, row_version = row_version + 1 WHERE id = ? AND row_version = ? AND check_out_at IS NULL');
        if (!$update) {
            throw new RuntimeException('checkout update preparation failed');
        }
        if (!mysqli_stmt_bind_param($update, 'ssssii', $time_sql, $now_sql, $working_hours, $status, $open['id'], $expected_version)) {
            mysqli_stmt_close($update);
            throw new RuntimeException('checkout update binding failed');
        }
        $updated = mysqli_stmt_execute($update) && mysqli_stmt_affected_rows($update) === 1;
        mysqli_stmt_close($update);
        if (!$updated) {
            throw new RuntimeException('checkout update failed');
        }
        if (!mysqli_commit($conn)) {
            throw new RuntimeException('attendance commit failed');
        }
        return ['ok' => true, 'message' => 'Check-Out Successful'];
    } catch (Throwable $error) {
        mysqli_rollback($conn);
        return [
            'ok' => false,
            'message' => ($error instanceof DomainException) ? $error->getMessage() : 'Attendance could not be processed. Please try again.'
        ];
    }
}
