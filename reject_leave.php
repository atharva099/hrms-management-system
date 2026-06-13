<?php

session_start();

include 'includes/db.php';

if ($_SESSION['role'] != 'Admin') {
    header("Location: dashboard.php");
}

$id = $_GET['id'];

$query =
    "UPDATE leaves
SET status='Rejected',
employee_notification_status='Unread'
WHERE id='$id'";

mysqli_query($conn, $query);

header("Location: manage_leave.php");

?>