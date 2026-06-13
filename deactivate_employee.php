<?php

session_start();

include("includes/db.php");

if (!isset($_SESSION['employee_id'])) {
    header("Location:index.php");
    exit();
}

$id = $_GET['id'];

$current_admin =
    $_SESSION['employee_id'];

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