<?php

include 'includes/db.php';
require_once 'includes/auth.php';

$current_employee_id = auth_require_authenticated($conn);

function employee_profile_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$is_admin = auth_current_role() === 'Admin';
$requested_id = isset($_GET['id']) && is_string($_GET['id'])
    ? trim($_GET['id'])
    : '';

if ($is_admin) {
    $id = $requested_id;
} else {
    // Employee users may view only their own profile, even when an id is supplied.
    if (
        $requested_id !== ''
        && strcasecmp($requested_id, $current_employee_id) !== 0
    ) {
        http_response_code(403);
        exit('Forbidden');
    }

    $id = $current_employee_id;
}

if (
    !is_string($id)
    || strlen($id) > 20
    || !preg_match('/^EMP([0-9]{3,17})$/iD', $id, $employee_id_match)
    || ltrim($employee_id_match[1], '0') === ''
) {
    http_response_code(400);
    exit('Invalid employee ID');
}

$statement = mysqli_prepare($conn, 'SELECT * FROM users WHERE employee_id = ? LIMIT 1');
if ($statement === false) {
    http_response_code(500);
    exit('Unable to load employee profile');
}

mysqli_stmt_bind_param($statement, 's', $id);
if (!mysqli_stmt_execute($statement)) {
    mysqli_stmt_close($statement);
    http_response_code(500);
    exit('Unable to load employee profile');
}

$result = mysqli_stmt_get_result($statement);
$user = $result ? mysqli_fetch_assoc($result) : null;
mysqli_stmt_close($statement);

if (!$user) {
    http_response_code(404);
    exit('Employee profile not found');
}

?>

<!DOCTYPE html>

<html>

<head>

    <title>
        Employee Profile
    </title>

    <style>
        body {
            font-family: Arial;
            background: #f4f6f9;
            padding: 40px;
        }

        .profile-box {
            background: white;
            width: 550px;
            margin: auto;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0px 5px 20px rgba(0, 0, 0, 0.1);
        }

        .profile-item {
            margin-bottom: 15px;
            font-size: 18px;
        }

        img {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 20px;
        }

        .profile-box:hover {
            transform: translateY(-5px);
            transition: 0.3s;
        }
    </style>

</head>

<body>

    <div class="profile-box">

        <?php

        $image =
            "images/" . $user['employee_id'] . ".jpg";

        if (!file_exists($image)) {

            $image = "images/default.png";
        }

        ?>

        <img src="<?php echo employee_profile_escape($image); ?>" alt="Employee profile photo">

        <h1>
            Employee Profile
        </h1>

        <div class="profile-item">

            <b>Employee ID :</b>

            <?php echo employee_profile_escape($user['employee_id']); ?>

        </div>

        <div class="profile-item">

            <b>Name :</b>

            <?php echo employee_profile_escape($user['full_name']); ?>

        </div>

        <div class="profile-item">

            <b>Department :</b>

            <?php echo employee_profile_escape($user['department']); ?>

        </div>

        <div class="profile-item">

            <b>Designation :</b>

            <?php echo employee_profile_escape($user['designation']); ?>

        </div>

        <div class="profile-item">

            <b>Role :</b>

            <span style="
color:
<?php
if ($user['role'] == 'Admin') {
    echo '#0d6efd';
} else {
    echo '#198754';
}
?>;
font-weight:bold;
">

                <?php echo employee_profile_escape($user['role']); ?>

            </span>

        </div>


        <div class="profile-item">

            <b>Phone :</b>

            <?php echo employee_profile_escape($user['phone']); ?>

        </div>

        <div class="profile-item">

            <b>Email :</b>

            <?php echo employee_profile_escape($user['email']); ?>

        </div>

        <div class="profile-item">

            <b>Salary :</b>

            ₹
            <?php echo employee_profile_escape($user['salary']); ?>

        </div>

        <div class="profile-item">

            <b>Shift Time :</b>

            <?php echo employee_profile_escape($user['shift_time']); ?>

        </div>

        <div class="profile-item">

            <b>Joining Date :</b>

            <?php echo employee_profile_escape($user['joining_date']); ?>

        </div>
        <div class="profile-item">

            <b>Status :</b>

            <span style="
padding:6px 12px;
            border-radius:20px;
background:
<?php
if ($user['status'] == 'Active') {
    echo '#198754';
} else {
    echo '#dc3545';
}
?>;
color:white;
font-size:14px;
">

                <?php echo employee_profile_escape($user['status']); ?>

            </span>

        </div>

    </div>

    <br><br>

    <div style="text-align:center; margin-top:25px;">

        <a href="employees.php" style="
padding:12px 25px;
background:#0d6efd;
color:white;
text-decoration:none;
border-radius:8px;
font-weight:bold;
display:inline-block;
">

            Back To Employees

        </a>

    </div>

</body>

</html>
