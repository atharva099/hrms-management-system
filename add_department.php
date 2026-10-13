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
$valid_utf8 = preg_match('//u', $department_name) === 1;
$department_character_count = $valid_utf8
    ? preg_match_all('/./us', $department_name, $department_name_characters)
    : false;
if ($department_name === '' || $department_character_count === false || $department_character_count > 100) {
    http_response_code(400);
    exit('Invalid department name');
}

$check_statement = mysqli_prepare($conn, 'SELECT id FROM departments WHERE department_name = ? LIMIT 1');
if ($check_statement === false) {
    http_response_code(500);
    exit('Department could not be checked.');
}
mysqli_stmt_bind_param($check_statement, 's', $department_name);
if (!mysqli_stmt_execute($check_statement)) {
    mysqli_stmt_close($check_statement);
    http_response_code(500);
    exit('Department could not be checked.');
}
$check_result = mysqli_stmt_get_result($check_statement);
$department_exists = $check_result && mysqli_num_rows($check_result) > 0;
mysqli_stmt_close($check_statement);

if ($department_exists) {

    $_SESSION['error'] =
        "Department already exists.";

    header(
        "Location: department_master.php"
    );

    exit();

}

$insert_statement = mysqli_prepare($conn, 'INSERT INTO departments (department_name) VALUES (?)');
if ($insert_statement === false) {
    http_response_code(500);
    exit('Department could not be added.');
}
mysqli_stmt_bind_param($insert_statement, 's', $department_name);
if (!mysqli_stmt_execute($insert_statement)) {
    mysqli_stmt_close($insert_statement);
    http_response_code(500);
    exit('Department could not be added.');
}
mysqli_stmt_close($insert_statement);

$_SESSION['success'] =
    "Department added successfully.";

header(
    "Location: department_master.php"
);

exit();

?>
