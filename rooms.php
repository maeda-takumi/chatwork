<?php
require_once __DIR__ . '/db.php';

$pdo = get_db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
            $roomName = trim($_POST['room_name'] ?? '');
            $roomId = trim($_POST['room_id'] ?? '');
            $roomIcon = trim($_POST['room_icon'] ?? '');

            if ($roomName === '' || $roomId === '') {
                throw new RuntimeException('ルーム名とroom_idは必須です。');
            }

            $stmt = $pdo->prepare('INSERT INTO room (room_name, room_id, room_icon) VALUES (?, ?, ?)');
            $stmt->execute([$roomName, $roomId, $roomIcon]);
        }

        if ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $roomName = trim($_POST['room_name'] ?? '');
            $roomId = trim($_POST['room_id'] ?? '');
            $roomIcon = trim($_POST['room_icon'] ?? '');

            if ($id <= 0 || $roomName === '' || $roomId === '') {
                throw new RuntimeException('更新に必要な値が不足しています。');
            }

            $stmt = $pdo->prepare('UPDATE room SET room_name = ?, room_id = ?, room_icon = ? WHERE id = ?');
            $stmt->execute([$roomName, $roomId, $roomIcon, $id]);
        }

        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare('DELETE FROM room WHERE id = ?');
                $stmt->execute([$id]);
            }
        }

        header('Location: rooms.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$rooms = $pdo->query('SELECT id, room_name, room_id, room_icon FROM room ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/header.php';
?>

<section class="card glass form-card">
  <h2>ルーム追加</h2>
  <?php if ($error !== ''): ?>
    <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
  <?php endif; ?>
  <form method="post" class="admin-form">
    <input type="hidden" name="action" value="create">
    <label>ルーム名<input type="text" name="room_name" required></label>
    <label>room_id<input type="text" name="room_id" required></label>
    <label>アイコンパス<input type="text" name="room_icon" placeholder="img/room.png"></label>
    <button type="submit">追加</button>
  </form>
</section>

<section class="cards" aria-label="ルーム一覧">
  <?php foreach ($rooms as $room): ?>
    <article class="card glass">
      <h3>#<?php echo (int)$room['id']; ?></h3>
      <form method="post" class="admin-form inline-form">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?php echo (int)$room['id']; ?>">
        <label>ルーム名<input type="text" name="room_name" value="<?php echo htmlspecialchars($room['room_name'], ENT_QUOTES, 'UTF-8'); ?>" required></label>
        <label>room_id<input type="text" name="room_id" value="<?php echo htmlspecialchars($room['room_id'], ENT_QUOTES, 'UTF-8'); ?>" required></label>
        <label>アイコンパス<input type="text" name="room_icon" value="<?php echo htmlspecialchars($room['room_icon'], ENT_QUOTES, 'UTF-8'); ?>"></label>
        <div class="form-actions">
          <button type="submit">更新</button>
        </div>
      </form>
      <form method="post" onsubmit="return confirm('このルームを削除しますか？');">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?php echo (int)$room['id']; ?>">
        <button type="submit" class="danger">削除</button>
      </form>
    </article>
  <?php endforeach; ?>
</section>

<?php include __DIR__ . '/footer.php'; ?>
