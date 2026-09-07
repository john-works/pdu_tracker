<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

$db = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $result = $db->query('SELECT p.*, u.username AS initiator_name FROM procurements p LEFT JOIN users u ON p.created_by = u.id ORDER BY p.id DESC');
    $procs = [];
    while ($row = $result->fetch_assoc()) {
        $row['stages'] = [];
        $stmt = $db->prepare('SELECT * FROM procurement_stages WHERE procurement_id = ? ORDER BY stage_order');
        $stmt->bind_param('i', $row['id']);
        $stmt->execute();
        $stages = $stmt->get_result();
        while ($s = $stages->fetch_assoc()) {
            $row['stages'][] = $s;
        }
        $procs[] = $row;
    }
    echo json_encode($procs);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $createdBy = $input['created_by'] ?? null;

    $stmt = $db->prepare('INSERT INTO procurements (ref_no, type, entity, title, method, start_date, beb_date, completion_date, current_stage_index, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)');
    $stmt->bind_param('ssssssssi',
        $input['ref_no'], $input['type'], $input['entity'], $input['title'],
        $input['method'], $input['start_date'], $input['beb_date'],
        $input['completion_date'], $createdBy
    );

    if ($stmt->execute()) {
        $procId = $db->insert_id;
        $stages = $input['stages'] ?? [];
        $order = 0;
        foreach ($stages as $s) {
            $comment = $s['comment'] ?? null;
            $stmt2 = $db->prepare('INSERT INTO procurement_stages (procurement_id, stage_name, stage_order, target_days, responsible_party, target_date, comment) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt2->bind_param('isissss', $procId, $s['name'], $order, $s['target_days'], $s['responsible_party'], $s['target_date'], $comment);
            $stmt2->execute();
            $order++;
        }
        echo json_encode(['success' => true, 'id' => $procId]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed: ' . $db->error]);
    }
    exit;
}

if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    // Standalone comment save (no stage advance)
    if (isset($input['save_comment']) && $input['save_comment'] && isset($input['stage_comment']) && isset($input['stage_index'])) {
        $comment = $input['stage_comment'];
        $stageIdx = intval($input['stage_index']);
        $upd = $db->prepare('UPDATE procurement_stages SET comment = ? WHERE procurement_id = ? AND stage_order = ?');
        $upd->bind_param('sii', $comment, $id, $stageIdx);
        $upd->execute();
        echo json_encode(['success' => true]);
        exit;
    }

    // Advance stage only
    if (array_key_exists('current_stage_index', $input) && $input['current_stage_index'] !== null) {
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
                $to = 'jssekamatte@ppda.go.ug';
                $subject = 'Procurement Successfully Completed - ' . $info['ref_no'];
                $body = "Dear User,\n\n"
                      . "This is to confirm that the procurement process has been successfully completed.\n\n"
                      . "Reference No.: " . $info['ref_no'] . "\n"
                      . "Procurement: " . $info['title'] . "\n"
                      . "Entity: " . $info['entity'] . "\n"
                      . "Completion Date: " . ($info['completion_date'] ?: date('Y-m-d')) . "\n\n"
                      . "Thank you.\n";
                $headers = "From: PPDA & JJK Procurement System <no-reply@ppda.go.ug>\r\n"
                         . "Content-Type: text/plain; charset=utf-8\r\n";
                @mail($to, $subject, $body, $headers);
            }
        }

        echo json_encode(['success' => true]);
        exit;
    }

    // Full edit
    $refNo = $input['ref_no'] ?? '';
    $type = $input['type'] ?? '';
    $entity = $input['entity'] ?? '';
    $title = $input['title'] ?? '';
    $method2 = $input['method'] ?? '';
    $startDate = $input['start_date'] ?? '';
    $bebDate = $input['beb_date'] ?? '';
    $completionDate = $input['completion_date'] ?? '';
    $stages = $input['stages'] ?? [];

    $stmt = $db->prepare('UPDATE procurements SET ref_no=?, type=?, entity=?, title=?, method=?, start_date=?, beb_date=?, completion_date=? WHERE id=?');
    $stmt->bind_param('ssssssssi', $refNo, $type, $entity, $title, $method2, $startDate, $bebDate, $completionDate, $id);
    $stmt->execute();

    // Replace stages
    $del = $db->prepare('DELETE FROM procurement_stages WHERE procurement_id = ?');
    $del->bind_param('i', $id);
    $del->execute();

    $order = 0;
    foreach ($stages as $s) {
        $comment = $s['comment'] ?? null;
        $stmt2 = $db->prepare('INSERT INTO procurement_stages (procurement_id, stage_name, stage_order, target_days, responsible_party, target_date, comment) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt2->bind_param('isissss', $id, $s['name'], $order, $s['target_days'], $s['responsible_party'], $s['target_date'], $comment);
        $stmt2->execute();
        $order++;
    }

    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid id']);
        exit;
    }

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
