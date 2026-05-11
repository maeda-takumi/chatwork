<?php

declare(strict_types=1);

function app_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function app_current_viewer_account_id(): string
{
    app_start_session();
    return trim((string)($_SESSION['viewer_account_id'] ?? ''));
}

function app_set_viewer_account_id(string $accountId): void
{
    app_start_session();
    $_SESSION['viewer_account_id'] = trim($accountId);
}

function app_clear_viewer(): void
{
    app_start_session();
    unset($_SESSION['viewer_account_id']);
}

function app_find_viewer(PDO $pdo, string $accountId): ?array
{
    $accountId = trim($accountId);
    if ($accountId === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT account_id, user_name, user_icon FROM users WHERE account_id = :account_id LIMIT 1');
    $stmt->execute([':account_id' => $accountId]);
    $viewer = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($viewer) ? $viewer : null;
}

function app_redirect_to_login(): void
{
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $redirect = $requestUri !== '' ? '?redirect=' . rawurlencode($requestUri) : '';
    header('Location: login.php' . $redirect);
    exit;
}

function app_require_viewer(PDO $pdo): array
{
    $viewer = app_find_viewer($pdo, app_current_viewer_account_id());
    if ($viewer === null) {
        app_clear_viewer();
        app_redirect_to_login();
    }

    return $viewer;
}

function app_safe_redirect_path(string $redirect): string
{
    $redirect = trim($redirect);
    if ($redirect === '' || str_starts_with($redirect, '//')) {
        return 'index.php';
    }

    $parts = parse_url($redirect);
    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
        return 'index.php';
    }

    $path = (string)($parts['path'] ?? '');
    if ($path === '' || basename($path) === 'login.php' || basename($path) === 'logout.php') {
        return 'index.php';
    }

    return $redirect;
}
