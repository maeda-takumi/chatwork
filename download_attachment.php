<?php
declare(strict_types=1);

require_once __DIR__ . '/chatwork_download_api.php';

$roomId = (int)($_GET['room_id'] ?? 0);
$fileId = trim((string)($_GET['file_id'] ?? ''));

if ($roomId <= 0 || $fileId === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'ダウンロード情報が不正です。';
    exit;
}

try {
    $downloadUrl = get_chatwork_file_download_url($roomId, $fileId);
    header('Location: ' . $downloadUrl);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'ダウンロードURLの取得に失敗しました。';
    exit;
}
