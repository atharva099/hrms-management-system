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

if ($request_method === 'POST') {
    if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Request could not be verified.');
    }
    $raw_id = $_POST['shift_id'] ?? null;
} else {
    $raw_id = $_GET['id'] ?? null;
}

$id = is_string($raw_id)
    ? filter_var($raw_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]])
    : false;
if ($id === false) {
    http_response_code(400);
    exit('Invalid shift ID.');
}

$error_message = '';
if ($request_method === 'POST') {
    if (!isset($_POST['update'])) {
        http_response_code(400);
        $error_message = 'Invalid shift update request.';
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
            $error_message = 'Late After cannot be earlier than Shift In Time.';
        }

        if (!$valid) {
            http_response_code(400);
            if ($error_message === '') {
                $error_message = 'Please provide a valid shift name and times.';
            }
        } else {
            $duplicate_check = mysqli_prepare(
                $conn,
                'SELECT id FROM shifts WHERE shift_name = ? AND id <> ? LIMIT 1'
            );
            if ($duplicate_check === false) {
                header('Location: manage_shift.php?error=update_failed');
                exit();
            }
            mysqli_stmt_bind_param($duplicate_check, 'si', $shift_name, $id);
            $duplicate_query_ok = mysqli_stmt_execute($duplicate_check)
                && mysqli_stmt_store_result($duplicate_check);
            $duplicate_exists = $duplicate_query_ok && mysqli_stmt_num_rows($duplicate_check) > 0;
            mysqli_stmt_close($duplicate_check);

            if (!$duplicate_query_ok) {
                header('Location: manage_shift.php?error=update_failed');
                exit();
            }
            if ($duplicate_exists) {
                http_response_code(400);
                $error_message = 'Shift name already exists.';
            } else {
                $update = mysqli_prepare(
                    $conn,
                    'UPDATE shifts SET shift_name = ?, in_time = ?, out_time = ?, late_after = ? WHERE id = ?'
                );
                if ($update === false) {
                    header('Location: manage_shift.php?error=update_failed');
                    exit();
                }
                mysqli_stmt_bind_param(
                    $update,
                    'ssssi',
                    $shift_name,
                    $in_time,
                    $out_time,
                    $late_after,
                    $id
                );
                $updated = mysqli_stmt_execute($update);
                mysqli_stmt_close($update);

                if ($updated) {
                    header('Location: manage_shift.php?success=shift_updated');
                    exit();
                }
                header('Location: manage_shift.php?error=update_failed');
                exit();
            }
        }
    }
}

$select = mysqli_prepare(
    $conn,
    'SELECT id, shift_name, in_time, out_time, late_after FROM shifts WHERE id = ? LIMIT 1'
);
if ($select === false) {
    http_response_code(500);
    exit('Shift could not be loaded.');
}
mysqli_stmt_bind_param($select, 'i', $id);
if (!mysqli_stmt_execute($select)) {
    mysqli_stmt_close($select);
    http_response_code(500);
    exit('Shift could not be loaded.');
}
$row_id = null;
$row_name = null;
$row_in_time = null;
$row_out_time = null;
$row_late_after = null;
mysqli_stmt_bind_result($select, $row_id, $row_name, $row_in_time, $row_out_time, $row_late_after);
$found = mysqli_stmt_fetch($select);
mysqli_stmt_close($select);
if (!$found) {
    http_response_code(404);
    exit('Shift not found.');
}
$row = [
    'id' => $row_id,
    'shift_name' => $row_name,
    'in_time' => $row_in_time,
    'out_time' => $row_out_time,
    'late_after' => $row_late_after
];

$escape_html = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

?>

<!DOCTYPE html>
<html>

<head>
    <title>Edit Shift</title>
</head>

<body>

    <h2>Edit Shift</h2>
    <?php if ($error_message !== '') { ?>
        <p role="alert"><?php echo $escape_html($error_message); ?></p>
    <?php } ?>

    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="shift_id" value="<?php echo $escape_html($row['id']); ?>">

        Shift Name:
        <input type="text" name="shift_name" value="<?php echo $escape_html($row['shift_name']); ?>" maxlength="100" required>

        <br><br>

        In Time:
        <input type="time" name="in_time" value="<?php echo $escape_html(substr((string) $row['in_time'], 0, 5)); ?>" required>

        <br><br>

        Out Time:
        <input type="time" name="out_time" value="<?php echo $escape_html(substr((string) $row['out_time'], 0, 5)); ?>" required>

        <br><br>

        Late After:
        <input type="time" name="late_after" value="<?php echo $escape_html(substr((string) $row['late_after'], 0, 5)); ?>" required>

        <br><br>

        <input type="submit" name="update" value="Update Shift">

    </form>

</body>

</html>
