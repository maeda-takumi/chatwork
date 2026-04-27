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
