<?php

session_start();

include("includes/db.php");

if (!isset($_SESSION['employee_id'])) {
    header("Location:index.php");
    exit();
}

$id = $_GET['id'];

$update_query = "
UPDATE users
SET status='Active'
WHERE employee_id='$id'
";

mysqli_query($conn, $update_query);

header("Location: employees.php?success=activated");

exit();

?>