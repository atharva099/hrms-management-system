<?php

/** Validate a password using its raw byte length; callers must not trim it. */
function coreaxis_password_is_valid($password)
{
    if (!is_string($password)) {
        return false;
    }

    $byte_length = strlen($password);

    return $byte_length >= 12 && $byte_length <= 72;
}
