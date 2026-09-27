<?php
/**
 * FabLab Workshop-Portal - Öffentliche Workshop-Übersicht
 * Zeigt Workshops ohne Login; Kapazitäten und Buchung erst nach Login.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Schueler.php';
require_once __DIR__ . '/includes/Workshop.php';
require_once __DIR__ . '/includes/Anmeldung.php';
require_once __DIR__ . '/includes/Mailer.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/level.php';

$db = Database::getInstance();
$schuelerModel = new Schueler();
$workshopModel = new Workshop();
$anmeldungModel = new Anmeldung();

// Token prüfen
$token = $_SESSION['user_token'] ?? '';
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = $_GET['token'];
    $schueler = $schuelerModel->getByToken($token);
    if ($schueler) {
        $_SESSION['user_token'] = $token;
        header('Location: workshops.php');
        exit;
    }
}

$schueler = null;
if (!empty($token)) {
    $schueler = $schuelerModel->getByToken($token);
}

$istEingeloggt = $schueler !== null;

$message = '';
$messageType = '';

// Aktionen nur für eingeloggte Nutzer
if ($istEingeloggt && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'anmelden') {
        $workshopId = (int)($_POST['workshop_id'] ?? 0);
        if ($workshopId > 0) {
            $result = $anmeldungModel->anmelden($schueler['id'], $workshopId);
            if ($result['success']) {
                $workshop = $workshopModel->getById($workshopId);
                if ($result['status'] === 'bestaetigt') {
                    Mailer::sendAnmeldeBestaetigungMail($result, $schueler, $workshop);
                    $message = 'Anmeldung erfolgreich! Du erhältst eine Bestätigung per E-Mail' . (SMS_ENABLED ? ' und SMS' : '') . '.';
                    $messageType = 'success';
                } else {
                    Mailer::sendWartelisteMail($result, $schueler, $workshop, $result['warteliste_position']);
                    $message = 'Du stehst auf der Warteliste (Platz ' . $result['warteliste_position'] . '). Falls jemand absagt, rückst du automatisch nach.';
                    $messageType = 'warning';
                }
            } else {
                $message = $result['error'];
                $messageType = 'danger';
            }
        }
    }

    if ($action === 'stornieren') {
        $anmeldungId = (int)($_POST['anmeldung_id'] ?? 0);
        if ($anmeldungId > 0) {
            $anmeldung = $anmeldungModel->getById($anmeldungId);
            $result = $anmeldungModel->stornieren($anmeldungId, $schueler['id']);
            if ($result['success']) {
                if ($anmeldung) {
                    $workshop = $workshopModel->getById($anmeldung['workshop_id']);
                    Mailer::sendStornierungMail($schueler, $workshop);
                }
                $message = 'Anmeldung wurde storniert.';
                $messageType = 'success';
            } else {
                $message = $result['error'];
                $messageType = 'danger';
            }
        }
    }
}

// Workshops laden: nur eingeloggte Nutzer brauchen Kapazitätsdaten
if ($istEingeloggt) {
    $workshops = $workshopModel->getAlleMitStatus(true);
} else {
    $workshops = $workshopModel->getAlle(true);
}

// Für eingeloggte 5./6.-Klässler: Workshops ausblenden, bei denen das Klassenlimit erreicht ist
if ($istEingeloggt && preg_match('/^[56]/i', $schueler['klasse'] ?? '')) {
    $workshops = array_filter($workshops, function($ws) use ($workshopModel) {
        if (empty($ws['max_5_6_klasse'])) return true;
        return $workshopModel->getAnzahl5_6Klasse($ws['id']) < $ws['max_5_6_klasse'];
    });
}

// Daten für eingeloggte Nutzer
$meineAnmeldungen = [];
$anmeldungenAktiv = [];
$anmeldungenVergangen = [];
$offeneBuchungen = 0;
$meineWorkshopIds = [];
$levelInfo = null;

if ($istEingeloggt) {
    $meineAnmeldungen = $anmeldungModel->getBySchueler($schueler['id']);
    $offeneBuchungen  = $schuelerModel->getOffeneBuchungen($schueler['id']);
    $anzahlGesamt     = $schuelerModel->getAnzahlAnmeldungen($schueler['id']);
    $levelInfo        = getLevelInfo($anzahlGesamt);

    $meineWorkshopIds = array_column(array_filter($meineAnmeldungen, function($a) {
        return $a['status'] !== 'storniert';
    }), 'workshop_id');

    $anmeldungenAktiv = array_filter($meineAnmeldungen, function($a) {
        return $a['status'] !== 'storniert' && strtotime($a['datum'] . ' ' . $a['uhrzeit_ende']) > time();
    });

    $anmeldungenVergangen = array_filter($meineAnmeldungen, function($a) {
        return strtotime($a['datum'] . ' ' . $a['uhrzeit_ende']) <= time() || $a['status'] === 'storniert';
    });
}

function formatDatum($datum) {
    $wochentage = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    $monate = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    $ts = strtotime($datum);
    return $wochentage[date('w', $ts)] . ', ' . date('j', $ts) . '. ' . $monate[date('n', $ts) - 1] . ' ' . date('Y', $ts);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workshops - FabLab<?= $istEingeloggt ? ' · ' . htmlspecialchars($schueler['vorname']) : '' ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="header">
    <div class="header-inner">
        <a href="workshops.php" class="logo">
            <div class="logo-icon">FL</div>
            <div class="logo-text">
                <h1>FabLab Workshop-Portal</h1>
                <p>Gymnasium in den Filder Benden</p>
            </div>
        </a>
        <?php if ($istEingeloggt): ?>
        <nav class="nav">
            <a href="#workshops" class="active">Workshops</a>
            <a href="#meine-anmeldungen">Meine Anmeldungen</a>
        </nav>
        <?php endif; ?>
    </div>
</header>

<main class="main">
    <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>">
            <span class="alert-icon">
                <?php if ($messageType === 'success'): ?>✓<?php elseif ($messageType === 'danger'): ?>✕<?php else: ?>⚠<?php endif; ?>
            </span>
            <div><?= htmlspecialchars($message) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($istEingeloggt): ?>
    <!-- ===== EINGELOGGTE ANSICHT ===== -->

    <!-- User-Box mit Erfahrungslevel -->
    <div class="user-box">
        <div class="user-info">
            <h2>Hallo, <?= htmlspecialchars($schueler['vorname']) ?>!</h2>
            <p><?= htmlspecialchars($schueler['klasse']) ?> · <?= $schueler['typ'] === 'GFB' ? 'GFB-Schüler/in' : 'Externe/r Teilnehmer/in' ?></p>
        </div>
        <div style="display: flex; gap: 2rem; align-items: center;">
            <?php if ($levelInfo): ?>
            <div class="level-badge">
                <span class="level-badge-label">⚙️ Erfahrungslevel</span>
                <span class="level-badge-name"><?= htmlspecialchars($levelInfo['name']) ?></span>
            </div>
            <?php endif; ?>
            <div class="user-stats">
                <div class="user-stat">
                    <div class="user-stat-value"><?= $offeneBuchungen ?>/<?= MAX_OFFENE_BUCHUNGEN ?></div>
                    <div class="user-stat-label">Offene Buchungen</div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($offeneBuchungen >= MAX_OFFENE_BUCHUNGEN): ?>
        <div class="alert alert-encourage">
            <span class="alert-icon">⭐</span>
            <div>
                <strong>Du hast <?= MAX_OFFENE_BUCHUNGEN ?> aktive Anmeldungen — super!</strong>
                Komm vorbei, zeig was du drauf hast, und danach kannst du direkt den nächsten Workshop buchen. Wir freuen uns auf dich! 🛠️
            </div>
        </div>
    <?php endif; ?>

    <!-- Verfügbare Workshops (eingeloggt) -->
    <section id="workshops">
        <h2 style="margin-bottom: 1.5rem;">📅 Verfügbare Workshops</h2>
        <?php if (empty($workshops)): ?>
            <div class="card">
                <div class="card-body">
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <h3>Keine Workshops verfügbar</h3>
                        <p>Aktuell sind keine Workshops geplant. Schau später noch einmal vorbei!</p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="workshop-grid">
                <?php foreach ($workshops as $ws): ?>
                    <?php
                    $istAngemeldet = in_array($ws['id'], $meineWorkshopIds);
                    $kannBuchen = !$istAngemeldet && $offeneBuchungen < MAX_OFFENE_BUCHUNGEN && $ws['status'] !== 'voll' && $ws['status'] !== 'vergangen';
                    ?>
                    <div class="workshop-card">
                        <div class="workshop-card-header">
                            <h3><?= htmlspecialchars($ws['titel']) ?></h3>
                            <div class="workshop-date">📅 <?= formatDatum($ws['datum']) ?></div>
                        </div>
                        <div class="workshop-card-body">
                            <div class="workshop-info">
                                <div class="workshop-info-item">
                                    <span class="icon">🕐</span>
                                    <?= date('H:i', strtotime($ws['uhrzeit_start'])) ?> - <?= date('H:i', strtotime($ws['uhrzeit_ende'])) ?> Uhr
                                </div>
                                <div class="workshop-info-item">
                                    <span class="icon">📍</span>
                                    <?= htmlspecialchars($ws['ort']) ?>
                                </div>
                                <?php if ($ws['altersgruppe']): ?>
                                <div class="workshop-info-item">
                                    <span class="icon">👥</span>
                                    <?= htmlspecialchars($ws['altersgruppe']) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($ws['beschreibung']): ?>
                                <div class="workshop-description">
                                    <?= nl2br(htmlspecialchars($ws['beschreibung'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="workshop-card-footer">
                            <div>
                                <?php if ($ws['status'] === 'verfuegbar'): ?>
                                    <span class="status-badge status-verfuegbar">✓ <?= $ws['freie_plaetze'] ?> Plätze frei</span>
                                <?php elseif ($ws['status'] === 'warteliste'): ?>
                                    <span class="status-badge status-warteliste">⏳ Nur Warteliste (<?= $ws['freie_warteliste'] ?> frei)</span>
                                <?php else: ?>
                                    <span class="status-badge status-voll">✕ Ausgebucht</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($istAngemeldet): ?>
                                <span class="btn btn-sm btn-outline" style="cursor: default;">✓ Angemeldet</span>
                            <?php elseif ($kannBuchen): ?>
                                <form method="post" style="margin: 0;">
                                    <input type="hidden" name="action" value="anmelden">
                                    <input type="hidden" name="workshop_id" value="<?= $ws['id'] ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-accent">Anmelden</button>
                                </form>
                            <?php elseif ($offeneBuchungen >= MAX_OFFENE_BUCHUNGEN): ?>
                                <span class="btn btn-sm btn-outline" style="cursor: default; opacity: 0.5;">Max. erreicht</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Meine Anmeldungen -->
    <section id="meine-anmeldungen" style="margin-top: 3rem;">
        <h2 style="margin-bottom: 1.5rem;">📋 Meine Anmeldungen</h2>

        <!-- Erfahrungslevel-Karte -->
        <?php if ($levelInfo): ?>
        <div class="level-card">
            <div class="level-card-icon">⚙️</div>
            <div class="level-card-body">
                <p class="level-card-title">Erfahrungslevel: <?= htmlspecialchars($levelInfo['name']) ?></p>
                <?php if ($levelInfo['is_max']): ?>
                    <p class="level-card-sub">Du hast das höchste Level erreicht! 🏆</p>
                    <div class="level-progress"><div class="level-progress-bar" style="width: 100%;"></div></div>
                <?php else: ?>
                    <p class="level-card-sub">Noch <?= $levelInfo['remaining'] ?> Anmeldung<?= $levelInfo['remaining'] === 1 ? '' : 'en' ?> bis zum nächsten Level: <strong><?= htmlspecialchars($levelInfo['next_name']) ?></strong></p>
                    <div class="level-progress"><div class="level-progress-bar" style="width: <?= $levelInfo['progress_percent'] ?>%;"></div></div>
                <?php endif; ?>
            </div>
            <div class="level-card-count"><?= $levelInfo['anzahl'] ?> Anmeldung<?= $levelInfo['anzahl'] === 1 ? '' : 'en' ?><br>gesamt</div>
        </div>
        <?php endif; ?>

        <?php if (!empty($anmeldungenAktiv)): ?>
            <div class="card mb-3">
                <div class="card-header">Aktive Anmeldungen</div>
                <div class="card-body" style="padding: 0;">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Workshop</th><th>Datum</th><th>Uhrzeit</th><th>Ort</th><th>Status</th><th>Aktion</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($anmeldungenAktiv as $anm): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($anm['titel']) ?></strong></td>
                                        <td><?= date('d.m.Y', strtotime($anm['datum'])) ?></td>
                                        <td><?= date('H:i', strtotime($anm['uhrzeit_start'])) ?> Uhr</td>
                                        <td><?= htmlspecialchars($anm['ort']) ?></td>
                                        <td>
                                            <?php if ($anm['status'] === 'bestaetigt'): ?>
                                                <span class="status-badge status-bestaetigt">✓ Bestätigt</span>
                                            <?php else: ?>
                                                <span class="status-badge status-warteliste">⏳ Warteliste #<?= $anm['warteliste_position'] ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="post" style="margin: 0;" onsubmit="return confirm('Anmeldung wirklich stornieren?');">
                                                <input type="hidden" name="action" value="stornieren">
                                                <input type="hidden" name="anmeldung_id" value="<?= (int)$anm['id'] ?>">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="btn btn-sm btn-danger">Stornieren</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($anmeldungenVergangen)): ?>
            <div class="card">
                <div class="card-header">Vergangene Workshops</div>
                <div class="card-body" style="padding: 0;">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr><th>Workshop</th><th>Datum</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($anmeldungenVergangen as $anm): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($anm['titel']) ?></td>
                                        <td><?= date('d.m.Y', strtotime($anm['datum'])) ?></td>
                                        <td>
                                            <?php if ($anm['status'] === 'storniert'): ?>
                                                <span class="status-badge status-storniert">Storniert</span>
                                            <?php else: ?>
                                                <span class="status-badge status-vergangen">Teilgenommen</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($anmeldungenAktiv) && empty($anmeldungenVergangen)): ?>
            <div class="card">
                <div class="card-body">
                    <div class="empty-state">
                        <div class="empty-state-icon">📝</div>
                        <h3>Noch keine Anmeldungen</h3>
                        <p>Du hast dich noch für keinen Workshop angemeldet. Schau dir die verfügbaren Workshops oben an!</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <?php else: ?>
    <!-- ===== ÖFFENTLICHE ANSICHT (kein Login) ===== -->

    <div class="public-info-banner">
        <span>🔬</span>
        <div>
            Schau dir unsere Workshops an — <a href="index.php?anmelden">melde dich an</a>, um Verfügbarkeit zu sehen und einen Platz zu buchen.
        </div>
    </div>

    <section id="workshops">
        <h2 style="margin-bottom: 1.5rem;">📅 Kommende Workshops</h2>
        <?php if (empty($workshops)): ?>
            <div class="card">
                <div class="card-body">
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <h3>Keine Workshops geplant</h3>
                        <p>Aktuell sind keine Workshops geplant. Schau später noch einmal vorbei!</p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="workshop-grid">
                <?php foreach ($workshops as $ws): ?>
                    <div class="workshop-card">
                        <div class="workshop-card-header">
                            <h3><?= htmlspecialchars($ws['titel']) ?></h3>
                            <div class="workshop-date">📅 <?= formatDatum($ws['datum']) ?></div>
                        </div>
                        <div class="workshop-card-body">
                            <div class="workshop-info">
                                <div class="workshop-info-item">
                                    <span class="icon">🕐</span>
                                    <?= date('H:i', strtotime($ws['uhrzeit_start'])) ?> - <?= date('H:i', strtotime($ws['uhrzeit_ende'])) ?> Uhr
                                </div>
                                <div class="workshop-info-item">
                                    <span class="icon">📍</span>
                                    <?= htmlspecialchars($ws['ort']) ?>
                                </div>
                                <?php if ($ws['altersgruppe']): ?>
                                <div class="workshop-info-item">
                                    <span class="icon">👥</span>
                                    <?= htmlspecialchars($ws['altersgruppe']) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($ws['beschreibung']): ?>
                                <div class="workshop-description">
                                    <?= nl2br(htmlspecialchars($ws['beschreibung'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="workshop-card-footer">
                            <span class="status-badge status-locked">🔒 Kapazität nach Anmeldung sichtbar</span>
                            <a href="index.php?anmelden" class="btn btn-sm btn-accent">Anmelden</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php endif; ?>
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
