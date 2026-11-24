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
    public static $programme = null;  // App-Routinen (result von /user/scene/device/…); null = Endpunkt fehlt
    public static $gestartet = [];    // ausgeführte Routinen-IDs
    public static $q7 = [];           // Q7 (B01): gesendete [methode, params, rohe Nutzlast]
    public static $q7Antworten = [];  // Q7: methode => data (oder ['__code' => n], oder '__still')
    public static $q7Karte = null;    // Q7: entpackte SCMap für service.upload_by_mapid (null = keine Antwort)
    public static $q7KarteTeile = 3;

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
        if (preg_match('#/user/scene/(device/(\w+)|(\d+)/execute)$#', $url, $sm) && self::$programme !== null) {
            $pfad = (string)parse_url($url, PHP_URL_PATH);
            $kopf = implode("\n", $headers);
            if (!preg_match('/Authorization: Hawk id="user123",s="pass123",ts="(\d+)",nonce="([^"]+)"/', $kopf, $m)
                || RoboHubRoborock::hawkKopf(self::RRIOT, $pfad, (int)$m[1], $m[2]) !== substr($kopf, strpos($kopf, 'Hawk '))) {
                return [401, '{"success":false}'];
            }
            if (($sm[3] ?? '') !== '') {
                if ($methode !== 'POST') {
                    return [405, '{}'];
                }
                self::$gestartet[] = (int)$sm[3];
                return [200, json_encode(['success' => true, 'result' => null])];
            }
            return [200, json_encode(['success' => true, 'result' => $sm[2] === 'abc123' ? self::$programme : []])];
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
            if (substr($r, 0, 3) === 'B01') {
                // Q7 (python-roborock b01_q7_protocol.py / b01_q7_channel.py): Anfrage dps 10000 als Objekt,
                // Antwort dps 10001 als JSON-Text {msgId, code, method, data}.
                foreach (RoboHubRoborock::rahmenLesen($r, 'xyzxyzxyzxyzxyz1') as $n) {
                    $j = json_decode(RoboHubRoborockQ7::entpolstern($n['nutzlast']), true);
                    $a = $j['dps']['10000'] ?? [];
                    self::$q7[] = [$a['method'] ?? null, $a['params'] ?? null, $n['nutzlast']];
                    if (($a['method'] ?? '') === 'service.upload_by_mapid' && self::$q7Karte !== null) {
                        // python-roborock: Base64(AES-ECB(Kartenschlüssel, Hex(zlib(SCMap)))), Teile 300 … 301 mit gleichem random/timestamp.
                        $schl = RoboHubRoborockQ7Karte::schluessel('testsn012345', 'roborock.vacuum.sc01');
                        $text = base64_encode(openssl_encrypt(bin2hex(gzcompress(self::$q7Karte)), 'aes-128-ecb', $schl, OPENSSL_RAW_DATA));
                        $teile = str_split($text, (int)ceil(strlen($text) / self::$q7KarteTeile));
                        foreach (array_reverse(array_keys($teile)) as $i) {   // absichtlich in falscher Reihenfolge
                            $prot = $i === count($teile) - 1 ? 301 : 300;
                            if ($verarbeite(RoboHubRoborock::rahmenBauen($teile[$i], 'xyzxyzxyzxyzxyz1', $i + 1, 5555, 1700000000, $prot, 'B01'))) {
                                return;
                            }
                        }
                        continue;
                    }
                    $antwort = self::$q7Antworten[$a['method'] ?? ''] ?? 0;
                    if ($antwort === '__still') {
                        continue;
                    }
                    $innen = ['msgId' => $a['msgId'], 'code' => is_array($antwort) && isset($antwort['__code']) ? $antwort['__code'] : 0,
                        'method' => $a['method'], 'data' => is_array($antwort) && isset($antwort['__code']) ? null : $antwort];
                    // Fremde Antwort vorweg (andere msgId) — muss übergangen werden.
                    $verarbeite(RoboHubRoborock::rahmenBauen(RoboHubRoborockQ7::polstern(json_encode(['t' => time(), 'dps' => ['10001' => json_encode(['msgId' => '1', 'code' => 0, 'data' => ['status' => 99]])]])), 'xyzxyzxyzxyzxyz1', 3, 77, time(), 102, 'B01'));
                    if ($verarbeite(RoboHubRoborock::rahmenBauen(RoboHubRoborockQ7::polstern(json_encode(['t' => time(), 'dps' => ['10001' => json_encode($innen)]])), 'xyzxyzxyzxyzxyz1', 4, 4321, time(), 102, 'B01'))) {
                        return;
                    }
                }
                continue;
            }
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

$c4 = RoboHubRoborock::connackFehler(4);
$c5 = RoboHubRoborock::connackFehler(5);
$c3 = RoboHubRoborock::connackFehler(3);
check('MQTT abgewiesen (4/5) = Drosselung: 15 min Pause, KEINE Abmeldung (Forum alsk1 144636/125)', !$c4->anmeldungNoetig && !$c5->anmeldungNoetig
    && $c4->warteSekunden === 900 && $c5->warteSekunden === 900 && strpos($c4->getMessage(), 'keine neue Anmeldung') === false && strpos($c4->getMessage(), 'nicht nötig') !== false, $c4->getMessage());
check('andere Codes: weder Pause noch Abmeldung', !$c3->anmeldungNoetig && $c3->warteSekunden === 0);

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
    ['duid' => 'q7neu', 'name' => 'Q7', 'localKey' => 'xyzxyzxyzxyzxyz1', 'productId' => 'p-q7', 'online' => true, 'pv' => 'B01', 'sn' => 'testsn012345'],
];
FakeRoborock::$raeume = [['id' => 3031886, 'name' => 'Küche'], ['id' => 3031880, 'name' => 'Flur'], ['id' => 3031883, 'name' => 'Bad']];
$rk = new RoboHubKonto(4000);
$rk->props['Hersteller'] = 'roborock';
$rk->Create();
$rk->ApplyChanges();
$f = json_encode(json_decode(konsole($rk->GetConfigurationForm()), true), JSON_UNESCAPED_UNICODE);
check('Formular: Code-Knopf und Code-Feld bei Roborock', strpos($f, 'ROBOHUB_CodeAnfordern') !== false && strpos($f, 'Code aus der E-Mail') !== false);
check('Formular: seit 0.50.0 nicht mehr „keine Echtzeit für Roborock"', strpos($f, 'Für Roborock gibt es noch keine Echtzeit-Meldungen') === false);
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
check('ohne Routinen-Endpunkt: keine Variable „Programm starten", Räume trotzdem da', variable(4100, 'Programm') === null && $rr->ReadAttributeString('Programme') === '{}');

// Forum lugge 144636/155: Programme (App-Routinen) aus der Cloud, Start per Hawk-POST.
FakeRoborock::$programme = [['id' => 9001, 'name' => 'Erst saugen, dann wischen', 'enabled' => true], ['id' => 9002, 'name' => ''], ['id' => 0, 'name' => 'kaputt']];
$t = $rr->RaeumeLaden();
$pv = variable(4100, 'Programm');
$pOpt = $pv === null ? [] : array_column(json_decode($pv['pres']['OPTIONS'], true), 'Caption', 'Value');
check('Programme: Variable mit Namen, leerer Name → „Routine n", ID 0 übergangen', $pOpt === [9001 => 'Erst saugen, dann wischen', 9002 => 'Routine 9002'] && $pv['action'], json_encode($pOpt) . ' ' . $t);
$kp = kachelJson($rr->GetVisualizationTile())['programme'] ?? [];
check('Kachel: Programme als Liste', array_column($kp, 'id') === [9001, 9002]);
FakeRoborock::$rpc = [];
$t = $rr->ProgrammStarten(9001);
check('Programm starten: Hawk-POST auf die Routine, kein MQTT', FakeRoborock::$gestartet === [9001] && FakeRoborock::$rpc === [] && strpos($t, 'Erst saugen, dann wischen') !== false, $t);
$t = $rr->ProgrammStarten(4242);
check('unbekannte Programm-ID: nichts gesendet', FakeRoborock::$gestartet === [9001] && strpos($t, '⛔') === 0, $t);
$rr->RequestAction('Programm', 9002);
check('Variable „Programm starten" startet', FakeRoborock::$gestartet === [9001, 9002]);
FakeRoborock::$programme = [];
$rr->RaeumeLaden();
check('Programme in der App gelöscht: Variable bleibt (Verknüpfungen), Liste leer', variable(4100, 'Programm') !== null && $rr->ReadAttributeString('Programme') === '[]'
    && strpos($rr->ProgrammStarten(9001), '⛔') === 0 && FakeRoborock::$gestartet === [9001, 9002]);
FakeRoborock::$programme = null;
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
// 0.54.0: Q7 (B01, Modell sc…) — python-roborock (Apache-2.0) b01_q7_*; Felder wie testdata/b01_q7_protocol/get_prop.json.
FakeRoborock::$q7 = [];
FakeRoborock::$q7Antworten = ['prop.get' => ['status' => 5, 'fault' => 0, 'wind' => 3, 'water' => 2, 'mode' => 1, 'quantity' => 81, 'volume' => 60,
    'main_brush' => 4088, 'side_brush' => 12000, 'hypa' => 900, 'mop_life' => 0, 'main_sensor' => 1800]];
$t = $rq->Aktualisieren();
$q7a = FakeRoborock::$q7[0] ?? [null, null, ''];
check('Q7: Abruf beginnt mit prop.get und Feldliste (B01-Rahmen, dps 10000); danach lädt der Roboter einmal die Räume', count(FakeRoborock::$sitzungen) >= 1 && $q7a[0] === 'prop.get'
    && ($q7a[1]['property'] ?? null) === RoboHubRoborockQ7::ABFRAGE, $t . ' ' . json_encode($q7a[1]));
check('Q7: Inhalt doppelt gepolstert wie python-roborock (encode_mqtt_payload + _encode)', strlen($q7a[2]) % 16 === 0
    && RoboHubRoborockQ7::entpolstern($q7a[2]) !== $q7a[2] && json_decode(RoboHubRoborockQ7::entpolstern($q7a[2]), true) !== null, bin2hex(substr($q7a[2], -20)));
check('Q7: Zustand 5 = reinigt, Akku aus quantity, Fehler 0', variable(4200, 'Zustand')['value'] === RoboHubModell::ZUSTAND_REINIGT
    && variable(4200, 'Akku')['value'] === 81 && variable(4200, 'Fehler')['value'] === 'kein Fehler' && strpos(variable(4200, 'ZustandRoh')['value'], '(5)') !== false);
check('Q7: Saugstufe 3 Turbo, Wassermenge 2, Modus vac_and_mop (1) = Saugen und Wischen (2)', variable(4200, 'Saugstufe')['value'] === 3
    && variable(4200, 'Wassermenge')['value'] === 2 && variable(4200, 'Reinigungsmodus')['value'] === 2);
check('Q7: Verbrauchsteile aus verbrauchten Minuten (Hauptbürste 300 h, Seitenbürste 200 h, Filter 150 h, Mopp 180 h, Sensoren 30 h)',
    variable(4200, 'Hauptbuerste')['value'] === 77 && variable(4200, 'HauptbuersteRest')['value'] === 232.0 && variable(4200, 'Seitenbuerste')['value'] === 0
    && variable(4200, 'Filter')['value'] === 90 && variable(4200, 'Mopp')['value'] === 100 && variable(4200, 'Sensoren')['value'] === 0, json_encode([variable(4200, 'Hauptbuerste'), variable(4200, 'HauptbuersteRest')]));
FakeRoborock::$q7 = [];
$rq->Anhalten();
$rq->ZurStation();
$rq->Orten();
$rq->Starten();
$rq->Stoppen();
check('Q7: Befehle wie python-roborock (set_room_clean ctrl 2/1/0, start_recharge, find_device)', array_map(function ($a) { return [$a[0], $a[1]]; }, FakeRoborock::$q7) === [
    ['service.set_room_clean', ['clean_type' => 0, 'ctrl_value' => 2, 'room_ids' => []]],
    ['service.start_recharge', []],
    ['service.find_device', []],
    ['service.set_room_clean', ['clean_type' => 0, 'ctrl_value' => 1, 'room_ids' => []]],
    ['service.set_room_clean', ['clean_type' => 0, 'ctrl_value' => 0, 'room_ids' => []]],
], json_encode(FakeRoborock::$q7));
check('Q7: leere Parameter als Objekt {} (wie params={} in python-roborock)', strpos(RoboHubRoborockQ7::anfrageNutzlast('1', 'service.find_device', new stdClass()), '"params":{}') !== false);
FakeRoborock::$q7 = [];
$rq->RequestAction('Saugstufe', 4);
$rq->RequestAction('Reinigungsmodus', 1);
$rq->RequestAction('Lautstaerke', 40);
check('Q7: Einstellungen per prop.set (wind 4, mode 2 = Wischen, volume 40)', array_map(function ($a) { return [$a[0], $a[1]]; }, FakeRoborock::$q7) === [
    ['prop.set', ['wind' => 4]], ['prop.set', ['mode' => 2]], ['prop.set', ['volume' => 40]],
], json_encode(FakeRoborock::$q7));
FakeRoborock::$q7Antworten['service.find_device'] = ['__code' => 1];
$t = $rq->Orten();
check('Q7: Ablehnung (code ≠ 0) wird gemeldet', strpos($t, 'Code 1') !== false, $t);
FakeRoborock::$q7Antworten['prop.get'] = ['status' => 1, 'fault' => 522, 'quantity' => 50];
$rq->Aktualisieren();
check('Q7: Fehler 522 „Mopp nicht eingesetzt“ → Zustand Fehler', variable(4200, 'Zustand')['value'] === RoboHubModell::ZUSTAND_FEHLER
    && strpos(variable(4200, 'Fehler')['value'], 'Mopp nicht eingesetzt (522)') === 0, variable(4200, 'Fehler')['value']);
FakeRoborock::$q7Antworten = [];

// 0.55.0: Q7-Räume und Karte — echte Aufnahme aus python-roborock (tests/map/testdata, Apache-2.0, siehe .tools/roborock/HERKUNFT.md).
check('Q7-Kartenschlüssel wie python-roborock create_map_key (unabhängig mit openssl-CLI nachgerechnet)',
    RoboHubRoborockQ7Karte::schluessel('testsn012345', 'roborock.vacuum.sc05') === 'ca9c6c9e6ba63f6e');
$q7scmap = gzdecode((string)file_get_contents(__DIR__ . '/roborock/q7-karte-sc05.bin.gz'));
$q7k = RoboHubRoborockQ7Karte::lesen($q7scmap);
$erwartet = [];
for ($i = 10; $i <= 19; $i++) {
    $erwartet[$i] = 'room' . ($i - 9);
}
check('Q7-Karte (echte Aufnahme): 340 × 300, Räume 10–19 „room1“…„room10“ wie test_b01_map_parser.py', ($q7k['raeume'] ?? null) === $erwartet
    && $q7k['roh']['breite'] === 340 && $q7k['roh']['hoehe'] === 300 && $q7k['roh']['raster'] === 50, json_encode($q7k['raeume'] ?? null));
check('Q7-Karte: alle zehn Räume per Flutfüllung im Bild, Station und Weg gelesen', $q7k['roh']['raeume'] === range(10, 19)
    && $q7k['roh']['station'] !== null && count($q7k['roh']['weg'][0] ?? []) > 100);
check('Q7-Protobuf: kaputte Daten → null statt Absturz', RoboHubRoborockQ7Karte::lesen("\xff\xff\xff") === null && RoboHubRoborockQ7Karte::protobuf("\x0a\x05ab") === null);
FakeRoborock::$q7 = [];
FakeRoborock::$q7Karte = $q7scmap;
FakeRoborock::$q7Antworten['service.get_map_list'] = ['map_list' => [['id' => 7, 'cur' => false], ['id' => 1234, 'cur' => true]]];
$t = $rq->RaeumeLaden();
$q7Methoden = array_column(FakeRoborock::$q7, 0);
check('Q7: Räume laden = get_map_list, dann upload_by_mapid mit der aktuellen Karte (cur)', $q7Methoden === ['service.get_map_list', 'service.upload_by_mapid']
    && (FakeRoborock::$q7[1][1]['map_id'] ?? null) === 1234, $t . ' ' . json_encode(array_map(function ($a) { return [$a[0], $a[1]]; }, FakeRoborock::$q7)));
$rqRaeume = json_decode($rq->ReadAttributeString('Raeume'), true) ?: [];
check('Q7: Raumnamen aus der Karte im Roboter (Teile in falscher Reihenfolge zusammengesetzt)', count($rqRaeume) === 10 && ($rqRaeume['10'] ?? '') === 'room1', json_encode($rqRaeume));
$rqKarte = json_decode($rq->ReadAttributeString('Karte'), true) ?: [];
check('Q7: Kartenbild (PNG) mit Raummarken und Station', strpos((string)($rqKarte['bild'] ?? ''), 'data:image/png;base64,') === 0 && count($rqKarte['marken'] ?? []) === 10
    && ($rqKarte['station'] ?? null) !== null, substr(json_encode($rqKarte), 0, 200));
FakeRoborock::$q7 = [];
$rq->RaeumeReinigen('12,15');
check('Q7: Raumreinigung = set_room_clean clean_type 1, ctrl 1, room_ids (clean_segments)', array_map(function ($a) { return [$a[0], $a[1]]; }, FakeRoborock::$q7) === [
    ['service.set_room_clean', ['clean_type' => 1, 'ctrl_value' => 1, 'room_ids' => [12, 15]]],
], json_encode(FakeRoborock::$q7));
FakeRoborock::$q7KarteTeile = 1;
$offenTest = [];
$einzeln = RoboHubRoborockQ7Karte::teilAufnehmen($offenTest, ['version' => 'B01', 'protokoll' => 301, 'zufall' => 1, 'zeit' => 2, 'seq' => 12750, 'nutzlast' => base64_encode(str_repeat('x', 32))]);
$bruch = RoboHubRoborockQ7Karte::teilAufnehmen($offenTest, ['version' => 'B01', 'protokoll' => 301, 'zufall' => 3, 'zeit' => 4, 'seq' => 2, 'nutzlast' => base64_encode(str_repeat('y', 7))]);
check('Q7-Kartenteile: einzelnes 301 ganz durchgereicht, Bruchstück (nicht 16er-Block) wartet auf den Rest', $einzeln !== null && $bruch === null && isset($offenTest['3-4']));
FakeRoborock::$q7Karte = null;
FakeRoborock::$q7Antworten = [];
check('Q10 (B01, Modell ss…): ehrlich „nur Q7“', !RoboHubRoborockQ7::istQ7(['protokoll' => 'B01', 'modell' => 'roborock.vacuum.ss07'])
    && RoboHubRoborockQ7::istQ7(['protokoll' => 'B01', 'modell' => 'roborock.vacuum.sc05']) && !RoboHubRoborockQ7::istQ7(['protokoll' => '1.0', 'modell' => 'roborock.vacuum.sc05']));
// B01-Rahmen unabhängig nachgerechnet: AES-128-CBC, Schlüssel = localKey, IV = md5hex(%08x(random) + B01_HASH)[9:25].
$b01 = RoboHubRoborock::rahmenBauen('{"x":1}', 'xyzxyzxyzxyzxyz1', 7, 97431, 1652547161, 101, 'B01');
$ivTest = substr(md5('00017c97' . '5wwh9ikChRjASpMU8cxg7o1d2E'), 9, 16);
$klar = openssl_decrypt(substr($b01, 19, unpack('n', substr($b01, 17, 2))[1]), 'aes-128-cbc', 'xyzxyzxyzxyzxyz1', OPENSSL_RAW_DATA, $ivTest);
check('B01-Rahmen: Kopf „B01“, CBC mit IV aus random, Prüfsumme stimmt, rahmenLesen liest zurück', substr($b01, 0, 3) === 'B01' && $klar === '{"x":1}'
    && unpack('N', substr($b01, -4))[1] === crc32(substr($b01, 0, -4)) && (RoboHubRoborock::rahmenLesen($b01, 'xyzxyzxyzxyzxyz1')[0]['nutzlast'] ?? '') === '{"x":1}');
check('1.0-Rahmen unverändert (ECB, Version 1.0)', substr(RoboHubRoborock::rahmenBauen('{"x":1}', 'key123key123key1', 1, 2, 3, 101), 0, 3) === '1.0');

// ===========================================================================
abschnitt('Roborock: Station (dss) und Verbindung (Forum lugge 13.11.2026)');
$rrt = new RoboHubRoborock();
$st = $rrt->normalisiere('roborock.vacuum.a307', ['dss' => 2216])['werte'] ?? $rrt->normalisiere('roborock.vacuum.a307', ['dss' => 2216]);
check('Saros 20 dss 2216 (Felder = 2) → alles OK', ($st['Frischwasser'] ?? '') === RoboHubText::t('OK') && ($st['Schmutzwasser'] ?? '') === RoboHubText::t('OK') && ($st['Staubbeutel'] ?? '') === RoboHubText::t('OK'), json_encode($st, JSON_UNESCAPED_UNICODE));
$st = $rrt->normalisiere('roborock.vacuum.a307', ['dss' => (1 << 2) | (1 << 4) | (1 << 6) | 2])['werte'] ?? $rrt->normalisiere('roborock.vacuum.a307', ['dss' => (1 << 2) | (1 << 4) | (1 << 6) | 2]);
check('Wert 1 meldet leer/voll', ($st['Frischwasser'] ?? '') === RoboHubText::t('Empty or missing') && ($st['Schmutzwasser'] ?? '') === RoboHubText::t('Full or missing') && ($st['Staubbeutel'] ?? '') === RoboHubText::t('Full or missing'), json_encode($st, JSON_UNESCAPED_UNICODE));

// Forum alsk1 144636/146: von der Konsole angelegter Virtual IO („Virtual IO") unter einem Roborock-Konto → seit 0.50.0
// braucht Roborock einen Client Socket; der leere Virtual IO wird ausgetauscht (vorher: „inkompatible übergeordnete Instanz").
$vio = new FakeSocket(4895);
$vio->modul = '{6179ED6A-FC31-413C-BB8E-1204150CF376}';
IPS_SetName(4895, 'Virtual IO');
$rkVorher = $rk->parent;
$rk->parent = $vio;
$GLOBALS['GELOESCHT'] = [];
$rk->VerbindungPruefen();
check('Roborock-Konto: von der Konsole angelegter Virtual IO wird gegen einen Client Socket getauscht', in_array(4895, $GLOBALS['GELOESCHT'], true)
    && $rk->parent instanceof FakeSocket && $rk->parent->modul === '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}', json_encode($GLOBALS['GELOESCHT']));
$vio2 = new FakeSocket(4896);
$vio2->modul = '{6179ED6A-FC31-413C-BB8E-1204150CF376}';
IPS_SetName(4896, 'Virtual IO');
$nachbarVio = new FakeSocket(4897);
$nachbarVio->parent = $vio2;
$rk->parent = $vio2;
$GLOBALS['GELOESCHT'] = [];
$rk->VerbindungPruefen();
check('… aber nie, wenn noch eine andere Instanz an diesem Virtual IO hängt', $GLOBALS['GELOESCHT'] === [] && $rk->parent === $vio2);
$rk->parent = $rkVorher;

// Konsole hat beim Neuanlegen (noch „Dreame" voreingestellt) einen Client Socket mit Dreames Wurzel angelegt.
$alt = new FakeSocket(4900);
$alt->config = ['Open' => true, 'Host' => 'app.mt.eu.iot.dreame.tech', 'Port' => 19973, 'CertificateAuthority' => base64_encode(RoboHubDreame::MQTT_WURZEL)];
IPS_SetName(4900, 'Client Socket');
$rk->parent = $alt;
$GLOBALS['GELOESCHT'] = [];
$GLOBALS['SKRIPTE'] = [];
$rk->VerbindungPruefen();
// Seit 0.50.0 braucht Roborock einen Client Socket (dauerhafte Verbindung): er bleibt, bekommt aber Roborocks Wurzel.
check('Roborock-Konto: von der Konsole angelegter Socket mit Dreames Wurzel bleibt, bekommt aber Roborocks Wurzel und Server', $GLOBALS['GELOESCHT'] === []
    && $rk->parent === $alt && strpos(implode("\n", $GLOBALS['SKRIPTE']), base64_encode((new RoboHubRoborock())->mqttWurzel())) !== false
    && strpos(implode("\n", $GLOBALS['SKRIPTE']), 'mqtt-eu-3.roborock.com') !== false, json_encode([$GLOBALS['GELOESCHT'], $GLOBALS['SKRIPTE']]));
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
$n = $rrt->normalisiere('roborock.vacuum.a307', ['water_box_mode' => 210]);
check('Smart-Wasser 210 (Forum lugge 144636/160, während einer Routine) mit Namen statt „Stufe 210“', (($n['optionen']['Wassermenge'] ?? [])[210] ?? '') === 'Smart water'
    && ($n['werte']['Wassermenge'] ?? null) === 210, json_encode($n['optionen']['Wassermenge'] ?? null));
check('alte Wasserstufen unverändert', (($n['optionen']['Wassermenge'] ?? [])[202] ?? '') === 'Medium' && isset($n['optionen']['Wassermenge'][201]));

// Konsole: passende Verbindungsart je Hersteller (SUITE.md Stolperstein 28), sonst „inkompatible übergeordnete Instanz“.
check('Roborock-Konto nennt der Konsole den Client Socket als passende Verbindung (dauerhafte Verbindung)', json_decode($rk->GetCompatibleParents(), true) === ['type' => 'require', 'moduleIDs' => ['{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}']]);
$dk = new RoboHubKonto(4950);
$dk->Create();
check('Dreame-Konto nennt den Client Socket', json_decode($dk->GetCompatibleParents(), true)['moduleIDs'] === ['{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}']);

// ===========================================================================
abschnitt('Roborock: dauerhafte Verbindung des Kontos (0.50.0)');

/** Nachgebauter Roborock-MQTT-Server hinter dem Client Socket: beantwortet Anfragen sofort (über FakeRoborock). */
class FakeRoborockServer extends FakeSocket
{
    public $konto;
    public $veroeffentlicht = [];   // Themen der PUBLISH vom Konto
    public $connack = 0;
    public $anmeldungen = 0;
    public $stumm = false;   // Roborock hält die Verbindung, liefert aber nichts aus (Forum lugge 144636/179)
    public function ForwardData(string $json): string
    {
        parent::ForwardData($json);
        $bytes = end($this->gesendet);
        foreach (RoboHubMqtt::zerlegen($bytes) as $p) {
            if ($p['typ'] === RoboHubMqtt::CONNECT) {
                $this->anmeldungen++;
                mqttVomServer($this->konto, "\x20\x02\x00" . chr($this->connack));
            } elseif ($p['typ'] === RoboHubMqtt::PUBLISH) {
                $pub = RoboHubMqtt::publishLesen($p['flags'], $p['body']);
                $this->veroeffentlicht[] = $pub['topic'];
                if ($this->stumm) {
                    continue;
                }
                $aus = str_replace('rr/m/i/', 'rr/m/o/', $pub['topic']);
                $fake = new FakeRoborock();
                $m = new ReflectionMethod('FakeRoborock', 'mqttAustausch');
                $konto = $this->konto;
                $m->invoke($fake, '', 0, '', '', $aus, $pub['topic'], [$pub['payload']], function (string $daten) use ($konto, $aus): bool {
                    mqttVomServer($konto, RoboHubMqtt::publish($aus, $daten));
                    return false;
                }, 1.0);
                array_pop(FakeRoborock::$sitzungen);   // das war der Server, keine Sitzung des Treibers
            }
        }
        return '';
    }
}

$srv = new FakeRoborockServer(4990);
$srv->konto = $rk;
$rk->parent = $srv;
$rk->WriteAttributeInteger('MqttEltern', 4990);
$rk->WriteAttributeInteger('EchtzeitPauseBis', 0);
$rk->WriteAttributeInteger('WarteBis', 0);
$rkCfg = json_decode($rk->GetConfigurationForParent(), true);
check('Client Socket: Roborock-Server, TLS mit Roborocks Wurzel (nicht Dreames), voll geprüft', $rkCfg['Host'] === 'mqtt-eu-3.roborock.com' && $rkCfg['Port'] === 8883
    && $rkCfg['CertificateAuthority'] === base64_encode((new RoboHubRoborock())->mqttWurzel()) && $rkCfg['VerifyPeer'] === true && $rkCfg['VerifyHost'] === true && $rkCfg['Open'] === true, json_encode($rkCfg));
$srv->gesendet = [];
$rk->MessageSink(0, 4990, IM_CHANGESTATUS, [IS_ACTIVE]);
$conPuffer = $srv->gesendet[0] ?? '';
$con = RoboHubMqtt::zerlegen($conPuffer)[0]['body'] ?? '';
$sub = $srv->gesendet[1] ?? '';
check('verbunden: CONNECT mit md5-Zugang wie die Einzelsitzung, danach SUBSCRIBE rr/m/o je Gerät', strpos($con, pack('n', 8) . '19648f94' . pack('n', 16) . '234678ea854f199e') !== false
    && ord($sub[0] ?? "\0") === 0x82 && strpos($sub, 'rr/m/o/user123/19648f94/abc123') !== false
    && (json_decode($rk->ReadAttributeString('Mqtt'), true)['zustand'] ?? '') === 'verbunden', bin2hex(substr($con, 0, 40)));

FakeRoborock::$antworten = $alle;
FakeRoborock::$sitzungen = [];
FakeRoborock::$rpc = [];
$srv->veroeffentlicht = [];
$t = $rr->Aktualisieren();
check('Abruf über die dauerhafte Verbindung: keine eigene Sitzung, Anfragen per PUBLISH rr/m/i, Antworten verarbeitet',
    FakeRoborock::$sitzungen === [] && in_array('rr/m/i/user123/19648f94/abc123', $srv->veroeffentlicht, true)
    && array_slice(array_column(FakeRoborock::$rpc, 0), 0, 5) === ['get_status', 'get_consumable', 'get_clean_summary', 'get_dnd_timer', 'get_sound_volume']
    && variable(4100, 'Akku')['value'] === 100, $t . ' ' . json_encode(FakeRoborock::$sitzungen));
$vorher = count($srv->veroeffentlicht);
$rr->Starten();
check('Befehl über dieselbe Verbindung', FakeRoborock::$sitzungen === [] && count($srv->veroeffentlicht) > $vorher && in_array('app_start', array_column(FakeRoborock::$rpc, 0), true)
    || in_array('app_segment_clean', array_column(FakeRoborock::$rpc, 0), true), json_encode(array_column(FakeRoborock::$rpc, 0)));
$anmeldungenVorher = $srv->anmeldungen;
for ($i = 0; $i < 5; $i++) {
    $rr->Aktualisieren();
}
check('fünf weitere Abrufe: keine neue Anmeldung beim Server (vorher je Abruf eine)', $srv->anmeldungen === $anmeldungenVorher && FakeRoborock::$sitzungen === []);

// Unaufgeforderte Meldung (python-roborock RoborockDataProtocol 121/122) → sofort im Roboter.
$push = RoboHubRoborock::rahmenBauen(json_encode(['t' => time(), 'dps' => ['121' => 5, '122' => 77]]), 'key123key123key1', 9, 9, time(), 102);
mqttVomServer($rk, RoboHubMqtt::publish('rr/m/o/user123/19648f94/abc123', $push));
check('Push: Akku 77 und Zustand 5 (reinigt) sofort übernommen', variable(4100, 'Akku')['value'] === 77 && variable(4100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_REINIGT);
check('Push mit falschem Schlüssel/fremdem Gerät übergangen', (new RoboHubRoborock())->pushLesenThema('rr/m/o/user123/19648f94/fremd', $push, [], json_decode($rk->ReadAttributeString('Geraete'), true)) === []);

// Stumme Dauerverbindung (Forum lugge 144636/179): Server hält die Verbindung, liefert aber nichts aus → nach 3 Ausfällen neu.
$ueber = new ReflectionMethod($rk, 'ueberDauerverbindung');
// Echte Anfrage (get_status) statt Push-Rahmen — sonst kann der nicht stumme Server sie nicht lesen (PHP-Warnungen in 0.52.2).
$anfrage = RoboHubRoborock::rahmenBauen(RoboHubRoborock::anfrageNutzlast(4711, 'get_status', [], time(), 'abc', '00'), 'key123key123key1', 9, 9, time(), 101);
$stummFrage = function () use ($ueber, $rk, $anfrage): void {
    $ueber->invoke($rk, 'rr/m/o/user123/19648f94/abc123', 'rr/m/i/user123/19648f94/abc123', [$anfrage], function (string $d): bool { return true; }, 0.01);
};
$neuAufbau = function (): int {
    return count(array_filter($GLOBALS['SKRIPTE'] ?? [], function ($s) { return strpos($s, 'IPS_SetProperty(4990, "Open", false)') !== false; }));
};
$srv->config = json_decode($rk->GetConfigurationForParent(), true);   // Socket offen wie verlangt
$srv->stumm = true;
$rk->SetBuffer('DauerNeustart', '0');
$rk->SetBuffer('DauerAusfaelle', '0');
$rk->SetBuffer('DauerUngeheilt', '0');
$stummLog = function (): int {
    return count(array_filter($GLOBALS['LOG'] ?? [], function ($z) { return strpos((string)$z, 'auch nach dem Neuaufbau') !== false; }));
};
$vorherLog = $stummLog();
$vorherNeu = $neuAufbau();
$stummFrage();
$stummFrage();
check('stumme Verbindung: zwei Ausfälle → noch kein Neuaufbau', $neuAufbau() === $vorherNeu);
$stummFrage();
check('dritter Ausfall in Folge → Socket aus/an (neue Anmeldung + Abo, python-roborock HealthManager)', $neuAufbau() === $vorherNeu + 1);
check('erster Neuaufbau nur im Debug, nicht im Meldungsfenster (Forum lugge 144636/187)', $stummLog() === $vorherLog);
for ($i = 0; $i < 6; $i++) {
    $stummFrage();
}
check('innerhalb 30 min kein weiterer Neuaufbau', $neuAufbau() === $vorherNeu + 1);
$rk->SetBuffer('DauerNeustart', (string)(time() - 1801));
$rk->SetBuffer('DauerAusfaelle', '0');
$srv->stumm = false;
$stummFrage();
$stummFrage();
$srv->stumm = true;
$stummFrage();
$stummFrage();
check('Antwort setzt den Zähler zurück (2 Ausfälle nach einer Antwort → kein Neuaufbau)', $neuAufbau() === $vorherNeu + 1);
// Neuaufbau mit Antwort danach = geheilt → nächster Neuaufbau wieder still; ohne Antwort dazwischen → Warnung.
$stummFrage();
check('Neuaufbau nach geheiltem Neuaufbau: wieder still', $neuAufbau() === $vorherNeu + 2 && $stummLog() === $vorherLog);
$rk->SetBuffer('DauerNeustart', (string)(time() - 1801));
$stummFrage();
$stummFrage();
$stummFrage();
check('Neuaufbau hat nichts gebracht (keine Antwort dazwischen) → Warnung im Meldungsfenster', $neuAufbau() === $vorherNeu + 3 && $stummLog() === $vorherLog + 1);
$srv->stumm = false;

// 0.52.3 (Forum alsk1 144636/184): Socket-Felder sind gesperrt → Knopf im Konto immer sichtbar, trennt und verbindet neu.
$rkForm = json_encode(json_decode(konsole($rk->GetConfigurationForm()), true), JSON_UNESCAPED_UNICODE);
check('Knopf „Echtzeit jetzt neu verbinden“ sichtbar, auch ohne Zertifikatssperre', $rk->ReadAttributeInteger('EchtzeitGesperrtBis') <= time()
    && strpos($rkForm, '"caption":"🔁 Echtzeit jetzt neu verbinden","visible":true') !== false);
$rk->SetBuffer('DauerAusfaelle', '2');
$vorherKnopf = $neuAufbau();
$rk->EchtzeitNeuVersuchen();
check('Knopf: Socket aus/an, Ausfallzähler zurückgesetzt', $neuAufbau() === $vorherKnopf + 1 && $rk->GetBuffer('DauerAusfaelle') === '0');
$rk->WriteAttributeInteger('EchtzeitPauseBis', time() + 600);
$vorherKnopf = $neuAufbau();
$rk->EchtzeitNeuVersuchen();
check('Knopf hebt eine Drosselpause des Herstellers nicht auf (jeder Versuch verlängert sie)', $neuAufbau() === $vorherKnopf && $rk->ReadAttributeInteger('EchtzeitPauseBis') > time());
$rk->WriteAttributeInteger('EchtzeitPauseBis', 0);
$srv->config = json_decode($rk->GetConfigurationForParent(), true);

// Ohne stehende Verbindung: Rückfall auf die Einzelsitzung.
$mq = json_decode($rk->ReadAttributeString('Mqtt'), true);
$rk->WriteAttributeString('Mqtt', json_encode(['zustand' => 'getrennt'] + $mq));
FakeRoborock::$sitzungen = [];
$rr->Aktualisieren();
check('Verbindung weg → Rückfall auf kurze Einzelsitzungen (Zustand, hier auch Live-Karte, weil er reinigt)', count(FakeRoborock::$sitzungen) >= 1, json_encode(FakeRoborock::$sitzungen));

// Daueranmeldung abgewiesen (Drosselung): Pause, Socket zu, auch keine Einzelsitzungen.
$srv->connack = 5;
$rk->MessageSink(0, 4990, IM_CHANGESTATUS, [IS_ACTIVE]);
FakeRoborock::$sitzungen = [];
$tPause = $rr->Aktualisieren();
check('CONNACK 5 → 15 min Pause: Socket geschlossen, keine Einzelsitzung, Hinweis', $rk->ReadAttributeInteger('EchtzeitPauseBis') > time() + 800
    && json_decode($rk->GetConfigurationForParent(), true)['Open'] === false && FakeRoborock::$sitzungen === [] && $rk->ReadAttributeString('Buendel') !== '', $tPause);
$ersteBis = $rk->ReadAttributeInteger('EchtzeitPauseBis');
$rk->WriteAttributeInteger('EchtzeitPauseBis', 0);
$rk->WriteAttributeInteger('WarteBis', 0);
$rk->MessageSink(0, 4990, IM_CHANGESTATUS, [IS_ACTIVE]);
$zweiteDauer = $rk->ReadAttributeInteger('EchtzeitPauseBis') - time();
check('erneut abgewiesen → Pause verdoppelt (30 min), wächst bis höchstens 6 h', $zweiteDauer > 1700 && $zweiteDauer <= 1800 + 5, (string)$zweiteDauer);
for ($i = 0; $i < 8; $i++) {
    $rk->WriteAttributeInteger('EchtzeitPauseBis', 0);
    $rk->WriteAttributeInteger('WarteBis', 0);
    $rk->MessageSink(0, 4990, IM_CHANGESTATUS, [IS_ACTIVE]);
}
check('… höchstens 6 h', $rk->ReadAttributeInteger('EchtzeitPauseBis') - time() <= 6 * 3600 + 5 && $rk->ReadAttributeInteger('EchtzeitPauseBis') - time() > 6 * 3600 - 60);
$mqText = (string)(json_decode($rk->ReadAttributeString('Mqtt'), true)['text'] ?? '');
check('ab der dritten Abweisung in Folge: Hinweis auf Neuanmelden mit neuem Code (python-roborock: neue Zugangsdaten lösen die Sperre)',
    strpos($mqText, 'sign in again') !== false || strpos($mqText, 'neu an') !== false, $mqText);
// Neuanmelden = neue Zugangsdaten → Pause und Stufe sofort zurück (Forum alsk1 144636/159: 28 h ausgesperrt).
$buendelJetzt = json_decode($rk->ReadAttributeString('Buendel'), true);
$treiberM = new ReflectionMethod($rk, 'treiber');
$abschluss = new ReflectionMethod($rk, 'anmeldungAbschliessen');
$rk->WriteAttributeInteger('EchtzeitGesperrtBis', time() + 86400);   // 24-h-Sperre „nie verbunden“ (Forum alsk1 144636/168)
$abschluss->invoke($rk, $buendelJetzt, $treiberM->invoke($rk));
check('Neuanmeldung setzt Echtzeit-Pause und Pausenstufe zurück', $rk->ReadAttributeInteger('EchtzeitPauseBis') === 0
    && $rk->ReadAttributeInteger('EchtzeitPauseStufe') === 0 && $rk->ReadAttributeInteger('WarteBis') === 0
    && $rk->ReadAttributeInteger('EchtzeitGesperrtBis') === 0);
$rk->WriteAttributeInteger('EchtzeitPauseBis', 0);
$rk->WriteAttributeInteger('WarteBis', 0);
$srv->connack = 0;
$rk->MessageSink(0, 4990, IM_CHANGESTATUS, [IS_ACTIVE]);
check('angenommen → Pausenstufe zurück auf 0', $rk->ReadAttributeInteger('EchtzeitPauseStufe') === 0);
check('Mi-Home-Roborock bleibt ohne Konto-Verbindung (Befehle lokal per miIO)', (new RoboHubXiaomi())->echtzeitMoeglich() === false);
