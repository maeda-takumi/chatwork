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

function resolveIconUrl(string $icon): string
{
    $trimmed = trim($icon);
    if ($trimmed === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $trimmed) === 1) {
        return $trimmed;
    }

    $iconFile = __DIR__ . '/' . ltrim($trimmed, '/');
    return file_exists($iconFile) ? $trimmed : '';
}
function initDatabase(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            webhook_setting_id TEXT,
            webhook_event_type TEXT,
            webhook_event_time INTEGER,
            account_id TEXT,
            room_id TEXT,
            message_id TEXT,
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
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_mention_token_unique ON users(mention_token)");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_chatwork_account_id_unique ON users(chatwork_account_id)");
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_user_id_unique ON users(user_id)');

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
$search = trim((string)($_GET['search'] ?? ''));
$selectedRoomId = trim((string)($_GET['room_id'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));

$limit  = 25;
$offset = ($page - 1) * $limit;

$rows = [];
$rooms = [];
$total = 0;
$totalPages = 1;
$dbAvailable = false;

if (file_exists($dbPath)) {
    try {
        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $dbAvailable = true;
        initDatabase($pdo);

        $roomsStmt = $pdo->query(
            'SELECT room_id, room_name, room_icon
             FROM rooms
             ORDER BY COALESCE(NULLIF(room_name, ""), room_id) ASC'
        );
        $rooms = $roomsStmt->fetchAll();

        $whereParts = [];

        $where = '';
        $params = [];

        if ($search !== '') {
            $whereParts[] = '(
                m.webhook_event_type LIKE :search
                OR m.room_id LIKE :search
                OR m.message_id LIKE :search
                OR m.from_account_id LIKE :search
                OR u.user_name LIKE :search
                OR m.body LIKE :search
                OR m.raw_json LIKE :search
            )';
            $params[':search'] = '%' . $search . '%';
        }

        if ($selectedRoomId !== '') {
            $whereParts[] = 'm.room_id = :room_id';
            $params[':room_id'] = $selectedRoomId;
        }

        $where = '';
        if (!empty($whereParts)) {
            $where = 'WHERE ' . implode(' AND ', $whereParts);
        }

        $countSql = "
            SELECT COUNT(*)
            FROM messages m
            LEFT JOIN users u ON u.user_id = m.from_account_id
            {$where}
        ";
        $countStmt = $pdo->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();

        $totalPages = max(1, (int)ceil($total / $limit));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $limit;
        }

        $listSql = "
            SELECT
                m.id,
                m.received_at,
                m.webhook_event_type,
                m.room_id,
                m.message_id,
                m.from_account_id,
                m.body,
                COALESCE(NULLIF(u.user_name, ''), m.from_account_id, '') AS sender_name
            FROM messages m
            LEFT JOIN users u ON u.user_id = m.from_account_id
            {$where}
            ORDER BY m.id DESC
            LIMIT :limit OFFSET :offset
        ";

        $listStmt = $pdo->prepare($listSql);
        foreach ($params as $key => $value) {
            $listStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $listStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $listStmt->execute();
        $rows = $listStmt->fetchAll();

    } catch (Throwable $e) {
        $dbAvailable = false;
        $rooms = [];
        $rows = [];
        $total = 0;
        $totalPages = 1;
    }
}

$queryBase = [];
if ($search !== '') {
    $queryBase['search'] = $search;
}

if ($selectedRoomId !== '') {
    $queryBase['room_id'] = $selectedRoomId;
}
include __DIR__ . '/header.php';
?>

<section class="panel">
    <div class="panel-head">
        <div>
            <h1 class="page-title">Webhook Viewer</h1>
            <p class="page-subtitle">Chatwork Webhookで保存したデータを検索・閲覧できます。</p>
        </div>
        <div class="summary-box">
            <div class="summary-label">総件数</div>
            <div class="summary-value"><?php echo number_format($total); ?></div>
        </div>
    </div>

    <form method="get" action="index.php" class="search-form">
        <?php if ($selectedRoomId !== ''): ?>
            <input type="hidden" name="room_id" value="<?php echo h($selectedRoomId); ?>">
        <?php endif; ?>
        <div class="search-group">
            <input
                type="text"
                name="search"
                value="<?php echo h($search); ?>"
                class="search-input"
                placeholder="送信者名、本文、ルームID、イベント種別などで検索"
            >
            <button type="submit" class="btn btn-primary">検索</button>
            <?php if ($search !== '' || $selectedRoomId !== ''): ?>
                <a href="index.php" class="btn btn-secondary">クリア</a>
            <?php endif; ?>
        </div>
    </form>
    <?php if ($dbAvailable && !empty($rooms)): ?>
        <div class="room-filter-wrap">
            <div class="room-filter-label">ルーム選択</div>
            <div class="room-filter-list">
                <?php $allRoomsQuery = http_build_query($search !== '' ? ['search' => $search] : []); ?>
                <a
                    href="index.php<?php echo $allRoomsQuery !== '' ? '?' . h($allRoomsQuery) : ''; ?>"
                    class="room-filter-btn <?php echo $selectedRoomId === '' ? 'is-active' : ''; ?>"
                    data-tooltip="すべてのルーム"
                    aria-label="すべてのルーム"
                >
                    <span class="room-filter-all">ALL</span>
                </a>

                <?php foreach ($rooms as $room): ?>
                    <?php
                    $roomId = (string)$room['room_id'];
                    $roomName = (string)($room['room_name'] ?? '');
                    $iconPath = (string)($room['room_icon'] ?? ('img/' . $roomId . '.png'));
                    $iconUrl = resolveIconUrl($iconPath);

                    $query = ['room_id' => $roomId];
                    if ($search !== '') {
                        $query['search'] = $search;
                    }
                    $roomQuery = http_build_query($query);
                    $buttonLabel = $roomName !== '' ? $roomName : ('ルームID: ' . $roomId);
                    ?>
                    <a
                        href="index.php?<?php echo h($roomQuery); ?>"
                        class="room-filter-btn <?php echo $selectedRoomId === $roomId ? 'is-active' : ''; ?>"
                        data-tooltip="<?php echo h($buttonLabel); ?>"
                        aria-label="<?php echo h($buttonLabel); ?>"
                    >
                        <?php if ($iconUrl !== ''): ?>
                            <img src="<?php echo h($iconUrl); ?>" alt="<?php echo h($buttonLabel); ?>" class="room-filter-icon">
                        <?php else: ?>
                            <span class="room-filter-fallback"><?php echo h(substr($roomId, -2)); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="list-meta">
        <div>1ページ最大 <?php echo $limit; ?> 件表示</div>
        <div>
            <?php if (!$dbAvailable): ?>
                DBファイル未作成
            <?php elseif ($selectedRoomId !== '' && $search !== ''): ?>
                ルームID「<?php echo h($selectedRoomId); ?>」かつ「<?php echo h($search); ?>」の検索結果
            <?php elseif ($selectedRoomId !== ''): ?>
                ルームID「<?php echo h($selectedRoomId); ?>」のメッセージ一覧
            <?php elseif ($search !== ''): ?>
                「<?php echo h($search); ?>」の検索結果
            <?php else: ?>
                最新データ一覧
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$dbAvailable): ?>
        <div class="empty-state">
            <div class="empty-state-badge">NO DATA</div>
            <h2 class="empty-state-title">表示するデータがありません</h2>
            <p class="empty-state-text">
                まだWebhookデータが保存されていないため、表示できる内容がありません。<br>
                ChatworkからWebhookを受信すると、ここに一覧が表示されます。
            </p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>受信日時</th>
                        <th>イベント</th>
                        <th>ルームID</th>
                        <th>送信者</th>
                        <th>本文</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="6" class="empty-cell">表示するデータがありません。</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td class="mono"><?php echo h((string)$row['id']); ?></td>
                                <td class="mono"><?php echo h((string)$row['received_at']); ?></td>
                                <td>
                                    <span class="badge"><?php echo h((string)$row['webhook_event_type']); ?></span>
                                </td>
                                <td class="mono"><?php echo h((string)$row['room_id']); ?></td>
                                <td><?php echo h((string)$row['sender_name']); ?></td>
                                <td>
                                    <div class="message-cell" title="<?php echo h((string)$row['body']); ?>">
                                        <?php echo nl2br(h((string)$row['body'])); ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pager">
                <?php
                $prevPage = $page - 1;
                $nextPage = $page + 1;

                $startPage = max(1, $page - 2);
                $endPage   = min($totalPages, $page + 2);
                ?>

                <?php if ($page > 1): ?>
                    <?php $prevQuery = http_build_query(array_merge($queryBase, ['page' => $prevPage])); ?>
                    <a href="index.php?<?php echo h($prevQuery); ?>" class="pager-link">前へ</a>
                <?php endif; ?>

                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <?php $pageQuery = http_build_query(array_merge($queryBase, ['page' => $i])); ?>
                    <?php if ($i === $page): ?>
                        <span class="pager-link is-current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="index.php?<?php echo h($pageQuery); ?>" class="pager-link"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <?php $nextQuery = http_build_query(array_merge($queryBase, ['page' => $nextPage])); ?>
                    <a href="index.php?<?php echo h($nextQuery); ?>" class="pager-link">次へ</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/footer.php'; ?>