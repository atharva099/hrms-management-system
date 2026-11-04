<?php

declare(strict_types=1);

function databaseConfigAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$source = file_get_contents(dirname(__DIR__) . '/includes/db.php');
databaseConfigAssert(is_string($source), 'shared database bootstrap is readable');
$source = str_replace(["\r\n", "\r"], "\n", $source);
$accountSql = file_get_contents(dirname(__DIR__) . '/tests/fixtures/runtime_test_account_grants.sql.template');
databaseConfigAssert(is_string($accountSql), 'isolated test account template is readable');

foreach ([
    'COREAXIS_DB_HOST',
    'COREAXIS_DB_PORT',
    'COREAXIS_DB_NAME',
    'COREAXIS_DB_USER',
    'COREAXIS_DB_PASSWORD',
] as $setting) {
    databaseConfigAssert(strpos($source, "getenv('{$setting}')") !== false, "{$setting} is deployment supplied");
}
databaseConfigAssert(
    strpos($source, "getenv('COREAXIS_DB_CONFIG_FILE')") !== false
        && strpos($source, 'realpath($database_configuration_path)') !== false
        && strpos($source, '$configuration_inside_document_root') !== false,
    'Apache may load an operator-managed config file only from outside its document root'
);

databaseConfigAssert(
    strpos($source, 'strcasecmp($database_user, \'root\') !== 0') !== false,
    'MariaDB root account is rejected'
);
databaseConfigAssert(
    strpos($source, 'preg_match(\'/^[A-Za-z0-9_]+$/D\', $database_name)') !== false
        && strpos($source, 'if (is_int($database_port))') !== false
        && strpos($source, 'elseif (is_string($database_port) && ctype_digit($database_port))') !== false
        && strpos($source, "FILTER_VALIDATE_INT") !== false
        && strpos($source, "'max_range' => 65535") !== false,
    'database name and port are strictly validated'
);
databaseConfigAssert(
    strpos($source, 'mysqli_set_charset($conn, \'utf8mb4\')') !== false,
    'connection charset is explicitly utf8mb4'
);
databaseConfigAssert(
    strpos($source, "mysqli_connect(\n    \$database_host,\n    \$database_user,\n    \$database_password,\n    \$database_name,\n    \$database_port_number\n)") !== false,
    'mysqli connection receives exactly host, user, password, database and port'
);
databaseConfigAssert(
    strpos($source, "\$database_runtime_mode === 'isolated_http_test'") !== false
        && strpos($source, "\$database_name === 'coreaxis_r5_runtime_http_test_20261014_a91d2b6c'") !== false
        && strpos($source, "\$database_user === 'coreaxis_r5_http_test_20261014'") !== false
        && strpos($source, 'SELECT DATABASE(), @@port, CURRENT_USER()') !== false,
    'existing isolated HTTP mode pins its disposable identity before and after connecting'
);
databaseConfigAssert(
    strpos($source, "\$database_runtime_mode === 'isolated_mysql8_dev'") !== false
        && strpos($source, "\$database_port_number === 3306") !== false
        && strpos($source, "\$database_name === 'coreaxis_crm_dev_http_20261014_6b0f9a21'") !== false
        && strpos($source, "\$database_user === 'coreaxis_dev_http_20261014'") !== false
        && strpos($source, "'port' => '3306'") !== false
        && strpos($source, "'database' => 'coreaxis_crm_dev_http_20261014_6b0f9a21'") !== false,
    'MySQL 8 development mode is independently pinned to its exact loopback database and account'
);
databaseConfigAssert(
    strpos($source, '"root"') === false
        && strpos($source, "'root'") !== false
        && strpos($source, 'die("Database Connection Failed")') === false,
    'no privileged credential fallback or raw driver error is exposed'
);
databaseConfigAssert(
    strpos($source, "exit('Database service is not configured.')") !== false
        && strpos($source, "exit('Database service is unavailable.')") !== false,
    'missing configuration and connection failure stop with generic errors'
);
databaseConfigAssert(
    strpos($accountSql, '`{{TEST_DATABASE}}`.*') !== false
        && strpos($accountSql, 'coreaxis_crm') === false
        && strpos($accountSql, '*.*') === false,
    'test grant contract uses only a portable schema placeholder'
);
databaseConfigAssert(
    strpos($accountSql, 'GRANT SELECT, INSERT, UPDATE') !== false
        && strpos($accountSql, 'GRANT DELETE') !== false
        && strpos($accountSql, '`shifts`') !== false
        && strpos($accountSql, 'GRANT CREATE') === false
        && strpos($accountSql, 'GRANT ALTER') === false
        && strpos($accountSql, 'GRANT DROP') === false
        && strpos($accountSql, 'GRANT TRIGGER') === false
        && strpos($accountSql, 'IDENTIFIED BY {{APP_PASSWORD_SQL_LITERAL}}') !== false
        && preg_match('/^\s*(?:CREATE USER|GRANT)\b/mi', $accountSql) === 0,
    'comment-only contract retains scoped grant assertions without executable SQL or a credential'
);

echo "PASS: database configuration contracts; no database connection attempted.\n";
