<?php
require_once __DIR__ . '/db.php';

$pdo = get_db();
$q = trim((string)($_GET['q'] ?? ''));
$selectedRoomId = trim((string)($_GET['room_id'] ?? ''));

$rooms = $pdo->query('SELECT id, room_id, room_name, room_icon FROM room ORDER BY room_name ASC')->fetchAll(PDO::FETCH_ASSOC);

$sql = <<<'SQL'
SELECT
    m.id,
    m.room_id,
    m.account_id,
    m.body,
    m.send_time,
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

/**
 * メッセージ本文から [to:account_id] を抽出する。
 * Chatworkの表記ゆれ（[To:...] など）にも対応する。
 */
function extract_target_account_id(string $body): string
{
    if (preg_match('/\[to:\s*([^\]\s]+)\]/i', $body, $matches)) {
        return (string)$matches[1];
    }

    return '';
}
$targetAccountIds = [];
foreach ($messages as $message) {
    $targetAccountId = extract_target_account_id((string)($message['body'] ?? ''));
    if ($targetAccountId !== '') {
        $targetAccountIds[$targetAccountId] = true;
    }
}

$targetUsersByAccountId = [];
if (!empty($targetAccountIds)) {
    $placeholders = implode(',', array_fill(0, count($targetAccountIds), '?'));
    $targetStmt = $pdo->prepare("SELECT account_id, user_name, user_icon FROM users WHERE account_id IN ($placeholders)");
    $targetStmt->execute(array_keys($targetAccountIds));

    foreach ($targetStmt->fetchAll(PDO::FETCH_ASSOC) as $targetUser) {
        $targetUsersByAccountId[(string)$targetUser['account_id']] = $targetUser;
    }
}

include __DIR__ . '/header.php';
?>

<section class="card glass form-card">
  <h2>メッセージ検索</h2>
  <form method="get" class="admin-form horizontal-form search-row">
    <label>テキスト検索
      <input type="text" name="q" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>" placeholder="bodyを検索">
    </label>
    <input type="hidden" name="room_id" value="<?php echo htmlspecialchars($selectedRoomId, ENT_QUOTES, 'UTF-8'); ?>">
    <button type="submit">検索</button>
  </form>

  <div class="icon-filter-row" aria-label="ルーム絞り込み">
    <a href="index.php?q=<?php echo urlencode($q); ?>" class="icon-filter-button <?php echo $selectedRoomId === '' ? 'active' : ''; ?>" title="すべて表示">
      すべて
    </a>
    <?php foreach ($rooms as $room): ?>
      <?php
        $roomId = (string)$room['room_id'];
        $roomName = (string)$room['room_name'];
        $iconPath = trim((string)($room['room_icon'] ?? ''));
      ?>
      <a
        href="index.php?q=<?php echo urlencode($q); ?>&room_id=<?php echo urlencode($roomId); ?>"
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

      $targetAccountId = extract_target_account_id((string)($message['body'] ?? ''));

      $targetUser = $targetAccountId !== '' ? ($targetUsersByAccountId[$targetAccountId] ?? null) : null;
      $targetName = trim((string)($targetUser['user_name'] ?? ''));
      $targetLabel = $targetName !== ''
          ? $targetName
          : ($targetAccountId !== '' ? ('account_id: ' . $targetAccountId) : '対象者なし');
      $targetIcon = trim((string)($targetUser['user_icon'] ?? ''));

      $rawSendTime = trim((string)($message['send_time'] ?? ''));
      $timestamp = strtotime($rawSendTime);
      $formattedSendTime = $rawSendTime;
      if ($timestamp !== false) {
          $formattedSendTime = date('Y/m/d H:i:s', $timestamp);
      }
    ?>
    <article class="card glass">
      <div class="card-head">
        <h2>#<?php echo (int)$message['id']; ?></h2>
        <span class="badge"><?php echo htmlspecialchars($formattedSendTime, ENT_QUOTES, 'UTF-8'); ?></span>
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

        <div class="entity-chip" data-tooltip="<?php echo htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8'); ?>">
          <strong>対象者</strong>
          <img src="<?php echo htmlspecialchars($targetIcon !== '' ? $targetIcon : 'img/noimage.png', ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8'); ?>" onerror="this.onerror=null;this.src='img/noimage.png';">
          <span><?php echo htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8'); ?></span>        </div>
      </div>

      <div class="message-body"><?php echo nl2br(htmlspecialchars((string)$message['body'], ENT_QUOTES, 'UTF-8')); ?></div>
    </article>
  <?php endforeach; ?>
</section>

<?php include __DIR__ . '/footer.php'; ?>