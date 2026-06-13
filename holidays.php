<?php
session_start();
include("includes/db.php");

$employee_id = $_SESSION['employee_id'];

$user_query = mysqli_query(
    $conn,

    "SELECT * FROM users
WHERE employee_id='$employee_id'"
);

$user = mysqli_fetch_assoc($user_query);

$role = $user['role'];

if (isset($_POST['add_holiday'])) {
    $holiday_name = $_POST['holiday_name'];

    $holiday_date = $_POST['holiday_date'];

    mysqli_query(
        $conn,

        "INSERT INTO holidays
    (holiday_name, holiday_date)

    VALUES

    ('$holiday_name', '$holiday_date')"
    );

    echo "<script>
    alert('Holiday Added Successfully');
    window.location='holidays.php';
    </script>";
}

if (!isset($_SESSION['employee_id'])) {
    header("Location: index.php");
}

$current_date = date("Y-m-d");

$upcoming = mysqli_query(
    $conn,
    "SELECT * FROM holidays
WHERE holiday_date >= '$current_date'
ORDER BY holiday_date ASC"
);
?>

<!DOCTYPE html>
<html>

<head>
    <title>Holidays</title>

    <link rel="stylesheet" type="text/css" href="css/style.css">
</head>

<body>

    <div class="container">

        <div class="back-btn-box">

            <a href="dashboard.php" class="back-dashboard-btn">

                ⬅ Back to Dashboard

            </a>

        </div>

        <?php
        if ($role == "Admin") {
            ?>

            <div class="add-holiday-box">

                <h2>Add Holiday</h2>

                <form method="POST" class="add-holiday-form">

                    <input type="text" name="holiday_name" placeholder="Holiday Name" required>

                    <input type="date" name="holiday_date" required>

                    <button type="submit" name="add_holiday">
                        Add Holiday
                    </button>

                </form>

            </div>

            <?php
        }
        ?>

        <div class="holiday-container"></div>

        <h2>Upcoming Holidays</h2>

        <table class="holiday-table">

            <tr>
                <th>Holiday</th>
                <th>Date</th>
            </tr>

            <?php

            while ($row = mysqli_fetch_assoc($upcoming)) {
                ?>

                <tr>

                    <td>
                        <?php echo $row['holiday_name']; ?>
                    </td>

                    <td>
                        <?php echo $row['holiday_date']; ?>
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