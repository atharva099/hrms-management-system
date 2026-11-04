<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$php = file_get_contents($root . '/tools/bootstrap_admin.php');
$ps = file_get_contents($root . '/tools/bootstrap_admin.ps1');
$checks = 0;
$assert = static function ($condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};
$assert(is_string($php) && is_string($ps), 'bootstrap sources are present');
$assert(strpos($php, "if (PHP_SAPI !== 'cli')") !== false && strpos($php, "http_response_code(404)") !== false, 'PHP entrypoint is CLI-only');
$assert(strpos($php, "getenv('COREAXIS_DB_MODE')") !== false && strpos($php, 'database_mode_must_be_empty') !== false, 'non-empty database mode is rejected');
$assert(strpos($php, 'realpath(dirname(__DIR__))') !== false && strpos($php, 'database_configuration_must_be_outside_project') !== false, 'database config inside project is rejected');
$assert(strpos($php, 'coreaxis_password_is_valid($password)') !== false && strpos($php, 'password_hash($password, PASSWORD_DEFAULT)') !== false, 'shared password policy and PASSWORD_DEFAULT hash are used');
$assert(strpos($php, "require_once dirname(__DIR__) . '/includes/password_policy.php'") !== false && strpos($php, 'strlen($password)') === false, 'bootstrap reuses the shared byte-length policy rather than duplicating it');
$assert(strpos($php, 'users_table_not_empty') !== false && strpos($php, "SELECT id FROM users LIMIT 1 FOR UPDATE") !== false, 'existing user blocks bootstrap inside the transaction');
$assert(strpos($php, "'database_port', 'employee_id', 'full_name', 'department', 'designation', 'joining_date', 'phone', 'email', 'password'") !== false && strpos($php, "'Admin', 'Active'") !== false, 'required profile fields are collected and only an Active Admin role is inserted');
$assert(strpos($php, "INSERT INTO users") !== false && strpos($php, 'UPDATE users') === false && stripos($php, 'ON DUPLICATE KEY') === false && stripos($php, 'REPLACE INTO') === false, 'only insert behavior exists; no update/upsert path');
$assert(strpos($php, 'GET_LOCK(?, 10)') !== false && strpos($php, 'RELEASE_LOCK(?)') !== false && strpos($php, 'begin_transaction()') !== false && strpos($php, 'rollback()') !== false, 'schema-scoped advisory lock and transaction rollback/release are present');
$assert(strpos($php, 'SELECT DATABASE(), @@port, VERSION(), @@version_comment, CURRENT_USER()') !== false && strpos($php, "'attendance_correction_audit'") !== false && strpos($php, "'trg_aca_no_delete'") !== false && strpos($php, '$port_confirmation') !== false, 'server identity and expected core schema are validated');
$assert(strpos($php, "'fk_aca_salary'") !== false && strpos($php, "'chk_aca_payroll_reconciliation_ref'") !== false && strpos($php, "'leave_attendance_audit_no_update'") !== false, 'required foreign keys, checks, and audit triggers are validated');
$assert(preg_match('/\$database_password\s*=\s*[\'\"]/i', $php) !== 1 && preg_match('/password\s*=>\s*[\'\"]/i', $php) !== 1 && strpos($php, 'COREAXIS_DB_PASSWORD') === false, 'bootstrap contains no hardcoded database or user password');
$assert(strpos($ps, "Read-Host 'New Admin password (hidden)' -AsSecureString") !== false && strpos($ps, "Read-Host 'Confirm Admin password (hidden)' -AsSecureString") !== false, 'PowerShell collects password via hidden secure input');
$assert(strpos($ps, 'StandardInput.BaseStream.Write($bytes, 0, $bytes.Length)') !== false && strpos($ps, 'StandardInput') !== false, 'password payload is sent through process stdin');
$assert(strpos($ps, '$startInfo.Arguments =') !== false && strpos($ps, '$json') !== false && strpos($ps, '$startInfo.Arguments = $json') === false, 'password is not assigned to command-line arguments');
$assert(strpos($ps, 'Set-Content') === false && strpos($ps, 'WriteAllText') === false && strpos($ps, 'WriteAllBytes') === false && strpos($ps, 'Write-Output $json') === false, 'launcher does not persist or output the password payload');
$assert(preg_match('/Write-(?:Output|Host|Verbose|Information|Debug|Warning|Error)\s+\$(?:plainOne|plainTwo|json|payload|secureOne|secureTwo)/i', $ps) !== 1, 'launcher does not print secure inputs or the serialized payload');
$assert(strpos($ps, 'COREAXIS_PHP_CLI') !== false && strpos($ps, 'COREAXIS_DB_MODE') !== false && strpos($ps, '$startInfo.Arguments = $json') === false, 'launcher uses configured PHP CLI and rejects test mode without putting payload in arguments');
echo "PASS: {$checks} Admin bootstrap contract checks\n";
