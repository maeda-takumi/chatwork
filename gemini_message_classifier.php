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
あなたは業務チャットの高精度トリアージ分類器です。
次のメッセージを、意味と話者の意図に基づいて「必ず1つだけ」分類してください。
キーワードの有無ではなく、依頼の有無・返答期待・情報価値を優先して判断してください。
「不明」は最後の手段です。日本語として読めて意図が推測できる場合は、必ず他の分類を選んでください。

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

判定手順（上から順に優先して判定）:
1. 明確な依頼・指示・作業要求がある → 要対応
2. 相手からの回答を求める質問が中心 → 要返信
3. 「確認お願いします」「認識あってますか」など確認行為を求める → 要確認
4. 「完了しました」「対応済みです」など完了通知が中心 → 完了報告
5. 進捗・実施内容・結果の報告が中心（完了通知に限らない） → 報告
6. 周知・案内・資料展開・共有が中心で、対応要求や質問はない → 共有
7. 感謝・相槌・了解・スタンプ相当の短文 → お礼・リアクション
8. 上記に当てはまらない軽い会話 → 雑談
9. 文字化け・意味不明・情報不足で意図が読めない場合のみ → 不明

追加ルール:
- 複数要素がある場合は「受信者に次の行動が発生する要素」を優先する（要対応 > 要返信 > 要確認 > それ以外）。
- 文末が丁寧でも、実質が依頼なら要対応。実質が質問なら要返信。
- 「確認お願いします」は要返信ではなく要確認。
- 「ありがとうございます。対応完了しました」は完了報告を優先。
- URLや資料送付のみで要求がなければ共有。

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