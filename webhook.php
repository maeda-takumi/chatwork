<?php
declare(strict_types=1);
require_once __DIR__ . '/gemini_message_classifier.php';
/**
 * ChatWork Webhook Receiver
 *
 * - Loads webhook settings from config.webhooks.php
 * - Validates incoming request by webhook signature/token
 * - Persists webhook payload into SQLite DB file (data/webhooks.sqlite)
 */

header('Content-Type: application/json; charset=UTF-8');

const CONFIG_FILE = __DIR__ . '/config.webhooks.php';
const DATA_DIR = __DIR__ . '/data';
const SQLITE_FILE = DATA_DIR . '/webhooks.sqlite';
const ERROR_LOG_FILE = __DIR__ . '/webhook_error.log';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respondError(405, 'method_not_allowed');
    }

    if (!is_file(CONFIG_FILE)) {
        respondError(500, 'config_not_found');
    }

    $config = require CONFIG_FILE;
    if (!is_array($config)) {
        respondError(500, 'invalid_config');
    }

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || $rawBody === '') {
        respondError(400, 'empty_body');
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        respondError(400, 'invalid_json');
    }

    $headers = getRequestHeadersLower();

    $webhookSettingId = findWebhookSettingId($payload);
    if ($webhookSettingId === null) {
        respondError(400, 'webhook_setting_id_not_found', ['payload' => $payload]);
    }

    $setting = findEnabledSettingById($config, $webhookSettingId);
    if ($setting === null) {
        respondError(403, 'webhook_setting_not_allowed', ['webhook_setting_id' => $webhookSettingId]);
    }

    $verified = verifyRequest($headers, $_GET, $rawBody, (string)$setting['token']);
    if (!$verified) {
        respondError(401, 'signature_or_token_mismatch', ['webhook_setting_id' => $webhookSettingId]);
    }

    ensureDataDirectoryExists();

    $pdo = new PDO('sqlite:' . SQLITE_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    createTableIfNotExists($pdo);

    $messageData = formatMessageData($payload, $setting);
    $typeName = classify_message_type_name((string)$messageData['body']);
    $typeId = resolveTypeIdByName($pdo, $typeName);
    $insertParams = [
        ':room_id' => $messageData['room_id'],
        ':account_id' => $messageData['account_id'],
        ':body' => $messageData['body'],
        ':send_time' => $messageData['send_time'],
        ':message_id' => $messageData['message_id'],
    ];

    if (hasColumn($pdo, 'message', 'type_id')) {
        $stmt = $pdo->prepare(
            'INSERT INTO message (
                room_id,
                account_id,
                body,
                send_time,
                message_id,
                type_id
            ) VALUES (
                :room_id,
                :account_id,
                :body,
                :send_time,
                :message_id,
                :type_id
            )'
        );
        $insertParams[':type_id'] = $typeId;
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO message (
                room_id,
                account_id,
                body,
                send_time,
                message_id
            ) VALUES (
                :room_id,
                :account_id,
                :body,
                :send_time,
                :message_id
            )'
        );
    }

    $stmt->execute($insertParams);

    respond(200, [
        'ok' => true,
        'saved_id' => (int)$pdo->lastInsertId(),
        'webhook_setting_id' => $webhookSettingId,
        'room_id' => $messageData['room_id'],
    ]);
} catch (Throwable $e) {
    logError('internal_server_error', ['exception' => $e->getMessage()]);
    respond(500, [
        'ok' => false,
        'error' => 'internal_server_error',
        'message' => $e->getMessage(),
    ]);
}

function createTableIfNotExists(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS message (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id INTEGER,
            account_id TEXT,
            body TEXT NOT NULL,
            send_time TEXT,
            task INTEGER NOT NULL DEFAULT 0,
            message_id TEXT,
            type_id INTEGER
        )'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS type (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type_name TEXT NOT NULL UNIQUE
        )'
    );
    $columns = $pdo->query('PRAGMA table_info(message)')->fetchAll(PDO::FETCH_ASSOC);
    $hasTaskColumn = false;
    $hasMessageIdColumn = false;
    $hasTypeIdColumn = false;
    foreach ($columns as $column) {
        $name = (string)($column['name'] ?? '');
        if ($name === 'task') {
            $hasTaskColumn = true;
        }
        if ($name === 'message_id') {
            $hasMessageIdColumn = true;
        }
        if ($name === 'type_id') {
            $hasTypeIdColumn = true;
        }
    }
    if (!$hasTaskColumn) {
        $pdo->exec('ALTER TABLE message ADD COLUMN task INTEGER NOT NULL DEFAULT 0');
    }
    if (!$hasMessageIdColumn) {
        $pdo->exec('ALTER TABLE message ADD COLUMN message_id TEXT');
    }
    if (!$hasTypeIdColumn) {
        $pdo->exec('ALTER TABLE message ADD COLUMN type_id INTEGER');
    }
    seedMessageTypes($pdo);


    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_message_room_id ON message (room_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_message_send_time ON message (send_time)');
}
function seedMessageTypes(PDO $pdo): void
{
    if (!tableExists($pdo, 'type')) {
        return;
    }

    $stmt = $pdo->prepare('INSERT OR IGNORE INTO type (type_name) VALUES (:type_name)');
    $typeNames = [
        '要対応',
        '要返信',
        '要確認',
        '報告',
        '共有',
        '完了報告',
        'お礼・リアクション',
        '雑談',
        '不明',
    ];
    foreach ($typeNames as $typeName) {
        $stmt->execute([':type_name' => $typeName]);
    }
}

function resolveTypeIdByName(PDO $pdo, string $typeName): ?int
{
    if (!tableExists($pdo, 'type')) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM type WHERE type_name = :type_name LIMIT 1');
    $stmt->execute([':type_name' => $typeName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || !isset($row['id'])) {
        return null;
    }

    return (int)$row['id'];
}

function hasColumn(PDO $pdo, string $tableName, string $columnName): bool
{
    $columns = $pdo->query('PRAGMA table_info(' . $tableName . ')')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        if ((string)($column['name'] ?? '') === $columnName) {
            return true;
        }
    }

    return false;
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table_name LIMIT 1"
    );
    $stmt->execute([':table_name' => $tableName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row);
}


function ensureDataDirectoryExists(): void
{
    if (is_dir(DATA_DIR)) {
        return;
    }

    if (!mkdir(DATA_DIR, 0775, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('failed_to_create_data_directory');
    }
}

function formatMessageData(array $payload, array $setting): array
{
    $event = $payload['webhook_event'] ?? [];
    if (!is_array($event)) {
        $event = [];
    }

    $roomId = findFirstInt([
        $event['room_id'] ?? null,
        $event['room']['room_id'] ?? null,
        $event['source']['room_id'] ?? null,
        $payload['room_id'] ?? null,
        $setting['room_id'] ?? null,
    ]);

    $accountId = findFirstString([
        $event['from_account_id'] ?? null,
        $event['from_account']['account_id'] ?? null,
        $event['account_id'] ?? null,
        $event['sender']['account_id'] ?? null,
        $payload['account_id'] ?? null,
    ]);

    $body = findFirstString([
        $event['body'] ?? null,
        $event['message'] ?? null,
        $payload['body'] ?? null,
        $payload['message'] ?? null,
    ]) ?? '';

    $sendTime = normalizeSendTime(findFirstString([
        $event['send_time'] ?? null,
        $event['message_time'] ?? null,
        $payload['send_time'] ?? null,
    ]));

    $messageId = findFirstString([
        $event['message_id'] ?? null,
        $event['message']['message_id'] ?? null,
        $payload['message_id'] ?? null,
    ]);
    return [
        'room_id' => $roomId,
        'account_id' => $accountId,
        'body' => $body,
        'send_time' => $sendTime,
        'message_id' => $messageId,
    ];
}

function findFirstInt(array $candidates): ?int
{
    foreach ($candidates as $candidate) {
        if (is_int($candidate)) {
            return $candidate;
        }

        if (is_string($candidate) && preg_match('/^\d+$/', $candidate) === 1) {
            return (int)$candidate;
        }
    }

    return null;
}

function findFirstString(array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (is_scalar($candidate) && trim((string)$candidate) !== '') {
            return (string)$candidate;
        }
    }

    return null;
}

function normalizeSendTime(?string $sendTime): ?string
{
    if ($sendTime === null || trim($sendTime) === '') {
        return gmdate('c');
    }

    $trimmed = trim($sendTime);
    if (preg_match('/^\d+$/', $trimmed) === 1) {
        return gmdate('c', (int)$trimmed);
    }

    $timestamp = strtotime($trimmed);
    if ($timestamp === false) {
        return gmdate('c');
    }

    return gmdate('c', $timestamp);
}

function verifyRequest(array $headers, array $queryParams, string $rawBody, string $token): bool
{
    $signatureCandidate = findSignatureCandidate($headers, $queryParams);
    if ($signatureCandidate !== null && $signatureCandidate !== '') {
        foreach (buildExpectedSignatures($rawBody, $token) as $expectedSignature) {
            if (hash_equals($expectedSignature, $signatureCandidate)) {
                return true;
            }
        }

        return false;
    }

    $tokenHeader = $headers['x-chatworktoken'] ?? ($headers['x-chatwork-webhook-token'] ?? null);
    if (is_string($tokenHeader) && $tokenHeader !== '') {
        return hash_equals($token, trim($tokenHeader));
    }

    return false;
}

function buildExpectedSignatures(string $rawBody, string $token): array
{
    $keys = [$token];
    $decodedToken = base64_decode($token, true);
    if (is_string($decodedToken) && $decodedToken !== '') {
        $keys[] = $decodedToken;
    }

    $signatures = [];
    foreach ($keys as $key) {
        $signatures[] = base64_encode(hash_hmac('sha256', $rawBody, $key, true));
    }

    return array_values(array_unique($signatures));
}

function findSignatureCandidate(array $headers, array $queryParams): ?string
{
    $signatureCandidates = [
        $headers['x-chatworkwebhooksignature'] ?? null,
        $queryParams['chatwork_webhook_signature'] ?? null,
    ];

    foreach ($signatureCandidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }

        // Query parameter signatures may include spaces when '+' is not URL-encoded.
        return str_replace(' ', '+', trim($candidate));
    }

    return null;
}
function findEnabledSettingById(array $config, string $webhookSettingId): ?array
{
    foreach ($config as $setting) {
        if (!is_array($setting)) {
            continue;
        }

        $enabled = (bool)($setting['enabled'] ?? false);
        if (!$enabled) {
            continue;
        }

        if ((string)($setting['webhook_setting_id'] ?? '') === $webhookSettingId) {
            return $setting;
        }
    }

    return null;
}

function findWebhookSettingId(array $payload): ?string
{
    $candidates = [
        $payload['webhook_setting_id'] ?? null,
        $payload['webhook_event']['webhook_setting_id'] ?? null,
        $payload['webhook_event']['source']['webhook_setting_id'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_scalar($candidate) && (string)$candidate !== '') {
            return (string)$candidate;
        }
    }

    return null;
}

function findEventType(array $payload): string
{
    $candidates = [
        $payload['webhook_event_type'] ?? null,
        $payload['webhook_event']['webhook_event_type'] ?? null,
        $payload['webhook_event']['event_type'] ?? null,
        $payload['event_type'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_scalar($candidate) && (string)$candidate !== '') {
            return (string)$candidate;
        }
    }

    return 'unknown';
}

function getRequestHeadersLower(): array
{
    $headers = [];

    if (function_exists('getallheaders')) {
        $all = getallheaders();
        if (is_array($all)) {
            foreach ($all as $k => $v) {
                $headers[strtolower((string)$k)] = is_scalar($v) ? (string)$v : json_encode($v);
            }
            return $headers;
        }
    }

    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') !== 0) {
            continue;
        }

        $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
        $headers[$headerName] = is_scalar($value) ? (string)$value : '';
    }

    return $headers;
}

function respond(int $statusCode, array $body): void
{
    http_response_code($statusCode);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function respondError(int $statusCode, string $errorCode, array $context = []): void
{
    logError($errorCode, $context);
    respond($statusCode, ['ok' => false, 'error' => $errorCode]);
}

function logError(string $errorCode, array $context = []): void
{
    $record = [
        'timestamp' => gmdate('c'),
        'error' => $errorCode,
        'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'request_method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        'context' => $context,
    ];

    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) {
        $line = '{"timestamp":"' . gmdate('c') . '","error":"json_encode_failed"}';
    }

    error_log($line . PHP_EOL, 3, ERROR_LOG_FILE);
}
