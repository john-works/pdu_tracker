<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);

if (($input['action'] ?? '') === 'logout') {
    $_SESSION = [];
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

$email = strtolower(trim($input['email'] ?? ''));
$pass  = $input['password'] ?? '';

if (!$email || !$pass) {
    http_response_code(400);
    echo json_encode(['error' => 'Email address and password required']);
    exit;
}

$db = db();
$stmt = $db->prepare('SELECT id, username, display_name, phone, email, role, password, entity FROM users WHERE LOWER(email) = ? LIMIT 2');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->num_rows === 1 ? $result->fetch_assoc() : null;

if (!$user || !password_verify($pass, $user['password'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid email address or password']);
    exit;
}

session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['id'];
unset($user['password']);
echo json_encode(['success' => true, 'user' => $user]);
