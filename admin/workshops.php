<?php
/**
 * FabLab Workshop-Portal - Admin: Workshop-Verwaltung
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Workshop.php';
require_once __DIR__ . '/../includes/Anmeldung.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin_auth.php';

$db = Database::getInstance();

// Login prüfen
admin_require_login();

$workshopModel = new Workshop();
$anmeldungModel = new Anmeldung();

$message = '';
$messageType = '';
$editWorkshop = null;

// Aktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'erstellen' || $action === 'aktualisieren') {
        $daten = [
            'titel' => trim($_POST['titel'] ?? ''),
            'beschreibung' => trim($_POST['beschreibung'] ?? ''),
            'datum' => $_POST['datum'] ?? '',
            'uhrzeit_start' => $_POST['uhrzeit_start'] ?? '',
            'uhrzeit_ende' => $_POST['uhrzeit_ende'] ?? '',
            'ort' => trim($_POST['ort'] ?? ''),
            'altersgruppe' => trim($_POST['altersgruppe'] ?? ''),
            'max_teilnehmer' => (int)($_POST['max_teilnehmer'] ?? MAX_TEILNEHMER),
            'max_warteliste' => (int)($_POST['max_warteliste'] ?? MAX_WARTELISTE),
            'max_5_6_klasse' => !empty($_POST['max_5_6_klasse']) ? (int)$_POST['max_5_6_klasse'] : null
        ];
        
        if ($action === 'erstellen') {
            $result = $workshopModel->erstellen($daten);
            if ($result['success']) {
                $message = 'Workshop erfolgreich erstellt.';
                $messageType = 'success';
            } else {
                $message = 'Fehler: ' . $result['error'];
                $messageType = 'danger';
            }
        } else {
            $id = (int)($_POST['workshop_id'] ?? 0);
            if ($workshopModel->update($id, $daten)) {
                $message = 'Workshop erfolgreich aktualisiert.';
                $messageType = 'success';
            } else {
                $message = 'Fehler beim Aktualisieren.';
                $messageType = 'danger';
            }
        }
    }
    
    if ($action === 'loeschen') {
        $id = (int)($_POST['workshop_id'] ?? 0);
        $result = $workshopModel->loeschen($id);
        if ($result['success']) {
            $message = 'Workshop gelöscht.';
            $messageType = 'success';
        } else {
            $message = $result['error'];
            $messageType = 'danger';
        }
    }
    
    if ($action === 'deaktivieren') {
        $id = (int)($_POST['workshop_id'] ?? 0);
        $workshopModel->deaktivieren($id);
        $message = 'Workshop deaktiviert.';
        $messageType = 'success';
    }
    
    if ($action === 'aktivieren') {
        $id = (int)($_POST['workshop_id'] ?? 0);
        $workshopModel->aktivieren($id);
        $message = 'Workshop aktiviert.';
        $messageType = 'success';
    }
}

// Bearbeiten-Modus
if (isset($_GET['edit'])) {
    $editWorkshop = $workshopModel->getById((int)$_GET['edit']);
}

// Workshops laden
$workshops = $workshopModel->getAlleAdmin();
foreach ($workshops as &$ws) {
    $ws['teilnehmer_anzahl'] = $workshopModel->getAnzahlTeilnehmer($ws['id']);
    $ws['warteliste_anzahl'] = $workshopModel->getAnzahlWarteliste($ws['id']);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workshops verwalten - FabLab Admin</title>
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
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }
        .action-buttons form {
            margin: 0;
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
            <li><a href="workshops.php" class="active">Workshops</a></li>
            <li><a href="schueler.php">Schüler</a></li>
            <li><a href="anmeldungen.php">Anmeldungen</a></li>
        </ul>
    </nav>
    
    <main class="main">
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <span class="alert-icon"><?= $messageType === 'success' ? '✓' : '✕' ?></span>
                <div><?= htmlspecialchars($message) ?></div>
            </div>
        <?php endif; ?>
        
        <div style="display: grid; grid-template-columns: 1fr 400px; gap: 2rem;">
            <!-- Workshop-Liste -->
            <div>
                <div class="card">
                    <div class="card-header">
                        📅 Alle Workshops
                    </div>
                    <div class="card-body" style="padding: 0;">
                        <?php if (empty($workshops)): ?>
                            <div class="empty-state" style="padding: 2rem;">
                                <p>Noch keine Workshops angelegt.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Workshop</th>
                                            <th>Datum</th>
                                            <th>Uhrzeit</th>
                                            <th>Teilnehmer</th>
                                            <th>Status</th>
                                            <th>Aktionen</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($workshops as $ws): ?>
                                            <?php 
                                            $vergangen = strtotime($ws['datum'] . ' ' . $ws['uhrzeit_ende']) < time();
                                            ?>
                                            <tr style="<?= !$ws['aktiv'] ? 'opacity: 0.5;' : '' ?>">
                                                <td>
                                                    <strong><?= htmlspecialchars($ws['titel']) ?></strong><br>
                                                    <small style="color: #666;"><?= htmlspecialchars($ws['ort']) ?></small>
                                                </td>
                                                <td><?= date('d.m.Y', strtotime($ws['datum'])) ?></td>
                                                <td><?= date('H:i', strtotime($ws['uhrzeit_start'])) ?></td>
                                                <td>
                                                    <span class="status-badge status-verfuegbar"><?= $ws['teilnehmer_anzahl'] ?>/<?= $ws['max_teilnehmer'] ?></span>
                                                    <?php if ($ws['warteliste_anzahl'] > 0): ?>
                                                        <span class="status-badge status-warteliste">+<?= $ws['warteliste_anzahl'] ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!$ws['aktiv']): ?>
                                                        <span class="status-badge status-storniert">Deaktiviert</span>
                                                    <?php elseif ($vergangen): ?>
                                                        <span class="status-badge status-vergangen">Beendet</span>
                                                    <?php else: ?>
                                                        <span class="status-badge status-bestaetigt">Aktiv</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <a href="?edit=<?= $ws['id'] ?>" class="btn btn-sm btn-outline">Bearbeiten</a>
                                                        <a href="anmeldungen.php?workshop=<?= $ws['id'] ?>" class="btn btn-sm btn-primary">Teilnehmer</a>
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
            </div>
            
            <!-- Workshop erstellen/bearbeiten -->
            <div>
                <div class="card">
                    <div class="card-header">
                        <?= $editWorkshop ? '✏️ Workshop bearbeiten' : '➕ Neuen Workshop erstellen' ?>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= $editWorkshop ? 'aktualisieren' : 'erstellen' ?>">
                            <?php if ($editWorkshop): ?>
                                <input type="hidden" name="workshop_id" value="<?= $editWorkshop['id'] ?>">
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <label>Titel <span class="required">*</span></label>
                                <input type="text" name="titel" class="form-control" required
                                       value="<?= htmlspecialchars($editWorkshop['titel'] ?? '') ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Beschreibung</label>
                                <textarea name="beschreibung" class="form-control" rows="3"><?= htmlspecialchars($editWorkshop['beschreibung'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>Datum <span class="required">*</span></label>
                                <input type="date" name="datum" class="form-control" required
                                       value="<?= $editWorkshop['datum'] ?? '' ?>">
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Beginn <span class="required">*</span></label>
                                    <input type="time" name="uhrzeit_start" class="form-control" required
                                           value="<?= $editWorkshop['uhrzeit_start'] ?? '14:00' ?>">
                                </div>
                                <div class="form-group">
                                    <label>Ende <span class="required">*</span></label>
                                    <input type="time" name="uhrzeit_ende" class="form-control" required
                                           value="<?= $editWorkshop['uhrzeit_ende'] ?? '17:00' ?>">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Ort <span class="required">*</span></label>
                                <input type="text" name="ort" class="form-control" required
                                       value="<?= htmlspecialchars($editWorkshop['ort'] ?? 'FabLab, Raum ...') ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Altersgruppe</label>
                                <input type="text" name="altersgruppe" class="form-control" placeholder="z.B. Klasse 5-7"
                                       value="<?= htmlspecialchars($editWorkshop['altersgruppe'] ?? '') ?>">
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Max. Teilnehmer</label>
                                    <input type="number" name="max_teilnehmer" class="form-control" min="1"
                                           value="<?= $editWorkshop['max_teilnehmer'] ?? MAX_TEILNEHMER ?>">
                                </div>
                                <div class="form-group">
                                    <label>Max. Warteliste</label>
                                    <input type="number" name="max_warteliste" class="form-control" min="0"
                                           value="<?= $editWorkshop['max_warteliste'] ?? MAX_WARTELISTE ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Max. 5./6. Klässler <small style="color: #666; font-weight: normal;">(leer = keine Begrenzung)</small></label>
                                <input type="number" name="max_5_6_klasse" class="form-control" min="1" placeholder="–"
                                       value="<?= htmlspecialchars($editWorkshop['max_5_6_klasse'] ?? '') ?>">
                            </div>
                            
                            <div style="display: flex; gap: 1rem;">
                                <button type="submit" class="btn btn-accent" style="flex: 1;">
                                    <?= $editWorkshop ? 'Speichern' : 'Workshop erstellen' ?>
                                </button>
                                <?php if ($editWorkshop): ?>
                                    <a href="workshops.php" class="btn btn-outline">Abbrechen</a>
                                <?php endif; ?>
                            </div>
                        </form>
                        
                        <?php if ($editWorkshop): ?>
                            <hr style="margin: 1.5rem 0;">
                            <div style="display: flex; gap: 0.5rem;">
                                <?php if ($editWorkshop['aktiv']): ?>
                                    <form method="post" style="flex: 1;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="deaktivieren">
                                        <input type="hidden" name="workshop_id" value="<?= $editWorkshop['id'] ?>">
                                        <button type="submit" class="btn btn-outline" style="width: 100%;">Deaktivieren</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" style="flex: 1;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="aktivieren">
                                        <input type="hidden" name="workshop_id" value="<?= $editWorkshop['id'] ?>">
                                        <button type="submit" class="btn btn-success" style="width: 100%;">Aktivieren</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('Workshop wirklich löschen? Dies ist nur möglich, wenn keine Anmeldungen existieren.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="loeschen">
                                    <input type="hidden" name="workshop_id" value="<?= $editWorkshop['id'] ?>">
                                    <button type="submit" class="btn btn-danger">Löschen</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <footer class="footer">
        <p>FabLab Workshop-Portal · Admin-Bereich</p>
    </footer>
</body>
</html>
