<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

$db = db();

function requireAdminUser(mysqli $db): array
{
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['error' => 'Please sign in again']);
        exit;
    }

    $stmt = $db->prepare('SELECT id, username, display_name, phone, role, entity FROM users WHERE id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $actor = $stmt->get_result()->fetch_assoc();
    if (!$actor || $actor['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Only admin users can edit accounts']);
        exit;
    }
    return $actor;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['entity']) && $_GET['entity'] !== '') {
        $entity = trim($_GET['entity']);
        $stmt = $db->prepare("SELECT id, username, display_name, phone, email, role, entity, created_at FROM users WHERE LOWER(entity) LIKE CONCAT(LOWER(?), '%') ORDER BY id");
        $stmt->bind_param('s', $entity);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $db->query('SELECT id, username, display_name, phone, email, role, entity, created_at FROM users ORDER BY id');
    }
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

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $actor = requireAdminUser($db);
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = (int) ($input['id'] ?? 0);
    $displayName = trim($input['display_name'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $email = trim($input['email'] ?? '');
    $entity = trim($input['entity'] ?? '');
    $role = $input['role'] ?? '';
    $password = $input['password'] ?? '';

    if ($id <= 0 || $displayName === '' || $phone === '' || $entity === '' || !in_array($role, ['admin', 'user'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Name, phone, entity, and a valid role are required']);
        exit;
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Enter a valid email address']);
        exit;
    }

    $existingStmt = $db->prepare('SELECT username, phone FROM users WHERE id = ?');
    $existingStmt->bind_param('i', $id);
    $existingStmt->execute();
    $existing = $existingStmt->get_result()->fetch_assoc();
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }

    $username = $existing['username'] === $existing['phone'] ? $phone : $existing['username'];
    if ($password !== '') {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE users SET username = ?, display_name = ?, phone = ?, email = ?, entity = ?, role = ?, password = ? WHERE id = ?');
        $stmt->bind_param('sssssssi', $username, $displayName, $phone, $email, $entity, $role, $passwordHash, $id);
    } else {
        $stmt = $db->prepare('UPDATE users SET username = ?, display_name = ?, phone = ?, email = ?, entity = ?, role = ? WHERE id = ?');
        $stmt->bind_param('ssssssi', $username, $displayName, $phone, $email, $entity, $role, $id);
    }
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $error) {
        if ((int) $error->getCode() === 1062) {
            http_response_code(409);
            echo json_encode(['error' => 'That username or phone number is already in use']);
        } else {
            error_log('User update failed: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'The user account could not be updated']);
        }
        exit;
    }

    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $id,
            'username' => $username,
            'display_name' => $displayName,
            'phone' => $phone,
            'email' => $email,
            'role' => $role,
            'entity' => $entity
        ],
        'actor_id' => (int) $actor['id']
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
