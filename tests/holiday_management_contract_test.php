<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$holidayPage = file_get_contents($root . '/holidays.php');
$schema = file_get_contents($root . '/tests/fixtures/holiday_schema_contract.sql');
$navigation = file_get_contents($root . '/includes/navigation.php');
$styles = file_get_contents($root . '/css/style.css');

function holidayManagementAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

holidayManagementAssert(
    strpos($holidayPage, 'auth_require_authenticated($conn)') !== false
        && strpos($holidayPage, "if (\$role !== 'Admin')") !== false,
    'holiday listing requires an active session and mutations require Admin'
);
holidayManagementAssert(
    strpos($navigation, "['key' => 'holidays', 'href' => 'holidays.php'") !== false
        && strpos($holidayPage, "if (\$role === 'Admin')") !== false,
    'holiday calendar is available in navigation and Employee view is read-only'
);
holidayManagementAssert(
    strpos($holidayPage, "csrf_validate_token(\$_POST['csrf_token'] ?? null)") !== false
        && strpos($holidayPage, 'csrf_field()') !== false,
    'holiday mutations validate CSRF and forms carry a token'
);
holidayManagementAssert(
    strpos($holidayPage, 'INSERT INTO holidays (holiday_name, holiday_date) VALUES (?, ?)') !== false
        && strpos($holidayPage, 'UPDATE holidays SET holiday_name = ?, holiday_date = ? WHERE id = ?') !== false
        && strpos($holidayPage, "mysqli_stmt_bind_param(\$update, 'ssi'") !== false,
    'holiday add and update use prepared statements'
);
holidayManagementAssert(
    strpos($holidayPage, 'SELECT id FROM holidays WHERE holiday_date = ? AND id <> ? LIMIT 1') !== false
        && strpos($holidayPage, '=== 1062') !== false
        && strpos($schema, 'UNIQUE KEY uq_holidays_date (holiday_date)') !== false,
    'duplicate date checks and the database unique key cover concurrent submissions'
);
holidayManagementAssert(
    strpos($holidayPage, 'SELECT id FROM attendance WHERE attendance_date = ? OR attendance_date = ? LIMIT 1') !== false
        && strpos($holidayPage, 'A holiday is already configured for this date.') !== false,
    'changing a holiday date is rejected when Attendance exists for the old or new date'
);
holidayManagementAssert(
    strpos($holidayPage, 'A holiday cannot be added in the past.') === false
        && strpos($holidayPage, 'A holiday date cannot be moved into the past.') === false,
    'valid past-date entries are not rejected by an unapproved business policy'
);
holidayManagementAssert(
    strpos($holidayPage, 'DELETE FROM holidays') === false
        && strpos($holidayPage, 'htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\')') !== false,
    'holiday history is not deleted and displayed values are escaped'
);
holidayManagementAssert(
    strpos($styles, '.holiday-page') !== false
        && strpos($styles, '@media (max-width: 760px)') !== false,
    'holiday page has scoped responsive styling'
);

echo "PASS: holiday management contracts; no database or HTTP operations were performed.\n";
