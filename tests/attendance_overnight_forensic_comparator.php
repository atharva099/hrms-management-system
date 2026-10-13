<?php
/**
 * Read-only exact row comparison for the overnight test forensic review.
 * Fixed schemas only; performs SELECTs in read-only consistent snapshots.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "BLOCKED: CLI only.\n");
    exit(2);
}

const FORENSIC_HOST = '127.0.0.1';
const FORENSIC_PORT = 3307;
const FORENSIC_BASELINE = 'coreaxis_r5_overnight_forensic_baseline_20261011';
const FORENSIC_CURRENT = 'coreaxis_r5_attendance_overnight_rerun_20261013_a6d29f4c';

require_once __DIR__ . '/attendance_overnight_preservation_helpers.php';
require_once __DIR__ . '/attendance_forensic_compare_lib.php';

$tables = [
    'attendance', 'users', 'leaves', 'salaries',
    'leave_attendance_audit', 'attendance_correction_audit'
];
$requiredColumns = [
    'attendance' => ['id', 'employee_id', 'attendance_date', 'check_in_at', 'check_out_at', 'scheduled_start_at', 'scheduled_end_at', 'late_after_at', 'shift_id_snapshot', 'shift_name_snapshot', 'early_checkin_minutes_snapshot', 'late_checkin_cutoff_minutes_snapshot', 'row_version'],
    'users' => ['id', 'employee_id'],
    'leaves' => ['id', 'employee_id'],
    'salaries' => ['id', 'employee_id', 'salary_period'],
    'leave_attendance_audit' => [],
    'attendance_correction_audit' => []
];
$expectedAuditTriggers = [
    'trg_aca_no_delete' => ['attendance_correction_audit', 'BEFORE', 'DELETE'],
    'trg_aca_no_update' => ['attendance_correction_audit', 'BEFORE', 'UPDATE']
];
$connections = [];
$failure = false;
$dbUser = getenv('COREAXIS_TEST_DB_USER');
$dbPassword = getenv('COREAXIS_TEST_DB_PASSWORD');

function forensicOutput(bool $pass, string $message): void
{
    echo ($pass ? 'PASS: ' : 'FAIL: ') . $message . PHP_EOL;
}

function forensicQuery(mysqli $connection, string $sql, string $types = '', ...$values): array
{
    $statement = $connection->prepare($sql);
    if ($types !== '') {
        $bind = [$types];
        foreach ($values as &$value) {
            $bind[] = &$value;
        }
        unset($value);
        if (!call_user_func_array([$statement, 'bind_param'], $bind)) {
            $statement->close();
            throw new RuntimeException('query-bind-failed');
        }
    }
    $statement->execute();
    $result = $statement->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
    $statement->close();
    return $rows;
}

function forensicSchema(mysqli $connection, string $schema, array $tables): array
{
    $result = [];
    foreach ($tables as $table) {
        $result[$table] = [
            'columns' => forensicQuery(
                $connection,
                'SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                'ss',
                $schema,
                $table
            ),
            'indexes' => forensicQuery(
                $connection,
                'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, COLLATION, SUB_PART, NULLABLE, INDEX_TYPE, COMMENT, INDEX_COMMENT FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
                'ss',
                $schema,
                $table
            ),
            'foreign_keys' => forensicQuery(
                $connection,
                'SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.ORDINAL_POSITION, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, k.POSITION_IN_UNIQUE_CONSTRAINT, r.UPDATE_RULE, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=? AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION',
                'ss',
                $schema,
                $table
            )
        ];
    }
    $result['_engines'] = forensicQuery(
        $connection,
        'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_TYPE=\'BASE TABLE\' AND TABLE_NAME IN (\'attendance\',\'users\',\'leaves\',\'salaries\',\'leave_attendance_audit\',\'attendance_correction_audit\') ORDER BY TABLE_NAME',
        's',
        $schema
    );
    $result['_triggers'] = forensicQuery(
        $connection,
        'SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=? AND EVENT_OBJECT_TABLE=\'attendance_correction_audit\' ORDER BY TRIGGER_NAME',
        's',
        $schema
    );
    return $result;
}

function forensicPrimaryColumns(mysqli $connection, string $schema, string $table): array
{
    $rows = forensicQuery(
        $connection,
        'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND INDEX_NAME=\'PRIMARY\' ORDER BY SEQ_IN_INDEX',
        'ss',
        $schema,
        $table
    );
    return array_column($rows, 'COLUMN_NAME');
}

function forensicTableRows(mysqli $connection, string $schema, string $table, array $primaryColumns): array
{
    $quotedSchema = '`' . str_replace('`', '``', $schema) . '`';
    $quotedTable = '`' . str_replace('`', '``', $table) . '`';
    $orderBy = implode(', ', array_map(static function ($column) {
        return '`' . str_replace('`', '``', $column) . '`';
    }, $primaryColumns));
    return forensicQuery($connection, "SELECT * FROM {$quotedSchema}.{$quotedTable} ORDER BY {$orderBy}");
}

function forensicFormatKeys(array $keys): string
{
    return $keys === [] ? '[]' : '[' . implode(',', $keys) . ']';
}

try {
    if ($dbUser === false || $dbUser === '') {
        throw new RuntimeException('credentials-missing');
    }
    if ($dbPassword === false) {
        $dbPassword = '';
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    foreach ([FORENSIC_BASELINE, FORENSIC_CURRENT] as $database) {
        $connection = new mysqli(FORENSIC_HOST, $dbUser, $dbPassword, $database, FORENSIC_PORT);
        $connection->set_charset('utf8mb4');
        $connections[$database] = $connection;
        $connection->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
        $identity = forensicQuery($connection, 'SELECT DATABASE() AS db_name, @@port AS db_port, VERSION() AS version, @@version_comment AS version_comment')[0] ?? [];
        $identityOk = ($identity['db_name'] ?? null) === $database
            && (int) ($identity['db_port'] ?? 0) === FORENSIC_PORT
            && strpos((string) ($identity['version'] ?? ''), '10.4.') === 0
            && stripos((string) ($identity['version_comment'] ?? ''), 'mariadb') !== false;
        forensicOutput($identityOk, "identity {$database} at fixed loopback endpoint");
        if (!$identityOk) {
            $failure = true;
            throw new RuntimeException('database-identity-mismatch');
        }
    }

    $schemaData = [];
    foreach ([FORENSIC_BASELINE, FORENSIC_CURRENT] as $database) {
        $schemaData[$database] = forensicSchema($connections[$database], $database, $tables);
        foreach ($tables as $table) {
            $names = array_column($schemaData[$database][$table]['columns'], 'COLUMN_NAME');
            $expectedPresent = count($names) > 0;
            foreach ($requiredColumns[$table] as $requiredColumn) {
                $expectedPresent = $expectedPresent && in_array($requiredColumn, $names, true);
            }
            forensicOutput($expectedPresent, "required schema {$database}.{$table}");
            if (!$expectedPresent) {
                $failure = true;
            }
        }
        $triggerMap = [];
        foreach ($schemaData[$database]['_triggers'] as $trigger) {
            $triggerMap[$trigger['TRIGGER_NAME']] = [
                $trigger['EVENT_OBJECT_TABLE'],
                $trigger['ACTION_TIMING'],
                $trigger['EVENT_MANIPULATION']
            ];
        }
        ksort($triggerMap, SORT_STRING);
        $expected = $expectedAuditTriggers;
        ksort($expected, SORT_STRING);
        $triggerBodiesOk = count($schemaData[$database]['_triggers']) === 2;
        foreach ($schemaData[$database]['_triggers'] as $trigger) {
            $triggerBodiesOk = $triggerBodiesOk
                && preg_match("/SIGNAL\\s+SQLSTATE\\s+'45000'/i", (string) $trigger['ACTION_STATEMENT']) === 1
                && stripos((string) $trigger['ACTION_STATEMENT'], 'append-only') !== false;
        }
        $triggersOk = $triggerMap === $expected && $triggerBodiesOk;
        forensicOutput($triggersOk, "audit protection trigger inventory and append-only bodies {$database}");
        if (!$triggersOk) {
            $failure = true;
        }
    }

    $schemaMatchesByTable = [];
    foreach ($tables as $table) {
        $baselineSchema = forensicNormalizeLocalForeignKeySchema(
            $schemaData[FORENSIC_BASELINE][$table],
            FORENSIC_BASELINE
        );
        $currentSchema = forensicNormalizeLocalForeignKeySchema(
            $schemaData[FORENSIC_CURRENT][$table],
            FORENSIC_CURRENT
        );
        $schemaMatches = $baselineSchema === $currentSchema;
        $schemaMatchesByTable[$table] = $schemaMatches;
        forensicOutput($schemaMatches, "column/index/foreign-key schema {$table}");
        if (!$schemaMatches) {
            $failure = true;
            continue;
        }
    }
    $engineMatch = $schemaData[FORENSIC_BASELINE]['_engines'] === $schemaData[FORENSIC_CURRENT]['_engines'];
    forensicOutput($engineMatch, 'storage engine and table collation inventory');
    if (!$engineMatch) {
        $failure = true;
    }

    $primaryColumns = [];
    foreach ($tables as $table) {
        if (empty($schemaMatchesByTable[$table])) {
            forensicOutput(false, "{$table} row comparison skipped because schema did not match");
            $failure = true;
            continue;
        }
        $baselinePk = forensicPrimaryColumns($connections[FORENSIC_BASELINE], FORENSIC_BASELINE, $table);
        $currentPk = forensicPrimaryColumns($connections[FORENSIC_CURRENT], FORENSIC_CURRENT, $table);
        $pkOk = $baselinePk !== [] && $baselinePk === $currentPk;
        forensicOutput($pkOk, "primary key definition {$table}");
        if (!$pkOk) {
            $failure = true;
            continue;
        }
        $primaryColumns[$table] = $baselinePk;
    }

    if (isset($primaryColumns['attendance']) && isset($primaryColumns['attendance'][0])) {
        $idColumn = $primaryColumns['attendance'][0];
        if (count($primaryColumns['attendance']) !== 1 || $idColumn !== 'id') {
            forensicOutput(false, 'attendance primary key is the expected single id column');
            $failure = true;
        } else {
            $baselineAttendance = forensicRowsByIntegerId(
                forensicTableRows($connections[FORENSIC_BASELINE], FORENSIC_BASELINE, 'attendance', $primaryColumns['attendance']),
                'id'
            );
            $currentAttendance = forensicRowsByIntegerId(
                forensicTableRows($connections[FORENSIC_CURRENT], FORENSIC_CURRENT, 'attendance', $primaryColumns['attendance']),
                'id'
            );
            $baselineHas56 = isset($baselineAttendance[56]);
            $testRow = $currentAttendance[56] ?? [];
            $testRowsForEmployeeDates = array_filter($currentAttendance, static function ($row) {
                return ($row['employee_id'] ?? null) === 'EMP9806'
                    && in_array($row['attendance_date'] ?? null, ['2026-10-11', '2026-10-12'], true);
            });
            $test56Mismatches = forensicOvernightRowMismatches($testRow, count($testRowsForEmployeeDates));
            $test56Valid = $test56Mismatches === [];
            if (!$test56Valid) {
                fwrite(STDERR, 'INFO: attendance ID 56 expected-field mismatches=' . implode(',', $test56Mismatches) . PHP_EOL);
            }
            forensicOutput(!$baselineHas56 && $test56Valid, 'attendance ID 56 is absent from baseline and matches the complete overnight test row in current');
            if ($baselineHas56 || !$test56Valid) {
                $failure = true;
            } else {
                unset($currentAttendance[56]);
            }
            $baselineRows = array_values($baselineAttendance);
            $currentRows = array_values($currentAttendance);
            $result = forensicCompareRows($baselineRows, $currentRows, $primaryColumns['attendance']);
            $pass = $result['added'] === [] && $result['missing'] === [] && $result['changed'] === [];
            forensicOutput($pass, 'attendance added=' . forensicFormatKeys($result['added'])
                . ' missing=' . forensicFormatKeys($result['missing'])
                . ' changed=' . json_encode($result['changed'], JSON_UNESCAPED_SLASHES));
            if (!$pass) {
                $failure = true;
            }
            foreach ([53, 54, 55] as $protectedId) {
                $same = isset($baselineAttendance[$protectedId], $currentAttendance[$protectedId])
                    && $baselineAttendance[$protectedId] === $currentAttendance[$protectedId];
                forensicOutput($same, "protected attendance ID {$protectedId} exact row match");
                if (!$same) {
                    $failure = true;
                }
            }
        }
    } else {
        forensicOutput(false, 'attendance row comparison skipped because a matching primary key was unavailable');
        $failure = true;
    }

    foreach (array_slice($tables, 1) as $table) {
        if (!isset($primaryColumns[$table])) {
            forensicOutput(false, "{$table} row comparison skipped because a matching primary key was unavailable");
            $failure = true;
            continue;
        }
        $baselineRows = forensicTableRows($connections[FORENSIC_BASELINE], FORENSIC_BASELINE, $table, $primaryColumns[$table]);
        $currentRows = forensicTableRows($connections[FORENSIC_CURRENT], FORENSIC_CURRENT, $table, $primaryColumns[$table]);
        $result = forensicCompareRows($baselineRows, $currentRows, $primaryColumns[$table]);
        $pass = $result['added'] === [] && $result['missing'] === [] && $result['changed'] === [];
        forensicOutput($pass, "{$table} added=" . forensicFormatKeys($result['added'])
            . ' missing=' . forensicFormatKeys($result['missing'])
            . ' changed=' . json_encode($result['changed'], JSON_UNESCAPED_SLASHES));
        if (!$pass) {
            $failure = true;
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: forensic comparison stopped (' . preg_replace('/[^a-z0-9-]/i', '', $error->getMessage()) . ').' . PHP_EOL);
    $failure = true;
} finally {
    foreach ($connections as $connection) {
        try {
            $connection->rollback();
            $connection->close();
        } catch (Throwable $ignored) {
            $failure = true;
            fwrite(STDERR, "FAIL: read-only snapshot cleanup failed." . PHP_EOL);
        }
    }
}

forensicOutput(!$failure, 'overall forensic preservation result');
exit($failure ? 1 : 0);
