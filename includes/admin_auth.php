<?php
/**
 * FabLab Workshop-Portal - Centralized Admin Authentication
 *
 * Provides admin_require_login(), admin_ist_eingeloggt(), admin_login()
 * and admin_logout() for all admin pages.
 * Constants used (defined in config.php):
 *   ADMIN_SESSION_TIMEOUT, MAX_LOGIN_ATTEMPTS, LOGIN_LOCKOUT_SECONDS,
 *   ADMIN_USERNAME, ADMIN_PASSWORD_HASH
 */

require_once __DIR__ . '/Fehlversuche.php';

/**
 * Obergrenze für Fehlversuche über alle IP-Adressen zusammen
 * (Schutz gegen verteiltes Durchprobieren).
 */
const ADMIN_MAX_LOGIN_ATTEMPTS_GESAMT = 50;

/**
 * Check whether an admin session is active and not idle for too long.
 * Refreshes the activity timestamp on success.
 */
function admin_ist_eingeloggt(): bool
{
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        return false;
    }

    // Abmeldung nach Untätigkeit, nicht nach fester Zeit ab Anmeldung
    $letzteAktivitaet = $_SESSION['admin_last_activity'] ?? $_SESSION['admin_login_time'] ?? 0;
    if ((time() - $letzteAktivitaet) > ADMIN_SESSION_TIMEOUT) {
        admin_logout();
        return false;
    }

    $_SESSION['admin_last_activity'] = time();
    return true;
}

/**
 * Enforce admin login and session timeout.
 *
 * Redirects to index.php when the session is not authenticated.
 * Redirects to index.php?timeout=1 when the session has expired.
 */
function admin_require_login(): void
{
    $warEingeloggt = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
    if (!admin_ist_eingeloggt()) {
        header('Location: index.php' . ($warEingeloggt ? '?timeout=1' : ''));
        exit;
    }
}

/**
 * End the admin session.
 */
function admin_logout(): void
{
    unset($_SESSION['admin_logged_in'], $_SESSION['admin_login_time'], $_SESSION['admin_last_activity']);
}

/**
 * Attempt an admin login with rate-limiting.
 *
 * Failed attempts are counted in the database per IP address and in total,
 * so deleting the session cookie does not reset the counter.
 *
 * @param string $username Submitted username
 * @param string $password Submitted plain-text password
 * @return array{success: bool, error: string}
 */
function admin_login(string $username, string $password): array
{
    $fehlversuche = new Fehlversuche();
    $schluesselIp     = 'admin:' . ($_SERVER['REMOTE_ADDR'] ?? 'unbekannt');
    $schluesselGesamt = 'admin:*';

    // Rate-limit check
    $remaining = max(
        $fehlversuche->gesperrt($schluesselIp, MAX_LOGIN_ATTEMPTS, LOGIN_LOCKOUT_SECONDS),
        $fehlversuche->gesperrt($schluesselGesamt, ADMIN_MAX_LOGIN_ATTEMPTS_GESAMT, LOGIN_LOCKOUT_SECONDS)
    );
    if ($remaining > 0) {
        $minutes = (int) ceil($remaining / 60);
        return [
            'success' => false,
            'error'   => 'Zu viele Fehlversuche. Bitte warten Sie noch ' . $minutes . ' Minute(n).',
        ];
    }

    // Credential check
    if ($username === ADMIN_USERNAME && password_verify($password, ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true);

        $_SESSION['admin_logged_in']     = true;
        $_SESSION['admin_login_time']    = time();
        $_SESSION['admin_last_activity'] = time();

        $fehlversuche->zuruecksetzen($schluesselIp);

        return ['success' => true, 'error' => ''];
    }

    // Failed attempt — count it
    $fehlversuche->zaehlen($schluesselIp, LOGIN_LOCKOUT_SECONDS);
    $fehlversuche->zaehlen($schluesselGesamt, LOGIN_LOCKOUT_SECONDS);

    return ['success' => false, 'error' => 'Ungültige Zugangsdaten'];
}
