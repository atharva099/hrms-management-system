<?php

session_start();

include 'includes/db.php';

$employee_id = $_POST['employee_id'];
$password = $_POST['password'];

$query = "SELECT * FROM users
          WHERE employee_id='$employee_id'";

$result = mysqli_query($conn, $query);

if (mysqli_num_rows($result) > 0) {

    $row = mysqli_fetch_assoc($result);

    // Active Account
    if (
        password_verify($password, $row['password']) &&
        $row['status'] == 'Active'
    ) {

        $_SESSION['employee_id'] = $row['employee_id'];
        $_SESSION['role'] = $row['role'];

        header("Location: dashboard.php");
        exit();
    }

    // Inactive Account
    if (
        password_verify($password, $row['password']) &&
        $row['status'] == 'Inactive'
    ) {

        echo "<script>
        alert('Your account has been deactivated. Please contact HR.');
        window.location='index.php';
        </script>";

        exit();
    }
}

// Invalid Login
echo "<script>
alert('Invalid Employee ID or Password');
window.location='index.php';
</script>";

?>