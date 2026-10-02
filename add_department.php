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

if (!isset($_POST['department_name']) || !is_string($_POST['department_name'])) {
    http_response_code(400);
    exit('Invalid department name');
}

$department_name = trim($_POST['department_name']);
if ($department_name === '') {
    http_response_code(400);
    exit('Invalid department name');
}

$check =
    mysqli_query(
        $conn,
        "SELECT *
FROM departments
WHERE department_name='$department_name'"
    );

if (mysqli_num_rows($check) > 0) {

    $_SESSION['error'] =
        "Department already exists.";

    header(
        "Location: department_master.php"
    );

    exit();

}

mysqli_query(
    $conn,
    "INSERT INTO departments
(department_name)
VALUES
('$department_name')"
);

$_SESSION['success'] =
    "Department added successfully.";

header(
    "Location: department_master.php"
);

exit();

?>
