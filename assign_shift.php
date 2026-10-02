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

    $user_check = mysqli_prepare(
        $conn,
        "SELECT shift_id FROM users WHERE id = ? AND status = 'Active' LIMIT 1"
    );
    if ($user_check === false) {
        header('Location: assign_shift.php?error=database_error');
        exit();
    }
    mysqli_stmt_bind_param($user_check, 'i', $user_id);
    if (!mysqli_stmt_execute($user_check)) {
        mysqli_stmt_close($user_check);
        header('Location: assign_shift.php?error=database_error');
        exit();
    }
    $current_shift_id = null;
    mysqli_stmt_bind_result($user_check, $current_shift_id);
    $user_exists = mysqli_stmt_fetch($user_check);
    mysqli_stmt_close($user_check);
    if (!$user_exists) {
        header('Location: assign_shift.php?error=invalid_input');
        exit();
    }

    $shift_check = mysqli_prepare(
        $conn,
        "SELECT id FROM shifts WHERE id = ? AND status = 'Active' LIMIT 1"
    );
    if ($shift_check === false) {
        header('Location: assign_shift.php?error=database_error');
        exit();
    }
    mysqli_stmt_bind_param($shift_check, 'i', $shift_id);
    if (!mysqli_stmt_execute($shift_check)) {
        mysqli_stmt_close($shift_check);
        header('Location: assign_shift.php?error=database_error');
        exit();
    }
    mysqli_stmt_store_result($shift_check);
    $shift_exists = mysqli_stmt_num_rows($shift_check) === 1;
    mysqli_stmt_close($shift_check);
    if (!$shift_exists) {
        header('Location: assign_shift.php?error=invalid_input');
        exit();
    }

    if ((int) $current_shift_id === $shift_id) {
        header('Location: assign_shift.php?error=already_assigned');
        exit();
    }

    $update = mysqli_prepare(
        $conn,
        "UPDATE users SET shift_id = ? WHERE id = ? AND status = 'Active'"
    );
    if ($update === false) {
        header('Location: assign_shift.php?error=database_error');
        exit();
    }
    mysqli_stmt_bind_param($update, 'ii', $shift_id, $user_id);
    $updated = mysqli_stmt_execute($update);
    $changed_rows = mysqli_stmt_affected_rows($update);
    mysqli_stmt_close($update);

    if ($updated && $changed_rows === 1) {
        header('Location: assign_shift.php?success=assigned');
        exit();
    }

    header('Location: assign_shift.php?error=database_error');
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
<html>

<head>
    <title>Assign Shift</title>
</head>

<body>

    <?php if ($success) { ?>
        <div style="background:#d4edda;color:#155724;padding:10px;margin:10px;border-radius:5px;">
            Shift assigned successfully.
        </div>
    <?php } ?>

    <?php if ($error === 'already_assigned') { ?>
        <div style="background:#f8d7da;color:#721c24;padding:10px;margin:10px;border-radius:5px;">
            Employee already has this shift assigned.
        </div>
    <?php } elseif ($error === 'database_error') { ?>
        <div style="background:#f8d7da;color:#721c24;padding:10px;margin:10px;border-radius:5px;">
            Database error occurred.
        </div>
    <?php } elseif ($error === 'invalid_input') { ?>
        <div style="background:#f8d7da;color:#721c24;padding:10px;margin:10px;border-radius:5px;">
            Select an active employee and shift.
        </div>
    <?php } ?>

    <h2>Assign Shift To Employee</h2>

    <form method="POST">
        <?php echo csrf_field(); ?>

        Select Employee:

        <select name="user_id" required>
            <option value="">Select Employee</option>
            <?php while ($user = mysqli_fetch_assoc($user_result)) { ?>
                <option value="<?php echo $escape_html($user['id']); ?>">
                    <?php echo $escape_html($user['full_name']); ?>
                </option>
            <?php } ?>
        </select>

        <br><br>

        Select Shift:

        <select name="shift_id" required>
            <option value="">Select Shift</option>
            <?php while ($shift = mysqli_fetch_assoc($shift_result)) { ?>
                <option value="<?php echo $escape_html($shift['id']); ?>">
                    <?php echo $escape_html($shift['shift_name']); ?>
                </option>
            <?php } ?>
        </select>

        <br><br>

        <input type="submit" name="assign" value="Assign Shift">

    </form>

</body>

</html>
