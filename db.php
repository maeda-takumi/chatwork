<?php
function get_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbPath = __DIR__ . '/data/webhooks.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_name TEXT NOT NULL,
            account_id TEXT NOT NULL,
            user_icon TEXT
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS room (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_name TEXT NOT NULL,
            room_id TEXT NOT NULL,
            room_icon TEXT
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS message (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id INTEGER,
            account_id TEXT,
            body TEXT NOT NULL,
            send_time TEXT,
            task INTEGER NOT NULL DEFAULT 0,
            message_id TEXT
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL
        )'
    );

    ensure_message_columns($pdo);
    
    return $pdo;
}

function ensure_message_columns(PDO $pdo): void
{
    $columns = $pdo->query('PRAGMA table_info(message)')->fetchAll(PDO::FETCH_ASSOC);
    $hasTask = false;
    $hasMessageId = false;
    foreach ($columns as $column) {
        $name = (string)($column['name'] ?? '');
        if ($name === 'task') {
            $hasTask = true;
        }
        if ($name === 'message_id') {
            $hasMessageId = true;
        }
    }

    if (!$hasTask) {
        $pdo->exec('ALTER TABLE message ADD COLUMN task INTEGER NOT NULL DEFAULT 0');
    }

    if (!$hasMessageId) {
        $pdo->exec('ALTER TABLE message ADD COLUMN message_id TEXT');
    }
}