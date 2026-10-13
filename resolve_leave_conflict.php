<?php

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/leave_conflicts.php';
require_once __DIR__ . '/includes/attendance_time.php';

auth_require_admin($conn);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST', true, 405);
    exit('Method Not Allowed');
}

if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Forbidden');
}

$parse_positive_id = static function ($value) {
    if (
        !is_string($value)
        || !preg_match('/^[1-9][0-9]*$/D', $value)
        || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
    ) {
        return false;
    }
    return (int) $value;
};

$leave_id = $parse_positive_id($_POST['leave_id'] ?? null);
$raw_attendance_id = $_POST['attendance_id'] ?? null;
$attendance_id = ($raw_attendance_id === null || $raw_attendance_id === '')
    ? null
    : $parse_positive_id($raw_attendance_id);
$decision = $_POST['decision'] ?? null;
$reason = $_POST['reason'] ?? null;

if ($leave_id === false || ($raw_attendance_id !== null && $raw_attendance_id !== '' && $attendance_id === false)) {
    http_response_code(400);
    exit('Invalid conflict reference');
}

$allowed_decisions = ['reject_leave', 'approve_preserve_attendance', 'convert_absent_to_leave'];
if (!is_string($decision) || !in_array($decision, $allowed_decisions, true)) {
    http_response_code(400);
    exit('Invalid resolution decision');
}

if (
    !is_string($reason)
    || trim($reason) === ''
    || strlen($reason) > 4000
    || preg_match('//u', $reason) !== 1
) {
    http_response_code(400);
    exit('A valid reason is required');
}
$reason = trim($reason);

if ($decision !== 'reject_leave' && $attendance_id === null) {
    http_response_code(400);
    exit('Attendance reference is required');
}
if ($decision === 'convert_absent_to_leave' && !attendance_has_row_version_column($conn)) {
    http_response_code(503);
    exit('Attendance versioning migration is required before resolving this conflict.');
}

$redirect = static function ($result) {
    header('Location: manage_leave.php?result=' . rawurlencode($result));
    exit();
};

$transaction_started = false;
try {
    if (!mysqli_begin_transaction($conn)) {
        throw new RuntimeException('Conflict resolution transaction could not start.');
    }
    $transaction_started = true;

    // Read the owner without locking so all leave flows use the same
    // employee-row-then-leave-row lock order as leave submission.
    $owner_statement = mysqli_prepare(
        $conn,
        'SELECT employee_id FROM leaves WHERE id = ?'
    );
    if ($owner_statement === false) {
        throw new RuntimeException('Leave request could not be loaded.');
    }
    mysqli_stmt_bind_param($owner_statement, 'i', $leave_id);
    if (!mysqli_stmt_execute($owner_statement)) {
        mysqli_stmt_close($owner_statement);
        throw new RuntimeException('Leave request could not be loaded.');
    }
    $owner_result = mysqli_stmt_get_result($owner_statement);
    $owner = $owner_result ? mysqli_fetch_assoc($owner_result) : null;
    mysqli_stmt_close($owner_statement);
    if (!$owner || !is_string($owner['employee_id']) || trim($owner['employee_id']) === '') {
        throw new DomainException('The leave request is no longer available.');
    }

    $employee_lock = mysqli_prepare($conn, 'SELECT id FROM users WHERE employee_id = ? FOR UPDATE');
    if ($employee_lock === false) {
        throw new RuntimeException('Employee could not be locked.');
    }
    mysqli_stmt_bind_param($employee_lock, 's', $owner['employee_id']);
    if (!mysqli_stmt_execute($employee_lock)) {
        mysqli_stmt_close($employee_lock);
        throw new RuntimeException('Employee could not be locked.');
    }
    $employee_result = mysqli_stmt_get_result($employee_lock);
    $employee = $employee_result ? mysqli_fetch_assoc($employee_result) : null;
    mysqli_stmt_close($employee_lock);
    if (!$employee) {
        throw new DomainException('The leave request employee could not be found.');
    }

    $leave_statement = mysqli_prepare(
        $conn,
        'SELECT id, employee_id, from_date, to_date, status
         FROM leaves WHERE id = ? FOR UPDATE'
    );
    if ($leave_statement === false) {
        throw new RuntimeException('Leave request could not be locked.');
    }
    mysqli_stmt_bind_param($leave_statement, 'i', $leave_id);
    if (!mysqli_stmt_execute($leave_statement)) {
        mysqli_stmt_close($leave_statement);
        throw new RuntimeException('Leave request could not be locked.');
    }
    $leave_result = mysqli_stmt_get_result($leave_statement);
    $leave = $leave_result ? mysqli_fetch_assoc($leave_result) : null;
    mysqli_stmt_close($leave_statement);
    if (
        !$leave
        || $leave['status'] !== 'Pending'
        || !is_string($leave['employee_id'])
        || strcasecmp($leave['employee_id'], $owner['employee_id']) !== 0
    ) {
        throw new DomainException('The leave request is no longer pending.');
    }

    $first_date = leaveConflictParseDate($leave['from_date']);
    $last_date = leaveConflictParseDate($leave['to_date']);
    if ($first_date === false || $last_date === false || $first_date > $last_date) {
        throw new DomainException('The leave request dates are invalid.');
    }
    $from_date = $first_date->format('Y-m-d');
    $to_date = $last_date->format('Y-m-d');

    $actor_employee_id = auth_current_employee_id();
    $actor_statement = mysqli_prepare(
        $conn,
        "SELECT id FROM users WHERE employee_id = ? AND role = 'Admin' AND status = 'Active' LIMIT 1"
    );
    if ($actor_statement === false) {
        throw new RuntimeException('Admin identity could not be verified.');
    }
    mysqli_stmt_bind_param($actor_statement, 's', $actor_employee_id);
    if (!mysqli_stmt_execute($actor_statement)) {
        mysqli_stmt_close($actor_statement);
        throw new RuntimeException('Admin identity could not be verified.');
    }
    $actor_result = mysqli_stmt_get_result($actor_statement);
    $actor = $actor_result ? mysqli_fetch_assoc($actor_result) : null;
    mysqli_stmt_close($actor_statement);
    if (!$actor) {
        throw new DomainException('Admin identity is no longer active.');
    }
    $actor_user_id = (int) $actor['id'];

    $attendance_version_column = attendance_has_row_version_column($conn) ? ', row_version' : '';
    $attendance_statement = mysqli_prepare(
        $conn,
        'SELECT id, employee_id, attendance_date, check_in, check_out, check_in_at,
                check_out_at, scheduled_end_at, status, working_hours' . $attendance_version_column . '
         FROM attendance
         WHERE employee_id = ? AND attendance_date >= ? AND attendance_date <= ?
         ORDER BY attendance_date, id FOR UPDATE'
    );
    if ($attendance_statement === false) {
        throw new RuntimeException('Attendance conflicts could not be loaded.');
    }
    mysqli_stmt_bind_param($attendance_statement, 'sss', $leave['employee_id'], $from_date, $to_date);
    if (!mysqli_stmt_execute($attendance_statement)) {
        mysqli_stmt_close($attendance_statement);
        throw new RuntimeException('Attendance conflicts could not be loaded.');
    }
    $attendance_result = mysqli_stmt_get_result($attendance_statement);
    $attendance_rows = [];
    while ($attendance_result && ($attendance = mysqli_fetch_assoc($attendance_result))) {
        $attendance_rows[] = $attendance;
    }
    mysqli_stmt_close($attendance_statement);

    $overlap_statement = mysqli_prepare(
        $conn,
        "SELECT id, from_date, to_date, status
         FROM leaves
         WHERE employee_id = ? AND id <> ?
           AND status IN ('Pending', 'Approved')
           AND from_date <= ? AND to_date >= ?
         ORDER BY from_date, id FOR UPDATE"
    );
    if ($overlap_statement === false) {
        throw new RuntimeException('Overlapping leave requests could not be checked.');
    }
    mysqli_stmt_bind_param($overlap_statement, 'siss', $leave['employee_id'], $leave_id, $to_date, $from_date);
    if (!mysqli_stmt_execute($overlap_statement)) {
        mysqli_stmt_close($overlap_statement);
        throw new RuntimeException('Overlapping leave requests could not be checked.');
    }
    $overlap_result = mysqli_stmt_get_result($overlap_statement);
    $overlapping_leaves = [];
    while ($overlap_result && ($overlap = mysqli_fetch_assoc($overlap_result))) {
        $overlapping_leaves[] = $overlap;
    }
    mysqli_stmt_close($overlap_statement);

    $correlation_statement = mysqli_prepare(
        $conn,
        "SELECT correlation_id FROM leave_attendance_audit
         WHERE leave_id = ? AND event_type = 'conflict_resolved'
         ORDER BY id ASC LIMIT 1"
    );
    if ($correlation_statement === false) {
        throw new RuntimeException('Conflict history could not be loaded.');
    }
    mysqli_stmt_bind_param($correlation_statement, 'i', $leave_id);
    if (!mysqli_stmt_execute($correlation_statement)) {
        mysqli_stmt_close($correlation_statement);
        throw new RuntimeException('Conflict history could not be loaded.');
    }
    $correlation_result = mysqli_stmt_get_result($correlation_statement);
    $prior_correlation = $correlation_result ? mysqli_fetch_assoc($correlation_result) : null;
    mysqli_stmt_close($correlation_statement);
    $correlation_id = $prior_correlation['correlation_id'] ?? leaveConflictUuid();

    $audit_attendance = static function ($attendance, $event_type, $decision_value, $new_leave_status) use (
        $conn,
        $correlation_id,
        $leave,
        $actor_user_id,
        $actor_employee_id,
        $reason
    ) {
        $event = leaveConflictNewAuditEvent(
            $correlation_id,
            $event_type,
            $leave,
            $attendance['attendance_date'],
            leaveConflictTypeForStatus($attendance['status']),
            $decision_value,
            $actor_user_id,
            $actor_employee_id,
            $reason
        );
        $event['attendance_id'] = (int) $attendance['id'];
        $event['previous_leave_status'] = $leave['status'];
        $event['new_leave_status'] = $new_leave_status;
        $event['previous_attendance_status'] = $attendance['status'];
        $event['new_attendance_status'] = $attendance['status'];
        $event['previous_check_in'] = $attendance['check_in'];
        $event['previous_check_out'] = $attendance['check_out'];
        $event['previous_check_in_at'] = $attendance['check_in_at'];
        $event['previous_check_out_at'] = $attendance['check_out_at'];
        $event['previous_scheduled_end_at'] = $attendance['scheduled_end_at'];
        $event['previous_working_hours'] = $attendance['working_hours'];
        return $event;
    };

    if ($decision === 'reject_leave') {
        if (count($attendance_rows) === 0 && count($overlapping_leaves) === 0) {
            throw new DomainException('The conflict is no longer present.');
        }

        $update = mysqli_prepare(
            $conn,
            "UPDATE leaves SET status = 'Rejected', employee_notification_status = 'Unread'
             WHERE id = ? AND status = 'Pending'"
        );
        if ($update === false) {
            throw new RuntimeException('Leave decision could not be prepared.');
        }
        mysqli_stmt_bind_param($update, 'i', $leave_id);
        if (!mysqli_stmt_execute($update) || mysqli_stmt_affected_rows($update) !== 1) {
            mysqli_stmt_close($update);
            throw new DomainException('The leave request is no longer pending.');
        }
        mysqli_stmt_close($update);

        foreach ($attendance_rows as $attendance) {
            leaveConflictAuditAppend($conn, $audit_attendance($attendance, 'conflict_resolved', 'reject_leave', 'Rejected'));
        }
        foreach ($overlapping_leaves as $overlap) {
            $event = leaveConflictNewAuditEvent(
                $correlation_id,
                'conflict_resolved',
                $leave,
                max($from_date, $overlap['from_date']),
                'leave_leave',
                'reject_leave',
                $actor_user_id,
                $actor_employee_id,
                $reason
            );
            $event['conflicting_leave_id'] = (int) $overlap['id'];
            $event['new_leave_status'] = 'Rejected';
            leaveConflictAuditAppend($conn, $event);
        }

        if (!mysqli_commit($conn)) {
            throw new RuntimeException('Conflict resolution could not commit.');
        }
        $transaction_started = false;
        $redirect('rejected');
    }

    if (count($overlapping_leaves) > 0) {
        throw new DomainException('An overlapping leave request still blocks approval.');
    }

    $selected = null;
    foreach ($attendance_rows as $attendance) {
        if ((int) $attendance['id'] === $attendance_id) {
            $selected = $attendance;
            break;
        }
    }
    if (!$selected) {
        throw new DomainException('The selected attendance conflict is stale.');
    }

    $resolved_statement = mysqli_prepare(
        $conn,
        "SELECT * FROM leave_attendance_audit
         WHERE leave_id = ? AND attendance_id = ? AND event_type = 'conflict_resolved'
           AND resolution_decision IN ('approve_preserve_attendance', 'convert_absent_to_leave')
         ORDER BY id DESC LIMIT 1"
    );
    if ($resolved_statement === false) {
        throw new RuntimeException('Conflict history could not be checked.');
    }
    $selected_id = (int) $selected['id'];
    mysqli_stmt_bind_param($resolved_statement, 'ii', $leave_id, $selected_id);
    if (!mysqli_stmt_execute($resolved_statement)) {
        mysqli_stmt_close($resolved_statement);
        throw new RuntimeException('Conflict history could not be checked.');
    }
    $resolved_result = mysqli_stmt_get_result($resolved_statement);
    $prior_resolution = $resolved_result ? mysqli_fetch_assoc($resolved_result) : null;
    mysqli_stmt_close($resolved_statement);
    $already_resolved = leaveConflictAuditMatchesAttendance($prior_resolution, $selected);
    if ($already_resolved || $selected['status'] === 'Leave') {
        throw new DomainException('The selected conflict is already resolved or remains blocked.');
    }

    $preservable_statuses = ['Present', 'Late', 'Half Day', 'Late Half Day', 'Absent', 'Sunday Working'];
    if ($decision === 'approve_preserve_attendance' && !in_array($selected['status'], $preservable_statuses, true)) {
        throw new DomainException('This attendance status does not have an allowed preservation decision.');
    }

    if ($decision === 'convert_absent_to_leave') {
        if ($selected['status'] !== 'Absent' || leaveConflictHasWorkEvidence($selected)) {
            throw new DomainException('Only an Absent record with no work evidence can be converted.');
        }
    }

    $unresolved_count = 0;
    foreach ($attendance_rows as $attendance) {
        if ((int) $attendance['id'] === $selected_id) {
            continue;
        }
        $history_statement = mysqli_prepare(
            $conn,
            "SELECT * FROM leave_attendance_audit
             WHERE leave_id = ? AND attendance_id = ? AND event_type = 'conflict_resolved'
               AND resolution_decision IN ('approve_preserve_attendance', 'convert_absent_to_leave')
             ORDER BY id DESC LIMIT 1"
        );
        if ($history_statement === false) {
            throw new RuntimeException('Conflict history could not be checked.');
        }
        $other_attendance_id = (int) $attendance['id'];
        mysqli_stmt_bind_param($history_statement, 'ii', $leave_id, $other_attendance_id);
        if (!mysqli_stmt_execute($history_statement)) {
            mysqli_stmt_close($history_statement);
            throw new RuntimeException('Conflict history could not be checked.');
        }
        $history_result = mysqli_stmt_get_result($history_statement);
        $prior_event = $history_result ? mysqli_fetch_assoc($history_result) : null;
        mysqli_stmt_close($history_statement);
        $was_resolved = leaveConflictAuditMatchesAttendance($prior_event, $attendance);
        if (!$was_resolved) {
            $unresolved_count++;
        }
    }
    $will_approve = $unresolved_count === 0;

    if ($decision === 'convert_absent_to_leave') {
        $expected_version = attendance_row_version_value($selected);
        if ($expected_version === false) {
            throw new DomainException('The attendance record version is invalid.');
        }
        $convert = mysqli_prepare(
            $conn,
            "UPDATE attendance SET status = 'Leave', row_version = row_version + 1
             WHERE id = ? AND employee_id = ? AND attendance_date = ? AND status = 'Absent'
               AND row_version = ?
               AND check_in IS NULL AND check_out IS NULL AND check_in_at IS NULL
               AND check_out_at IS NULL AND scheduled_end_at IS NULL"
        );
        if ($convert === false) {
            throw new RuntimeException('Attendance update could not be prepared.');
        }
        mysqli_stmt_bind_param($convert, 'issi', $selected_id, $leave['employee_id'], $selected['attendance_date'], $expected_version);
        if (!mysqli_stmt_execute($convert) || mysqli_stmt_affected_rows($convert) !== 1) {
            mysqli_stmt_close($convert);
            throw new DomainException('The Absent record changed or contains work evidence.');
        }
        mysqli_stmt_close($convert);
    }

    $new_leave_status = $will_approve ? 'Approved' : 'Pending';
    if ($will_approve) {
        $approve = mysqli_prepare(
            $conn,
            "UPDATE leaves SET status = 'Approved', employee_notification_status = 'Unread'
             WHERE id = ? AND status = 'Pending'"
        );
        if ($approve === false) {
            throw new RuntimeException('Leave approval could not be prepared.');
        }
        mysqli_stmt_bind_param($approve, 'i', $leave_id);
        if (!mysqli_stmt_execute($approve) || mysqli_stmt_affected_rows($approve) !== 1) {
            mysqli_stmt_close($approve);
            throw new DomainException('The leave request is no longer pending.');
        }
        mysqli_stmt_close($approve);
    }

    $resolution_event = $audit_attendance(
        $selected,
        'conflict_resolved',
        $decision,
        $new_leave_status
    );
    $resolution_event['new_attendance_status'] = $decision === 'convert_absent_to_leave'
        ? 'Leave'
        : $selected['status'];
    leaveConflictAuditAppend($conn, $resolution_event);

    if ($will_approve) {
        $insert = mysqli_prepare(
            $conn,
            "INSERT INTO attendance (employee_id, attendance_date, status)
             VALUES (?, ?, 'Leave')"
        );
        if ($insert === false) {
            throw new RuntimeException('Leave attendance could not be prepared.');
        }

        $date_attendance = $first_date;
        while ($date_attendance <= $last_date) {
            $business_date = $date_attendance->format('Y-m-d');
            $has_attendance = false;
            foreach ($attendance_rows as $attendance) {
                if ($attendance['attendance_date'] === $business_date) {
                    $has_attendance = true;
                    break;
                }
            }
            if (!$has_attendance) {
                $nonworking_reason = leaveConflictNonworkingReason($conn, $business_date);
                if ($nonworking_reason !== null) {
                    $nonworking_event = leaveConflictNewAuditEvent(
                        $correlation_id,
                        'leave_approved',
                        $leave,
                        $business_date,
                        $nonworking_reason,
                        'nonworking_date_not_recorded',
                        $actor_user_id,
                        $actor_employee_id,
                        $reason
                    );
                    $nonworking_event['new_leave_status'] = 'Approved';
                    leaveConflictAuditAppend($conn, $nonworking_event);
                    $date_attendance = $date_attendance->modify('+1 day');
                    continue;
                }
                mysqli_stmt_bind_param($insert, 'ss', $leave['employee_id'], $business_date);
                if (!mysqli_stmt_execute($insert) || mysqli_stmt_affected_rows($insert) !== 1) {
                    mysqli_stmt_close($insert);
                    throw new RuntimeException('Leave attendance could not be created.');
                }
                $new_attendance_id = (int) mysqli_insert_id($conn);
                $approval_event = leaveConflictNewAuditEvent(
                    $correlation_id,
                    'leave_approved',
                    $leave,
                    $business_date,
                    'no_conflict',
                    'approved_after_conflict_resolution',
                    $actor_user_id,
                    $actor_employee_id,
                    $reason
                );
                $approval_event['attendance_id'] = $new_attendance_id;
                $approval_event['new_leave_status'] = 'Approved';
                $approval_event['new_attendance_status'] = 'Leave';
                leaveConflictAuditAppend($conn, $approval_event);
            }
            $date_attendance = $date_attendance->modify('+1 day');
        }
        mysqli_stmt_close($insert);
    }

    if (!mysqli_commit($conn)) {
        throw new RuntimeException('Conflict resolution could not commit.');
    }
    $transaction_started = false;
    $redirect($will_approve ? 'conflict_approved' : 'conflict_resolved');
} catch (DomainException $error) {
    if ($transaction_started) {
        mysqli_rollback($conn);
    }
    $redirect('conflict_stale');
} catch (Throwable $error) {
    if ($transaction_started) {
        mysqli_rollback($conn);
    }
    error_log('Leave conflict resolution failed.');
    $redirect('resolution_failed');
}
