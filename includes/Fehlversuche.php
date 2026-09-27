<?php
/**
 * Zählt Fehlversuche und Anfragen serverseitig in der Datenbank.
 *
 * Früher lagen die Zähler in der PHP-Session. Wer sein Cookie löschte,
 * bekam einen frischen Zähler und konnte unbegrenzt weiterprobieren.
 * Hier hängt der Zähler an einem Schlüssel (z.B. IP-Adresse oder E-Mail),
 * der vom Browser unabhängig ist.
 *
 * Schlüssel werden nur als SHA-256-Hash gespeichert, damit in der Tabelle
 * keine E-Mail-Adressen oder IP-Adressen im Klartext liegen.
 *
 * Die Tabelle legt sich beim ersten Gebrauch selbst an. Das SQL ist
 * bewusst schlicht gehalten und läuft unter MySQL wie unter SQLite (Tests).
 */
class Fehlversuche {
    private $db;
    private static $tabelleGeprueft = false;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->getPdo();
        if (!self::$tabelleGeprueft) {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS fehlversuche (
                    schluessel CHAR(64) NOT NULL PRIMARY KEY,
                    anzahl INT NOT NULL,
                    fenster_start INT NOT NULL
                )
            ");
            self::$tabelleGeprueft = true;
        }
    }

    /**
     * Ist die Grenze für diesen Schlüssel erreicht?
     * Gibt die verbleibende Sperrzeit in Sekunden zurück, 0 = nicht gesperrt.
     */
    public function gesperrt(string $schluessel, int $max, int $fensterSekunden): int {
        $stmt = $this->db->prepare("SELECT anzahl, fenster_start FROM fehlversuche WHERE schluessel = ?");
        $stmt->execute([$this->hash($schluessel)]);
        $zeile = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$zeile) {
            return 0;
        }
        $vergangen = $this->jetzt() - (int)$zeile['fenster_start'];
        if ($vergangen >= $fensterSekunden || (int)$zeile['anzahl'] < $max) {
            return 0;
        }
        return $fensterSekunden - $vergangen;
    }

    /**
     * Wie viele Versuche wurden im laufenden Zeitfenster gezählt?
     */
    public function anzahl(string $schluessel, int $fensterSekunden): int {
        $stmt = $this->db->prepare("SELECT anzahl, fenster_start FROM fehlversuche WHERE schluessel = ?");
        $stmt->execute([$this->hash($schluessel)]);
        $zeile = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$zeile || $this->jetzt() - (int)$zeile['fenster_start'] >= $fensterSekunden) {
            return 0;
        }
        return (int)$zeile['anzahl'];
    }

    /**
     * Einen Versuch zählen. Ist das Zeitfenster abgelaufen, beginnt ein neues.
     */
    public function zaehlen(string $schluessel, int $fensterSekunden): void {
        $hash = $this->hash($schluessel);
        $jetzt = $this->jetzt();

        $stmt = $this->db->prepare("
            UPDATE fehlversuche SET anzahl = anzahl + 1
            WHERE schluessel = ? AND fenster_start > ?
        ");
        $stmt->execute([$hash, $jetzt - $fensterSekunden]);

        if ($stmt->rowCount() === 0) {
            $stmt = $this->db->prepare("REPLACE INTO fehlversuche (schluessel, anzahl, fenster_start) VALUES (?, 1, ?)");
            $stmt->execute([$hash, $jetzt]);
        }

        // Gelegentlich alte Einträge aufräumen (älter als ein Tag)
        if (random_int(1, 50) === 1) {
            $stmt = $this->db->prepare("DELETE FROM fehlversuche WHERE fenster_start < ?");
            $stmt->execute([$jetzt - 86400]);
        }
    }

    /**
     * Zähler zurücksetzen, z.B. nach erfolgreicher Anmeldung.
     */
    public function zuruecksetzen(string $schluessel): void {
        $stmt = $this->db->prepare("DELETE FROM fehlversuche WHERE schluessel = ?");
        $stmt->execute([$this->hash($schluessel)]);
    }

    protected function jetzt(): int {
        return time();
    }

    private function hash(string $schluessel): string {
        return hash('sha256', $schluessel);
    }
}
