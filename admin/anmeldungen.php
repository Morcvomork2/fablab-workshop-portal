<?php
/**
 * FabLab Workshop-Portal - Admin: Anmeldungen verwalten
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Schueler.php';
require_once __DIR__ . '/../includes/Workshop.php';
require_once __DIR__ . '/../includes/Anmeldung.php';
require_once __DIR__ . '/../includes/Mailer.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/csv.php';

$db = Database::getInstance();

admin_require_login();

$schuelerModel = new Schueler();
$workshopModel = new Workshop();
$anmeldungModel = new Anmeldung();
$pdo = $db->getPdo();

$message = '';
$messageType = '';

// Aktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'stornieren') {
        $anmeldungId = (int)($_POST['anmeldung_id'] ?? 0);
        $result = $anmeldungModel->stornieren($anmeldungId);
        if ($result['success']) {
            $message = 'Anmeldung storniert.';
            $messageType = 'success';
        } else {
            $message = $result['error'];
            $messageType = 'danger';
        }
    }

    if ($action === 'nachruecken') {
        $anmeldungId = (int)($_POST['anmeldung_id'] ?? 0);
        $result = $anmeldungModel->manuellNachruecken($anmeldungId);
        if ($result['success']) {
            $message = 'Teilnehmer nachgerückt.';
            $messageType = 'success';
        } else {
            $message = $result['error'];
            $messageType = 'danger';
        }
    }

    if ($action === 'hinzufuegen') {
        $schuelerId = (int)($_POST['schueler_id'] ?? 0);
        $workshopId = (int)($_POST['workshop_id'] ?? 0);
        $status = $_POST['status'] ?? 'bestaetigt';
        if (!in_array($status, ['bestaetigt', 'warteliste'], true)) {
            $status = 'bestaetigt';
        }

        if ($schuelerId > 0 && $workshopId > 0) {
            $result = $anmeldungModel->manuellAnmelden($schuelerId, $workshopId, $status);
            if ($result['success']) {
                $message = 'Anmeldung hinzugefügt.';
                $messageType = 'success';
            } else {
                $message = $result['error'];
                $messageType = 'danger';
            }
        }
    }
}

// Filter
$workshopFilter = (int)($_GET['workshop'] ?? 0);

// Workshop-Details laden falls gefiltert
$selectedWorkshop = null;
if ($workshopFilter > 0) {
    $selectedWorkshop = $workshopModel->getMitStatus($workshopFilter);
}

// Anmeldungen laden
if ($workshopFilter > 0) {
    $anmeldungen = $anmeldungModel->getByWorkshop($workshopFilter);
} else {
    // Alle aktuellen Anmeldungen
    $stmt = $pdo->query("
        SELECT a.*, s.vorname, s.nachname, s.klasse, s.email, s.notfall_telefon, s.typ,
               w.titel as workshop_titel, w.datum, w.uhrzeit_start
        FROM anmeldungen a
        JOIN schueler s ON a.schueler_id = s.id
        JOIN workshops w ON a.workshop_id = w.id
        WHERE a.status != 'storniert'
        AND CONCAT(w.datum, ' ', w.uhrzeit_ende) > NOW()
        ORDER BY w.datum, w.uhrzeit_start, a.status, a.warteliste_position
    ");
    $anmeldungen = $stmt->fetchAll();
}

// Workshops für Dropdown
$workshops = $workshopModel->getAlleMitStatus(true);

// Schüler für manuelles Hinzufügen
$alleSchueler = $schuelerModel->getAll();

// CSV-Export
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $workshopFilter > 0) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="teilnehmer_workshop_' . $workshopFilter . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    
    fputcsv($output, ['Nr.', 'Vorname', 'Nachname', 'Klasse', 'Typ', 'Elternteil', 'Notfall-Tel.', 'E-Mail', 'Status'], ';');
    
    $nr = 1;
    foreach ($anmeldungen as $a) {
        fputcsv($output, array_map('csv_zelle', [
            $nr++,
            $a['vorname'],
            $a['nachname'],
            $a['klasse'],
            $a['typ'],
            $a['elternteil_name'] ?? '',
            $a['notfall_telefon'],
            $a['email'],
            $a['status'] === 'bestaetigt' ? 'Bestätigt' : 'Warteliste #' . $a['warteliste_position']
        ]), ';');
    }
    
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Anmeldungen verwalten - FabLab Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .admin-nav {
            background: #152a45;
            padding: 0.5rem 2rem;
        }
        .admin-nav ul {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            gap: 0.25rem;
            max-width: 1200px;
            margin: 0 auto;
        }
        .admin-nav a {
            display: block;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            padding: 0.75rem 1.25rem;
            border-radius: 6px 6px 0 0;
            transition: all 0.2s;
        }
        .admin-nav a:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        .admin-nav a.active {
            background: #f8f9fa;
            color: #1e3a5f;
        }
        .filter-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .filter-bar .form-group {
            margin: 0;
        }
        .workshop-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #2a4d7a 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .workshop-header h2 {
            margin: 0;
        }
        .workshop-header p {
            margin: 0.25rem 0 0 0;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-inner">
            <a href="index.php" class="logo">
                <div class="logo-icon">FL</div>
                <div class="logo-text">
                    <h1>FabLab Admin</h1>
                    <p>Workshop-Verwaltung</p>
                </div>
            </a>
            <nav class="nav">
                <a href="index.php?logout=1" style="background: rgba(255,255,255,0.1);">Abmelden</a>
            </nav>
        </div>
    </header>
    
    <nav class="admin-nav">
        <ul>
            <li><a href="index.php">Dashboard</a></li>
            <li><a href="workshops.php">Workshops</a></li>
            <li><a href="schueler.php">Schüler</a></li>
            <li><a href="anmeldungen.php" class="active">Anmeldungen</a></li>
        </ul>
    </nav>
    
    <main class="main">
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <span class="alert-icon"><?= $messageType === 'success' ? '✓' : '✕' ?></span>
                <div><?= htmlspecialchars($message) ?></div>
            </div>
        <?php endif; ?>
        
        <!-- Workshop-Filter -->
        <form method="get" class="filter-bar">
            <div class="form-group">
                <label>Workshop auswählen</label>
                <select name="workshop" class="form-control" onchange="this.form.submit()" style="width: 350px;">
                    <option value="">-- Alle kommenden Workshops --</option>
                    <?php foreach ($workshops as $ws): ?>
                        <option value="<?= $ws['id'] ?>" <?= $workshopFilter == $ws['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ws['titel']) ?> (<?= date('d.m.Y', strtotime($ws['datum'])) ?>) - <?= $ws['teilnehmer_anzahl'] ?>/<?= $ws['max_teilnehmer'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
        
        <?php if ($selectedWorkshop): ?>
            <!-- Workshop-Header -->
            <div class="workshop-header">
                <div>
                    <h2><?= htmlspecialchars($selectedWorkshop['titel']) ?></h2>
                    <p>📅 <?= date('d.m.Y', strtotime($selectedWorkshop['datum'])) ?> · 🕐 <?= date('H:i', strtotime($selectedWorkshop['uhrzeit_start'])) ?> - <?= date('H:i', strtotime($selectedWorkshop['uhrzeit_ende'])) ?> Uhr · 📍 <?= htmlspecialchars($selectedWorkshop['ort']) ?></p>
                </div>
                <div style="display: flex; gap: 1rem; align-items: center;">
                    <div style="text-align: center;">
                        <div style="font-size: 1.5rem; font-weight: bold;"><?= $selectedWorkshop['teilnehmer_anzahl'] ?>/<?= $selectedWorkshop['max_teilnehmer'] ?></div>
                        <div style="font-size: 0.85rem; opacity: 0.9;">Teilnehmer</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 1.5rem; font-weight: bold;"><?= $selectedWorkshop['warteliste_anzahl'] ?>/<?= $selectedWorkshop['max_warteliste'] ?></div>
                        <div style="font-size: 0.85rem; opacity: 0.9;">Warteliste</div>
                    </div>
                    <a href="?workshop=<?= $workshopFilter ?>&export=csv" class="btn btn-accent">📥 CSV-Export</a>
                </div>
            </div>
            
            <!-- Manuell hinzufügen -->
            <div class="card mb-3">
                <div class="card-header">➕ Teilnehmer manuell hinzufügen</div>
                <div class="card-body">
                    <form method="post" style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="hinzufuegen">
                        <input type="hidden" name="workshop_id" value="<?= $workshopFilter ?>">
                        <div class="form-group" style="flex: 1; min-width: 250px; margin: 0;">
                            <label>Schüler</label>
                            <select name="schueler_id" class="form-control" required>
                                <option value="">-- Schüler auswählen --</option>
                                <?php foreach ($alleSchueler as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nachname'] . ', ' . $s['vorname']) ?> (<?= $s['klasse'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin: 0;">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <option value="bestaetigt">Bestätigt</option>
                                <option value="warteliste">Warteliste</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">Hinzufügen</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Teilnehmerliste -->
        <div class="card">
            <div class="card-header">
                📋 <?= $selectedWorkshop ? 'Teilnehmerliste' : 'Alle aktuellen Anmeldungen' ?>
                (<?= count($anmeldungen) ?>)
            </div>
            <div class="card-body" style="padding: 0;">
                <?php if (empty($anmeldungen)): ?>
                    <div class="empty-state" style="padding: 3rem;">
                        <div class="empty-state-icon">📝</div>
                        <h3>Keine Anmeldungen</h3>
                        <p><?= $selectedWorkshop ? 'Für diesen Workshop gibt es noch keine Anmeldungen.' : 'Es gibt aktuell keine Anmeldungen für kommende Workshops.' ?></p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <?php if (!$selectedWorkshop): ?>
                                        <th>Workshop</th>
                                    <?php endif; ?>
                                    <th>Name</th>
                                    <th>Klasse</th>
                                    <th>Typ</th>
                                    <th>Kontakt</th>
                                    <th>Status</th>
                                    <th>Aktionen</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $nr = 1;
                                foreach ($anmeldungen as $a): 
                                ?>
                                    <tr>
                                        <td><?= $nr++ ?></td>
                                        <?php if (!$selectedWorkshop): ?>
                                            <td>
                                                <strong><?= htmlspecialchars($a['workshop_titel']) ?></strong><br>
                                                <small style="color: #666;"><?= date('d.m.Y', strtotime($a['datum'])) ?></small>
                                            </td>
                                        <?php endif; ?>
                                        <td><strong><?= htmlspecialchars($a['vorname'] . ' ' . $a['nachname']) ?></strong></td>
                                        <td><?= htmlspecialchars($a['klasse']) ?></td>
                                        <td>
                                            <?php if ($a['typ'] === 'GFB'): ?>
                                                <span class="status-badge status-bestaetigt">GFB</span>
                                            <?php else: ?>
                                                <span class="status-badge status-warteliste">Extern</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small>
                                                📧 <?= htmlspecialchars($a['email']) ?><br>
                                                📞 <?= htmlspecialchars($a['notfall_telefon']) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($a['status'] === 'bestaetigt'): ?>
                                                <span class="status-badge status-bestaetigt">✓ Bestätigt</span>
                                            <?php else: ?>
                                                <span class="status-badge status-warteliste">⏳ Warteliste #<?= $a['warteliste_position'] ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 0.5rem;">
                                                <?php if ($a['status'] === 'warteliste'): ?>
                                                    <form method="post" style="margin: 0;">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="nachruecken">
                                                        <input type="hidden" name="anmeldung_id" value="<?= $a['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-success">Nachrücken</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="post" style="margin: 0;" onsubmit="return confirm('Anmeldung wirklich stornieren?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="stornieren">
                                                    <input type="hidden" name="anmeldung_id" value="<?= $a['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger">Stornieren</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    
    <footer class="footer">
        <p>FabLab Workshop-Portal · Admin-Bereich</p>
    </footer>
</body>
</html>
