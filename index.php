<?php
require_once __DIR__ . '/db.php';

$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accountId = trim((string)($_POST['account_id'] ?? ''));
    if ($accountId !== '' && sqlite_has_column($pdo, 'users', 'star')) {
        $updateStmt = $pdo->prepare('UPDATE users SET star = CASE WHEN COALESCE(star, 0) = 1 THEN 0 ELSE 1 END WHERE account_id = :account_id');
        $updateStmt->execute([':account_id' => $accountId]);
    }

    header('Location: index.php');
    exit;
}

$hasUsersStarColumn = sqlite_has_column($pdo, 'users', 'star');
$userSql = 'SELECT account_id, user_name, user_icon' . ($hasUsersStarColumn ? ', COALESCE(star, 0) AS star' : ', 0 AS star')
    . ' FROM users ORDER BY ' . ($hasUsersStarColumn ? 'COALESCE(star, 0) DESC, ' : '') . 'user_name ASC';
$users = $pdo->query($userSql)->fetchAll(PDO::FETCH_ASSOC);
function sqlite_table_exists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table_name LIMIT 1");
    $stmt->execute([':table_name' => $tableName]);
    return is_array($stmt->fetch(PDO::FETCH_ASSOC));
}

function sqlite_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
    $columns = $pdo->query('PRAGMA table_info(' . $tableName . ')')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        if ((string)($column['name'] ?? '') === $columnName) {
            return true;
        }
    }

    return false;
}


function parse_targets_from_body(string $body): array
{
    $bodyWithoutQuote = preg_replace('/\[qt\].*?\[\/qt\]/is', '', $body) ?? $body;

    if (preg_match('/\[toall\]/i', $bodyWithoutQuote) === 1) {
        return ['type' => 'all', 'account_ids' => []];
    }

    if (preg_match_all('/\[to[:：]\s*([^\]\s]+)\]/i', $bodyWithoutQuote, $matches) > 0) {
        $accountIds = array_values(array_unique(array_filter(array_map(static fn(string $value): string => trim($value), $matches[1] ?? []), static fn(string $value): bool => $value !== '')));
        return ['type' => 'user', 'account_ids' => $accountIds];
    }

    return ['type' => 'none', 'account_ids' => []];
}

function parse_reply_target_account_id(string $body): ?string
{
    if ($body === '') {
        return null;
    }

    if (preg_match('/\[rp[^\]]*\baid=([^\]\s]+)[^\]]*\]/i', $body, $matches) === 1) {
        return trim((string)($matches[1] ?? '')) ?: null;
    }

    return null;
}

function normalize_account_id(string $accountId): string
{
    $normalized = trim($accountId);
    if ($normalized === '') {
        return '';
    }

    if (preg_match('/^\d+$/', $normalized) === 1) {
        return ltrim($normalized, '0') ?: '0';
    }

    return $normalized;
}
function resolve_message_type_label(array $message): string
{
    $typeName = trim((string)($message['type_name'] ?? ''));
    return $typeName !== '' ? $typeName : '不明';
}

$hasTypeTable = sqlite_table_exists($pdo, 'type');
$hasMessageTypeIdColumn = sqlite_has_column($pdo, 'message', 'type_id');
$hasMessageAccountIdColumn = sqlite_has_column($pdo, 'message', 'account_id');
$hasMessageBodyColumn = sqlite_has_column($pdo, 'message', 'body');
$canJoinType = $hasTypeTable && $hasMessageTypeIdColumn;

$sql = <<<'SQL'
SELECT
SQL;

if ($hasMessageAccountIdColumn) {
    $sql .= " m.account_id,\n";
} else {
    $sql .= " NULL AS account_id,\n";
}

if ($hasMessageBodyColumn) {
    $sql .= " m.body, COALESCE(m.task, 0) AS task,\n";
} else {
    $sql .= " NULL AS body, COALESCE(m.task, 0) AS task,\n";
}

if ($canJoinType) {
    $sql .= " m.type_id, t.type_name\n";
} else {
    $sql .= " NULL AS type_id, NULL AS type_name\n";
}

$sql .= <<<'SQL'
FROM message m
SQL;

if ($canJoinType) {
    $sql .= "\nLEFT JOIN type t ON m.type_id = t.id\n";
}
try {
    $stmt = $pdo->query($sql);
} catch (PDOException $e) {
    $message = (string)$e->getMessage();
    if (strpos($message, 'no such column: m.account_id') === false && strpos($message, 'no such column: m.type_id') === false && strpos($message, 'no such column: m.body') === false) {
        throw $e;
    }

    $stmt = $pdo->query('SELECT NULL AS account_id, NULL AS body, COALESCE(m.task, 0) AS task, NULL AS type_id, NULL AS type_name FROM message m');
}
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);


$typeStatsByUser = [];

foreach ($users as $user) {
    $accountId = normalize_account_id((string)($user['account_id'] ?? ''));
    if ($accountId === '') {
        continue;
    }
    $typeStatsByUser[$accountId] = [];
}

foreach ($messages as $message) {
    $body = (string)($message['body'] ?? '');
    $target = parse_targets_from_body($body);
    $replyAid = parse_reply_target_account_id($body);
    if ($replyAid !== null) {
        $target = ['type' => 'user', 'account_ids' => [$replyAid]];
    }

    $target['account_ids'] = array_values(array_unique(array_filter(array_map('normalize_account_id', $target['account_ids'] ?? []), static fn(string $value): bool => $value !== '')));
    if ($target['type'] !== 'user') {
        continue;
    }
    $typeName = resolve_message_type_label($message);
    $isDone = (int)($message['task'] ?? 0) === 1;
    foreach ($target['account_ids'] as $targetAccountId) {
        if (!isset($typeStatsByUser[$targetAccountId])) {
            continue;
        }
        if (!isset($typeStatsByUser[$targetAccountId][$typeName])) {
            $typeStatsByUser[$targetAccountId][$typeName] = ['open' => 0, 'done' => 0];
        }

        if ($isDone) {
            $typeStatsByUser[$targetAccountId][$typeName]['done']++;
        } else {
            $typeStatsByUser[$targetAccountId][$typeName]['open']++;
        }
    }

}

include __DIR__ . '/header.php';
?>

<section class="dashboard-grid" aria-label="ユーザ別ダッシュボード">
  <?php if ($users === []): ?>
    <article class="card glass">
      <h2>ユーザが登録されていません</h2>
      <p>まずは「ユーザ追加」からユーザを登録してください。</p>
    </article>
  <?php endif; ?>

  <?php foreach ($users as $user): ?>
    <?php
    
      $accountId = normalize_account_id((string)($user['account_id'] ?? ''));
      $userName = trim((string)($user['user_name'] ?? '')) ?: ('account_id: ' . $accountId);
      $userIcon = trim((string)($user['user_icon'] ?? '')) ?: 'img/noimage.png';
      $isStarred = (int)($user['star'] ?? 0) === 1;
      $stats = $typeStatsByUser[$accountId] ?? [];
      ksort($stats);
    ?>
    
    <article class="card glass dashboard-card">
      <form method="post" class="dashboard-star-form">
        <input type="hidden" name="account_id" value="<?php echo htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="dashboard-star-button <?php echo $isStarred ? 'is-starred' : 'is-unstarred'; ?>" aria-label="<?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>をスターにする">★</button>
      </form>
      <div class="dashboard-user-head">
        <img src="<?php echo htmlspecialchars($userIcon, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>" onerror="this.onerror=null;this.src='img/noimage.png';">
        <div>
          <h2><?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?></h2>
          <p><?php echo htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
      </div>

      <?php if ($stats === []): ?>
        <p class="dashboard-empty">このユーザ宛てのメッセージはまだありません。</p>
      <?php else: ?>
        <ul class="dashboard-type-list">
          <?php foreach ($stats as $typeName => $counts): ?>
            <?php $query = http_build_query(['target' => $accountId, 'type' => [$typeName]]); ?>
            <li>
              <a href="list.php?<?php echo htmlspecialchars($query, ENT_QUOTES, 'UTF-8'); ?>" class="dashboard-type-link">
                <span class="type-name"><?php echo htmlspecialchars((string)$typeName, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="type-counts">未完了: <?php echo (int)($counts['open'] ?? 0); ?> / 完了: <?php echo (int)($counts['done'] ?? 0); ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
</section>

<?php include __DIR__ . '/footer.php'; ?>