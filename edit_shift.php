<?php

include 'includes/db.php';

$id = $_GET['id'];

$select = "SELECT * FROM shifts WHERE id='$id'";

$result = mysqli_query($conn, $select);

$row = mysqli_fetch_assoc($result);

if (isset($_POST['update'])) {
    $shift_name = $_POST['shift_name'];
    $in_time = $_POST['in_time'];
    $out_time = $_POST['out_time'];
    $late_after = $_POST['late_after'];
    $shift_name = trim($shift_name);

    $check_shift = mysqli_query(
        $conn,
        "SELECT id
     FROM shifts
     WHERE shift_name='$shift_name'
     AND id != '$id'"
    );

    if (mysqli_num_rows($check_shift) > 0) {
        die("Shift name already exists.");
    }
    if (strtotime($late_after) < strtotime($in_time)) {
        die("Late After cannot be earlier than Shift In Time.");
    }
    $shift_name = trim($shift_name);

    $check_shift = mysqli_query(
        $conn,
        "SELECT id
     FROM shifts
     WHERE shift_name='$shift_name'
     AND id != '$id'"
    );

    if (mysqli_num_rows($check_shift) > 0) {
        die("Shift name already exists.");
    }
    if (strtotime($late_after) < strtotime($in_time)) {
        die("Late After cannot be earlier than Shift In Time.");
    }

    $update = "UPDATE shifts SET

    shift_name='$shift_name',
    in_time='$in_time',
    out_time='$out_time',
    late_after='$late_after'

    WHERE id='$id'";

    if (mysqli_query($conn, $update)) {
        header("Location: manage_shift.php?success=shift_updated");
        exit();
    } else {
        header("Location: manage_shift.php?error=update_failed");
        exit();
    }
}

?>

<!DOCTYPE html>
<html>

<head>
    <title>Edit Shift</title>
</head>

<body>

    <h2>Edit Shift</h2>

    <form method="POST">

        Shift Name:
        <input type="text" name="shift_name" value="<?php echo $row['shift_name']; ?>">

        <br><br>

        In Time:
        <input type="time" name="in_time" value="<?php echo $row['in_time']; ?>">

        <br><br>

        Out Time:
        <input type="time" name="out_time" value="<?php echo $row['out_time']; ?>">

        <br><br>

        Late After:
        <input type="time" name="late_after" value="<?php echo $row['late_after']; ?>">

        <br><br>

        <input type="submit" name="update" value="Update Shift">

    </form>

</body>

</html>