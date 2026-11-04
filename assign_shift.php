<?php

include 'includes/db.php';
require_once 'includes/auth.php';

auth_require_admin($conn);

$request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($request_method !== 'GET' && $request_method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}

if ($request_method === 'POST') {
    if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Request could not be verified.');
    }

    if (!isset($_POST['assign'])) {
        header('Location: assign_shift.php?error=invalid_input');
        exit();
    }

    $raw_user_id = $_POST['user_id'] ?? null;
    $raw_shift_id = $_POST['shift_id'] ?? null;
    $user_id = is_string($raw_user_id)
        ? filter_var($raw_user_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]])
        : false;
    $shift_id = is_string($raw_shift_id)
        ? filter_var($raw_shift_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]])
        : false;

    if ($user_id === false || $shift_id === false) {
        header('Location: assign_shift.php?error=invalid_input');
        exit();
    }

    if (!mysqli_begin_transaction($conn)) {
        header('Location: assign_shift.php?error=database_error');
        exit();
    }

    try {
        // Shift mutations use shift-then-user lock order. The same shift-row
        // lock is used by delete_shift.php so assignment cannot race deletion.
        $shift_check = mysqli_prepare(
            $conn,
            'SELECT status FROM shifts WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        if ($shift_check === false || !mysqli_stmt_bind_param($shift_check, 'i', $shift_id)
            || !mysqli_stmt_execute($shift_check)
            || !mysqli_stmt_bind_result($shift_check, $shift_status)) {
            if ($shift_check instanceof mysqli_stmt) {
                mysqli_stmt_close($shift_check);
            }
            throw new RuntimeException('Shift could not be locked.');
        }
        $shift_exists = mysqli_stmt_fetch($shift_check);
        mysqli_stmt_close($shift_check);
        if (!$shift_exists || $shift_status !== 'Active') {
            mysqli_rollback($conn);
            header('Location: assign_shift.php?error=invalid_input');
            exit();
        }

        $user_check = mysqli_prepare(
            $conn,
            'SELECT status, shift_id FROM users WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        if ($user_check === false || !mysqli_stmt_bind_param($user_check, 'i', $user_id)
            || !mysqli_stmt_execute($user_check)
            || !mysqli_stmt_bind_result($user_check, $user_status, $current_shift_id)) {
            if ($user_check instanceof mysqli_stmt) {
                mysqli_stmt_close($user_check);
            }
            throw new RuntimeException('Employee could not be locked.');
        }
        $user_exists = mysqli_stmt_fetch($user_check);
        mysqli_stmt_close($user_check);
        if (!$user_exists || $user_status !== 'Active') {
            mysqli_rollback($conn);
            header('Location: assign_shift.php?error=invalid_input');
            exit();
        }

        if ((int) $current_shift_id === $shift_id) {
            mysqli_rollback($conn);
            header('Location: assign_shift.php?error=already_assigned');
            exit();
        }

        $update = mysqli_prepare(
            $conn,
            "UPDATE users SET shift_id = ? WHERE id = ? AND status = 'Active'"
        );
        if ($update === false || !mysqli_stmt_bind_param($update, 'ii', $shift_id, $user_id)
            || !mysqli_stmt_execute($update) || mysqli_stmt_affected_rows($update) !== 1) {
            if ($update instanceof mysqli_stmt) {
                mysqli_stmt_close($update);
            }
            throw new RuntimeException('Shift assignment failed.');
        }
        mysqli_stmt_close($update);

        if (!mysqli_commit($conn)) {
            throw new RuntimeException('Shift assignment could not be committed.');
        }
    } catch (Throwable $error) {
        mysqli_rollback($conn);
        header('Location: assign_shift.php?error=database_error');
        exit();
    }

    header('Location: assign_shift.php?success=assigned');
    exit();
}

$user_result = mysqli_query(
    $conn,
    "SELECT id, full_name FROM users WHERE status = 'Active' ORDER BY full_name"
);
$shift_result = mysqli_query(
    $conn,
    "SELECT id, shift_name FROM shifts WHERE status = 'Active' ORDER BY shift_name"
);
if ($user_result === false || $shift_result === false) {
    http_response_code(500);
    exit('Assignment form could not be loaded.');
}

$success = isset($_GET['success']) && is_string($_GET['success']) && $_GET['success'] === 'assigned';
$error = isset($_GET['error']) && is_string($_GET['error']) ? $_GET['error'] : '';
$escape_html = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="css/style.css"><link rel="stylesheet" href="css/shift.css?v=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <title>Assign Shift</title>
</head>

<body>
<div class="main-container">
<?php $active_nav = 'assign_shift'; include __DIR__ . '/includes/navigation.php'; ?>
<main class="content shift-page"><header class="topbar"><h1>Assign Shift</h1><a class="shift-back" href="dashboard.php">Back to Dashboard</a></header><section class="shift-card">

    <?php if ($success) { ?>
        <div class="shift-alert shift-success" role="status">
            Shift assigned successfully.
        </div>
    <?php } ?>

    <?php if ($error === 'already_assigned') { ?>
        <div class="shift-alert shift-error" role="alert">
            Employee already has this shift assigned.
        </div>
    <?php } elseif ($error === 'database_error') { ?>
        <div class="shift-alert shift-error" role="alert">
            Database error occurred.
        </div>
    <?php } elseif ($error === 'invalid_input') { ?>
        <div class="shift-alert shift-error" role="alert">
            Select an active employee and shift.
        </div>
    <?php } ?>

    <h2>Assign Shift To Employee</h2>

    <form method="POST" class="shift-form">
        <?php echo csrf_field(); ?>

        <label for="user_id">Employee</label>

        <select id="user_id" name="user_id" required>
            <option value="">Select Employee</option>
            <?php while ($user = mysqli_fetch_assoc($user_result)) { ?>
                <option value="<?php echo $escape_html($user['id']); ?>">
                    <?php echo $escape_html($user['full_name']); ?>
                </option>
            <?php } ?>
        </select>



        <label for="shift_id">Shift</label>

        <select id="shift_id" name="shift_id" required>
            <option value="">Select Shift</option>
            <?php while ($shift = mysqli_fetch_assoc($shift_result)) { ?>
                <option value="<?php echo $escape_html($shift['id']); ?>">
                    <?php echo $escape_html($shift['shift_name']); ?>
                </option>
            <?php } ?>
        </select>



        <input type="submit" name="assign" value="Assign Shift">

    </form>

</section></main></div></body>

</html>
