<?php

/*
 * Application database credentials must be injected by the deployment
 * environment. Never fall back to a bundled administrator account.
 */
mysqli_report(MYSQLI_REPORT_OFF);

$database_configuration_path = getenv('COREAXIS_DB_CONFIG_FILE');
$database_runtime_mode = getenv('COREAXIS_DB_MODE');
$loaded_database_configuration = null;

if (is_string($database_configuration_path) && trim($database_configuration_path) !== '') {
    $resolved_configuration_path = realpath($database_configuration_path);
    $resolved_document_root = isset($_SERVER['DOCUMENT_ROOT'])
        ? realpath((string) $_SERVER['DOCUMENT_ROOT'])
        : false;
    $configuration_inside_document_root = false;
    if ($resolved_configuration_path !== false && $resolved_document_root !== false) {
        $document_root_prefix = rtrim($resolved_document_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $configuration_inside_document_root =
            strcasecmp($resolved_configuration_path, $resolved_document_root) === 0
            || strncasecmp($resolved_configuration_path, $document_root_prefix, strlen($document_root_prefix)) === 0;
    }

    if (
        $resolved_configuration_path === false
        || !is_file($resolved_configuration_path)
        || !is_readable($resolved_configuration_path)
        || $configuration_inside_document_root
    ) {
        http_response_code(503);
        exit('Database service is not configured.');
    }

    try {
        $loaded_database_configuration = require $resolved_configuration_path;
    } catch (Throwable $configuration_error) {
        http_response_code(503);
        exit('Database service is not configured.');
    }
    if (!is_array($loaded_database_configuration)) {
        http_response_code(503);
        exit('Database service is not configured.');
    }
    $database_host = $loaded_database_configuration['host'] ?? null;
    $database_port = $loaded_database_configuration['port'] ?? null;
    $database_name = $loaded_database_configuration['database'] ?? null;
    $database_user = $loaded_database_configuration['user'] ?? null;
    $database_password = $loaded_database_configuration['password'] ?? null;
    if (isset($loaded_database_configuration['mode'])) {
        $database_runtime_mode = $loaded_database_configuration['mode'];
    }
} else {
    $database_host = getenv('COREAXIS_DB_HOST');
    $database_port = getenv('COREAXIS_DB_PORT');
    $database_name = getenv('COREAXIS_DB_NAME');
    $database_user = getenv('COREAXIS_DB_USER');
    $database_password = getenv('COREAXIS_DB_PASSWORD');
}

$database_port_number = false;
if (is_int($database_port)) {
    $database_port_number = ($database_port >= 1 && $database_port <= 65535)
        ? $database_port
        : false;
} elseif (is_string($database_port) && ctype_digit($database_port)) {
    $database_port_number = filter_var($database_port, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);
}

$database_configuration_valid =
    is_string($database_host) && $database_host !== ''
    && is_string($database_name) && preg_match('/^[A-Za-z0-9_]+$/D', $database_name) === 1
    && is_string($database_user) && preg_match('/^[A-Za-z0-9_.-]+$/D', $database_user) === 1
    && strcasecmp($database_user, 'root') !== 0
    && is_string($database_password) && $database_password !== ''
    && $database_port_number !== false;

if ($database_runtime_mode === 'isolated_http_test') {
    $database_configuration_valid = $database_configuration_valid
        && $database_host === '127.0.0.1'
        && $database_port_number === 3307
        && $database_name === 'coreaxis_r5_runtime_http_test_20261014_a91d2b6c'
        && $database_user === 'coreaxis_r5_http_test_20261014';
} elseif ($database_runtime_mode === 'isolated_mysql8_dev') {
    $database_configuration_valid = $database_configuration_valid
        && $database_host === '127.0.0.1'
        && $database_port_number === 3306
        && $database_name === 'coreaxis_crm_dev_http_20261014_6b0f9a21'
        && $database_user === 'coreaxis_dev_http_20261014';
} elseif ($database_runtime_mode !== false && $database_runtime_mode !== '') {
    $database_configuration_valid = false;
}

if (!$database_configuration_valid) {
    http_response_code(503);
    exit('Database service is not configured.');
}

$conn = mysqli_connect(
    $database_host,
    $database_user,
    $database_password,
    $database_name,
    $database_port_number
);

$connection_ready = $conn && mysqli_set_charset($conn, 'utf8mb4');
$isolated_database_identity = null;
if ($database_runtime_mode === 'isolated_http_test') {
    $isolated_database_identity = [
        'database' => 'coreaxis_r5_runtime_http_test_20261014_a91d2b6c',
        'port' => '3307',
        'user' => 'coreaxis_r5_http_test_20261014',
    ];
} elseif ($database_runtime_mode === 'isolated_mysql8_dev') {
    $isolated_database_identity = [
        'database' => 'coreaxis_crm_dev_http_20261014_6b0f9a21',
        'port' => '3306',
        'user' => 'coreaxis_dev_http_20261014',
    ];
}
if ($connection_ready && $isolated_database_identity !== null) {
    $identity_result = mysqli_query($conn, 'SELECT DATABASE(), @@port, CURRENT_USER()');
    $identity_row = $identity_result ? mysqli_fetch_row($identity_result) : false;
    if ($identity_result instanceof mysqli_result) {
        mysqli_free_result($identity_result);
    }
    $connection_ready = is_array($identity_row)
        && ($identity_row[0] ?? null) === $isolated_database_identity['database']
        && (string) ($identity_row[1] ?? '') === $isolated_database_identity['port']
        && is_string($identity_row[2] ?? null)
        && strcasecmp(strtok($identity_row[2], '@'), $isolated_database_identity['user']) === 0;
}

if (!$connection_ready) {
    if ($conn instanceof mysqli) {
        mysqli_close($conn);
    }
    http_response_code(503);
    exit('Database service is unavailable.');
}

unset(
    $database_host,
    $database_port,
    $database_name,
    $database_user,
    $database_password,
    $database_configuration_path,
    $resolved_configuration_path,
    $resolved_document_root,
    $document_root_prefix,
    $configuration_inside_document_root,
    $loaded_database_configuration,
    $database_port_number,
    $database_runtime_mode,
    $database_configuration_valid,
    $connection_ready,
    $identity_result,
    $identity_row,
    $isolated_database_identity
);

?>
