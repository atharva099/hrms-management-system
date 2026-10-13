<?php

include 'includes/db.php';
require_once __DIR__ . '/includes/auth.php';
$employee_id = auth_require_authenticated($conn);

$escape_html = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$user_statement = mysqli_prepare($conn, 'SELECT role FROM users WHERE employee_id = ? LIMIT 1');
mysqli_stmt_bind_param($user_statement, 's', $employee_id);
mysqli_stmt_execute($user_statement);
$user_result = mysqli_stmt_get_result($user_statement);
$user = $user_result ? mysqli_fetch_assoc($user_result) : null;
mysqli_stmt_close($user_statement);

$leave_statement = mysqli_prepare($conn, 'SELECT * FROM leaves WHERE employee_id = ? ORDER BY id DESC');
mysqli_stmt_bind_param($leave_statement, 's', $employee_id);
mysqli_stmt_execute($leave_statement);
$result = mysqli_stmt_get_result($leave_statement);

?>

<!DOCTYPE html>

<html>

<head>

    <title>Leave History</title>

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

        .status-pending {
            color: orange;
            font-weight: bold;
        }

        .status-approved {
            color: green;
            font-weight: bold;
        }

        .status-rejected {
            color: red;
            font-weight: bold;
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

            <a href="leave.php">
                Apply Leave
            </a>

            <a href="leave_history.php" class="active">
                Leave History
            </a>

            <?php if (($user['role'] ?? '') === 'Admin') { ?>

                <a href="employees.php">
                    Employees
                </a>

                <a href="attendance_report.php">
                    Attendance Report
                </a>

                <a href="salary_report.php">
                    Salary Report
                </a>

            <?php } ?>

            <a href="logout.php">
                Logout
            </a>

        </div>

        <div class="content">

            <h1>
                Leave History
            </h1>

            <table style="width:100%; border-collapse:collapse; text-align:center;">

                <tr>

                    <th style="padding:15px;">Leave Type</th>

                    <th style="padding:15px;">From</th>

                    <th style="padding:15px;">To</th>

                    <th style="padding:15px;">Reason</th>

                    <th style="padding:15px;">Status</th>

                </tr>

                <?php

                while ($row = mysqli_fetch_assoc($result)) {

                    ?>

                    <tr>

                        <td style="padding:15px; text-align:center;">
                            <?php echo $escape_html($row['leave_type']); ?>
                        </td>

                        <td style="padding:15px; text-align:center;">
                            <?php echo $escape_html($row['from_date']); ?>
                        </td>

                        <td style="padding:15px; text-align:center;">
                            <?php echo $escape_html($row['to_date']); ?>
                        </td>

                        <td style="padding:15px; text-align:center;">
                            <?php echo $escape_html($row['reason']); ?>
                        </td>

                        <td style="padding:15px; text-align:center;">

                            <?php

                            if ($row['status'] == "Pending") {
                                echo "<span class='status-pending'>
                        Pending
                        </span>";
                            } elseif ($row['status'] == "Approved") {
                                echo "<span class='status-approved'>
                        Approved
                        </span>";
                            } else {
                                echo "<span class='status-rejected'>
                        Rejected
                        </span>";
                            }

                            ?>

                        </td>

                    </tr>

                    <?php

                }

                mysqli_stmt_close($leave_statement);

                ?>

            </table>

        </div>

    </div>

</body>

</html>
