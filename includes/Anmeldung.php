<?php
/**
 * Anmeldung-Model (MySQL-Version)
 */
class Anmeldung {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getPdo();
    }
    
    /**
     * Neue Anmeldung erstellen
     */
    public function anmelden($schuelerId, $workshopId) {
        $workshop = new Workshop();
        $schueler = new Schueler();
        
        // Workshop laden
        $ws = $workshop->getMitStatus($workshopId);
        if (!$ws) {
            return ['success' => false, 'error' => 'Workshop nicht gefunden.'];
        }
        
        // Prüfen ob Workshop noch buchbar
        if ($ws['status'] === 'vergangen') {
            return ['success' => false, 'error' => 'Dieser Workshop hat bereits stattgefunden.'];
        }
        
        if ($ws['status'] === 'voll') {
            return ['success' => false, 'error' => 'Dieser Workshop ist leider voll (inkl. Warteliste).'];
        }
        
        // Prüfen ob bereits angemeldet - stornierte Anmeldungen bleiben wegen des
        // UNIQUE KEY unique_anmeldung (schueler_id, workshop_id) als Zeile bestehen
        $stmt = $this->db->prepare("
            SELECT * FROM anmeldungen
            WHERE schueler_id = ? AND workshop_id = ?
        ");
        $stmt->execute([$schuelerId, $workshopId]);
        $vorhanden = $stmt->fetch();
        if ($vorhanden && $vorhanden['status'] !== 'storniert') {
            return ['success' => false, 'error' => 'Du bist bereits für diesen Workshop angemeldet.'];
        }
        
        // Prüfen ob max. offene Buchungen erreicht
        $offeneBuchungen = $schueler->getOffeneBuchungen($schuelerId);
        if ($offeneBuchungen >= MAX_OFFENE_BUCHUNGEN) {
            return ['success' => false, 'error' => 'Du hast bereits ' . MAX_OFFENE_BUCHUNGEN . ' offene Workshop-Anmeldungen. Bitte warte, bis ein Workshop stattgefunden hat.'];
        }

        // Prüfen ob Klassenstufen-Limit für 5./6. Klasse erreicht
        if (!empty($ws['max_5_6_klasse'])) {
            $schuelerDaten = $schueler->getById($schuelerId);
            $klasse = $schuelerDaten['klasse'] ?? '';
            if (preg_match('/^[56]/i', $klasse)) {
                $anzahl5_6 = $workshop->getAnzahl5_6Klasse($workshopId);
                if ($anzahl5_6 >= $ws['max_5_6_klasse']) {
                    return ['success' => false, 'error' => 'Dieser Workshop hat bereits die maximale Anzahl an Schülerinnen und Schülern der 5. und 6. Klasse erreicht.'];
                }
            }
        }

        // Status bestimmen
        if ($ws['status'] === 'verfuegbar') {
            $status = 'bestaetigt';
            $wartelistePosition = null;
        } else {
            $status = 'warteliste';
            $wartelistePosition = $ws['warteliste_anzahl'] + 1;
        }
        
        // Anmeldung speichern
        try {
            if ($vorhanden) {
                // Früher storniert: Datensatz wiederverwenden. Das anmeldedatum
                // wird neu gesetzt - die Anmeldung reiht sich hinten ein.
                $stmt = $this->db->prepare("
                    UPDATE anmeldungen
                    SET status = ?, warteliste_position = ?, storniert_am = NULL, anmeldedatum = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$status, $wartelistePosition, $vorhanden['id']]);

                return [
                    'success' => true,
                    'status' => $status,
                    'warteliste_position' => $wartelistePosition,
                    'id' => $vorhanden['id']
                ];
            }

            $stmt = $this->db->prepare("
                INSERT INTO anmeldungen (schueler_id, workshop_id, status, warteliste_position)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$schuelerId, $workshopId, $status, $wartelistePosition]);

            return [
                'success' => true,
                'status' => $status,
                'warteliste_position' => $wartelistePosition,
                'id' => $this->db->lastInsertId()
            ];
        } catch (PDOException $e) {
            error_log('FabLab DB-Fehler: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Datenbankfehler bei der Anmeldung.'];
        }
    }
    
    /**
     * Anmeldung stornieren
     */
    public function stornieren($anmeldungId, $schuelerId = null) {
        // Anmeldung laden
        $sql = "SELECT * FROM anmeldungen WHERE id = ?";
        $params = [$anmeldungId];
        
        if ($schuelerId !== null) {
            $sql .= " AND schueler_id = ?";
            $params[] = $schuelerId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $anmeldung = $stmt->fetch();
        
        if (!$anmeldung) {
            return ['success' => false, 'error' => 'Anmeldung nicht gefunden.'];
        }
        
        if ($anmeldung['status'] === 'storniert') {
            return ['success' => false, 'error' => 'Diese Anmeldung wurde bereits storniert.'];
        }
        
        // Stornieren
        $stmt = $this->db->prepare("
            UPDATE anmeldungen SET status = 'storniert', storniert_am = NOW() WHERE id = ?
        ");
        $stmt->execute([$anmeldungId]);
        
        // Wenn bestätigte Anmeldung storniert wurde, ersten von Warteliste nachrücken
        if ($anmeldung['status'] === 'bestaetigt') {
            $this->nachruecken($anmeldung['workshop_id']);
        }
        
        return ['success' => true];
    }
    
    /**
     * Ersten von Warteliste nachrücken lassen
     */
    public function nachruecken($workshopId) {
        // Ersten auf Warteliste finden
        $stmt = $this->db->prepare("
            SELECT * FROM anmeldungen 
            WHERE workshop_id = ? AND status = 'warteliste'
            ORDER BY warteliste_position ASC
            LIMIT 1
        ");
        $stmt->execute([$workshopId]);
        $warteliste = $stmt->fetch();
        
        if (!$warteliste) {
            return null; // Niemand auf Warteliste
        }
        
        // Status ändern
        $stmt = $this->db->prepare("
            UPDATE anmeldungen SET status = 'bestaetigt', warteliste_position = NULL WHERE id = ?
        ");
        $stmt->execute([$warteliste['id']]);
        
        // Wartelisten-Positionen aktualisieren
        $stmt = $this->db->prepare("
            UPDATE anmeldungen 
            SET warteliste_position = warteliste_position - 1 
            WHERE workshop_id = ? AND status = 'warteliste'
        ");
        $stmt->execute([$workshopId]);
        
        return $warteliste;
    }
    
    /**
     * Manuell von Warteliste nachrücken (Admin)
     */
    public function manuellNachruecken($anmeldungId) {
        $stmt = $this->db->prepare("SELECT * FROM anmeldungen WHERE id = ? AND status = 'warteliste'");
        $stmt->execute([$anmeldungId]);
        $anmeldung = $stmt->fetch();
        
        if (!$anmeldung) {
            return ['success' => false, 'error' => 'Anmeldung nicht gefunden oder nicht auf Warteliste.'];
        }
        
        // Status ändern
        $stmt = $this->db->prepare("
            UPDATE anmeldungen SET status = 'bestaetigt', warteliste_position = NULL WHERE id = ?
        ");
        $stmt->execute([$anmeldungId]);
        
        // Wartelisten-Positionen aktualisieren
        $stmt = $this->db->prepare("
            UPDATE anmeldungen 
            SET warteliste_position = warteliste_position - 1 
            WHERE workshop_id = ? AND status = 'warteliste' AND warteliste_position > ?
        ");
        $stmt->execute([$anmeldung['workshop_id'], $anmeldung['warteliste_position']]);
        
        return ['success' => true];
    }
    
    /**
     * Anmeldungen eines Schülers laden
     */
    public function getBySchueler($schuelerId) {
        $stmt = $this->db->prepare("
            SELECT a.*, w.titel, w.datum, w.uhrzeit_start, w.uhrzeit_ende, w.ort, w.beschreibung, w.altersgruppe
            FROM anmeldungen a
            JOIN workshops w ON a.workshop_id = w.id
            WHERE a.schueler_id = ?
            ORDER BY w.datum DESC, w.uhrzeit_start
        ");
        $stmt->execute([$schuelerId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Anmeldungen eines Workshops laden
     */
    public function getByWorkshop($workshopId) {
        $stmt = $this->db->prepare("
            SELECT a.*, s.vorname, s.nachname, s.klasse, s.elternteil_name, s.notfall_telefon, s.email, s.typ
            FROM anmeldungen a
            JOIN schueler s ON a.schueler_id = s.id
            WHERE a.workshop_id = ? AND a.status != 'storniert'
            ORDER BY 
                CASE a.status WHEN 'bestaetigt' THEN 1 WHEN 'warteliste' THEN 2 END,
                a.warteliste_position,
                a.anmeldedatum
        ");
        $stmt->execute([$workshopId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Anmeldung per ID laden
     */
    public function getById($id) {
        $stmt = $this->db->prepare("
            SELECT a.*, w.titel, w.datum, w.uhrzeit_start, w.uhrzeit_ende, w.ort,
                   s.vorname, s.nachname, s.email, s.notfall_telefon
            FROM anmeldungen a
            JOIN workshops w ON a.workshop_id = w.id
            JOIN schueler s ON a.schueler_id = s.id
            WHERE a.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    /**
     * Manuelle Anmeldung durch Admin
     */
    public function manuellAnmelden($schuelerId, $workshopId, $status = 'bestaetigt') {
        // Bestehenden Datensatz laden - auch stornierte, denn der UNIQUE KEY
        // unique_anmeldung (schueler_id, workshop_id) verbietet ein zweites INSERT
        $stmt = $this->db->prepare("
            SELECT * FROM anmeldungen
            WHERE schueler_id = ? AND workshop_id = ?
        ");
        $stmt->execute([$schuelerId, $workshopId]);
        $vorhanden = $stmt->fetch();
        if ($vorhanden && $vorhanden['status'] !== 'storniert') {
            return ['success' => false, 'error' => 'Schüler ist bereits angemeldet.'];
        }

        $wartelistePosition = null;
        if ($status === 'warteliste') {
            $workshop = new Workshop();
            $ws = $workshop->getMitStatus($workshopId);
            $wartelistePosition = $ws['warteliste_anzahl'] + 1;
        }
        
        try {
            if ($vorhanden) {
                // Stornierte Anmeldung reaktivieren statt neu einzufügen.
                // anmeldedatum bleibt erhalten, damit die ursprüngliche
                // Reihenfolge nach einem versehentlichen Stornieren stimmt.
                $stmt = $this->db->prepare("
                    UPDATE anmeldungen
                    SET status = ?, warteliste_position = ?, storniert_am = NULL
                    WHERE id = ?
                ");
                $stmt->execute([$status, $wartelistePosition, $vorhanden['id']]);
                return ['success' => true, 'id' => $vorhanden['id']];
            }

            $stmt = $this->db->prepare("
                INSERT INTO anmeldungen (schueler_id, workshop_id, status, warteliste_position)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$schuelerId, $workshopId, $status, $wartelistePosition]);
            return ['success' => true, 'id' => $this->db->lastInsertId()];
        } catch (PDOException $e) {
            error_log('FabLab DB-Fehler: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Datenbankfehler beim manuellen Anmelden.'];
        }
    }
}
