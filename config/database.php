<?php
/**
 * Database Connection — WAMP / MySQL (MariaDB)
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'pdu');
define('DB_USER', 'root');       // WAMP default
define('DB_PASS', '');           // WAMP default (no password)
define('DB_CHARSET', 'utf8mb4');

function db(): mysqli
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($pdo->connect_error) {
            die('Database connection failed: ' . $pdo->connect_error);
        }
        $pdo->set_charset(DB_CHARSET);
    }
    return $pdo;
}
