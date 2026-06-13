<?php

include 'includes/db.php';

if(isset($_GET['employee_id']))
{
    $employee_id = $_GET['employee_id'];

    $query = "
    SELECT salary, incentive
    FROM users
    WHERE employee_id='$employee_id'
    ";

    $result = mysqli_query($conn,$query);

    if(mysqli_num_rows($result)>0)
    {
        $row = mysqli_fetch_assoc($result);

        echo json_encode($row);
    }
}
?>