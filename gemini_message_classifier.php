<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
const GEMINI_MODEL = 'gemma-3-27b-it';
const GEMINI_API_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

const GEMINI_ERROR_LOG_FILE = __DIR__ . '/webhook_error.log';
/**
 * メッセージ本文をタイプ名に分類する。
 * API呼び出しに失敗した場合は「不明」を返す。
 */
function classify_message_type_name(string $messageBody): string
{
    $messageBody = trim($messageBody);
    if ($messageBody === '') {
        logGeminiError('gemini_message_empty');
        return '不明';
    }

    $apiKey = getGeminiApiKey();
    if ($apiKey === '' || $apiKey === 'DUMMY_GEMINI_API_KEY') {
        logGeminiError('gemini_api_key_missing_or_dummy');
        return '不明';
    }

    $prompt = buildClassificationPrompt($messageBody);
    $response = callGeminiApi($prompt, $apiKey);
    if (!is_array($response)) {
        return '不明';
    }

    $text = extractCandidateText($response);
    if ($text === null) {
        logGeminiError('gemini_api_empty_candidate_text', [
            'response_excerpt' => mb_substr(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 0, 500),
        ]);
        return '不明';
    }

    $decoded = json_decode($text, true);
    if (!is_array($decoded)) {
        return normalizeTypeName($text);
    }

    return normalizeTypeName((string)($decoded['type'] ?? '不明'));
}

function buildClassificationPrompt(string $messageBody): string
{
    return <<<PROMPT
次のメッセージを意味ベースで1つだけ分類してください。
キーワード一致ではなく、文脈と意図で判断してください。
「不明」は最後の手段です。内容が日本語として読めて意図が推測できる場合は、必ず他の分類を選んでください。

分類候補:
- 要対応
- 要返信
- 要確認
- 報告
- 共有
- 完了報告
- お礼・リアクション
- 雑談
- 不明

判定ルール:
- 依頼・指示・対応要求（「お願いします」「送付して」「対応ください」など）: 要対応
- 質問・返答待ち: 要返信
- 確認依頼・確認中共有: 要確認
- 進捗や実施内容の連絡: 報告
- 情報展開・案内・ログイン情報共有: 共有
- 完了した旨の連絡: 完了報告
- 感謝・称賛・スタンプ的短文（「ありがとう」「いいね！」など）: お礼・リアクション
- 軽い会話・業務外雑談: 雑談
- 文字化け・意味不明・判定不能な断片のみ: 不明

出力形式(JSONのみ):
{"type":"分類名"}

メッセージ:
{$messageBody}
PROMPT;
}

function getGeminiApiKey(): string
{
    if (!defined('GEMINI_API_KEY')) {
        logGeminiError('gemini_api_key_constant_not_defined');
        return '';
    }

    return trim((string)constant('GEMINI_API_KEY'));
}

function callGeminiApi(string $prompt, string $apiKey): ?array
{
    $url = GEMINI_API_ENDPOINT . rawurlencode(GEMINI_MODEL) . ':generateContent?key=' . rawurlencode($apiKey);

    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt],
                ],
            ],
        ],
        'generationConfig' => [
            'temperature' => 0.1,
        ],
    ];
    if (supportsJsonMode(GEMINI_MODEL)) {
        $payload['generationConfig']['responseMimeType'] = 'application/json';
    }

    return requestGeminiApi($url, $payload);
}

function supportsJsonMode(string $model): bool
{
    $normalized = strtolower(trim($model));

    // 2026-04 時点で gemma 系は JSON mode 非対応のため除外する。
    return str_starts_with($normalized, 'gemini');
}

function requestGeminiApi(string $url, array $payload): ?array
{

    $ch = curl_init($url);
    if ($ch === false) {
        logGeminiError('curl_init_failed', [
            'url' => $url,
        ]);
        return null;
    }
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($jsonPayload) || $jsonPayload === '') {
        curl_close($ch);
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_TIMEOUT => 10,
    ]);

    $result = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if (!is_string($result) || $result === '' || $statusCode < 200 || $statusCode >= 300) {
        logGeminiError('gemini_api_request_failed', [
            'url' => $url,
            'status_code' => $statusCode,
            'curl_errno' => $curlErrno,
            'curl_error' => $curlError,
            'response_excerpt' => is_string($result) ? mb_substr($result, 0, 500) : null,
        ]);
        return null;
    }

    $decoded = json_decode($result, true);
    if (!is_array($decoded)) {
        logGeminiError('gemini_api_invalid_json', [
            'url' => $url,
            'status_code' => $statusCode,
            'response_excerpt' => mb_substr($result, 0, 500),
        ]);
        return null;
    }

    if (isset($decoded['error']) && is_array($decoded['error'])) {
        logGeminiError('gemini_api_error_response', [
            'url' => $url,
            'status_code' => $statusCode,
            'api_error' => $decoded['error'],
        ]);
        return null;
    }

    return $decoded;
}

function logGeminiError(string $errorCode, array $context = []): void
{
    $record = [
        'timestamp' => gmdate('c'),
        'error' => $errorCode,
        'component' => 'gemini_message_classifier',
        'context' => $context,
    ];

    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($line)) {
        $line = '{"timestamp":"' . gmdate('c') . '","error":"json_encode_failed","component":"gemini_message_classifier"}';
    }

    error_log($line . PHP_EOL, 3, GEMINI_ERROR_LOG_FILE);
}

function extractCandidateText(array $response): ?string
{
    $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!is_string($text)) {
        return null;
    }

    $trimmed = trim($text);
    return $trimmed === '' ? null : $trimmed;
}

function normalizeTypeName(string $typeName): string
{
    $typeName = sanitizeTypeName($typeName);
    $allowed = [
        '要対応',
        '要返信',
        '要確認',
        '報告',
        '共有',
        '完了報告',
        'お礼・リアクション',
        '雑談',
        '不明',
    ];

    $typeName = trim($typeName);
    if (in_array($typeName, $allowed, true)) {
        return $typeName;
    }

    $aliases = [
        '対応依頼' => '要対応',
        '要対応事項' => '要対応',
        '返信要' => '要返信',
        '返信必要' => '要返信',
        '確認依頼' => '要確認',
        '確認事項' => '要確認',
        '進捗報告' => '報告',
        '連絡' => '共有',
        '情報共有' => '共有',
        '共有事項' => '共有',
        '完了' => '完了報告',
        '完了連絡' => '完了報告',
        'お礼' => 'お礼・リアクション',
        'リアクション' => 'お礼・リアクション',
        '感謝' => 'お礼・リアクション',
        '雑談・その他' => '雑談',
        'その他' => '雑談',
        '未知' => '不明',
    ];
    if (isset($aliases[$typeName])) {
        return $aliases[$typeName];
    }

    return '不明';
}
function sanitizeTypeName(string $rawTypeName): string
{
    $typeName = trim($rawTypeName);
    if ($typeName === '') {
        return '';
    }

    $typeName = preg_replace('/^```(?:json)?/u', '', $typeName) ?? $typeName;
    $typeName = preg_replace('/```$/u', '', $typeName) ?? $typeName;
    $typeName = trim($typeName);

    if (str_contains($typeName, "\n")) {
        $lines = preg_split('/\R/u', $typeName);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line !== '') {
                    $typeName = $line;
                    break;
                }
            }
        }
    }

    return trim($typeName, " \t\n\r\0\x0B\"'{}[]");
}