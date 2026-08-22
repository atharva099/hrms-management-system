<?php

include 'includes/db.php';
require_once 'includes/auth.php';

auth_require_admin($conn);

$id = $_GET['id'];

$query =
    "UPDATE leaves
SET status='Rejected',
employee_notification_status='Unread'
WHERE id='$id'";

mysqli_query($conn, $query);

header("Location: manage_leave.php");

?>
