<?php

include 'includes/db.php';
require_once 'includes/auth.php';

auth_require_admin($conn);

$id = (int) $_GET['id'];

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
