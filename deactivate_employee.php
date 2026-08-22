<?php

include("includes/db.php");
require_once "includes/auth.php";

auth_require_admin($conn);

$id = $_GET['id'];

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
