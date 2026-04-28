<?php
declare(strict_types=1);

const GEMINI_API_KEY = 'AIzaSyAFVTnrJn97x9FmS-qUK7fCDNnDIhL2cso';
const GEMINI_MODEL = 'gemma-3-27b-it';
const GEMINI_API_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

/**
 * メッセージ本文をタイプ名に分類する。
 * API呼び出しに失敗した場合は「不明」を返す。
 */
function classify_message_type_name(string $messageBody): string
{
    $messageBody = trim($messageBody);
    if ($messageBody === '') {
        return '不明';
    }

    if (GEMINI_API_KEY === '' || GEMINI_API_KEY === 'DUMMY_GEMINI_API_KEY') {
        return '不明';
    }

    $prompt = buildClassificationPrompt($messageBody);
    $response = callGeminiApi($prompt);
    if (!is_array($response)) {
        return '不明';
    }

    $text = extractCandidateText($response);
    if ($text === null) {
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

出力形式(JSONのみ):
{"type":"分類名"}

メッセージ:
{$messageBody}
PROMPT;
}

function callGeminiApi(string $prompt): ?array
{
    $url = GEMINI_API_ENDPOINT . rawurlencode(GEMINI_MODEL) . ':generateContent?key=' . rawurlencode(GEMINI_API_KEY);

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
            'responseMimeType' => 'application/json',
        ],
    ];

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 10,
    ]);

    $result = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($result) || $result === '' || $statusCode < 200 || $statusCode >= 300) {
        return null;
    }

    $decoded = json_decode($result, true);
    return is_array($decoded) ? $decoded : null;
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

    return '不明';
}