<?php

function forensicKeyToken(array $row, array $primaryColumns): string
{
    $parts = [];
    foreach ($primaryColumns as $column) {
        if (!array_key_exists($column, $row) || $row[$column] === null) {
            throw new RuntimeException('invalid-primary-key');
        }
        $value = $row[$column];
        $normalizedId = overnightNormalizePositiveId($value);
        $parts[] = $normalizedId ?? $value;
    }
    return serialize($parts);
}

function forensicKeyLabel(array $row, array $primaryColumns): string
{
    $parts = [];
    foreach ($primaryColumns as $column) {
        $value = $row[$column] ?? null;
        if (count($primaryColumns) === 1 && (is_int($value) || is_string($value))) {
            $integer = overnightNormalizePositiveId($value);
            if ($integer !== null) {
                return (string) $integer;
            }
        }
        $parts[] = $value;
    }
    return 'key#' . substr(hash('sha256', serialize($parts)), 0, 16);
}

/** Compare keyed complete rows; return keys and changed column names, never values. */
function forensicCompareRows(array $baselineRows, array $currentRows, array $primaryColumns): array
{
    $baseline = [];
    foreach ($baselineRows as $row) {
        $key = forensicKeyToken($row, $primaryColumns);
        if (isset($baseline[$key])) {
            throw new RuntimeException('duplicate-baseline-primary-key');
        }
        $baseline[$key] = $row;
    }

    $current = [];
    foreach ($currentRows as $row) {
        $key = forensicKeyToken($row, $primaryColumns);
        if (isset($current[$key])) {
            throw new RuntimeException('duplicate-current-primary-key');
        }
        $current[$key] = $row;
    }

    $added = [];
    $missing = [];
    $changed = [];
    foreach ($current as $key => $row) {
        if (!array_key_exists($key, $baseline)) {
            $added[] = forensicKeyLabel($row, $primaryColumns);
            continue;
        }
        $changedColumns = [];
        foreach (array_unique(array_merge(array_keys($baseline[$key]), array_keys($row))) as $column) {
            if (!array_key_exists($column, $baseline[$key]) || !array_key_exists($column, $row)) {
                $changedColumns[] = $column;
                continue;
            }
            $before = $baseline[$key][$column];
            $after = $row[$column];
            $same = $before === $after;
            if (!$same && in_array($column, $primaryColumns, true)) {
                $beforeId = overnightNormalizePositiveId($before);
                $afterId = overnightNormalizePositiveId($after);
                $same = $beforeId !== null && $beforeId === $afterId;
            }
            if (!$same) {
                $changedColumns[] = $column;
            }
        }
        if ($changedColumns !== []) {
            sort($changedColumns, SORT_STRING);
            $changed[forensicKeyLabel($row, $primaryColumns)] = $changedColumns;
        }
    }
    foreach ($baseline as $key => $row) {
        if (!array_key_exists($key, $current)) {
            $missing[] = forensicKeyLabel($row, $primaryColumns);
        }
    }

    sort($added, SORT_STRING);
    sort($missing, SORT_STRING);
    ksort($changed, SORT_STRING);
    return ['added' => $added, 'missing' => $missing, 'changed' => $changed];
}

function forensicRowsByIntegerId(array $rows, string $idColumn): array
{
    $indexed = [];
    foreach ($rows as $row) {
        $id = overnightNormalizePositiveId($row[$idColumn] ?? null);
        if ($id === null || isset($indexed[$id])) {
            throw new RuntimeException('invalid-or-duplicate-row-id');
        }
        $indexed[$id] = $row;
    }
    return $indexed;
}

/** Compare a database integer with a fixed expected integer across mysqli drivers. */
function forensicIntegerEquals($value, int $expected): bool
{
    if ($expected < 0 || !(is_int($value) || is_string($value))) {
        return false;
    }
    if (is_int($value)) {
        return $value === $expected;
    }
    if (!preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)) {
        return false;
    }
    return $value === (string) $expected;
}

/** Validate the fixed overnight fixture contract and return failed field names only. */
function forensicOvernightRowMismatches(array $row, int $employeeBusinessDateRowCount): array
{
    $checks = [
        'id' => forensicIntegerEquals($row['id'] ?? null, 56),
        'employee_id' => ($row['employee_id'] ?? null) === 'EMP9806',
        'attendance_date' => ($row['attendance_date'] ?? null) === '2026-10-11',
        'check_in_at' => ($row['check_in_at'] ?? null) === '2026-10-11 21:50:00',
        'check_out_at' => ($row['check_out_at'] ?? null) === '2026-10-12 06:05:00',
        'scheduled_start_at' => ($row['scheduled_start_at'] ?? null) === '2026-10-11 22:00:00',
        'scheduled_end_at' => ($row['scheduled_end_at'] ?? null) === '2026-10-12 06:00:00',
        'working_hours' => ($row['working_hours'] ?? null) === '8 hrs 15 mins',
        'status' => ($row['status'] ?? null) === 'Sunday Working',
        'row_version' => forensicIntegerEquals($row['row_version'] ?? null, 2),
        'shift_id_snapshot' => forensicIntegerEquals($row['shift_id_snapshot'] ?? null, 13),
        'shift_name_snapshot' => is_string($row['shift_name_snapshot'] ?? null)
            && $row['shift_name_snapshot'] !== '',
        'early_checkin_minutes_snapshot' => forensicIntegerEquals($row['early_checkin_minutes_snapshot'] ?? null, 15),
        'late_checkin_cutoff_minutes_snapshot' => forensicIntegerEquals($row['late_checkin_cutoff_minutes_snapshot'] ?? null, 120),
        'employee_business_date_row_count' => $employeeBusinessDateRowCount === 1
    ];
    return array_keys(array_filter($checks, static function ($passed) {
        return !$passed;
    }));
}

/**
 * Canonicalize only foreign-key references that point back into the schema
 * currently being inspected. External cross-schema targets remain literal.
 */
function forensicNormalizeLocalForeignKeySchema(array $tableSchema, string $inspectedSchema): array
{
    if (!isset($tableSchema['foreign_keys']) || !is_array($tableSchema['foreign_keys'])) {
        return $tableSchema;
    }

    foreach ($tableSchema['foreign_keys'] as &$foreignKey) {
        if (isset($foreignKey['REFERENCED_TABLE_SCHEMA'])
            && $foreignKey['REFERENCED_TABLE_SCHEMA'] === $inspectedSchema) {
            $foreignKey['REFERENCED_TABLE_SCHEMA'] = '__LOCAL_SCHEMA__';
        }
    }
    unset($foreignKey);

    return $tableSchema;
}
