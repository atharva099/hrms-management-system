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
    || !preg_match('/^EMP([0-9]+)$/D', $id, $matches)
    || ltrim($matches[1], '0') === ''
    || filter_var(ltrim($matches[1], '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
) {
    http_response_code(400);
    exit('Invalid employee ID');
}

$current_admin =
    auth_current_employee_id();

if ($id == $current_admin) {
    header("Location: employees.php?error=self_deactivate");
    exit();
}

$update_query = "
UPDATE users
SET status='Inactive'
WHERE employee_id='$id'
";

mysqli_query($conn, $update_query);

header("Location: employees.php?success=deactivated");

exit();

?>
