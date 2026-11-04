<?php

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

auth_require_authenticated($conn);
$role = auth_current_role();
$active_nav = 'holidays';
$escape_html = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$valid_date = static function ($value) {
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

$request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($request_method !== 'GET' && $request_method !== 'POST') {
    header('Allow: GET, POST', true, 405);
    exit('Method Not Allowed');
}

$holiday_error = '';
if ($request_method === 'POST') {
    if ($role !== 'Admin') {
        http_response_code(403);
        exit('Forbidden');
    }
    if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Request could not be verified.');
    }

    $is_update = isset($_POST['update_holiday']);
    if (!$is_update && !isset($_POST['add_holiday'])) {
        http_response_code(400);
        $holiday_error = 'Choose a valid holiday action.';
    }

    $holiday_id = null;
    if ($holiday_error === '' && $is_update) {
        $raw_id = $_POST['holiday_id'] ?? null;
        if (
            !is_string($raw_id)
            || !preg_match('/^[1-9][0-9]*$/D', $raw_id)
            || filter_var($raw_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
        ) {
            http_response_code(400);
            $holiday_error = 'Choose a valid holiday to update.';
        } else {
            $holiday_id = (int) $raw_id;
        }
    }

    $holiday_name = $_POST['holiday_name'] ?? null;
    $holiday_name_length = is_string($holiday_name)
        ? preg_match_all('/./us', trim($holiday_name), $name_characters)
        : false;
    if (
        $holiday_error === ''
        && (
            !is_string($holiday_name)
            || trim($holiday_name) === ''
            || preg_match('/^\s*$/u', $holiday_name) === 1
            || $holiday_name_length === false
            || $holiday_name_length > 100
        )
    ) {
        http_response_code(400);
        $holiday_error = 'Enter a holiday name of up to 100 characters.';
    }
    if (is_string($holiday_name)) {
        $holiday_name = trim($holiday_name);
    }

    $holiday_date = $_POST['holiday_date'] ?? null;
    if ($holiday_error === '' && !$valid_date($holiday_date)) {
        http_response_code(400);
        $holiday_error = 'Enter a valid holiday date.';
    }

    if ($holiday_error === '' && $is_update) {
        $transaction_started = mysqli_begin_transaction($conn);
        if (!$transaction_started) {
            http_response_code(500);
            $holiday_error = 'The holiday could not be updated. Please try again.';
        } else {
            try {
                $lock = mysqli_prepare($conn, 'SELECT holiday_date FROM holidays WHERE id = ? LIMIT 1 FOR UPDATE');
                if ($lock === false) {
                    throw new RuntimeException('prepare');
                }
                mysqli_stmt_bind_param($lock, 'i', $holiday_id);
                if (!mysqli_stmt_execute($lock)) {
                    mysqli_stmt_close($lock);
                    throw new RuntimeException('execute');
                }
                $locked_result = mysqli_stmt_get_result($lock);
                $existing = $locked_result ? mysqli_fetch_assoc($locked_result) : null;
                mysqli_stmt_close($lock);
                if (!$existing) {
                    mysqli_rollback($conn);
                    $transaction_started = false;
                    http_response_code(404);
                    $holiday_error = 'That holiday no longer exists.';
                } else {
                    $old_date = (string) $existing['holiday_date'];
                    if ($holiday_date !== $old_date) {
                        $duplicate = mysqli_prepare($conn, 'SELECT id FROM holidays WHERE holiday_date = ? AND id <> ? LIMIT 1');
                        if ($duplicate === false) {
                            throw new RuntimeException('prepare');
                        }
                        mysqli_stmt_bind_param($duplicate, 'si', $holiday_date, $holiday_id);
                        $duplicate_ok = mysqli_stmt_execute($duplicate) && mysqli_stmt_store_result($duplicate);
                        $duplicate_exists = $duplicate_ok && mysqli_stmt_num_rows($duplicate) > 0;
                        mysqli_stmt_close($duplicate);
                        if (!$duplicate_ok) {
                            throw new RuntimeException('execute');
                        }
                        if ($duplicate_exists) {
                            mysqli_rollback($conn);
                            $transaction_started = false;
                            http_response_code(409);
                            $holiday_error = 'A holiday is already configured for this date.';
                        } else {
                            $attendance = mysqli_prepare(
                                $conn,
                                'SELECT id FROM attendance WHERE attendance_date = ? OR attendance_date = ? LIMIT 1'
                            );
                            if ($attendance === false) {
                                throw new RuntimeException('prepare');
                            }
                            mysqli_stmt_bind_param($attendance, 'ss', $old_date, $holiday_date);
                            $attendance_ok = mysqli_stmt_execute($attendance)
                                && mysqli_stmt_store_result($attendance);
                            $attendance_exists = $attendance_ok && mysqli_stmt_num_rows($attendance) > 0;
                            mysqli_stmt_close($attendance);
                            if (!$attendance_ok) {
                                throw new RuntimeException('execute');
                            }
                            if ($attendance_exists) {
                                mysqli_rollback($conn);
                                $transaction_started = false;
                                http_response_code(409);
                                $holiday_error = 'The date cannot be changed because attendance already exists on the old or new date. You can still update the holiday name.';
                            }
                        }
                    }

                    if ($holiday_error === '') {
                        $update = mysqli_prepare(
                            $conn,
                            'UPDATE holidays SET holiday_name = ?, holiday_date = ? WHERE id = ?'
                        );
                        if ($update === false) {
                            throw new RuntimeException('prepare');
                        }
                        mysqli_stmt_bind_param($update, 'ssi', $holiday_name, $holiday_date, $holiday_id);
                        $updated = mysqli_stmt_execute($update);
                        $update_errno = mysqli_stmt_errno($update);
                        mysqli_stmt_close($update);
                        if (!$updated && $update_errno === 1062) {
                            mysqli_rollback($conn);
                            $transaction_started = false;
                            http_response_code(409);
                            $holiday_error = 'A holiday is already configured for this date.';
                        } elseif (!$updated) {
                            throw new RuntimeException('execute');
                        } elseif (!mysqli_commit($conn)) {
                            throw new RuntimeException('commit');
                        } else {
                            $transaction_started = false;
                            $_SESSION['holiday_success'] = 'updated';
                            header('Location: holidays.php');
                            exit();
                        }
                    }
                }
            } catch (Throwable $error) {
                if ($transaction_started) {
                    mysqli_rollback($conn);
                }
                http_response_code(500);
                $holiday_error = 'The holiday could not be updated. Please try again.';
            }
        }
    } elseif ($holiday_error === '') {
        $insert = mysqli_prepare($conn, 'INSERT INTO holidays (holiday_name, holiday_date) VALUES (?, ?)');
        if ($insert === false) {
            http_response_code(500);
            $holiday_error = 'The holiday could not be added. Please try again.';
        } else {
            mysqli_stmt_bind_param($insert, 'ss', $holiday_name, $holiday_date);
            $inserted = mysqli_stmt_execute($insert);
            $insert_errno = mysqli_stmt_errno($insert);
            mysqli_stmt_close($insert);
            if (!$inserted && $insert_errno === 1062) {
                http_response_code(409);
                $holiday_error = 'A holiday is already configured for this date.';
            } elseif (!$inserted) {
                http_response_code(500);
                $holiday_error = 'The holiday could not be added. Please try again.';
            } else {
                $_SESSION['holiday_success'] = 'added';
                header('Location: holidays.php');
                exit();
            }
        }
    }
}

$success_key = $_SESSION['holiday_success'] ?? '';
unset($_SESSION['holiday_success']);
$success_messages = [
    'added' => 'Holiday added successfully.',
    'updated' => 'Holiday updated successfully.',
];
$success_message = is_string($success_key) ? ($success_messages[$success_key] ?? '') : '';

$today = (new DateTimeImmutable('today', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$upcoming_statement = mysqli_prepare(
    $conn,
    'SELECT id, holiday_name, holiday_date FROM holidays WHERE holiday_date >= ? ORDER BY holiday_date ASC, id ASC'
);
if ($upcoming_statement === false) {
    http_response_code(500);
    exit('Upcoming holidays could not be loaded.');
}
mysqli_stmt_bind_param($upcoming_statement, 's', $today);
if (!mysqli_stmt_execute($upcoming_statement)) {
    mysqli_stmt_close($upcoming_statement);
    http_response_code(500);
    exit('Upcoming holidays could not be loaded.');
}
$upcoming = mysqli_stmt_get_result($upcoming_statement);
mysqli_stmt_close($upcoming_statement);
if (!$upcoming instanceof mysqli_result) {
    http_response_code(500);
    exit('Upcoming holidays could not be loaded.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Holidays | CoreAxis CRM</title>
    <link rel="stylesheet" href="css/style.css?v=college-20261025">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="main-container">
        <?php include __DIR__ . '/includes/navigation.php'; ?>
        <main class="content holiday-page" id="main-content">
            <header class="topbar">
                <h1>Holiday Management</h1>
                <span class="profile-box"><?php echo $role === 'Admin' ? 'Admin calendar' : 'Company calendar'; ?></span>
            </header>

            <?php if ($holiday_error !== '') { ?>
                <div class="holiday-alert holiday-alert-error" role="alert"><?php echo $escape_html($holiday_error); ?></div>
            <?php } ?>
            <?php if ($success_message !== '') { ?>
                <div class="holiday-alert holiday-alert-success" role="status"><?php echo $escape_html($success_message); ?></div>
            <?php } ?>

            <?php if ($role === 'Admin') { ?>
                <section class="holiday-card" aria-labelledby="add-holiday-heading">
                    <h2 id="add-holiday-heading">Add a holiday</h2>
                    <form method="POST" class="holiday-form">
                        <?php echo csrf_field(); ?>
                        <div class="holiday-field">
                            <label for="new-holiday-name">Holiday name</label>
                            <input id="new-holiday-name" type="text" name="holiday_name" maxlength="100" required>
                        </div>
                        <div class="holiday-field">
                            <label for="new-holiday-date">Date</label>
                            <input id="new-holiday-date" type="date" name="holiday_date" required>
                        </div>
                        <button type="submit" name="add_holiday" value="1">Add holiday</button>
                    </form>
                    <p class="holiday-help">A holiday date can be changed only before attendance is recorded for either date.</p>
                </section>
            <?php } ?>

            <section class="holiday-card" aria-labelledby="upcoming-holidays-heading">
                <div class="holiday-section-heading">
                    <div>
                        <h2 id="upcoming-holidays-heading">Upcoming holidays</h2>
                        <p>Configured company holidays used by Attendance.</p>
                    </div>
                    <span class="holiday-count"><?php echo (int) mysqli_num_rows($upcoming); ?> upcoming</span>
                </div>
                <div class="holiday-table-wrap">
                    <table class="holiday-table">
                        <thead>
                            <tr>
                                <th scope="col">Holiday</th>
                                <th scope="col">Date</th>
                                <?php if ($role === 'Admin') { ?><th scope="col">Action</th><?php } ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (mysqli_num_rows($upcoming) === 0) { ?>
                                <tr><td colspan="<?php echo $role === 'Admin' ? 3 : 2; ?>" class="holiday-empty">No upcoming holidays are configured.</td></tr>
                            <?php } else { ?>
                                <?php while ($row = mysqli_fetch_assoc($upcoming)) { ?>
                                    <tr>
                                        <?php if ($role === 'Admin') { ?>
                                            <td colspan="3">
                                                <form method="POST" class="holiday-edit-form">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="holiday_id" value="<?php echo (int) $row['id']; ?>">
                                                    <label class="visually-hidden" for="holiday-name-<?php echo (int) $row['id']; ?>">Holiday name</label>
                                                    <input id="holiday-name-<?php echo (int) $row['id']; ?>" type="text" name="holiday_name" maxlength="100" value="<?php echo $escape_html($row['holiday_name']); ?>" required>
                                                    <label class="visually-hidden" for="holiday-date-<?php echo (int) $row['id']; ?>">Holiday date</label>
                                                    <input id="holiday-date-<?php echo (int) $row['id']; ?>" type="date" name="holiday_date" value="<?php echo $escape_html($row['holiday_date']); ?>" required>
                                                    <button type="submit" name="update_holiday" value="1">Save</button>
                                                </form>
                                            </td>
                                        <?php } else { ?>
                                            <td><?php echo $escape_html($row['holiday_name']); ?></td>
                                            <td><time datetime="<?php echo $escape_html($row['holiday_date']); ?>"><?php echo $escape_html(date('d M Y', strtotime((string) $row['holiday_date']))); ?></time></td>
                                        <?php } ?>
                                    </tr>
                                <?php } ?>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($role === 'Admin') { ?>
                    <p class="holiday-help">Past holidays stay in the database for historical Attendance; this page does not delete them.</p>
                <?php } ?>
            </section>
        </main>
    </div>
</body>
</html>
