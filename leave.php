<?php

session_start();

if (!isset($_SESSION['employee_id'])) {
    header("Location:index.php");
}

include 'includes/db.php';

$employee_id = $_SESSION['employee_id'];

$user_query = "SELECT * FROM users
WHERE employee_id='$employee_id'";

$user_result = mysqli_query($conn, $user_query);

$user = mysqli_fetch_assoc($user_result);

if (isset($_POST['apply_leave'])) {
    $employee_id = $_SESSION['employee_id'];

    $leave_type = $_POST['leave_type'];

    $from_date = $_POST['from_date'];

    $to_date = $_POST['to_date'];

    $reason = $_POST['reason'];

    $check_leave_query = "SELECT * FROM leaves
WHERE employee_id='$employee_id'
AND (
(from_date BETWEEN '$from_date' AND '$to_date')
OR
(to_date BETWEEN '$from_date' AND '$to_date')
)
AND status != 'Rejected'";

    $check_leave_result = mysqli_query($conn, $check_leave_query);

    if (mysqli_num_rows($check_leave_result) > 0) {

        echo "<script>
    alert('Leave Already Applied For Selected Dates');
    </script>";

    } else {

        $query = "INSERT INTO leaves
(employee_id, leave_type, from_date, to_date, reason, status)

VALUES

('$employee_id','$leave_type',
'$from_date','$to_date','$reason','Pending')";

        mysqli_query($conn, $query);

        echo "<script>
    alert('Leave Applied Successfully');
    </script>";
    }
}

?>

<!DOCTYPE html>

<html>

<head>

    <title>Leave Management</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        .main-container {
            display: flex;
        }

        .sidebar {
            width: 250px;
            background: #007bff;
            min-height: 100vh;
            padding: 25px 20px;
            box-sizing: border-box;
        }

        .sidebar a {
            display: block;
            color: white;
            text-decoration: none;
            padding: 12px;
            margin-top: 10px;
            border-radius: 8px;
            transition: 0.3s;
        }

        .sidebar a:hover,
        .sidebar .active {
            background: white;
            color: #007bff;
        }

        .content {
            flex: 1;
            padding: 40px;
        }

        form {
            background: white;
            padding: 30px;
            border-radius: 15px;
            width: 500px;
            box-shadow: 0px 0px 10px rgba(0, 0, 0, 0.1);
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 12px;
            margin-top: 15px;
            border: 1px solid #ccc;
            border-radius: 8px;
            box-sizing: border-box;
        }

        button {
            width: 100%;
            padding: 14px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 8px;
            margin-top: 20px;
            font-size: 18px;
            cursor: pointer;
        }

        button:hover {
            background: #0056b3;
        }
    </style>

</head>

<body>

    <div class="main-container">

        <div class="sidebar">

            <h2 style="color:white;">
                CoreAxis CRM
            </h2>

            <a href="dashboard.php">
                Dashboard
            </a>

            <a href="attendance.php">
                Attendance
            </a>

            <a href="leave.php" class="active">
                Leave Management
            </a>

            <a href="leave_history.php">
                Leave History
            </a>

            <a href="holidays.php">
                Holidays
            </a>

            <a href="salary.php">
                Salary
            </a>

            <?php if ($user['role'] == 'Admin') { ?>

                <a href="employees.php">
                    Employees
                </a>

            <?php } ?>

            <a href="logout.php">
                Logout
            </a>

        </div>

        <div class="content">

            <div class="container">

                <h1>
                    Leave Management
                </h1>

                <form method="POST">

                    <select name="leave_type" required>

                        <option value="">
                            Select Leave Type
                        </option>

                        <option value="Sick Leave">
                            Sick Leave
                        </option>

                        <option value="Casual Leave">
                            Casual Leave
                        </option>

                        <option value="Paid Leave">
                            Paid Leave
                        </option>

                    </select>

                    <input type="date" name="from_date" required>

                    <input type="date" name="to_date" required>

                    <textarea name="reason" placeholder="Reason" required></textarea>

                    <button type="submit" name="apply_leave">

                        Apply Leave

                    </button>

                </form>

            </div>

        </div>

    </div>

</body>

</html>