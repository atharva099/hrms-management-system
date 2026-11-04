<?php

include 'includes/db.php';
require_once 'includes/auth.php';

auth_require_admin($conn);

$request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($request_method !== 'GET' && $request_method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}

$error_message = '';
if ($request_method === 'POST') {
    if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Request could not be verified.');
    }

    if (!isset($_POST['submit'])) {
        http_response_code(400);
        $error_message = 'Invalid shift creation request.';
    } else {
        $raw_shift_name = $_POST['shift_name'] ?? null;
        $raw_in_time = $_POST['in_time'] ?? null;
        $raw_out_time = $_POST['out_time'] ?? null;
        $raw_late_after = $_POST['late_after'] ?? null;

        $valid = is_string($raw_shift_name)
            && preg_match('//u', $raw_shift_name) === 1
            && is_string($raw_in_time)
            && is_string($raw_out_time)
            && is_string($raw_late_after);

        if ($valid) {
            $shift_name = preg_replace('/^\s+|\s+$/u', '', $raw_shift_name);
            $valid = is_string($shift_name) && $shift_name !== '';
        }

        if ($valid) {
            preg_match_all('/./us', $shift_name, $name_characters);
            $valid = count($name_characters[0]) <= 100;
        }

        $normalize_time = static function ($value) {
            if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/D', $value)) {
                return false;
            }

            return strlen($value) === 5 ? $value . ':00' : $value;
        };

        if ($valid) {
            $in_time = $normalize_time($raw_in_time);
            $out_time = $normalize_time($raw_out_time);
            $late_after = $normalize_time($raw_late_after);
            $valid = $in_time !== false && $out_time !== false && $late_after !== false;
        }

        if ($valid && strtotime($late_after) < strtotime($in_time)) {
            $valid = false;
            $error_message = 'Late After time cannot be earlier than Shift In Time.';
        }

        if (!$valid) {
            http_response_code(400);
            if ($error_message === '') {
                $error_message = 'Please provide a valid shift name and times.';
            }
        } else {
            $duplicate_check = mysqli_prepare(
                $conn,
                'SELECT id FROM shifts WHERE shift_name = ? LIMIT 1'
            );
            if ($duplicate_check === false) {
                http_response_code(500);
                $error_message = 'Database error occurred.';
            } else {
                mysqli_stmt_bind_param($duplicate_check, 's', $shift_name);
                $duplicate_query_ok = mysqli_stmt_execute($duplicate_check)
                    && mysqli_stmt_store_result($duplicate_check);
                $duplicate_exists = $duplicate_query_ok && mysqli_stmt_num_rows($duplicate_check) > 0;
                mysqli_stmt_close($duplicate_check);

                if (!$duplicate_query_ok) {
                    http_response_code(500);
                    $error_message = 'Database error occurred.';
                } elseif ($duplicate_exists) {
                    http_response_code(400);
                    $error_message = 'Shift already exists.';
                } else {
                    $insert = mysqli_prepare(
                        $conn,
                        'INSERT INTO shifts (shift_name, in_time, out_time, late_after) VALUES (?, ?, ?, ?)'
                    );
                    if ($insert === false) {
                        http_response_code(500);
                        $error_message = 'Database error occurred.';
                    } else {
                        mysqli_stmt_bind_param(
                            $insert,
                            'ssss',
                            $shift_name,
                            $in_time,
                            $out_time,
                            $late_after
                        );
                        $inserted = mysqli_stmt_execute($insert);
                        mysqli_stmt_close($insert);
                        if ($inserted) {
                            header('Location: manage_shift.php?success=shift_added');
                            exit();
                        }
                        http_response_code(500);
                        $error_message = 'Database error occurred.';
                    }
                }
            }
        }
    }
}

$result = mysqli_query(
    $conn,
    'SELECT id, shift_name, in_time, out_time, late_after FROM shifts'
);
if ($result === false) {
    http_response_code(500);
    exit('Shift list could not be loaded.');
}

$success = isset($_GET['success'])
    && is_string($_GET['success'])
    && in_array($_GET['success'], ['shift_added', 'shift_updated', 'shift_deleted'], true);
$error = isset($_GET['error']) && is_string($_GET['error']) ? $_GET['error'] : '';
$escape_html = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="css/style.css"><link rel="stylesheet" href="css/shift.css?v=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <title>Manage Shift</title>
</head>

<body>
<div class="main-container">
<?php $active_nav = 'manage_shift'; include __DIR__ . '/includes/navigation.php'; ?>
<main class="content shift-page"><header class="topbar"><h1>Shift Management</h1><a class="shift-back" href="dashboard.php">Back to Dashboard</a></header><section class="shift-card">

    <?php if ($error_message !== '') { ?>
        <div role="alert" class="shift-alert shift-error">
            <?php echo $escape_html($error_message); ?>
        </div>
    <?php } ?>

    <?php if ($success) { ?>
        <div class="shift-alert shift-success" role="status">
            <?php
            if ($_GET['success'] === 'shift_added') {
                echo 'Shift added successfully.';
            } elseif ($_GET['success'] === 'shift_updated') {
                echo 'Shift updated successfully.';
            } else {
                echo 'Shift deleted successfully.';
            }
            ?>
        </div>
    <?php } ?>

    <?php if ($error === 'database_error') { ?>
        <div class="shift-alert shift-error" role="alert">
            Database error occurred.
        </div>
    <?php } elseif ($error === 'shift_assigned') { ?>
        <div class="shift-alert shift-error" role="alert">
            Cannot delete shift. Employees are assigned to this shift.
        </div>
    <?php } elseif ($error === 'update_failed') { ?>
        <div class="shift-alert shift-error" role="alert">
            Shift update failed.
        </div>
    <?php } ?>

    <h2>Add Shift</h2>

    <form method="POST" class="shift-form">
        <?php echo csrf_field(); ?>

        <label for="shift_name">Shift name</label>
        <input type="text" id="shift_name" name="shift_name" maxlength="100" required>



        <label for="in_time">In time</label>
        <input type="time" id="in_time" name="in_time" required>



        <label for="out_time">Out time</label>
        <input type="time" id="out_time" name="out_time" required>



        <label for="late_after">Late after</label>
        <input type="time" id="late_after" name="late_after" required>



        <input type="submit" name="submit" value="Add Shift">

    </form>



    </section><section class="shift-card"><h2>Shift List</h2>

    <div class="shift-table-wrap" role="region" aria-label="Shift list" tabindex="0"><table class="shift-table">
        <tr>
            <th>ID</th>
            <th>Shift Name</th>
            <th>In Time</th>
            <th>Out Time</th>
            <th>Late After</th>
            <th>Action</th>
        </tr>

        <?php while ($row = mysqli_fetch_assoc($result)) { ?>
            <tr>
                <td><?php echo $escape_html($row['id']); ?></td>
                <td><?php echo $escape_html($row['shift_name']); ?></td>
                <td><?php echo $escape_html($row['in_time']); ?></td>
                <td><?php echo $escape_html($row['out_time']); ?></td>
                <td><?php echo $escape_html($row['late_after']); ?></td>
                <td>
                    <form method="POST" action="delete_shift.php" style="display:inline;">
                        <input type="hidden" name="id" value="<?php echo $escape_html($row['id']); ?>">
                        <?php echo csrf_field(); ?>
                        <button type="submit">Delete</button>
                    </form>
                    |
                    <a href="edit_shift.php?id=<?php echo $escape_html(rawurlencode((string) $row['id'])); ?>">
                        Edit
                    </a>
                </td>
            </tr>
        <?php } ?>
    </table></div>

</section></main></div></body>

</html>
