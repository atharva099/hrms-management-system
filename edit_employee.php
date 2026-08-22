<?php

include("includes/db.php");
require_once "includes/auth.php";

auth_require_admin($conn);

$id = $_GET['id'];

$query = "SELECT * FROM users WHERE employee_id='$id'";
$result = mysqli_query($conn, $query);

$row = mysqli_fetch_assoc($result);

if (isset($_POST['update'])) {
    $name = $_POST['full_name'];
    $department = $_POST['department'];
    $designation = $_POST['designation'];
    $salary = $_POST['salary'];
    $role = $_POST['role'];
    $status = $_POST['status'];

    $update = "UPDATE users SET

    full_name='$name',
    department='$department',
    designation='$designation',
    salary='$salary',
    role='$role',
    status='$status'

    WHERE employee_id='$id'
    ";

    mysqli_query($conn, $update);

    header("Location: employees.php");
}

?>

<!DOCTYPE html>
<html>

<head>

    <title>Edit Employee</title>

    <style>
        body {
            background: #f4f6f9;
            font-family: Arial;
        }

        .form-box {
            width: 500px;
            background: white;
            margin: 50px auto;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.1);
        }

        .form-box h1 {
            margin-bottom: 20px;
        }

        input,
        select {
            width: 100%;
            padding: 12px;
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        button {
            width: 100%;
            padding: 12px;
            background: #0d6efd;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover {
            background: #0b5ed7;
        }
    </style>

</head>

<body>

    <div class="form-box">

        <h1>Edit Employee</h1>

        <form method="POST">

            <input type="text" name="full_name" value="<?php echo $row['full_name']; ?>" required>

            <input type="text" name="department" value="<?php echo $row['department']; ?>" required>

            <input type="text" name="designation" value="<?php echo $row['designation']; ?>" required>

            <input type="number" name="salary" value="<?php echo $row['salary']; ?>" required>

            <select name="role">

                <option value="Admin" <?php if ($row['role'] == "Admin")
                    echo "selected"; ?>>
                    Admin
                </option>

                <option value="Employee" <?php if ($row['role'] == "Employee")
                    echo "selected"; ?>>
                    Employee
                </option>

            </select>

            <select name="status">

                <option value="Active" <?php if ($row['status'] == "Active")
                    echo "selected"; ?>>
                    Active
                </option>

                <option value="Inactive" <?php if ($row['status'] == "Inactive")
                    echo "selected"; ?>>
                    Inactive
                </option>

            </select>

            <button type="submit" name="update">
                Update Employee
            </button>

        </form>

    </div>

</body>

</html>
