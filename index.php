<?php
require_once __DIR__ . '/db.php';

$pdo = get_db();
$q = trim((string)($_GET['q'] ?? ''));
$selectedRoomId = trim((string)($_GET['room_id'] ?? ''));
$selectedTarget = trim((string)($_GET['target'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;

$rooms = $pdo->query('SELECT id, room_id, room_name, room_icon FROM room ORDER BY room_name ASC')->fetchAll(PDO::FETCH_ASSOC);

$users = $pdo->query('SELECT account_id, user_name, user_icon FROM users ORDER BY user_name ASC')->fetchAll(PDO::FETCH_ASSOC);
$sql = <<<'SQL'
SELECT
    m.id,
    m.room_id,
    m.account_id,
    m.body,
    m.send_time,
    COALESCE(m.task, 0) AS task,
    r.room_name,
    r.room_icon,
    u.user_name,
    u.user_icon
FROM message m
LEFT JOIN room r ON CAST(m.room_id AS TEXT) = r.room_id
LEFT JOIN users u ON m.account_id = u.account_id
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
    if (preg_match('/\[toall\]/i', $body) === 1) {
        return ['type' => 'all', 'account_ids' => []];
    }

    if (preg_match_all('/\[to:\s*([^\]\s]+)\]/i', $body, $matches) > 0) {
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


$targetUsersByAccountId = [];
foreach ($users as $user) {
    $targetUsersByAccountId[(string)$user['account_id']] = $user;
}

foreach ($messages as $index => $message) {
    $target = parse_targets_from_body((string)($message['body'] ?? ''));
    $messages[$index]['target_type'] = $target['type'];
    $messages[$index]['target_account_ids'] = $target['account_ids'];
    $messages[$index]['attachments'] = parse_attachments_from_body((string)($message['body'] ?? ''));
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

$totalCount = count($messages);
$totalPages = max(1, (int)ceil($totalCount / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$messages = array_slice($messages, $offset, $perPage);

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
        'page' => (string)max(1, (int)($_GET['page'] ?? 1)),
    ];

    foreach ($overrides as $key => $value) {
        $params[$key] = (string)$value;
    }

    if (($params['page'] ?? '1') === '1') {
        unset($params['page']);
    }

    return http_build_query(array_filter($params, static fn(string $value): bool => $value !== ''));
}

include __DIR__ . '/header.php';
?>

<section class="card glass form-card">
  <h2>メッセージ検索</h2>
  <form method="get" class="admin-form horizontal-form search-row">
    <label>テキスト検索
      <input type="text" name="q" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>" placeholder="bodyを検索">
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
    <input type="hidden" name="room_id" value="<?php echo htmlspecialchars($selectedRoomId, ENT_QUOTES, 'UTF-8'); ?>">
    <button type="submit">検索</button>
  </form>

  <div class="icon-filter-row" aria-label="ルーム絞り込み">
    <a href="index.php?<?php echo htmlspecialchars(build_query(['room_id' => '', 'page' => '1']), ENT_QUOTES, 'UTF-8'); ?>" class="icon-filter-button <?php echo $selectedRoomId === '' ? 'active' : ''; ?>" title="すべて表示">
      すべて
    </a>
    <?php foreach ($rooms as $room): ?>
      <?php
        $roomId = (string)$room['room_id'];
        $roomName = (string)$room['room_name'];
        $iconPath = trim((string)($room['room_icon'] ?? ''));
      ?>
      <a
        href="index.php?<?php echo htmlspecialchars(build_query(['room_id' => $roomId, 'page' => '1']), ENT_QUOTES, 'UTF-8'); ?>"
        class="icon-filter-button <?php echo $selectedRoomId === $roomId ? 'active' : ''; ?>"
        title="<?php echo htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8'); ?>"
      >
        <img src="<?php echo htmlspecialchars($iconPath !== '' ? $iconPath : 'img/noimage.png', ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8'); ?>" onerror="this.onerror=null;this.src='img/noimage.png';">
      </a>
    <?php endforeach; ?>
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
    ?>
    <article class="card glass message-card <?php echo $isTaskDone ? 'is-complete' : ''; ?>" data-message-id="<?php echo (int)$message['id']; ?>">
      <img class="complete-stamp" src="img/complete.png" alt="完了" onerror="this.style.display='none';">
      <div class="card-head">
        <h2>#<?php echo (int)$message['id']; ?></h2>
        <div class="card-actions">
          <span class="badge"><?php echo htmlspecialchars($formattedSendTime, ENT_QUOTES, 'UTF-8'); ?></span>
          <button type="button" class="task-toggle" data-task-state="<?php echo $isTaskDone ? '1' : '0'; ?>"><?php echo $isTaskDone ? '取消' : '完了'; ?></button>
        </div>
      </div>

      <div class="message-meta-row">

        <div class="entity-chip" data-tooltip="<?php echo htmlspecialchars($roomLabel, ENT_QUOTES, 'UTF-8'); ?>">
          <strong>ルーム情報</strong>
          <img src="<?php echo htmlspecialchars($roomIcon !== '' ? $roomIcon : 'img/noimage.png', ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($roomLabel, ENT_QUOTES, 'UTF-8'); ?>" onerror="this.onerror=null;this.src='img/noimage.png';">

          <span><?php echo htmlspecialchars($roomLabel, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>

        <div class="entity-chip" data-tooltip="<?php echo htmlspecialchars($senderLabel, ENT_QUOTES, 'UTF-8'); ?>">
          <strong>送信者</strong>
          <img src="<?php echo htmlspecialchars($senderIcon !== '' ? $senderIcon : 'img/noimage.png', ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($senderLabel, ENT_QUOTES, 'UTF-8'); ?>" onerror="this.onerror=null;this.src='img/noimage.png';">
          <span><?php echo htmlspecialchars($senderLabel, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>

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

      <div class="message-body"><?php echo nl2br(htmlspecialchars((string)$message['body'], ENT_QUOTES, 'UTF-8')); ?></div>
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
  <?php endforeach; ?>
</section>

<?php if ($totalPages > 1): ?>
  <nav class="pager" aria-label="ページャー">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
      <a class="pager-link <?php echo $p === $page ? 'active' : ''; ?>" href="index.php?<?php echo htmlspecialchars(build_query(['page' => (string)$p]), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $p; ?></a>
    <?php endfor; ?>
  </nav>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>