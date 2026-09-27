# FabLab Workshop-Portal — Funktionsbeschreibung

Stand: 27. September 2026

Ein Anmeldesystem für Schul-Workshops, entstanden für das FabLab des Gymnasiums
in den Filder Benden. Schülerinnen und Schüler melden sich selbst zu Workshops
an; die Verwaltung pflegt Termine, Teilnehmerlisten und Stammdaten über einen
Adminbereich.

Dieses Dokument beschreibt, was das System heute tatsächlich kann — nicht, was
einmal geplant war.

---

## Überblick

| | |
| --- | --- |
| Technik | PHP 8, MySQL, kein Framework, keine externen Pakete |
| Installation | Dateien auf einen Webspace kopieren, Zugangsdaten eintragen |
| Rollen | Schülerinnen und Schüler (Selbstbedienung), eine Verwaltungskennung |
| Sprache | durchgehend deutsch, Oberfläche wie Quelltext |

Das System kommt ohne Composer, ohne Build-Schritt und ohne Hintergrunddienste
aus. Es läuft auf jedem gewöhnlichen PHP-Webspace mit MySQL-Datenbank.

---

## Für Schülerinnen und Schüler

### Registrierung

Einmalig, über ein Formular auf der Startseite. Erfasst werden Vorname,
Nachname, Klasse, Name eines Elternteils, eine Notfall-Telefonnummer, die
E-Mail-Adresse und ob die Person die eigene Schule besucht oder von außerhalb
kommt. Eine Einwilligung muss ausdrücklich bestätigt werden.

Die E-Mail-Adresse ist eindeutig — jede Adresse kann sich nur einmal
registrieren.

### Anmeldung am Portal

Zwei Wege führen hinein, beide ohne Passwort:

1. **Zahlencode per E-Mail.** Die Person gibt ihre Adresse ein und bekommt einen
   sechsstelligen Code zugeschickt, der 30 Minuten gilt. Nach drei Fehlversuchen
   wird der Code ungültig und ein neuer muss angefordert werden. Pro Adresse
   lassen sich höchstens fünf Codes in der Stunde anfordern.
2. **Dauerhafter Zugangslink.** Die Registrierungsmail enthält einen persönlichen
   Link, der ein Jahr lang gültig bleibt und direkt zur Workshop-Übersicht führt.
   Ist er abgelaufen, genügt eine Anmeldung per Zahlencode; dabei wird ein
   neuer Link ausgestellt.

Der Code wird nur als Hashwert gespeichert, nie im Klartext, und ist nach
erfolgreicher Nutzung verbraucht.

### Workshops ansehen und buchen

Die Übersicht zeigt alle bevorstehenden, freigeschalteten Workshops mit Datum,
Uhrzeit, Ort, Beschreibung und Altersgruppe. Ohne Anmeldung sind die Termine
sichtbar, die freien Plätze jedoch nicht.

Für Kinder der Klassen 5 und 6 blendet die Übersicht Workshops aus, deren
eigene Obergrenze für diese Jahrgänge bereits erreicht ist — sie sehen also gar
nicht erst einen Termin, den sie nicht buchen könnten.

Ein Klick genügt zum Buchen. Je nach Lage geschieht eines von dreien:

- **Platz frei** → die Anmeldung ist sofort bestätigt.
- **Ausgebucht, Warteliste offen** → Aufnahme auf die Warteliste mit Platznummer.
- **Alles voll** → die Buchung wird abgelehnt.

### Abmelden

Jede eigene Anmeldung lässt sich selbst wieder stornieren. War es ein bestätigter
Platz, rückt automatisch die erste Person von der Warteliste nach und die
übrigen Wartenden rücken eine Position auf.

### Erfahrungsstufen

Mit der Zahl der besuchten Workshops steigt eine spielerische Stufe, von
„Starter" über „Tüftler", „Erfinder", „Halb Mensch, halb Maschine",
„FabLab-Meister" und „Innovations-Guru" bis zur „FabLab-Legende" ab zwölf
Anmeldungen. Die Stufe erscheint auf der eigenen Übersicht und hat keinerlei
Auswirkung auf Buchungsrechte — sie motiviert, mehr nicht.

---

## Für die Verwaltung

Der Adminbereich liegt unter `admin/` und ist durch Benutzername und Passwort
geschützt.

### Workshops

Anlegen, bearbeiten, freischalten, stillegen und löschen. Ein Workshop hat
Titel, Beschreibung, Datum, Anfangs- und Endzeit, Ort, Altersgruppe, eine
Höchstzahl an Teilnehmenden, eine Wartelistengröße und optional eine
Obergrenze für Kinder der Klassen 5 und 6.

- **Stilllegen** nimmt den Workshop aus der öffentlichen Liste. Alle Anmeldungen
  bleiben erhalten, und der Schritt ist jederzeit umkehrbar.
- **Löschen** ist nur möglich, solange überhaupt keine Anmeldung vorliegt —
  auch stornierte zählen. Es schützt davor, versehentlich eine Teilnehmerliste
  zu vernichten.

### Anmeldungen

Eine Liste aller bevorstehenden Anmeldungen, wahlweise auf einen Workshop
gefiltert. Möglich sind:

- eine Person von Hand hinzufügen, auch auf die Warteliste
- eine Anmeldung stornieren
- jemanden von der Warteliste vorzeitig nachrücken lassen
- die Teilnehmerliste eines Workshops als CSV-Datei herunterladen, mit
  Notfallnummern und Elternangaben für den Workshoptag

Wurde jemand versehentlich entfernt, genügt das erneute Hinzufügen: Das System
erkennt die stornierte Anmeldung und belebt sie wieder, samt ursprünglichem
Anmeldedatum.

### Schülerinnen und Schüler

Übersicht aller Registrierten mit Klasse, Kontaktdaten und Anzahl der
Anmeldungen. Zugänge lassen sich stilllegen und wieder freischalten; gelöscht
wird nichts.

### Klassenwechsel zum Schuljahresbeginn

Da die Klasse bei der Registrierung einmalig eingetragen wird, altert sie nicht
mit. Ab dem 1. August weist das Dashboard darauf hin, dass die Umstellung
aussteht.

Die zugehörige Seite zeigt zuerst eine Vorschau: wer würde erhöht, wer nicht.
Erst ein ausdrücklicher Klick ändert etwas, und zwar alles in einem Zug — bricht
etwas ab, bleibt der alte Zustand vollständig erhalten.

- Die Klassen 5 bis 9 werden um eine Stufe erhöht, der Klassenbuchstabe bleibt.
- Jahrgang 10, die Oberstufenkürzel und alles Uneindeutige bleiben unverändert
  und erscheinen in einer eigenen Liste, in der sich jeder Wert von Hand
  korrigieren lässt. Der Übergang von Klasse 10 in die Oberstufe hängt an der
  einzelnen Schullaufbahn und wird deshalb bewusst nicht erraten.
- Stillgelegte Zugänge werden mit umgestellt, damit ihre Klasse stimmt, falls
  sie später wieder freigeschaltet werden.

Jede einzelne Änderung wird protokolliert. Solange der Lauf besteht, lässt er
sich vollständig zurücknehmen; danach ist das Schuljahr wieder offen. Pro
Schuljahr ist nur eine Umstellung möglich.

---

## Regeln, die das System durchsetzt

| Regel | Voreinstellung | Wo eingestellt |
| --- | --- | --- |
| Plätze je Workshop | 15 | je Workshop, Vorgabe in der Konfiguration |
| Warteliste je Workshop | 3 | je Workshop, Vorgabe in der Konfiguration |
| Gleichzeitig offene Buchungen je Person | 2 | Konfiguration |
| Obergrenze für Klassen 5 und 6 | keine | je Workshop, freiwillig |
| Gültigkeit des Zugangslinks | 365 Tage | Konfiguration |
| Gültigkeit des Zahlencodes | 30 Minuten | fest im Programm |
| Fehlversuche je Zahlencode | 3 | fest im Programm |
| Code-Anfragen je Stunde | 5 je Adresse, 100 je IP-Adresse | fest im Programm |
| Fehlversuche Verwaltungszugang | 5 je IP-Adresse, 50 insgesamt, je 15 Minuten | Konfiguration |

Die Begrenzung auf zwei offene Buchungen sorgt dafür, dass einzelne Personen
nicht das ganze Halbjahr im Voraus belegen. Sie zählt nur bevorstehende
Workshops — nach dem Termin wird der Platz wieder frei.

---

## E-Mails

Automatisch verschickt werden:

- die Registrierungsbestätigung mit dem persönlichen Zugangslink
- der sechsstellige Anmeldecode
- die Bestätigung einer gebuchten Teilnahme
- die Bestätigung eines Wartelistenplatzes samt Position
- die Bestätigung einer Stornierung

**Nicht verschickt wird eine Benachrichtigung beim Nachrücken.** Wer von der
Warteliste auf einen festen Platz aufrückt, erfährt das nicht von selbst,
sondern erst beim nächsten Blick ins Portal. Wer das braucht, muss es ergänzen.

Der Versand läuft über die Mailfunktion des Webservers, ohne externen Dienst.
Ein Versand von SMS an die Notfallnummer ist vorbereitet, aber ab Werk
abgeschaltet und ungetestet; er setzt ein Konto bei einem Anbieter voraus.

---

## Sicherheit

- Alle Datenbankzugriffe laufen über vorbereitete Anweisungen.
- Jedes Formular, das etwas verändert, ist gegen untergeschobene Anfragen
  gesichert.
- Ausgaben aus der Datenbank werden beim Einsetzen in die Seite maskiert.
- Fehlversuche werden in der Datenbank gezählt, nicht im Browser. Das Löschen
  des Cookies setzt eine Sperre also nicht zurück. E-Mail- und IP-Adressen
  liegen dort nur als Hashwert.
- Der Verwaltungszugang sperrt nach fünf Fehlversuchen von derselben
  IP-Adresse für 15 Minuten, nach 50 Fehlversuchen insgesamt für alle. Er
  meldet nach acht Stunden Untätigkeit ab. Das Passwort liegt nur als Hashwert
  in der Konfiguration.
- Die Zahlencodes werden mit einem kryptographisch sicheren Zufallsgenerator
  erzeugt, nur als Hashwert gespeichert und beim Gebrauch verbraucht. Die
  Prüfung erfolgt gegen die Adresse in der Sitzung, nicht gegen eine
  mitgeschickte. Nach drei Fehlversuchen wird der Code verworfen.
- Ob eine E-Mail-Adresse registriert ist, verrät die Code-Anmeldung nicht:
  Meldung und Ablauf sind in beiden Fällen gleich.
- Zugangslinks verlieren nach der eingestellten Frist ihre Gültigkeit.
- Beim CSV-Export werden Werte, die Excel als Formel ausführen würde,
  entschärft.
- Die Datei mit den Datenbankzugangsdaten ist von der Versionsverwaltung
  ausgenommen, der Ordner `includes/` ist für direkte Aufrufe gesperrt
  (Apache).

Sicherheitslücken bitte vertraulich über **Security → Report a vulnerability**
im GitHub-Repository melden, nicht als öffentliches Issue.

---

## Datenbestand

Sechs Tabellen: `schueler` (Stammdaten und Zugang), `workshops` (Termine),
`anmeldungen` (Buchungen und Warteliste), `klassenwechsel_laeufe` und
`klassenwechsel_log`, die den jährlichen Klassenwechsel protokollieren, sowie
`fehlversuche` (Zähler für Anmeldesperren, legt sich selbst an, Einträge
werden nach einem Tag gelöscht).

Anmeldungen werden nie gelöscht, sondern auf „storniert" gesetzt. Das erhält die
Nachvollziehbarkeit, wer wann gebucht hatte.

---

## Einrichtung auf einem eigenen Server

1. Die Dateien auf einen PHP-Webspace mit MySQL kopieren.
2. `includes/config.example.php` nach `includes/config.php` kopieren und
   ausfüllen: Datenbankzugang, Adresse der Installation, Absenderadresse,
   Verwaltungskennung samt Passwort-Hashwert.
3. Die Tabellen anlegen. **Ein fertiges Einrichtungsskript gibt es nicht mehr.**
   Die Definitionen stehen in `includes/Database.php` in der Methode
   `createTables()`, die im laufenden Betrieb nirgends aufgerufen wird. Für eine
   Neuinstallation ruft man sie einmalig über ein kurzes eigenes Skript auf und
   löscht dieses danach — oder führt die enthaltenen `CREATE TABLE`-Anweisungen
   von Hand in phpMyAdmin aus.

Drei Spalten fehlen in `createTables()`, weil sie auf der bestehenden Anlage
nachträglich ergänzt wurden. Sie müssen von Hand nachgezogen werden:

```sql
ALTER TABLE workshops ADD COLUMN max_5_6_klasse INT NULL AFTER max_warteliste;
ALTER TABLE schueler  ADD COLUMN otp_code VARCHAR(255) NULL AFTER token;
ALTER TABLE schueler  ADD COLUMN otp_expires_at DATETIME NULL AFTER otp_code;
```

Ohne die erste Zeile lässt sich keine Obergrenze für die Klassen 5 und 6 setzen,
ohne die beiden anderen funktioniert die Anmeldung per Zahlencode nicht.

---

## Grenzen

Ehrlichkeitshalber, damit niemand danach sucht:

- Es gibt genau eine Verwaltungskennung, keine Benutzerverwaltung und keine
  abgestuften Rechte.
- Nachrückende werden nicht benachrichtigt.
- Der Klassenwechsel erhöht auch die Angaben derer, die sich erst nach
  Schuljahresbeginn registriert und daher bereits ihre aktuelle Klasse
  eingetragen haben. Diese wenigen Fälle müssen von Hand nachgezogen werden.
- Das Klassenfeld ist ein freies Textfeld ohne Auswahlliste; Tippfehler landen
  bei der Umstellung in der Prüfliste.
- Es gibt keine Terminserien, keine Anwesenheitserfassung und keine
  Auswertungen über mehrere Schuljahre.
- Automatisierte Tests gibt es nur für die Anmeldesperren, den Ablauf der
  Zugangslinks und den CSV-Export (`php tests/test_sicherheit.php`), nicht
  für die übrigen Teile.
