<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

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
$flagged = (bool)($payload['flagged'] ?? false);
$accountId = trim((string)($payload['account_id'] ?? ''));

if ($id <= 0 || $accountId === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_payload'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = get_db();
$messageStmt = $pdo->prepare('SELECT id FROM message WHERE id = :id LIMIT 1');
$messageStmt->execute([':id' => $id]);
if (!is_array($messageStmt->fetch(PDO::FETCH_ASSOC))) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare(
    'UPDATE message_user_state
     SET is_flagged = :is_flagged, updated_at = CURRENT_TIMESTAMP
     WHERE message_id = :message_id AND account_id = :account_id'
);
$stmt->execute([
    ':message_id' => $id,
    ':account_id' => $accountId,
    ':is_flagged' => $flagged ? 1 : 0,
]);

if ($stmt->rowCount() === 0) {
    $insertStmt = $pdo->prepare(
        'INSERT OR IGNORE INTO message_user_state (message_id, account_id, is_flagged, updated_at)
         VALUES (:message_id, :account_id, :is_flagged, CURRENT_TIMESTAMP)'
    );
    $insertStmt->execute([
        ':message_id' => $id,
        ':account_id' => $accountId,
        ':is_flagged' => $flagged ? 1 : 0,
    ]);
}

echo json_encode([
    'ok' => true,
    'id' => $id,
    'account_id' => $accountId,
    'flagged' => $flagged,
], JSON_UNESCAPED_UNICODE);
