<?php
/**
 * Ecovacs-Teil des Prüfstands (von test-robohub.php eingebunden).
 * Referenz-Signatur unabhängig in Python nachgerechnet (Verfahren wie in
 * deebot_client authentication.py beschrieben).
 */

declare(strict_types=1);

/** Attrappe der Ecovacs-Cloud: ersetzt nur http(). */
class FakeEcovacs extends RoboHubEcovacs
{
    public static $anfragen = [];
    public static $befehle = [];
    public static $antworten = [];
    public static $bestaetigungNoetig = false;
    public static $bestaetigt = [];
    public static $codeMail = null;
    public static $privat = null;
    public static $loginAnzahl = 0;
    public static $offline = false;

    public static function letzte(string $teil): ?array
    {
        for ($i = count(self::$anfragen) - 1; $i >= 0; $i--) {
            if (strpos(self::$anfragen[$i]['url'], $teil) !== false) {
                return self::$anfragen[$i];
            }
        }
        return null;
    }

    private static function schluessel()
    {
        if (self::$privat === null) {
            self::$privat = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        }
        return self::$privat;
    }

    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        self::$anfragen[] = ['methode' => $methode, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $q = [];
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $ok = function ($data) { return [200, json_encode(['code' => '0000', 'msg' => 'ok', 'data' => $data])]; };
        if (preg_match('#^https://gl-de-api\.ecovacs\.com/v1/private/de/EN/([0-9a-f]{32})/global_e/3\.14\.0/google_play/1/(.+)$#', (string)strtok($url, '?'), $m)) {
            $geraetId = $m[1];
            switch ($m[2]) {
                case 'user/login':
                    self::$loginAnzahl++;
                    if (($q['password'] ?? '') !== md5('geheim')) {
                        return [200, '{"code":"1010","msg":"wrong password"}'];
                    }
                    if (self::$bestaetigungNoetig && !in_array($geraetId, self::$bestaetigt, true)) {
                        return [200, '{"code":"1013","msg":"device verify"}'];
                    }
                    return $ok(['uid' => 'U1', 'accessToken' => 'AT1']);
                case 'common/getConfig':
                    $der = base64_encode((string)base64_decode(preg_replace('/-----[^-]+-----|\s/', '', openssl_pkey_get_details(self::schluessel())['key'])));
                    return $ok([['key' => 'PUBLIC.KEY.CONFIG', 'value' => json_encode(['publicKey' => $der])]]);
                case 'user/sendEmailVerifyCode':
                    openssl_private_decrypt((string)base64_decode((string)$q['encryptEmail']), $klar, self::schluessel(), OPENSSL_PKCS1_PADDING);
                    self::$codeMail = $klar;
                    return $ok(null);
                case 'user/verifyDevice':
                    if (($q['verifyCode'] ?? '') !== '123456') {
                        return [200, '{"code":"1012","msg":"wrong code"}'];
                    }
                    self::$bestaetigt[] = $geraetId;
                    return $ok(['uid' => 'U1', 'accessToken' => 'AT1']);
            }
            return [404, '{}'];
        }
        if (strpos($url, 'https://gl-de-openapi.ecovacs.com/v1/global/auth/getAuthCode') === 0) {
            return $ok(['authCode' => 'AC1']);
        }
        if (strpos($url, 'https://portal-eu.ecouser.net/api/') !== 0) {
            return [404, '{}'];
        }
        $j = json_decode((string)$body, true);
        if (strpos($url, 'users/user.do') !== false && ($j['todo'] ?? '') === 'loginByItToken') {
            return [200, json_encode(['result' => 'ok', 'userId' => 'U1', 'token' => 'TOK' . self::$loginAnzahl, 'last' => 604800000])];
        }
        if (($j['auth']['token'] ?? '') === '' || ($j['auth']['userid'] ?? '') !== 'U1' || !preg_match('/^[0-9a-f]{32}$/', (string)($j['auth']['resource'] ?? ''))) {
            return [200, '{"ret":"fail","errno":3,"error":"auth error"}'];
        }
        if (($j['todo'] ?? '') === 'GetDeviceList') {
            return [200, json_encode(['result' => 'ok', 'devices' => [
                ['did' => 'D-V2', 'class' => '02qwum', 'resource' => 'r1', 'company' => 'eco-ng', 'deviceName' => 'DEEBOT T20 OMNI', 'nick' => 'Wuseline', 'status' => 1],
                ['did' => 'D-ALT', 'class' => '0bdtzz', 'resource' => 'r2', 'company' => 'eco-ng', 'deviceName' => 'DEEBOT OZMO', 'status' => 1],
                ['did' => 'D-XML', 'class' => '02uwxm', 'resource' => 'r3', 'company' => 'eco-ng', 'deviceName' => 'DEEBOT alt'],
            ]])];
        }
        if (($j['todo'] ?? '') === 'GetGlobalDeviceList') {
            return [200, json_encode(['result' => 'ok', 'devices' => [
                ['did' => 'D-GOAT', 'class' => '5xu9h3', 'resource' => 'r4', 'company' => 'eco-ng', 'deviceName' => 'GOAT G1', 'status' => 1],
            ]])];
        }
        if (strpos($url, 'iot/devmanager.do') !== false) {
            self::$befehle[] = ['did' => $j['toId'], 'cmd' => $j['cmdName'], 'data' => $j['payload']['body']['data'] ?? null, 'query' => $q, 'typ' => $j['payloadType'], 'res' => $j['toRes'], 'klasse' => $j['toType']];
            if (self::$offline) {
                return [200, '{"ret":"fail","errno":4200,"error":"offline"}'];
            }
            $a = self::$antworten[$j['cmdName']] ?? ['code' => 0, 'msg' => 'ok'];
            if ($a === 500) {
                return [200, '{"ret":"fail","errno":500,"error":"timeout"}'];
            }
            return [200, json_encode(['ret' => 'ok', 'id' => 'x', 'resp' => ['header' => ['ver' => '0.0.1'], 'body' => $a]])];
        }
        return [404, '{}'];
    }
}

$ok = function ($daten) { return ['code' => 0, 'msg' => 'ok', 'data' => $daten]; };

// ===========================================================================
abschnitt('Ecovacs: Signatur und Klassen');
$sig = RoboHubEcovacs::signieren(['account' => 'a@b.de', 'password' => 'x', 'requestId' => 'r1', 'authTimespan' => 1700000000000, 'authTimeZone' => 'GMT-8'],
    ['lang' => 'EN', 'appCode' => 'global_e', 'appVersion' => '3.14.0', 'channel' => 'google_play', 'deviceType' => '1', 'country' => 'de', 'deviceId' => 'dev1'],
    '1520391301804', '6c319b2a5cd3e66e39159c2e28f2fce9');
check('authSign = Python-Referenz, authAppkey gesetzt, Meta nicht in den Parametern', $sig['authSign'] === 'a6903b77c80baf54d811632c22828350' && $sig['authAppkey'] === '1520391301804' && !isset($sig['appCode']));
check('Klassen: T20 nutzt clean_V2, OZMO nicht; GOAT ist Mäher', RoboHubEcovacs::nutztV2('02qwum') && !RoboHubEcovacs::nutztV2('0bdtzz') && RoboHubEcovacs::istMaeher('5xu9h3', '') && RoboHubEcovacs::istMaeher('zzz', 'GOAT O800') && !RoboHubEcovacs::istMaeher('02qwum', 'T20'));
check('clean_V2-Argumente (Start/Pause/Fortsetzen)', json_encode(RoboHubEcovacs::reinigung('start', true)) === '{"act":"start","content":{"type":"auto"}}'
    && json_encode(RoboHubEcovacs::reinigung('pause', true)) === '{"act":"pause","content":{"type":""}}' && json_encode(RoboHubEcovacs::reinigung('resume', true)) === '{"act":"resume","content":{}}');
check('clean-Argumente alt', RoboHubEcovacs::reinigung('start', false) === ['act' => 'start', 'type' => 'auto'] && RoboHubEcovacs::reinigung('stop', false) === ['act' => 'stop']);

// ===========================================================================
abschnitt('Ecovacs: Anmeldung (Hash, Gerätebestätigung, Neuanmeldung)');
$fabrikAlt = $GLOBALS['robohubTreiberFabrik'];
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) use ($fabrikAlt) {
    return $marke === 'ecovacs' ? new FakeEcovacs() : $fabrikAlt($marke);
};
FakeEcovacs::$bestaetigungNoetig = true;
$ek = new RoboHubKonto(6000);
$ek->props['Hersteller'] = 'ecovacs';
$ek->Create();
$ek->props['Region'] = 'DE';
$ek->ApplyChanges();
check('auch ohne Konto-Echtzeit: Client Socket wird nachgeholt (bleibt zu)', ($ek->timers['Verbindung'] ?? 0) === 15000);
$ek->props['Konto'] = 'putz@example.org';
$ek->props['Passwort'] = 'falsch';
check('falsches Passwort abgelehnt', strpos($ek->Anmelden(), 'E-Mail/Passwort') !== false && $ek->ReadAttributeString('AnmeldeVorstufe') === '');
$ek->props['Passwort'] = 'geheim';
$t = $ek->Anmelden();
$vs = json_decode($ek->ReadAttributeString('AnmeldeVorstufe'), true);
check('Bestätigung verlangt → Hinweis, Vorstufe gemerkt, Passwortfeld geleert', strpos($t, '📧') === 0 && ($vs['pwmd5'] ?? '') === md5('geheim') && $ek->props['Passwort'] === '' && $ek->ReadAttributeString('Buendel') === '', $t);
check('Formular zeigt jetzt den Code-Knopf', strpos(json_encode(json_decode($ek->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE), '"caption":"📧 Code anfordern","visible":true') !== false);
$t = $ek->CodeAnfordern();
check('Code angefordert, E-Mail RSA-verschlüsselt (mit privatem Schlüssel lesbar)', strpos($t, '📧') === 0 && FakeEcovacs::$codeMail === 'putz@example.org', $t);
$ek->props['Passwort'] = '999999';
check('falscher Code abgelehnt, Vorstufe bleibt', strpos($ek->Anmelden(), 'Bestätigungscode ist falsch') !== false && $ek->ReadAttributeString('AnmeldeVorstufe') !== '');
$ek->props['Passwort'] = '123456';
$t = $ek->Anmelden();
$eb = json_decode($ek->ReadAttributeString('Buendel'), true);
check('mit Code angemeldet, Hinweis auf gespeicherten Hash', strpos($t, '✅ Angemeldet') === 0 && strpos($t, 'Hash des Passworts') !== false && $ek->ReadAttributeString('AnmeldeVorstufe') === '', $t);
check('Bündel: Hash, kein Klartext, Gültigkeit ~7 Tage, gleiche Geräte-ID', ($eb['pwmd5'] ?? '') === md5('geheim') && strpos($ek->ReadAttributeString('Buendel'), 'geheim') === false
    && $eb['expires_at'] > time() + 6 * 86400 && $eb['expires_at'] < time() + 7 * 86400 && $eb['geraetId'] === $vs['geraetId'] && $eb['token'] !== '');
check('Protokoll ohne Passwort und ohne Hash', strpos(implode("\n", $GLOBALS['LOG']), 'geheim') === false && strpos(implode("\n", $GLOBALS['LOG']), md5('geheim')) === false);
$eg = json_decode($ek->ReadAttributeString('Geraete'), true);
check('Geräte aus beiden Listen (Deebot + GOAT)', count($eg) === 4 && in_array('D-GOAT', array_column($eg, 'id'), true));
$goat = array_values(array_filter($eg, function ($g) { return $g['id'] === 'D-GOAT'; }))[0];
check('GOAT als Mäher, Spitzname als Name', $goat['klasse'] === 'maeher' && $eg[0]['name'] === 'Wuseline');
$vorher = FakeEcovacs::$loginAnzahl;
$eb['expires_at'] = time() + 60;
$ek->WriteAttributeString('Buendel', json_encode($eb));
$ek->Erneuern();
$nb = json_decode($ek->ReadAttributeString('Buendel'), true);
check('kurz vor Ablauf: selbständige Neuanmeldung mit dem Hash', FakeEcovacs::$loginAnzahl === $vorher + 1 && $nb['token'] !== $eb['token'] && $nb['expires_at'] > time() + 86400);

// ===========================================================================
abschnitt('Ecovacs: Zustand und Befehle');
FakeEcovacs::$antworten = [
    'getBattery' => $ok(['value' => 76, 'isLow' => 0]),
    'getChargeState' => $ok(['isCharging' => 0]),
    'getCleanInfo_V2' => $ok(['trigger' => 'app', 'state' => 'clean', 'cleanState' => ['motionState' => 'working', 'content' => ['type' => 'auto']]]),
    'getError' => $ok(['code' => [100]]),
    'getTotalStats' => $ok(['area' => 1234, 'time' => 360000, 'count' => 77]),
    'getVolume' => $ok(['volume' => 7, 'total' => 10]),
    'getSpeed' => $ok(['speed' => 1]),
    'getLifeSpan' => $ok([['type' => 'brush', 'left' => 9000, 'total' => 18000], ['type' => 'heap', 'left' => 0, 'total' => 7200]]),
    'getCachedMapInfo' => $ok(['enable' => 1, 'info' => [['mid' => '199390082', 'using' => 1, 'name' => 'EG'], ['mid' => '2', 'using' => 0]]]),
    'getMapSet' => $ok(['mid' => '199390082', 'msid' => '8', 'type' => 'ar', 'subsets' => [['mssid' => '7'], ['mssid' => '12']]]),
    'getMapSubSet' => $ok(['mid' => '199390082', 'type' => 'ar', 'mssid' => '7', 'name' => '', 'subtype' => '5', 'value' => '0,0']),
];
$ed = new RoboHubGeraet(6100);
$ed->props = ['GeraetID' => 'D-V2', 'Modell' => 'ecovacs.vacuum.02qwum', 'Klasse' => 'sauger', 'Intervall' => 120];
$ed->Create();
$ed->parent = $ek;
$ed->ApplyChanges();
FakeEcovacs::$befehle = [];
$t = $ed->Aktualisieren();
$b0 = FakeEcovacs::$befehle[0] ?? [];
check('Abfrage über devmanager.do mit JSON-Nutzlast, Ressource und Klasse', ($b0['typ'] ?? '') === 'j' && ($b0['res'] ?? '') === 'r1' && ($b0['klasse'] ?? '') === '02qwum' && ($b0['query']['mid'] ?? '') === '02qwum' && ($b0['query']['u'] ?? '') === 'U1', $t);
check('V2-Gerät fragt getCleanInfo_V2', in_array('getCleanInfo_V2', array_column(FakeEcovacs::$befehle, 'cmd'), true));
check('reinigt, Akku 76, kein Fehler', variable(6100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_REINIGT && variable(6100, 'Akku')['value'] === 76 && variable(6100, 'Fehler')['value'] === 'kein Fehler');
check('Saugstufe Max als Auswahl', variable(6100, 'Saugstufe')['value'] === 1 && variable(6100, 'Saugstufe')['action']);
check('Verschleiß in %: Hauptbürste 50, Filter 0', variable(6100, 'Hauptbuerste')['value'] === 50 && variable(6100, 'Filter')['value'] === 0);
check('Statistik und Lautstärke (7 von 10 = 70 %)', variable(6100, 'GesamtDauer')['value'] === 100 && variable(6100, 'GesamtFlaeche')['value'] === 1234 && variable(6100, 'GesamtAnzahl')['value'] === 77 && variable(6100, 'Lautstaerke')['value'] === 70);
$raumOpt = array_column(json_decode((string)(variable(6100, 'Raum')['pres']['OPTIONS'] ?? '[]'), true), 'Caption', 'Value');
check('Räume: Name aus Raumtyp (5 = Küche), Rückfall „Raum n"', ($raumOpt[7] ?? '') === 'Küche' && isset($raumOpt[12]), json_encode($raumOpt, JSON_UNESCAPED_UNICODE));

FakeEcovacs::$antworten['getCleanInfo_V2'] = $ok(['state' => 'idle']);
FakeEcovacs::$antworten['getChargeState'] = $ok(['isCharging' => 1]);
FakeEcovacs::$antworten['getBattery'] = $ok(['value' => 100]);
$ed->Aktualisieren();
check('idle + lädt + 100 % = In der Station', variable(6100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_GELADEN && variable(6100, 'Laedt')['value'] === true);
FakeEcovacs::$antworten['getError'] = $ok(['code' => [105]]);
FakeEcovacs::$antworten['getChargeState'] = $ok(['isCharging' => 0]);
$ed->Aktualisieren();
check('Fehler 105 = Steckt fest + Fehlerzustand', variable(6100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_FEHLER && strpos(variable(6100, 'Fehler')['value'], 'Steckt fest') === 0);
FakeEcovacs::$antworten['getError'] = $ok(['code' => [100]]);
FakeEcovacs::$antworten['getCleanInfo_V2'] = $ok(['state' => 'clean', 'cleanState' => ['motionState' => 'pause']]);
$ed->Aktualisieren();

FakeEcovacs::$befehle = [];
$ed->Starten();
$ed->ZurStation();
$ed->Orten();
$ed->RaeumeReinigen('7,12');
$ed->RequestAction('Saugstufe', 1000);
$ed->RequestAction('Lautstaerke', 40);
$bf = array_map(function ($b) { return [$b['cmd'], $b['data']]; }, FakeEcovacs::$befehle);
check('pausiert → Start setzt fort (resume)', ($bf[0] ?? null) === ['clean_V2', ['act' => 'resume', 'content' => []]], json_encode($bf[0] ?? null));
check('Station, Orten, Räume (spotArea), Saugstufe, Lautstärke', array_slice($bf, 1) === [
    ['charge', ['act' => 'go']], ['playSound', ['count' => 1, 'sid' => 30]],
    ['clean_V2', ['act' => 'start', 'content' => ['type' => 'spotArea', 'value' => '7,12']]],
    ['setSpeed', ['speed' => 1000]], ['setVolume', ['volume' => 4]],
], json_encode(array_slice($bf, 1)));
FakeEcovacs::$befehle = [];
$t = $ed->VerbrauchsteilZuruecksetzen('Hauptbuerste');
check('Hauptbürste zurücksetzen = resetLifeSpan brush', (FakeEcovacs::$befehle[0]['cmd'] ?? '') === 'resetLifeSpan' && (FakeEcovacs::$befehle[0]['data'] ?? null) === ['type' => 'brush'], $t);

$ea = new RoboHubGeraet(6200);
$ea->props = ['GeraetID' => 'D-ALT', 'Modell' => 'ecovacs.vacuum.0bdtzz', 'Klasse' => 'sauger', 'Intervall' => 120];
$ea->Create();
$ea->parent = $ek;
$ea->ApplyChanges();
FakeEcovacs::$befehle = [];
$ea->Aktualisieren();
$ea->RaeumeReinigenMit('7', -1, -1, 2);
$alt = array_column(FakeEcovacs::$befehle, 'cmd');
check('älteres Gerät: getCleanInfo und clean mit spotArea + Durchgängen', in_array('getCleanInfo', $alt, true) && end(FakeEcovacs::$befehle)['data'] === ['act' => 'start', 'type' => 'spotArea', 'content' => '7', 'count' => 2]);

$ex = new RoboHubGeraet(6300);
$ex->props = ['GeraetID' => 'D-XML', 'Modell' => 'ecovacs.vacuum.02uwxm', 'Klasse' => 'sauger', 'Intervall' => 120];
$ex->Create();
$ex->parent = $ek;
$ex->ApplyChanges();
FakeEcovacs::$befehle = [];
$t = $ex->Aktualisieren();
check('XML-Gerät: ehrlich „noch nicht unterstützt", nichts gesendet', strpos($t, 'XML') !== false && FakeEcovacs::$befehle === [], $t);

$eg2 = new RoboHubGeraet(6400);
$eg2->props = ['GeraetID' => 'D-GOAT', 'Modell' => 'ecovacs.mower.5xu9h3', 'Klasse' => 'maeher', 'Intervall' => 120];
$eg2->Create();
$eg2->parent = $ek;
$eg2->ApplyChanges();
FakeEcovacs::$antworten['getLifeSpan'] = $ok([['type' => 'blade', 'left' => 600, 'total' => 1200], ['type' => 'lensBrush', 'left' => 30, 'total' => 100]]);
FakeEcovacs::$befehle = [];
$eg2->Aktualisieren();
$life = array_values(array_filter(FakeEcovacs::$befehle, function ($b) { return $b['cmd'] === 'getLifeSpan'; }));
check('GOAT: Messer und Linsenbürste, keine Saugstufe', count($life) === 1 && $life[0]['data'] === ['blade', 'lensBrush'] && variable(6400, 'Messer')['value'] === 50 && variable(6400, 'Saugstufe') === null);

FakeEcovacs::$offline = true;
$t = $ed->Aktualisieren();
check('offline: klare Meldung', strpos($t, 'offline') !== false, $t);
FakeEcovacs::$offline = false;

$ek->WriteAttributeString('AnmeldeVorstufe', '{"pwmd5":"x"}');
$ek->Abmelden();
check('Abmelden löscht Bündel samt Hash und Vorstufe', $ek->ReadAttributeString('Buendel') === '' && $ek->ReadAttributeString('AnmeldeVorstufe') === '');
$GLOBALS['robohubTreiberFabrik'] = $fabrikAlt;
