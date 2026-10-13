<?php

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/leave_conflicts.php';

auth_require_admin($conn);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST', true, 405);
    exit('Method Not Allowed');
}

if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Forbidden');
}

$raw_id = $_POST['id'] ?? null;
if (
    !is_string($raw_id)
    || !preg_match('/^[1-9][0-9]*$/D', $raw_id)
    || filter_var($raw_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
) {
    http_response_code(400);
    exit('Invalid leave ID');
}
$id = (int) $raw_id;

$redirect_to_manage = static function ($result) {
    header('Location: manage_leave.php?result=' . rawurlencode($result));
    exit();
};

try {
    if (!mysqli_begin_transaction($conn)) {
        throw new RuntimeException('Leave rejection transaction could not start.');
    }

    $owner_statement = mysqli_prepare(
        $conn,
        'SELECT employee_id FROM leaves WHERE id = ?'
    );
    if ($owner_statement === false) {
        throw new RuntimeException('Leave request could not be loaded.');
    }
    mysqli_stmt_bind_param($owner_statement, 'i', $id);
    if (!mysqli_stmt_execute($owner_statement)) {
        mysqli_stmt_close($owner_statement);
        throw new RuntimeException('Leave request could not be loaded.');
    }
    $owner_result = mysqli_stmt_get_result($owner_statement);
    $owner = $owner_result ? mysqli_fetch_assoc($owner_result) : null;
    mysqli_stmt_close($owner_statement);
    if (!$owner || !is_string($owner['employee_id']) || trim($owner['employee_id']) === '') {
        mysqli_rollback($conn);
        $redirect_to_manage('decision_unavailable');
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
        'SELECT id, employee_id, from_date, to_date, status FROM leaves WHERE id = ? FOR UPDATE'
    );
    if ($leave_statement === false) {
        throw new RuntimeException('Leave request could not be locked.');
    }
    mysqli_stmt_bind_param($leave_statement, 'i', $id);
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
        mysqli_rollback($conn);
        $redirect_to_manage('decision_unavailable');
    }

    $from_date = leaveConflictParseDate($leave['from_date']);
    if ($from_date === false) {
        throw new DomainException('The leave request date is invalid.');
    }
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

    $to_date = leaveConflictParseDate($leave['to_date']);
    if ($to_date === false || $from_date > $to_date) {
        throw new DomainException('The leave request dates are invalid.');
    }
    $attendance_conflict = mysqli_prepare(
        $conn,
        'SELECT id FROM attendance
         WHERE employee_id = ? AND attendance_date >= ? AND attendance_date <= ?
         ORDER BY attendance_date, id FOR UPDATE'
    );
    if ($attendance_conflict === false) {
        throw new RuntimeException('Attendance conflicts could not be checked.');
    }
    $from_value = $from_date->format('Y-m-d');
    $to_value = $to_date->format('Y-m-d');
    mysqli_stmt_bind_param($attendance_conflict, 'sss', $leave['employee_id'], $from_value, $to_value);
    if (!mysqli_stmt_execute($attendance_conflict)) {
        mysqli_stmt_close($attendance_conflict);
        throw new RuntimeException('Attendance conflicts could not be checked.');
    }
    $attendance_result = mysqli_stmt_get_result($attendance_conflict);
    $has_attendance_conflict = $attendance_result && mysqli_fetch_assoc($attendance_result);
    mysqli_stmt_close($attendance_conflict);

    $overlap_statement = mysqli_prepare(
        $conn,
        "SELECT id FROM leaves WHERE employee_id = ? AND id <> ?
         AND status IN ('Pending', 'Approved') AND from_date <= ? AND to_date >= ?
         LIMIT 1 FOR UPDATE"
    );
    if ($overlap_statement === false) {
        throw new RuntimeException('Overlapping leave requests could not be checked.');
    }
    mysqli_stmt_bind_param($overlap_statement, 'siss', $leave['employee_id'], $id, $to_value, $from_value);
    if (!mysqli_stmt_execute($overlap_statement)) {
        mysqli_stmt_close($overlap_statement);
        throw new RuntimeException('Overlapping leave requests could not be checked.');
    }
    $overlap_result = mysqli_stmt_get_result($overlap_statement);
    $has_leave_overlap = $overlap_result && mysqli_fetch_assoc($overlap_result);
    mysqli_stmt_close($overlap_statement);
    if ($has_attendance_conflict || $has_leave_overlap) {
        mysqli_rollback($conn);
        $redirect_to_manage('attendance_conflict');
    }

    $update_statement = mysqli_prepare(
        $conn,
        "UPDATE leaves
         SET status = 'Rejected', employee_notification_status = 'Unread'
         WHERE id = ? AND status = 'Pending'"
    );
    if ($update_statement === false) {
        throw new RuntimeException('Leave decision could not be prepared.');
    }

    mysqli_stmt_bind_param($update_statement, 'i', $id);
    if (!mysqli_stmt_execute($update_statement)) {
        mysqli_stmt_close($update_statement);
        throw new RuntimeException('Leave rejection update failed.');
    }
    $affected_rows = mysqli_stmt_affected_rows($update_statement);
    mysqli_stmt_close($update_statement);

    if ($affected_rows !== 1) {
        mysqli_rollback($conn);
        $redirect_to_manage('decision_unavailable');
    }

    $audit_event = leaveConflictNewAuditEvent(
        leaveConflictUuid(),
        'leave_rejected',
        $leave,
        $from_date->format('Y-m-d'),
        'no_conflict',
        'rejected_without_conflict',
        (int) $actor['id'],
        $actor_employee_id,
        null
    );
    $audit_event['new_leave_status'] = 'Rejected';
    leaveConflictAuditAppend($conn, $audit_event);

    if (!mysqli_commit($conn)) {
        throw new RuntimeException('Leave rejection transaction could not commit.');
    }

    $redirect_to_manage('rejected');
} catch (Throwable $error) {
    mysqli_rollback($conn);
    error_log('Leave rejection transaction failed.');
    $redirect_to_manage('decision_failed');
}
