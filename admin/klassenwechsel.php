<?php
/**
 * FabLab Workshop-Portal - Klassenwechsel zum Schuljahresbeginn
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Klassenwechsel.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/admin_auth.php';

admin_require_login();

$kw = new Klassenwechsel();
$kw->tabellenAnlegen();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'ausfuehren') {
        $result = $kw->ausfuehren();
        if ($result['success']) {
            $message = 'Umstellung durchgeführt: ' . $result['anzahl'] . ' Klassen erhöht.';
            $messageType = 'success';
        } else {
            $message = $result['error'];
            $messageType = 'danger';
        }
    }

    if ($action === 'zuruecknehmen') {
        $result = $kw->zuruecknehmen();
        if ($result['success']) {
            $message = 'Umstellung zurückgenommen: ' . $result['anzahl'] . ' Klassen wiederhergestellt.';
            $messageType = 'success';
        } else {
            $message = $result['error'];
            $messageType = 'danger';
        }
    }

    if ($action === 'klasse_korrigieren') {
        $schuelerId = (int)($_POST['schueler_id'] ?? 0);
        if ($kw->klasseKorrigieren($schuelerId, $_POST['klasse'] ?? '')) {
            $message = 'Klasse geändert.';
            $messageType = 'success';
        } else {
            $message = 'Bitte eine Klasse mit höchstens 20 Zeichen angeben.';
            $messageType = 'danger';
        }
    }
}

$schuljahr = Klassenwechsel::aktuellesSchuljahr();
$lauf      = $kw->letzterLauf($schuljahr);
$vorschau  = $kw->vorschau();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Klassenwechsel - FabLab Admin</title>
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
        <div class="container">
            <h1>Klassenwechsel <?= htmlspecialchars($schuljahr) ?></h1>

            <?php if ($message): ?>
                <div class="alert alert-<?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if ($lauf): ?>
                <div class="alert alert-info">
                    Die Umstellung für <?= htmlspecialchars($schuljahr) ?> wurde am
                    <?= htmlspecialchars(date('d.m.Y \u\m H:i', strtotime($lauf['ausgefuehrt_am']))) ?> Uhr
                    durchgeführt: <?= (int)$lauf['anzahl_geaendert'] ?> Klassen erhöht.
                </div>
                <form method="post" onsubmit="return confirm('Umstellung wirklich zurücknehmen? Alle Klassen erhalten ihren vorherigen Wert.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="zuruecknehmen">
                    <button type="submit" class="btn btn-danger">Umstellung zurücknehmen</button>
                </form>
            <?php endif; ?>

            <?php if ($lauf): ?>
                <h2>Vorschau für das kommende Schuljahr (<?= count($vorschau['aenderungen']) ?>)</h2>
                <p>Diese Umstellung betrifft bereits das nächste Schuljahr und lässt sich
                   erst nach dem kommenden 1. August ausführen.</p>
            <?php else: ?>
                <h2>Geplante Änderungen (<?= count($vorschau['aenderungen']) ?>)</h2>
            <?php endif; ?>
            <?php if (!$vorschau['aenderungen']): ?>
                <p>Es gibt derzeit keine Klasse, die sich eindeutig erhöhen lässt.</p>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr><th>Nachname</th><th>Vorname</th><th>bisher</th><th>neu</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vorschau['aenderungen'] as $a): ?>
                                <tr style="<?= $a['aktiv'] ? '' : 'opacity: 0.5;' ?>">
                                    <td><?= htmlspecialchars($a['nachname']) ?></td>
                                    <td><?= htmlspecialchars($a['vorname']) ?></td>
                                    <td><?= htmlspecialchars($a['klasse_alt']) ?></td>
                                    <td><strong><?= htmlspecialchars($a['klasse_neu']) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!$lauf): ?>
                    <form method="post" onsubmit="return confirm('Umstellung jetzt durchführen? <?= count($vorschau['aenderungen']) ?> Klassen werden erhöht. Der Schritt lässt sich anschließend zurücknehmen.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="ausfuehren">
                        <button type="submit" class="btn btn-primary">Umstellung durchführen</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <h2>Zur Prüfung (<?= count($vorschau['pruefung']) ?>)</h2>
            <p>Diese Angaben lassen sich nicht eindeutig erhöhen und bleiben unverändert.
               Jahrgang 10, die Oberstufe und alles Uneindeutige gehören hierher.</p>
            <?php if (!$vorschau['pruefung']): ?>
                <p>Nichts zu prüfen.</p>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr><th>Nachname</th><th>Vorname</th><th>aktuell</th><th>korrigieren</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vorschau['pruefung'] as $s): ?>
                                <tr style="<?= $s['aktiv'] ? '' : 'opacity: 0.5;' ?>">
                                    <td><?= htmlspecialchars($s['nachname']) ?></td>
                                    <td><?= htmlspecialchars($s['vorname']) ?></td>
                                    <td><?= htmlspecialchars($s['klasse']) ?></td>
                                    <td>
                                        <form method="post" style="display: flex; gap: 0.5rem; margin: 0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="klasse_korrigieren">
                                            <input type="hidden" name="schueler_id" value="<?= (int)$s['id'] ?>">
                                            <input type="text" name="klasse" maxlength="20"
                                                   value="<?= htmlspecialchars($s['klasse']) ?>"
                                                   class="form-control" style="max-width: 8rem;">
                                            <button type="submit" class="btn btn-outline">Speichern</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
