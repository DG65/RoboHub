<?php
/**
 * Kärcher-Teil des Prüfstands (von test-robohub.php eingebunden).
 * Datenformat nach lafriks/python-karcher und vosadci/karcher-rcv5-ha (beide MIT);
 * Signatur, Verschlüsselung und Fehlercodes 620/623/612 live am 27.10.2026 gegen
 * eu-appaiot.3irobotix.net geprüft (ohne Konto).
 */

declare(strict_types=1);

class FakeKaercher extends RoboHubKaercher
{
    public static $anfragen = [];
    public static $mqtt = [];
    public static $eigenschaften = [];
    public static $antwortet = true;
    public static $gueltig = 'AUTH1';
    public static $tokenLogin = true;
    public static $runde = 1;
    public static $fremdServer = false;

    public static function befehle(): array
    {
        return array_values(array_map(function ($m) {
            $j = json_decode($m['inhalt']);   // Objekte bleiben Objekte: leere params müssen {} sein
            return [$m['ziel'], $j->method, $j->params];
        }, array_filter(self::$mqtt, function ($m) { return $m['antwort'] === null; })));
    }

    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        $k = [];
        foreach ($headers as $h) {
            [$n, $v] = explode(': ', $h, 2);
            $k[strtolower($n)] = $v;
        }
        self::$anfragen[] = ['methode' => $methode, 'url' => $url, 'kopf' => $k, 'body' => (string)$body];
        // Signatur wie der Server: md5(auth + ts + nonce + Daten).
        $teile = parse_url($url);
        $daten = $methode === 'GET' ? (string)($teile['query'] ?? '') : RoboHubKaercher::koerperText(json_decode((string)$body, true) ?: []);
        if (($k['user-agent'] ?? '') !== 'Android_1528983614213726208' || ($k['tenantid'] ?? '') !== '1528983614213726208' || strlen($k['nonce'] ?? '') !== 32
            || ($k['sign'] ?? '') !== md5(($k['authorization'] ?? '') . ($k['ts'] ?? '') . ($k['nonce'] ?? '') . $daten)) {
            return [200, '{"code":889,"msg":"sign error"}'];
        }
        $pfad = (string)$teile['path'];
        if ($pfad === '/network-service/domains/list') {
            $server = self::$fremdServer ? ['MQTT' => 'boese.example.com:8883', 'APP_api' => 'boese.example.com:443']
                : ['MQTT' => 'eu-gamqttaiot.3irobotix.net:8883', 'APP_api' => 'eu-appaiot.3irobotix.net:443'];
            return [200, json_encode(['code' => 0, 'result' => ['domain' => RoboHubKaercher::verschluesseln(json_encode($server))]])];
        }
        if ($pfad === '/user-center/auth/login') {
            $j = json_decode((string)$body, true);
            if (!is_int($j['versionCode'] ?? null) || ($j['projectType'] ?? '') !== 'android_iot.karcher') {
                return [200, '{"code":612,"msg":"param"}'];
            }
            $nutzer = RoboHubKaercher::entschluesseln((string)$j['username']);
            if ($nutzer !== 'putz@example.com') {
                return [200, '{"code":623,"msg":"user not found"}'];
            }
            if (RoboHubKaercher::entschluesseln((string)$j['password']) !== 'Sauber!') {
                return [200, '{"code":620,"msg":"wrong"}'];
            }
            return [200, json_encode(['code' => 0, 'result' => ['AUTH' => self::$gueltig, 'EMQ_TOKEN' => 'EMQ1', 'id' => '99887766']])];
        }
        if ($pfad === '/user-center/auth/login/token') {
            $j = json_decode((string)$body, true);
            if (!is_string($j['versionCode'] ?? null) || !is_string($j['phoneSys'] ?? null)) {
                return [200, '{"code":400,"msg":"cannot unmarshal number into TokenLoginReq.VersionCode"}'];
            }
            if (!self::$tokenLogin || ($j['userId'] ?? '') !== '99887766' || ($j['token'] ?? '') === '') {
                return [200, '{"code":401,"msg":"invalid auth"}'];
            }
            self::$runde++;
            self::$gueltig = 'AUTH' . self::$runde;
            return [200, json_encode(['code' => 0, 'result' => ['AUTH' => self::$gueltig, 'EMQ_TOKEN' => 'EMQ' . self::$runde, 'id' => '99887766']])];
        }
        if (($k['authorization'] ?? '') !== self::$gueltig || ($k['id'] ?? '') !== '99887766') {
            return [200, '{"code":609,"msg":"expired"}'];
        }
        if ($methode === 'GET' && $pfad === '/smart-home-service/smartHome/user/getDeviceInfoByUserId/99887766') {
            return [200, json_encode(['code' => 0, 'result' => [
                ['deviceId' => '1', 'sn' => 'KSN001', 'nickname' => 'Kärchi', 'productId' => '1540149850806333440', 'productModeCode' => 'RCV5',
                    'status' => 1, 'photoUrl' => 'https://eu-cdnimageaiot.3irobotix.net/rcv5.png'],
                ['deviceId' => '2', 'sn' => 'KSN007', 'nickname' => '', 'productId' => '1950097614355394560', 'status' => 0],
                ['deviceId' => '3', 'sn' => ''],
            ]])];
        }
        return [404, '{}'];
    }

    protected function mqttAustausch(array $buendel, array $geraet, string $ziel, string $inhalt, ?string $antwortThema, float $frist): ?array
    {
        self::$mqtt[] = ['ziel' => $ziel, 'inhalt' => $inhalt, 'antwort' => $antwortThema, 'emq' => $buendel['emq'] ?? '', 'produkt' => $geraet['produkt'] ?? ''];
        if ($antwortThema === null || !self::$antwortet) {
            return null;
        }
        $j = json_decode($inhalt, true);
        $daten = [];
        foreach ((array)($j['params']['property'] ?? []) as $eig) {
            if (array_key_exists($eig, self::$eigenschaften)) {
                $daten[$eig] = self::$eigenschaften[$eig];
            }
        }
        return ['code' => 0, 'data' => $daten];
    }
}

// ===========================================================================
abschnitt('Kärcher');
$fabrikKa = $GLOBALS['robohubTreiberFabrik'];
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) use ($fabrikKa) {
    return $marke === 'kaercher' ? new FakeKaercher() : $fabrikKa($marke);
};
check('Verschlüsselung wie python-karcher (AES-128-ECB, Schlüssel aus der Tenant-ID)', RoboHubKaercher::schluessel() === substr(md5('1528983614213726208'), 8, 16)
    && RoboHubKaercher::entschluesseln(RoboHubKaercher::verschluesseln('xiaomi_mi 9')) === 'xiaomi_mi 9'
    && RoboHubKaercher::koerperText(['a' => null, 'b' => 'x', 'c' => 10004, 'd' => ['andIpad' => 'r', 'android' => 'r']]) === 'anullbxc10004d{"andIpad":"r","android":"r"}');
$pemKa = RoboHubKaercher::ZERTIFIKAT;
check('gepinntes Zertifikat = 3iRobotix (*.3irobotix.net, SHA-256 68:A3…4A:10)', openssl_x509_fingerprint($pemKa, 'sha256') === '68a368925db3de516a8064fd7038a34945d86edf113308662c8785a4c9f54a10'
    && openssl_x509_parse($pemKa)['subject']['CN'] === '*.3irobotix.net');
$kaQuelle = (string)file_get_contents(__DIR__ . '/../libs/RoboHubKaercher.php');
check('TLS-Prüfung nie abgeschaltet (curl VERIFYPEER/VERIFYHOST, MQTT über RoboHubMqttSitzung)', strpos($kaQuelle, 'CURLOPT_SSL_VERIFYPEER => true') !== false
    && strpos($kaQuelle, 'CURLOPT_SSL_VERIFYHOST => 2') !== false && strpos($kaQuelle, 'CURLOPT_CAINFO') !== false && stripos($kaQuelle, 'verify_peer') === false
    && strpos($kaQuelle, 'VERIFYPEER => false') === false && strpos($kaQuelle, 'VERIFYHOST => 0') === false);
check('Zugangsschlüssel-Ablauf aus „exp"', RoboHubKaercher::ablaufAus('x.' . rtrim(strtr(base64_encode('{"exp":1893456000,"value":"{}"}'), '+/', '-_'), '=') . '.y') === 1893456000
    && RoboHubKaercher::ablaufAus('kein-jwt') === 0);

$ka = new RoboHubKonto(10300);
$ka->props['Hersteller'] = 'kaercher';
$ka->Create();
$ka->ApplyChanges();
$kaSichtbar = [];
$kaSuche = function ($k) use (&$kaSuche, &$kaSichtbar) {
    if (!is_array($k) || ($k['visible'] ?? true) === false) {
        return;
    }
    if (isset($k['caption']) && is_string($k['caption'])) {
        $kaSichtbar[] = $k['caption'] . (isset($k['name']) ? ' [' . $k['name'] . ']' : '') . (isset($k['options'][0]['caption']) ? ' {' . $k['options'][0]['caption'] . '}' : '');
    }
    foreach ($k as $w) {
        $kaSuche($w);
    }
};
$kaSuche(json_decode($ka->GetConfigurationForm(), true));
$kaText = implode("\n", $kaSichtbar);
check('Formular: Kärcher-Regionen, E-Mail + Passwort, keine Client-Felder', strpos($kaText, 'Region des Kontos [Region] {Europa (auch DE/AT/CH)}') !== false
    && strpos($kaText, 'E-Mail des Kontos [Konto]') !== false && strpos($kaText, 'Passwort (nur für die Anmeldung, wird danach verworfen) [Passwort]') !== false
    && strpos($kaText, '[ClientId]') === false && strpos($kaText, 'developer.miele.com') === false, $kaText);
$ka->props['Konto'] = 'falsch@example.com';
$ka->props['Passwort'] = 'Sauber!';
$t = $ka->Anmelden();
check('unbekannte E-Mail (623): verständlich', strpos($t, 'Kärcher kennt diese E-Mail nicht') !== false, $t);
$ka->props['Konto'] = 'putz@example.com';
$ka->props['Passwort'] = 'falsch';
$t = $ka->Anmelden();
check('falsches Passwort (620): verständlich', strpos($t, 'E-Mail oder Passwort falsch') !== false, $t);
FakeKaercher::$fremdServer = true;
$ka->props['Passwort'] = 'Sauber!';
$t = $ka->Anmelden();
check('fremder Server in der Serverliste: abgelehnt, nicht angemeldet', strpos($t, 'keinen gültigen MQTT-Server') !== false && $ka->ReadAttributeString('Buendel') === '', $t);
FakeKaercher::$fremdServer = false;
FakeKaercher::$anfragen = [];
$ka->props['Passwort'] = 'Sauber!';
$t = $ka->Anmelden();
$kb = json_decode($ka->ReadAttributeString('Buendel'), true);
$kg = json_decode($ka->ReadAttributeString('Geraete'), true);
$login = json_decode(FakeKaercher::$anfragen[1]['body'], true);
check('angemeldet: Passwort verworfen und nur verschlüsselt gesendet, Zugang + Server im Bündel', strpos($t, '✅ Angemeldet. Das Passwort wurde verworfen.') === 0
    && strpos(json_encode($kb), 'Sauber') === false && $kb['auth'] === 'AUTH1' && $kb['emq'] === 'EMQ1' && $kb['userId'] === '99887766'
    && $kb['mqtt'] === 'eu-gamqttaiot.3irobotix.net' && $kb['mqttPort'] === 8883 && $kb['api'] === 'https://eu-appaiot.3irobotix.net'
    && strpos(FakeKaercher::$anfragen[1]['body'], 'Sauber') === false && $login['lang'] === 'de' && strlen($login['noticeSetting']['android']) === 19
    && $ka->props['Passwort'] === '', $t . ' ' . json_encode($kb));
check('zwei Roboter (Eintrag ohne SN ausgelassen), Modell aus der Produkt-ID, Bild nur https', count($kg) === 2 && $kg[0]['id'] === 'KSN001' && $kg[0]['name'] === 'Kärchi'
    && $kg[0]['modell'] === 'kaercher.vacuum.rcv5' && $kg[0]['produkt'] === '1540149850806333440' && $kg[0]['online'] === true
    && $kg[1]['name'] === 'Kärcher RVF 7 Comfort' && $kg[1]['online'] === false && $kg[0]['bild'] === 'https://eu-cdnimageaiot.3irobotix.net/rcv5.png', json_encode($kg));

$kr = new RoboHubGeraet(10350);
$kr->props = ['GeraetID' => 'KSN001', 'Modell' => 'kaercher.vacuum.rcv5', 'Klasse' => 'sauger', 'Intervall' => 120];
$kr->Create();
$kr->parent = $ka;
$kr->ApplyChanges();
$zustandKa = function (array $p) use ($kr): int {
    FakeKaercher::$eigenschaften = $p;
    $kr->Aktualisieren();
    return variable(10350, 'Zustand')['value'];
};
check('reinigt: Akku, Fläche/100, Dauer, Verschleiß aus verbrauchten Minuten, Saugstufe/Wasser/Modus', $zustandKa(['work_mode' => 1, 'status' => 1, 'quantity' => 81,
    'cleaning_area' => 1234, 'cleaning_time' => 17, 'main_brush' => 10800, 'side_brush' => 0, 'hypa' => 10800, 'mop_life' => 12000, 'wind' => 3, 'water' => 0, 'mode' => 1,
    'fault' => 0, 'firmware' => '3.1.4']) === RoboHubModell::ZUSTAND_REINIGT
    && variable(10350, 'Akku')['value'] === 81 && variable(10350, 'Flaeche')['value'] === 12 && variable(10350, 'Dauer')['value'] === 17
    && variable(10350, 'Hauptbuerste')['value'] === 50 && variable(10350, 'HauptbuersteRest')['value'] == 180 && variable(10350, 'Seitenbuerste')['value'] === 100
    && variable(10350, 'Filter')['value'] === 0 && variable(10350, 'Mopp')['value'] === 0 && variable(10350, 'Saugstufe')['value'] === 3
    && variable(10350, 'Wassermenge')['value'] === 0 && variable(10350, 'Reinigungsmodus')['value'] === 1 && variable(10350, 'Firmware')['value'] === '3.1.4');
check('Station: lädt unter 100 %, voll = in der Station; Rückweg; Pause', $zustandKa(['work_mode' => 0, 'status' => 4, 'charge_state' => 1, 'quantity' => 60]) === RoboHubModell::ZUSTAND_LAEDT
    && variable(10350, 'Laedt')['value'] === true
    && $zustandKa(['work_mode' => 0, 'status' => 4, 'charge_state' => 1, 'quantity' => 100]) === RoboHubModell::ZUSTAND_GELADEN
    && $zustandKa(['work_mode' => 0, 'status' => 4, 'charge_state' => 0, 'quantity' => 100, 'fault' => 519]) === RoboHubModell::ZUSTAND_GELADEN
    && $zustandKa(['work_mode' => 5, 'status' => 1, 'charge_state' => 0, 'quantity' => 40]) === RoboHubModell::ZUSTAND_ZURUECK
    && $zustandKa(['work_mode' => 5, 'status' => 4, 'charge_state' => 1, 'quantity' => 40]) === RoboHubModell::ZUSTAND_LAEDT
    && $zustandKa(['work_mode' => 31, 'status' => 1]) === RoboHubModell::ZUSTAND_PAUSIERT);
check('Fehler nur außerhalb der Station; 21xx ist Meldung, kein Fehler', $zustandKa(['work_mode' => 0, 'status' => 1, 'fault' => 519]) === RoboHubModell::ZUSTAND_FEHLER
    && variable(10350, 'Fehler')['value'] === 'Hauptbürste blockiert (519)'
    && $zustandKa(['work_mode' => 0, 'status' => 1, 'fault' => 2105]) === RoboHubModell::ZUSTAND_BEREIT && variable(10350, 'Fehler')['value'] === 'kein Fehler'
    && variable(10350, 'Meldung')['value'] === 'Laden abgeschlossen (2105)'
    && $zustandKa(['work_mode' => 0, 'status' => 4, 'charge_state' => 1, 'quantity' => 100, 'fault' => 523]) === RoboHubModell::ZUSTAND_GELADEN
    && $zustandKa(['work_mode' => 1, 'fault' => 514]) === RoboHubModell::ZUSTAND_REINIGT);
FakeKaercher::$antwortet = false;
check('keine MQTT-Antwort: unbekannt, nicht erreichbar', $zustandKa([]) === RoboHubModell::ZUSTAND_UNBEKANNT && variable(10350, 'Erreichbar')['value'] === false);
FakeKaercher::$antwortet = true;
check('Abfrage: prop.get mit Verschleißteilen an service/property/get, MQTT-Passwort und Produkt dabei', (function () {
    $m = end(FakeKaercher::$mqtt);
    $j = json_decode($m['inhalt'], true);
    return $m['ziel'] === 'service/property/get' && $m['antwort'] === 'service/property/get_reply' && $j['method'] === 'prop.get' && $j['version'] === '3.0'
        && $j['tenantId'] === '1528983614213726208' && is_string($j['msgId']) && in_array('main_brush', $j['params']['property'], true)
        && in_array('work_mode', $j['params']['property'], true) && $m['emq'] === 'EMQ1' && $m['produkt'] === '1540149850806333440';
})());

FakeKaercher::$eigenschaften = ['work_mode' => 0, 'status' => 4, 'charge_state' => 1, 'quantity' => 100];
FakeKaercher::$mqtt = [];
$kr->Starten();
FakeKaercher::$eigenschaften = ['work_mode' => 1];
$kr->Anhalten();
FakeKaercher::$eigenschaften = ['work_mode' => 4];
$kr->Stoppen();
FakeKaercher::$eigenschaften = ['work_mode' => 5];
$kr->Stoppen();
$kr->ZurStation();
$kr->Orten();
$kr->RequestAction('Saugstufe', 3);
$kr->RequestAction('Wassermenge', 2);
$kr->RequestAction('Reinigungsmodus', 0);
$leer = new stdClass();
check('Befehle: Start/Pause/Stopp über set_room_clean 1/2/0, Stopp auf dem Heimweg = stop_recharge, Station, Orten, prop.set', json_encode(FakeKaercher::befehle()) === json_encode([
    ['service_invoke/set_room_clean', 'service.set_room_clean', ['room_ids' => [], 'ctrl_value' => 1, 'clean_type' => 0]],
    ['service_invoke/set_room_clean', 'service.set_room_clean', ['room_ids' => [], 'ctrl_value' => 2, 'clean_type' => 0]],
    ['service_invoke/set_room_clean', 'service.set_room_clean', ['room_ids' => [], 'ctrl_value' => 0, 'clean_type' => 0]],
    ['service_invoke/stop_recharge', 'service.stop_recharge', $leer],
    ['service_invoke/start_recharge', 'service.start_recharge', $leer],
    ['service_invoke/find_device', 'service.find_device', $leer],
    ['service/property/set', 'prop.set', ['wind' => 3]],
    ['service/property/set', 'prop.set', ['water' => 2]],
    ['service/property/set', 'prop.set', ['mode' => 0]],
]), json_encode(FakeKaercher::befehle()));
FakeKaercher::$eigenschaften = ['work_mode' => 31];
FakeKaercher::$mqtt = [];
$kr->Starten();
$kr->Anhalten();
FakeKaercher::$eigenschaften = ['work_mode' => 30];
$kr->Stoppen();
check('Bereichsreinigung: Fortsetzen/Pause/Stopp über set_zone_clean', json_encode(FakeKaercher::befehle()) === json_encode([
    ['service_invoke/set_zone_clean', 'service.set_zone_clean', ['ctrl_value' => 1]],
    ['service_invoke/set_zone_clean', 'service.set_zone_clean', ['ctrl_value' => 2]],
    ['service_invoke/set_zone_clean', 'service.set_zone_clean', ['ctrl_value' => 0]],
]), json_encode(FakeKaercher::befehle()));
$kv = new RoboHubGeraet(10351);
$kv->props = ['GeraetID' => 'KSN007', 'Modell' => 'kaercher.vacuum.rvf7comfort', 'Klasse' => 'sauger', 'Intervall' => 120];
$kv->Create();
$kv->parent = $ka;
$kv->ApplyChanges();
FakeKaercher::$eigenschaften = ['work_mode' => 0, 'wind' => 4];
$kv->Aktualisieren();
FakeKaercher::$mqtt = [];
$kv->RequestAction('Saugstufe', 3);
check('RVF 7 zählt Saugstufen ab 1: gelesen 4 = Turbo, Turbo gesendet als 4', variable(10351, 'Saugstufe')['value'] === 3
    && json_encode(FakeKaercher::befehle()) === json_encode([['service/property/set', 'prop.set', ['wind' => 4]]]), json_encode(FakeKaercher::befehle()));

// Schlüssel abgelehnt (609): angemeldet bleiben, nächster Abruf erneuert per login/token (ohne Passwort).
FakeKaercher::$gueltig = 'WEG';
$ka->GeraeteSuchen();
check('609: angemeldet bleiben …', $ka->ReadAttributeString('Buendel') !== '');
FakeKaercher::$gueltig = 'AUTH' . FakeKaercher::$runde;
FakeKaercher::$anfragen = [];
FakeKaercher::$eigenschaften = ['work_mode' => 0, 'status' => 4, 'charge_state' => 1, 'quantity' => 100];
$kr->Aktualisieren();
$neu = json_decode($ka->ReadAttributeString('Buendel'), true);
$tok = json_decode(FakeKaercher::$anfragen[0]['body'] ?? '{}', true);
check('… nächster Abruf erneuert per login/token (token + userId, versionCode als Text), neues MQTT-Passwort', strpos(FakeKaercher::$anfragen[0]['url'] ?? '', '/user-center/auth/login/token') !== false
    && ($tok['token'] ?? '') === 'AUTH1' && array_key_exists('password', $tok) && $tok['password'] === null && $tok['versionCode'] === '10004' && $neu['auth'] === 'AUTH2' && $neu['emq'] === 'EMQ2'
    && end(FakeKaercher::$mqtt)['emq'] === 'EMQ2', json_encode($neu) . ' ' . json_encode(end(FakeKaercher::$mqtt)));
FakeKaercher::$tokenLogin = false;
FakeKaercher::$gueltig = 'WEG2';
$ka->GeraeteSuchen();
$kr->Aktualisieren();
check('Erneuerung abgelehnt: neue Anmeldung nötig', $ka->ReadAttributeString('Buendel') === '' && strpos($ka->ReadAttributeString('LetzterFehler'), 'nicht ohne Passwort erneuern') !== false,
    $ka->ReadAttributeString('LetzterFehler'));
FakeKaercher::$tokenLogin = true;
$GLOBALS['robohubTreiberFabrik'] = $fabrikKa;
