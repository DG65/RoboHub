<?php
/**
 * Roborock-Teil des Prüfstands (wird von test-robohub.php eingebunden).
 *
 * Referenzwerte stammen aus einer unabhängigen Nachbildung mit Python-
 * Standardbibliothek + openssl (Logik aus python-roborock protocol.py /
 * web_api.py, Apache-2.0), der Endpunkt „87ItGWdb" und die Nutzlast direkt
 * aus python-roborock tests/protocols/test_v1_protocol.py.
 */

declare(strict_types=1);

/** Attrappe: ersetzt http() und die MQTT-Sitzung, der Rest ist echter Code. */
class FakeRoborock extends RoboHubRoborock
{
    public static $anfragen = [];
    public static $rpc = [];          // gesendete [methode, params]
    public static $sitzungen = [];    // [host, port, benutzer, passwort, abo, ziel]
    public static $antworten = [];    // methode => result (oder ['__fehler' => …], oder '__still')
    public static $geraete = [];
    public static $raeume = [];
    public const RRIOT = ['u' => 'user123', 's' => 'pass123', 'h' => 'unknown123', 'k' => 'qiCNieZa',
        'r' => ['r' => 'EU', 'a' => 'https://api-eu.roborock.com', 'm' => 'ssl://mqtt-eu-3.roborock.com:8883', 'l' => 'https://wood-eu.roborock.com']];

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
        $ok = function ($data) { return [200, json_encode(['code' => 200, 'msg' => 'success', 'data' => $data])]; };
        if (strpos($url, '/api/v1/getUrlByEmail') !== false) {
            return strpos($url, 'euiot') !== false
                ? $ok(['url' => 'https://euiot.roborock.com', 'country' => 'DE', 'countrycode' => '49'])
                : [200, json_encode(['code' => 2008, 'msg' => 'account not exist'])];
        }
        if (strpos($url, '/api/v4/email/code/send') !== false || strpos($url, '/api/v1/sendEmailCode') !== false) {
            return $ok(null);
        }
        if (strpos($url, '/api/v3/key/sign') !== false) {
            return $ok(['k' => 'SIGNIERT']);
        }
        if (strpos($url, '/api/v3/app/agreement/latest') !== false) {
            return $ok(['majorVersion' => 3, 'minorVersion' => 7]);
        }
        if (strpos($url, '/api/v4/auth/email/login/code') !== false) {
            parse_str((string)$body, $f);
            if (($f['code'] ?? '') !== '123456') {
                return [200, json_encode(['code' => 2018, 'msg' => 'invalid code'])];
            }
            return $ok(['uid' => 1, 'token' => 'TOKEN1', 'rriot' => self::RRIOT, 'country' => 'DE']);
        }
        if (strpos($url, '/api/v1/getHomeDetail') !== false) {
            return $ok(['rrHomeId' => 4711]);
        }
        if (strpos($url, '/v3/user/homes/4711') !== false) {
            $kopf = implode("\n", $headers);
            if (!preg_match('/Authorization: Hawk id="user123",s="pass123",ts="(\d+)",nonce="([^"]+)",mac="([^"]+)"/', $kopf, $m)
                || RoboHubRoborock::hawkKopf(self::RRIOT, '/v3/user/homes/4711', (int)$m[1], $m[2]) !== substr($kopf, strpos($kopf, 'Hawk '))) {
                return [401, '{"success":false}'];
            }
            return [200, json_encode(['success' => true, 'result' => [
                'id' => 4711, 'name' => 'Zuhause',
                'products' => [
                    ['id' => 'p-a27', 'name' => 'Roborock S7 MaxV', 'model' => 'roborock.vacuum.a27', 'category' => 'robot.vacuum.cleaner', 'iconUrl' => 'https://files.roborock.com/s7.png'],
                    ['id' => 'p-q7', 'name' => 'Roborock Q7', 'model' => 'roborock.vacuum.sc01', 'category' => 'robot.vacuum.cleaner'],
                ],
                'devices' => self::$geraete,
                'receivedDevices' => [],
                'rooms' => self::$raeume,
            ]])];
        }
        return [404, '{}'];
    }

    protected function mqttAustausch(string $host, int $port, string $benutzer, string $passwort, string $abo, string $ziel, array $rahmen, callable $verarbeite, float $frist): void
    {
        self::$sitzungen[] = [$host, $port, $benutzer, $passwort, $abo, $ziel];
        foreach ($rahmen as $r) {
            foreach (RoboHubRoborock::rahmenLesen($r, 'key123key123key1') as $n) {
                $j = json_decode($n['nutzlast'], true);
                $a = json_decode((string)$j['dps']['101'], true);
                self::$rpc[] = [$a['method'], $a['params'], $a['security'] ?? null];
                $antwort = self::$antworten[$a['method']] ?? 'ok';
                if ($antwort === '__still') {
                    continue;
                }
                if (is_array($antwort) && isset($antwort['__fehler'])) {
                    $innen = ['id' => $a['id'], 'error' => ['code' => -10007, 'message' => $antwort['__fehler']]];
                } else {
                    $innen = ['id' => $a['id'], 'result' => $antwort === 'ok' ? ['ok'] : $antwort];
                }
                $nutzlast = json_encode(['t' => time(), 'dps' => ['102' => json_encode($innen)]]);
                // Fremde Nachricht vorweg (anderes Protokoll, aber gleiche ID) — muss übergangen werden.
                $verarbeite(RoboHubRoborock::rahmenBauen(json_encode(['t' => time(), 'dps' => ['102' => json_encode(['id' => $a['id'], 'result' => ['fremd']])]]), 'key123key123key1', 1, 2, time(), 121));
                if ($verarbeite(RoboHubRoborock::rahmenBauen($nutzlast, 'key123key123key1', 5, 6, time(), 102))) {
                    return;
                }
            }
        }
    }
}

// ===========================================================================
abschnitt('Roborock: Protokoll gegen Referenzwerte');
check('Zeitstempel-Kodierung (encode_timestamp)', RoboHubRoborock::zeitKodiert(1737374400) === 'ace07863');
$nutzlastRef = '{"dps":{"101":"{\"id\":44444,\"method\":\"get_status\",\"params\":[]}"},"t":1737374400}';
$rahmenRef = '312e300001e2400000d431678e3ac000650060898384cc94d6962d0768ab27d9a4d96aaf83cc49b74932f02be1c99fa4d1a789a2a54a492f119f5491306964b5dce5964d89e34358b20a381a62e03ecadc63801772b39e030c56f5f6b50a3c32f226fb7e5924b8005870dc026c2dee0521c0d70bcdca95';
check('Rahmen (AES-128-ECB + CRC32) = Referenz', bin2hex(RoboHubRoborock::rahmenBauen($nutzlastRef, 'key123key123key1', 123456, 54321, 1737374400, 101)) === $rahmenRef);
$gelesen = RoboHubRoborock::rahmenLesen(hex2bin($rahmenRef), 'key123key123key1');
check('Referenzrahmen entschlüsselt', count($gelesen) === 1 && $gelesen[0]['nutzlast'] === $nutzlastRef && $gelesen[0]['protokoll'] === 101);
check('Rahmen mit Müll davor und dahinter gefunden', count(RoboHubRoborock::rahmenLesen("\x00\x00\x05\xa1" . hex2bin($rahmenRef) . "\xff", 'key123key123key1')) === 1);
$kaputt = hex2bin($rahmenRef);
$kaputt[30] = chr(ord($kaputt[30]) ^ 1);
check('Rahmen mit falscher Prüfsumme verworfen', RoboHubRoborock::rahmenLesen($kaputt, 'key123key123key1') === []);
check('falscher Gerätschlüssel → nichts gelesen', RoboHubRoborock::rahmenLesen(hex2bin($rahmenRef), 'falsch1234567890') === []);
check('Hawk-Signatur = Referenz', RoboHubRoborock::hawkKopf(FakeRoborock::RRIOT, '/v3/user/homes/123456', 1700000000, 'abcdefgh')
    === 'Hawk id="user123",s="pass123",ts="1700000000",nonce="abcdefgh",mac="wAbZvOuUBXbyJs9Sup976YlIVRMQF2dHOAK/NDsVMwM="');
check('Nutzlast mit Sicherheitsdaten = python-roborock-Test', RoboHubRoborock::anfrageNutzlast(44444, 'get_status', [], 1737374400, '87ItGWdb', '91be10c9622b9d8acd482a19f6fe8168')
    === '{"dps":{"101":"{\"id\":44444,\"method\":\"get_status\",\"params\":[],\"security\":{\"endpoint\":\"87ItGWdb\",\"nonce\":\"91be10c9622b9d8acd482a19f6fe8168\"}}"},"t":1737374400}');
check('MQTT-Server aus rriot.r.m', RoboHubRoborock::mqttServer(FakeRoborock::RRIOT) === ['mqtt-eu-3.roborock.com', 8883]);
$a = RoboHubRoborock::antwortLesen('{"t":1,"dps":{"102":"{\"id\":7,\"result\":\"unknown_method\"}"}}');
check('„unknown_method" wird Fehler', $a['id'] === 7 && strpos((string)$a['fehler'], 'unbekannt') !== false);
check('Raumzuordnung: Liste und einzelnes Paar', RoboHubRoborock::raumZuordnung([[16, '3031886'], [17, '3031880']]) === [16 => '3031886', 17 => '3031880'] && RoboHubRoborock::raumZuordnung([16, 3031886]) === [16 => '3031886']);

$quelle = (new ReflectionMethod('RoboHubRoborock', 'mqttAustausch'));
$zeilen = array_slice(file($quelle->getFileName()), $quelle->getStartLine() - 1, $quelle->getEndLine() - $quelle->getStartLine() + 1);
$text = implode('', $zeilen);
check('MQTT-TLS prüft Zertifikat und Namen gegen die eigene Wurzel (nie abschaltbar)', substr_count($text, "'verify_peer' => true") === 1 && strpos($text, "'verify_peer_name' => true") !== false
    && strpos($text, "'cafile' => self::caDatei()") !== false && strpos($text, '=> false') === false && strpos($text, "'tls://'") !== false);
check('Wurzelzertifikat ist DigiCert Global Root G2', strtoupper(implode(':', str_split(openssl_x509_fingerprint(RoboHubRoborock::MQTT_WURZEL, 'sha256'), 2)))
    === 'CB:3C:CB:B7:60:31:E5:E0:13:8F:8D:D3:9A:23:F9:DE:47:FF:C3:5E:43:C1:14:4C:EA:27:D4:6A:5A:B1:CB:5F');

// ===========================================================================
abschnitt('Roborock: Anmeldung per E-Mail-Code');
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) { return $marke === 'roborock' ? new FakeRoborock() : new FakeCloud($marke); };
FakeRoborock::$geraete = [
    ['duid' => 'abc123', 'name' => 'Roborock S7 MaxV', 'localKey' => 'key123key123key1', 'productId' => 'p-a27', 'online' => true, 'pv' => '1.0', 'fv' => '02.56.02'],
    ['duid' => 'q7neu', 'name' => 'Q7', 'localKey' => 'xyzxyzxyzxyzxyz1', 'productId' => 'p-q7', 'online' => true, 'pv' => 'B01'],
];
FakeRoborock::$raeume = [['id' => 3031886, 'name' => 'Küche'], ['id' => 3031880, 'name' => 'Flur'], ['id' => 3031883, 'name' => 'Bad']];
$rk = new RoboHubKonto(4000);
$rk->props['Hersteller'] = 'roborock';
$rk->Create();
$rk->ApplyChanges();
$f = json_encode(json_decode($rk->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
check('Formular: Code-Knopf und Code-Feld bei Roborock', strpos($f, 'ROBOHUB_CodeAnfordern') !== false && strpos($f, 'Code aus der E-Mail') !== false);
check('Formular: Echtzeit ehrlich als „noch nicht" beschrieben', strpos($f, 'Für Roborock gibt es noch keine Echtzeit-Meldungen') !== false);
check('Code anfordern ohne E-Mail: klare Meldung', strpos($rk->CodeAnfordern(), '⛔') === 0);
$rk->props['Konto'] = 'nutzer@example.org';
$t = $rk->CodeAnfordern();
$code = FakeRoborock::letzte('/api/v4/email/code/send');
parse_str((string)($code['body'] ?? ''), $cf);
check('Code angefordert (v4, Formular mit E-Mail)', strpos($t, '📧') === 0 && ($cf['email'] ?? '') === 'nutzer@example.org' && ($cf['type'] ?? '') === 'login', $t);
check('Server über getUrlByEmail gefunden (EU)', strpos((string)($code['url'] ?? ''), 'https://euiot.roborock.com') === 0);
$rk->props['Passwort'] = 'MeinPasswort!';
$t = $rk->Anmelden();
check('Passwort statt Code → verständlicher Hinweis, nichts gesendet', strpos($t, 'Code aus der E-Mail') !== false && FakeRoborock::letzte('/login/code') === null, $t);
$rk->props['Passwort'] = '999999';
check('falscher Code abgelehnt', strpos($rk->Anmelden(), 'Code ist falsch') !== false);
$rk->props['Passwort'] = '123456';
$t = $rk->Anmelden();
check('Anmeldung mit Code erfolgreich', strpos($t, '✅ Angemeldet') === 0 && strpos($t, 'der Code wurde verworfen') !== false, $t);
$login = FakeRoborock::letzte('/api/v4/auth/email/login/code');
$kopf = implode("\n", $login['headers']);
parse_str((string)$login['body'], $lf);
check('Login mit Signatur-Köpfen und Vertragsversion', strpos($kopf, 'x-mercy-k: SIGNIERT') !== false && preg_match('/x-mercy-ks: [A-Za-z0-9]{16}/', $kopf) === 1 && $lf['majorVersion'] === '3' && $lf['country'] === 'DE' && $lf['countryCode'] === '49');
check('Client-Kennung bei Code und Login gleich', preg_match('/header_clientid: (\S+)/', implode("\n", $code['headers']), $c1) && strpos($kopf, 'header_clientid: ' . $c1[1]) !== false);
check('Code-Property danach leer', $rk->props['Passwort'] === '');
$rb = json_decode($rk->ReadAttributeString('Buendel'), true);
check('Bündel: rriot + Token, kein Code', ($rb['rriot']['k'] ?? '') === 'qiCNieZa' && ($rb['token'] ?? '') === 'TOKEN1' && strpos($rk->ReadAttributeString('Buendel'), '123456') === false);
$rg = json_decode($rk->ReadAttributeString('Geraete'), true);
check('Geräte aus den Hausdaten (Hawk geprüft)', count($rg) === 2 && $rg[0]['id'] === 'abc123' && $rg[0]['modell'] === 'roborock.vacuum.a27' && $rg[0]['klasse'] === 'sauger');
check('Gerätschlüssel im Konto gespeichert', ($rg[0]['schluessel'] ?? '') === 'key123key123key1');
$liste = json_decode($rk->GeraeteListe(), true);
check('GeraeteListe gibt keine Gerätschlüssel heraus', strpos(json_encode($liste), 'key123key123key1') === false && $liste['anzeige'] === 'Roborock (nutzer@example.org)');

// ===========================================================================
abschnitt('Roborock: Zustand');
$fx = function (string $datei) {
    $roh = json_decode(file_get_contents(__DIR__ . '/roborock/' . $datei . '.json'), true);
    return json_decode($roh['dps']['102'], true)['result'];
};
FakeRoborock::$antworten = [
    'get_status' => $fx('get_status'), 'get_consumable' => $fx('get_consumeables'), 'get_clean_summary' => $fx('get_clean_summary'),
    'get_dnd_timer' => $fx('get_dnd'), 'get_sound_volume' => $fx('get_volume'), 'get_room_mapping' => $fx('get_room_mapping'),
];
$rr = new RoboHubGeraet(4100);
$rr->props = ['GeraetID' => 'abc123', 'Modell' => 'roborock.vacuum.a27', 'Klasse' => 'sauger', 'Intervall' => 120];
$rr->Create();
$rr->parent = $rk;
$rr->ApplyChanges();
FakeRoborock::$rpc = [];
FakeRoborock::$sitzungen = [];
$t = $rr->Aktualisieren();
check('Zustand in einer MQTT-Sitzung mit 5 Anfragen, Räume beim ersten Abruf mitgeladen', count(FakeRoborock::$sitzungen) === 2
    && array_column(FakeRoborock::$rpc, 0) === ['get_status', 'get_consumable', 'get_clean_summary', 'get_dnd_timer', 'get_sound_volume', 'get_room_mapping'], $t);
[$host, $port, $nutzer, $pw, $abo, $ziel] = FakeRoborock::$sitzungen[0];
check('MQTT-Zugang = Referenz (md5-Ableitung)', $nutzer === '19648f94' && $pw === '234678ea854f199e' && $host === 'mqtt-eu-3.roborock.com' && $port === 8883);
check('Topics rr/m/o bzw. rr/m/i mit duid', $abo === 'rr/m/o/user123/19648f94/abc123' && $ziel === 'rr/m/i/user123/19648f94/abc123');
check('jede Anfrage trägt Endpunkt „87ItGWdb" und 32-stellige Nonce', count(array_filter(FakeRoborock::$rpc, function ($r) { return ($r[2]['endpoint'] ?? '') === '87ItGWdb' && strlen((string)($r[2]['nonce'] ?? '')) === 32; })) === 6);
check('Zustand 8 = Lädt', variable(4100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_LAEDT && variable(4100, 'ZustandRoh')['value'] === 'Lädt (8)');
check('Akku 100, kein Fehler', variable(4100, 'Akku')['value'] === 100 && variable(4100, 'Fehler')['value'] === 'kein Fehler');
check('Saugstufe 106 mit Auswahl der neuen Stufen', variable(4100, 'Saugstufe')['value'] === 106 && variable(4100, 'Saugstufe')['action']);
check('Wassermenge 204 als Auswahl', variable(4100, 'Wassermenge')['value'] === 204);
check('Hauptbürste 19 % / 56 h (300 h Lebensdauer)', variable(4100, 'Hauptbuerste')['value'] === 19 && variable(4100, 'HauptbuersteRest')['value'] === 56.0);
check('überzogener Filter = 0 % (nicht negativ)', variable(4100, 'Filter')['value'] === 0 && variable(4100, 'FilterRest')['value'] === 0.0);
check('Gesamtstatistik aus der Liste', variable(4100, 'GesamtDauer')['value'] === 401 && variable(4100, 'GesamtFlaeche')['value'] === 24258 && variable(4100, 'GesamtAnzahl')['value'] === 296);
check('Nicht stören 22:00–08:00 an, schaltbar', variable(4100, 'NichtStoeren')['value'] === true && variable(4100, 'NichtStoerenVon')['value'] === '22:00' && variable(4100, 'NichtStoerenBis')['value'] === '08:00' && variable(4100, 'NichtStoeren')['action']);
check('Lautstärke 90, schaltbar', variable(4100, 'Lautstaerke')['value'] === 90 && variable(4100, 'Lautstaerke')['action']);
$kd = kachelJson($rr->GetVisualizationTile());
check('Kachel: Saugstufen-Auswahl aus dem Treiber, kein Modus', in_array([102, 'Ausgewogen'], $kd['startWahl']['saug'] ?? [], true) && ($kd['startWahl']['modus'] ?? null) === []);

FakeRoborock::$antworten['get_sound_volume'] = '__still';
$rr->Aktualisieren();
check('ein Block ohne Antwort: Rest trotzdem übernommen', variable(4100, 'Akku')['value'] === 100);
$alle = FakeRoborock::$antworten;
FakeRoborock::$antworten = array_fill_keys(array_keys($alle), '__still');
$t = $rr->Aktualisieren();
check('Roboter antwortet gar nicht → klare Meldung', strpos($t, 'antwortet nicht') !== false, $t);
FakeRoborock::$antworten = $alle;

// ===========================================================================
abschnitt('Roborock: Räume und Befehle');
$t = $rr->RaeumeLaden();
$rv = variable(4100, 'Raum');
$rOpt = $rv === null ? [] : array_column(json_decode($rv['pres']['OPTIONS'], true), 'Caption', 'Value');
check('Räume: Segmente mit Namen aus der Roborock-App', $rOpt === [16 => 'Küche', 17 => 'Flur', 18 => 'Bad'], $t);
FakeRoborock::$rpc = [];
$rr->RaeumeReinigenMit('16,17', -1, 102, 2);
$m = array_column(FakeRoborock::$rpc, 0);
check('Start mit Wahl: Saugstufe, dann Räume mit Wiederholung', $m === ['set_custom_mode', 'app_segment_clean'] && FakeRoborock::$rpc[0][1] === [102] && FakeRoborock::$rpc[1][1] === [['segments' => [16, 17], 'repeat' => 2]]);
FakeRoborock::$rpc = [];
FakeRoborock::$antworten['app_segment_clean'] = ['__fehler' => 'invalid params'];
$t = $rr->RaeumeReinigen('16');
check('alte Firmware: zweiter Versuch mit einfacher Liste', array_column(FakeRoborock::$rpc, 1) === [[['segments' => [16], 'repeat' => 1]], [16]]);
unset(FakeRoborock::$antworten['app_segment_clean']);
FakeRoborock::$rpc = [];
$rr->ZurStation();
$rr->Orten();
check('Station = app_charge, Orten = find_me', array_column(FakeRoborock::$rpc, 0) === ['app_charge', 'find_me']);
$st = json_decode($rk->ReadAttributeString('Schnappschuss'), true);
$st['abc123']['p']['state'] = 10;
$st['abc123']['p']['in_cleaning'] = 3;
$rk->WriteAttributeString('Schnappschuss', json_encode($st));
FakeRoborock::$rpc = [];
$rr->Starten();
check('Start bei pausierter Raumreinigung = resume_segment_clean', array_column(FakeRoborock::$rpc, 0) === ['resume_segment_clean']);
FakeRoborock::$rpc = [];
$rr->RequestAction('Saugstufe', 104);
$rr->RequestAction('Lautstaerke', 50);
$rr->RequestAction('NichtStoerenBis', '07:30');
check('Saugstufe, Lautstärke, Nicht-stören-Ende geschrieben', array_map(function ($r) { return [$r[0], $r[1]]; }, FakeRoborock::$rpc)
    === [['set_custom_mode', [104]], ['change_sound_volume', [50]], ['get_dnd_timer', []], ['set_dnd_timer', [22, 0, 7, 30]]]);
FakeRoborock::$rpc = [];
$rr->RequestAction('NichtStoeren', false);
check('Nicht stören aus = close_dnd_timer', array_column(FakeRoborock::$rpc, 0) === ['get_dnd_timer', 'close_dnd_timer']);
FakeRoborock::$rpc = [];
FakeRoborock::$antworten['get_dnd_timer'] = [['start_hour' => 22, 'start_minute' => 0, 'end_hour' => 8, 'end_minute' => 0, 'enabled' => 0]];
$rr->RequestAction('NichtStoerenVon', '21:00');
check('Zeit ändern bei ausgeschaltetem „Nicht stören" schaltet NICHT ungefragt ein', array_column(FakeRoborock::$rpc, 0) === ['get_dnd_timer']);
FakeRoborock::$antworten['get_dnd_timer'] = $alle['get_dnd_timer'];
FakeRoborock::$rpc = [];
$t = $rr->VerbrauchsteilZuruecksetzen('Hauptbuerste');
check('Hauptbürste zurücksetzen = reset_consumable main_brush_work_time', FakeRoborock::$rpc[0][0] === 'reset_consumable' && FakeRoborock::$rpc[0][1] === ['main_brush_work_time'], $t);
FakeRoborock::$antworten['app_charge'] = ['__fehler' => 'invalid status'];
$t = $rr->ZurStation();
check('Ablehnung des Roboters wird gemeldet', strpos($t, 'invalid status') !== false, $t);
unset(FakeRoborock::$antworten['app_charge']);

$rq = new RoboHubGeraet(4200);
$rq->props = ['GeraetID' => 'q7neu', 'Modell' => 'roborock.vacuum.sc01', 'Klasse' => 'sauger', 'Intervall' => 120];
$rq->Create();
$rq->parent = $rk;
$rq->ApplyChanges();
FakeRoborock::$sitzungen = [];
$t = $rq->Aktualisieren();
check('Gerät mit Protokoll B01: ehrlich „noch nicht unterstützt", keine Sitzung', strpos($t, 'B01') !== false && FakeRoborock::$sitzungen === [], $t);
