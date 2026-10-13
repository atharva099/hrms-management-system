<?php

/**
 * Normalize mysqli attendance IDs without accepting lossy PHP casts.
 */
function overnightNormalizePositiveId($value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }

    if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) {
        return null;
    }

    $normalized = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]]
    );
    return $normalized === false ? null : $normalized;
}

/**
 * Exclude only the verified inserted test row; other matching business keys
 * remain in the preservation comparison and therefore cannot hide changes.
 */
function overnightRowsWithoutInsertedTestAttendance(array $rows, ?int $insertedId): array
{
    if ($insertedId === null) {
        return $rows;
    }

    return array_values(array_filter($rows, static function ($row) use ($insertedId) {
        $rowId = overnightNormalizePositiveId($row['id'] ?? null);
        return !(
            $rowId === $insertedId
            && ($row['employee_id'] ?? null) === 'EMP9806'
            && ($row['attendance_date'] ?? null) === '2026-10-11'
        );
    }));
}
