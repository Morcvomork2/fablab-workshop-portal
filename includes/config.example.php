<?php
/**
 * FabLab Workshop-Portal - Konfigurationsvorlage
 *
 * Diese Datei nach includes/config.php kopieren und die mit ANPASSEN
 * markierten Werte eintragen. config.php enthält Zugangsdaten und ist
 * deshalb von der Versionsverwaltung ausgenommen (.gitignore).
 */

// Fehleranzeige (im Produktivbetrieb auf false setzen)
define('DEBUG_MODE', false);

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Basis-URL der Installation, mit abschließendem Schrägstrich - ANPASSEN!
define('BASE_URL', 'https://deine-schule.de/fablab/');

// ============================================
// DATENBANK-EINSTELLUNGEN (MySQL) - ANPASSEN!
// ============================================
// Diese Daten bekommst du von deinem Webhoster
define('DB_HOST', 'localhost');           // Oft 'localhost' oder eine Adresse vom Hoster
define('DB_NAME', 'fablab_workshops');    // Name der Datenbank
define('DB_USER', 'dein_benutzer');       // Datenbank-Benutzername
define('DB_PASS', 'dein_passwort');       // Datenbank-Passwort
define('DB_CHARSET', 'utf8mb4');          // Zeichensatz (so lassen)

// E-Mail-Einstellungen - ANPASSEN!
// Die Absenderadresse muss zur Domain des Webservers passen, sonst landen Mails im Spam.
define('MAIL_FROM', 'fablab@deine-schule.de');
define('MAIL_FROM_NAME', 'FabLab Deine Schule');

// SMS-Einstellungen (seven.io) - ab Werk aus und ungetestet
define('SMS_ENABLED', false);
define('SMS_API_KEY', 'DEIN_SEVEN_IO_API_KEY');
define('SMS_FROM', 'FabLab');

// Workshop-Einstellungen (Vorgaben, je Workshop änderbar)
define('MAX_TEILNEHMER', 15);
define('MAX_WARTELISTE', 3);
define('MAX_OFFENE_BUCHUNGEN', 2);

// Gültigkeit des persönlichen Zugangslinks (in Tagen)
define('TOKEN_VALIDITY_DAYS', 365);

// Admin-Zugangsdaten - ANPASSEN!
// Den Hash für dein Passwort erzeugst du auf der Kommandozeile mit:
//   php -r "echo password_hash('DEIN_PASSWORT', PASSWORD_DEFAULT), PHP_EOL;"
// Das Passwort selbst gehört NICHT in diese Datei, nur der Hash.
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD_HASH', 'HIER_DEN_ERZEUGTEN_HASH_EINTRAGEN');

// Admin-Session und Rate-Limiting
define('ADMIN_SESSION_TIMEOUT', 28800);   // Abmeldung nach 8 Stunden Untätigkeit
define('MAX_LOGIN_ATTEMPTS', 5);          // Fehlversuche je IP-Adresse vor Sperre
define('LOGIN_LOCKOUT_SECONDS', 900);     // Sperrdauer: 15 Minuten

// Session-Härtung (vor session_start aufrufen)
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);      // setzt HTTPS voraus
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);

// Security-Header
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

// Session starten
session_start();

// Zeitzone
date_default_timezone_set('Europe/Berlin');

// Autoload für Klassen
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
