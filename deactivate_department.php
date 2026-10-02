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
    exit('Invalid department ID');
}

$department_query =
    mysqli_query(
        $conn,
        "SELECT department_name
         FROM departments
         WHERE id='$id'"
    );

$department =
    mysqli_fetch_assoc($department_query);

if (!$department) {
    header("Location:department_master.php");
    exit();
}

$department_name =
    $department['department_name'];

$employee_check =
    mysqli_query(
        $conn,
        "SELECT *
         FROM users
         WHERE department='$department_name'
         AND status='Active'"
    );

if (mysqli_num_rows($employee_check) > 0) {

    $_SESSION['error'] =
        "Cannot deactivate department. Active employees are assigned.";

    header("Location:department_master.php");
    exit();
}

mysqli_query(
    $conn,
    "UPDATE departments
     SET status='Inactive'
     WHERE id='$id'"
);

$_SESSION['success'] =
    "Department deactivated successfully.";

header("Location:department_master.php");
exit();
?>
