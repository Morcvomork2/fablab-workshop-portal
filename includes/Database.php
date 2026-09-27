<?php
/**
 * Datenbank-Klasse für MySQL
 */
class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        
        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            if (DEBUG_MODE) {
                die('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage());
            } else {
                die('Datenbankverbindung fehlgeschlagen. Bitte kontaktiere den Administrator.');
            }
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getPdo() {
        return $this->pdo;
    }
    
    /**
     * Prüft ob die Tabellen existieren
     */
    public function tabellenExistieren() {
        try {
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'schueler'");
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Erstellt die Tabellen (wird vom Setup-Skript aufgerufen)
     */
    public function createTables() {
        // Schüler-Tabelle
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS schueler (
                id INT AUTO_INCREMENT PRIMARY KEY,
                vorname VARCHAR(100) NOT NULL,
                nachname VARCHAR(100) NOT NULL,
                klasse VARCHAR(20) NOT NULL,
                elternteil_name VARCHAR(200) NOT NULL,
                notfall_telefon VARCHAR(50) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                typ ENUM('GFB', 'extern') NOT NULL DEFAULT 'GFB',
                einwilligung TINYINT(1) NOT NULL DEFAULT 0,
                token VARCHAR(64) UNIQUE,
                token_erstellt DATETIME,
                erstellt DATETIME DEFAULT CURRENT_TIMESTAMP,
                aktiv TINYINT(1) DEFAULT 1,
                INDEX idx_token (token),
                INDEX idx_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Workshops-Tabelle
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS workshops (
                id INT AUTO_INCREMENT PRIMARY KEY,
                titel VARCHAR(255) NOT NULL,
                beschreibung TEXT,
                datum DATE NOT NULL,
                uhrzeit_start TIME NOT NULL,
                uhrzeit_ende TIME NOT NULL,
                ort VARCHAR(255) NOT NULL,
                altersgruppe VARCHAR(100),
                max_teilnehmer INT DEFAULT " . MAX_TEILNEHMER . ",
                max_warteliste INT DEFAULT " . MAX_WARTELISTE . ",
                erstellt DATETIME DEFAULT CURRENT_TIMESTAMP,
                aktiv TINYINT(1) DEFAULT 1,
                INDEX idx_datum (datum)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Anmeldungen-Tabelle
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS anmeldungen (
                id INT AUTO_INCREMENT PRIMARY KEY,
                schueler_id INT NOT NULL,
                workshop_id INT NOT NULL,
                status ENUM('bestaetigt', 'warteliste', 'storniert') NOT NULL DEFAULT 'bestaetigt',
                warteliste_position INT,
                anmeldedatum DATETIME DEFAULT CURRENT_TIMESTAMP,
                storniert_am DATETIME,
                FOREIGN KEY (schueler_id) REFERENCES schueler(id) ON DELETE CASCADE,
                FOREIGN KEY (workshop_id) REFERENCES workshops(id) ON DELETE CASCADE,
                UNIQUE KEY unique_anmeldung (schueler_id, workshop_id),
                INDEX idx_schueler (schueler_id),
                INDEX idx_workshop (workshop_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Klassenwechsel-Tabellen: identisch zu Klassenwechsel::tabellenAnlegen()
        // in includes/Klassenwechsel.php. Beide Stellen bei Änderungen pflegen.
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS klassenwechsel_laeufe (
                id INT AUTO_INCREMENT PRIMARY KEY,
                schuljahr VARCHAR(9) NOT NULL,
                ausgefuehrt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
                anzahl_geaendert INT NOT NULL DEFAULT 0,
                UNIQUE KEY unique_schuljahr (schuljahr)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->pdo->exec("
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

        return true;
    }
}
