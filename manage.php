<?php
declare(strict_types=1);

$dbPath = __DIR__ . '/data/chatwork_webhook.sqlite';

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tme(string $path): string
{
    $fullPath = __DIR__ . '/' . ltrim($path, '/');
    return file_exists($fullPath) ? (string)filemtime($fullPath) : (string)time();
}

function tableColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    if ($stmt === false) {
        return [];
    }

    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $result[(string)$column['name']] = true;
    }

    return $result;
}

function initDatabase(PDO $pdo): void
{
    // messages: Chatwork Webhookドキュメントにある共通/イベント項目を保持
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            webhook_setting_id TEXT,
            webhook_event_type TEXT,
            webhook_event_time INTEGER,
            message_id TEXT,
            room_id TEXT,
            account_id TEXT,
            from_account_id TEXT,
            to_account_id TEXT,
            body TEXT,
            send_time INTEGER,
            update_time INTEGER,
            raw_json TEXT,
            received_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_webhook_event_type ON messages(webhook_event_type)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_room_id ON messages(room_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_messages_message_id ON messages(message_id)');

    // users: 要件定義の構造に揃える
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_name TEXT,
            user_id TEXT,
            user_icon TEXT
        )'
    );

    $userColumns = tableColumns($pdo, 'users');
    $needsUserMigration = !isset($userColumns['id'])
        || !isset($userColumns['user_name'])
        || !isset($userColumns['user_id'])
        || !isset($userColumns['user_icon'])
        || count($userColumns) !== 4;

    if ($needsUserMigration) {
        $idExpr = isset($userColumns['id'])
            ? 'id'
            : (isset($userColumns['account_id']) ? 'account_id' : 'NULL');
        $userNameExpr = isset($userColumns['user_name'])
            ? 'user_name'
            : (isset($userColumns['account_name']) ? 'account_name' : 'NULL');
        $userIdExpr = isset($userColumns['user_id'])
            ? 'user_id'
            : (isset($userColumns['chatwork_account_id']) ? 'chatwork_account_id' : 'NULL');
        $userIconExpr = isset($userColumns['user_icon'])
            ? 'user_icon'
            : (isset($userColumns['icon_path']) ? 'icon_path' : 'NULL');
        $pdo->exec(
            'CREATE TABLE users_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_name TEXT,
                user_id TEXT,
                user_icon TEXT
            )'
        );

        $pdo->exec(
            'INSERT INTO users_new (id, user_name, user_id, user_icon)
             SELECT
                ' . $idExpr . ',
                ' . $userNameExpr . ',
                ' . $userIdExpr . ',
                ' . $userIconExpr . '
             FROM users'
        );

        $pdo->exec('DROP TABLE users');
        $pdo->exec('ALTER TABLE users_new RENAME TO users');
    }

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_user_id_unique ON users(user_id)');

    // rooms: 要件定義の構造に揃える
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS rooms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id TEXT,
            room_name TEXT,
            room_icon TEXT
        )'
    );

    $roomColumns = tableColumns($pdo, 'rooms');
    $needsRoomMigration = !isset($roomColumns['id'])
        || !isset($roomColumns['room_id'])
        || !isset($roomColumns['room_name'])
        || !isset($roomColumns['room_icon'])
        || count($roomColumns) !== 4;

    if ($needsRoomMigration) {
        $roomIconExpr = isset($roomColumns['room_icon'])
            ? 'room_icon'
            : (isset($roomColumns['icon_path']) ? 'icon_path' : 'NULL');

        $pdo->exec(
            'CREATE TABLE rooms_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                room_id TEXT,
                room_name TEXT,
                room_icon TEXT
            )'
        );

        $pdo->exec(
            'INSERT INTO rooms_new (room_id, room_name, room_icon)
             SELECT
                room_id,
                room_name,
                ' . $roomIconExpr . '
             FROM rooms'
        );

        $pdo->exec('DROP TABLE rooms');
        $pdo->exec('ALTER TABLE rooms_new RENAME TO rooms');
    }

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_rooms_room_id_unique ON rooms(room_id)');
}

function redirectWithStatus(string $section, string $type, string $message): void
{
    $query = http_build_query([
        'section' => $section,
        'status' => $type,
        'message' => $message,
    ]);
    header('Location: manage.php?' . $query);
    exit;
}

$section = (string)($_GET['section'] ?? 'rooms');
if (!in_array($section, ['rooms', 'users'], true)) {
    $section = 'rooms';
}

$status = (string)($_GET['status'] ?? '');
$message = (string)($_GET['message'] ?? '');

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
initDatabase($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $target = (string)($_POST['target'] ?? '');

    try {
        if ($target === 'room') {
            $id = (int)($_POST['id'] ?? 0);
            $roomId = trim((string)($_POST['room_id'] ?? ''));
            $roomName = trim((string)($_POST['room_name'] ?? ''));
            $roomIcon = trim((string)($_POST['room_icon'] ?? ''));

            if ($roomId === '') {
                redirectWithStatus('rooms', 'error', 'ルームIDは必須です。');
            }

            if ($action === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO rooms (room_id, room_name, room_icon)
                     VALUES (:room_id, :room_name, :room_icon)'
                );
                $stmt->execute([
                    ':room_id' => $roomId,
                    ':room_name' => $roomName,
                    ':room_icon' => $roomIcon,
                ]);
                redirectWithStatus('rooms', 'success', 'ルームを追加しました。');
            }

            if ($action === 'update') {
                if ($id <= 0) {
                    redirectWithStatus('rooms', 'error', '更新対象のIDが不正です。');
                }

                $stmt = $pdo->prepare(
                    'UPDATE rooms
                     SET room_id = :room_id,
                         room_name = :room_name,
                         room_icon = :room_icon
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':room_id' => $roomId,
                    ':room_name' => $roomName,
                    ':room_icon' => $roomIcon,
                    ':id' => $id,
                ]);
                redirectWithStatus('rooms', 'success', 'ルームを更新しました。');
            }

            if ($action === 'delete') {
                if ($id <= 0) {
                    redirectWithStatus('rooms', 'error', '削除対象のIDが不正です。');
                }
                $stmt = $pdo->prepare('DELETE FROM rooms WHERE id = :id');
                $stmt->execute([':id' => $id]);
                redirectWithStatus('rooms', 'success', 'ルームを削除しました。');
            }
        }

        if ($target === 'user') {
            $id = (int)($_POST['id'] ?? 0);
            $userName = trim((string)($_POST['user_name'] ?? ''));
            $userId = trim((string)($_POST['user_id'] ?? ''));
            $userIcon = trim((string)($_POST['user_icon'] ?? ''));

            if ($action === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (user_name, user_id, user_icon)
                     VALUES (:user_name, :user_id, :user_icon)'
                );
                $stmt->execute([
                    ':user_name' => $userName,
                    ':user_id' => $userId,
                    ':user_icon' => $userIcon,
                ]);
                redirectWithStatus('users', 'success', 'ユーザーを追加しました。');
            }

            if ($action === 'update') {
                if ($id <= 0) {
                    redirectWithStatus('users', 'error', '更新対象のIDが不正です。');
                }
                $stmt = $pdo->prepare(
                    'UPDATE users
                     SET user_name = :user_name,
                         user_id = :user_id,
                         user_icon = :user_icon
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':user_name' => $userName,
                    ':user_id' => $userId,
                    ':user_icon' => $userIcon,
                    ':id' => $id,
                ]);
                redirectWithStatus('users', 'success', 'ユーザーを更新しました。');
            }

            if ($action === 'delete') {
                if ($id <= 0) {
                    redirectWithStatus('users', 'error', '削除対象のIDが不正です。');
                }
                $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
                $stmt->execute([':id' => $id]);
                redirectWithStatus('users', 'success', 'ユーザーを削除しました。');
            }
        }

        redirectWithStatus($section, 'error', '不正な操作です。');
    } catch (Throwable $e) {
        redirectWithStatus($section, 'error', '保存に失敗しました: ' . $e->getMessage());
    }
}

$rooms = $pdo->query('SELECT id, room_id, room_name, room_icon FROM rooms ORDER BY id DESC')->fetchAll();
$users = $pdo->query('SELECT id, user_name, user_id, user_icon FROM users ORDER BY id DESC')->fetchAll();
include __DIR__ . '/header.php';
?>

<section class="panel">
    <div class="panel-head">
        <div>
            <h1 class="page-title">マスタ管理</h1>
            <p class="page-subtitle">ルームとユーザーを手動で追加・編集・削除できます。</p>
        </div>
    </div>

    <div class="tab-nav">
        <a href="manage.php?section=rooms" class="tab-link <?php echo $section === 'rooms' ? 'is-active' : ''; ?>">ルーム管理</a>
        <a href="manage.php?section=users" class="tab-link <?php echo $section === 'users' ? 'is-active' : ''; ?>">ユーザー管理</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="flash-message <?php echo $status === 'success' ? 'is-success' : 'is-error'; ?>">
            <?php echo h($message); ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($section === 'rooms'): ?>
    <section class="panel">
        <h2 class="section-title">ルーム追加</h2>
        <form method="post" class="crud-form grid-4">
            <input type="hidden" name="target" value="room">
            <input type="hidden" name="action" value="create">
            <input name="room_id" class="search-input" placeholder="ルームID" required>
            <input name="room_name" class="search-input" placeholder="ルーム名">
            <input name="room_icon" class="search-input" placeholder="アイコンファイル名 (例: 123.png)">
            <button type="submit" class="btn btn-primary">追加</button>
        </form>
    </section>

    <section class="panel">
        <h2 class="section-title">ルーム一覧</h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>ルームID</th>
                        <th>ルーム名</th>
                        <th>アイコン</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rooms)): ?>
                        <tr><td colspan="5" class="empty-cell">ルームはまだありません。</td></tr>
                    <?php else: ?>
                        <?php foreach ($rooms as $room): ?>
                            <tr>
                                <form method="post">
                                    <td class="mono">
                                        <input type="hidden" name="target" value="room">
                                        <input type="hidden" name="id" value="<?php echo h((string)$room['id']); ?>">
                                        <?php echo h((string)$room['id']); ?>
                                    </td>
                                    <td><input name="room_id" class="inline-input" value="<?php echo h($room['room_id']); ?>" required></td>
                                    <td><input name="room_name" class="inline-input" value="<?php echo h($room['room_name']); ?>"></td>
                                    <td><input name="room_icon" class="inline-input" value="<?php echo h($room['room_icon']); ?>"></td>
                                    <td class="actions-cell">
                                        <button class="btn btn-primary" type="submit" name="action" value="update">更新</button>
                                        <button class="btn btn-danger" type="submit" name="action" value="delete" onclick="return confirm('このルームを削除しますか？');">削除</button>
                                    </td>
                                </form>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($section === 'users'): ?>
    <section class="panel">
        <h2 class="section-title">ユーザー追加</h2>
        <form method="post" class="crud-form grid-4">
            <input type="hidden" name="target" value="user">
            <input type="hidden" name="action" value="create">
            <input name="user_name" class="search-input" placeholder="user_name">
            <input name="user_id" class="search-input" placeholder="user_id (ChatWorkメンションID)">
            <input name="user_icon" class="search-input" placeholder="user_icon (アイコンファイル名)">
            <button type="submit" class="btn btn-primary">追加</button>
        </form>
    </section>

    <section class="panel">
        <h2 class="section-title">ユーザー一覧</h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>user_name</th>
                        <th>user_id</th>
                        <th>user_icon</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="5" class="empty-cell">ユーザーはまだありません。</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <form method="post">
                                    <td class="mono">
                                        <input type="hidden" name="target" value="user">
                                        <input type="hidden" name="id" value="<?php echo h((string)$user['id']); ?>">
                                        <?php echo h((string)$user['id']); ?>
                                    </td>
                                    <td><input name="user_name" class="inline-input" value="<?php echo h($user['user_name']); ?>"></td>
                                    <td><input name="user_id" class="inline-input" value="<?php echo h($user['user_id']); ?>"></td>
                                    <td><input name="user_icon" class="inline-input" value="<?php echo h($user['user_icon']); ?>"></td>
                                    <td class="mono"><?php echo h($user['updated_at']); ?></td>
                                    <td class="actions-cell">
                                        <button class="btn btn-primary" type="submit" name="action" value="update">更新</button>
                                        <button class="btn btn-danger" type="submit" name="action" value="delete" onclick="return confirm('このユーザーを削除しますか？');">削除</button>
                                    </td>
                                </form>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
