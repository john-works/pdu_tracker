<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);
$username = $input['username'] ?? '';
$phone    = $input['phone'] ?? '';
$pass     = $input['password'] ?? '';
$entity   = $input['entity'] ?? '';
$email    = $input['email'] ?? '';
$role     = $input['role'] ?? $entity; // fallback to entity value if role not provided

if (!$username || !$phone || !$pass || !$entity) {
    http_response_code(400);
    echo json_encode(['error' => 'All fields are required']);
    exit;
}

if (!in_array($role, ['admin', 'user'], true)) {
    $role = $entity;
}
if (!in_array($role, ['admin', 'user'], true)) {
    $role = 'user';
}

$db = db();
$hash = password_hash($pass, PASSWORD_DEFAULT);

$stmt = $db->prepare('INSERT INTO users (username, phone, email, role, password, entity) VALUES (?, ?, ?, ?, ?, ?)');
$stmt->bind_param('ssssss', $username, $phone, $email, $role, $hash, $entity);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'id' => $db->insert_id]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create user: ' . $db->error]);
}
