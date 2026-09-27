<?php

/**
 * Macht einen Wert sicher für den CSV-Export.
 *
 * Beginnt eine Zelle mit =, +, -, @ oder einem Steuerzeichen, führt Excel
 * sie als Formel aus. Wer sich z.B. mit dem Namen "=HYPERLINK(...)"
 * registriert, könnte so eine Formel in die Teilnehmerliste schmuggeln.
 * Solche Werte bekommen ein vorangestelltes Hochkomma.
 *
 * Ausnahme: Telefonnummern wie "+49 211 123456" bleiben unverändert.
 */
function csv_zelle($wert): string
{
    $wert = (string)$wert;
    if ($wert === '') {
        return $wert;
    }
    if (preg_match('/^[+-][\d\s\/()-]*$/', $wert)) {
        return $wert;
    }
    if (strpbrk($wert[0], "=+-@\t\r") !== false) {
        return "'" . $wert;
    }
    return $wert;
}
