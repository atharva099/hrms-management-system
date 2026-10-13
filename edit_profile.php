<?php

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/profile_security.php';

$employee_id = auth_require_authenticated($conn);
if (!profile_valid_employee_id($employee_id)) {
    http_response_code(403);
    exit('Profile access denied.');
}

$request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($request_method !== 'GET' && $request_method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}

$images_directory = __DIR__ . DIRECTORY_SEPARATOR . 'images';
$message = '';
$message_is_error = false;
$form_values = ['full_name' => '', 'phone' => '', 'email' => ''];

if ($request_method === 'POST') {
    if (!csrf_validate_token($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Request could not be verified.');
    }

    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'delete_image') {
        if (!profile_delete_owned_image($employee_id, $images_directory)) {
            http_response_code(500);
            $message = 'Profile picture could not be deleted.';
            $message_is_error = true;
        } else {
            $message = 'Profile picture deleted.';
        }
    } elseif ($action === 'update_profile') {
        $validated = profile_validate_fields(
            $_POST['full_name'] ?? null,
            $_POST['phone'] ?? null,
            $_POST['email'] ?? null
        );

        $current_password = isset($_POST['current_password']) && is_string($_POST['current_password'])
            ? $_POST['current_password']
            : '';
        $new_password = isset($_POST['new_password']) && is_string($_POST['new_password'])
            ? $_POST['new_password']
            : '';
        $password_change_requested = $new_password !== '';
        $upload = $_FILES['profile_image'] ?? null;
        $upload_requested = is_array($upload)
            && isset($upload['error'])
            && $upload['error'] !== UPLOAD_ERR_NO_FILE;
        $staged_image = null;

        if ($validated === false) {
            http_response_code(400);
            $message = 'Please enter a valid name, phone number, and email address.';
            $message_is_error = true;
        } elseif ($password_change_requested && !profile_validate_new_password($new_password)) {
            http_response_code(400);
            $message = 'A new password must be between 12 and 72 bytes.';
            $message_is_error = true;
        } elseif ($password_change_requested && $current_password === '') {
            http_response_code(400);
            $message = 'Enter your current password to change it.';
            $message_is_error = true;
        } elseif ($upload_requested) {
            $staged_image = profile_stage_uploaded_image($upload);
            if ($staged_image === false) {
                http_response_code(400);
                $message = 'The profile image is invalid or could not be processed.';
                $message_is_error = true;
            }
        }

        if ($validated !== false && !$message_is_error) {
            $password_hash = null;
            if ($password_change_requested) {
                $password_query = mysqli_prepare(
                    $conn,
                    'SELECT password FROM users WHERE employee_id = ? LIMIT 1'
                );
                if ($password_query === false) {
                    http_response_code(500);
                    $message = 'Profile could not be updated.';
                    $message_is_error = true;
                } else {
                    mysqli_stmt_bind_param($password_query, 's', $employee_id);
                    $password_ok = mysqli_stmt_execute($password_query);
                    $stored_password = null;
                    if ($password_ok) {
                        mysqli_stmt_bind_result($password_query, $stored_password);
                        $password_ok = mysqli_stmt_fetch($password_query) === true;
                    }
                    mysqli_stmt_close($password_query);

                    if (!$password_ok || !is_string($stored_password) || !password_verify($current_password, $stored_password)) {
                        http_response_code(403);
                        $message = 'Current password could not be verified.';
                        $message_is_error = true;
                    } else {
                        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                        if (!is_string($password_hash)) {
                            http_response_code(500);
                            $message = 'Profile could not be updated.';
                            $message_is_error = true;
                        }
                    }
                }
            }

            if (!$message_is_error) {
                if ($password_change_requested) {
                    $update = mysqli_prepare(
                        $conn,
                        'UPDATE users SET full_name = ?, phone = ?, email = ?, password = ? WHERE employee_id = ?'
                    );
                } else {
                    $update = mysqli_prepare(
                        $conn,
                        'UPDATE users SET full_name = ?, phone = ?, email = ? WHERE employee_id = ?'
                    );
                }

                if ($update === false) {
                    http_response_code(500);
                    $message = 'Profile could not be updated.';
                    $message_is_error = true;
                } else {
                    if ($password_change_requested) {
                        mysqli_stmt_bind_param(
                            $update,
                            'sssss',
                            $validated['full_name'],
                            $validated['phone'],
                            $validated['email'],
                            $password_hash,
                            $employee_id
                        );
                    } else {
                        mysqli_stmt_bind_param(
                            $update,
                            'ssss',
                            $validated['full_name'],
                            $validated['phone'],
                            $validated['email'],
                            $employee_id
                        );
                    }

                    $updated = mysqli_stmt_execute($update);
                    mysqli_stmt_close($update);

                    if (!$updated) {
                        http_response_code(500);
                        $message = 'Profile could not be updated.';
                        $message_is_error = true;
                    } else {
                        $form_values = $validated;
                        $message = 'Profile updated.';
                        if ($staged_image !== null) {
                            if (!profile_install_staged_image($staged_image, $employee_id, $images_directory)) {
                                $message .= ' The existing profile picture was retained because the new image could not be installed.';
                                $message_is_error = true;
                            }
                        }
                    }
                }
            }
        }

        if (is_string($staged_image) && is_file($staged_image)) {
            @unlink($staged_image);
        }

        if (!$message_is_error) {
            $form_values = $validated;
        } else {
            foreach (['full_name', 'phone', 'email'] as $field) {
                if (isset($_POST[$field]) && is_string($_POST[$field])) {
                    $form_values[$field] = $_POST[$field];
                }
            }
        }
    } else {
        http_response_code(400);
        $message = 'Invalid profile request.';
        $message_is_error = true;
    }
}

$profile_query = mysqli_prepare(
    $conn,
    'SELECT employee_id, full_name, phone, email FROM users WHERE employee_id = ? LIMIT 1'
);
if ($profile_query === false) {
    http_response_code(500);
    exit('Profile could not be loaded.');
}

mysqli_stmt_bind_param($profile_query, 's', $employee_id);
if (!mysqli_stmt_execute($profile_query)) {
    mysqli_stmt_close($profile_query);
    http_response_code(500);
    exit('Profile could not be loaded.');
}

mysqli_stmt_bind_result($profile_query, $db_employee_id, $db_full_name, $db_phone, $db_email);
$profile_found = mysqli_stmt_fetch($profile_query);
mysqli_stmt_close($profile_query);

if (!$profile_found) {
    http_response_code(404);
    exit('Profile could not be loaded.');
}

if ($request_method === 'GET' || !$message_is_error) {
    $form_values = [
        'full_name' => (string) $db_full_name,
        'phone' => (string) $db_phone,
        'email' => (string) $db_email,
    ];
}

$profile_image = 'images/default.png';
$images_real_directory = realpath($images_directory);
$owned_image = $images_real_directory === false
    ? ''
    : $images_real_directory . DIRECTORY_SEPARATOR . $employee_id . '.jpg';
if ($owned_image !== '' && is_file($owned_image) && !is_link($owned_image)) {
    $profile_image = 'images/' . $employee_id . '.jpg';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Profile</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f1f2f6; }
        .container { width: min(450px, calc(100% - 40px)); background: white; margin: 50px auto; padding: 35px; border-radius: 10px; }
        input { box-sizing: border-box; width: 100%; padding: 12px; margin-top: 8px; }
        button { width: 100%; padding: 12px; margin-top: 20px; background: #0d6efd; color: white; border: 0; cursor: pointer; }
        label { display: block; margin: 15px 0 8px; font-weight: bold; color: #333; }
        .message { padding: 10px; color: #14532d; }
        .error { color: #991b1b; }
        .profile-image { width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 3px solid #0d6efd; }
    </style>
</head>
<body>
<main class="container">
    <h2>Edit Profile</h2>
    <?php if ($message !== ''): ?>
        <p class="message<?php echo $message_is_error ? ' error' : ''; ?>" role="status"><?php echo profile_escape($message); ?></p>
    <?php endif; ?>

    <img class="profile-image" src="<?php echo profile_escape($profile_image); ?>" alt="Profile picture">

    <form method="POST" action="edit_profile.php" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="update_profile">
        <label for="profile_image">Profile Picture (JPEG; max 5 MB)</label>
        <input id="profile_image" type="file" name="profile_image" accept="image/jpeg">

        <label for="full_name">Full Name</label>
        <input id="full_name" type="text" name="full_name" maxlength="100" required value="<?php echo profile_escape($form_values['full_name']); ?>">

        <label for="phone">Phone Number</label>
        <input id="phone" type="text" name="phone" maxlength="15" value="<?php echo profile_escape($form_values['phone']); ?>">

        <label for="email">Email Address</label>
        <input id="email" type="email" name="email" maxlength="100" value="<?php echo profile_escape($form_values['email']); ?>">

        <label for="current_password">Current Password (required only to change password)</label>
        <input id="current_password" type="password" name="current_password" autocomplete="current-password">

        <label for="new_password">New Password (leave blank to keep the current password)</label>
        <input id="new_password" type="password" name="new_password" minlength="12" maxlength="72" autocomplete="new-password">

        <button type="submit">Update Profile</button>
    </form>

    <form method="POST" action="edit_profile.php">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="delete_image">
        <button type="submit" style="background: #dc3545;">Delete Profile Picture</button>
    </form>
</main>
</body>
</html>
