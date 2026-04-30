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

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT NOT NULL
        )'
    );

    ensure_message_columns($pdo);
    
    ensure_users_columns($pdo);

    return $pdo;
}

function ensure_message_columns(PDO $pdo): void
{
    $columns = $pdo->query('PRAGMA table_info(message)')->fetchAll(PDO::FETCH_ASSOC);
    $hasTask = false;
    $hasMessageId = false;
    $hasTypeId = false;
    foreach ($columns as $column) {
        $name = (string)($column['name'] ?? '');
        if ($name === 'task') {
            $hasTask = true;
        }
        if ($name === 'message_id') {
            $hasMessageId = true;
        }
        if ($name === 'type_id') {
            $hasTypeId = true;
        }
    }

    if (!$hasTask) {
        $pdo->exec('ALTER TABLE message ADD COLUMN task INTEGER NOT NULL DEFAULT 0');
    }

    if (!$hasMessageId) {
        $pdo->exec('ALTER TABLE message ADD COLUMN message_id TEXT');
    }
    if (!$hasTypeId) {
        $pdo->exec('ALTER TABLE message ADD COLUMN type_id INTEGER');
    }

    seed_message_types($pdo);
}

function ensure_users_columns(PDO $pdo): void
{
    $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC);
    $hasStar = false;

    foreach ($columns as $column) {
        if ((string)($column['name'] ?? '') === 'star') {
            $hasStar = true;
            break;
        }
    }

    if (!$hasStar) {
        $pdo->exec('ALTER TABLE users ADD COLUMN star INTEGER NOT NULL DEFAULT 0');
    }
}
function seed_message_types(PDO $pdo): void
{
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