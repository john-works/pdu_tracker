<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

$db = db();
$method = $_SERVER['REQUEST_METHOD'];

function ensureProcurementAuditTable(mysqli $db): void
{
    $db->query("CREATE TABLE IF NOT EXISTS procurement_audit_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        procurement_id INT UNSIGNED NOT NULL,
        procurement_ref_no VARCHAR(50) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        username VARCHAR(50) NOT NULL,
        action VARCHAR(32) NOT NULL,
        details LONGTEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_audit_procurement (procurement_id, id),
        KEY idx_audit_user (user_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function requireProcurementUser(mysqli $db): array
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
    $user = $stmt->get_result()->fetch_assoc();
    $entity = strtolower($user['entity'] ?? '');
    if (!$user || (strpos($entity, 'pdu') !== 0 && strpos($entity, 'depoint') !== 0)) {
        http_response_code(403);
        echo json_encode(['error' => 'PDU or Depoint account required']);
        exit;
    }
    return $user;
}

function procurementSnapshot(mysqli $db, int $procurementId): ?array
{
    $stmt = $db->prepare(
        'SELECT p.id, p.ref_no, p.entity, p.title, p.start_date, p.beb_date, p.completion_date,
            p.current_stage_index, p.created_by, p.agent_id, t.name AS type, t.code AS type_code, m.code AS method,
            CASE
                WHEN NULLIF(agent_user.display_name, \'\') IS NOT NULL THEN agent_user.display_name
                WHEN agent_user.username IS NOT NULL AND agent_user.username <> agent_user.phone THEN agent_user.username
                WHEN agent_user.id IS NOT NULL THEN \'Name not set\'
                ELSE NULL
            END AS assigned_to
           FROM procurements p
           LEFT JOIN procurement_types   t ON t.id = p.procurement_type_id
           LEFT JOIN procurement_methods m ON m.id = p.procurement_method_id
           LEFT JOIN users agent_user ON agent_user.id = p.agent_id
          WHERE p.id = ?'
    );
    $stmt->bind_param('i', $procurementId);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    if (!$record) {
        return null;
    }

    $record['stages'] = procurementStages($db, $procurementId);
    return $record;
}

function procurementStages(mysqli $db, int $procurementId): array
{
    $stageStmt = $db->prepare(
        'SELECT s.stage_order, s.target_days, s.target_date, s.completed_at, s.comment,
                cat.code AS stage_code, cat.name AS stage_name, cat.date_role,
                rp.name AS responsible_party
           FROM procurement_stages s
           JOIN process_stages cat ON cat.id = s.process_stage_id
           LEFT JOIN responsible_parties rp ON rp.id = s.responsible_party_id
          WHERE s.procurement_id = ?
          ORDER BY s.stage_order'
    );
    $stageStmt->bind_param('i', $procurementId);
    $stageStmt->execute();
    return $stageStmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// The stage rows arrive from the client with a catalogue code rather than a
// free-text name, so resolve the code to an id and reject anything unknown
// rather than letting it fail later as a foreign key error.
function resolveStageId(mysqli $db, string $code): ?int
{
    if ($code === '') {
        return null;
    }
    $stmt = $db->prepare('SELECT id FROM process_stages WHERE code = ? LIMIT 1');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int) $row['id'] : null;
}

function resolvePartyId(mysqli $db, ?string $name): ?int
{
    $name = trim((string) $name);
    if ($name === '') {
        return null;
    }
    $stmt = $db->prepare('SELECT id FROM responsible_parties WHERE name = ? LIMIT 1');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int) $row['id'] : null;
}

function resolveTypeId(mysqli $db, string $nameOrCode): ?int
{
    $value = trim($nameOrCode);
    if ($value === '') {
        return null;
    }
    $stmt = $db->prepare('SELECT id FROM procurement_types WHERE code = ? OR name = ? LIMIT 1');
    $stmt->bind_param('ss', $value, $value);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int) $row['id'] : null;
}

function resolveMethodId(mysqli $db, string $code): ?int
{
    $value = trim($code);
    if ($value === '') {
        return null;
    }
    $stmt = $db->prepare('SELECT id FROM procurement_methods WHERE code = ? OR name = ? LIMIT 1');
    $stmt->bind_param('ss', $value, $value);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int) $row['id'] : null;
}

// Shared by create and edit: the client sends stage_code, not stage_name.
function insertProcurementStages(mysqli $db, int $procurementId, array $stages): void
{
    $stmt = $db->prepare(
        'INSERT INTO procurement_stages
            (procurement_id, process_stage_id, stage_order, target_days, responsible_party_id, target_date, comment)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $order = 0;
    foreach ($stages as $s) {
        $stageId = resolveStageId($db, (string) ($s['stage_code'] ?? ''));
        if ($stageId === null) {
            throw new RuntimeException('Unknown process stage: ' . ($s['stage_code'] ?? '(none)'));
        }
        $days  = (int) ($s['target_days'] ?? 0);
        $party = resolvePartyId($db, $s['responsible_party'] ?? null);
        $date  = $s['target_date'] ?? null;
        $note  = $s['comment'] ?? null;
        if ($date === '') {
            $date = null;
        }
        // procurement_id, process_stage_id, stage_order, target_days, party, date, comment
        $stmt->bind_param('iiiisss', $procurementId, $stageId, $order, $days, $party, $date, $note);
        $stmt->execute();
        $order++;
    }
}

function recordProcurementAudit(mysqli $db, array $actor, int $procurementId, string $referenceNo, string $action, array $details): void
{
    ensureProcurementAuditTable($db);
    $actorName = trim($actor['display_name'] ?? '');
    if ($actorName === '') {
        $actorName = $actor['username'] !== $actor['phone'] ? $actor['username'] : 'Name not set';
    }
    $encodedDetails = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $stmt = $db->prepare('INSERT INTO procurement_audit_log (procurement_id, procurement_ref_no, user_id, username, action, details) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('isisss', $procurementId, $referenceNo, $actor['id'], $actorName, $action, $encodedDetails);
    $stmt->execute();
}

if ($method === 'GET') {
    if (($_GET['run_reminders'] ?? '') === '1') {
        requireProcurementUser($db);
        session_write_close();
        require_once __DIR__ . '/../config/deadline_reminder_service.php';
        try {
            echo json_encode(['success' => true, 'stats' => runDeadlineReminders($db)]);
        } catch (Throwable $error) {
            error_log('Deadline reminder check failed: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Deadline reminders could not be processed']);
        }
        exit;
    }

    if (isset($_GET['history'])) {
        requireProcurementUser($db);
        ensureProcurementAuditTable($db);
        $procurementId = (int) $_GET['history'];
        $stmt = $db->prepare("SELECT a.id, a.procurement_ref_no,
                CASE
                    WHEN NULLIF(u.display_name, '') IS NOT NULL THEN u.display_name
                    WHEN u.username IS NOT NULL AND u.username <> u.phone THEN u.username
                    WHEN u.username IS NULL AND a.username NOT REGEXP '^[+0-9 ()-]+$' THEN a.username
                    ELSE 'Name not set'
                END AS username,
                a.action, a.details, a.created_at
            FROM procurement_audit_log a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.procurement_id = ? ORDER BY a.id DESC LIMIT 500");
        $stmt->bind_param('i', $procurementId);
        $stmt->execute();
        $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($history as &$entry) {
            $entry['details'] = json_decode($entry['details'], true) ?? [];
        }
        unset($entry);
        echo json_encode($history);
        exit;
    }

    $result = $db->query(
        'SELECT p.*, u.username AS initiator_name, t.name AS type, t.code AS type_code, m.code AS method,
                CASE
                    WHEN NULLIF(agent_user.display_name, \'\') IS NOT NULL THEN agent_user.display_name
                    WHEN agent_user.username IS NOT NULL AND agent_user.username <> agent_user.phone THEN agent_user.username
                    WHEN agent_user.id IS NOT NULL THEN \'Name not set\'
                    ELSE NULL
                END AS assigned_to
           FROM procurements p
           LEFT JOIN users u ON p.created_by = u.id
           LEFT JOIN procurement_types   t ON t.id = p.procurement_type_id
           LEFT JOIN procurement_methods m ON m.id = p.procurement_method_id
           LEFT JOIN users agent_user ON agent_user.id = p.agent_id
          ORDER BY p.id DESC'
    );
    $procs = [];
    while ($row = $result->fetch_assoc()) {
        $row['stages'] = procurementStages($db, (int) $row['id']);
        $procs[] = $row;
    }
    echo json_encode($procs);
    exit;
}

if ($method === 'POST') {
    $actor = requireProcurementUser($db);
    if ($actor['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Only admin users can create procurement records']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    $createdBy = (int) $actor['id'];
    $refNo = trim($input['ref_no'] ?? '');
    $agentId = intval($input['agent_id'] ?? 0);

    $refCheck = $db->prepare('SELECT id FROM procurements WHERE ref_no = ? LIMIT 1');
    $refCheck->bind_param('s', $refNo);
    $refCheck->execute();
    if ($refCheck->get_result()->num_rows > 0) {
        http_response_code(409);
        echo json_encode(['error' => 'That procurement reference already exists. Use a unique reference number.']);
        exit;
    }

    if ($agentId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'A Depoint agent is required']);
        exit;
    }

    $agentStmt = $db->prepare("SELECT entity FROM users WHERE id = ? AND LOWER(entity) LIKE 'depoint%'");
    $agentStmt->bind_param('i', $agentId);
    $agentStmt->execute();
    $agent = $agentStmt->get_result()->fetch_assoc();
    if (!$agent) {
        http_response_code(400);
        echo json_encode(['error' => 'Selected agent is not a Depoint user']);
        exit;
    }
    $entity = $agent['entity'];

    $typeId = resolveTypeId($db, (string) ($input['type'] ?? ''));
    $methodId = resolveMethodId($db, (string) ($input['method'] ?? ''));
    if ($typeId === null || $methodId === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Select a valid procurement type and method']);
        exit;
    }

    $stmt = $db->prepare('INSERT INTO procurements (ref_no, procurement_type_id, entity, title, procurement_method_id, start_date, beb_date, completion_date, current_stage_index, created_by, agent_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)');
    $title = (string) ($input['title'] ?? '');
    $startDate = (string) ($input['start_date'] ?? '');
    $bebDate = (string) ($input['beb_date'] ?? '');
    $completionDate = (string) ($input['completion_date'] ?? '');
    $stmt->bind_param('sississsii',
        $refNo, $typeId, $entity, $title,
        $methodId, $startDate, $bebDate, $completionDate, $createdBy, $agentId
    );

    try {
        $inserted = $stmt->execute();
    } catch (mysqli_sql_exception $error) {
        if ((int) $error->getCode() === 1062) {
            http_response_code(409);
            echo json_encode(['error' => 'That procurement reference already exists. Use a unique reference number.']);
        } else {
            error_log('Procurement insert failed: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'The procurement could not be saved. Please try again.']);
        }
        exit;
    }

    if ($inserted) {
        $procId = $db->insert_id;
        try {
            insertProcurementStages($db, $procId, $input['stages'] ?? []);
        } catch (Throwable $error) {
            // Do not leave a procurement with no stages behind.
            $rollback = $db->prepare('DELETE FROM procurements WHERE id = ?');
            $rollback->bind_param('i', $procId);
            $rollback->execute();
            error_log('Procurement stage insert failed: ' . $error->getMessage());
            http_response_code(400);
            echo json_encode(['error' => $error->getMessage()]);
            exit;
        }
        $snapshot = procurementSnapshot($db, $procId);
        recordProcurementAudit($db, $actor, $procId, $refNo, 'created', ['after' => $snapshot]);
        echo json_encode(['success' => true, 'id' => $procId]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed: ' . $db->error]);
    }
    exit;
}

if ($method === 'PUT') {
    $actor = requireProcurementUser($db);
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    // Standalone comment save (no stage advance)
    if (isset($input['save_comment']) && $input['save_comment'] && isset($input['stage_comment']) && isset($input['stage_index'])) {
        $before = procurementSnapshot($db, $id);
        if (!$before) {
            http_response_code(404);
            echo json_encode(['error' => 'Procurement not found']);
            exit;
        }
        $comment = $input['stage_comment'];
        $stageIdx = intval($input['stage_index']);
        $upd = $db->prepare('UPDATE procurement_stages SET comment = ? WHERE procurement_id = ? AND stage_order = ?');
        $upd->bind_param('sii', $comment, $id, $stageIdx);
        $upd->execute();
        $after = procurementSnapshot($db, $id);
        recordProcurementAudit($db, $actor, $id, $before['ref_no'], 'comment_updated', ['before' => $before, 'after' => $after]);
        echo json_encode(['success' => true]);
        exit;
    }

    // Advance stage only
    if (array_key_exists('current_stage_index', $input) && $input['current_stage_index'] !== null) {
        $before = procurementSnapshot($db, $id);
        if (!$before) {
            http_response_code(404);
            echo json_encode(['error' => 'Procurement not found']);
            exit;
        }
        $newStage = intval($input['current_stage_index']);
        $stmt = $db->prepare('UPDATE procurements SET current_stage_index = ? WHERE id = ?');
        $stmt->bind_param('ii', $newStage, $id);
        $stmt->execute();

        // Record completion of the stage being advanced (the previously active stage)
        if (isset($input['stage_index'])) {
            $stageIdx = intval($input['stage_index']);
            $comment = $input['stage_comment'] ?? null;
            if ($comment !== null) {
                $upd = $db->prepare('UPDATE procurement_stages SET comment = ?, completed_at = CURDATE() WHERE procurement_id = ? AND stage_order = ?');
                $upd->bind_param('sii', $comment, $id, $stageIdx);
            } else {
                $upd = $db->prepare('UPDATE procurement_stages SET completed_at = CURDATE() WHERE procurement_id = ? AND stage_order = ?');
                $upd->bind_param('ii', $id, $stageIdx);
            }
            $upd->execute();
        }

        $after = procurementSnapshot($db, $id);
        recordProcurementAudit($db, $actor, $id, $before['ref_no'], 'stage_advanced', ['before' => $before, 'after' => $after]);

        // Notify on successful completion of the final stage
        $stageCount = 0;
        $res = $db->query('SELECT COUNT(*) AS c FROM procurement_stages WHERE procurement_id = ' . $id);
        if ($res) {
            $row = $res->fetch_assoc();
            $stageCount = intval($row['c']);
        }
        if ($stageCount > 0 && $newStage >= $stageCount - 1) {
            $p = $db->query('SELECT ref_no, title, entity, completion_date FROM procurements WHERE id = ' . $id);
            if ($p && $info = $p->fetch_assoc()) {
                $emailConfig = require __DIR__ . '/../config/email.php';
                $fromName = str_replace(["\r", "\n"], '', $emailConfig['from_name'] ?? '');
                $fromAddress = trim($emailConfig['from_address'] ?? '');
                $replyTo = trim($emailConfig['reply_to'] ?? $fromAddress);
                $to = 'jssekamatte@PDU.go.ug';
                $subject = 'Procurement Successfully Completed - ' . $info['ref_no'];
                $body = "Dear User,\n\n"
                      . "This is to confirm that the procurement process has been successfully completed.\n\n"
                      . "Reference No.: " . $info['ref_no'] . "\n"
                      . "Procurement: " . $info['title'] . "\n"
                      . "Entity: " . $info['entity'] . "\n"
                      . "Completion Date: " . ($info['completion_date'] ?: date('Y-m-d')) . "\n\n"
                      . "Thank you.\n";
                if ($fromName !== '' && filter_var($fromAddress, FILTER_VALIDATE_EMAIL) && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                    $headers = 'From: ' . $fromName . ' <' . $fromAddress . ">\r\n"
                             . 'Reply-To: ' . $replyTo . "\r\n"
                             . "Content-Type: text/plain; charset=utf-8\r\n";
                    @mail($to, $subject, $body, $headers);
                }
            }
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // Full edit
    $before = procurementSnapshot($db, $id);
    if (!$before) {
        http_response_code(404);
        echo json_encode(['error' => 'Procurement not found']);
        exit;
    }
    $refNo = $input['ref_no'] ?? '';
    $type = $input['type'] ?? '';
    $entity = $input['entity'] ?? '';
    $title = $input['title'] ?? '';
    $method2 = $input['method'] ?? '';
    $startDate = $input['start_date'] ?? '';
    $bebDate = $input['beb_date'] ?? '';
    $completionDate = $input['completion_date'] ?? '';
    $stages = $input['stages'] ?? [];

    $editTypeId = resolveTypeId($db, (string) $type);
    $editMethodId = resolveMethodId($db, (string) $method2);
    if ($editTypeId === null || $editMethodId === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Select a valid procurement type and method']);
        exit;
    }

    // The stages are replaced wholesale, so validate every stage code before
    // deleting anything: otherwise a bad code would wipe the existing stages
    // and then fail to insert the replacements.
    $stages = $input['stages'] ?? [];
    foreach ($stages as $s) {
        if (resolveStageId($db, (string) ($s['stage_code'] ?? '')) === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Unknown process stage: ' . ($s['stage_code'] ?? '(none)')]);
            exit;
        }
    }

    $stmt = $db->prepare('UPDATE procurements SET ref_no=?, procurement_type_id=?, entity=?, title=?, procurement_method_id=?, start_date=?, beb_date=?, completion_date=? WHERE id=?');
    $stmt->bind_param('sississsi', $refNo, $editTypeId, $entity, $title, $editMethodId, $startDate, $bebDate, $completionDate, $id);
    $stmt->execute();

    // Replace stages
    $del = $db->prepare('DELETE FROM procurement_stages WHERE procurement_id = ?');
    $del->bind_param('i', $id);
    $del->execute();

    try {
        insertProcurementStages($db, $id, $stages);
    } catch (Throwable $error) {
        error_log('Procurement stage replace failed: ' . $error->getMessage());
        http_response_code(400);
        echo json_encode(['error' => $error->getMessage()]);
        exit;
    }

    $after = procurementSnapshot($db, $id);
    recordProcurementAudit($db, $actor, $id, $refNo, 'edited', ['before' => $before, 'after' => $after]);

    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    $actor = requireProcurementUser($db);
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid id']);
        exit;
    }

    $before = procurementSnapshot($db, $id);
    if (!$before) {
        http_response_code(404);
        echo json_encode(['error' => 'Procurement not found']);
        exit;
    }
    if ((int) $before['created_by'] !== (int) $actor['id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Only the record initiator can delete this procurement']);
        exit;
    }
    recordProcurementAudit($db, $actor, $id, $before['ref_no'], 'deleted', ['before' => $before]);

    // Delete child stages first, then the procurement (respects FK)
    $delStages = $db->prepare('DELETE FROM procurement_stages WHERE procurement_id = ?');
    $delStages->bind_param('i', $id);
    $delStages->execute();

    $delProc = $db->prepare('DELETE FROM procurements WHERE id = ?');
    $delProc->bind_param('i', $id);
    $delProc->execute();

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
