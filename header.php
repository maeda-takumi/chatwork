<?php
if (!function_exists('h')) {
    function h(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tme')) {
    function tme(string $path): string
    {
        $fullPath = __DIR__ . '/' . ltrim($path, '/');
        return file_exists($fullPath) ? (string)filemtime($fullPath) : (string)time();
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Webhook Viewer</title>
    <link rel="stylesheet" href="style/style.css?v=<?php echo h(tme('style/style.css')); ?>">
</head>
<body>
<div class="app-shell">
    <header class="site-header">
        <div class="site-header-inner">
            <div class="site-brand">
                <span class="site-brand-mark"></span>
                <span class="site-brand-text">Webhook Viewer</span>
            </div>
        </div>
    </header>

    <main class="site-main">