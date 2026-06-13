<?php

include 'includes/db.php';

$users = "SELECT *
          FROM users
          WHERE status='Active'
          ORDER BY full_name";

$user_result = mysqli_query($conn, $users);



$shifts = "SELECT *
           FROM shifts
           WHERE status='Active'
           ORDER BY shift_name";

$shift_result = mysqli_query($conn, $shifts);

if (isset($_POST['assign'])) {
    $user_id = $_POST['user_id'];

    $shift_id = $_POST['shift_id'];

    $current_shift_query = mysqli_query(
        $conn,
        "SELECT shift_id
     FROM users
     WHERE id='$user_id'"
    );

    $current_shift = mysqli_fetch_assoc(
        $current_shift_query
    );

    if (
        $current_shift['shift_id'] ==
        $shift_id
    ) {
        header(
            "Location: assign_shift.php?error=already_assigned"
        );
        exit();
    }

    $update = "UPDATE users SET

    shift_id='$shift_id'

    WHERE id='$user_id'";

    if (mysqli_query($conn, $update)) {
        header(
            "Location: assign_shift.php?success=assigned"
        );
        exit();
    } else {
        header(
            "Location: assign_shift.php?error=database_error"
        );
        exit();
    }
}

?>

<!DOCTYPE html>
<html>

<head>
    <title>Assign Shift</title>
</head>

<body>

    <?php

    if (
        isset($_GET['success'])
        &&
        $_GET['success'] == 'assigned'
    ) {
        echo "
    <div style='
    background:#d4edda;
    color:#155724;
    padding:10px;
    margin:10px;
    border-radius:5px;'>
    Shift assigned successfully.
    </div>";
    }

    if (
        isset($_GET['error'])
        &&
        $_GET['error'] == 'already_assigned'
    ) {
        echo "
    <div style='
    background:#f8d7da;
    color:#721c24;
    padding:10px;
    margin:10px;
    border-radius:5px;'>
    Employee already has this shift assigned.
    </div>";
    }

    if (
        isset($_GET['error'])
        &&
        $_GET['error'] == 'database_error'
    ) {
        echo "
    <div style='
    background:#f8d7da;
    color:#721c24;
    padding:10px;
    margin:10px;
    border-radius:5px;'>
    Database error occurred.
    </div>";
    }

    ?>

    <h2>Assign Shift To Employee</h2>

    <form method="POST">

        Select Employee:

        <select name="user_id">

            <option>Select Employee</option>

            <?php

            while ($user = mysqli_fetch_assoc($user_result)) {
                ?>

                <option value="<?php echo $user['id']; ?>">

                    <?php echo $user['full_name']; ?>

                </option>

                <?php
            }
            ?>

        </select>

        <br><br>



        Select Shift:

        <select name="shift_id">

            <option>Select Shift</option>

            <?php

            while ($shift = mysqli_fetch_assoc($shift_result)) {
                ?>

                <option value="<?php echo $shift['id']; ?>">

                    <?php echo $shift['shift_name']; ?>

                </option>

                <?php
            }
            ?>

        </select>

        <br><br>

        <input type="submit" name="assign" value="Assign Shift">

    </form>

</body>

</html>