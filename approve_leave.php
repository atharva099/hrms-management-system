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

$transaction_started = false;
try {
    if (!mysqli_begin_transaction($conn)) {
        throw new RuntimeException('Leave approval transaction could not start.');
    }
    $transaction_started = true;

    // Read the owner to use the same employee-then-leave lock order as leave submission.
    $owner_statement = mysqli_prepare($conn, 'SELECT employee_id FROM leaves WHERE id = ?');
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

    if (!$owner) {
        mysqli_rollback($conn);
        $transaction_started = false;
        $redirect_to_manage('decision_unavailable');
    }

    $employee_id = $owner['employee_id'];
    if (!is_string($employee_id) || trim($employee_id) === '') {
        throw new DomainException('The leave request has no valid employee.');
    }

    $employee_lock = mysqli_prepare($conn, 'SELECT id FROM users WHERE employee_id = ? FOR UPDATE');
    if ($employee_lock === false) {
        throw new RuntimeException('Employee could not be locked.');
    }
    mysqli_stmt_bind_param($employee_lock, 's', $employee_id);
    if (!mysqli_stmt_execute($employee_lock)) {
        mysqli_stmt_close($employee_lock);
        throw new RuntimeException('Employee could not be locked.');
    }
    $employee_result = mysqli_stmt_get_result($employee_lock);
    $locked_employee = $employee_result ? mysqli_fetch_assoc($employee_result) : null;
    mysqli_stmt_close($employee_lock);
    if (!$locked_employee) {
        throw new DomainException('The leave request employee could not be found.');
    }

    $leave_statement = mysqli_prepare(
        $conn,
        'SELECT employee_id, from_date, to_date, status FROM leaves WHERE id = ? FOR UPDATE'
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

    if (!$leave || $leave['status'] !== 'Pending') {
        mysqli_rollback($conn);
        $transaction_started = false;
        $redirect_to_manage('decision_unavailable');
    }

    if (
        !is_string($leave['employee_id'])
        || strcasecmp($leave['employee_id'], $employee_id) !== 0
        || !is_string($leave['from_date'])
        || !is_string($leave['to_date'])
    ) {
        throw new DomainException('The leave request data is invalid.');
    }

    $first_date = leaveConflictParseDate($leave['from_date']);
    $last_date = leaveConflictParseDate($leave['to_date']);
    if ($first_date === false || $last_date === false || $first_date > $last_date) {
        throw new DomainException('The leave request dates are invalid.');
    }

    // Any existing attendance row is unresolved. Preserve it and leave the
    // request Pending so Admins cannot mistake this for a reconciled approval.
    $attendance_statement = mysqli_prepare(
        $conn,
        'SELECT id FROM attendance
         WHERE employee_id = ? AND attendance_date >= ? AND attendance_date <= ?
         ORDER BY attendance_date FOR UPDATE'
    );
    if ($attendance_statement === false) {
        throw new RuntimeException('Attendance conflict check failed.');
    }
    $from_date = $first_date->format('Y-m-d');
    $to_date = $last_date->format('Y-m-d');

    $overlap_statement = mysqli_prepare(
        $conn,
        "SELECT id FROM leaves
         WHERE employee_id = ? AND id <> ?
           AND status IN ('Pending', 'Approved')
           AND from_date <= ? AND to_date >= ?
         LIMIT 1 FOR UPDATE"
    );
    if ($overlap_statement === false) {
        throw new RuntimeException('Overlapping leave requests could not be checked.');
    }
    mysqli_stmt_bind_param($overlap_statement, 'siss', $employee_id, $id, $to_date, $from_date);
    if (!mysqli_stmt_execute($overlap_statement)) {
        mysqli_stmt_close($overlap_statement);
        throw new RuntimeException('Overlapping leave requests could not be checked.');
    }
    $overlap_result = mysqli_stmt_get_result($overlap_statement);
    $overlap = $overlap_result ? mysqli_fetch_assoc($overlap_result) : null;
    mysqli_stmt_close($overlap_statement);
    if ($overlap) {
        mysqli_rollback($conn);
        $transaction_started = false;
        $redirect_to_manage('leave_overlap_conflict');
    }
    mysqli_stmt_bind_param($attendance_statement, 'sss', $employee_id, $from_date, $to_date);
    if (!mysqli_stmt_execute($attendance_statement)) {
        mysqli_stmt_close($attendance_statement);
        throw new RuntimeException('Attendance conflict check failed.');
    }
    $attendance_result = mysqli_stmt_get_result($attendance_statement);
    $attendance_conflict = $attendance_result && mysqli_fetch_assoc($attendance_result);
    mysqli_stmt_close($attendance_statement);

    if ($attendance_conflict) {
        mysqli_rollback($conn);
        $transaction_started = false;
        $redirect_to_manage('attendance_conflict');
    }

    $update_statement = mysqli_prepare(
        $conn,
        "UPDATE leaves
         SET status = 'Approved', employee_notification_status = 'Unread'
         WHERE id = ? AND status = 'Pending'"
    );
    if ($update_statement === false) {
        throw new RuntimeException('Leave decision could not be prepared.');
    }
    mysqli_stmt_bind_param($update_statement, 'i', $id);
    if (!mysqli_stmt_execute($update_statement) || mysqli_stmt_affected_rows($update_statement) !== 1) {
        mysqli_stmt_close($update_statement);
        mysqli_rollback($conn);
        $transaction_started = false;
        $redirect_to_manage('decision_unavailable');
    }
    mysqli_stmt_close($update_statement);

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
    $correlation_id = bin2hex(random_bytes(16));

    $insert_statement = mysqli_prepare(
        $conn,
        "INSERT INTO attendance (employee_id, attendance_date, status) VALUES (?, ?, 'Leave')"
    );
    if ($insert_statement === false) {
        throw new RuntimeException('Leave attendance could not be prepared.');
    }

    for ($attendance_date = $first_date; $attendance_date <= $last_date; $attendance_date = $attendance_date->modify('+1 day')) {
        $business_date = $attendance_date->format('Y-m-d');
        $nonworking_reason = leaveConflictNonworkingReason($conn, $business_date);
        if ($nonworking_reason !== null) {
            $nonworking_event = leaveConflictNewAuditEvent(
                $correlation_id,
                'leave_approved',
                ['id' => $id, 'employee_id' => $employee_id, 'status' => 'Pending'],
                $business_date,
                $nonworking_reason,
                'nonworking_date_not_recorded',
                $actor_user_id,
                $actor_employee_id,
                'No Leave attendance row created for a Sunday or configured holiday.'
            );
            $nonworking_event['new_leave_status'] = 'Approved';
            leaveConflictAuditAppend($conn, $nonworking_event);
            continue;
        }
        mysqli_stmt_bind_param($insert_statement, 'ss', $employee_id, $business_date);
        if (!mysqli_stmt_execute($insert_statement) || mysqli_stmt_affected_rows($insert_statement) !== 1) {
            mysqli_stmt_close($insert_statement);
            throw new RuntimeException('Leave attendance insert failed.');
        }
        $new_attendance_id = (int) mysqli_insert_id($conn);
        $approval_event = leaveConflictNewAuditEvent(
            $correlation_id,
            'leave_approved',
            [
                'id' => $id,
                'employee_id' => $employee_id,
                'status' => 'Pending'
            ],
            $business_date,
            'no_conflict',
            'approved_without_conflict',
            $actor_user_id,
            $actor_employee_id,
            'Approved without attendance or leave-overlap conflicts.'
        );
        $approval_event['attendance_id'] = $new_attendance_id;
        $approval_event['new_leave_status'] = 'Approved';
        $approval_event['new_attendance_status'] = 'Leave';
        leaveConflictAuditAppend($conn, $approval_event);
    }
    mysqli_stmt_close($insert_statement);

    if (!mysqli_commit($conn)) {
        throw new RuntimeException('Leave approval transaction could not commit.');
    }
    $transaction_started = false;
    $redirect_to_manage('approved');
} catch (DomainException $error) {
    if ($transaction_started) {
        mysqli_rollback($conn);
    }
    $redirect_to_manage('decision_unavailable');
} catch (Throwable $error) {
    if ($transaction_started) {
        mysqli_rollback($conn);
    }
    error_log('Leave approval transaction failed.');
    $redirect_to_manage('decision_failed');
}
