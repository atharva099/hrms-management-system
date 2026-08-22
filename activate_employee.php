<?php

include("includes/db.php");
require_once "includes/auth.php";

auth_require_admin($conn);

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
