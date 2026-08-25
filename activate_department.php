<?php

include 'includes/db.php';
require_once 'includes/auth.php';

auth_require_admin($conn);

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
