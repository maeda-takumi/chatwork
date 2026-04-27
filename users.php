<?php
require_once __DIR__ . '/db.php';

$pdo = get_db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $userName = trim($_POST['user_name'] ?? '');
            $accountId = trim($_POST['account_id'] ?? '');
            $userIcon = trim($_POST['user_icon'] ?? '');

            if ($userName === '' || $accountId === '') {
                throw new RuntimeException('ユーザ名とaccount_idは必須です。');
            }

            $stmt = $pdo->prepare('INSERT INTO users (user_name, account_id, user_icon) VALUES (?, ?, ?)');
            $stmt->execute([$userName, $accountId, $userIcon]);
        }

        if ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $userName = trim($_POST['user_name'] ?? '');
            $accountId = trim($_POST['account_id'] ?? '');
            $userIcon = trim($_POST['user_icon'] ?? '');

            if ($id <= 0 || $userName === '' || $accountId === '') {
                throw new RuntimeException('更新に必要な値が不足しています。');
            }

            $stmt = $pdo->prepare('UPDATE users SET user_name = ?, account_id = ?, user_icon = ? WHERE id = ?');
            $stmt->execute([$userName, $accountId, $userIcon, $id]);
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([$id]);
            }
        }

        header('Location: users.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$users = $pdo->query('SELECT id, user_name, account_id, user_icon FROM users ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/header.php';
?>

<section class="card glass form-card">
  <h2>ユーザ追加</h2>
  <?php if ($error !== ''): ?>
    <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
  <?php endif; ?>
  <form method="post" class="admin-form horizontal-form">
    <input type="hidden" name="action" value="create">
    <label>ユーザ名<input type="text" name="user_name" required></label>
    <label>account_id<input type="text" name="account_id" required></label>
    <label>アイコンパス<input type="text" name="user_icon" placeholder="img/user.png"></label>
    <button type="submit">追加</button>
  </form>
</section>

<section class="cards" aria-label="ユーザ一覧">
  <?php foreach ($users as $user): ?>
    <article class="card glass card-with-icon">
      <?php if (trim((string)$user['user_icon']) !== ''): ?>
        <img
          class="entity-preview"
          src="<?php echo htmlspecialchars($user['user_icon'], ENT_QUOTES, 'UTF-8'); ?>"
          alt="<?php echo htmlspecialchars($user['user_name'], ENT_QUOTES, 'UTF-8'); ?>"
          onerror="this.onerror=null;this.src='img/noimage.png';"
        >
      <?php endif; ?>
      <div class="card-main">
        <h3>#<?php echo (int)$user['id']; ?></h3>
        <form method="post" class="admin-form inline-form horizontal-form">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">
          <label>ユーザ名<input type="text" name="user_name" value="<?php echo htmlspecialchars($user['user_name'], ENT_QUOTES, 'UTF-8'); ?>" required></label>
          <label>account_id<input type="text" name="account_id" value="<?php echo htmlspecialchars($user['account_id'], ENT_QUOTES, 'UTF-8'); ?>" required></label>
          <label>アイコンパス<input type="text" name="user_icon" value="<?php echo htmlspecialchars($user['user_icon'], ENT_QUOTES, 'UTF-8'); ?>"></label>
          <div class="form-actions">
            <button type="submit">更新</button>
          </div>
        </form>
        <form method="post" onsubmit="return confirm('このユーザを削除しますか？');">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">
          <button type="submit" class="danger">削除</button>
        </form>
      </div>
    </article>
  <?php endforeach; ?>
</section>

<?php include __DIR__ . '/footer.php'; ?>
