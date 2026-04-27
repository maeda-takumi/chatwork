<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function get_chatwork_api_token(PDO $pdo): string
{
    $envToken = trim((string)getenv('CHATWORK_API_TOKEN'));
    if ($envToken !== '') {
        return $envToken;
    }

    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key LIMIT 1');
    $stmt->execute([':key' => 'chatwork_api_token']);
    $value = $stmt->fetchColumn();

    return is_string($value) ? trim($value) : '';
}

function save_chatwork_api_token(PDO $pdo, string $token): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value)
         VALUES (:key, :value)
         ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value'
    );

    $stmt->execute([
        ':key' => 'chatwork_api_token',
        ':value' => $token,
    ]);
}

function fetch_message_files(int $roomId, string $messageId, string $token): array
{
    $response = call_chatwork_api(sprintf('/rooms/%d/messages/%s/files', $roomId, rawurlencode($messageId)), $token);

    if (!is_array($response)) {
        return [];
    }

    return array_values(array_filter($response, static fn($row): bool => is_array($row)));
}

function fetch_download_url(int $roomId, string $fileId, string $token): ?string
{
    $response = call_chatwork_api(
        sprintf('/rooms/%d/files/%s', $roomId, rawurlencode($fileId)),
        $token,
        ['create_download_url' => '1']
    );

    if (!is_array($response)) {
        return null;
    }

    $downloadUrl = $response['download_url'] ?? null;
    if (!is_string($downloadUrl) || trim($downloadUrl) === '') {
        return null;
    }

    return $downloadUrl;
}

function call_chatwork_api(string $path, string $token, array $query = []): array
{
    $baseUrl = 'https://api.chatwork.com/v2';
    $url = rtrim($baseUrl, '/') . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Chatwork APIの初期化に失敗しました。');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'X-ChatWorkToken: ' . $token,
            'Accept: application/json',
        ],
    ]);

    $rawResponse = curl_exec($ch);
    if ($rawResponse === false) {
        $errorMessage = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Chatwork API通信エラー: ' . $errorMessage);
    }

    $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($rawResponse, true);
    if ($statusCode >= 400) {
        $apiError = '';
        if (is_array($decoded) && isset($decoded['errors']) && is_array($decoded['errors'])) {
            $apiError = implode(' / ', array_map(static fn($item): string => (string)$item, $decoded['errors']));
        }

        $suffix = $apiError !== '' ? ' - ' . $apiError : '';
        throw new RuntimeException('Chatwork APIエラー (' . $statusCode . ')' . $suffix);
    }

    if (!is_array($decoded)) {
        return [];
    }

    return $decoded;
}
