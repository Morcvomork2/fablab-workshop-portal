<?php
/**
 * FabLab Workshop-Portal - Admin: Schüler-Verwaltung
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Schueler.php';
require_once __DIR__ . '/../includes/Anmeldung.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin_auth.php';

$db = Database::getInstance();

admin_require_login();

$schuelerModel = new Schueler();
$anmeldungModel = new Anmeldung();

$message = '';
$messageType = '';

// Aktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $schuelerId = (int)($_POST['schueler_id'] ?? 0);
    
    if ($action === 'deaktivieren' && $schuelerId > 0) {
        $schuelerModel->deaktivieren($schuelerId);
        $message = 'Schüler deaktiviert.';
        $messageType = 'success';
    }
    
    if ($action === 'aktivieren' && $schuelerId > 0) {
        $schuelerModel->aktivieren($schuelerId);
        $message = 'Schüler aktiviert.';
        $messageType = 'success';
    }
}

// Suchfilter
$search = $_GET['search'] ?? '';
$filterTyp = $_GET['typ'] ?? '';

// Schüler laden
$pdo = $db->getPdo();
$sql = "SELECT s.*, 
        (SELECT COUNT(*) FROM anmeldungen WHERE schueler_id = s.id AND status = 'bestaetigt') as buchungen_bestaetigt,
        (SELECT COUNT(*) FROM anmeldungen WHERE schueler_id = s.id AND status = 'warteliste') as buchungen_warteliste
        FROM schueler s WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (s.vorname LIKE ? OR s.nachname LIKE ? OR s.email LIKE ? OR s.klasse LIKE ?)";
    $searchParam = '%' . $search . '%';
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
}

if (!empty($filterTyp)) {
    $sql .= " AND s.typ = ?";
    $params[] = $filterTyp;
}

$sql .= " ORDER BY s.nachname, s.vorname";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$schueler = $stmt->fetchAll();

// CSV-Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="schueler_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    
    fputcsv($output, ['Vorname', 'Nachname', 'Klasse', 'Typ', 'Elternteil', 'Notfall-Tel.', 'E-Mail', 'Registriert', 'Buchungen'], ';');
    
    foreach ($schueler as $s) {
        fputcsv($output, [
            $s['vorname'],
            $s['nachname'],
            $s['klasse'],
            $s['typ'],
            $s['elternteil_name'],
            $s['notfall_telefon'],
            $s['email'],
            date('d.m.Y', strtotime($s['erstellt'])),
            $s['buchungen_bestaetigt']
        ], ';');
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
    <title>Schüler verwalten - FabLab Admin</title>
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
            <li><a href="schueler.php" class="active">Schüler</a></li>
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
        
        <h2 style="margin-bottom: 1.5rem;">👥 Registrierte Schüler (<?= count($schueler) ?>)</h2>
        
        <!-- Filter -->
        <form method="get" class="filter-bar">
            <div class="form-group">
                <label>Suche</label>
                <input type="text" name="search" class="form-control" placeholder="Name, E-Mail, Klasse..." 
                       value="<?= htmlspecialchars($search) ?>" style="width: 250px;">
            </div>
            <div class="form-group">
                <label>Typ</label>
                <select name="typ" class="form-control">
                    <option value="">Alle</option>
                    <option value="GFB" <?= $filterTyp === 'GFB' ? 'selected' : '' ?>>GFB-Schüler</option>
                    <option value="extern" <?= $filterTyp === 'extern' ? 'selected' : '' ?>>Externe</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Filtern</button>
            <?php if ($search || $filterTyp): ?>
                <a href="schueler.php" class="btn btn-outline">Zurücksetzen</a>
            <?php endif; ?>
            <div style="margin-left: auto;">
                <a href="?export=csv<?= $search ? '&search=' . urlencode($search) : '' ?><?= $filterTyp ? '&typ=' . urlencode($filterTyp) : '' ?>" class="btn btn-accent">
                    📥 CSV-Export
                </a>
            </div>
        </form>
        
        <div class="card">
            <div class="card-body" style="padding: 0;">
                <?php if (empty($schueler)): ?>
                    <div class="empty-state" style="padding: 3rem;">
                        <div class="empty-state-icon">👥</div>
                        <h3>Keine Schüler gefunden</h3>
                        <p>Es wurden keine Schüler mit den gewählten Filterkriterien gefunden.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Klasse</th>
                                    <th>Typ</th>
                                    <th>Elternteil</th>
                                    <th>Kontakt</th>
                                    <th>Buchungen</th>
                                    <th>Registriert</th>
                                    <th>Status</th>
                                    <th>Aktionen</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($schueler as $s): ?>
                                    <tr style="<?= !$s['aktiv'] ? 'opacity: 0.5;' : '' ?>">
                                        <td>
                                            <strong><?= htmlspecialchars($s['vorname'] . ' ' . $s['nachname']) ?></strong>
                                        </td>
                                        <td><?= htmlspecialchars($s['klasse']) ?></td>
                                        <td>
                                            <?php if ($s['typ'] === 'GFB'): ?>
                                                <span class="status-badge status-bestaetigt">GFB</span>
                                            <?php else: ?>
                                                <span class="status-badge status-warteliste">Extern</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($s['elternteil_name']) ?></td>
                                        <td>
                                            <small>
                                                📧 <?= htmlspecialchars($s['email']) ?><br>
                                                📞 <?= htmlspecialchars($s['notfall_telefon']) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="status-badge status-bestaetigt"><?= $s['buchungen_bestaetigt'] ?></span>
                                            <?php if ($s['buchungen_warteliste'] > 0): ?>
                                                <span class="status-badge status-warteliste">+<?= $s['buchungen_warteliste'] ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= date('d.m.Y', strtotime($s['erstellt'])) ?></td>
                                        <td>
                                            <?php if ($s['aktiv']): ?>
                                                <span class="status-badge status-bestaetigt">Aktiv</span>
                                            <?php else: ?>
                                                <span class="status-badge status-storniert">Deaktiviert</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($s['aktiv']): ?>
                                                <form method="post" style="display: inline;" onsubmit="return confirm('Schüler wirklich deaktivieren?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="deaktivieren">
                                                    <input type="hidden" name="schueler_id" value="<?= $s['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline">Deaktivieren</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="post" style="display: inline;">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="aktivieren">
                                                    <input type="hidden" name="schueler_id" value="<?= $s['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-success">Aktivieren</button>
                                                </form>
                                            <?php endif; ?>
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
