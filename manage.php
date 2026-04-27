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

function initDatabase(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            account_id INTEGER PRIMARY KEY AUTOINCREMENT,
            chatwork_account_id TEXT,
            account_name TEXT,
            mention_token TEXT,
            icon_path TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC);
    $columnMap = [];
    foreach ($columns as $column) {
        $columnMap[(string)$column['name']] = $column;
    }
    $accountIdType = strtoupper((string)($columnMap['account_id']['type'] ?? ''));
    $needsMigration = !isset($columnMap['chatwork_account_id'])
        || !isset($columnMap['icon_path'])
        || strpos($accountIdType, 'INT') === false;

    if ($needsMigration) {
        $pdo->exec(
            'CREATE TABLE users_new (
                account_id INTEGER PRIMARY KEY AUTOINCREMENT,
                chatwork_account_id TEXT,
                account_name TEXT,
                mention_token TEXT,
                icon_path TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $pdo->exec(
            'INSERT INTO users_new (chatwork_account_id, account_name, mention_token, icon_path, created_at, updated_at)
             SELECT
                account_id,
                account_name,
                mention_token,
                NULL,
                created_at,
                updated_at
             FROM users'
        );

        $pdo->exec('DROP TABLE users');
        $pdo->exec('ALTER TABLE users_new RENAME TO users');
    }

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_mention_token_unique ON users(mention_token)");

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_chatwork_account_id_unique ON users(chatwork_account_id)");
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
        $now = date('Y-m-d H:i:s');

        if ($target === 'room') {
            $roomId = trim((string)($_POST['room_id'] ?? ''));
            $roomName = trim((string)($_POST['room_name'] ?? ''));
            $iconPath = trim((string)($_POST['icon_path'] ?? ''));
            $isEnabled = isset($_POST['is_enabled']) ? 1 : 0;

            if ($roomId === '') {
                redirectWithStatus('rooms', 'error', 'ルームIDは必須です。');
            }

            if ($action === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO rooms (room_id, room_name, icon_path, is_enabled, created_at, updated_at)
                     VALUES (:room_id, :room_name, :icon_path, :is_enabled, :created_at, :updated_at)'
                );
                $stmt->execute([
                    ':room_id' => $roomId,
                    ':room_name' => $roomName,
                    ':icon_path' => $iconPath,
                    ':is_enabled' => $isEnabled,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
                redirectWithStatus('rooms', 'success', 'ルームを追加しました。');
            }

            if ($action === 'update') {
                $stmt = $pdo->prepare(
                    'UPDATE rooms
                     SET room_name = :room_name,
                         icon_path = :icon_path,
                         is_enabled = :is_enabled,
                         updated_at = :updated_at
                     WHERE room_id = :room_id'
                );
                $stmt->execute([
                    ':room_name' => $roomName,
                    ':icon_path' => $iconPath,
                    ':is_enabled' => $isEnabled,
                    ':updated_at' => $now,
                    ':room_id' => $roomId,
                ]);
                redirectWithStatus('rooms', 'success', 'ルームを更新しました。');
            }

            if ($action === 'delete') {
                $stmt = $pdo->prepare('DELETE FROM rooms WHERE room_id = :room_id');
                $stmt->execute([':room_id' => $roomId]);
                redirectWithStatus('rooms', 'success', 'ルームを削除しました。');
            }
        }

        if ($target === 'user') {
            $accountId = (int)($_POST['account_id'] ?? 0);
            $chatworkAccountId = trim((string)($_POST['chatwork_account_id'] ?? ''));
            $accountName = trim((string)($_POST['account_name'] ?? ''));
            $mentionToken = trim((string)($_POST['mention_token'] ?? ''));
            $iconPath = trim((string)($_POST['icon_path'] ?? ''));
            if ($mentionToken === '' && $chatworkAccountId !== '') {
                $mentionToken = '[To:' . $chatworkAccountId . ']';
            }

            if ($action === 'create') {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (chatwork_account_id, account_name, mention_token, icon_path, created_at, updated_at)
                     VALUES (:chatwork_account_id, :account_name, :mention_token, :icon_path, :created_at, :updated_at)'
                );
                $stmt->execute([
                    ':chatwork_account_id' => $chatworkAccountId,
                    ':account_name' => $accountName,
                    ':mention_token' => $mentionToken,
                    ':icon_path' => $iconPath,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
                redirectWithStatus('users', 'success', 'ユーザーを追加しました。');
            }

            if ($action === 'update') {
                if ($accountId <= 0) {
                    redirectWithStatus('users', 'error', '更新対象のIDが不正です。');
                }
                $stmt = $pdo->prepare(
                    'UPDATE users
                     SET chatwork_account_id = :chatwork_account_id,
                         account_name = :account_name,
                         mention_token = :mention_token,
                         icon_path = :icon_path,
                         updated_at = :updated_at
                     WHERE account_id = :account_id'
                );
                $stmt->execute([
                    ':chatwork_account_id' => $chatworkAccountId,
                    ':account_name' => $accountName,
                    ':mention_token' => $mentionToken,
                    ':icon_path' => $iconPath,
                    ':updated_at' => $now,
                    ':account_id' => $accountId,
                ]);
                redirectWithStatus('users', 'success', 'ユーザーを更新しました。');
            }

            if ($action === 'delete') {
                if ($accountId <= 0) {
                    redirectWithStatus('users', 'error', '削除対象のIDが不正です。');
                }
                $stmt = $pdo->prepare('DELETE FROM users WHERE account_id = :account_id');
                $stmt->execute([':account_id' => $accountId]);
                redirectWithStatus('users', 'success', 'ユーザーを削除しました。');
            }
        }

        redirectWithStatus($section, 'error', '不正な操作です。');
    } catch (Throwable $e) {
        redirectWithStatus($section, 'error', '保存に失敗しました: ' . $e->getMessage());
    }
}

$rooms = $pdo->query('SELECT room_id, room_name, icon_path, is_enabled, created_at, updated_at FROM rooms ORDER BY updated_at DESC')->fetchAll();
$users = $pdo->query('SELECT account_id, chatwork_account_id, account_name, mention_token, icon_path, created_at, updated_at FROM users ORDER BY updated_at DESC')->fetchAll();

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
            <input name="icon_path" class="search-input" placeholder="アイコンパス (例: img/123.png)">
            <label class="checkbox-inline"><input type="checkbox" name="is_enabled" checked> 有効</label>
            <button type="submit" class="btn btn-primary">追加</button>
        </form>
    </section>

    <section class="panel">
        <h2 class="section-title">ルーム一覧</h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ルームID</th>
                        <th>ルーム名</th>
                        <th>アイコン</th>
                        <th>有効</th>
                        <th>更新日時</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rooms)): ?>
                        <tr><td colspan="6" class="empty-cell">ルームはまだありません。</td></tr>
                    <?php else: ?>
                        <?php foreach ($rooms as $room): ?>
                            <tr>
                                <form method="post">
                                    <td class="mono">
                                        <input type="hidden" name="target" value="room">
                                        <input type="hidden" name="room_id" value="<?php echo h($room['room_id']); ?>">
                                        <?php echo h($room['room_id']); ?>
                                    </td>
                                    <td><input name="room_name" class="inline-input" value="<?php echo h($room['room_name']); ?>"></td>
                                    <td><input name="icon_path" class="inline-input" value="<?php echo h($room['icon_path']); ?>"></td>
                                    <td>
                                        <label class="checkbox-inline">
                                            <input type="checkbox" name="is_enabled" <?php echo (int)$room['is_enabled'] === 1 ? 'checked' : ''; ?>>
                                            表示
                                        </label>
                                    </td>
                                    <td class="mono"><?php echo h($room['updated_at']); ?></td>
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
            <input name="chatwork_account_id" class="search-input" placeholder="ChatworkアカウントID">
            <input name="account_name" class="search-input" placeholder="表示名">
            <input name="mention_token" class="search-input" placeholder="メンショントークン (例: [To:123])">
            <input name="icon_path" class="search-input" placeholder="アイコンパス (例: img/user-1.png)">
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
                        <th>Chatwork ID</th>
                        <th>表示名</th>
                        <th>アイコン</th>
                        <th>メンション</th>
                        <th>更新日時</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="7" class="empty-cell">ユーザーはまだありません。</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <form method="post">
                                    <td class="mono">
                                        <input type="hidden" name="target" value="user">
                                        <input type="hidden" name="account_id" value="<?php echo h($user['account_id']); ?>">
                                        <?php echo h($user['account_id']); ?>
                                    </td>
                                    <td><input name="chatwork_account_id" class="inline-input" value="<?php echo h($user['chatwork_account_id']); ?>"></td>
                                    <td><input name="account_name" class="inline-input" value="<?php echo h($user['account_name']); ?>"></td>
                                    <td><input name="icon_path" class="inline-input" value="<?php echo h($user['icon_path']); ?>"></td>
                                    <td><input name="mention_token" class="inline-input" value="<?php echo h($user['mention_token']); ?>"></td>
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
