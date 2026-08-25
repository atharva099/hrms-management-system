<?php

include 'includes/db.php';
require_once 'includes/auth.php';

auth_require_admin($conn);

$id = $_GET['id'];

/*
    Check if shift is assigned
    to any employee
*/

$check = mysqli_query(
    $conn,
    "SELECT id
     FROM users
     WHERE shift_id='$id'"
);

if (mysqli_num_rows($check) > 0) {
    header("Location: manage_shift.php?error=shift_assigned");
    exit();
}

/*
    Safe to delete
*/

mysqli_query(
    $conn,
    "DELETE FROM shifts
     WHERE id='$id'"
);

header("Location: manage_shift.php?success=shift_deleted");
exit();

?>
