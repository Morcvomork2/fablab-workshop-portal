<?php
/**
 * FabLab Workshop-Portal - Admin Panel
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Schueler.php';
require_once __DIR__ . '/../includes/Workshop.php';
require_once __DIR__ . '/../includes/Anmeldung.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/Klassenwechsel.php';

$db = Database::getInstance();

// Login-Handling
$loginError = '';

if (isset($_GET['timeout'])) {
    $loginError = 'Sitzung abgelaufen. Bitte erneut anmelden.';
}

if (isset($_POST['login'])) {
    csrf_verify();
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $loginResult = admin_login($username, $password);
    if (!$loginResult['success']) {
        $loginError = $loginResult['error'];
    }
}

if (isset($_GET['logout'])) {
    admin_logout();
    header('Location: index.php');
    exit;
}

// Login pruefen (mit Timeout bei Untaetigkeit)
$warEingeloggt = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$isLoggedIn = admin_ist_eingeloggt();
if ($warEingeloggt && !$isLoggedIn) {
    $loginError = 'Sitzung abgelaufen. Bitte erneut anmelden.';
}

if (!$isLoggedIn):
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - FabLab Workshop-Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1e3a5f 0%, #152a45 100%);
        }
        .login-box {
            background: white;
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 400px;
        }
        .login-box h1 {
            text-align: center;
            margin: 0 0 0.5rem 0;
            color: #1e3a5f;
        }
        .login-box p {
            text-align: center;
            color: #666;
            margin: 0 0 2rem 0;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <h1>🔐 Admin Login</h1>
            <p>FabLab Workshop-Portal</p>
            
            <?php if ($loginError): ?>
                <div class="alert alert-danger">
                    <span class="alert-icon">&#10005;</span>
                    <div><?= htmlspecialchars($loginError) ?></div>
                </div>
            <?php endif; ?>
            
            <form method="post">
                <?= csrf_field() ?>
                <div class="form-group">
                    <label>Benutzername</label>
                    <input type="text" name="username" class="form-control" required autofocus>
                </div>
                <div class="form-group">
                    <label>Passwort</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" name="login" class="btn btn-primary btn-lg" style="width: 100%;">
                    Anmelden
                </button>
            </form>
        </div>
    </div>
</body>
</html>
<?php
exit;
endif;

// Ab hier: Eingeloggt
$workshopModel = new Workshop();
$schuelerModel = new Schueler();
$anmeldungModel = new Anmeldung();

// Statistiken
$pdo = $db->getPdo();

// Steht der Klassenwechsel für dieses Schuljahr noch aus, oder wurde er
// bereits durchgeführt? Beide Zustände schließen sich gegenseitig aus.
$klassenwechselFaellig = false;
$klassenwechselZahlen = ['aenderungen' => 0, 'pruefung' => 0];
$klassenwechselLauf = null;
try {
    $kw = new Klassenwechsel();
    if ($kw->istFaellig()) {
        $klassenwechselFaellig = true;
        $v = $kw->vorschau();
        $klassenwechselZahlen = [
            'aenderungen' => count($v['aenderungen']),
            'pruefung'    => count($v['pruefung']),
        ];
    } else {
        $klassenwechselLauf = $kw->letzterLauf();
    }
} catch (PDOException $e) {
    // Fehlen die Protokolltabellen noch, bleibt das Dashboard nutzbar.
    error_log('FabLab Klassenwechsel-Prüfung: ' . $e->getMessage());
}

$statsSchueler = $pdo->query("SELECT COUNT(*) FROM schueler WHERE aktiv = 1")->fetchColumn();
$statsWorkshops = $pdo->query("SELECT COUNT(*) FROM workshops WHERE aktiv = 1 AND datum >= CURDATE()")->fetchColumn();
$statsAnmeldungen = $pdo->query("SELECT COUNT(*) FROM anmeldungen WHERE status = 'bestaetigt'")->fetchColumn();
$statsWarteliste = $pdo->query("SELECT COUNT(*) FROM anmeldungen WHERE status = 'warteliste'")->fetchColumn();

// Nächste Workshops
$naechsteWorkshops = $pdo->query("
    SELECT w.*, 
           (SELECT COUNT(*) FROM anmeldungen WHERE workshop_id = w.id AND status = 'bestaetigt') as teilnehmer,
           (SELECT COUNT(*) FROM anmeldungen WHERE workshop_id = w.id AND status = 'warteliste') as warteliste
    FROM workshops w 
    WHERE w.aktiv = 1 AND w.datum >= CURDATE()
    ORDER BY w.datum, w.uhrzeit_start
    LIMIT 5
")->fetchAll();

// Letzte Registrierungen
$letzteRegistrierungen = $pdo->query("
    SELECT * FROM schueler ORDER BY erstellt DESC LIMIT 5
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - FabLab Workshop-Portal</title>
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
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-card .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: #1e3a5f;
        }
        .stat-card .stat-label {
            color: #666;
            margin-top: 0.25rem;
        }
        .stat-card.accent .stat-value { color: #e65100; }
        .stat-card.success .stat-value { color: #2e7d32; }
        .stat-card.warning .stat-value { color: #f57c00; }
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
            <li><a href="index.php" class="active">Dashboard</a></li>
            <li><a href="workshops.php">Workshops</a></li>
            <li><a href="schueler.php">Schüler</a></li>
            <li><a href="anmeldungen.php">Anmeldungen</a></li>
        </ul>
    </nav>
    
    <main class="main">
        <h2 style="margin-bottom: 1.5rem;">📊 Dashboard</h2>

        <?php if ($klassenwechselFaellig && $klassenwechselZahlen['aenderungen'] > 0): ?>
            <div class="alert alert-warning">
                <strong>Schuljahreswechsel <?= htmlspecialchars(Klassenwechsel::aktuellesSchuljahr()) ?> steht an.</strong>
                <?= (int)$klassenwechselZahlen['aenderungen'] ?> Klassen können erhöht werden,
                <?= (int)$klassenwechselZahlen['pruefung'] ?> brauchen deine Prüfung.
                <a href="klassenwechsel.php">Vorschau ansehen</a>
            </div>
        <?php elseif ($klassenwechselLauf !== null): ?>
            <div class="alert alert-info">Klassenwechsel <?= htmlspecialchars(Klassenwechsel::aktuellesSchuljahr()) ?> durchgeführt: <?= (int)$klassenwechselLauf['anzahl_geaendert'] ?> Klassen erhöht. <a href="klassenwechsel.php">Ansehen oder zurücknehmen</a></div>
        <?php endif; ?>

        <!-- Statistiken -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $statsSchueler ?></div>
                <div class="stat-label">Registrierte Schüler</div>
            </div>
            <div class="stat-card accent">
                <div class="stat-value"><?= $statsWorkshops ?></div>
                <div class="stat-label">Kommende Workshops</div>
            </div>
            <div class="stat-card success">
                <div class="stat-value"><?= $statsAnmeldungen ?></div>
                <div class="stat-label">Bestätigte Anmeldungen</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-value"><?= $statsWarteliste ?></div>
                <div class="stat-label">Auf Warteliste</div>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 2rem;">
            <!-- Nächste Workshops -->
            <div class="card">
                <div class="card-header">
                    📅 Nächste Workshops
                    <a href="workshops.php" class="btn btn-sm btn-outline">Alle anzeigen</a>
                </div>
                <div class="card-body" style="padding: 0;">
                    <?php if (empty($naechsteWorkshops)): ?>
                        <div class="empty-state" style="padding: 2rem;">
                            <p>Keine kommenden Workshops</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <tbody>
                                <?php foreach ($naechsteWorkshops as $ws): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($ws['titel']) ?></strong><br>
                                            <small style="color: #666;"><?= date('d.m.Y', strtotime($ws['datum'])) ?>, <?= date('H:i', strtotime($ws['uhrzeit_start'])) ?> Uhr</small>
                                        </td>
                                        <td style="text-align: right;">
                                            <span class="status-badge status-verfuegbar"><?= $ws['teilnehmer'] ?>/<?= $ws['max_teilnehmer'] ?? MAX_TEILNEHMER ?></span>
                                            <?php if ($ws['warteliste'] > 0): ?>
                                                <span class="status-badge status-warteliste">+<?= $ws['warteliste'] ?> WL</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Letzte Registrierungen -->
            <div class="card">
                <div class="card-header">
                    👥 Letzte Registrierungen
                    <a href="schueler.php" class="btn btn-sm btn-outline">Alle anzeigen</a>
                </div>
                <div class="card-body" style="padding: 0;">
                    <?php if (empty($letzteRegistrierungen)): ?>
                        <div class="empty-state" style="padding: 2rem;">
                            <p>Noch keine Registrierungen</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <tbody>
                                <?php foreach ($letzteRegistrierungen as $s): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($s['vorname'] . ' ' . $s['nachname']) ?></strong><br>
                                            <small style="color: #666;"><?= htmlspecialchars($s['klasse']) ?> · <?= $s['typ'] ?></small>
                                        </td>
                                        <td style="text-align: right; color: #666;">
                                            <?= date('d.m.Y', strtotime($s['erstellt'])) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    
    <footer class="footer">
        <p>FabLab Workshop-Portal · Admin-Bereich</p>
    </footer>
</body>
</html>
