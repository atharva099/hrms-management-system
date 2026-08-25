<?php

include 'includes/db.php';
require_once 'includes/auth.php';

auth_require_admin($conn);

if (isset($_POST['submit'])) {
    $shift_name = $_POST['shift_name'];
    $in_time = $_POST['in_time'];
    $out_time = $_POST['out_time'];
    $late_after = $_POST['late_after'];
    $shift_name = trim($shift_name);

    $check_shift = mysqli_query(
        $conn,
        "SELECT id FROM shifts
     WHERE shift_name='$shift_name'"
    );

    if (mysqli_num_rows($check_shift) > 0) {
        die("Shift already exists.");
    }

    if (strtotime($late_after) < strtotime($in_time)) {
        die("Late After time cannot be earlier than Shift In Time.");
    }

    $query = "INSERT INTO shifts
    (shift_name, in_time, out_time, late_after)

    VALUES

    ('$shift_name', '$in_time', '$out_time', '$late_after')";

    if (mysqli_query($conn, $query)) {
        header("Location: manage_shift.php?success=shift_added");
        exit();
    } else {
        header("Location: manage_shift.php?error=database_error");
        exit();
    }
}

?>

<!DOCTYPE html>
<html>

<head>
    <title>Manage Shift</title>
</head>

<body>

    <?php

if (isset($_GET['success']) && $_GET['success'] == 'shift_added') {
    echo "<div style='background:#d4edda;
                 color:#155724;
                 padding:10px;
                 margin:10px;
                 border-radius:5px;'>
            Shift added successfully.
          </div>";
}

if (isset($_GET['success']) && $_GET['success'] == 'shift_updated') {
    echo "<div style='background:#d4edda;
                 color:#155724;
                 padding:10px;
                 margin:10px;
                 border-radius:5px;'>
            Shift updated successfully.
          </div>";
}

if (isset($_GET['success']) && $_GET['success'] == 'shift_deleted') {
    echo "<div style='background:#d4edda;
                 color:#155724;
                 padding:10px;
                 margin:10px;
                 border-radius:5px;'>
            Shift deleted successfully.
          </div>";
}

if (isset($_GET['error']) && $_GET['error'] == 'database_error') {
    echo "<div style='background:#f8d7da;
                 color:#721c24;
                 padding:10px;
                 margin:10px;
                 border-radius:5px;'>
            Database error occurred.
          </div>";
}

if (isset($_GET['error']) && $_GET['error'] == 'shift_assigned') {
    echo "<div style='background:#f8d7da;
                 color:#721c24;
                 padding:10px;
                 margin:10px;
                 border-radius:5px;'>
            Cannot delete shift. Employees are assigned to this shift.
          </div>";
}

if (isset($_GET['error']) && $_GET['error'] == 'update_failed') {
    echo "<div style='background:#f8d7da;
                 color:#721c24;
                 padding:10px;
                 margin:10px;
                 border-radius:5px;'>
            Shift update failed.
          </div>";
}

?>

    <h2>Add Shift</h2>

    <form method="POST">

        Shift Name:
        <input type="text" name="shift_name" required>

        <br><br>

        In Time:
        <input type="time" name="in_time" required>

        <br><br>

        Out Time:
        <input type="time" name="out_time" required>

        <br><br>

        Late After:
        <input type="time" name="late_after" required>

        <br><br>

        <input type="submit" name="submit" value="Add Shift">

    </form>

    <br><br>

    <h2>Shift List</h2>

    <table border="1" cellpadding="10">

        <tr>
            <th>ID</th>
            <th>Shift Name</th>
            <th>In Time</th>
            <th>Out Time</th>
            <th>Late After</th>
            <th>Action</th>
        </tr>

        <?php

        $select = "SELECT * FROM shifts";

        $result = mysqli_query($conn, $select);

        while ($row = mysqli_fetch_assoc($result)) {
            ?>

            <tr>

                <td>
                    <?php echo $row['id']; ?>
                </td>

                <td>
                    <?php echo $row['shift_name']; ?>
                </td>

                <td>
                    <?php echo $row['in_time']; ?>
                </td>

                <td>
                    <?php echo $row['out_time']; ?>
                </td>

                <td>
                    <?php echo $row['late_after']; ?>
                </td>

                <td>

                    <a href="delete_shift.php?id=<?php echo $row['id']; ?>">

                        Delete

                    </a>

                    |

                    <a href="edit_shift.php?id=<?php echo $row['id']; ?>">

                        Edit

                    </a>

                </td>

            </tr>

            <?php
        }
        ?>

    </table>

</body>

</html>
