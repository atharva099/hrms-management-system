<?php

session_start();

include("includes/db.php");

if (!isset($_SESSION['employee_id'])) {
    header("Location:index.php");
}

$employee_id = $_SESSION['employee_id'];

$query = mysqli_query(
    $conn,
    "SELECT * FROM users
WHERE employee_id='$employee_id'"
);

$user = mysqli_fetch_assoc($query);

if (isset($_POST['delete_image'])) {

    $image_path =
        "images/" . $employee_id . ".jpg";

    if (file_exists($image_path)) {

        unlink($image_path);
    }

    echo "<script>
    alert('Profile Picture Deleted');
    window.location='edit_profile.php';
    </script>";
}

if (isset($_POST['update_profile'])) {
    if (!empty($_FILES['profile_image']['name'])) {

        $target_file =
            "images/" . $employee_id . ".jpg";

        move_uploaded_file(
            $_FILES['profile_image']['tmp_name'],
            $target_file
        );
    }
    $full_name = $_POST['full_name'];

    $phone = $_POST['phone'];

    $email = $_POST['email'];

    $password = $_POST['password'];

    $update = mysqli_query(
        $conn,

        "UPDATE users SET

    full_name='$full_name',
    phone='$phone',
    email='$email',
    password='$password'

    WHERE employee_id='$employee_id'"
    );

    echo "<script>
    alert('Profile Updated Successfully');
    window.location='dashboard.php';
    </script>";
}

?>

<!DOCTYPE html>
<html>

<head>

    <title>Edit Profile</title>

    <style>
        body {
            font-family: Arial;
            background: #f1f2f6;
        }

        .container {
            width: 450px;
            background: white;
            margin: auto;
            margin-top: 50px;
            padding: 35px;
            border-radius: 10px;
        }

        input {
            width: 100%;
            padding: 12px;
            margin-top: 15px;
        }

        button {
            width: 100%;
            padding: 12px;
            margin-top: 20px;
            background: #0d6efd;
            color: white;
            border: none;
            cursor: pointer;
        }

        label {
            display: block;
            margin-bottom: 8px;
            margin-top: 15px;
            font-weight: bold;
            color: #333;
        }
    </style>

</head>

<body>

    <div class="container">

        <h2>Edit Profile</h2>

        <form method="POST" enctype="multipart/form-data">

            <label>Profile Picture</label>

            <?php
            $image_path = "images/" . $employee_id . ".jpg";

            if (file_exists($image_path)) {
                ?>

                <img src="<?php echo $image_path; ?>" width="120" height="120" style="
        border-radius:50%;
        object-fit:cover;
        border:3px solid #0d6efd;
        display:block;
        margin-bottom:15px;
    ">

                <?php
            }
            ?>

            <input type="file" name="profile_image">

            <br><br>

            <button type="submit" name="delete_image" style="background:red;">
                Delete Profile Picture
            </button>

            <label>Full Name</label>
            <input type="text" name="full_name" value="<?php echo $user['full_name']; ?>">

            <label>Phone Number</label>
            <input type="text" name="phone" value="<?php echo $user['phone']; ?>">

            <label>Email Address</label>
            <input type="email" name="email" value="<?php echo $user['email']; ?>">

            <label>Password</label>
            <input type="password" name="password" value="<?php echo $user['password']; ?>">

            <button type="submit" name="update_profile">

                Update Profile

            </button>

        </form>

    </div>

</body>

</html>