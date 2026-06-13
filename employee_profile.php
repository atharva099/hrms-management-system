<?php

session_start();

if ($_SESSION['role'] != 'Admin') {
    $_GET['id'] = $_SESSION['employee_id'];
}

include 'includes/db.php';

$id = trim($_GET['id']);

$query =
    "SELECT * FROM users
WHERE employee_id='$id'";

$result =
    mysqli_query($conn, $query);

$user =
    mysqli_fetch_assoc($result);

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

        <img src="<?php echo $image; ?>">

        <h1>
            Employee Profile
        </h1>

        <div class="profile-item">

            <b>Employee ID :</b>

            <?php echo $user['employee_id']; ?>

        </div>

        <div class="profile-item">

            <b>Name :</b>

            <?php echo $user['full_name']; ?>

        </div>

        <div class="profile-item">

            <b>Department :</b>

            <?php echo $user['department']; ?>

        </div>

        <div class="profile-item">

            <b>Designation :</b>

            <?php echo $user['designation']; ?>

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

                <?php echo $user['role']; ?>

            </span>

        </div>


        <div class="profile-item">

            <b>Phone :</b>

            <?php echo $user['phone']; ?>

        </div>

        <div class="profile-item">

            <b>Email :</b>

            <?php echo $user['email']; ?>

        </div>

        <div class="profile-item">

            <b>Salary :</b>

            ₹
            <?php echo $user['salary']; ?>

        </div>

        <div class="profile-item">

            <b>Shift Time :</b>

            <?php echo $user['shift_time']; ?>

        </div>

        <div class="profile-item">

            <b>Joining Date :</b>

            <?php echo $user['joining_date']; ?>

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

                <?php echo $user['status']; ?>

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