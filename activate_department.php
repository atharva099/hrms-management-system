<?php

session_start();

if (!isset($_SESSION['employee_id'])) {
    header("Location:index.php");
    exit();
}

include 'includes/db.php';

$id = (int) $_GET['id'];

mysqli_query(
    $conn,
    "UPDATE departments
     SET status='Active'
     WHERE id='$id'"
);

$_SESSION['success'] =
    "Department activated successfully.";

header("Location:department_master.php");
exit();

?>