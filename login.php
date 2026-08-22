<?php

include 'includes/db.php';
require_once 'includes/auth.php';

$employee_id = isset($_POST['employee_id']) && is_string($_POST['employee_id'])
    ? trim($_POST['employee_id'])
    : '';
$password = isset($_POST['password']) && is_string($_POST['password'])
    ? $_POST['password']
    : '';

$statement = mysqli_prepare(
    $conn,
    'SELECT employee_id, password, role, status FROM users WHERE employee_id = ? LIMIT 1'
);

$row = null;

if ($statement !== false) {
    mysqli_stmt_bind_param($statement, 's', $employee_id);

    if (mysqli_stmt_execute($statement)) {
        mysqli_stmt_bind_result(
            $statement,
            $db_employee_id,
            $db_password,
            $db_role,
            $db_status
        );

        if (mysqli_stmt_fetch($statement)) {
            $row = [
                'employee_id' => $db_employee_id,
                'password' => $db_password,
                'role' => $db_role,
                'status' => $db_status
            ];
        }
    }

    mysqli_stmt_close($statement);
}

if ($row !== null) {
    $password_valid = password_verify($password, $row['password']);

    // Active Account
    if ($password_valid && $row['status'] === 'Active') {

        auth_login($row['employee_id'], $row['role']);

        header("Location: dashboard.php");
        exit();
    }

    // Inactive Account
    if ($password_valid && $row['status'] === 'Inactive') {

        echo "<script>
        alert('Your account has been deactivated. Please contact HR.');
        window.location='index.php';
        </script>";

        exit();
    }
}

// Invalid Login
echo "<script>
alert('Invalid Employee ID or Password');
window.location='index.php';
</script>";

?>
