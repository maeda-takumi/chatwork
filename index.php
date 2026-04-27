<?php
$dir = __DIR__ . '/data';
$dbFiles = [];

if (is_dir($dir)) {
    $entries = scandir($dir);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;
        if (is_file($path) && preg_match('/\.(db|sqlite|sqlite3)$/i', $entry)) {
            $dbFiles[] = [
                'name' => $entry,
                'relative_path' => 'data/' . $entry,
                'absolute_path' => realpath($path) ?: $path,
                'size' => filesize($path),
                'updated_at' => filemtime($path),
            ];
        }
    }
}

include __DIR__ . '/header.php';
?>

<section class="cards" aria-label="DBファイル一覧">
  <?php if (empty($dbFiles)): ?>
    <article class="card glass">
      <h2>DBファイルが見つかりません</h2>
      <p><code>data/</code> に <code>.db</code> / <code>.sqlite</code> / <code>.sqlite3</code> を追加してください。</p>
    </article>
  <?php else: ?>
    <?php foreach ($dbFiles as $index => $file): ?>
      <article class="card glass" style="--delay: <?php echo $index * 80; ?>ms">
        <div class="card-head">
          <h2><?php echo htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
          <span class="badge">DB</span>
        </div>
        <dl>
          <div>
            <dt>位置（相対パス）</dt>
            <dd><code><?php echo htmlspecialchars($file['relative_path'], ENT_QUOTES, 'UTF-8'); ?></code></dd>
          </div>
          <div>
            <dt>位置（絶対パス）</dt>
            <dd><code><?php echo htmlspecialchars($file['absolute_path'], ENT_QUOTES, 'UTF-8'); ?></code></dd>
          </div>
          <div>
            <dt>サイズ</dt>
            <dd><?php echo number_format((float)$file['size'] / 1024, 2); ?> KB</dd>
          </div>
          <div>
            <dt>最終更新</dt>
            <dd><?php echo date('Y-m-d H:i:s', (int)$file['updated_at']); ?></dd>
          </div>
        </dl>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<?php include __DIR__ . '/footer.php'; ?>