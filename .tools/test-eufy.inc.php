<?php
/**
 * Eufy-Teil des Prüfstands (von test-robohub.php eingebunden).
 * Protobuf-Daten nach den .proto-Dateien aus jeppesens/eufy-clean (work_status, control,
 * error_code, clean_statistics, consumable), G50-Werte nach docs/g50_capture/FINDINGS.md.
 */

declare(strict_types=1);

/** DPS-Wert wie die App: Länge als Varint vorn, base64. */
function eufyPb(string $m): string
{
    return base64_encode(RoboHubNarwal::pbVarint(strlen($m)) . $m);
}

class FakeEufy extends RoboHubEufy
{
    public static $anfragen = [];
    public static $gesendet = [];
    public static $dps = [];
    public static $v2 = true;
    public static $gueltig = 'UCT1';
    public static $connack = 0;
    public static $endpunkt = 'abc123-ats.iot.eu-central-1.amazonaws.com';

    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        $k = [];
        foreach ($headers as $h) {
            [$n, $v] = explode(': ', $h, 2);
            $k[strtolower($n)] = $v;
        }
        self::$anfragen[] = ['methode' => $methode, 'url' => $url, 'kopf' => $k, 'body' => (string)$body];
        if (strpos($url, '/email/login') !== false) {
            $j = json_decode((string)$body, true);
            $v2 = strpos($url, '/v2/') !== false;
            if ($v2 && !self::$v2) {
                return [200, '{"res_code":5002,"message":"Incorrect email login or password. Please try again."}'];
            }
            if (($j['email'] ?? '') !== 'sauger@example.com' || ($j['password'] ?? '') !== 'Robo!' || ($j['client_id'] ?? '') !== ($v2 ? 'eufy-app' : 'eufyhome-app')) {
                return [200, '{"res_code":5002,"message":"Incorrect email login or password. Please try again."}'];
            }
            return [200, json_encode(['res_code' => 1, 'access_token' => 'ACC', 'user_id' => 'u1'])];
        }
        if ($url === 'https://api.eufylife.com/v1/user/user_center_info') {
            return ($k['token'] ?? '') === 'ACC' ? [200, json_encode(['user_center_id' => 'UCID', 'user_center_token' => 'UCT1'])]
                : [200, '{"res_code":401,"user_center_token":"","user_center_id":""}'];
        }
        if (($k['x-auth-token'] ?? '') !== self::$gueltig || ($k['gtoken'] ?? '') !== md5('UCID')) {
            return [401, '{"code":401,"msg":"token error"}'];
        }
        if ($url === 'https://aiot-clean-api-pr.eufylife.com/app/devicemanage/get_user_mqtt_info') {
            return [200, json_encode(['code' => 0, 'data' => ['endpoint_addr' => self::$endpunkt, 'thing_name' => 'THING',
                'certificate_pem' => "-----BEGIN CERTIFICATE-----\nZZ\n-----END CERTIFICATE-----", 'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nYY\n-----END RSA PRIVATE KEY-----",
                'user_id' => 'U42', 'app_name' => 'eufy_home']])];
        }
        if ($url === 'https://aiot-clean-api-pr.eufylife.com/app/devicerelation/get_device_list' && json_decode((string)$body, true) === ['attribute' => 3]) {
            return [200, json_encode(['code' => 0, 'data' => ['devices' => [
                ['device' => ['device_sn' => 'SNX10', 'device_model' => 'T2351', 'device_name' => 'Wischi', 'dps' => self::$dps['SNX10'] ?? []]],
                ['device' => ['device_sn' => 'SNG50', 'device_model' => 'T2210', 'alias_name' => '', 'dps' => self::$dps['SNG50'] ?? ['15' => 6]]],
                ['device' => ['device_sn' => '']],
            ]]])];
        }
        return [404, '{}'];
    }

    protected function mqttSenden(array $buendel, string $client, string $thema, string $inhalt): void
    {
        if (self::$connack !== 0) {
            $e = new RoboHubFehler('Eufy-MQTT lehnt den Zugang ab');
            $e->anmeldungNoetig = true;
            throw $e;
        }
        self::$gesendet[] = ['client' => $client, 'thema' => $thema, 'inhalt' => json_decode($inhalt, true), 'zert' => $buendel['zert'], 'thing' => $buendel['thing']];
    }

    public static function daten(): array
    {
        return array_map(function ($g) { return json_decode($g['inhalt']['payload'], true)['data']; }, self::$gesendet);
    }
}

// ===========================================================================
abschnitt('Eufy');
$fabrikEu = $GLOBALS['robohubTreiberFabrik'];
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) use ($fabrikEu) {
    return $marke === 'eufy' ? new FakeEufy() : $fabrikEu($marke);
};
check('Protobuf-Befehle wie die Vorlage (Länge vorn, Methode 0 weggelassen, Start mit auto_clean)', RoboHubEufy::modusBefehl(13) === 'AggN'
    && RoboHubEufy::modusBefehl(0) === 'BBoCCAE=' && RoboHubEufy::modusBefehl(6) === 'AggG' && RoboHubEufy::pb('AggN') === [1 => [13]]);
check('Art: Protobuf („novel"), Zahlen („scalar"), reines Tuya („legacy")', RoboHubEufy::art(['153' => eufyPb(RoboHubNarwal::pbZahl(2, 5))]) === 'novel'
    && RoboHubEufy::art(['153' => 0, '15' => 2]) === 'scalar' && RoboHubEufy::art(['15' => 2]) === 'scalar' && RoboHubEufy::art(['15' => 'Running', '2' => true]) === 'legacy');
$euQuelle = (string)file_get_contents(__DIR__ . '/../libs/RoboHubEufy.php');
$sitzQuelle = (string)file_get_contents(__DIR__ . '/../libs/RoboHubMqttSitzung.php');
check('mTLS: Server gegen Amazon Root CA 1, Client-Schlüssel nur kurz und nur für den Eigentümer lesbar auf Platte', strpos($euQuelle, 'RoboHubWorx::MQTT_WURZEL') !== false
    && stripos($euQuelle, 'verify_peer') === false && strpos($euQuelle, 'SSL_VERIFYPEER') === false
    && strpos($sitzQuelle, "chmod(\$clientDatei, 0600)") !== false && strpos($sitzQuelle, '@unlink($clientDatei)') !== false && strpos($sitzQuelle, "'verify_peer' => true") !== false);

$ek = new RoboHubKonto(10400);
$ek->props['Hersteller'] = 'eufy';
$ek->Create();
$ek->ApplyChanges();
$euSichtbar = [];
$euSuche = function ($k) use (&$euSuche, &$euSichtbar) {
    if (!is_array($k) || ($k['visible'] ?? true) === false) {
        return;
    }
    if (isset($k['caption']) && is_string($k['caption'])) {
        $euSichtbar[] = $k['caption'] . (isset($k['name']) ? ' [' . $k['name'] . ']' : '');
    }
    foreach ($k as $w) {
        $euSuche($w);
    }
};
$euSuche(json_decode($ek->GetConfigurationForm(), true));
$euText = implode("\n", $euSichtbar);
check('Formular: Eufy-Hinweis (Tuya-Altgeräte offen genannt), keine Region, keine Client-Felder', strpos($euText, 'reinem Tuya-Protokoll') !== false
    && strpos($euText, '[Region]') === false && strpos($euText, '[ClientId]') === false && strpos($euText, 'E-Mail des Kontos [Konto]') !== false, $euText);
$ek->props['Konto'] = 'sauger@example.com';
$ek->props['Passwort'] = 'falsch';
$t = $ek->Anmelden();
check('falsches Passwort: Eufys Text, nicht angemeldet', strpos($t, 'Eufy lehnt die Anmeldung ab (Incorrect email login or password') !== false && $ek->ReadAttributeString('Buendel') === '', $t);
FakeEufy::$endpunkt = 'boese.example.com';
$ek->props['Passwort'] = 'Robo!';
$t = $ek->Anmelden();
check('fremder MQTT-Endpunkt: abgelehnt', strpos($t, 'unerwarteten MQTT-Server') !== false && $ek->ReadAttributeString('Buendel') === '', $t);
FakeEufy::$endpunkt = 'abc123-ats.iot.eu-central-1.amazonaws.com';
FakeEufy::$v2 = false;
FakeEufy::$anfragen = [];
$ek->props['Passwort'] = 'Robo!';
$t = $ek->Anmelden();
$eb = json_decode($ek->ReadAttributeString('Buendel'), true);
$eg = json_decode($ek->ReadAttributeString('Geraete'), true);
check('Anmeldung: v2 abgelehnt → v1 (Eufy Clean), Passwort verworfen, Zugang + MQTT-Zertifikat im Bündel', strpos($t, '✅ Angemeldet. Das Passwort wurde verworfen') === 0
    && strpos(FakeEufy::$anfragen[0]['url'], '/v2/') !== false && strpos(FakeEufy::$anfragen[1]['url'], '/v1/user/email/login') !== false
    && strpos(json_encode($eb), 'Robo!') === false && $eb['ucToken'] === 'UCT1' && $eb['gtoken'] === md5('UCID') && $eb['thing'] === 'THING'
    && $eb['mqtt'] === 'abc123-ats.iot.eu-central-1.amazonaws.com' && $eb['userId'] === 'U42' && strpos($eb['schluessel'], 'PRIVATE KEY') !== false
    && $ek->props['Passwort'] === '', $t);
check('Geräte: X10 Pro Omni und G50 (Eintrag ohne SN ausgelassen), Modellcode für die Themen', count($eg) === 2 && $eg[0]['id'] === 'SNX10' && $eg[0]['name'] === 'Wischi'
    && $eg[0]['modell'] === 'eufy.vacuum.t2351' && $eg[0]['code'] === 'T2351' && $eg[1]['name'] === 'Eufy Robot Vacuum G50' && $eg[1]['roh']['art'] === 'scalar', json_encode($eg));

$er = new RoboHubGeraet(10450);
$er->props = ['GeraetID' => 'SNX10', 'Modell' => 'eufy.vacuum.t2351', 'Klasse' => 'sauger', 'Intervall' => 120];
$er->Create();
$er->parent = $ek;
$er->ApplyChanges();
$zustandEu = function (string $sn, int $id, array $dps) use (&$er): int {
    FakeEufy::$dps[$sn] = $dps;
    $GLOBALS['euGeraete'][$id]->Aktualisieren();
    return variable($id, 'Zustand')['value'];
};
$GLOBALS['euGeraete'] = [10450 => $er];
$ws = function (int $state, string $extra = '') { return eufyPb(($state ? RoboHubNarwal::pbZahl(2, $state) : '') . $extra); };
$teil = function (int $feld, int $stunden) { return RoboHubNarwal::pbTeil($feld, RoboHubNarwal::pbZahl(1, $stunden)); };
check('reinigt: Akku, Saugstufe, Statistik (s → min, Gesamt), Verschleiß in Stunden, Fehler aus error vor warn', $zustandEu('SNX10', 10450, ['153' => $ws(5, RoboHubNarwal::pbTeil(6, '')),
    '163' => 71, '158' => 4,
    '167' => eufyPb(RoboHubNarwal::pbTeil(1, RoboHubNarwal::pbZahl(1, 1260) . RoboHubNarwal::pbZahl(2, 23)) . RoboHubNarwal::pbTeil(3, RoboHubNarwal::pbZahl(1, 36000) . RoboHubNarwal::pbZahl(2, 900) . RoboHubNarwal::pbZahl(3, 41))),
    '168' => eufyPb(RoboHubNarwal::pbTeil(1, $teil(3, 90) . $teil(2, 400) . $teil(1, 0) . $teil(6, 45))),
    '177' => eufyPb(RoboHubNarwal::pbZahl(1, 99) . RoboHubNarwal::pbZahl(3, 6021))]) === RoboHubModell::ZUSTAND_REINIGT
    && variable(10450, 'Akku')['value'] === 71 && variable(10450, 'Saugstufe')['value'] === 4 && variable(10450, 'Dauer')['value'] === 21
    && variable(10450, 'Flaeche')['value'] === 23 && variable(10450, 'GesamtFlaeche')['value'] === 900 && variable(10450, 'GesamtDauer')['value'] === 10
    && variable(10450, 'GesamtAnzahl')['value'] === 41 && variable(10450, 'Filter')['value'] === 75 && variable(10450, 'FilterRest')['value'] == 270
    && variable(10450, 'Hauptbuerste')['value'] === 0 && variable(10450, 'Seitenbuerste')['value'] === 100 && variable(10450, 'Mopp')['value'] === 75
    && variable(10450, 'Fehler')['value'] === 'Station: Schmutzwassertank voll (6021)');
check('Fehler: error hat Vorrang vor warn', $zustandEu('SNX10', 10450, ['153' => $ws(0), '177' => eufyPb(RoboHubNarwal::pbZahl(2, 14) . RoboHubNarwal::pbZahl(3, 6021))]) === RoboHubModell::ZUSTAND_BEREIT
    && variable(10450, 'Fehler')['value'] === 'Staubbehälter fehlt (14)');
check('Pause (Reinigung pausiert, kein Waschgang; Fahrt zum Waschen zählt als Reinigung), Mopp waschen/trocknen, Staub absaugen = Station arbeitet',
    $zustandEu('SNX10', 10450, ['153' => $ws(5, RoboHubNarwal::pbTeil(6, RoboHubNarwal::pbZahl(1, 1)))]) === RoboHubModell::ZUSTAND_PAUSIERT
    && $zustandEu('SNX10', 10450, ['153' => $ws(5, RoboHubNarwal::pbTeil(6, RoboHubNarwal::pbZahl(1, 1)) . RoboHubNarwal::pbTeil(7, ''))]) === RoboHubModell::ZUSTAND_REINIGT
    && $zustandEu('SNX10', 10450, ['153' => $ws(5, RoboHubNarwal::pbTeil(6, RoboHubNarwal::pbZahl(1, 1)) . RoboHubNarwal::pbTeil(7, RoboHubNarwal::pbZahl(2, 1)))]) === RoboHubModell::ZUSTAND_STATIONSARBEIT
    && variable(10450, 'ZustandRoh')['value'] === 'Wäscht Mopp (5)'
    && $zustandEu('SNX10', 10450, ['153' => $ws(5, RoboHubNarwal::pbTeil(14, RoboHubNarwal::pbTeil(3, RoboHubNarwal::pbZahl(1, 1))))]) === RoboHubModell::ZUSTAND_STATIONSARBEIT
    && variable(10450, 'ZustandRoh')['value'] === 'Trocknet Mopp (5)'
    && $zustandEu('SNX10', 10450, ['153' => $ws(5, RoboHubNarwal::pbTeil(14, RoboHubNarwal::pbTeil(2, '')))]) === RoboHubModell::ZUSTAND_STATIONSARBEIT
    && variable(10450, 'ZustandRoh')['value'] === 'Leert den Staubbehälter (5)');
check('Laden: lädt / voll (Charging DONE oder 100 %), Rückweg, Schlaf, Bereit (Standardwert 0 fehlt), Fehlerzustand',
    $zustandEu('SNX10', 10450, ['153' => $ws(3, RoboHubNarwal::pbTeil(3, '')), '163' => 50]) === RoboHubModell::ZUSTAND_LAEDT && variable(10450, 'Laedt')['value'] === true
    && $zustandEu('SNX10', 10450, ['153' => $ws(3, RoboHubNarwal::pbTeil(3, RoboHubNarwal::pbZahl(1, 1))), '163' => 98]) === RoboHubModell::ZUSTAND_GELADEN
    && $zustandEu('SNX10', 10450, ['153' => $ws(3, RoboHubNarwal::pbTeil(3, '')), '163' => 100]) === RoboHubModell::ZUSTAND_GELADEN
    && $zustandEu('SNX10', 10450, ['153' => $ws(7)]) === RoboHubModell::ZUSTAND_ZURUECK
    && $zustandEu('SNX10', 10450, ['153' => $ws(1)]) === RoboHubModell::ZUSTAND_SCHLAF
    && $zustandEu('SNX10', 10450, ['153' => $ws(0)]) === RoboHubModell::ZUSTAND_BEREIT
    && $zustandEu('SNX10', 10450, ['153' => $ws(2)]) === RoboHubModell::ZUSTAND_FEHLER
    && $zustandEu('SNX10', 10450, []) === RoboHubModell::ZUSTAND_UNBEKANNT && variable(10450, 'Erreichbar')['value'] === false);

$eg50 = new RoboHubGeraet(10451);
$eg50->props = ['GeraetID' => 'SNG50', 'Modell' => 'eufy.vacuum.t2210', 'Klasse' => 'sauger', 'Intervall' => 120];
$eg50->Create();
$eg50->parent = $ek;
$eg50->ApplyChanges();
$GLOBALS['euGeraete'][10451] = $eg50;
check('G50 (Zahlen): Zustände, Akku, Sekunden → min, m², Verschleiß in Minuten (JSON-Text), Fehler 106/177',
    $zustandEu('SNG50', 10451, ['15' => 2, '104' => 64, '109' => 780, '110' => 4, '102' => 2,
        '150' => '{"side_brush":6209,"roller_brush":6209,"dust_filter":6209,"mop":0,"sensors":1355}']) === RoboHubModell::ZUSTAND_REINIGT
    && variable(10451, 'Akku')['value'] === 64 && variable(10451, 'Dauer')['value'] === 13 && variable(10451, 'Flaeche')['value'] === 4 && variable(10451, 'Saugstufe')['value'] === 2
    && variable(10451, 'Filter')['value'] === 48 && variable(10451, 'SensorenRest')['value'] == 12.4 && variable(10451, 'Hauptbuerste')['value'] === 71
    && $zustandEu('SNG50', 10451, ['15' => 5, '104' => 80]) === RoboHubModell::ZUSTAND_LAEDT && $zustandEu('SNG50', 10451, ['15' => 6]) === RoboHubModell::ZUSTAND_GELADEN
    && $zustandEu('SNG50', 10451, ['15' => 7]) === RoboHubModell::ZUSTAND_PAUSIERT && $zustandEu('SNG50', 10451, ['15' => 4]) === RoboHubModell::ZUSTAND_ZURUECK
    && $zustandEu('SNG50', 10451, ['15' => 0, '106' => 0, '177' => 2]) === RoboHubModell::ZUSTAND_FEHLER && variable(10451, 'Fehler')['value'] === 'Rad blockiert (2)');

FakeEufy::$dps['SNX10'] = ['153' => $ws(3, RoboHubNarwal::pbTeil(3, '')), '163' => 100];
FakeEufy::$gesendet = [];
$er->Starten();
FakeEufy::$dps['SNX10'] = ['153' => $ws(5)];
$er->Anhalten();
FakeEufy::$dps['SNX10'] = ['153' => $ws(5, RoboHubNarwal::pbTeil(6, RoboHubNarwal::pbZahl(1, 1)))];
$er->Starten();
$er->Stoppen();
$er->ZurStation();
$er->Orten();
$er->RequestAction('Saugstufe', 3);
$g0 = FakeEufy::$gesendet[0] ?? [];
$huelle = $g0['inhalt'] ?? [];
$nutz = json_decode($huelle['payload'] ?? '{}', true);
check('Befehle X10: Start/Pause/Fortsetzen/Stopp/Heim als ModeCtrlRequest auf 152, Orten 160, Saugstufe 158', json_encode(FakeEufy::daten()) === json_encode([
    ['152' => 'BBoCCAE='], ['152' => 'AggN'], ['152' => 'AggO'], ['152' => 'AggM'], ['152' => 'AggG'], ['160' => true], ['158' => 3]]), json_encode(FakeEufy::daten()));
check('Hülle wie die App: Thema, Client-ID, head.cmd 65537, payload als JSON-Text mit account_id/device_sn/protocol 2', ($g0['thema'] ?? '') === 'cmd/eufy_home/T2351/SNX10/req'
    && $g0['client'] === 'android-eufy_home-eufy_android_' . $eb['udid'] . '_U42' && $huelle['head']['cmd'] === 65537 && $huelle['head']['client_id'] === $g0['client']
    && $huelle['head']['version'] === '1.0.0.1' && is_string($huelle['payload']) && $nutz['account_id'] === 'U42' && $nutz['device_sn'] === 'SNX10' && $nutz['protocol'] === 2
    && $g0['thing'] === 'THING' && strpos($g0['zert'], 'CERTIFICATE') !== false, json_encode($g0));
FakeEufy::$dps['SNG50'] = ['15' => 6];
FakeEufy::$gesendet = [];
$eg50->Starten();
$eg50->Anhalten();
FakeEufy::$dps['SNG50'] = ['15' => 7];
$eg50->Starten();
$eg50->ZurStation();
$eg50->Orten();
$eg50->RequestAction('Saugstufe', 1);
check('Befehle G50 nach Mitschnitt: Start 5=1, Pause 122=1, Fortsetzen 122=2, Heim 5=3, Orten 103=1, Saugstufe 102', json_encode(FakeEufy::daten()) === json_encode([
    ['5' => 1], ['122' => 1], ['122' => 2], ['5' => 3], ['103' => 1], ['102' => 1]]) && FakeEufy::$gesendet[0]['thema'] === 'cmd/eufy_home/T2210/SNG50/req', json_encode(FakeEufy::daten()));

FakeEufy::$connack = 5;
$t = $er->Orten();
check('MQTT-Zertifikat abgelehnt: neue Anmeldung nötig', $ek->ReadAttributeString('Buendel') === '', $t);
FakeEufy::$connack = 0;
$ek->props['Passwort'] = 'Robo!';
$ek->Anmelden();
FakeEufy::$gueltig = 'WEG';
$er->Aktualisieren();
check('AIOT 401: neue Anmeldung nötig, verständlich', $ek->ReadAttributeString('Buendel') === '' && strpos($ek->ReadAttributeString('LetzterFehler'), 'kennt keinen Erneuerungsweg ohne Passwort') !== false,
    $ek->ReadAttributeString('LetzterFehler'));
FakeEufy::$gueltig = 'UCT1';
$GLOBALS['robohubTreiberFabrik'] = $fabrikEu;
