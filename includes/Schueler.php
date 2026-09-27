<?php
/**
 * Schüler-Model (MySQL-Version)
 */
class Schueler {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getPdo();
    }
    
    /**
     * Neuen Schüler registrieren
     */
    public function registrieren($daten) {
        // Prüfen ob E-Mail bereits existiert
        $stmt = $this->db->prepare("SELECT id FROM schueler WHERE email = ?");
        $stmt->execute([$daten['email']]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'Diese E-Mail-Adresse ist bereits registriert.'];
        }
        
        // Token generieren
        $token = bin2hex(random_bytes(32));
        
        $stmt = $this->db->prepare("
            INSERT INTO schueler (vorname, nachname, klasse, elternteil_name, notfall_telefon, email, typ, einwilligung, token, token_erstellt)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        try {
            $stmt->execute([
                $daten['vorname'],
                $daten['nachname'],
                $daten['klasse'],
                $daten['elternteil_name'],
                $daten['notfall_telefon'],
                $daten['email'],
                $daten['typ'],
                $daten['einwilligung'] ? 1 : 0,
                $token
            ]);
            
            return [
                'success' => true,
                'id' => $this->db->lastInsertId(),
                'token' => $token
            ];
        } catch (PDOException $e) {
            error_log('FabLab DB-Fehler: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Datenbankfehler bei der Registrierung.'];
        }
    }
    
    /**
     * Schüler per Token laden. Abgelaufene Zugangslinks (älter als
     * TOKEN_VALIDITY_DAYS) gelten als ungültig.
     */
    public function getByToken($token) {
        $stmt = $this->db->prepare("
            SELECT * FROM schueler WHERE token = ? AND aktiv = 1 AND token_erstellt >= ?
        ");
        $stmt->execute([$token, $this->tokenGueltigAb()]);
        return $stmt->fetch();
    }

    /**
     * Ältester Erstellungszeitpunkt, zu dem ein Token noch gültig ist.
     */
    private function tokenGueltigAb(): string {
        $tage = defined('TOKEN_VALIDITY_DAYS') ? (int)TOKEN_VALIDITY_DAYS : 365;
        return date('Y-m-d H:i:s', time() - $tage * 86400);
    }
    
    /**
     * Schüler per ID laden
     */
    public function getById($id) {
        $stmt = $this->db->prepare("SELECT * FROM schueler WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    /**
     * Alle Schüler laden
     */
    public function getAll() {
        $stmt = $this->db->query("SELECT * FROM schueler ORDER BY nachname, vorname");
        return $stmt->fetchAll();
    }
    
    /**
     * Schüler aktualisieren
     */
    public function update($id, $daten) {
        $stmt = $this->db->prepare("
            UPDATE schueler 
            SET vorname = ?, nachname = ?, klasse = ?, elternteil_name = ?, notfall_telefon = ?, typ = ?
            WHERE id = ?
        ");
        
        return $stmt->execute([
            $daten['vorname'],
            $daten['nachname'],
            $daten['klasse'],
            $daten['elternteil_name'],
            $daten['notfall_telefon'],
            $daten['typ'],
            $id
        ]);
    }
    
    /**
     * Anzahl offener Buchungen für Schüler
     */
    public function getOffeneBuchungen($schuelerId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as anzahl
            FROM anmeldungen a
            JOIN workshops w ON a.workshop_id = w.id
            WHERE a.schueler_id = ?
            AND a.status IN ('bestaetigt', 'warteliste')
            AND CONCAT(w.datum, ' ', w.uhrzeit_ende) > NOW()
        ");
        $stmt->execute([$schuelerId]);
        $result = $stmt->fetch();
        return $result['anzahl'];
    }

    /**
     * Anzahl aller nicht-stornierten Anmeldungen (für Erfahrungslevel).
     * Zählt bestaetigt + warteliste — beides zählt als aktives Engagement.
     */
    public function getAnzahlAnmeldungen(int $schuelerId): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as anzahl
            FROM anmeldungen
            WHERE schueler_id = ?
            AND status != 'storniert'
        ");
        $stmt->execute([$schuelerId]);
        $result = $stmt->fetch();
        return (int)$result['anzahl'];
    }

    /**
     * Neuen Zugangslink generieren
     */
    public function regenerateToken($email) {
        $token = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare("
            UPDATE schueler SET token = ?, token_erstellt = NOW() WHERE email = ? AND aktiv = 1
        ");
        $stmt->execute([$token, $email]);
        
        if ($stmt->rowCount() > 0) {
            return $token;
        }
        return false;
    }

    /**
     * Einmal-Code generieren und gehasht speichern. Gibt false zurück bei unbekannter/inaktiver E-Mail.
     */
    public function generateOtp(string $email): string|false {
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = password_hash($code, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("
            UPDATE schueler SET otp_code = ?, otp_expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE)
            WHERE email = ? AND aktiv = 1
        ");
        $stmt->execute([$hash, $email]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        return $code;
    }

    /**
     * Einmal-Code prüfen. Bei Erfolg: OTP-Felder leeren, Schüler-Array zurückgeben. Sonst false.
     */
    public function verifyOtp(string $email, string $code): array|false {
        $stmt = $this->db->prepare("
            SELECT * FROM schueler
            WHERE email = ? AND aktiv = 1 AND otp_expires_at > NOW()
        ");
        $stmt->execute([$email]);
        $schueler = $stmt->fetch();
        if (!$schueler || !password_verify($code, $schueler['otp_code'])) {
            return false;
        }
        $stmt = $this->db->prepare("
            UPDATE schueler SET otp_code = NULL, otp_expires_at = NULL WHERE id = ?
        ");
        $stmt->execute([$schueler['id']]);
        unset($schueler['otp_code'], $schueler['otp_expires_at']);

        // Ist der Zugangslink abgelaufen, wird ein neuer ausgestellt,
        // damit die Anmeldung per Code weiter funktioniert.
        if (!$this->getByToken($schueler['token'])) {
            $neu = $this->regenerateToken($schueler['email']);
            if ($neu) {
                $schueler['token'] = $neu;
            }
        }
        return $schueler;
    }

    /**
     * Einmal-Code verwerfen (z.B. nach zu vielen Fehlversuchen).
     */
    public function otpVerwerfen(string $email): void {
        $stmt = $this->db->prepare("
            UPDATE schueler SET otp_code = NULL, otp_expires_at = NULL WHERE email = ?
        ");
        $stmt->execute([$email]);
    }

    /**
     * Schüler deaktivieren
     */
    public function deaktivieren($id) {
        $stmt = $this->db->prepare("UPDATE schueler SET aktiv = 0 WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Schüler aktivieren
     */
    public function aktivieren($id) {
        $stmt = $this->db->prepare("UPDATE schueler SET aktiv = 1 WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
