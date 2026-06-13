<?php

session_start();

include 'includes/db.php';

$department_name =
    trim($_POST['department_name']);

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