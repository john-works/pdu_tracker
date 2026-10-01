<?php
/**
 * Database connection. Production values should be provided by the server
 * environment; the defaults keep the local WAMP setup working.
 */
function databaseSetting(string $name, string $default): string
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

define('DB_HOST', databaseSetting('PDU_DB_HOST', 'localhost'));
define('DB_NAME', databaseSetting('PDU_DB_NAME', 'pdu'));
define('DB_USER', databaseSetting('PDU_DB_USER', 'root'));
define('DB_PASS', databaseSetting('PDU_DB_PASS', ''));
define('DB_PORT', (int) databaseSetting('PDU_DB_PORT', '3306'));
define('DB_CHARSET', 'utf8mb4');

function db(): mysqli
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
            $pdo->set_charset(DB_CHARSET);
        } catch (Throwable $error) {
            error_log('Database connection failed: ' . $error->getMessage());
            if (PHP_SAPI === 'cli') {
                throw new RuntimeException('Database connection failed; verify the PDU_DB settings.', 0, $error);
            }

            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Database connection failed. Check server database configuration.']);
            exit;
        }
    }
    return $pdo;
}
