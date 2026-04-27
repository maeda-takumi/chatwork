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

$search = trim((string)($_GET['search'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 25;
$offset = ($page - 1) * $limit;

$rows = [];
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

        $where = '';
        $params = [];

        if ($search !== '') {
            $where = "WHERE 
                event_type LIKE :search
                OR room_id LIKE :search
                OR message_id LIKE :search
                OR from_account_id LIKE :search
                OR from_account_name LIKE :search
                OR body LIKE :search
                OR raw_json LIKE :search
            ";
            $params[':search'] = '%' . $search . '%';
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
        $rows = [];
        $total = 0;
        $totalPages = 1;
    }
}

$queryBase = [];
if ($search !== '') {
    $queryBase['search'] = $search;
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
        <div class="search-group">
            <input
                type="text"
                name="search"
                value="<?php echo h($search); ?>"
                class="search-input"
                placeholder="送信者名、本文、ルームID、イベント種別などで検索"
            >
            <button type="submit" class="btn btn-primary">検索</button>
            <?php if ($search !== ''): ?>
                <a href="index.php" class="btn btn-secondary">クリア</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="panel">
    <div class="list-meta">
        <div>1ページ最大 <?php echo $limit; ?> 件表示</div>
        <div>
            <?php if (!$dbAvailable): ?>
                DBファイル未作成
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