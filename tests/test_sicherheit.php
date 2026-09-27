<?php
// Prüft Anmeldesperren, Linkablauf und CSV-Export gegen eine SQLite-Testdatenbank.
// Aufruf: php tests/test_sicherheit.php  (braucht pdo_sqlite, kein MySQL)
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$P = dirname(__DIR__);

define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_SECONDS', 900);
define('ADMIN_SESSION_TIMEOUT', 28800);
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD_HASH', password_hash('richtig', PASSWORD_DEFAULT));
define('TOKEN_VALIDITY_DAYS', 365);

class Database {
    private static $i; public $pdo;
    static function getInstance() {
        if (!self::$i) {
            self::$i = new self();
            self::$i->pdo = new PDO('sqlite::memory:');
            self::$i->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$i->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$i->pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
        }
        return self::$i;
    }
    function getPdo() { return $this->pdo; }
}

ini_set('session.save_path', sys_get_temp_dir());
ob_start();
session_start();

require $P . '/includes/admin_auth.php';
require $P . '/includes/csv.php';
require $P . '/includes/Schueler.php';

$fehler = 0;
function pruefe($bed, $text) { global $fehler; echo ($bed ? "OK   " : "FEHL "), $text, "\n"; if (!$bed) $fehler++; }

// --- Befund 1: Admin-Sperre überlebt Cookie-Löschen ---
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
for ($i = 0; $i < 5; $i++) { $_SESSION = []; admin_login('admin', 'falsch'); }
$_SESSION = [];   // "Cookie gelöscht"
$r = admin_login('admin', 'richtig');
pruefe(!$r['success'] && str_contains($r['error'], 'Zu viele'), 'nach 5 Fehlversuchen gesperrt, auch mit neuer Session');

$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
$r = admin_login('admin', 'richtig');
pruefe($r['success'], 'andere IP kann sich weiter anmelden');

$_SESSION = [];
for ($i = 0; $i < 50; $i++) { $_SERVER['REMOTE_ADDR'] = "10.1.0.$i"; admin_login('admin', 'falsch'); }
$_SERVER['REMOTE_ADDR'] = '10.2.0.1';
$r = admin_login('admin', 'richtig');
pruefe(!$r['success'], 'globale Grenze (50) greift bei verteiltem Durchprobieren');

// Erfolgreiche Anmeldung setzt IP-Zähler zurück
Database::getInstance()->getPdo()->exec('DELETE FROM fehlversuche');
$_SERVER['REMOTE_ADDR'] = '10.3.0.1';
for ($i = 0; $i < 4; $i++) admin_login('admin', 'falsch');
admin_login('admin', 'richtig');
for ($i = 0; $i < 4; $i++) admin_login('admin', 'falsch');
$r = admin_login('admin', 'richtig');
pruefe($r['success'], 'Erfolg setzt den IP-Zähler zurück');

// --- Befund 5: Timeout nach Untätigkeit ---
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_login_time'] = time() - 20 * 3600;     // vor 20 h angemeldet
$_SESSION['admin_last_activity'] = time() - 3600;       // vor 1 h aktiv
pruefe(admin_ist_eingeloggt(), 'aktive Sitzung bleibt trotz langer Gesamtdauer bestehen');
$_SESSION['admin_last_activity'] = time() - 9 * 3600;
pruefe(!admin_ist_eingeloggt() && !isset($_SESSION['admin_logged_in']), 'nach 9 h Untätigkeit abgemeldet');
$_SESSION = ['admin_logged_in' => true, 'admin_login_time' => time() - 60];
pruefe(admin_ist_eingeloggt(), 'alte Sessions ohne admin_last_activity funktionieren weiter');

// --- Fehlversuche: Zeitfenster läuft ab ---
class TestFehlversuche extends Fehlversuche { public $t; protected function jetzt(): int { return $this->t; } }
$fv = new TestFehlversuche(); $fv->t = 1000;
for ($i = 0; $i < 3; $i++) $fv->zaehlen('x', 1800);
pruefe($fv->gesperrt('x', 3, 1800) === 1800, 'Sperre nach 3 Versuchen, volle Restzeit');
$fv->t = 1000 + 1800;
pruefe($fv->gesperrt('x', 3, 1800) === 0 && $fv->anzahl('x', 1800) === 0, 'Sperre endet nach Ablauf des Fensters');
$fv->zaehlen('x', 1800);
pruefe($fv->anzahl('x', 1800) === 1, 'nach Ablauf beginnt ein neues Fenster bei 1');
$zeile = Database::getInstance()->getPdo()->query("SELECT schluessel FROM fehlversuche LIMIT 1")->fetch();
pruefe(strlen($zeile['schluessel']) === 64 && !str_contains($zeile['schluessel'], '@'), 'Schlüssel nur als Hash gespeichert');

// --- Befund 3: Zugangslink läuft ab ---
$pdo = Database::getInstance()->getPdo();
$pdo->exec("CREATE TABLE schueler (id INTEGER PRIMARY KEY, email TEXT, aktiv INT, token TEXT, token_erstellt TEXT, otp_code TEXT, otp_expires_at TEXT)");
$pdo->exec("INSERT INTO schueler VALUES (1, 'neu@x.de', 1, 'tneu', '" . date('Y-m-d H:i:s', time() - 10 * 86400) . "', NULL, NULL)");
$pdo->exec("INSERT INTO schueler VALUES (2, 'alt@x.de', 1, 'talt', '" . date('Y-m-d H:i:s', time() - 400 * 86400) . "', NULL, NULL)");
$pdo->exec("INSERT INTO schueler VALUES (3, 'null@x.de', 1, 'tnull', NULL, NULL, NULL)");
$s = new Schueler();
pruefe((bool)$s->getByToken('tneu'), '10 Tage alter Link gilt');
pruefe(!$s->getByToken('talt'), '400 Tage alter Link gilt nicht mehr');
pruefe(!$s->getByToken('tnull'), 'Link ohne Datum gilt nicht');

// Code-Anmeldung stellt für abgelaufene Links einen neuen aus
$pdo->exec("UPDATE schueler SET otp_code = '" . password_hash('123456', PASSWORD_DEFAULT) . "', otp_expires_at = '" . date('Y-m-d H:i:s', time() + 600) . "' WHERE id = 2");
$r = $s->verifyOtp('alt@x.de', '123456');
pruefe($r && $r['token'] !== 'talt' && $s->getByToken($r['token']), 'Code-Anmeldung liefert gültigen neuen Link');
$pdo->exec("UPDATE schueler SET otp_code = '" . password_hash('123456', PASSWORD_DEFAULT) . "', otp_expires_at = '" . date('Y-m-d H:i:s', time() + 600) . "' WHERE id = 1");
$r = $s->verifyOtp('neu@x.de', '123456');
pruefe($r && $r['token'] === 'tneu', 'gültiger Link bleibt bei Code-Anmeldung erhalten');
$pdo->exec("UPDATE schueler SET otp_code = 'x', otp_expires_at = '" . date('Y-m-d H:i:s', time() + 600) . "' WHERE id = 1");
$s->otpVerwerfen('neu@x.de');
pruefe($pdo->query("SELECT otp_code FROM schueler WHERE id = 1")->fetchColumn() === null, 'otpVerwerfen löscht den Code');

// --- Befund 4: CSV ---
pruefe(csv_zelle('=HYPERLINK("http://x")') === '\'=HYPERLINK("http://x")', 'Formel mit = wird entschärft');
pruefe(csv_zelle('@SUM(A1)') === "'@SUM(A1)", 'Formel mit @ wird entschärft');
pruefe(csv_zelle('-2+3+cmd|x') === "'-2+3+cmd|x", 'Formel mit - wird entschärft');
pruefe(csv_zelle('+49 211 123456') === '+49 211 123456', 'Telefonnummer mit + bleibt');
pruefe(csv_zelle('0211 / 12-34') === '0211 / 12-34', 'normale Nummer bleibt');
pruefe(csv_zelle('Anna') === 'Anna' && csv_zelle(7) === '7' && csv_zelle(null) === '', 'normale Werte bleiben');

echo $fehler === 0 ? "\nAlle Prüfungen bestanden.\n" : "\n$fehler Prüfung(en) fehlgeschlagen.\n";
ob_end_flush();
