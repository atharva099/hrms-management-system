<?php

/**
 * Narrow, database-free validation helpers for the self-service profile page.
 */
function profile_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function profile_valid_employee_id($employee_id)
{
    return is_string($employee_id)
        && strlen($employee_id) <= 20
        && preg_match('/^EMP[0-9]{3,17}$/iD', $employee_id) === 1;
}

function profile_text_length($value)
{
    if (!is_string($value) || preg_match_all('/./us', $value, $characters) === false) {
        return false;
    }

    return count($characters[0]);
}

function profile_validate_fields($full_name, $phone, $email)
{
    if (!is_string($full_name) || !is_string($phone) || !is_string($email)) {
        return false;
    }

    $full_name = trim($full_name);
    $phone = trim($phone);
    $email = trim($email);
    $name_length = profile_text_length($full_name);

    if (
        $name_length === false
        || $name_length < 1
        || $name_length > 100
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $full_name)
    ) {
        return false;
    }

    if (
        strlen($phone) > 15
        || ($phone !== '' && preg_match('/^[0-9+(). -]+$/D', $phone) !== 1)
        || strlen($email) > 100
        || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)
    ) {
        return false;
    }

    return [
        'full_name' => $full_name,
        'phone' => $phone === '' ? null : $phone,
        'email' => $email === '' ? null : $email,
    ];
}

function profile_validate_new_password($password)
{
    return is_string($password)
        && strlen($password) >= 12
        && strlen($password) <= 72;
}

/** Inspect actual file content; caller separately verifies HTTP upload provenance. */
function profile_inspect_image_file($path)
{
    if (!is_string($path) || !is_file($path) || !is_readable($path)) {
        return false;
    }

    $size = filesize($path);
    if ($size === false || $size < 1 || $size > 5 * 1024 * 1024) {
        return false;
    }

    $image_info = @getimagesize($path);
    if (!is_array($image_info) || empty($image_info[0]) || empty($image_info[1])) {
        return false;
    }

    if (($image_info[2] ?? null) !== IMAGETYPE_JPEG) {
        return false;
    }

    if ($image_info[0] > 6000 || $image_info[1] > 6000 || $image_info[0] * $image_info[1] > 25000000) {
        return false;
    }

    if (!class_exists('finfo')) {
        return false;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($path);
    return $mime === 'image/jpeg';
}

/** Stage a real, structurally valid JPEG upload outside the public document root. */
function profile_stage_uploaded_image($upload)
{
    if (
        !is_array($upload)
        || !isset($upload['error'], $upload['tmp_name'])
        || !is_int($upload['error'])
        || $upload['error'] !== UPLOAD_ERR_OK
        || !is_string($upload['tmp_name'])
        || !is_uploaded_file($upload['tmp_name'])
        || !profile_inspect_image_file($upload['tmp_name'])
    ) {
        return false;
    }

    $staged_path = tempnam(sys_get_temp_dir(), 'coreaxis-profile-');
    if ($staged_path === false) {
        return false;
    }

    $saved = @copy($upload['tmp_name'], $staged_path);

    if (!$saved || !profile_inspect_image_file($staged_path)) {
        @unlink($staged_path);
        return false;
    }

    return $staged_path;
}

/** Install the normalized image at the legacy, server-derived employee filename. */
function profile_install_staged_image($staged_path, $employee_id, $images_directory)
{
    if (
        !profile_valid_employee_id($employee_id)
        || !is_string($staged_path)
        || !profile_inspect_image_file($staged_path)
        || !is_string($images_directory)
    ) {
        return false;
    }

    $directory = realpath($images_directory);
    if ($directory === false || !is_dir($directory) || !is_writable($directory)) {
        return false;
    }

    $target = $directory . DIRECTORY_SEPARATOR . $employee_id . '.jpg';
    if (is_link($target) || (file_exists($target) && !is_file($target))) {
        return false;
    }

    // Stage inside the target directory only after validation, so rename stays on-volume.
    $replacement = tempnam($directory, '.profile-');
    if ($replacement === false || !@copy($staged_path, $replacement)) {
        if (is_string($replacement) && file_exists($replacement)) {
            @unlink($replacement);
        }
        return false;
    }

    $backup = null;
    if (file_exists($target)) {
        $backup = tempnam(sys_get_temp_dir(), 'coreaxis-profile-backup-');
        if ($backup === false || !@copy($target, $backup)) {
            if (is_string($backup) && file_exists($backup)) {
                @unlink($backup);
            }
            @unlink($replacement);
            return false;
        }
    }

    if (!@rename($replacement, $target)) {
        // Some Windows filesystems refuse rename-over-existing; replace only
        // after a private backup has been safely copied.
        if ($backup === null || !@unlink($target) || !@rename($replacement, $target)) {
            if ($backup !== null && is_file($backup)) {
                @copy($backup, $target);
            }
            if ($backup !== null && is_file($backup)) {
                @unlink($backup);
            }
            @unlink($replacement);
            return false;
        }
    }

    if ($backup !== null) {
        @unlink($backup);
    }

    return true;
}

function profile_delete_owned_image($employee_id, $images_directory)
{
    if (!profile_valid_employee_id($employee_id) || !is_string($images_directory)) {
        return false;
    }

    $directory = realpath($images_directory);
    if ($directory === false || !is_dir($directory)) {
        return false;
    }

    $target = $directory . DIRECTORY_SEPARATOR . $employee_id . '.jpg';
    if (is_link($target)) {
        return false;
    }

    return !file_exists($target) || (is_file($target) && @unlink($target));
}
