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
    public static $karte = null;      // „rr"-Datei für get_map_v1 (null = keine Antwort)
    public static $karteTeile = 1;    // in so vielen Teilen schicken

    public static function kartenNutzlast(string $endpunkt, string $nonce, int $id, string $rr): string
    {
        return str_pad($endpunkt, 8, "\0") . str_repeat("\0", 8) . pack('v', $id) . str_repeat("\0", 6)
            . openssl_encrypt(gzencode($rr), 'aes-128-cbc', $nonce, OPENSSL_RAW_DATA, str_repeat("\0", 16));
    }
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
                if ($a['method'] === 'get_map_v1' && self::$karte !== null) {
                    // Kartenantwort wie python-roborock testing/v1_simulator.py: 301 (bzw. Teile 300…301),
                    // Kopf Endpunkt + 8 × 0 + ID (LE) + 6 × 0, Körper AES-128-CBC(gzip) mit der Rohnonce.
                    $nutzlast = self::kartenNutzlast($a['security']['endpoint'], hex2bin($a['security']['nonce']), (int)$a['id'], self::$karte);
                    $teile = str_split($nutzlast, (int)ceil(strlen($nutzlast) / max(1, self::$karteTeile)));
                    $folge = array_keys($teile);
                    if (self::$karteTeile > 1) {
                        $folge = array_reverse($folge);   // Teile in falscher Reihenfolge (python-roborock: kann vorkommen)
                    }
                    foreach ($folge as $i) {
                        $protokoll = $i === count($teile) - 1 ? 301 : 300;
                        if ($verarbeite(RoboHubRoborock::rahmenBauen($teile[$i], 'key123key123key1', 700 + $i, 4242, time(), $protokoll))) {
                            return;
                        }
                    }
                    continue;
                }
                $antwort = self::$antworten[$a['method']] ?? 'ok';
                if (is_array($antwort) && isset($antwort['__folge'])) {   // je Aufruf die nächste Antwort
                    $antwort = array_shift(self::$antworten[$a['method']]['__folge']) ?? 'ok';
                }
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

/**
 * Kleine „rr"-Karte nach vacuum-map-parser-roborock (map_data_parser.py): Dateikopf 20 Byte, Blöcke
 * Station (1), Bild (2, Kopf 28 mit 0/top/left/height/width), Weg (3, Kopf 20), Roboter (8).
 * Bild 6×4, Zeile 0 = unten: Wandzeile, darüber Raum 16 links / Raum 17 rechts, oben Boden ohne Raum.
 */
function roborockTestKarte(): string
{
    $r16 = chr((16 << 3) | 7);
    $r17 = chr((17 << 3) | 7);
    $pixel = str_repeat("\x01", 6)                         // Zeile 0 (unten): Wand
        . $r16 . $r16 . $r16 . $r17 . $r17 . $r17
        . $r16 . $r16 . $r16 . $r17 . $r17 . $r17
        . "\x00\xff\xff\xff\xff\x00";                     // Zeile 3 (oben): außen/Boden
    $block = function (int $typ, string $kopfRest, string $daten): string {
        return pack('vvV', $typ, 8 + strlen($kopfRest), strlen($daten)) . $kopfRest . $daten;
    };
    $weg = pack('v*', 24600, 25100, 24700, 25150, 24800, 25100);
    $bloecke = $block(1, '', pack('VVV', 24550, 25050, 0))
        . $block(2, pack('VVVVV', 0, 500, 490, 4, 6), $pixel)
        . $block(3, pack('VVV', 3, 4, 0), $weg)
        . $block(8, '', pack('VVV', 24800, 25100, 90));
    return 'rr' . pack('vVvvVV', 20, strlen($bloecke), 1, 0, 1, 7) . $bloecke;
}

// ===========================================================================
abschnitt('Roborock: Karte („rr"-Datei, Antwort 301)');
$rrKarte = roborockTestKarte();
$rk0 = RoboHubRoborockKarte::lesen($rrKarte);
check('Kartendatei gelesen: 6×4 Punkte, Räume 16/17, 50 mm je Punkt, Ursprung links 490/oben 500 Punkte', is_array($rk0) && $rk0['breite'] === 6 && $rk0['hoehe'] === 4
    && $rk0['raeume'] === [16, 17] && $rk0['raster'] === 50 && $rk0['links'] === 24500 && $rk0['oben'] === 25000, json_encode($rk0 === null ? null : array_diff_key($rk0, ['pixel' => 1])));
check('Station, Roboter mit Winkel und Weg in mm', $rk0['station'] === [24550, 25050, 0] && $rk0['roboter'] === [24800, 25100, 90] && $rk0['weg'] === [[[24600, 25100], [24700, 25150], [24800, 25100]]]);
check('Bildpunkte übersetzt: Raum n → n<<2, Wand 252, Boden 248, außen 0', $rk0['pixel'] === str_repeat(chr(252), 6) . str_repeat(chr(64), 3) . str_repeat(chr(68), 3) . str_repeat(chr(64), 3) . str_repeat(chr(68), 3) . "\0" . str_repeat(chr(248), 4) . "\0");
$rkBild = RoboHubRoborockKarte::bild($rk0, [16 => 'Küche', 17 => 'Flur'], true);
check('Kartenbild: PNG, Raumnamen, Station, Weg und Roboter', strpos($rkBild['bild'], 'data:image/png;base64,') === 0 && array_column($rkBild['marken'], 'name') === ['Küche', 'Flur']
    && $rkBild['station'] !== null && count($rkBild['weg'] ?? []) === 1 && ($rkBild['roboter']['winkel'] ?? null) === 90 && !isset($rkBild['kodierung']), json_encode(array_diff_key($rkBild, ['bild' => 1])));
check('ohne Live: kein Weg, kein Roboter', !isset(RoboHubRoborockKarte::bild($rk0, [], false)['weg']));
$roh301 = FakeRoborock::kartenNutzlast('87ItGWdb', str_repeat("\x11", 16), 12345, $rrKarte);
$a301 = RoboHubRoborockKarte::kartenAntwort($roh301, '87ItGWdb', str_repeat("\x11", 16));
check('Antwort 301: Kopf, ID (LE), AES-CBC mit Rohnonce, gzip', $a301 !== null && $a301['id'] === 12345 && $a301['rr'] === $rrKarte);
check('fremder Endpunkt / falsche Nonce → verworfen', RoboHubRoborockKarte::kartenAntwort($roh301, 'andersXX', str_repeat("\x11", 16)) === null
    && RoboHubRoborockKarte::kartenAntwort($roh301, '87ItGWdb', str_repeat("\x22", 16)) === null);
$t3 = str_split($roh301, 40);
$teile = [12 => [false, $t3[0]], 14 => [false, $t3[2] ?? ''], 13 => [false, $t3[1]]];
check('Teile: unvollständig → null', RoboHubRoborockKarte::teileZusammen($teile, '87ItGWdb') === null);
$teileGanz = [];
foreach ($t3 as $i => $t) {
    $teileGanz[20 + $i] = [$i === count($t3) - 1, $t];
}
check('Teile: lückenlos mit 301 am Ende → zusammengesetzt', RoboHubRoborockKarte::teileZusammen(array_reverse($teileGanz, true), '87ItGWdb') === $roh301);
unset($teileGanz[21]);
check('Teile: Lücke → null', RoboHubRoborockKarte::teileZusammen($teileGanz, '87ItGWdb') === null);
// Unterkarte (Block 17): ein Byte je Bildpunkt; 16 = in der App ausgeblendet, 8 = ausgeblendeter Rand, 1 = Teppich.
$rrUnter = function (string $unter): string {
    $rr = roborockTestKarte();
    $block = pack('vvV', 17, 8, strlen($unter)) . $unter;
    return substr($rr, 0, 4) . pack('V', unpack('V', substr($rr, 4, 4))[1] + strlen($block)) . substr($rr, 8) . $block;
};
// Zeile 1 (unten über der Wand): rechte drei Punkte (Raum 17) ausgeblendet; Zeile 2: Raum 16 mit Teppich.
$rkU = RoboHubRoborockKarte::lesen($rrUnter(str_repeat("\0", 6) . "\0\0\0\x10\x08\x18" . "\x01\x01\x01\0\0\0" . str_repeat("\0", 6)));
check('ausgeblendete Bereiche (Block 17, Bit 8/16) → außen, Rest unverändert', is_array($rkU) && $rkU['entfernt'] === 3
    && $rkU['pixel'] === str_repeat(chr(252), 6) . str_repeat(chr(64), 3) . "\0\0\0" . str_repeat(chr(64), 3) . str_repeat(chr(68), 3) . "\0" . str_repeat(chr(248), 4) . "\0", json_encode($rkU === null ? null : bin2hex($rkU['pixel'])));
check('Teppichmaske aus Bit 1, ausgeblendet hat Vorrang', ($rkU['teppich'] ?? '') === str_repeat("\0", 12) . "\1\1\1" . str_repeat("\0", 9));
check('ohne Block 17: keine Maske, nichts ausgeblendet', $rk0['teppich'] === '' && $rk0['entfernt'] === 0);
$nurRaum16 = RoboHubRoborockKarte::lesen($rrUnter(str_repeat("\0\0\0\x10\x10\x10", 4)));   // rechte Hälfte ganz ausgeblendet
$bildU = RoboHubRoborockKarte::bild($nurRaum16, [16 => 'Küche', 17 => 'Flur'], false);
check('ganz ausgeblendeter Raum: keine Beschriftung, Bild schmaler (Platz gespart)', array_column($bildU['marken'], 'name') === ['Küche'] && $bildU['format'] < $rkBild['format'], json_encode([$bildU['format'], $rkBild['format']]));
$mitT = RoboHubRoborockKarte::bild($rkU, [16 => 'Küche'], false, true);
$ohneT = RoboHubRoborockKarte::bild($rkU, [16 => 'Küche'], false, false);
$plte = function (string $uri): string { $png = base64_decode(substr($uri, 22)); $i = strpos($png, 'PLTE'); return substr($png, $i + 4, unpack('N', substr($png, $i - 4, 4))[1]); };
check('Teppich nur auf Wunsch: eigene Farbe in der Palette, sonst Bild wie bisher', strpos($plte($mitT['bild']), "\x76\x7e\x8a") !== false
    && strpos($plte($ohneT['bild']), "\x76\x7e\x8a") === false && $mitT['bild'] !== $ohneT['bild']);
check('Teppich ändert keine Raumfarbe', substr($plte($mitT['bild']), 15) === substr($plte($ohneT['bild']), 12));
check('kaputte Datei → null (kein Absturz)', RoboHubRoborockKarte::lesen('rr' . str_repeat("\xff", 40)) === null && RoboHubRoborockKarte::lesen('xx') === null
    && RoboHubRoborockKarte::entpacken("\x1f\x8bkaputt") === null);

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

$quelle = (new ReflectionMethod('RoboHubMqttSitzung', 'oeffnen'));
$zeilen = array_slice(file($quelle->getFileName()), $quelle->getStartLine() - 1, $quelle->getEndLine() - $quelle->getStartLine() + 1);
$text = implode('', $zeilen);
check('MQTT-TLS prüft Zertifikat und Namen gegen die eigene Wurzel (nie abschaltbar)', substr_count($text, "'verify_peer' => true") === 1 && strpos($text, "'verify_peer_name' => true") !== false
    && strpos($text, '\'cafile\' => self::caDatei($wurzelPem)') !== false && strpos($text, '=> false') === false && strpos($text, "'tls://'") !== false
    && strpos(implode('', array_slice(file((new ReflectionMethod('RoboHubRoborock', 'mqttAustausch'))->getFileName()), 0)), 'RoboHubMqttSitzung::oeffnen($host, $port, self::MQTT_WURZEL') !== false);
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
$f = json_encode(json_decode(konsole($rk->GetConfigurationForm()), true), JSON_UNESCAPED_UNICODE);
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
FakeRoborock::$karte = roborockTestKarte();
FakeRoborock::$karteTeile = 3;
$t = $rr->Aktualisieren();
check('Zustand in einer MQTT-Sitzung mit 5 Anfragen, Räume und Karte beim ersten Abruf mitgeladen', count(FakeRoborock::$sitzungen) === 3
    && array_column(FakeRoborock::$rpc, 0) === ['get_status', 'get_consumable', 'get_clean_summary', 'get_dnd_timer', 'get_sound_volume', 'get_room_mapping', 'get_map_v1'], $t);
$rrKachel = kachelJson($rr->GetVisualizationTile());
check('Roborock-Karte (in 3 Teilen, falsche Reihenfolge) auf der Kachel', strpos((string)($rrKachel['karte']['bild'] ?? ''), 'data:image/png;base64,') === 0, json_encode(array_keys((array)($rrKachel['karte'] ?? []))));
[$host, $port, $nutzer, $pw, $abo, $ziel] = FakeRoborock::$sitzungen[0];
check('MQTT-Zugang = Referenz (md5-Ableitung)', $nutzer === '19648f94' && $pw === '234678ea854f199e' && $host === 'mqtt-eu-3.roborock.com' && $port === 8883);
check('Topics rr/m/o bzw. rr/m/i mit duid', $abo === 'rr/m/o/user123/19648f94/abc123' && $ziel === 'rr/m/i/user123/19648f94/abc123');
check('jede Anfrage trägt Endpunkt „87ItGWdb" und 32-stellige Nonce', count(array_filter(FakeRoborock::$rpc, function ($r) { return ($r[2]['endpoint'] ?? '') === '87ItGWdb' && strlen((string)($r[2]['nonce'] ?? '')) === 32; })) === 7);
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
check('Kachel: Saugstufen-Auswahl aus dem Treiber, dazu der Reinigungsmodus (Wischmodul)', in_array([102, 'Ausgewogen'], $kd['startWahl']['saug'] ?? [], true) && in_array([0, 'Saugen'], $kd['startWahl']['modus'] ?? [], true));

FakeRoborock::$antworten['get_sound_volume'] = '__still';
$rr->Aktualisieren();
check('ein Block ohne Antwort: Rest trotzdem übernommen', variable(4100, 'Akku')['value'] === 100);
$alle = FakeRoborock::$antworten;
FakeRoborock::$antworten = array_fill_keys(array_keys($alle), '__still');
$logVorher = count($GLOBALS['LOG']);
$t = $rr->Aktualisieren();
check('Roboter antwortet gar nicht → klare Meldung', strpos($t, 'antwortet nicht') !== false, $t);
// Forum lugge 144636/92: Saros schläft nachts → eine Warnung je Abruf. Jetzt: nur „nicht erreichbar", Instanz bleibt ok.
check('schlafender Roboter: keine Warnung im Protokoll, Instanz bleibt 102, Konto unberührt', count($GLOBALS['LOG']) === $logVorher && $rr->status === 102
    && variable(4100, 'Erreichbar')['value'] === false && $rk->status === 102 && $rk->ReadAttributeString('LetzterFehler') === '', implode(' | ', array_slice($GLOBALS['LOG'], $logVorher)));
$rr->WriteAttributeInteger('OfflineSeit', time() - 86400);
$rr->Aktualisieren();
$rr->Aktualisieren();
$neu = array_slice($GLOBALS['LOG'], $logVorher);
check('nach 24 h am Stück genau eine Warnung', count($neu) === 1 && strpos($neu[0], 'antwortet seit') !== false && end($GLOBALS['LOGSTUFE']) === KL_WARNING, implode(' | ', $neu));
FakeRoborock::$antworten = $alle;
$rr->Aktualisieren();
$neu = array_slice($GLOBALS['LOG'], $logVorher);
check('wieder da: eine Meldung, Merker zurückgesetzt', count($neu) === 2 && strpos($neu[1], 'antwortet wieder') !== false && $rr->ReadAttributeInteger('OfflineSeit') === 0
    && variable(4100, 'Erreichbar')['value'] === true, implode(' | ', $neu));

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

// Forum lugge 144636/98: Reinigungsmodus wie in der App (python-roborock CleaningMode, set_clean_motor_mode).
$rm = variable(4100, 'Reinigungsmodus');
$rmOpt = $rm === null ? [] : array_column(json_decode($rm['pres']['OPTIONS'], true), 'Caption', 'Value');
check('Modus aus den Abrufwerten: Saugstufe 106/Wasser 204 = „Benutzerdefiniert", Auswahl Saugen/Wischen/Saugen und Wischen', $rm !== null && $rm['value'] === 4
    && $rmOpt[0] === 'Saugen' && $rmOpt[1] === 'Wischen' && $rmOpt[2] === 'Saugen und Wischen' && strpos($rmOpt[4], 'Benutzerdefiniert') === 0 && $rm['action'], json_encode($rmOpt));
check('Kachel: Modus-Auswahl beim Raumstart', array_column(kachelJson($rr->GetVisualizationTile())['startWahl']['modus'] ?? [], 0) === [0, 1, 2, 4]);
$rrM = new FakeRoborock();
$nm = function (string $modell, array $p) use ($rrM) { $n = $rrM->normalisiere($modell, $p); return [$n['werte']['Reinigungsmodus'] ?? null, array_keys($n['optionen']['Reinigungsmodus'] ?? []), $n['optionen']['Saugstufe'][105] ?? null]; };
check('Wasser aus = Saugen, Saugstufe 105 + Wasser = Wischen (105 heißt „Aus")', $nm('roborock.vacuum.a307', ['fan_power' => 102, 'water_box_mode' => 200])[0] === 0
    && $nm('roborock.vacuum.a307', ['fan_power' => 105, 'water_box_mode' => 235]) === [1, [0, 1, 2], 'Off'] && $nm('roborock.vacuum.a75', ['fan_power' => 103, 'water_box_mode' => 202])[0] === 2);
check('Modell ohne reines Wischen (S6 = Tanos): kein „Wischen", 105 bleibt „Sanft"', $nm('roborock.vacuum.s6', ['fan_power' => 105, 'water_box_mode' => 202]) === [2, [0, 2], 'Gentle']);
check('ohne Wischmodul (kein water_box_mode): kein Modus', $nm('roborock.vacuum.s5', ['fan_power' => 102])[0] === null);
check('SmartPlan (110/209/306) nur als Anzeige', $nm('roborock.vacuum.a307', ['fan_power' => 110, 'water_box_mode' => 209, 'mop_mode' => 306])[0] === 5);
FakeRoborock::$rpc = [];
$rr->RaeumeReinigenMit('16', 1, 104, 1);
check('Raumstart „Wischen": erst set_clean_motor_mode (Saugen aus, Wasser Standard), keine Saugstufe, dann Raum', array_column(FakeRoborock::$rpc, 0) === ['set_clean_motor_mode', 'app_segment_clean']
    && FakeRoborock::$rpc[0][1] === [['fan_power' => 105, 'water_box_mode' => 202]], json_encode(FakeRoborock::$rpc));
FakeRoborock::$rpc = [];
$rr->RequestAction('Reinigungsmodus', 0);
check('Variable auf „Saugen": Wasser aus, Saugstufe Ausgewogen statt Benutzerdefiniert', array_column(FakeRoborock::$rpc, 0) === ['set_clean_motor_mode']
    && FakeRoborock::$rpc[0][1] === [['fan_power' => 102, 'water_box_mode' => 200]], json_encode(FakeRoborock::$rpc));
$gr = ['id' => 'abc123', 'schluessel' => 'key123key123key1', 'modell' => 'roborock.vacuum.a307', 'protokoll' => '1.0',
    'stand' => ['fan_power' => 103, 'water_box_mode' => 200, 'mop_mode' => 301]];
FakeRoborock::$rpc = [];
FakeRoborock::$antworten['set_clean_motor_mode'] = ['__folge' => [['__fehler' => 'invalid param'], 'ok']];
try {
    $t = $rrM->befehl(json_decode($rk->ReadAttributeString('Buendel'), true), $gr, 'setze', ['feld' => 'Reinigungsmodus', 'wert' => 2]);
} catch (RoboHubFehler $e) {
    $t = '⛔ ' . $e->getMessage();
}
check('aus „Saugen" zu „Saugen und Wischen": Wasser 202, abgelehnt → einmal 235 (Wasser-Regler), Saugstufe und Route bleiben', array_column(FakeRoborock::$rpc, 1) === [
    [['fan_power' => 103, 'water_box_mode' => 202, 'mop_mode' => 301]], [['fan_power' => 103, 'water_box_mode' => 235, 'mop_mode' => 301]]], json_encode(FakeRoborock::$rpc) . ' ' . $t);
unset(FakeRoborock::$antworten['set_clean_motor_mode']);
$gr['modell'] = 'roborock.vacuum.s6';
$fehler = '';
try {
    $rrM->befehl(json_decode($rk->ReadAttributeString('Buendel'), true), $gr, 'setze', ['feld' => 'Reinigungsmodus', 'wert' => 1]);
} catch (RoboHubFehler $e) {
    $fehler = $e->getMessage();
}
check('„Wischen" bei Modell ohne reines Wischen → klare Absage', stripos($fehler, 'wischen') !== false, $fehler);
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
FakeRoborock::$rpc = [];
$t = $rr->VerbrauchsteilZuruecksetzen('Waschwanne');
check('Waschwanne ohne Meldung des Geräts → nicht zurücksetzbar, nichts gesendet', FakeRoborock::$rpc === [] && strpos($t, '⛔') === 0, $t);
check('ohne Option keine Rücksetz-Variable', variable(4100, 'TeilZuruecksetzen') === null);
FakeRoborock::$antworten['get_consumable'][0] += ['strainer_work_times' => 75, 'cleaning_brush_work_times' => 30];
$rr->Aktualisieren();
check('Stationsteile: Waschwanne 50 % (150), Reinigungsbürste 90 % (300), keine Reststunden', variable(4100, 'Waschwanne')['value'] === 50
    && variable(4100, 'Reinigungsbuerste')['value'] === 90 && variable(4100, 'WaschwanneRest') === null && variable(4100, 'ReinigungsbuersteRest') === null);
$rr->props['TeilVariable'] = true;
$rr->ApplyChanges();
$tv = variable(4100, 'TeilZuruecksetzen');
$tvOpt = array_column((array)json_decode((string)($tv['pres']['OPTIONS'] ?? ''), true), 'Caption', 'Value');
check('Option an: Variable „Verbrauchsteil zurücksetzen" mit den Teilen des Geräts, schaltbar', $tv !== null && $tv['action'] && $tv['name'] === 'Verbrauchsteil zurücksetzen'
    && ($tvOpt[11] ?? '') === 'Waschwanne (Sieb der Station)' && ($tvOpt[1] ?? '') === 'Hauptbürste' && !isset($tvOpt[8]), json_encode($tvOpt, JSON_UNESCAPED_UNICODE));
FakeRoborock::$rpc = [];
$rr->RequestAction('TeilZuruecksetzen', 11);
check('Waschwanne über die Variable = reset_consumable strainer_work_times', (FakeRoborock::$rpc[0][0] ?? '') === 'reset_consumable' && FakeRoborock::$rpc[0][1] === ['strainer_work_times']);
FakeRoborock::$rpc = [];
$rr->RequestAction('TeilZuruecksetzen', 9);
check('Reinigungsbürste = reset_consumable cleaning_brush_work_times', (FakeRoborock::$rpc[0][1] ?? null) === ['cleaning_brush_work_times']);
$rr->Aktualisieren();
check('Rücksetz-Variable nicht bei jedem Abruf neu angelegt', count(array_filter($GLOBALS['OBJ'], function ($o) { return ($o['ident'] ?? '') === 'TeilZuruecksetzen' && ($o['parent'] ?? 0) === 4100; })) === 1);
$rr->props['TeilVariable'] = false;
$rr->ApplyChanges();
check('Option aus: Variable entfernt', variable(4100, 'TeilZuruecksetzen') === null);
FakeRoborock::$antworten['get_consumable'] = $alle['get_consumable'] ?? $fx('get_consumeables');
FakeRoborock::$rpc = [];
$rr->props['KarteTeppich'] = true;
$rr->ApplyChanges();
$rr->Aktualisieren();
check('Teppich-Option geändert → Karte beim nächsten Abruf neu geholt', in_array('get_map_v1', array_column(FakeRoborock::$rpc, 0), true));
FakeRoborock::$rpc = [];
$rr->ApplyChanges();
$rr->Aktualisieren();
check('… danach nicht bei jedem Übernehmen erneut', !in_array('get_map_v1', array_column(FakeRoborock::$rpc, 0), true));
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

// ===========================================================================
abschnitt('Roborock: Station (dss) und Verbindung (Forum lugge 13.11.2026)');
$rrt = new RoboHubRoborock();
$st = $rrt->normalisiere('roborock.vacuum.a307', ['dss' => 2216])['werte'] ?? $rrt->normalisiere('roborock.vacuum.a307', ['dss' => 2216]);
check('Saros 20 dss 2216 (Felder = 2) → alles OK', ($st['Frischwasser'] ?? '') === RoboHubText::t('OK') && ($st['Schmutzwasser'] ?? '') === RoboHubText::t('OK') && ($st['Staubbeutel'] ?? '') === RoboHubText::t('OK'), json_encode($st, JSON_UNESCAPED_UNICODE));
$st = $rrt->normalisiere('roborock.vacuum.a307', ['dss' => (1 << 2) | (1 << 4) | (1 << 6) | 2])['werte'] ?? $rrt->normalisiere('roborock.vacuum.a307', ['dss' => (1 << 2) | (1 << 4) | (1 << 6) | 2]);
check('Wert 1 meldet leer/voll', ($st['Frischwasser'] ?? '') === RoboHubText::t('Empty or missing') && ($st['Schmutzwasser'] ?? '') === RoboHubText::t('Full or missing') && ($st['Staubbeutel'] ?? '') === RoboHubText::t('Full or missing'), json_encode($st, JSON_UNESCAPED_UNICODE));

// Konsole hat beim Neuanlegen (noch „Dreame" voreingestellt) einen Client Socket mit Dreames Wurzel angelegt.
$alt = new FakeSocket(4900);
$alt->config = ['Open' => true, 'Host' => 'app.mt.eu.iot.dreame.tech', 'Port' => 19973, 'CertificateAuthority' => base64_encode(RoboHubDreame::MQTT_WURZEL)];
IPS_SetName(4900, 'Client Socket');
$rk->parent = $alt;
$GLOBALS['GELOESCHT'] = [];
$rk->VerbindungPruefen();
check('Roborock-Konto: von der Konsole angelegter Dreame-Socket wird gegen Virtual IO getauscht', in_array(4900, $GLOBALS['GELOESCHT'], true)
    && $rk->parent instanceof FakeSocket && $rk->parent->modul === '{6179ED6A-FC31-413C-BB8E-1204150CF376}', json_encode($GLOBALS['GELOESCHT']));
$geteilt = new FakeSocket(4901);
$geteilt->config = $alt->config;
IPS_SetName(4901, 'Client Socket');
$nachbar = new FakeSocket(4902);
$nachbar->parent = $geteilt;
$rk->parent = $geteilt;
$GLOBALS['GELOESCHT'] = [];
$rk->VerbindungPruefen();
check('Socket, an dem noch eine andere Instanz hängt, bleibt unangetastet', $GLOBALS['GELOESCHT'] === [] && $rk->parent === $geteilt);
$fremd = new FakeSocket(4903);
$fremd->config = ['Open' => true, 'Host' => 'mein.server', 'Port' => 1883];
IPS_SetName(4903, 'Client Socket');
$rk->parent = $fremd;
$GLOBALS['GELOESCHT'] = [];
$rk->VerbindungPruefen();
check('fremder Client Socket ohne RoboHub-Merkmal bleibt unangetastet', $GLOBALS['GELOESCHT'] === [] && $rk->parent === $fremd);

// Forum baraka 144636/89: Qrevo meldet charge_status 1 auch beim Putzen — „lädt“ nur im Zustand 8.
check('Reinigt (5) mit charge_status 1 = lädt nicht', ($rrt->normalisiere('roborock.vacuum.a75', ['state' => 5, 'charge_status' => 1])['werte']['Laedt'] ?? null) === false);
check('Lädt (8) = lädt, Geladen (100) = lädt nicht', ($rrt->normalisiere('roborock.vacuum.a75', ['state' => 8])['werte']['Laedt'] ?? null) === true
    && ($rrt->normalisiere('roborock.vacuum.a75', ['state' => 100, 'charge_status' => 1])['werte']['Laedt'] ?? null) === false);

// Saros 20 (Forum lugge 14.11.2026): Wasserfluss-Regler 220 + Stellung, App „Mittel 15“ = 235.
$n = $rrt->normalisiere('roborock.vacuum.a307', ['water_box_mode' => 235]);
$opt = $n['optionen']['Wassermenge'] ?? [];
check('Wasserfluss-Regler 235 = „Medium“, Auswahl der App-Bereiche', ($n['werte']['Wassermenge'] ?? null) === 235 && ($opt[235] ?? '') === 'Medium'
    && ($opt[200] ?? '') === 'Off' && ($opt[250] ?? '') === 'Extreme' && !isset($opt[201]), json_encode($n['optionen'] ?? null));
$n = $rrt->normalisiere('roborock.vacuum.a307', ['water_box_mode' => 233]);
check('Zwischenstellung 13 bekommt den Bereichsnamen', (($n['optionen']['Wassermenge'] ?? [])[233] ?? '') === 'Medium');
check('Bereichsgrenzen wie python-roborock (3/7/12/17/22/27/30)', RoboHubRoborock::wasserreglerName(223) === 'Slight' && RoboHubRoborock::wasserreglerName(224) === 'Low'
    && RoboHubRoborock::wasserreglerName(237) === 'Medium' && RoboHubRoborock::wasserreglerName(238) === 'Moderate' && RoboHubRoborock::wasserreglerName(250) === 'Extreme'
    && RoboHubRoborock::wasserreglerName(251) === null && RoboHubRoborock::wasserreglerName(220) === null);
$n = $rrt->normalisiere('roborock.vacuum.a27', ['water_box_mode' => 202]);
check('alte Wasserstufen unverändert', (($n['optionen']['Wassermenge'] ?? [])[202] ?? '') === 'Medium' && isset($n['optionen']['Wassermenge'][201]));

// Konsole: passende Verbindungsart je Hersteller (SUITE.md Stolperstein 28), sonst „inkompatible übergeordnete Instanz“.
check('Roborock-Konto nennt der Konsole das Virtual IO als passende Verbindung', json_decode($rk->GetCompatibleParents(), true) === ['type' => 'require', 'moduleIDs' => ['{6179ED6A-FC31-413C-BB8E-1204150CF376}']]);
$dk = new RoboHubKonto(4950);
$dk->Create();
check('Dreame-Konto nennt den Client Socket', json_decode($dk->GetCompatibleParents(), true)['moduleIDs'] === ['{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}']);
