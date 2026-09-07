<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = $db->query('SELECT id, username, phone, email, role, entity, created_at FROM users ORDER BY id');
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    echo json_encode($users);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'User ID required']);
        exit;
    }
    $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
