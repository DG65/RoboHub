<?php
/**
 * Indego-Teil des Prüfstands (von test-robohub.php eingebunden).
 * Datenformat wie pyIndego tests/test_indego.py (MIT).
 */

declare(strict_types=1);

class FakeIndego extends RoboHubIndego
{
    public static $anfragen = [];
    public static $state = [];
    public static $akku = 0;
    public static $nummer = '3600HB0102';
    public static $gueltig = 'B1';
    public static $refresh = 'BR1';
    public static $runde = 1;

    public static function zaehle(string $teil): int
    {
        return count(array_filter(self::$anfragen, function ($a) use ($teil) { return strpos($a['methode'] . ' ' . $a['url'], $teil) !== false; }));
    }

    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        self::$anfragen[] = ['methode' => $methode, 'url' => $url, 'headers' => $headers, 'body' => (string)$body];
        if ($url === 'https://prodindego.b2clogin.com/prodindego.onmicrosoft.com/b2c_1a_signup_signin/oauth2/v2.0/token') {
            parse_str((string)$body, $f);
            if (($f['client_id'] ?? '') !== '65bb8c9d-1070-4fb4-aa95-853618acc876' || strpos((string)($f['scope'] ?? ''), 'Indego.Mower.User') === false || strpos((string)($f['scope'] ?? ''), 'offline_access') === false) {
                return [400, '{"error":"invalid_request"}'];
            }
            $ok = ($f['grant_type'] === 'authorization_code' && ($f['code'] ?? '') === 'eyJ.KOD-E_1' && ($f['redirect_uri'] ?? '') === 'com.bosch.indegoconnect://login')
                || ($f['grant_type'] === 'refresh_token' && ($f['refresh_token'] ?? '') === self::$refresh);
            if (!$ok) {
                return [400, '{"error":"invalid_grant","error_description":"AADB2C90080: The provided grant has expired. Correlation ID: geheim-123"}'];
            }
            self::$runde++;
            self::$gueltig = 'B' . self::$runde;
            self::$refresh = 'BR' . self::$runde;
            return [200, json_encode(['access_token' => self::$gueltig, 'refresh_token' => self::$refresh, 'expires_in' => 3600])];
        }
        $kopf = implode("\n", $headers);
        if (strpos($kopf, 'Authorization: Bearer ' . self::$gueltig) === false) {
            return [401, ''];
        }
        if (strpos($kopf, 'User-Agent: RoboHub/Indego') === false) {
            return [403, '<html>blocked</html>'];
        }
        $api = 'https://api.indego-cloud.iot.bosch-si.com/api/v1/';
        if ($methode === 'GET' && $url === $api . 'alms') {
            return [200, json_encode([['alm_sn' => '123456789'], ['x' => 1]])];
        }
        if ($methode === 'GET' && $url === $api . 'alms/123456789') {
            return [200, json_encode(['alm_sn' => '123456789', 'service_counter' => 69272, 'needs_service' => false, 'alm_mode' => 'smart', 'bareToolnumber' => self::$nummer, 'alm_firmware_version' => '17329.01211'])];
        }
        if ($methode === 'GET' && $url === $api . 'alms/123456789/state') {
            return [200, json_encode(self::$state)];
        }
        if ($methode === 'GET' && $url === $api . 'alms/123456789/operatingData') {
            return [200, json_encode(['hmiKeys' => 1, 'battery' => ['percent' => self::$akku, 'voltage' => 19.2, 'cycles' => 1], 'garden' => ['size' => 625]])];
        }
        if ($methode === 'PUT' && $url === $api . 'alms/123456789/state') {
            return [200, ''];
        }
        return [404, '{}'];
    }
}

// ===========================================================================
abschnitt('Indego: Anmeldung, Zustand, Befehle');
$fabrikIn = $GLOBALS['robohubTreiberFabrik'];
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) use ($fabrikIn) {
    return $marke === 'indego' ? new FakeIndego() : $fabrikIn($marke);
};
$konsole = "Failed to launch 'com.bosch.indegoconnect://login?state=ab12&code=eyJ.KOD-E_1' because the scheme does not have a registered handler.";
check('Code aus Konsolenzeile, nackter Adresse oder pur; Unsinn = leer', RoboHubIndego::codeAus($konsole) === 'eyJ.KOD-E_1'
    && RoboHubIndego::codeAus('com.bosch.indegoconnect://login?code=eyJ.KOD-E_1&state=x') === 'eyJ.KOD-E_1'
    && RoboHubIndego::codeAus('eyJ.KOD-E_1') === 'eyJ.KOD-E_1' && RoboHubIndego::codeAus('irgendwas mit Leerzeichen') === '');
$link = (new RoboHubIndego())->anmeldeLink();
parse_str((string)parse_url($link, PHP_URL_QUERY), $lq);
check('Anmeldelink: Bosch B2C, App-Kennung, Rücksprung wie HA, Bereich mit offline_access',
    strpos($link, 'https://prodindego.b2clogin.com/prodindego.onmicrosoft.com/b2c_1a_signup_signin/oauth2/v2.0/authorize?') === 0
    && $lq['client_id'] === '65bb8c9d-1070-4fb4-aa95-853618acc876' && $lq['redirect_uri'] === 'com.bosch.indegoconnect://login'
    && $lq['response_type'] === 'code' && strpos($lq['scope'], 'offline_access') !== false && strpos($lq['scope'], 'Indego.Mower.User') !== false, $link);
check('Akku: Volt-Modell (Indego 1000) umgerechnet, S+ direkt, begrenzt auf 0–100', RoboHubIndego::akkuProzent(327, 'indego.mower.3600ha2300') === 50
    && RoboHubIndego::akkuProzent(77, 'indego.mower.3600hb0102') === 77 && RoboHubIndego::akkuProzent(400, 'indego.mower.3600ha2300') === 100);

$ik = new RoboHubKonto(9700);
$ik->props['Hersteller'] = 'indego';
$ik->Create();
$ik->ApplyChanges();
$fText = json_encode(json_decode($ik->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
check('Formular: Anleitung mit Entwicklerkonsole, Link-Knopf sichtbar', strpos($fText, 'Entwicklerkonsole öffnen (F12') !== false && strpos($fText, '🔗 Anmeldeseite öffnen') !== false);
$ik->props['Passwort'] = 'com.bosch.indegoconnect://login?code=ALT&state=1';
$t = $ik->Anmelden();
check('abgelaufener Code: Ablehnung mit AADB2C-Nummer, ohne Correlation-ID', strpos($t, 'lehnt den Code ab (invalid_grant, AADB2C90080)') !== false && strpos($t, 'geheim-123') === false, $t);
$ik->props['Passwort'] = $konsole;
$t = $ik->Anmelden();
$ib = json_decode($ik->ReadAttributeString('Buendel'), true);
$ig = json_decode($ik->ReadAttributeString('Geraete'), true);
check('angemeldet aus der Konsolenzeile; ein Mäher mit Modellname', strpos($t, '✅ Angemeldet') === 0 && strpos($t, 'Bosch-Passwort') !== false
    && array_keys($ib) === ['access_token', 'refresh_token', 'expires_at'] && count($ig) === 1 && $ig[0]['id'] === '123456789'
    && $ig[0]['name'] === 'Indego S+ 350 123456789' && $ig[0]['modell'] === 'indego.mower.3600hb0102' && $ig[0]['klasse'] === 'maeher', $t);

$im = new RoboHubGeraet(9800);
$im->props = ['GeraetID' => '123456789', 'Modell' => 'indego.mower.3600hb0102', 'Klasse' => 'maeher', 'Intervall' => 60];
$im->Create();
$im->parent = $ik;
$im->ApplyChanges();
FakeIndego::$state = ['state' => 64513, 'map_update_available' => true, 'mowed' => 97, 'mowmode' => 0, 'error' => 0, 'runtime' => ['total' => ['operate' => 86534, 'charge' => 20534], 'session' => ['operate' => 0, 'charge' => 0]]];
FakeIndego::$akku = 88;
FakeIndego::$anfragen = [];
$im->Aktualisieren();
check('schläft, 97 % gemäht, Mähzeit 660 h (Betrieb − Laden), Akku beim ersten Abruf geholt', variable(9800, 'Zustand')['value'] === RoboHubModell::ZUSTAND_SCHLAF
    && variable(9800, 'Fortschritt')['value'] === 97 && variable(9800, 'GesamtDauer')['value'] === 660 && variable(9800, 'Akku')['value'] === 88
    && FakeIndego::zaehle('operatingData') === 1);
FakeIndego::$akku = 10;
FakeIndego::$anfragen = [];
$im->Aktualisieren();
$im->Aktualisieren();
check('in Ruhe (zwei Abrufe): operatingData nicht erneut abgefragt (weckt den Mäher), Akku bleibt', FakeIndego::zaehle('operatingData') === 0 && variable(9800, 'Akku')['value'] === 88);
FakeIndego::$state = ['state' => 513, 'mowed' => 40, 'error' => 0];
FakeIndego::$akku = 70;
$im->Aktualisieren();
check('mäht: Akku frisch (70)', variable(9800, 'Zustand')['value'] === RoboHubModell::ZUSTAND_REINIGT && variable(9800, 'Akku')['value'] === 70
    && variable(9800, 'ZustandRoh')['value'] === 'Mäht (513)');
FakeIndego::$state = ['state' => 1537, 'error' => 149];
$im->Aktualisieren();
check('steckt fest, Fehler 149 = außerhalb des Drahts', variable(9800, 'Zustand')['value'] === RoboHubModell::ZUSTAND_FEHLER
    && variable(9800, 'Fehler')['value'] === 'Außerhalb des Begrenzungsdrahts (149)');

FakeIndego::$anfragen = [];
$im->Starten();
$im->Anhalten();
$im->ZurStation();
$puts = array_map(function ($a) { return json_decode($a['body'], true); }, array_values(array_filter(FakeIndego::$anfragen, function ($a) { return $a['methode'] === 'PUT'; })));
check('Mähen, Pause, Zur Station als PUT state (pyIndego COMMANDS)', $puts === [['state' => 'mow'], ['state' => 'pause'], ['state' => 'returnToDock']], json_encode($puts));
FakeIndego::$anfragen = [];
$t = $im->Stoppen();
check('Stopp: ehrlicher Hinweis, keine Anfrage', strpos($t, '„Stopp" gibt es bei Indego nicht') !== false && FakeIndego::$anfragen === [], $t);

$ib = json_decode($ik->ReadAttributeString('Buendel'), true);
$ib['expires_at'] = time() + 60;
$ik->WriteAttributeString('Buendel', json_encode($ib));
$im->Aktualisieren();
$ib = json_decode($ik->ReadAttributeString('Buendel'), true);
check('kurz vor Ablauf erneuert, neuer Erneuerungsschlüssel gespeichert', $ib['access_token'] === 'B3' && $ib['refresh_token'] === 'BR3');
FakeIndego::$gueltig = 'ANDERS';
FakeIndego::$anfragen = [];
$im->Aktualisieren();
check('401 mitten in der Laufzeit: angemeldet bleiben, noch keine Erneuerung', $ik->ReadAttributeString('Buendel') !== '' && FakeIndego::zaehle('oauth2/v2.0/token') === 0);
$im->Aktualisieren();
$ib = json_decode($ik->ReadAttributeString('Buendel'), true);
check('… nächster Abruf erneuert sofort und läuft wieder', $ib['refresh_token'] === 'BR4' && FakeIndego::zaehle('oauth2/v2.0/token') === 1
    && variable(9800, 'Zustand')['value'] === RoboHubModell::ZUSTAND_FEHLER);
FakeIndego::$refresh = 'WEG';
$ib['expires_at'] = 0;
$ik->WriteAttributeString('Buendel', json_encode($ib));
$im->Aktualisieren();
check('abgelehnte Erneuerung → Anmeldung nötig (Status 201)', $ik->ReadAttributeString('Buendel') === '' && $ik->status === 201);
$GLOBALS['robohubTreiberFabrik'] = $fabrikIn;
