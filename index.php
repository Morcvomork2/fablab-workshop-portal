<?php
/**
 * FabLab Workshop-Portal - Registrierung / Startseite
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET)) {
    header('Location: workshops.php', true, 302);
    exit;
}
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Schueler.php';
require_once __DIR__ . '/includes/Mailer.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/Fehlversuche.php';

// Grenzen für die Anmeldung per Zahlencode
const OTP_MAX_FEHLVERSUCHE         = 3;   // je Code, danach neuer Code nötig
const OTP_MAX_ANFRAGEN_PRO_ADRESSE = 5;   // Codes je Adresse und Stunde
const OTP_MAX_ANFRAGEN_PRO_IP      = 100; // Codes je IP-Adresse und Stunde (im Schulnetz teilen sich alle eine IP)

$db = Database::getInstance();
$schuelerModel = new Schueler();
$fehlversuche = new Fehlversuche();

$message = '';
$messageType = '';
$messageHtml = false;

// Formular verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'registrieren') {
        // Validierung
        $errors = [];
        
        $vorname = trim($_POST['vorname'] ?? '');
        $nachname = trim($_POST['nachname'] ?? '');
        $klasse = trim($_POST['klasse'] ?? '');
        $elternteil = trim($_POST['elternteil_name'] ?? '');
        $telefon = trim($_POST['notfall_telefon'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $typ = $_POST['typ'] ?? 'GFB';
        if (!in_array($typ, ['GFB', 'extern'], true)) {
            $typ = 'GFB';
        }
        $einwilligung = isset($_POST['einwilligung']);
        
        if (empty($vorname)) $errors[] = 'Vorname ist erforderlich';
        if (empty($nachname)) $errors[] = 'Nachname ist erforderlich';
        if (empty($klasse)) $errors[] = 'Klasse ist erforderlich';
        if (empty($elternteil)) $errors[] = 'Name eines Elternteils ist erforderlich';
        if (empty($telefon)) $errors[] = 'Notfall-Rufnummer ist erforderlich';
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Gültige E-Mail-Adresse ist erforderlich';
        if (!$einwilligung) $errors[] = 'Einwilligung zur Datenverarbeitung ist erforderlich';
        
        if (empty($errors)) {
            $result = $schuelerModel->registrieren([
                'vorname' => $vorname,
                'nachname' => $nachname,
                'klasse' => $klasse,
                'elternteil_name' => $elternteil,
                'notfall_telefon' => $telefon,
                'email' => $email,
                'typ' => $typ,
                'einwilligung' => $einwilligung
            ]);
            
            if ($result['success']) {
                // E-Mail senden
                $schueler = $schuelerModel->getById($result['id']);
                Mailer::sendRegistrierungsMail($schueler);
                
                $message = 'Registrierung erfolgreich! Du erhältst in Kürze eine E-Mail mit deinem persönlichen Zugangslink.';
                $messageType = 'success';
            } else {
                $message = htmlspecialchars($result['error']);
                $messageType = 'danger';
            }
        } else {
            $message = implode('<br>', array_map('htmlspecialchars', $errors));
            $messageType = 'danger';
            $messageHtml = true;
        }
    }
    
    if ($action === 'otp_anfordern') {
        $email = trim($_POST['email'] ?? '');
        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Anfragen begrenzen, pro Adresse und pro IP. Die Sperre gilt
            // unabhängig davon, ob die Adresse registriert ist (kein User-Enumeration).
            $schluesselMail = 'otp_anfrage:' . strtolower($email);
            $schluesselIp   = 'otp_anfrage_ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unbekannt');
            if ($fehlversuche->gesperrt($schluesselMail, OTP_MAX_ANFRAGEN_PRO_ADRESSE, 3600) > 0
                || $fehlversuche->gesperrt($schluesselIp, OTP_MAX_ANFRAGEN_PRO_IP, 3600) > 0) {
                $message     = 'Zu viele Code-Anfragen. Bitte versuche es in einer Stunde erneut.';
                $messageType = 'danger';
            } else {
                $fehlversuche->zaehlen($schluesselMail, 3600);
                $fehlversuche->zaehlen($schluesselIp, 3600);

                $stmt = $db->getPdo()->prepare("SELECT * FROM schueler WHERE email = ? AND aktiv = 1");
                $stmt->execute([$email]);
                $schueler = $stmt->fetch();
                if ($schueler) {
                    $code = $schuelerModel->generateOtp($email);
                    if ($code) {
                        // Neuer Code, neue Versuche
                        $fehlversuche->zuruecksetzen('otp_fehler:' . strtolower($email));
                        Mailer::sendOtpMail($schueler, $code);
                    }
                }
                // Immer gleicher Ablauf und gleiche Meldung (kein User-Enumeration)
                $_SESSION['otp_pending'] = true;
                $_SESSION['otp_email']   = $email;
                $message     = 'Falls ein Konto mit dieser E-Mail-Adresse existiert, erhältst du in Kürze einen 6-stelligen Code per E-Mail.';
                $messageType = 'info';
            }
        }
    }

    if ($action === 'otp_abbrechen') {
        // Zurück zur Adresseingabe, z.B. bei vertippter oder nicht registrierter Adresse
        unset($_SESSION['otp_pending'], $_SESSION['otp_email']);
    }

    if ($action === 'otp_verify') {
        $email = $_SESSION['otp_email'] ?? '';
        $code  = trim($_POST['otp_code'] ?? '');
        if (!empty($email) && !empty($code)) {
            // Fehlversuche werden pro Adresse in der Datenbank gezählt, nicht in
            // der Session - sonst genügt ein neues Cookie für neue Versuche.
            $schluesselFehler = 'otp_fehler:' . strtolower($email);
            $schueler = false;
            if ($fehlversuche->gesperrt($schluesselFehler, OTP_MAX_FEHLVERSUCHE, 1800) === 0) {
                $schueler = $schuelerModel->verifyOtp($email, $code);
            }
            if ($schueler) {
                $fehlversuche->zuruecksetzen($schluesselFehler);
                session_regenerate_id(true);
                $_SESSION['user_token'] = $schueler['token'];
                unset($_SESSION['otp_pending'], $_SESSION['otp_email']);
                header('Location: workshops.php');
                exit;
            } else {
                $fehlversuche->zaehlen($schluesselFehler, 1800);
                $remaining = OTP_MAX_FEHLVERSUCHE - $fehlversuche->anzahl($schluesselFehler, 1800);
                if ($remaining <= 0) {
                    // Code unbrauchbar machen: Weiterraten ist zwecklos
                    $schuelerModel->otpVerwerfen($email);
                    unset($_SESSION['otp_pending'], $_SESSION['otp_email']);
                    $message     = 'Zu viele Fehlversuche. Bitte fordere einen neuen Code an.';
                } else {
                    $message     = 'Der Code ist ungültig oder abgelaufen. Noch ' . $remaining . ' Versuch' . ($remaining === 1 ? '' : 'e') . ' verbleibend.';
                }
                $messageType = 'danger';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FabLab Workshop-Portal - Gymnasium in den Filder Benden</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="header">
        <div class="header-inner">
            <a href="index.php" class="logo">
                <div class="logo-icon">FL</div>
                <div class="logo-text">
                    <h1>FabLab Workshop-Portal</h1>
                    <p>Gymnasium in den Filder Benden</p>
                </div>
            </a>
        </div>
    </header>
    
    <main class="main">
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <span class="alert-icon">
                    <?php if ($messageType === 'success'): ?>✓<?php elseif ($messageType === 'danger'): ?>✕<?php else: ?>ℹ<?php endif; ?>
                </span>
                <div><?= $messageHtml ? $message : htmlspecialchars($message) ?></div>
            </div>
        <?php endif; ?>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 2rem;">
            <!-- Registrierung -->
            <div class="card">
                <div class="card-header">
                    📝 Neu hier? Jetzt registrieren
                </div>
                <div class="card-body">
                    <p style="margin-top: 0;">Registriere dich einmalig, um dich für unsere FabLab-Workshops anmelden zu können.</p>
                    
                    <form method="post">
                        <input type="hidden" name="action" value="registrieren">
                        <?= csrf_field() ?>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Vorname <span class="required">*</span></label>
                                <input type="text" name="vorname" class="form-control" required 
                                       value="<?= htmlspecialchars($_POST['vorname'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Nachname <span class="required">*</span></label>
                                <input type="text" name="nachname" class="form-control" required
                                       value="<?= htmlspecialchars($_POST['nachname'] ?? '') ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Klasse <span class="required">*</span></label>
                                <input type="text" name="klasse" class="form-control" placeholder="z.B. 7a" required
                                       value="<?= htmlspecialchars($_POST['klasse'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Schülertyp <span class="required">*</span></label>
                                <select name="typ" class="form-control" required>
                                    <option value="GFB" <?= ($_POST['typ'] ?? '') === 'GFB' ? 'selected' : '' ?>>GFB-Schüler/in</option>
                                    <option value="extern" <?= ($_POST['typ'] ?? '') === 'extern' ? 'selected' : '' ?>>Externe/r Teilnehmer/in</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Name eines Elternteils <span class="required">*</span></label>
                            <input type="text" name="elternteil_name" class="form-control" required
                                   value="<?= htmlspecialchars($_POST['elternteil_name'] ?? '') ?>">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Notfall-Rufnummer <span class="required">*</span></label>
                                <input type="tel" name="notfall_telefon" class="form-control" placeholder="0123 456789" required
                                       value="<?= htmlspecialchars($_POST['notfall_telefon'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>E-Mail-Adresse <span class="required">*</span></label>
                                <input type="email" name="email" class="form-control" required
                                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                                <span class="form-hint">An diese Adresse wird dein Zugangslink gesendet</span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" name="einwilligung" id="einwilligung" required
                                       <?= isset($_POST['einwilligung']) ? 'checked' : '' ?>>
                                <label for="einwilligung">
                                    Ich willige in die Verarbeitung meiner Daten zum Zweck der Workshop-Anmeldung ein. 
                                    Die Daten werden nur für die Organisation der Workshops verwendet und nicht an Dritte weitergegeben.
                                    <span class="required">*</span>
                                </label>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-accent btn-lg" style="width: 100%;">
                            Jetzt registrieren
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Bereits registriert -->
            <div>
                <div class="card">
                    <div class="card-header">
                        🔗 Bereits registriert?
                    </div>
                    <div class="card-body">
                        <p style="margin-top: 0;">Nutze den Zugangslink aus deiner Registrierungs-E-Mail, um dich für Workshops anzumelden. Falls der Link nicht funktioniert, kannst du dich auch mit einem <strong>Einmal-Code</strong> einloggen (siehe unten).</p>

                        <div class="alert alert-info">
                            <span class="alert-icon">💡</span>
                            <div>
                                <strong>Tipp:</strong> Speichere deinen persönlichen Zugangslink als Lesezeichen, um jederzeit schnell auf das Workshop-Portal zugreifen zu können.
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        🔑 Einloggen mit Einmal-Code
                    </div>
                    <div class="card-body">
                        <?php if (!empty($_SESSION['otp_pending'])): ?>
                            <p style="margin-top: 0;">Falls <strong><?= htmlspecialchars($_SESSION['otp_email']) ?></strong> bei uns registriert ist, haben wir dir einen 6-stelligen Code geschickt.</p>
                            <form method="post">
                                <input type="hidden" name="action" value="otp_verify">
                                <?= csrf_field() ?>
                                <div class="form-group">
                                    <label>Einmal-Code</label>
                                    <input type="text" name="otp_code" class="form-control"
                                           inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                                           placeholder="123456" required autofocus>
                                    <span class="form-hint">6-stelliger Code aus der E-Mail · gültig 30 Minuten</span>
                                </div>
                                <button type="submit" class="btn btn-accent">Anmelden</button>
                            </form>
                            <p class="form-hint" style="margin-top: 1rem;">
                                Keine E-Mail bekommen? Schau im Spam-Ordner nach. Noch nicht registriert?
                                Dann registriere dich zuerst über das Formular „Neu hier?“.
                            </p>
                            <form method="post">
                                <input type="hidden" name="action" value="otp_abbrechen">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-outline">Andere E-Mail-Adresse eingeben</button>
                            </form>
                        <?php else: ?>
                            <p style="margin-top: 0;">Gib deine E-Mail-Adresse ein — wir schicken dir einen 6-stelligen Code zum Einloggen.</p>
                            <form method="post">
                                <input type="hidden" name="action" value="otp_anfordern">
                                <?= csrf_field() ?>
                                <div class="form-group">
                                    <label>E-Mail-Adresse</label>
                                    <input type="email" name="email" class="form-control" required>
                                </div>
                                <button type="submit" class="btn btn-primary">Code zusenden</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <footer class="footer">
        <p>
            FabLab im Gymnasium in den Filder Benden<br>
            Zahnstraße 43, 47447 Moers<br>
            <a href="https://filder-benden.de" target="_blank" rel="noopener noreferrer">filder-benden.de</a>
        </p>
    </footer>
</body>
</html>
