<?php

session_start();

include 'includes/db.php';

$current_employee = $_SESSION['employee_id'];

$user_query = "SELECT * FROM users
WHERE employee_id='$current_employee'";

$user_result = mysqli_query($conn, $user_query);

$user = mysqli_fetch_assoc($user_result);

if ($user['role'] != 'Admin') {
    header("Location: dashboard.php");
}

$query =
    "SELECT * FROM leaves
ORDER BY id DESC";

$result =
    mysqli_query($conn, $query);

?>

<!DOCTYPE html>

<html>

<head>

    <title>
        Manage Leave
    </title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        body {
            margin: 0;
            background: #f4f6f9;
            font-family: Arial;
        }

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

        table {
            width: 100%;
            background: white;
            border-collapse: collapse;
            margin-top: 20px;
            border-radius: 10px;
            overflow: hidden;
        }

        table th {
            background: #007bff;
            color: white;
            padding: 15px;
        }

        table td {
            padding: 15px;
            border-bottom: 1px solid #ddd;
        }

        .approve-btn {
            background: green;
            color: white;
            padding: 8px 12px;
            text-decoration: none;
            border-radius: 5px;
        }

        .reject-btn {
            background: red;
            color: white;
            padding: 8px 12px;
            text-decoration: none;
            border-radius: 5px;
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

            <a href="manage_leave.php" class="active">
                Manage Leave
            </a>

            <a href="logout.php">
                Logout
            </a>

        </div>

        <div class="content">

            <h1>
                Leave Requests
            </h1>

            <table style="width:100%; border-collapse:collapse; text-align:center;">

                <tr>

                    <th style="padding:15px;">Employee ID</th>
                    <th style="padding:15px;">Leave Type</th>
                    <th style="padding:15px;">From</th>
                    <th style="padding:15px;">To</th>
                    <th style="padding:15px;">Status</th>
                    <th style="padding:15px;">Action</th>

                </tr>

                <?php

                while ($row = mysqli_fetch_assoc($result)) {

                    ?>

                    <tr>

                        <td style="padding:15px; text-align:center;">
                            <?php echo $row['employee_id']; ?>
                        </td>

                        <td style="padding:15px; text-align:center;">
                            <?php echo $row['leave_type']; ?>
                        </td>

                        <td style="padding:15px; text-align:center;">
                            <?php echo $row['from_date']; ?>
                        </td>

                        <td style="padding:15px; text-align:center;">
                            <?php echo $row['to_date']; ?>
                        </td>

                        <td style="padding:15px; text-align:center;">
                            <?php echo $row['status']; ?>
                        </td>

                        <td style="padding:15px; text-align:center;">

                            <?php

                            if ($row['status'] == 'Pending') {
                                ?>

                                <a href="approve_leave.php?id=<?php echo $row['id']; ?>"
                                    style="background:green;color:white;padding:8px 15px;border-radius:5px;text-decoration:none;">
                                    Approve
                                </a>

                                <a href="reject_leave.php?id=<?php echo $row['id']; ?>"
                                    style="background:red;color:white;padding:8px 15px;border-radius:5px;text-decoration:none;">
                                    Reject
                                </a>

                                <?php
                            } elseif ($row['status'] == 'Approved') {
                                echo "<span style='color:green;font-weight:bold;'>Approved</span>";
                            } else {
                                echo "<span style='color:red;font-weight:bold;'>Rejected</span>";
                            }

                            ?>

                        </td>

                    </tr>

                    <?php

                }

                ?>

            </table>

        </div>

    </div>

</body>

</html>