<?php
if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">
  <title>ChatSave</title>
  <link rel="stylesheet" href="style/style.css?v=<?php echo time(); ?>">
  <link rel="icon" href="img/icon.png?v=<?php echo time(); ?>" type="image/png">
  <link rel="apple-touch-icon" href="img/icon.png?v=<?php echo time(); ?>">
</head>
<body>
  <div class="bg-gradient"></div>
  <header class="site-header glass">
    <div class="header-top">
      <div class="header-title">
        <img src="img/icon.png?v=<?php echo time(); ?>" alt="ChatSave" class="header-icon">
        <h1>ChatSave</h1>
      </div>
      <nav class="header-nav" aria-label="メインナビゲーション">
        <a href="index.php">ダッシュボード</a>
        <a href="list.php">一覧</a>
        <a href="users.php">ユーザ追加</a>
        <a href="rooms.php">ルーム追加</a>
        <!-- <a href="attachments.php">添付一覧</a> -->
      </nav>
    </div>
  </header>
  <main class="container">