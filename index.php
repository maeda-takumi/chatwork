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
/**
 * @return string[]
 */
function extractToAccountIds(string $body): array
{
    if ($body === '') {
        return [];
    }

    preg_match_all('/\[to:(\d+)\]/i', $body, $matches);
    if (empty($matches[1])) {
        return [];
    }

    return array_values(array_unique(array_map('strval', $matches[1])));
}
$search = trim((string)($_GET['search'] ?? ''));
$selectedRoomId = trim((string)($_GET['room_id'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 25;
$offset = ($page - 1) * $limit;

$rows = [];
$rooms = [];
$usersByAccountId = [];
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

        $usersStmt = $pdo->query(
            'SELECT account_id, account_name
             FROM users
             ORDER BY account_name ASC, account_id ASC'
        );
        foreach ($usersStmt->fetchAll() as $user) {
            $accountId = (string)($user['account_id'] ?? '');
            if ($accountId === '') {
                continue;
            }
            $usersByAccountId[$accountId] = (string)($user['account_name'] ?? '');
        }

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
                room_id,
                COALESCE(NULLIF(rooms.room_name, ''), webhook_events.room_id) AS room_name,
                body
            FROM webhook_events
            LEFT JOIN rooms ON rooms.room_id = webhook_events.room_id
            {$where}
            ORDER BY webhook_events.id DESC
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
        $usersByAccountId = [];
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
                    title="すべてのルーム"
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
                        title="<?php echo h($buttonLabel); ?>"
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
                        <th>ルーム名</th>
                        <th>宛先</th>
                        <th>本文</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="5" class="empty-cell">表示するデータがありません。</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $body = (string)($row['body'] ?? '');
                            $toAccountIds = extractToAccountIds($body);
                            $toLabels = [];
                            foreach ($toAccountIds as $toAccountId) {
                                $toName = trim((string)($usersByAccountId[$toAccountId] ?? ''));
                                $toLabels[] = $toName !== ''
                                    ? $toName . ' (' . $toAccountId . ')'
                                    : ('ID: ' . $toAccountId);
                            }
                            ?>
                            <tr>
                                <td class="mono"><?php echo h((string)$row['id']); ?></td>
                                <td class="mono"><?php echo h($row['received_at']); ?></td>
                                <td><?php echo h((string)($row['room_name'] ?? $row['room_id'])); ?></td>
                                <td>
                                    <?php if (empty($toLabels)): ?>
                                        <span class="sub-text">指定なし</span>
                                    <?php else: ?>
                                        <div class="recipient-list">
                                            <?php foreach ($toLabels as $toLabel): ?>
                                                <span class="recipient-item"><?php echo h($toLabel); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="message-cell">
                                        <?php echo nl2br(h($body)); ?>
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