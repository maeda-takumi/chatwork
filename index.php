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
        'CREATE TABLE IF NOT EXISTS webhook_events (
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
        )'
    );

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_event_source_key_unique ON webhook_events(event_source_key)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_received_at ON webhook_events(received_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_webhook_setting_id ON webhook_events(webhook_setting_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_room_id ON webhook_events(room_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_message_id ON webhook_events(message_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_event_type ON webhook_events(event_type)");

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

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_mention_token_unique ON users(mention_token)");

    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_chatwork_account_id_unique ON users(chatwork_account_id)");

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
             SELECT account_id, account_name, mention_token, NULL, created_at, updated_at
             FROM users'
        );
        $pdo->exec('DROP TABLE users');
        $pdo->exec('ALTER TABLE users_new RENAME TO users');
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_mention_token_unique ON users(mention_token)");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_chatwork_account_id_unique ON users(chatwork_account_id)");
    }

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
            'SELECT room_id, room_name, icon_path
             FROM rooms
             WHERE is_enabled = 1
             ORDER BY COALESCE(NULLIF(room_name, ""), room_id) ASC'
        );
        $rooms = $roomsStmt->fetchAll();

        $whereParts = [];

        $where = '';
        $params = [];

        if ($search !== '') {
            $whereParts[] = '(
                event_type LIKE :search
                OR room_id LIKE :search
                OR message_id LIKE :search
                OR from_account_id LIKE :search
                OR from_account_name LIKE :search
                OR body LIKE :search
                OR raw_json LIKE :search
            )';
            $params[':search'] = '%' . $search . '%';
        }

        if ($selectedRoomId !== '') {
            $whereParts[] = 'room_id = :room_id';
            $params[':room_id'] = $selectedRoomId;
        }

        $where = '';
        if (!empty($whereParts)) {
            $where = 'WHERE ' . implode(' AND ', $whereParts);
        }

        $countSql = "SELECT COUNT(*) FROM webhook_events {$where}";
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
                id,
                received_at,
                event_type,
                room_id,
                message_id,
                from_account_id,
                from_account_name,
                body
            FROM webhook_events
            {$where}
            ORDER BY id DESC
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
                    $iconPath = (string)($room['icon_path'] ?? ('img/' . $roomId . '.png'));
                    $iconFile = __DIR__ . '/' . ltrim($iconPath, '/');
                    $iconUrl = file_exists($iconFile) ? $iconPath : '';
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
                                <td class="mono"><?php echo h($row['received_at']); ?></td>
                                <td>
                                    <span class="badge"><?php echo h($row['event_type']); ?></span>
                                </td>
                                <td class="mono"><?php echo h($row['room_id']); ?></td>
                                <td><?php echo h($row['from_account_name']); ?></td>
                                <td>
                                    <div class="message-cell" title="<?php echo h($row['body']); ?>">
                                        <?php echo nl2br(h($row['body'])); ?>
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