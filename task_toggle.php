<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = (int)($payload['id'] ?? 0);
$task = (bool)($payload['task'] ?? false);
$pdo = get_db();
$viewer = app_find_viewer($pdo, app_current_viewer_account_id());
if ($viewer === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'viewer_required'], JSON_UNESCAPED_UNICODE);
    exit;
}
$accountId = trim((string)($viewer['account_id'] ?? ''));

if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_id'], JSON_UNESCAPED_UNICODE);
    exit;
}
$messageStmt = $pdo->prepare('SELECT id FROM message WHERE id = :id LIMIT 1');
$messageStmt->execute([':id' => $id]);
if (!is_array($messageStmt->fetch(PDO::FETCH_ASSOC))) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($accountId !== '') {
    $stmt = $pdo->prepare(
        'UPDATE message_user_state
         SET is_done = :is_done, updated_at = CURRENT_TIMESTAMP
         WHERE message_id = :message_id AND account_id = :account_id'
    );
    $stmt->execute([
        ':message_id' => $id,
        ':account_id' => $accountId,
        ':is_done' => $task ? 1 : 0,
    ]);

    if ($stmt->rowCount() === 0) {
        $insertStmt = $pdo->prepare(
            'INSERT OR IGNORE INTO message_user_state (message_id, account_id, is_done, updated_at)
             VALUES (:message_id, :account_id, :is_done, CURRENT_TIMESTAMP)'
        );
        $insertStmt->execute([
            ':message_id' => $id,
            ':account_id' => $accountId,
            ':is_done' => $task ? 1 : 0,
        ]);
    }
} else {
    $stmt = $pdo->prepare('UPDATE message SET task = :task WHERE id = :id');
    $stmt->execute([
        ':task' => $task ? 1 : 0,
        ':id' => $id,
    ]);
}
echo json_encode([
    'ok' => true,
    'id' => $id,
    'account_id' => $accountId,
    'task' => $task,
], JSON_UNESCAPED_UNICODE);
