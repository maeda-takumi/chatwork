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
$task = (bool)($payload['task'] ?? false);

if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = get_db();
$stmt = $pdo->prepare('UPDATE message SET task = :task WHERE id = :id');
$stmt->execute([
    ':task' => $task ? 1 : 0,
    ':id' => $id,
]);

if ($stmt->rowCount() === 0) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'id' => $id,
    'task' => $task,
], JSON_UNESCAPED_UNICODE);
