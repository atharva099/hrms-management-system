<?php

$conn = mysqli_connect(
    "localhost",
    "root",
    "",
    "coreaxis_crm",
    "3307"
);

if(!$conn)
{
    die("Database Connection Failed");
}

?>