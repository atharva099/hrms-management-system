<?php

include 'includes/db.php';
require_once 'includes/auth.php';

auth_require_admin($conn);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST', true, 405);
    exit('Method Not Allowed');
}

if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Forbidden');
}

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(400);
    exit('Invalid shift ID');
}

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
