<?php

include 'includes/db.php';
require_once __DIR__ . '/includes/auth.php';
auth_require_role($conn, ['Admin', 'Employee']);
$escape_html = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

if (auth_current_role() === 'Admin') {
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
    $employee_id = auth_current_employee_id();

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

          WHERE users.employee_id=?";
    $statement = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($statement, 's', $employee_id);
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    mysqli_stmt_close($statement);
}

if (auth_current_role() === 'Admin') {
    $result = mysqli_query($conn, $query);
}

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

                <td><?php echo $escape_html($row['employee_id']); ?></td>

                <td><?php echo $escape_html($row['full_name']); ?></td>

                <td><?php echo $escape_html($row['department']); ?></td>

                <td>
                    <?php
                    echo !empty($row['shift_name'])
                        ? $escape_html($row['shift_name'])
                        : 'No Shift Assigned';
                    ?>
                </td>

                <td>
                    <?php
                    echo !empty($row['in_time'])
                        ? $escape_html($row['in_time'])
                        : '-';
                    ?>
                </td>

                <td>
                    <?php
                    echo !empty($row['out_time'])
                        ? $escape_html($row['out_time'])
                        : '-';
                    ?>
                </td>

                <td>
                    <?php
                    echo !empty($row['late_after'])
                        ? $escape_html($row['late_after'])
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
