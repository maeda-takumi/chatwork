<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$pdo = get_db();
app_start_session();

$error = '';
$redirectTo = app_safe_redirect_path((string)($_GET['redirect'] ?? $_POST['redirect'] ?? 'index.php'));
$users = $pdo->query('SELECT account_id, user_name, user_icon FROM users ORDER BY user_name ASC')->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accountId = trim((string)($_POST['viewer_account_id'] ?? ''));
    $viewer = app_find_viewer($pdo, $accountId);

    if ($viewer === null) {
        $error = '閲覧者を選択してください。';
    } else {
        session_regenerate_id(true);
        app_set_viewer_account_id($accountId);
        header('Location: ' . $redirectTo);
        exit;
    }
}

include __DIR__ . '/header.php';
?>

<section class="card glass form-card login-card">
  <h2>閲覧者を選択</h2>
  <p>このアプリを利用するには、最初に閲覧者（ログイン者）を選択してください。選択内容はセッションに保存され、ログアウトするまで再選択は不要です。</p>
  <?php if ($error !== ''): ?>
    <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
  <?php endif; ?>
  <?php if ($users === []): ?>
    <p class="error">ユーザが登録されていません。先にユーザを登録してください。</p>
  <?php else: ?>
    <form method="post" class="admin-form horizontal-form login-form">
      <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirectTo, ENT_QUOTES, 'UTF-8'); ?>">
      <label>閲覧者
        <select name="viewer_account_id" required autofocus>
          <option value="">選択してください</option>
          <?php foreach ($users as $user): ?>
            <?php
              $accountId = (string)($user['account_id'] ?? '');
              $userName = trim((string)($user['user_name'] ?? '')) ?: ('account_id: ' . $accountId);
            ?>
            <option value="<?php echo htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8'); ?>">
              <?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit">ログイン</button>
    </form>
  <?php endif; ?>
</section>

<?php include __DIR__ . '/footer.php'; ?>
