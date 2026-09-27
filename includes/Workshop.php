<?php
/**
 * Workshop-Model (MySQL-Version)
 */
class Workshop {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getPdo();
    }
    
    /**
     * Neuen Workshop erstellen
     */
    public function erstellen($daten) {
        $stmt = $this->db->prepare("
            INSERT INTO workshops (titel, beschreibung, datum, uhrzeit_start, uhrzeit_ende, ort, altersgruppe, max_teilnehmer, max_warteliste, max_5_6_klasse)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        try {
            $stmt->execute([
                $daten['titel'],
                $daten['beschreibung'],
                $daten['datum'],
                $daten['uhrzeit_start'],
                $daten['uhrzeit_ende'],
                $daten['ort'],
                $daten['altersgruppe'],
                $daten['max_teilnehmer'] ?? MAX_TEILNEHMER,
                $daten['max_warteliste'] ?? MAX_WARTELISTE,
                !empty($daten['max_5_6_klasse']) ? (int)$daten['max_5_6_klasse'] : null
            ]);
            
            return ['success' => true, 'id' => $this->db->lastInsertId()];
        } catch (PDOException $e) {
            error_log('FabLab DB-Fehler: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Datenbankfehler beim Erstellen des Workshops.'];
        }
    }

    /**
     * Workshop aktualisieren
     */
    public function update($id, $daten) {
        $stmt = $this->db->prepare("
            UPDATE workshops
            SET titel = ?, beschreibung = ?, datum = ?, uhrzeit_start = ?, uhrzeit_ende = ?, ort = ?, altersgruppe = ?, max_teilnehmer = ?, max_warteliste = ?, max_5_6_klasse = ?
            WHERE id = ?
        ");

        return $stmt->execute([
            $daten['titel'],
            $daten['beschreibung'],
            $daten['datum'],
            $daten['uhrzeit_start'],
            $daten['uhrzeit_ende'],
            $daten['ort'],
            $daten['altersgruppe'],
            $daten['max_teilnehmer'] ?? MAX_TEILNEHMER,
            $daten['max_warteliste'] ?? MAX_WARTELISTE,
            !empty($daten['max_5_6_klasse']) ? (int)$daten['max_5_6_klasse'] : null,
            $id
        ]);
    }

    /**
     * Anzahl bestätigter 5./6.-Klässler in einem Workshop
     */
    public function getAnzahl5_6Klasse($workshopId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as anzahl
            FROM anmeldungen a
            JOIN schueler s ON a.schueler_id = s.id
            WHERE a.workshop_id = ? AND a.status = 'bestaetigt'
              AND (s.klasse LIKE '5%' OR s.klasse LIKE '6%')
        ");
        $stmt->execute([$workshopId]);
        $result = $stmt->fetch();
        return (int)$result['anzahl'];
    }
    
    /**
     * Workshop per ID laden
     */
    public function getById($id) {
        $stmt = $this->db->prepare("SELECT * FROM workshops WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    /**
     * Alle aktiven Workshops laden
     */
    public function getAlle($nurZukuenftige = true) {
        $sql = "SELECT * FROM workshops WHERE aktiv = 1";
        if ($nurZukuenftige) {
            $sql .= " AND CONCAT(datum, ' ', uhrzeit_ende) > NOW()";
        }
        $sql .= " ORDER BY datum, uhrzeit_start";
        
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }
    
    /**
     * Alle Workshops (auch vergangene) für Admin
     */
    public function getAlleAdmin() {
        $stmt = $this->db->query("SELECT * FROM workshops ORDER BY datum DESC, uhrzeit_start");
        return $stmt->fetchAll();
    }
    
    /**
     * Anzahl bestätigter Teilnehmer
     */
    public function getAnzahlTeilnehmer($workshopId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as anzahl FROM anmeldungen 
            WHERE workshop_id = ? AND status = 'bestaetigt'
        ");
        $stmt->execute([$workshopId]);
        $result = $stmt->fetch();
        return $result['anzahl'];
    }
    
    /**
     * Anzahl auf Warteliste
     */
    public function getAnzahlWarteliste($workshopId) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as anzahl FROM anmeldungen 
            WHERE workshop_id = ? AND status = 'warteliste'
        ");
        $stmt->execute([$workshopId]);
        $result = $stmt->fetch();
        return $result['anzahl'];
    }
    
    /**
     * Workshop-Status ermitteln
     * Gibt zurück: 'verfuegbar', 'warteliste', 'voll', 'vergangen'
     */
    public function getStatus($workshop) {
        // Prüfen ob Workshop vergangen
        $workshopEnde = strtotime($workshop['datum'] . ' ' . $workshop['uhrzeit_ende']);
        if ($workshopEnde < time()) {
            return 'vergangen';
        }
        
        $teilnehmer = $this->getAnzahlTeilnehmer($workshop['id']);
        $warteliste = $this->getAnzahlWarteliste($workshop['id']);
        
        if ($teilnehmer < $workshop['max_teilnehmer']) {
            return 'verfuegbar';
        } elseif ($warteliste < $workshop['max_warteliste']) {
            return 'warteliste';
        } else {
            return 'voll';
        }
    }
    
    /**
     * Workshop mit Status-Infos laden
     */
    public function getMitStatus($workshopId) {
        $workshop = $this->getById($workshopId);
        if (!$workshop) return null;
        
        $workshop['teilnehmer_anzahl'] = $this->getAnzahlTeilnehmer($workshopId);
        $workshop['warteliste_anzahl'] = $this->getAnzahlWarteliste($workshopId);
        $workshop['status'] = $this->getStatus($workshop);
        $workshop['freie_plaetze'] = max(0, $workshop['max_teilnehmer'] - $workshop['teilnehmer_anzahl']);
        $workshop['freie_warteliste'] = max(0, $workshop['max_warteliste'] - $workshop['warteliste_anzahl']);
        
        return $workshop;
    }
    
    /**
     * Alle Workshops mit Status-Infos
     */
    public function getAlleMitStatus($nurZukuenftige = true) {
        $workshops = $this->getAlle($nurZukuenftige);
        foreach ($workshops as &$workshop) {
            $workshop['teilnehmer_anzahl'] = $this->getAnzahlTeilnehmer($workshop['id']);
            $workshop['warteliste_anzahl'] = $this->getAnzahlWarteliste($workshop['id']);
            $workshop['status'] = $this->getStatus($workshop);
            $workshop['freie_plaetze'] = max(0, $workshop['max_teilnehmer'] - $workshop['teilnehmer_anzahl']);
            $workshop['freie_warteliste'] = max(0, $workshop['max_warteliste'] - $workshop['warteliste_anzahl']);
        }
        return $workshops;
    }
    
    /**
     * Workshop deaktivieren
     */
    public function deaktivieren($id) {
        $stmt = $this->db->prepare("UPDATE workshops SET aktiv = 0 WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Workshop aktivieren
     */
    public function aktivieren($id) {
        $stmt = $this->db->prepare("UPDATE workshops SET aktiv = 1 WHERE id = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Workshop löschen (nur wenn keine Anmeldungen)
     */
    public function loeschen($id) {
        // Prüfen ob Anmeldungen existieren
        $stmt = $this->db->prepare("SELECT COUNT(*) as anzahl FROM anmeldungen WHERE workshop_id = ?");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        
        if ($result['anzahl'] > 0) {
            return ['success' => false, 'error' => 'Workshop hat bereits Anmeldungen und kann nicht gelöscht werden.'];
        }
        
        $stmt = $this->db->prepare("DELETE FROM workshops WHERE id = ?");
        $stmt->execute([$id]);
        
        return ['success' => true];
    }
}
