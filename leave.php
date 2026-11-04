<?php

include 'includes/db.php';
require_once __DIR__ . '/includes/auth.php';
$employee_id = auth_require_authenticated($conn);

$user_role = auth_current_role();
$leave_message = '';

if (isset($_POST['apply_leave'])) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Method Not Allowed');
    }

    if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $leave_message = 'Your request could not be verified. Please reload the form and try again.';
    } else {
        $allowed_leave_types = ['Sick Leave', 'Casual Leave', 'Paid Leave'];
        $leave_type = $_POST['leave_type'] ?? null;
        $from_date = $_POST['from_date'] ?? null;
        $to_date = $_POST['to_date'] ?? null;
        $reason = $_POST['reason'] ?? null;

        $is_valid_date = static function ($value) {
            if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) {
                return false;
            }

            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            $errors = DateTimeImmutable::getLastErrors();

            return $date !== false
                && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
                && $date->format('Y-m-d') === $value
                && (int) $date->format('Y') >= 1000;
        };

        if (!is_string($leave_type) || !in_array($leave_type, $allowed_leave_types, true)) {
            $leave_message = 'Please select a valid leave type.';
        } elseif (!$is_valid_date($from_date) || !$is_valid_date($to_date)) {
            $leave_message = 'Please enter valid leave dates.';
        } elseif ($from_date > $to_date) {
            $leave_message = 'The leave start date must be on or before the end date.';
        } elseif (
            !is_string($reason)
            || trim($reason) === ''
            || preg_match('//u', $reason) !== 1
            || strpos($reason, "\0") !== false
            || strlen($reason) > 65535
        ) {
            $leave_message = 'Please enter a valid reason for your leave.';
        } else {
            $reason = trim($reason);
            $transaction_started = false;

            try {
                if (!mysqli_begin_transaction($conn)) {
                    throw new RuntimeException('Leave transaction could not start.');
                }
                $transaction_started = true;

                $employee_lock = mysqli_prepare(
                    $conn,
                    "SELECT id FROM users WHERE employee_id = ? AND status = 'Active' FOR UPDATE"
                );
                if ($employee_lock === false) {
                    throw new RuntimeException('Employee lock failed.');
                }
                mysqli_stmt_bind_param($employee_lock, 's', $employee_id);
                if (!mysqli_stmt_execute($employee_lock)) {
                    mysqli_stmt_close($employee_lock);
                    throw new RuntimeException('Employee lock failed.');
                }
                $employee_result = mysqli_stmt_get_result($employee_lock);
                $active_employee = $employee_result ? mysqli_fetch_assoc($employee_result) : null;
                mysqli_stmt_close($employee_lock);

                if (!$active_employee) {
                    throw new DomainException('Your account is not active. Please contact HR.');
                }

                $overlap_statement = mysqli_prepare(
                    $conn,
                    "SELECT id FROM leaves
                     WHERE employee_id = ?
                       AND status IN ('Pending', 'Approved')
                       AND from_date <= ?
                       AND to_date >= ?
                     LIMIT 1 FOR UPDATE"
                );
                if ($overlap_statement === false) {
                    throw new RuntimeException('Leave overlap check failed.');
                }
                mysqli_stmt_bind_param($overlap_statement, 'sss', $employee_id, $to_date, $from_date);
                if (!mysqli_stmt_execute($overlap_statement)) {
                    mysqli_stmt_close($overlap_statement);
                    throw new RuntimeException('Leave overlap check failed.');
                }
                $overlap_result = mysqli_stmt_get_result($overlap_statement);
                $overlapping_leave = $overlap_result ? mysqli_fetch_assoc($overlap_result) : null;
                mysqli_stmt_close($overlap_statement);

                if ($overlapping_leave) {
                    if (!mysqli_rollback($conn)) {
                        throw new RuntimeException('Leave transaction could not be rolled back.');
                    }
                    $transaction_started = false;
                    $leave_message = 'Leave Already Applied For Selected Dates';
                } else {
                    $insert_statement = mysqli_prepare(
                        $conn,
                        "INSERT INTO leaves (employee_id, leave_type, from_date, to_date, reason, status)
                         VALUES (?, ?, ?, ?, ?, 'Pending')"
                    );
                    if ($insert_statement === false) {
                        throw new RuntimeException('Leave insert could not be prepared.');
                    }
                    mysqli_stmt_bind_param($insert_statement, 'sssss', $employee_id, $leave_type, $from_date, $to_date, $reason);
                    if (!mysqli_stmt_execute($insert_statement) || mysqli_stmt_affected_rows($insert_statement) !== 1) {
                        mysqli_stmt_close($insert_statement);
                        throw new RuntimeException('Leave insert failed.');
                    }
                    mysqli_stmt_close($insert_statement);

                    if (!mysqli_commit($conn)) {
                        throw new RuntimeException('Leave transaction could not commit.');
                    }
                    $transaction_started = false;
                    $leave_message = 'Leave Applied Successfully';
                }
            } catch (DomainException $error) {
                if ($transaction_started) {
                    mysqli_rollback($conn);
                    $transaction_started = false;
                }
                $leave_message = $error->getMessage();
            } catch (Throwable $error) {
                if ($transaction_started) {
                    mysqli_rollback($conn);
                    $transaction_started = false;
                }
                error_log('Leave submission transaction failed.');
                $leave_message = 'Leave could not be submitted. Please try again.';
            }
        }
    }
}

$leave_message_json = json_encode(
    $leave_message,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);

?>

<!DOCTYPE html>

<html lang="en">

<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Leave Management</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">



<link rel="stylesheet" href="css/style.css"><link rel="stylesheet" href="css/leave-pages.css?v=1">
</head>

<body>

    <div class="main-container">

        <?php $active_nav = 'leave'; include __DIR__ . '/includes/navigation.php'; ?>

        <div class="content leave-page" id="main-content"><header class="topbar"><h1>Leave Application</h1><a href="dashboard.php">Back to Dashboard</a></header>

            <section class="leave-card"><h2>Apply for leave</h2>



                <form method="POST" class="leave-application-form">

                    <?php echo csrf_field(); ?>

                    <label for="leave_type">Leave type</label>
<select id="leave_type" name="leave_type" required>

                        <option value="">
                            Select Leave Type
                        </option>

                        <option value="Sick Leave">
                            Sick Leave
                        </option>

                        <option value="Casual Leave">
                            Casual Leave
                        </option>

                        <option value="Paid Leave">
                            Paid Leave
                        </option>

                    </select>

                    <label for="from_date">From date</label>
<input type="date" id="from_date" name="from_date" required>

                    <label for="to_date">To date</label>
<input type="date" id="to_date" name="to_date" required>

                    <label for="reason">Reason</label>
<textarea id="reason" name="reason" placeholder="Reason" required></textarea>

                    <button type="submit" name="apply_leave" value="1">

                        Apply Leave

                    </button>

                </form></section>

        </div>

    </div>

</body>

<?php if ($leave_message !== '') { ?>
    <script>
        alert(<?php echo $leave_message_json; ?>);
    </script>
<?php } ?>

</html>
