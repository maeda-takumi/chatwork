<?php
declare(strict_types=1);

$configs = require __DIR__ . '/config.webhooks.php';

$dbDir  = __DIR__ . '/data';
$dbPath = $dbDir . '/chatwork_webhook.sqlite';
$logPath = __DIR__ . '/webhook_error.log';

function writeLog(string $message, string $logPath): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    error_log($line, 3, $logPath);
}

function getHeaderValue(string $name): ?string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return $_SERVER[$key] ?? null;
}

function verifySignatureWithToken(string $rawBody, string $token, ?string $signature): bool
{
    if ($token === '' || !$signature) {
        return false;
    }

    $decodedToken = base64_decode($token, true);
    if ($decodedToken === false) {
        return false;
    }

    $digest = hash_hmac('sha256', $rawBody, $decodedToken, true);
    $expected = base64_encode($digest);

    return hash_equals($expected, $signature);
}

function findMatchedWebhookConfig(array $configs, string $rawBody, ?string $signature): ?array
{
    foreach ($configs as $config) {
        if (!($config['enabled'] ?? false)) {
            continue;
        }

        $token = (string)($config['token'] ?? '');
        if (verifySignatureWithToken($rawBody, $token, $signature)) {
            return $config;
        }
    }

    return null;
}

function initDatabase(PDO $pdo): void
{
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS webhook_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    received_at TEXT NOT NULL,
    webhook_setting_id TEXT,
    webhook_name TEXT,
    event_type TEXT,
    room_id TEXT,
    message_id TEXT,
    from_account_id TEXT,
    from_account_name TEXT,
    body TEXT,
    event_source_key TEXT NOT NULL,
    raw_json TEXT NOT NULL
);
SQL;
    $pdo->exec($sql);

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_event_source_key_unique ON webhook_events(event_source_key)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_received_at ON webhook_events(received_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_webhook_setting_id ON webhook_events(webhook_setting_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_room_id ON webhook_events(room_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_message_id ON webhook_events(message_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_event_type ON webhook_events(event_type)");
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            account_id TEXT PRIMARY KEY,
            account_name TEXT,
            mention_token TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_mention_token_unique ON users(mention_token)");

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS rooms (
            room_id TEXT PRIMARY KEY,
            room_name TEXT,
            icon_path TEXT,
            is_enabled INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rooms_enabled ON rooms(is_enabled)");
}

function payloadGet(array $payload, array $path, $default = null)
{
    $value = $payload;
    foreach ($path as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return $default;
        }
        $value = $value[$key];
    }
    return $value;
}

function extractMentionAccountIds(string $body): array
{
    if ($body === '') {
        return [];
    }

    preg_match_all('/\[To:(\d+)\]/', $body, $matches);
    if (empty($matches[1])) {
        return [];
    }

    return array_values(array_unique($matches[1]));
}
try {
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0777, true);
    }

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || $rawBody === '') {
        http_response_code(400);
        echo 'Empty body';
        exit;
    }

    $signature = getHeaderValue('x-chatworkwebhooksignature');

    $matchedConfig = findMatchedWebhookConfig($configs, $rawBody, $signature);
    if (!$matchedConfig) {
        writeLog('Signature verification failed or config not matched', $logPath);
        http_response_code(401);
        echo 'Invalid signature';
        exit;
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        writeLog('Invalid JSON: ' . $rawBody, $logPath);
        http_response_code(400);
        echo 'Invalid JSON';
        exit;
    }

    $webhookSettingId = (string)(payloadGet($payload, ['webhook_setting_id'], '') ?: ($matchedConfig['webhook_setting_id'] ?? ''));
    $eventType        = (string)(payloadGet($payload, ['webhook_event_type'], '') ?: payloadGet($payload, ['event_type'], ''));
    $roomId           = (string)(payloadGet($payload, ['webhook_event', 'room_id'], '') ?: ($matchedConfig['room_id'] ?? ''));
    $messageId        = (string)(payloadGet($payload, ['webhook_event', 'message_id'], '') ?: payloadGet($payload, ['message_id'], ''));
    $fromAccountId    = (string)(payloadGet($payload, ['webhook_event', 'from_account_id'], '') ?: payloadGet($payload, ['from_account_id'], ''));
    $fromAccountName  = (string)(payloadGet($payload, ['webhook_event', 'from_account_name'], '') ?: payloadGet($payload, ['from_account_name'], ''));
    $body             = (string)(payloadGet($payload, ['webhook_event', 'body'], '') ?: payloadGet($payload, ['body'], ''));

    $webhookName = (string)($matchedConfig['name'] ?? '');

    $eventSourceKey = implode('|', [
        $webhookSettingId,
        $eventType,
        $messageId,
    ]);

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    initDatabase($pdo);

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO webhook_events (
            received_at,
            webhook_setting_id,
            webhook_name,
            event_type,
            room_id,
            message_id,
            from_account_id,
            from_account_name,
            body,
            event_source_key,
            raw_json
        ) VALUES (
            :received_at,
            :webhook_setting_id,
            :webhook_name,
            :event_type,
            :room_id,
            :message_id,
            :from_account_id,
            :from_account_name,
            :body,
            :event_source_key,
            :raw_json
        )'
    );

    $now = date('Y-m-d H:i:s');
    $stmt->execute([
        ':received_at' => $now,
        ':webhook_setting_id' => $webhookSettingId,
        ':webhook_name' => $webhookName,
        ':event_type' => $eventType,
        ':room_id' => $roomId,
        ':message_id' => $messageId,
        ':from_account_id' => $fromAccountId,
        ':from_account_name' => $fromAccountName,
        ':body' => $body,
        ':event_source_key' => $eventSourceKey,
        ':raw_json' => $rawBody,
    ]);

    if ($roomId !== '') {
        $roomStmt = $pdo->prepare(
            'INSERT INTO rooms (room_id, room_name, icon_path, is_enabled, created_at, updated_at)
             VALUES (:room_id, :room_name, :icon_path, 1, :created_at, :updated_at)
             ON CONFLICT(room_id) DO UPDATE SET
                 room_name = CASE
                     WHEN excluded.room_name IS NOT NULL AND excluded.room_name <> "" THEN excluded.room_name
                     ELSE rooms.room_name
                 END,
                 icon_path = excluded.icon_path,
                 updated_at = excluded.updated_at'
        );

        $roomStmt->execute([
            ':room_id' => $roomId,
            ':room_name' => $webhookName,
            ':icon_path' => 'img/' . $roomId . '.png',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    if ($fromAccountId !== '') {
        $userStmt = $pdo->prepare(
            'INSERT INTO users (account_id, account_name, mention_token, created_at, updated_at)
             VALUES (:account_id, :account_name, :mention_token, :created_at, :updated_at)
             ON CONFLICT(account_id) DO UPDATE SET
                 account_name = CASE
                     WHEN excluded.account_name IS NOT NULL AND excluded.account_name <> "" THEN excluded.account_name
                     ELSE users.account_name
                 END,
                 mention_token = excluded.mention_token,
                 updated_at = excluded.updated_at'
        );

        $userStmt->execute([
            ':account_id' => $fromAccountId,
            ':account_name' => $fromAccountName,
            ':mention_token' => '[To:' . $fromAccountId . ']',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    $mentionIds = extractMentionAccountIds($body);
    if (!empty($mentionIds)) {
        $mentionStmt = $pdo->prepare(
            'INSERT INTO users (account_id, account_name, mention_token, created_at, updated_at)
             VALUES (:account_id, NULL, :mention_token, :created_at, :updated_at)
             ON CONFLICT(account_id) DO UPDATE SET
                 mention_token = excluded.mention_token,
                 updated_at = excluded.updated_at'
        );

        foreach ($mentionIds as $accountId) {
            $mentionStmt->execute([
                ':account_id' => $accountId,
                ':mention_token' => '[To:' . $accountId . ']',
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }
    }

    $pdo->commit();
    http_response_code(200);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'OK';

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    writeLog('Exception: ' . $e->getMessage(), $logPath);
    http_response_code(500);
    echo 'Internal Server Error';
}