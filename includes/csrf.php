<?php

/**
 * Generate a CSRF token or return the existing one from the session.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Return an HTML hidden input field containing the CSRF token.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verify the CSRF token on POST requests.
 * Sends HTTP 403 and terminates execution if the token is invalid.
 */
function csrf_verify(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('Ungueltige Anfrage. Bitte lade die Seite neu und versuche es erneut.');
    }
}
