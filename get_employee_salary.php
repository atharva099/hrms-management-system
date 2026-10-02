<?php

include 'includes/db.php';
require_once 'includes/auth.php';

$current_employee_id = auth_require_authenticated($conn);
$requested_employee_id = isset($_GET['employee_id']) && is_string($_GET['employee_id'])
    ? trim($_GET['employee_id'])
    : '';

if ($requested_employee_id === '') {
    http_response_code(400);
    exit();
}

if (
    auth_current_role() !== 'Admin'
    && !hash_equals($current_employee_id, $requested_employee_id)
) {
    http_response_code(403);
    exit();
}

$statement = mysqli_prepare(
    $conn,
    'SELECT salary, incentive FROM users WHERE employee_id = ? LIMIT 1'
);
mysqli_stmt_bind_param($statement, 's', $requested_employee_id);
mysqli_stmt_execute($statement);
$result = mysqli_stmt_get_result($statement);
$row = mysqli_fetch_assoc($result);
mysqli_stmt_close($statement);

if ($row) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['salary' => $row['salary'], 'incentive' => $row['incentive']]);
} else {
    http_response_code(404);
}
?>
