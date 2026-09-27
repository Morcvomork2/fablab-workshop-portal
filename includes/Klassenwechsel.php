<?php
/**
 * Klassenwechsel zum Schuljahresbeginn (MySQL-Version)
 *
 * Erhöht eindeutig erkennbare Klassenangaben um eine Stufe.
 * Alles Uneindeutige bleibt unverändert und wird gemeldet.
 */
class Klassenwechsel {

    private $db;

    public function __construct(?PDO $pdo = null) {
        // Ohne Argument die normale Verbindung - mit Argument eine Testdatenbank.
        $this->db = $pdo ?? Database::getInstance()->getPdo();
    }

    /**
     * Bezeichnung des laufenden Schuljahres, z. B. "2026/27".
     * Ein Schuljahr beginnt am 1. August.
     */
    public static function aktuellesSchuljahr(?DateTimeInterface $stichtag = null): string {
        $tag = $stichtag ?? new DateTimeImmutable();
        $jahr = (int)$tag->format('Y');
        if ((int)$tag->format('n') < 8) {
            $jahr--;
        }
        return $jahr . '/' . substr((string)($jahr + 1), -2);
    }

    /**
     * Nächster Klassenwert oder null, wenn nicht eindeutig erhöhbar.
     *
     * Erkannt wird eine führende Jahrgangszahl 5-9 mit optionalem
     * Klassenbuchstaben. Ab Jahrgang 10 und für Oberstufenkürzel wird
     * bewusst nicht gerechnet - der Übergang 10 -> EF ist nicht
     * mechanisch ableitbar.
     */
    public static function naechsteKlasse(string $klasse): ?string {
        $wert = trim($klasse);
        if (!preg_match('/^0*([5-9])\s*([a-zA-Z]?)$/', $wert, $treffer)) {
            return null;
        }
        return ((int)$treffer[1] + 1) . $treffer[2];
    }

    /**
     * Legt die beiden Protokolltabellen an, falls sie fehlen.
     * Bestehende Tabellen werden nicht angefasst.
     */
    public function tabellenAnlegen(): void {
        // Wortgleich mit den CREATE-TABLE-Anweisungen in
        // Database::createTables() (includes/Database.php). Beide Stellen bei Änderungen pflegen.
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS klassenwechsel_laeufe (
                id INT AUTO_INCREMENT PRIMARY KEY,
                schuljahr VARCHAR(9) NOT NULL,
                ausgefuehrt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
                anzahl_geaendert INT NOT NULL DEFAULT 0,
                UNIQUE KEY unique_schuljahr (schuljahr)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS klassenwechsel_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                lauf_id INT NOT NULL,
                schueler_id INT NOT NULL,
                klasse_alt VARCHAR(20) NOT NULL,
                klasse_neu VARCHAR(20) NOT NULL,
                FOREIGN KEY (lauf_id) REFERENCES klassenwechsel_laeufe(id) ON DELETE CASCADE,
                INDEX idx_lauf (lauf_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Lauf eines Schuljahres oder null, wenn noch keiner stattfand.
     */
    public function letzterLauf(?string $schuljahr = null): ?array {
        $schuljahr = $schuljahr ?? self::aktuellesSchuljahr();
        $stmt = $this->db->prepare("SELECT * FROM klassenwechsel_laeufe WHERE schuljahr = ?");
        $stmt->execute([$schuljahr]);
        $zeile = $stmt->fetch();
        return $zeile ?: null;
    }

    /**
     * Steht die Umstellung für das laufende Schuljahr noch aus?
     */
    public function istFaellig(?DateTimeInterface $stichtag = null): bool {
        return $this->letzterLauf(self::aktuellesSchuljahr($stichtag)) === null;
    }

    /**
     * Was würde eine Umstellung bewirken? Rein lesend.
     *
     * Deaktivierte Datensätze werden bewusst mit erfasst, damit die
     * Klasse bei einer späteren Reaktivierung stimmt.
     */
    public function vorschau(): array {
        $stmt = $this->db->query("
            SELECT id, vorname, nachname, klasse, aktiv
            FROM schueler
            ORDER BY nachname, vorname
        ");

        $aenderungen = [];
        $pruefung = [];
        foreach ($stmt as $s) {
            $neu = self::naechsteKlasse($s['klasse']);
            if ($neu === null) {
                $pruefung[] = [
                    'id'       => (int)$s['id'],
                    'vorname'  => $s['vorname'],
                    'nachname' => $s['nachname'],
                    'klasse'   => $s['klasse'],
                    'aktiv'    => (int)$s['aktiv'],
                ];
                continue;
            }
            $aenderungen[] = [
                'id'         => (int)$s['id'],
                'vorname'    => $s['vorname'],
                'nachname'   => $s['nachname'],
                'klasse_alt' => $s['klasse'],
                'klasse_neu' => $neu,
                'aktiv'      => (int)$s['aktiv'],
            ];
        }

        return ['aenderungen' => $aenderungen, 'pruefung' => $pruefung];
    }

    /**
     * Führt die Umstellung für das laufende Schuljahr aus.
     *
     * Alles oder nichts: Bricht etwas ab, bleibt der alte Zustand
     * vollständig erhalten. Jeder geänderte Wert wird protokolliert.
     */
    public function ausfuehren(?DateTimeInterface $stichtag = null): array {
        $schuljahr = self::aktuellesSchuljahr($stichtag);

        if ($this->letzterLauf($schuljahr) !== null) {
            return [
                'success' => false,
                'anzahl'  => 0,
                'error'   => 'Für das Schuljahr ' . $schuljahr . ' wurde die Umstellung bereits durchgeführt.',
            ];
        }

        $vorschau = $this->vorschau();
        if (!$vorschau['aenderungen']) {
            return ['success' => false, 'anzahl' => 0, 'error' => 'Es gibt nichts zu ändern.'];
        }

        try {
            $this->db->beginTransaction();

            // Der eindeutige Schlüssel auf schuljahr verhindert einen
            // zweiten Lauf auch bei gleichzeitigen Klicks.
            $stmt = $this->db->prepare("
                INSERT INTO klassenwechsel_laeufe (schuljahr, anzahl_geaendert)
                VALUES (?, ?)
            ");
            $stmt->execute([$schuljahr, 0]);
            $laufId = (int)$this->db->lastInsertId();

            $protokoll = $this->db->prepare("
                INSERT INTO klassenwechsel_log (lauf_id, schueler_id, klasse_alt, klasse_neu)
                VALUES (?, ?, ?, ?)
            ");
            $aendern = $this->db->prepare("UPDATE schueler SET klasse = ? WHERE id = ? AND klasse = ?");

            $anzahl = 0;
            foreach ($vorschau['aenderungen'] as $a) {
                // Nur ändern, solange der gelesene Wert noch gilt - sonst hat jemand
                // dazwischen korrigiert und das Protokoll wäre nicht mehr exakt.
                $aendern->execute([$a['klasse_neu'], $a['id'], $a['klasse_alt']]);
                if ($aendern->rowCount() === 1) {
                    $protokoll->execute([$laufId, $a['id'], $a['klasse_alt'], $a['klasse_neu']]);
                    $anzahl++;
                }
            }

            $stmt = $this->db->prepare("UPDATE klassenwechsel_laeufe SET anzahl_geaendert = ? WHERE id = ?");
            $stmt->execute([$anzahl, $laufId]);

            $this->db->commit();
            return ['success' => true, 'anzahl' => $anzahl, 'error' => null];

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('FabLab Klassenwechsel: ' . $e->getMessage());
            return [
                'success' => false,
                'anzahl'  => 0,
                'error'   => 'Die Umstellung wurde abgebrochen, es wurde nichts geändert.',
            ];
        }
    }

    /**
     * Nimmt den Lauf des laufenden Schuljahres zurück.
     *
     * Jeder protokollierte Wert wird zurückgeschrieben; anschließend
     * werden Lauf und Protokoll gelöscht, sodass das Schuljahr wieder
     * offen ist.
     */
    public function zuruecknehmen(?DateTimeInterface $stichtag = null): array {
        $schuljahr = self::aktuellesSchuljahr($stichtag);
        $lauf = $this->letzterLauf($schuljahr);

        if ($lauf === null) {
            return [
                'success' => false,
                'anzahl'  => 0,
                'error'   => 'Für das Schuljahr ' . $schuljahr . ' gibt es keine Umstellung zum Zurücknehmen.',
            ];
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                SELECT schueler_id, klasse_alt FROM klassenwechsel_log WHERE lauf_id = ?
            ");
            $stmt->execute([$lauf['id']]);
            $zeilen = $stmt->fetchAll();

            $zurueck = $this->db->prepare("UPDATE schueler SET klasse = ? WHERE id = ?");
            foreach ($zeilen as $z) {
                $zurueck->execute([$z['klasse_alt'], $z['schueler_id']]);
            }

            // Erst das Protokoll, dann den Lauf - die Fremdschlüssel-Regel
            // ON DELETE CASCADE greift in SQLite-Tests nicht.
            $stmt = $this->db->prepare("DELETE FROM klassenwechsel_log WHERE lauf_id = ?");
            $stmt->execute([$lauf['id']]);
            $stmt = $this->db->prepare("DELETE FROM klassenwechsel_laeufe WHERE id = ?");
            $stmt->execute([$lauf['id']]);

            $this->db->commit();
            return ['success' => true, 'anzahl' => count($zeilen), 'error' => null];

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('FabLab Klassenwechsel-Rücknahme: ' . $e->getMessage());
            return [
                'success' => false,
                'anzahl'  => 0,
                'error'   => 'Die Rücknahme wurde abgebrochen, es wurde nichts geändert.',
            ];
        }
    }

    /**
     * Korrigiert die Klasse eines einzelnen Datensatzes von Hand.
     *
     * Bewusst nicht über Schueler::update() - jene Methode schreibt alle
     * Felder und würde Name, Elternteil und Telefon leeren. Hier wird
     * ausschließlich die Spalte klasse angefasst.
     */
    public function klasseKorrigieren(int $schuelerId, string $neueKlasse): bool {
        $wert = trim($neueKlasse);
        if ($schuelerId <= 0 || $wert === '' || strlen($wert) > 20) {
            return false;
        }
        $stmt = $this->db->prepare("UPDATE schueler SET klasse = ? WHERE id = ?");
        return $stmt->execute([$wert, $schuelerId]);
    }
}
