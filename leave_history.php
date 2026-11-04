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

<html lang="en">

<head>
<meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Leave History</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">



<link rel="stylesheet" href="css/style.css"><link rel="stylesheet" href="css/leave-pages.css?v=1">
</head>

<body>

    <div class="main-container">

        <?php $active_nav = 'leave_history'; include __DIR__ . '/includes/navigation.php'; ?>

        <div class="content leave-page" id="main-content"><header class="topbar"><h1>Leave History</h1><a href="dashboard.php">Back to Dashboard</a></header>



            <div class="leave-card leave-table-wrap" role="region" aria-label="Leave requests" tabindex="0"><table class="leave-table" style="width:100%; border-collapse:collapse; text-align:center;">

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

            </table></div>

        </div>

    </div>

</body>

</html>
