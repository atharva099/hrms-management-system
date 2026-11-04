<?php

include 'includes/db.php';
require_once 'includes/auth.php';

auth_require_admin($conn);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST', true, 405);
    exit('Method Not Allowed');
}

if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Forbidden');
}

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(400);
    exit('Invalid shift ID');
}

if (!mysqli_begin_transaction($conn)) {
    http_response_code(500);
    exit('Shift could not be deleted.');
}

try {
    // Use the same shift-first lock order as assign_shift.php. The locked
    // parent row serializes assignments against deletion without relying on
    // request timing or a pre-transaction existence check.
    $shift_lock = mysqli_prepare($conn, 'SELECT id FROM shifts WHERE id = ? LIMIT 1 FOR UPDATE');
    if ($shift_lock === false || !mysqli_stmt_bind_param($shift_lock, 'i', $id)
        || !mysqli_stmt_execute($shift_lock) || !mysqli_stmt_bind_result($shift_lock, $locked_shift_id)) {
        if ($shift_lock instanceof mysqli_stmt) {
            mysqli_stmt_close($shift_lock);
        }
        throw new RuntimeException('Shift could not be locked.');
    }
    $shift_exists = mysqli_stmt_fetch($shift_lock);
    mysqli_stmt_close($shift_lock);
    if (!$shift_exists) {
        mysqli_rollback($conn);
        header('Location: manage_shift.php?error=database_error');
        exit();
    }

    $assignment_check = mysqli_prepare(
        $conn,
        'SELECT id FROM users WHERE shift_id = ? LIMIT 1 FOR UPDATE'
    );
    if ($assignment_check === false || !mysqli_stmt_bind_param($assignment_check, 'i', $id)
        || !mysqli_stmt_execute($assignment_check) || !mysqli_stmt_store_result($assignment_check)) {
        if ($assignment_check instanceof mysqli_stmt) {
            mysqli_stmt_close($assignment_check);
        }
        throw new RuntimeException('Shift assignments could not be checked.');
    }
    $is_assigned = mysqli_stmt_num_rows($assignment_check) > 0;
    mysqli_stmt_close($assignment_check);
    if ($is_assigned) {
        mysqli_rollback($conn);
        header('Location: manage_shift.php?error=shift_assigned');
        exit();
    }

    $delete = mysqli_prepare($conn, 'DELETE FROM shifts WHERE id = ?');
    if ($delete === false || !mysqli_stmt_bind_param($delete, 'i', $id)
        || !mysqli_stmt_execute($delete) || mysqli_stmt_affected_rows($delete) !== 1) {
        if ($delete instanceof mysqli_stmt) {
            mysqli_stmt_close($delete);
        }
        throw new RuntimeException('Shift deletion failed.');
    }
    mysqli_stmt_close($delete);
    if (!mysqli_commit($conn)) {
        throw new RuntimeException('Shift deletion could not be committed.');
    }
} catch (Throwable $error) {
    mysqli_rollback($conn);
    http_response_code(500);
    exit('Shift could not be deleted.');
}

header('Location: manage_shift.php?success=shift_deleted');
exit();

?>
