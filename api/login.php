<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);
$phone = $input['phone'] ?? '';
$pass  = $input['password'] ?? '';

if (!$phone || !$pass) {
    http_response_code(400);
    echo json_encode(['error' => 'Phone number and password required']);
    exit;
}

$db = db();
$stmt = $db->prepare('SELECT id, username, phone, email, role, password, entity FROM users WHERE phone = ?');
$stmt->bind_param('s', $phone);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user || !password_verify($pass, $user['password'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid phone number or password']);
    exit;
}

unset($user['password']);
echo json_encode(['success' => true, 'user' => $user]);
