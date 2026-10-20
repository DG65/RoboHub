<?php
/**
 * Worx/Kress/LandXcape/Ferrex-Teil des Prüfstands (von test-robohub.php eingebunden).
 * WebSocket-Werte aus RFC 6455 (Beispiele 1.3 und 5.7), MQTT-Benutzername aus
 * Python urllib.parse.quote wie in pyworxcloud.
 */

declare(strict_types=1);

/** Attrappe: ersetzt http() und das MQTT-Senden, der Rest ist echter Code. */
class FakeWorx extends RoboHubWorx
{
    public static $anfragen = [];
    public static $gesendet = [];
    public static $maeher = [];
    public static $tokenNr = 1;
    public static $mqttFehler = null;

    public static function letzte(string $teil): ?array
    {
        for ($i = count(self::$anfragen) - 1; $i >= 0; $i--) {
            if (strpos(self::$anfragen[$i]['url'], $teil) !== false) {
                return self::$anfragen[$i];
            }
        }
        return null;
    }

    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        self::$anfragen[] = ['methode' => $methode, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if (strpos($url, 'oauth/token') !== false) {
            $b = json_decode((string)$body, true);
            if (($b['grant_type'] ?? '') === 'password' && ($b['password'] ?? '') !== 'geheim') {
                return [400, '{"error":"invalid_grant"}'];
            }
            $n = self::$tokenNr++;
            return [200, json_encode(['access_token' => 'kopf' . $n . '_x-y.inhalt' . $n . '.sig' . $n . '_z', 'refresh_token' => 'RF' . $n, 'expires_in' => 3600, 'token_type' => 'Bearer'])];
        }
        if (strpos(implode("\n", $headers), 'Authorization: Bearer ') === false) {
            return [401, '{}'];
        }
        if (preg_match('#/api/v2/product-items\?status=1#', $url)) {
            return [200, json_encode(array_values(self::$maeher))];
        }
        if (preg_match('#/api/v2/product-items/([^?]+)\?#', $url, $m)) {
            return isset(self::$maeher[rawurldecode($m[1])]) ? [200, json_encode(self::$maeher[rawurldecode($m[1])])] : [404, '{}'];
        }
        return [404, '{}'];
    }

    protected function mqttSenden(string $host, string $client, string $benutzer, string $passwort, string $topic, string $nachricht): void
    {
        if (self::$mqttFehler !== null) {
            throw new RoboHubFehler(self::$mqttFehler);
        }
        self::$gesendet[] = compact('host', 'client', 'benutzer', 'passwort', 'topic', 'nachricht');
    }
}

// ===========================================================================
abschnitt('MQTT-Sitzung: WebSocket nach RFC 6455');
check('Sec-WebSocket-Accept wie RFC 6455 1.3', RoboHubMqttSitzung::wsAkzeptanz('dGhlIHNhbXBsZSBub25jZQ==') === 's3pPLMBiTxaQ9kYGzzhZRbK+xOo=');
check('maskierter Rahmen „Hello" wie RFC 6455 5.7', bin2hex(RoboHubMqttSitzung::wsRahmen('Hello', "\x37\xfa\x21\x3d", 0x1)) === '818537fa213d7f9f4d5158');
$puffer = hex2bin('810548656c6c6f') . hex2bin('8a00') . "\x82";
$r = RoboHubMqttSitzung::wsLesen($puffer);
check('Server-Rahmen gelesen, angefangener bleibt im Puffer', count($r) === 2 && $r[0] === ['op' => 1, 'daten' => 'Hello'] && $r[1]['op'] === 0xA && $puffer === "\x82");
$gross = str_repeat('x', 70000);
$puffer = "\x82\x7f" . pack('J', 70000) . $gross;
check('Rahmen mit 64-Bit-Länge', RoboHubMqttSitzung::wsLesen($puffer)[0]['daten'] === $gross);
$puffer = RoboHubMqttSitzung::wsRahmen(str_repeat('a', 300), "\x01\x02\x03\x04");
check('eigener 16-Bit-Rahmen lässt sich zurücklesen (maskiert)', RoboHubMqttSitzung::wsLesen($puffer)[0]['daten'] === str_repeat('a', 300));
check('PUBLISH QoS 1 mit Paket-ID', bin2hex(RoboHubMqtt::publish('a/b', '{}', 1, 1)) === '320900036' . '12f6200017b7d');
check('CONNECT ohne Passwort setzt kein Passwort-Flag', ord(RoboHubMqtt::connect('c', 'u', '', 30)[9]) === 0x82);

// ===========================================================================
abschnitt('Worx: Protokoll');
[$nutzer, $pw] = RoboHubWorx::mqttBenutzer('eyJhbGc_iOi-J.eyJzdW_IiO-iI+x/y=.c2ln_bm-F0dX+J/l=');
check('MQTT-Benutzername = pyworxcloud-Bildung (quote, „/" bleibt)', $nutzer === "robohub?jwt=eyJhbGc/iOi%2BJ.eyJzdW/IiO%2BiI%2Bx/y%3D&x-amz-customauthorizer-name=''&x-amz-customauthorizer-signature=c2ln/bm%2BF0dX%2BJ/l%3D" && $pw === '');
$zeit = mktime(14, 5, 9, 7, 3, 2026);
check('Nachricht Draht-Mäher: sn, Uhrzeit, Datum', RoboHubWorx::nachricht(['id' => 'SN1', 'art' => 'wire'], ['cmd' => 1], 4711, $zeit)
    === ['id' => 4711, 'cmd' => 1, 'lg' => 'de', 'sn' => 'SN1', 'tm' => '14:05:09', 'dt' => '03/07/2026']);
check('Nachricht Vision-Mäher: uuid und Ortszeit im ISO-Format', RoboHubWorx::nachricht(['id' => 'SN2', 'art' => 'vision', 'uuid' => 'U-1'], ['cmd' => 3], 5, $zeit)
    === ['id' => 5, 'cmd' => 3, 'lg' => 'de', 'uuid' => 'U-1', 'tm' => '2026-07-03T14:05:09.000Z']);
check('Geräteart aus capabilities', RoboHubWorx::artVon([]) === 'wire' && RoboHubWorx::artVon(['vision']) === 'vision' && RoboHubWorx::artVon(['vision', 'rtk']) === 'cloud' && RoboHubWorx::artVon(['maps']) === 'rtk');
check('Wurzelzertifikat ist Amazon Root CA 1', openssl_x509_fingerprint(RoboHubWorx::MQTT_WURZEL, 'sha256') === '8ecde6884f3d87b1125ba31ac3fcb13d7016de7f57cc904fe1cb97c6ae98196e');
$quelle = new ReflectionMethod('RoboHubWorx', 'mqttSenden');
$text = implode('', array_slice(file($quelle->getFileName()), $quelle->getStartLine() - 1, $quelle->getEndLine() - $quelle->getStartLine() + 1));
check('Befehle über WebSocket /mqtt mit Amazon-Wurzel, QoS 1 + Bestätigung', strpos($text, "RoboHubMqttSitzung::oeffnen(\$host, 443, self::MQTT_WURZEL, '/mqtt'") !== false && strpos($text, 'RoboHubMqtt::PUBACK') !== false);

// ===========================================================================
abschnitt('Worx: Anmeldung, Geräte, Zustand, Befehle');
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) {
    if ($marke === 'roborock') {
        return new FakeRoborock();
    }
    return isset(RoboHubWorx::MARKEN[$marke]) ? new FakeWorx($marke) : new FakeCloud($marke);
};
$draht = ['serial_number' => '20213012345', 'name' => 'Rasenkönig', 'product_id' => 'WR165E', 'online' => true, 'user_id' => 99, 'uuid' => 'uu-1',
    'mqtt_endpoint' => 'iot.eu-west-1.worxlandroid.com', 'mqtt_topics' => ['command_in' => 'PRM100/aabbcc/commandIn', 'command_out' => 'PRM100/aabbcc/commandOut'],
    'capabilities' => ['multi_zone'], 'last_status' => ['timestamp' => '2026-10-12 12:00:00', 'payload' => [
        'cfg' => ['lg' => 'de', 'rd' => 60],
        'dat' => ['ls' => 1, 'le' => 0, 'bt' => ['p' => 88, 'c' => 1, 'v' => 19.8], 'st' => ['b' => 1200, 'd' => 400000, 'wt' => 5460], 'rain' => ['s' => 0, 'cnt' => 0]],
    ]]];
FakeWorx::$maeher = ['20213012345' => $draht];
$wk = new RoboHubKonto(5000);
$wk->props['Hersteller'] = 'worx';
$wk->Create();
$wk->ApplyChanges();
$f = json_encode(json_decode($wk->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
$elemente = [];
$sammeln = function (array $knoten) use (&$sammeln, &$elemente): void {
    if (isset($knoten['type'])) {
        $elemente[] = $knoten;
    }
    foreach ($knoten as $kind) {
        if (is_array($kind)) {
            $sammeln($kind);
        }
    }
};
$sammeln(json_decode($wk->GetConfigurationForm(), true));
$finde = function (string $schluessel, string $wert) use ($elemente): ?array {
    foreach ($elemente as $e) {
        if (($e[$schluessel] ?? null) === $wert) {
            return $e;
        }
    }
    return null;
};
check('Formular: Worx wählbar, keine Region, kein Code-Knopf', strpos($f, 'Worx Landroid (App „Landroid') !== false
    && ($finde('name', 'Region')['visible'] ?? true) === false && ($finde('caption', '📧 Code anfordern')['visible'] ?? true) === false);
check('Formular: Echtzeit ehrlich „noch nicht"', strpos($f, 'Für Worx gibt es noch keine Echtzeit-Meldungen') !== false);
$wk->props['Konto'] = 'garten@example.org';
$wk->props['Passwort'] = 'falsch';
check('falsches Passwort abgelehnt', strpos($wk->Anmelden(), 'Anmeldung abgelehnt') !== false);
$wk->props['Passwort'] = 'geheim';
$t = $wk->Anmelden();
check('Anmeldung erfolgreich, Passwort verworfen', strpos($t, '✅ Angemeldet') === 0 && $wk->props['Passwort'] === '', $t);
$login = json_decode((string)FakeWorx::letzte('id.worx.com/oauth/token')['body'], true);
check('OAuth „password" mit Worx-Client-ID', ($login['client_id'] ?? '') === '150da4d2-bb44-433b-9429-3773adc70a2a' && $login['grant_type'] === 'password' && $login['scope'] === '*');
$wb = json_decode($wk->ReadAttributeString('Buendel'), true);
check('Bündel ohne Passwort, mit Erneuerungsschlüssel', ($wb['refresh_token'] ?? '') !== '' && strpos($wk->ReadAttributeString('Buendel'), 'geheim') === false);
$wg = json_decode($wk->ReadAttributeString('Geraete'), true);
check('Mäher gefunden: Klasse, Befehlskanal, Art', count($wg) === 1 && $wg[0]['klasse'] === 'maeher' && $wg[0]['modell'] === 'worx.mower.wr165e' && $wg[0]['mqtt']['befehl'] === 'PRM100/aabbcc/commandIn' && $wg[0]['art'] === 'wire');
$wb['expires_at'] = time() + 60;
$wk->WriteAttributeString('Buendel', json_encode($wb));
$wk->Erneuern();
$neu = json_decode($wk->ReadAttributeString('Buendel'), true);
$rf = json_decode((string)FakeWorx::letzte('oauth/token')['body'], true);
check('kurz vor Ablauf: Erneuerung mit rotierendem Schlüssel', $rf['grant_type'] === 'refresh_token' && $rf['refresh_token'] === $wb['refresh_token'] && $neu['refresh_token'] !== $wb['refresh_token']);

$wm = new RoboHubGeraet(5100);
$wm->props = ['GeraetID' => '20213012345', 'Modell' => 'worx.mower.wr165e', 'Klasse' => 'maeher', 'Intervall' => 120];
$wm->Create();
$wm->parent = $wk;
$wm->ApplyChanges();
$t = $wm->Aktualisieren();
check('zu Hause + lädt = Lädt', variable(5100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_LAEDT && variable(5100, 'ZustandRoh')['value'] === 'Zu Hause (1)', $t);
check('Akku 88, lädt, kein Fehler, kein Regen', variable(5100, 'Akku')['value'] === 88 && variable(5100, 'Laedt')['value'] === true && variable(5100, 'Fehler')['value'] === 'kein Fehler' && variable(5100, 'Regensensor')['value'] === false);
check('Mähzeit gesamt in Stunden', variable(5100, 'GesamtDauer')['value'] === 91);
FakeWorx::$maeher['20213012345']['last_status']['payload']['dat'] = ['ls' => 1, 'le' => 5, 'bt' => ['p' => 100, 'c' => 0], 'rain' => ['s' => 1]];
$wm->Aktualisieren();
check('Regenverzögerung = Wetterpause', variable(5100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_WETTERPAUSE && strpos(variable(5100, 'Fehler')['value'], 'Regenverzögerung') === 0 && variable(5100, 'Regensensor')['value'] === true);
FakeWorx::$maeher['20213012345']['last_status']['payload']['dat'] = ['ls' => 9, 'le' => 1, 'bt' => ['p' => 60, 'c' => 0]];
$wm->Aktualisieren();
check('steckt fest = Fehler', variable(5100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_FEHLER && strpos(variable(5100, 'Fehler')['value'], 'Steckt fest') === 0);
FakeWorx::$maeher['20213012345']['last_status']['payload']['dat'] = ['ls' => 7, 'le' => 0, 'bt' => ['p' => 70, 'c' => 0]];
$wm->Aktualisieren();
check('mäht', variable(5100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_REINIGT);

FakeWorx::$gesendet = [];
$wm->Starten();
$wm->Anhalten();
$wm->ZurStation();
$cmds = array_map(function ($g) { return json_decode($g['nachricht'], true)['cmd']; }, FakeWorx::$gesendet);
check('Start 1, Pause 2, Zur Station 3', $cmds === [1, 2, 3]);
$g0 = FakeWorx::$gesendet[0] ?? [];
$n0 = json_decode((string)($g0['nachricht'] ?? '{}'), true);
check('an command_in, Endpunkt des Mähers, Client-ID mit Präfix und Nutzer', ($g0['topic'] ?? '') === 'PRM100/aabbcc/commandIn' && ($g0['host'] ?? '') === 'iot.eu-west-1.worxlandroid.com'
    && preg_match('#^WX/USER/99/robohub/[0-9a-f]{16}$#', (string)($g0['client'] ?? '')) === 1 && ($n0['sn'] ?? '') === '20213012345' && strpos((string)$g0['benutzer'], 'robohub?jwt=') === 0);
check('Benutzername aus dem aktuellen Zugangsschlüssel', strpos((string)$g0['benutzer'], 'signature=sig2/z') !== false || strpos((string)$g0['benutzer'], 'signature=sig3/z') !== false, (string)$g0['benutzer']);
$t = $wm->Orten();
check('Orten: ehrlich „gibt es nicht"', strpos($t, 'gibt es bei Worx') !== false, $t);
FakeWorx::$mqttFehler = 'Der Befehlsserver lehnt die Anmeldung ab: nicht berechtigt.';
$t = $wm->Starten();
check('MQTT-Ablehnung wird gemeldet', strpos($t, 'nicht berechtigt') !== false, $t);
FakeWorx::$mqttFehler = null;

$kk = new RoboHubKonto(5200);
$kk->props['Hersteller'] = 'kress';
$kk->Create();
$kk->props['Konto'] = 'k@example.org';
$kk->props['Passwort'] = 'geheim';
$kk->Anmelden();
check('Kress: eigener Login-Server und Client', ($kl = FakeWorx::letzte('id.kress.com/oauth/token')) !== null && json_decode((string)$kl['body'], true)['client_id'] === '931d4bc4-3192-405a-be78-98e43486dc59');
check('Kress: Geräteabruf beim Kress-Server', FakeWorx::letzte('api.kress-robotik.com/api/v2/product-items') !== null);
FakeWorx::$maeher = ['K-1' => ['serial_number' => 'K-1', 'name' => 'Kressi', 'product_id' => 'KR233E', 'online' => true, 'user_id' => 7, 'uuid' => 'k-u',
    'mqtt_endpoint' => 'iot.eu-west-1.kress-robotik.com', 'mqtt_topics' => ['command_in' => 'KR/k1/commandIn'], 'capabilities' => [],
    'last_status' => ['payload' => ['dat' => ['ls' => 1, 'le' => 0, 'bt' => ['p' => 100, 'c' => 0]]]]]];
$kk->GeraeteSuchen();
$km = new RoboHubGeraet(5300);
$km->props = ['GeraetID' => 'K-1', 'Modell' => 'kress.mower.kr233e', 'Klasse' => 'maeher', 'Intervall' => 120];
$km->Create();
$km->parent = $kk;
$km->ApplyChanges();
FakeWorx::$gesendet = [];
$km->ZurStation();
check('Kress: Befehl mit Präfix KR an den Kress-Endpunkt', preg_match('#^KR/USER/7/robohub/#', (string)(FakeWorx::$gesendet[0]['client'] ?? '')) === 1 && (FakeWorx::$gesendet[0]['host'] ?? '') === 'iot.eu-west-1.kress-robotik.com');

$puffer = "\x82\x05He";
check('unvollständiger WebSocket-Rahmen wartet auf den Rest', RoboHubMqttSitzung::wsLesen($puffer) === [] && $puffer === "\x82\x05He");

// Echte Treiberwahl (ohne Prüfstand-Fabrik).
$fabrik = $GLOBALS['robohubTreiberFabrik'];
unset($GLOBALS['robohubTreiberFabrik']);
$wahl = [];
foreach (['dreame' => 'RoboHubDreame', 'mova' => 'RoboHubDreame', 'roborock' => 'RoboHubRoborock', 'worx' => 'RoboHubWorx', 'kress' => 'RoboHubWorx', 'landxcape' => 'RoboHubWorx', 'ferrex' => 'RoboHubWorx', 'ecovacs' => 'RoboHubEcovacs', 'irobot' => 'RoboHubIrobot', 'husqvarna' => 'RoboHubHusqvarna', 'gardena' => 'RoboHubGardena'] as $h => $klasse) {
    $tk = new RoboHubKonto(5900 + count($wahl));
    $tk->props['Hersteller'] = $h;
    $tk->Create();
    $m = new ReflectionMethod('RoboHubKonto', 'treiber');
    $wahl[$h] = get_class($m->invoke($tk)) === $klasse;
}
$GLOBALS['robohubTreiberFabrik'] = $fabrik;
check('Konto wählt für jeden Hersteller den richtigen Treiber', !in_array(false, $wahl, true), json_encode($wahl));

$wk->ApplyChanges();
check('Hersteller ohne Konto-Echtzeit (Worx): Client Socket wird trotzdem nachgeholt', ($wk->timers['Verbindung'] ?? 0) === 15000);
