<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/chatwork_files_service.php';

$pdo = get_db();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim((string)($_POST['chatwork_api_token'] ?? ''));
    try {
        save_chatwork_api_token($pdo, $token);
        $success = 'APIトークンを保存しました。';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$token = get_chatwork_api_token($pdo);
$maskedToken = $token !== '' ? str_repeat('*', max(strlen($token) - 4, 0)) . substr($token, -4) : '';

if (isset($_GET['download']) && $_GET['download'] === '1') {
    $roomId = (int)($_GET['room_id'] ?? 0);
    $fileId = trim((string)($_GET['file_id'] ?? ''));

    if ($token === '') {
        header('Location: attachments.php?error=' . urlencode('APIトークンが未設定です。'));
        exit;
    }

    if ($roomId <= 0 || $fileId === '') {
        header('Location: attachments.php?error=' . urlencode('ダウンロード情報が不正です。'));
        exit;
    }

    try {
        $downloadUrl = fetch_download_url($roomId, $fileId, $token);
        if ($downloadUrl === null) {
            throw new RuntimeException('ダウンロードURLを取得できませんでした。');
        }

        header('Location: ' . $downloadUrl);
        exit;
    } catch (Throwable $e) {
        header('Location: attachments.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

$queryError = trim((string)($_GET['error'] ?? ''));
if ($queryError !== '') {
    $error = $queryError;
}

$messages = $pdo->query(
    'SELECT
        m.id,
        m.room_id,
        m.message_id,
        m.send_time,
        m.body,
        r.room_name,
        u.user_name
     FROM message m
     LEFT JOIN room r ON CAST(m.room_id AS TEXT) = r.room_id
     LEFT JOIN users u ON m.account_id = u.account_id
     WHERE m.message_id IS NOT NULL AND TRIM(m.message_id) <> ""
     ORDER BY COALESCE(m.send_time, "") DESC, m.id DESC
     LIMIT 100'
)->fetchAll(PDO::FETCH_ASSOC);

$attachmentRows = [];
if ($token !== '') {
    foreach ($messages as $message) {
        $roomId = (int)($message['room_id'] ?? 0);
        $messageId = trim((string)($message['message_id'] ?? ''));
        if ($roomId <= 0 || $messageId === '') {
            continue;
        }

        try {
            $files = fetch_message_files($roomId, $messageId, $token);
            foreach ($files as $file) {
                $attachmentRows[] = [
                    'message_db_id' => (int)$message['id'],
                    'room_id' => $roomId,
                    'room_name' => (string)($message['room_name'] ?? ''),
                    'user_name' => (string)($message['user_name'] ?? ''),
                    'message_id' => $messageId,
                    'send_time' => (string)($message['send_time'] ?? ''),
                    'body' => (string)($message['body'] ?? ''),
                    'file_id' => (string)($file['file_id'] ?? ''),
                    'file_name' => (string)($file['filename'] ?? ''),
                    'file_size' => (int)($file['filesize'] ?? 0),
                    'upload_time' => (string)($file['upload_time'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            break;
        }
    }
}

function format_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '-';
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $value = $bytes;
    $unitIndex = 0;
    while ($value >= 1024 && $unitIndex < count($units) - 1) {
        $value /= 1024;
        $unitIndex++;
    }

    return number_format($value, 2) . ' ' . $units[$unitIndex];
}

include __DIR__ . '/header.php';
?>

<section class="card glass form-card">
  <h2>添付ファイル設定</h2>
  <?php if ($error !== ''): ?>
    <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
  <?php endif; ?>
  <?php if ($success !== ''): ?>
    <p><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></p>
  <?php endif; ?>

  <form method="post" class="admin-form horizontal-form">
    <label>Chatwork APIトークン
      <input type="password" name="chatwork_api_token" placeholder="X-ChatWorkToken" value="">
    </label>
    <button type="submit">保存</button>
  </form>
  <?php if ($maskedToken !== ''): ?>
    <p>現在の設定: <?php echo htmlspecialchars($maskedToken, ENT_QUOTES, 'UTF-8'); ?></p>
  <?php else: ?>
    <p>トークン未設定です。保存後に添付一覧を取得できます。</p>
  <?php endif; ?>
</section>

<section class="card glass form-card">
  <h2>添付ファイル一覧</h2>
  <p>Webhookで保存された message_id を使って Chatwork API から添付を取得しています。</p>

  <?php if ($token === ''): ?>
    <p>APIトークンを保存すると一覧を表示できます。</p>
  <?php elseif (empty($attachmentRows)): ?>
    <p>添付ファイルは見つかりませんでした。</p>
  <?php else: ?>
    <div class="attachment-table-wrap">
      <table class="attachment-table">
        <thead>
          <tr>
            <th>ファイル名</th>
            <th>サイズ</th>
            <th>ルーム</th>
            <th>送信者</th>
            <th>message_id</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($attachmentRows as $row): ?>
            <tr>
              <td><?php echo htmlspecialchars($row['file_name'] !== '' ? $row['file_name'] : ('file_id: ' . $row['file_id']), ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars(format_bytes((int)$row['file_size']), ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($row['room_name'] !== '' ? $row['room_name'] : ('room_id: ' . $row['room_id']), ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($row['user_name'] !== '' ? $row['user_name'] : '-', ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars((string)$row['message_id'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td>
                <a class="download-link" href="attachments.php?download=1&amp;room_id=<?php echo (int)$row['room_id']; ?>&amp;file_id=<?php echo urlencode((string)$row['file_id']); ?>">ダウンロード</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php include __DIR__ . '/footer.php'; ?>
