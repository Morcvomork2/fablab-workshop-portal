<?php
/**
 * Mailer-Klasse für E-Mail und SMS-Versand
 */
class Mailer {
    
    /**
     * E-Mail senden
     */
    public static function sendMail($to, $subject, $body, $isHtml = true) {
        $to = str_replace(["\r", "\n"], '', $to);
        $subject = str_replace(["\r", "\n"], '', $subject);
        $headers = [
            'From' => MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
            'Reply-To' => MAIL_FROM,
            'X-Mailer' => 'PHP/' . phpversion()
        ];
        
        if ($isHtml) {
            $headers['MIME-Version'] = '1.0';
            $headers['Content-Type'] = 'text/html; charset=UTF-8';
        } else {
            $headers['Content-Type'] = 'text/plain; charset=UTF-8';
        }
        
        $headerString = '';
        foreach ($headers as $key => $value) {
            $headerString .= "$key: $value\r\n";
        }
        
        return mail($to, $subject, $body, $headerString);
    }
    
    /**
     * SMS senden (seven.io)
     */
    public static function sendSMS($to, $message) {
        if (!SMS_ENABLED) {
            return ['success' => false, 'error' => 'SMS-Versand ist deaktiviert'];
        }
        
        // Telefonnummer formatieren
        $to = preg_replace('/[^0-9+]/', '', $to);
        if (substr($to, 0, 1) === '0') {
            $to = '+49' . substr($to, 1);
        }
        
        $url = 'https://gateway.seven.io/api/sms';
        $data = [
            'to' => $to,
            'text' => $message,
            'from' => SMS_FROM
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Api-Key: ' . SMS_API_KEY,
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && is_numeric($response) && $response > 0) {
            return ['success' => true];
        }
        
        return ['success' => false, 'error' => $response];
    }
    
    /**
     * Registrierungsbestätigung senden
     */
    public static function sendRegistrierungsMail($schueler) {
        $loginLink = BASE_URL . 'workshops.php?token=' . $schueler['token'];
        
        $subject = 'Willkommen beim FabLab Workshop-Portal';
        
        $body = self::getEmailTemplate('registrierung', [
            'vorname' => $schueler['vorname'],
            'nachname' => $schueler['nachname'],
            'login_link' => $loginLink
        ]);
        
        return self::sendMail($schueler['email'], $subject, $body);
    }

    /**
     * Einmal-Code per E-Mail senden
     */
    public static function sendOtpMail($schueler, string $code): bool {
        $subject = 'Dein Einmal-Code für das FabLab Workshop-Portal';
        $body = self::getEmailTemplate('otp', [
            'vorname'  => $schueler['vorname'],
            'otp_code' => $code,
        ]);
        return self::sendMail($schueler['email'], $subject, $body);
    }

    /**
     * Anmeldebestätigung senden
     */
    public static function sendAnmeldeBestaetigungMail($anmeldung, $schueler, $workshop) {
        $subject = 'Anmeldebestätigung: ' . $workshop['titel'];
        
        $body = self::getEmailTemplate('anmeldung_bestaetigt', [
            'vorname' => $schueler['vorname'],
            'nachname' => $schueler['nachname'],
            'workshop_titel' => $workshop['titel'],
            'workshop_datum' => date('d.m.Y', strtotime($workshop['datum'])),
            'workshop_zeit' => date('H:i', strtotime($workshop['uhrzeit_start'])) . ' - ' . date('H:i', strtotime($workshop['uhrzeit_ende'])),
            'workshop_ort' => $workshop['ort']
        ]);
        
        self::sendMail($schueler['email'], $subject, $body);
        
        // SMS senden
        if (SMS_ENABLED) {
            $smsText = "FabLab: Anmeldung bestätigt für " . $workshop['titel'] . " am " . date('d.m.', strtotime($workshop['datum']));
            self::sendSMS($schueler['notfall_telefon'], $smsText);
        }
    }
    
    /**
     * Wartelisten-Benachrichtigung senden
     */
    public static function sendWartelisteMail($anmeldung, $schueler, $workshop, $position) {
        $subject = 'Warteliste: ' . $workshop['titel'];
        
        $body = self::getEmailTemplate('warteliste', [
            'vorname' => $schueler['vorname'],
            'nachname' => $schueler['nachname'],
            'workshop_titel' => $workshop['titel'],
            'workshop_datum' => date('d.m.Y', strtotime($workshop['datum'])),
            'position' => $position
        ]);
        
        self::sendMail($schueler['email'], $subject, $body);
        
        // SMS senden
        if (SMS_ENABLED) {
            $smsText = "FabLab: Warteliste Platz " . $position . " für " . $workshop['titel'] . " am " . date('d.m.', strtotime($workshop['datum']));
            self::sendSMS($schueler['notfall_telefon'], $smsText);
        }
    }
    
    /**
     * Stornierungsbestätigung senden
     */
    public static function sendStornierungMail($schueler, $workshop) {
        $subject = 'Stornierung: ' . $workshop['titel'];
        
        $body = self::getEmailTemplate('stornierung', [
            'vorname' => $schueler['vorname'],
            'nachname' => $schueler['nachname'],
            'workshop_titel' => $workshop['titel'],
            'workshop_datum' => date('d.m.Y', strtotime($workshop['datum']))
        ]);
        
        self::sendMail($schueler['email'], $subject, $body);
    }
    
    /**
     * E-Mail-Template laden
     */
    private static function getEmailTemplate($template, $vars) {
        $templates = [
            'registrierung' => '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #1e3a5f; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .button { display: inline-block; background: #e65100; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; margin: 20px 0; }
        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>FabLab Workshop-Portal</h1>
            <p>Gymnasium in den Filder Benden</p>
        </div>
        <div class="content">
            <h2>Hallo {vorname}!</h2>
            <p>Deine Registrierung beim FabLab Workshop-Portal war erfolgreich.</p>
            <p>Mit dem folgenden Link kannst du dich jederzeit für Workshops anmelden:</p>
            <table cellspacing="0" cellpadding="0" border="0" style="margin:20px 0;">
                <tr>
                    <td style="background-color:#e65100;border-radius:4px;">
                        <a href="{login_link}" style="display:inline;background-color:#e65100;color:#ffffff;padding:12px 24px;text-decoration:none;border-radius:4px;font-family:Arial,sans-serif;font-size:16px;font-weight:bold;line-height:48px;">Zu den Workshops</a>
                    </td>
                </tr>
            </table>
            <div style="background:#f0f0f0;border-left:4px solid #1e3a5f;border-radius:4px;padding:14px 16px;margin:16px 0;">
                <p style="margin:0 0 6px 0;font-weight:bold;font-size:14px;color:#333;">Button funktioniert nicht?</p>
                <p style="margin:0 0 8px 0;font-size:13px;color:#555;">Kopiere diesen Link vollständig in die Adressleiste deines Browsers:</p>
                <p style="margin:0 0 8px 0;font-size:14px;word-break:break-all;color:#1e3a5f;">{login_link}</p>
                <p style="margin:0;font-size:12px;color:#777;">Tipp: Du kannst diesen Link auch als Lesezeichen speichern.</p>
            </div>
            <p><strong>Wichtig:</strong> Speichere diesen Link – er ist dein persönlicher Zugang zum Workshop-Portal.</p>
        </div>
        <div class="footer">
            <p>FabLab im Gymnasium in den Filder Benden<br>Zahnstraße 43, 47447 Moers</p>
        </div>
    </div>
</body>
</html>',

            'anmeldung_bestaetigt' => '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #1e3a5f; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .info-box { background: white; padding: 15px; border-left: 4px solid #4caf50; margin: 15px 0; }
        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Anmeldung bestätigt ✓</h1>
        </div>
        <div class="content">
            <h2>Hallo {vorname}!</h2>
            <p>Deine Anmeldung zum Workshop wurde bestätigt:</p>
            <div class="info-box">
                <strong>{workshop_titel}</strong><br>
                📅 {workshop_datum}<br>
                🕐 {workshop_zeit}<br>
                📍 {workshop_ort}
            </div>
            <p>Wir freuen uns auf dich!</p>
        </div>
        <div class="footer">
            <p>FabLab im Gymnasium in den Filder Benden</p>
        </div>
    </div>
</body>
</html>',

            'warteliste' => '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #1e3a5f; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .info-box { background: white; padding: 15px; border-left: 4px solid #ff9800; margin: 15px 0; }
        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Warteliste</h1>
        </div>
        <div class="content">
            <h2>Hallo {vorname}!</h2>
            <p>Der Workshop ist leider ausgebucht. Du stehst auf der Warteliste:</p>
            <div class="info-box">
                <strong>{workshop_titel}</strong><br>
                📅 {workshop_datum}<br>
                <br>
                <strong>Deine Position: Platz {position}</strong>
            </div>
            <p>Falls jemand absagt, rückst du automatisch nach und wirst benachrichtigt.</p>
        </div>
        <div class="footer">
            <p>FabLab im Gymnasium in den Filder Benden</p>
        </div>
    </div>
</body>
</html>',

            'stornierung' => '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #1e3a5f; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Stornierungsbestätigung</h1>
        </div>
        <div class="content">
            <h2>Hallo {vorname}!</h2>
            <p>Deine Anmeldung wurde erfolgreich storniert:</p>
            <p><strong>{workshop_titel}</strong> am {workshop_datum}</p>
            <p>Schade, dass du nicht teilnehmen kannst. Vielleicht beim nächsten Mal!</p>
        </div>
        <div class="footer">
            <p>FabLab im Gymnasium in den Filder Benden</p>
        </div>
    </div>
</body>
</html>',

            'otp' => '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #1e3a5f; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .code-box { background: #fff; border: 2px solid #1e3a5f; border-radius: 8px; padding: 20px; text-align: center; margin: 24px 0; }
        .code { font-size: 40px; font-weight: bold; letter-spacing: 12px; color: #1e3a5f; font-family: monospace; }
        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>FabLab Workshop-Portal</h1>
            <p>Gymnasium in den Filder Benden</p>
        </div>
        <div class="content">
            <h2>Hallo {vorname}!</h2>
            <p>Hier ist dein Einmal-Code zum Einloggen ins FabLab Workshop-Portal:</p>
            <div style="background:#fff;border:2px solid #1e3a5f;border-radius:8px;padding:20px;text-align:center;margin:24px 0;">
                <div style="font-size:40px;font-weight:bold;letter-spacing:12px;color:#1e3a5f;font-family:monospace;">{otp_code}</div>
            </div>
            <p style="text-align:center;color:#666;font-size:14px;">Dieser Code ist <strong>30 Minuten</strong> gültig.</p>
            <p style="font-size:13px;color:#888;margin-top:24px;">Falls du keinen Code angefordert hast, kannst du diese E-Mail ignorieren.</p>
        </div>
        <div class="footer">
            <p>FabLab im Gymnasium in den Filder Benden<br>Zahnstraße 43, 47447 Moers</p>
        </div>
    </div>
</body>
</html>'
        ];
        
        $html = $templates[$template] ?? '';
        
        foreach ($vars as $key => $value) {
            $html = str_replace('{' . $key . '}', htmlspecialchars($value), $html);
        }
        
        return $html;
    }
}
