<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = $db->query('SELECT * FROM statutory_rules ORDER BY procurement_type, method_code');
    $rules = [];
    while ($row = $result->fetch_assoc()) {
        $rules[] = $row;
    }
    echo json_encode($rules);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'GET only']);
