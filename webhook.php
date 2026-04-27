<?php
declare(strict_types=1);

/**
 * ChatWork Webhook Receiver
 *
 * - Loads webhook settings from config.webhooks.php
 * - Validates incoming request by webhook signature/token
 * - Persists webhook payload into SQLite DB file (webhooks.sqlite)
 */

header('Content-Type: application/json; charset=UTF-8');

const CONFIG_FILE = __DIR__ . '/config.webhooks.php';
const SQLITE_FILE = __DIR__ . '/webhooks.sqlite';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }

    if (!is_file(CONFIG_FILE)) {
        respond(500, ['ok' => false, 'error' => 'config_not_found']);
    }

    $config = require CONFIG_FILE;
    if (!is_array($config)) {
        respond(500, ['ok' => false, 'error' => 'invalid_config']);
    }

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || $rawBody === '') {
        respond(400, ['ok' => false, 'error' => 'empty_body']);
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        respond(400, ['ok' => false, 'error' => 'invalid_json']);
    }

    $headers = getRequestHeadersLower();

    $webhookSettingId = findWebhookSettingId($payload);
    if ($webhookSettingId === null) {
        respond(400, ['ok' => false, 'error' => 'webhook_setting_id_not_found']);
    }

    $setting = findEnabledSettingById($config, $webhookSettingId);
    if ($setting === null) {
        respond(403, ['ok' => false, 'error' => 'webhook_setting_not_allowed']);
    }

    $verified = verifyRequest($headers, $rawBody, (string)$setting['token']);
    if (!$verified) {
        respond(401, ['ok' => false, 'error' => 'signature_or_token_mismatch']);
    }

    $pdo = new PDO('sqlite:' . SQLITE_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    createTableIfNotExists($pdo);

    $eventType = findEventType($payload);
    $roomId = (string)($setting['room_id'] ?? '');
    $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    $stmt = $pdo->prepare(
        'INSERT INTO webhooks (
            received_at,
            webhook_setting_id,
            room_id,
            event_type,
            payload,
            headers,
            remote_addr,
            verified
        ) VALUES (
            :received_at,
            :webhook_setting_id,
            :room_id,
            :event_type,
            :payload,
            :headers,
            :remote_addr,
            :verified
        )'
    );

    $stmt->execute([
        ':received_at' => gmdate('c'),
        ':webhook_setting_id' => $webhookSettingId,
        ':room_id' => $roomId,
        ':event_type' => $eventType,
        ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':headers' => json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':remote_addr' => $remoteAddr,
        ':verified' => 1,
    ]);

    respond(200, [
        'ok' => true,
        'saved_id' => (int)$pdo->lastInsertId(),
        'webhook_setting_id' => $webhookSettingId,
        'room_id' => $roomId,
        'event_type' => $eventType,
    ]);
} catch (Throwable $e) {
    respond(500, [
        'ok' => false,
        'error' => 'internal_server_error',
        'message' => $e->getMessage(),
    ]);
}

function createTableIfNotExists(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS webhooks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            received_at TEXT NOT NULL,
            webhook_setting_id TEXT NOT NULL,
            room_id TEXT,
            event_type TEXT,
            payload TEXT NOT NULL,
            headers TEXT,
            remote_addr TEXT,
            verified INTEGER NOT NULL DEFAULT 0
        )'
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_webhooks_setting_id ON webhooks (webhook_setting_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_webhooks_received_at ON webhooks (received_at)');
}

function verifyRequest(array $headers, string $rawBody, string $token): bool
{
    $signatureHeader = $headers['x-chatworkwebhooksignature'] ?? null;
    if (is_string($signatureHeader) && $signatureHeader !== '') {
        $expectedSignature = base64_encode(hash_hmac('sha256', $rawBody, $token, true));
        return hash_equals($expectedSignature, trim($signatureHeader));
    }

    $tokenHeader = $headers['x-chatworktoken'] ?? ($headers['x-chatwork-webhook-token'] ?? null);
    if (is_string($tokenHeader) && $tokenHeader !== '') {
        return hash_equals($token, trim($tokenHeader));
    }

    return false;
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
