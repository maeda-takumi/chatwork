<?php
declare(strict_types=1);

const CHATWORK_API_KEY = 'fee574510c5ce22d78b85282a0a8acaa';
const CHATWORK_API_BASE_URL = 'https://api.chatwork.com/v2';

function get_chatwork_file_download_url(int $roomId, string $fileId): string
{
    if ($roomId <= 0) {
        throw new InvalidArgumentException('room_idが不正です。');
    }

    $fileId = trim($fileId);
    if ($fileId === '') {
        throw new InvalidArgumentException('file_idが不正です。');
    }

    if (CHATWORK_API_KEY === '') {
        throw new RuntimeException('APIキーが未設定です。');
    }

    $path = sprintf('/rooms/%d/files/%s', $roomId, rawurlencode($fileId));
    $response = call_chatwork_download_api($path, ['create_download_url' => '1']);

    $downloadUrl = $response['download_url'] ?? null;
    if (!is_string($downloadUrl) || trim($downloadUrl) === '') {
        throw new RuntimeException('ダウンロードURLの取得に失敗しました。');
    }

    return $downloadUrl;
}

function call_chatwork_download_api(string $path, array $query = []): array
{
    $url = rtrim(CHATWORK_API_BASE_URL, '/') . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('API通信の初期化に失敗しました。');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'X-ChatWorkToken: ' . CHATWORK_API_KEY,
            'Accept: application/json',
        ],
    ]);

    $rawResponse = curl_exec($ch);
    if ($rawResponse === false) {
        $errorMessage = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('API通信に失敗しました: ' . $errorMessage);
    }

    $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($rawResponse, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    if ($statusCode >= 400) {
        $apiErrors = $decoded['errors'] ?? [];
        $message = is_array($apiErrors) ? implode(' / ', array_map(static fn($item): string => (string)$item, $apiErrors)) : '';
        throw new RuntimeException('APIエラー(' . $statusCode . ')' . ($message !== '' ? ': ' . $message : ''));
    }

    return $decoded;
}
