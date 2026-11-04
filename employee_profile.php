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

<html lang="en">

<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="css/style.css"><link rel="stylesheet" href="css/employee-forms.css?v=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <title>
        Employee Profile
    </title>

    <style>.employee-form-page .profile-item { margin: 18px 0; line-height: 1.7; overflow-wrap: anywhere; } .employee-form-page .profile-item b { display: block; color: #475569; } .employee-form-page .profile-return { display: flex; flex-wrap: wrap; gap: 16px; margin-top: 24px; }</style>

</head>

<body><div class="main-container">
<?php $active_nav = $is_admin ? 'employees' : 'profile'; include __DIR__ . '/includes/navigation.php'; ?>
<main class="content employee-form-page"><header class="topbar"><h1>Employee Profile</h1><a href="dashboard.php">Back to Dashboard</a></header>

    <section class="employee-form-card">

        <?php

        $image =
            "images/" . $user['employee_id'] . ".jpg";

        if (!file_exists($image)) {

            $image = "images/default.png";
        }

        ?>

        <img class="profile-image" src="<?php echo employee_profile_escape($image); ?>" alt="Employee profile photo">

        <h2>Profile details</h2>

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

    </section>

    <div class="profile-return">
        <?php if ($is_admin) { ?>
            <a href="employees.php">Back to Employees</a>
        <?php } ?>
        <a href="dashboard.php">Back to Dashboard</a>
    </div>
</main></div>
</body>
</html>