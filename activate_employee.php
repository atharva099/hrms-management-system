<?php

include("includes/db.php");
require_once "includes/auth.php";

auth_require_admin($conn);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST', true, 405);
    exit('Method Not Allowed');
}

if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Forbidden');
}

$id = $_POST['employee_id'] ?? null;
if (
    !is_string($id)
    || !preg_match('/^EMP([0-9]+)$/iD', $id, $matches)
    || ltrim($matches[1], '0') === ''
    || filter_var(ltrim($matches[1], '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
) {
    http_response_code(400);
    exit('Invalid employee ID');
}

$update_statement = mysqli_prepare(
    $conn,
    "UPDATE users SET status = 'Active' WHERE employee_id = ?"
);
if ($update_statement === false
    || !mysqli_stmt_bind_param($update_statement, 's', $id)
    || !mysqli_stmt_execute($update_statement)) {
    if ($update_statement instanceof mysqli_stmt) {
        mysqli_stmt_close($update_statement);
    }
    http_response_code(500);
    exit('Employee status could not be updated.');
}
mysqli_stmt_close($update_statement);

header("Location: employees.php?success=activated");

exit();

?>
