<?php

session_start();

include 'includes/db.php';

if ($_SESSION['role'] == 'Admin') {
    $query = "SELECT
            users.employee_id,
            users.full_name,
            users.department,
            shifts.shift_name,
            shifts.in_time,
            shifts.out_time,
            shifts.late_after

          FROM users

          LEFT JOIN shifts
ON users.shift_id = shifts.id

          ORDER BY users.full_name";
} else {
    $employee_id = $_SESSION['employee_id'];

    $query = "SELECT
            users.employee_id,
            users.full_name,
            users.department,
            shifts.shift_name,
            shifts.in_time,
            shifts.out_time,
            shifts.late_after

          FROM users

          LEFT JOIN shifts
ON users.shift_id = shifts.id

          WHERE users.employee_id='$employee_id'";
}

$result = mysqli_query($conn, $query);

?>

<!DOCTYPE html>
<html>

<head>
    <title>View Assigned Shifts</title>
</head>

<body>

    <h2>Employee Assigned Shifts</h2>

    <table border="1" cellpadding="10">

        <tr>

            <th>Employee ID</th>
            <th>Employee Name</th>
            <th>Department</th>
            <th>Shift Name</th>
            <th>In Time</th>
            <th>Out Time</th>
            <th>Late After</th>

        </tr>

        <?php

        while ($row = mysqli_fetch_assoc($result)) {
            ?>

            <tr>

                <td><?php echo $row['employee_id']; ?></td>

                <td><?php echo $row['full_name']; ?></td>

                <td><?php echo $row['department']; ?></td>

                <td>
                    <?php
                    echo !empty($row['shift_name'])
                        ? $row['shift_name']
                        : 'No Shift Assigned';
                    ?>
                </td>

                <td>
                    <?php
                    echo !empty($row['in_time'])
                        ? $row['in_time']
                        : '-';
                    ?>
                </td>

                <td>
                    <?php
                    echo !empty($row['out_time'])
                        ? $row['out_time']
                        : '-';
                    ?>
                </td>

                <td>
                    <?php
                    echo !empty($row['late_after'])
                        ? $row['late_after']
                        : '-';
                    ?>
                </td>

            </tr>

            <?php
        }
        ?>

    </table>

</body>

</html>