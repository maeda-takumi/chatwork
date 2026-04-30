<?php
require_once __DIR__ . '/db.php';

$pdo = get_db();
$q = trim((string)($_GET['q'] ?? ''));
$selectedRoomId = trim((string)($_GET['room_id'] ?? ''));
$selectedTarget = trim((string)($_GET['target'] ?? ''));
$selectedTypes = $_GET['type'] ?? [];
if (!is_array($selectedTypes)) {
    $selectedTypes = [$selectedTypes];
}
$selectedTypes = array_values(array_unique(array_filter(array_map(static fn($value): string => trim((string)$value), $selectedTypes), static fn(string $value): bool => $value !== '')));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;

$rooms = $pdo->query('SELECT id, room_id, room_name, room_icon FROM room ORDER BY room_name ASC')->fetchAll(PDO::FETCH_ASSOC);

$users = $pdo->query('SELECT account_id, user_name, user_icon FROM users ORDER BY user_name ASC')->fetchAll(PDO::FETCH_ASSOC);
function sqlite_table_exists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table_name LIMIT 1");
    $stmt->execute([':table_name' => $tableName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row);
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

$hasTypeTable = sqlite_table_exists($pdo, 'type');
$hasMessageTypeIdColumn = sqlite_has_column($pdo, 'message', 'type_id');
$canJoinType = $hasTypeTable && $hasMessageTypeIdColumn;
$messageTypes = [];
if ($hasTypeTable) {
    $messageTypes = $pdo->query('SELECT type_name FROM type ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
    $messageTypes = array_values(array_filter(array_map(static fn($value): string => trim((string)$value), $messageTypes), static fn(string $value): bool => $value !== ''));
}
$sql = <<<'SQL'
SELECT
    m.id,
    m.message_id,
    m.room_id,
    m.account_id,
    m.body,
    m.send_time,
    COALESCE(m.task, 0) AS task,
SQL;

if ($canJoinType) {
    $sql .= <<<'SQL'
    m.type_id,
    t.type_name,
SQL;
} else {
    $sql .= <<<'SQL'
    NULL AS type_id,
    NULL AS type_name,
SQL;
}

$sql .= <<<'SQL'
    r.room_name,
    r.room_icon,
    u.user_name,
    u.user_icon
FROM message m
LEFT JOIN room r ON CAST(m.room_id AS TEXT) = r.room_id
LEFT JOIN users u ON m.account_id = u.account_id
SQL;

if ($canJoinType) {
    $sql .= "\nLEFT JOIN type t ON m.type_id = t.id\n";
}

$sql .= <<<'SQL'
WHERE 1=1
SQL;

$params = [];
if ($q !== '') {
    $sql .= ' AND m.body LIKE :q';
    $params[':q'] = '%' . $q . '%';
}
if ($selectedRoomId !== '') {
    $sql .= ' AND CAST(m.room_id AS TEXT) = :room_id';
    $params[':room_id'] = $selectedRoomId;
}
$sql .= ' ORDER BY COALESCE(m.send_time, "") DESC, m.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

function parse_attachments_from_body(string $body): array
{
    if ($body === '') {
        return [];
    }

    $matches = [];
    preg_match_all('/\[download:(\d+)\](.*?)\[\/download\]/is', $body, $matches, PREG_SET_ORDER);
    if ($matches === []) {
        return [];
    }

    $attachments = [];
    foreach ($matches as $match) {
        $fileId = trim((string)($match[1] ?? ''));
        $fileLabel = trim((string)($match[2] ?? ''));
        if ($fileId === '') {
            continue;
        }

        if ($fileLabel === '') {
            $fileLabel = 'file_id: ' . $fileId;
        }

        $attachments[] = [
            'file_id' => $fileId,
            'file_label' => $fileLabel,
        ];
    }

    return $attachments;
}


function parse_reply_to_message_id(string $body): ?string
{
    if ($body === '') {
        return null;
    }

    if (preg_match('/\[rp[^\]]*\bto=([^\]\s]+)[^\]]*\]/i', $body, $matches) === 1) {
        return trim((string)($matches[1] ?? '')) ?: null;
    }

    return null;
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

function parse_quote_to_message_id(string $body): ?string
{
    if ($body === '') {
        return null;
    }

    if (preg_match('/\[qtmeta[^\]]*\btime=([^\]\s]+)[^\]]*\]/i', $body, $matches) === 1) {
        return trim((string)($matches[1] ?? '')) ?: null;
    }

    return null;
}

function normalize_message_id(string $messageId): string
{
    $trimmed = trim($messageId);
    if ($trimmed === '') {
        return '';
    }

    if (preg_match('/^\d+-\d+$/', $trimmed) === 1) {
        $parts = explode('-', $trimmed, 2);
        return trim((string)($parts[1] ?? ''));
    }

    return $trimmed;
}

function parse_quote_preview_from_body(string $body): string
{
    if ($body === '') {
        return '';
    }

    if (preg_match('/\[qt\](.*?)\[\/qt\]/is', $body, $matches) !== 1) {
        return '';
    }

    $quoted = trim((string)($matches[1] ?? ''));
    if ($quoted === '') {
        return '';
    }

    $quoted = preg_replace('/^\[qtmeta[^\]]*\]\s*/i', '', $quoted) ?? $quoted;
    $quoted = preg_replace('/\[\/?toall\]/i', '', $quoted) ?? $quoted;
    $quoted = preg_replace('/\[to:[^\]]+\]/i', '', $quoted) ?? $quoted;
    $quoted = preg_replace('/^\[rp[^\]]*\]\s*/im', '', $quoted) ?? $quoted;
    $quoted = preg_replace('/\[(?:piconname|picon):[^\]]+\]/i', '', $quoted) ?? $quoted;
    $quoted = preg_replace("/[ \t]+/", ' ', $quoted) ?? $quoted;
    $quoted = preg_replace("/\n{3,}/", "\n\n", $quoted) ?? $quoted;

    return trim($quoted);
}

function sanitize_message_body_for_display(string $body): string
{
    if ($body === '') {
        return '';
    }

    $cleaned = $body;
    $patterns = [
        '/^\[rp[^\]]*\]/i',
        '/\[toall\]/i',
        '/\[qt\]\s*\[qtmeta[^\]]*\].*?\[\/qt\]\s*/is',
        '/\[to[:：]\s*[^\]\s]+\][^\r\n]*(?:\r?\n)?/i',
        '/\[download:\d+\].*?\[\/download\]/is',
        '/\[info\]\s*\[title\]\s*\[dtext:file_uploaded\]\s*\[\/title\].*?\[\/info\]\s*/is',
    ];

    foreach ($patterns as $pattern) {
        $cleaned = preg_replace($pattern, '', (string)$cleaned);
    }

    $cleaned = preg_replace("/\n{3,}/", "\n\n", (string)$cleaned);
    return trim((string)$cleaned);
}
function resolve_message_type_label(array $message): string
{
    $typeName = trim((string)($message['type_name'] ?? ''));
    if ($typeName !== '') {
        return $typeName;
    }

    return '不明';
}

function message_type_badge_class(string $typeName): string
{
    $map = [
        '要対応' => 'type-need-action',
        '要返信' => 'type-need-reply',
        '要確認' => 'type-need-check',
        '報告' => 'type-report',
        '共有' => 'type-share',
        '完了報告' => 'type-complete-report',
        'お礼・リアクション' => 'type-thanks-reaction',
        '雑談' => 'type-chat',
        '不明' => 'type-unknown',
    ];

    return $map[$typeName] ?? 'type-unknown';
}
$targetUsersByAccountId = [];
foreach ($users as $user) {
    $targetUsersByAccountId[(string)$user['account_id']] = $user;
}

foreach ($messages as $index => $message) {
    $rawBody = (string)($message['body'] ?? '');
    $messages[$index]['message_id'] = normalize_message_id((string)($message['message_id'] ?? ''));
    $target = parse_targets_from_body($rawBody);
    $replyTargetAccountId = parse_reply_target_account_id($rawBody);
    if ($replyTargetAccountId !== null) {
        $target = ['type' => 'user', 'account_ids' => [$replyTargetAccountId]];
    }
    $messages[$index]['target_type'] = $target['type'];
    $messages[$index]['target_account_ids'] = $target['account_ids'];
    $messages[$index]['attachments'] = parse_attachments_from_body($rawBody);
    $messages[$index]['reply_to_message_id'] = normalize_message_id((string)(parse_reply_to_message_id($rawBody) ?? ''));
    $messages[$index]['quote_to_message_id'] = normalize_message_id((string)(parse_quote_to_message_id($rawBody) ?? ''));
    $messages[$index]['quote_preview'] = parse_quote_preview_from_body($rawBody);
    $messages[$index]['body_for_display'] = sanitize_message_body_for_display($rawBody);
    $messages[$index]['resolved_type_name'] = resolve_message_type_label($messages[$index]);
}

if ($selectedTarget !== '') {
    $messages = array_values(array_filter($messages, static function (array $message) use ($selectedTarget): bool {
        $targetType = (string)($message['target_type'] ?? 'none');
        $targetAccountIds = $message['target_account_ids'] ?? [];

        if ($selectedTarget === '__all__') {
            return $targetType === 'all';
        }

        return $targetType === 'user' && is_array($targetAccountIds) && in_array($selectedTarget, $targetAccountIds, true);
    }));
}
if ($selectedTypes !== []) {
    $messagesByMessageIdForTypeFilter = [];
    foreach ($messages as $message) {
        $messageId = normalize_message_id((string)($message['message_id'] ?? ''));
        if ($messageId !== '') {
            $messagesByMessageIdForTypeFilter[$messageId] = $message;
        }
    }

    $adjacency = [];
    foreach ($messages as $message) {
        $messageId = normalize_message_id((string)($message['message_id'] ?? ''));
        if ($messageId === '') {
            continue;
        }
        if (!isset($adjacency[$messageId])) {
            $adjacency[$messageId] = [];
        }

        $replyToMessageId = trim((string)($message['reply_to_message_id'] ?? ''));
        if ($replyToMessageId !== '' && isset($messagesByMessageIdForTypeFilter[$replyToMessageId])) {
            $adjacency[$messageId][] = $replyToMessageId;
            if (!isset($adjacency[$replyToMessageId])) {
                $adjacency[$replyToMessageId] = [];
            }
            $adjacency[$replyToMessageId][] = $messageId;
        }
    }

    $matchedIds = [];
    $visited = [];
    foreach (array_keys($adjacency) as $startMessageId) {
        if (isset($visited[$startMessageId])) {
            continue;
        }

        $stack = [$startMessageId];
        $component = [];
        $hasMatchedTypeInComponent = false;
        while ($stack !== []) {
            $currentId = array_pop($stack);
            if ($currentId === null || isset($visited[$currentId])) {
                continue;
            }
            $visited[$currentId] = true;
            $component[] = $currentId;

            $currentMessage = $messagesByMessageIdForTypeFilter[$currentId] ?? null;
            if (is_array($currentMessage)) {
                $typeName = trim((string)($currentMessage['resolved_type_name'] ?? ''));
                if ($typeName !== '' && in_array($typeName, $selectedTypes, true)) {
                    $hasMatchedTypeInComponent = true;
                }
            }

            foreach ($adjacency[$currentId] as $nextId) {
                if (!isset($visited[$nextId])) {
                    $stack[] = $nextId;
                }
            }
        }

        if ($hasMatchedTypeInComponent) {
            foreach ($component as $componentMessageId) {
                $matchedIds[$componentMessageId] = true;
            }
        }
    }

    $messages = array_values(array_filter($messages, static function (array $message) use ($matchedIds): bool {
        $messageId = normalize_message_id((string)($message['message_id'] ?? ''));
        return $messageId !== '' && isset($matchedIds[$messageId]);
    }));
}

$resolvedTypeNames = array_values(array_unique(array_map(static fn(array $message): string => trim((string)($message['resolved_type_name'] ?? '不明')), $messages)));
$resolvedTypeNames = array_values(array_filter($resolvedTypeNames, static fn(string $value): bool => $value !== ''));
if (!in_array('不明', $resolvedTypeNames, true)) {
    $resolvedTypeNames[] = '不明';
}
$messageTypes = array_values(array_unique(array_merge($messageTypes, $resolvedTypeNames, $selectedTypes)));


$totalCount = count($messages);
$totalPages = max(1, (int)ceil($totalCount / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$messages = array_slice($messages, $offset, $perPage);

$messagesByMessageId = [];
foreach ($messages as $message) {
    $messageId = normalize_message_id((string)($message['message_id'] ?? ''));
    if ($messageId === '') {
        continue;
    }
    $messagesByMessageId[$messageId] = $message;
}

$childrenByParentMessageId = [];
foreach ($messages as $message) {
    $replyToMessageId = trim((string)($message['reply_to_message_id'] ?? ''));
    if ($replyToMessageId === '' || !isset($messagesByMessageId[$replyToMessageId])) {
        continue;
    }

    if (!isset($childrenByParentMessageId[$replyToMessageId])) {
        $childrenByParentMessageId[$replyToMessageId] = [];
    }
    $childrenByParentMessageId[$replyToMessageId][] = $message;
}

foreach ($childrenByParentMessageId as $parentMessageId => $children) {
    usort($children, static function (array $a, array $b): int {
        $aTime = strtotime((string)($a['send_time'] ?? '')) ?: 0;
        $bTime = strtotime((string)($b['send_time'] ?? '')) ?: 0;
        if ($aTime === $bTime) {
            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        }

        return $aTime <=> $bTime;
    });
    $childrenByParentMessageId[$parentMessageId] = $children;
}

$appendReplyChildren = static function (
    array $parentMessage,
    array $childrenByParentMessageId,
    callable $appendReplyChildren,
    int $depth = 1
): array {
    $collected = [];
    $parentMessageId = trim((string)($parentMessage['message_id'] ?? ''));
    if ($parentMessageId === '' || !isset($childrenByParentMessageId[$parentMessageId])) {
        return $collected;
    }

    foreach ($childrenByParentMessageId[$parentMessageId] as $childMessage) {
        $childMessage['is_reply_child'] = true;
        $childMessage['reply_depth'] = $depth;
        $collected[] = $childMessage;
        foreach ($appendReplyChildren($childMessage, $childrenByParentMessageId, $appendReplyChildren, $depth + 1) as $nestedChild) {
            $nestedChild['is_reply_child'] = true;
            $collected[] = $nestedChild;
        }
    }

    return $collected;
};

$messagesWithHierarchy = [];
foreach ($messages as $message) {
    $replyToMessageId = trim((string)($message['reply_to_message_id'] ?? ''));
    if ($replyToMessageId !== '' && isset($messagesByMessageId[$replyToMessageId])) {
        continue;
    }

    $message['reply_depth'] = 0;
    $messagesWithHierarchy[] = $message;
    foreach ($appendReplyChildren($message, $childrenByParentMessageId, $appendReplyChildren, 1) as $childMessage) {
        $messagesWithHierarchy[] = $childMessage;
    }
}

$messages = $messagesWithHierarchy;

$selectedTargetUser = $selectedTarget !== '' ? ($targetUsersByAccountId[$selectedTarget] ?? null) : null;
$selectedTargetLabel = '対象者を選択';
$selectedTargetIcon = 'img/all.png';
if ($selectedTarget === '__all__') {
    $selectedTargetLabel = '全員 ([toall])';
    $selectedTargetIcon = 'img/all.png';
} elseif (is_array($selectedTargetUser)) {
    $selectedTargetLabel = trim((string)$selectedTargetUser['user_name']) ?: ('account_id: ' . $selectedTarget);
    $selectedTargetIcon = trim((string)$selectedTargetUser['user_icon']) ?: 'img/noimage.png';
}

function build_query(array $overrides = []): string
{
    $params = [
        'q' => trim((string)($_GET['q'] ?? '')),
        'room_id' => trim((string)($_GET['room_id'] ?? '')),
        'target' => trim((string)($_GET['target'] ?? '')),
        'type' => $_GET['type'] ?? [],
        'page' => (string)max(1, (int)($_GET['page'] ?? 1)),
    ];

    foreach ($overrides as $key => $value) {
        if ($key === 'type') {
            if (is_array($value)) {
                $params[$key] = $value;
            } elseif ($value === null) {
                $params[$key] = [];
            } else {
                $params[$key] = [(string)$value];
            }
            continue;
        }
        $params[$key] = (string)$value;
    }

    if (($params['page'] ?? '1') === '1') {
        unset($params['page']);
    }

    $filtered = [];
    foreach ($params as $key => $value) {
        if ($key === 'type') {
            if (is_array($value) && $value !== []) {
                $filtered[$key] = array_values(array_filter(array_map(static fn($item): string => trim((string)$item), $value), static fn(string $item): bool => $item !== ''));
            }
            continue;
        }

        if ((string)$value !== '') {
            $filtered[$key] = (string)$value;
        }
    }

    return http_build_query($filtered);
}

include __DIR__ . '/header.php';
?>

<section class="card glass form-card">
  <form method="get" class="admin-form horizontal-form search-row" data-search-form>
    <div class="frame-row">
        <label>テキスト検索
        <input type="text" name="q" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>" placeholder="bodyを検索" data-search-text-input>
        </label>

        <label>対象者検索
        <input type="hidden" name="target" value="<?php echo htmlspecialchars($selectedTarget, ENT_QUOTES, 'UTF-8'); ?>" data-target-input>
        <div class="target-dropdown" data-target-dropdown>
            <button type="button" class="target-dropdown-toggle" data-target-toggle>
            <img src="<?php echo htmlspecialchars($selectedTargetIcon, ENT_QUOTES, 'UTF-8'); ?>" alt="選択対象" onerror="this.onerror=null;this.src='img/noimage.png';">
            <span><?php echo htmlspecialchars($selectedTargetLabel, ENT_QUOTES, 'UTF-8'); ?></span>
            </button>
            <div class="target-dropdown-menu" data-target-menu>
            <button type="button" class="target-option" data-value="">
                <img src="img/all.png" alt="対象指定なし">
                <span>対象指定なし</span>
            </button>
            <button type="button" class="target-option" data-value="__all__">
                <img src="img/all.png" alt="全員">
                <span>全員 ([toall])</span>
            </button>
            <?php foreach ($users as $user): ?>
                <?php
                $icon = trim((string)($user['user_icon'] ?? '')) ?: 'img/noimage.png';
                $name = trim((string)($user['user_name'] ?? '')) ?: ('account_id: ' . (string)$user['account_id']);
                ?>
                <button type="button" class="target-option" data-value="<?php echo htmlspecialchars((string)$user['account_id'], ENT_QUOTES, 'UTF-8'); ?>">
                <img src="<?php echo htmlspecialchars($icon, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>" onerror="this.onerror=null;this.src='img/noimage.png';">
                <span><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></span>
                </button>
            <?php endforeach; ?>
            </div>
        </div>
        </label>
    </div>
    <div class="type-filter-wrap">
      <strong>タイプ検索</strong>
      <div class="type-filter-list" data-type-filter-list>
        <?php foreach ($messageTypes as $typeName): ?>
          <?php
            $badgeClass = message_type_badge_class($typeName);
            $isSelected = in_array($typeName, $selectedTypes, true);
          ?>
          <button
            type="button"
            class="type-filter-chip <?php echo $isSelected ? 'is-active' : ''; ?>"
            data-type-toggle
            data-type-name="<?php echo htmlspecialchars($typeName, ENT_QUOTES, 'UTF-8'); ?>"
          >
            <span class="message-type-badge <?php echo htmlspecialchars($badgeClass, ENT_QUOTES, 'UTF-8'); ?>">
              <?php echo htmlspecialchars($typeName, ENT_QUOTES, 'UTF-8'); ?>
            </span>
          </button>
        <?php endforeach; ?>
      </div>
      <div data-type-hidden-container>
        <?php foreach ($selectedTypes as $selectedType): ?>
          <input type="hidden" name="type[]" value="<?php echo htmlspecialchars($selectedType, ENT_QUOTES, 'UTF-8'); ?>">
        <?php endforeach; ?>
      </div>
    </div>
    <!-- <div class="search-actions">
      <a href="list.php?<?php echo htmlspecialchars(build_query(['room_id' => $selectedRoomId, 'q' => '', 'target' => '', 'type' => [], 'page' => '1']), ENT_QUOTES, 'UTF-8'); ?>" class="search-reset-button">検索条件をリセット</a>
    </div> -->
    <input type="hidden" name="room_id" value="<?php echo htmlspecialchars($selectedRoomId, ENT_QUOTES, 'UTF-8'); ?>">
  </form>
    <div class="room-flex">
        <strong>ルーム検索</strong>
        <div class="icon-filter-row" aria-label="ルーム絞り込み">
            
            <a href="list.php?<?php echo htmlspecialchars(build_query(['room_id' => '', 'page' => '1']), ENT_QUOTES, 'UTF-8'); ?>" class="icon-filter-button <?php echo $selectedRoomId === '' ? 'active' : ''; ?>" title="すべて表示">
            すべて
            </a>
            <?php foreach ($rooms as $room): ?>
            <?php
                $roomId = (string)$room['room_id'];
                $roomName = (string)$room['room_name'];
                $iconPath = trim((string)($room['room_icon'] ?? ''));
            ?>
            <a
                href="list.php?<?php echo htmlspecialchars(build_query(['room_id' => $roomId, 'page' => '1']), ENT_QUOTES, 'UTF-8'); ?>"
                class="icon-filter-button <?php echo $selectedRoomId === $roomId ? 'active' : ''; ?>"
                title="<?php echo htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8'); ?>"
            >
                <img src="<?php echo htmlspecialchars($iconPath !== '' ? $iconPath : 'img/noimage.png', ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8'); ?>" onerror="this.onerror=null;this.src='img/noimage.png';">
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="cards" aria-label="メッセージ一覧">
  <?php if (empty($messages)): ?>
    <article class="card glass">
      <h3>メッセージが見つかりません</h3>
      <p>検索条件に一致するメッセージはありませんでした。</p>
    </article>
  <?php endif; ?>

  <?php foreach ($messages as $message): ?>
    <?php
      $roomName = trim((string)($message['room_name'] ?? ''));
      $roomLabel = $roomName !== '' ? $roomName : ('room_id: ' . (string)$message['room_id']);
      $roomIcon = trim((string)($message['room_icon'] ?? ''));
      $senderName = trim((string)($message['user_name'] ?? ''));
      $senderLabel = $senderName !== '' ? $senderName : ('account_id: ' . (string)$message['account_id']);
      $senderIcon = trim((string)($message['user_icon'] ?? ''));

      $targetType = (string)($message['target_type'] ?? 'none');
      $targetAccountIds = is_array($message['target_account_ids'] ?? null) ? $message['target_account_ids'] : [];
      $targetUsers = [];
      foreach ($targetAccountIds as $targetAccountId) {
          $targetUsers[] = $targetUsersByAccountId[(string)$targetAccountId] ?? ['account_id' => (string)$targetAccountId];
      }
      $targetEntries = [];
      if ($targetType === 'all') {
          $targetEntries[] = [
              'label' => '全員',
              'icon' => 'img/all.png',
          ];
      } else {
          foreach ($targetUsers as $targetUser) {
              $targetName = trim((string)($targetUser['user_name'] ?? ''));
              $targetAccountId = trim((string)($targetUser['account_id'] ?? ''));
              $targetLabel = '';
              if ($targetName !== '') {
                  $targetLabel = $targetName;
              } elseif ($targetAccountId !== '') {
                  $targetLabel = 'account_id: ' . $targetAccountId;
              }

              if ($targetLabel !== '') {
                  $targetEntries[] = [
                      'label' => $targetLabel,
                      'icon' => trim((string)($targetUser['user_icon'] ?? '')),
                  ];
              }
          }
      }
      if ($targetEntries === []) {
          $targetEntries[] = [
              'label' => '対象者なし',
              'icon' => 'img/noimage.png',
          ];
      }



      $rawSendTime = trim((string)($message['send_time'] ?? ''));
      $timestamp = strtotime($rawSendTime);
      $formattedSendTime = $rawSendTime;
      if ($timestamp !== false) {
          $formattedSendTime = date('Y/m/d H:i:s', $timestamp);
      }
      $isTaskDone = (int)($message['task'] ?? 0) === 1;
      $messageTypeLabel = resolve_message_type_label($message);
      $messageTypeBadgeClass = message_type_badge_class($messageTypeLabel);
      $isReplyChild = (bool)($message['is_reply_child'] ?? false);
      $replyDepth = max(0, (int)($message['reply_depth'] ?? 0));
      $replyToMessageId = trim((string)($message['reply_to_message_id'] ?? ''));
      $quotePreview = trim((string)($message['quote_preview'] ?? ''));
      if ($quotePreview !== '') {
          $quotePreview = preg_replace("/\s+/", ' ', $quotePreview) ?? $quotePreview;
      }
    ?>
    <div class="message-thread-item <?php echo $isReplyChild ? 'is-reply-child' : ''; ?>" style="--reply-level: <?php echo $replyDepth; ?>;">
      <?php if ($isReplyChild): ?>
        <div class="reply-branch-marker" aria-hidden="true"></div>
      <?php endif; ?>
      <article class="card glass message-card <?php echo $isTaskDone ? 'is-complete' : ''; ?>" data-message-id="<?php echo (int)$message['id']; ?>">
        <img class="complete-stamp" src="img/complete.png" alt="完了" onerror="this.style.display='none';">
        <div class="card-head">
          <h2>#<?php echo (int)$message['id']; ?></h2>
          <div class="card-actions">
            <span class="message-type-badge <?php echo htmlspecialchars($messageTypeBadgeClass, ENT_QUOTES, 'UTF-8'); ?>">
              <?php echo htmlspecialchars($messageTypeLabel, ENT_QUOTES, 'UTF-8'); ?>
            </span>
            <span class="badge message-datetime"><?php echo htmlspecialchars($formattedSendTime, ENT_QUOTES, 'UTF-8'); ?></span>
            <button type="button" class="task-toggle" data-task-state="<?php echo $isTaskDone ? '1' : '0'; ?>"><?php echo $isTaskDone ? '取消' : '完了'; ?></button>
          </div>
        </div>
        <div class="message-meta-row">

          <div class="entity-chip entity-chip-room" data-tooltip="<?php echo htmlspecialchars($roomLabel, ENT_QUOTES, 'UTF-8'); ?>">
            <strong>ルーム情報</strong>
            <img src="<?php echo htmlspecialchars($roomIcon !== '' ? $roomIcon : 'img/noimage.png', ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($roomLabel, ENT_QUOTES, 'UTF-8'); ?>" onerror="this.onerror=null;this.src='img/noimage.png';">
            
            <span><?php echo htmlspecialchars($roomLabel, ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
          <div class="entity-chip entity-chip-sender" data-tooltip="<?php echo htmlspecialchars($senderLabel, ENT_QUOTES, 'UTF-8'); ?>">
            <strong>送信者</strong>
            <img src="<?php echo htmlspecialchars($senderIcon !== '' ? $senderIcon : 'img/noimage.png', ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($senderLabel, ENT_QUOTES, 'UTF-8'); ?>" onerror="this.onerror=null;this.src='img/noimage.png';">
            <span><?php echo htmlspecialchars($senderLabel, ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
          <div class="target-chip-row">
            <?php foreach ($targetEntries as $targetEntry): ?>
              <?php
                $targetLabel = (string)($targetEntry['label'] ?? '対象者なし');
                $targetIcon = trim((string)($targetEntry['icon'] ?? ''));
              ?>
              <div class="entity-chip" data-tooltip="<?php echo htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8'); ?>">
                <strong>対象者</strong>
                <img src="<?php echo htmlspecialchars($targetIcon !== '' ? $targetIcon : 'img/noimage.png', ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8'); ?>" onerror="this.onerror=null;this.src='img/noimage.png';">
                <span><?php echo htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8'); ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php if ($replyToMessageId !== ''): ?>
          <div class="reply-meta">返信先 message_id: <?php echo htmlspecialchars($replyToMessageId, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($quotePreview !== ''): ?>
          <div class="quote-layout">
            <div class="quote-title">引用</div>
            <div class="quote-source">
              <div class="quote-preview"><?php echo htmlspecialchars($quotePreview, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="message-body"><?php echo nl2br(htmlspecialchars((string)($message['body_for_display'] ?? $message['body'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></div>
          </div>
        <?php else: ?>
          <div class="message-body"><?php echo nl2br(htmlspecialchars((string)($message['body_for_display'] ?? $message['body'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></div>
        <?php endif; ?>

        <?php
          $attachments = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];
          $downloadRoomId = (int)($message['room_id'] ?? 0);
        ?>
        <?php if ($downloadRoomId > 0 && $attachments !== []): ?>
          <div class="attachment-links">
            <?php foreach ($attachments as $attachment): ?>
              <?php
                $fileId = trim((string)($attachment['file_id'] ?? ''));
                $fileLabel = trim((string)($attachment['file_label'] ?? ''));
                if ($fileId === '') {
                    continue;
                }
              ?>
              <a
                class="attachment-link"
                href="download_attachment.php?room_id=<?php echo $downloadRoomId; ?>&amp;file_id=<?php echo urlencode($fileId); ?>"
                target="_blank"
                rel="noopener noreferrer"
              >
                <span><?php echo htmlspecialchars($fileLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                <img src="img/download.png" alt="ダウンロード" onerror="this.style.display='none';">
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>
    </div>
  <?php endforeach; ?>
</section>

<?php if ($totalPages > 1): ?>
  <nav class="pager" aria-label="ページャー">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <a class="pager-link <?php echo $p === $page ? 'active' : ''; ?>" href="list.php?<?php echo htmlspecialchars(build_query(['page' => (string)$p]), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $p; ?></a>
    <?php endfor; ?>
  </nav>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
