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
            task INTEGER NOT NULL DEFAULT 0
        )'
    );

    ensure_task_column($pdo);
    
    return $pdo;
}

function ensure_task_column(PDO $pdo): void
{
    $columns = $pdo->query('PRAGMA table_info(message)')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        if ((string)($column['name'] ?? '') === 'task') {
            return;
        }
    }

    $pdo->exec('ALTER TABLE message ADD COLUMN task INTEGER NOT NULL DEFAULT 0');
}