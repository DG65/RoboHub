<?php
/**
 * Eufy-Mäher-Teil des Prüfstands (von test-robohub.php eingebunden). Tuya 3.5 nach tinytuya (MIT), Datenpunkte und
 * Schlüsselweg nach Thomvanoorschot/ha-eufy-e15 (MIT). Prüfwerte für Signatur und Tuya-Passwort sind
 * unabhängig mit dem openssl-Kommandozeilenwerkzeug gerechnet. Kein Vergleich mit einem echten Gerät.
 */

declare(strict_types=1);

require_once __DIR__ . '/e15-geraet.inc.php';

class FakeE15Netz
{
    public $g;
    public function __construct(E15Geraet $g) { $this->g = $g; }
    public function senden(string $d): void { $this->g->nimm($d); }
    public function empfangen(float $ende): ?string
    {
        if ($this->g->aus === []) {
            return null;
        }
        return (string)array_shift($this->g->aus);
    }
    public function schliessen(): void {}
}

class FakeEufyMaeher extends RoboHubEufyMaeher
{
    public $meldungWarten = 0.05;
    public static $geraete = [];       // IP => E15Geraet
    public static $stumm = [];         // IP, unter denen die Verbindung steht, aber nichts antwortet
    public static $liste = [];
    public static $mobil = 'https://a1.tuyaeu.com';
    public static $anfragen = [];
    public static $privat = null;
    public static $status429 = false;

    protected function verbinden(string $ip)
    {
        if (isset(self::$stumm[$ip])) {
            return new class {
                public function senden(string $d): void {}
                public function empfangen(float $ende): ?string { return null; }
                public function schliessen(): void {}
            };
        }
        if (!isset(self::$geraete[$ip])) {
            throw new RoboHubFehler('Mäher unter ' . $ip . ':6668 nicht erreichbar (Connection refused).');
        }
        // Je Verbindung frische Sitzung, gleiche Daten
        $alt = self::$geraete[$ip];
        $n = new E15Geraet($alt->schluessel);
        $n->dps = $alt->dps;
        $n->meldung = $alt->meldung;
        $n->meldungAlsData = $alt->meldungAlsData;
        $n->geschrieben = &$alt->geschrieben;
        return new FakeE15Netz($n);
    }

    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        self::$anfragen[] = ['url' => $url, 'headers' => $headers, 'body' => (string)$body];
        if ($url === 'https://home-api.eufylife.com/v1/user/email/login') {
            if (self::$status429) {
                return [429, '{}'];
            }
            $d = json_decode((string)$body, true);
            if (($d['password'] ?? '') !== 'Rasen!' || ($d['client_Secret'] ?? '') === '' || ($d['email'] ?? '') !== 'timmy@example.org') {
                return [200, '{"res_code":0,"message":"Incorrect email login or password"}'];
            }
            return [200, json_encode(['access_token' => 'EUFYTOKEN', 'user_info' => ['id' => 'abc123', 'phone_code' => '43', 'request_host' => 'https://api.eufylife.com/v1']])];
        }
        if ($url === 'https://api.eufylife.com/v1/user/setting') {
            return [200, json_encode(['setting' => ['home_setting' => ['tuya_home' => ['tuya_region_code' => 'EU']]]])];
        }
        if (strpos($url, '/api.json?') === false) {
            return [404, '{}'];
        }
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        parse_str((string)$body, $f);
        $sign = (string)$q['sign'];
        unset($q['sign']);
        if (!hash_equals(RoboHubEufyMaeher::tuyaSignatur($q + (isset($f['postData']) ? ['postData' => $f['postData']] : [])), $sign)) {
            return [200, '{"success":false,"errorCode":"SING_VALIDATE_FALED","errorMsg":"Permission Deny"}'];
        }
        $post = json_decode((string)($f['postData'] ?? ''), true);
        switch ($q['a']) {
            case 'tuya.m.user.uid.token.create':
                $d = openssl_pkey_get_details(self::$privat)['rsa'];
                return [200, json_encode(['result' => ['publicKey' => self::dezimal($d['n']), 'exponent' => self::dezimal($d['e']), 'token' => 'TOK'], 'success' => true])];
            case 'tuya.m.user.uid.password.login.reg':
                $roh = '';
                openssl_private_decrypt((string)hex2bin((string)$post['passwd']), $roh, self::$privat, OPENSSL_NO_PADDING);
                if (ltrim($roh, "\0") !== RoboHubEufyMaeher::tuyaPasswort('eh-abc123') || $post['uid'] !== 'eh-abc123' || $post['countryCode'] !== '43' || $post['token'] !== 'TOK') {
                    return [200, '{"success":false,"errorMsg":"USER_PASSWD_WRONG"}'];
                }
                return [200, json_encode(['result' => ['sid' => 'SID1', 'domain' => ['mobileApiUrl' => self::$mobil]], 'success' => true])];
            case 'tuya.m.location.list':
                return [200, json_encode(['result' => [['groupId' => 77]], 'success' => true])];
            case 'tuya.m.my.group.device.list':
                return [200, json_encode(['result' => $q['gid'] === '77' && $q['sid'] === 'SID1' ? self::$liste : [], 'success' => true])];
            case 'tuya.m.my.shared.device.list':
                return [200, json_encode(['result' => [], 'success' => true])];
        }
        return [200, '{"success":false,"errorMsg":"unbekannt"}'];
    }

    public static function dezimal(string $bin): string
    {
        $z = '0';
        foreach (str_split($bin) as $c) {
            $neu = '';
            $u = ord($c);
            for ($i = strlen($z) - 1; $i >= 0; $i--) {
                $w = (int)$z[$i] * 256 + $u;
                $neu = (string)($w % 10) . $neu;
                $u = (int)($w / 10);
            }
            $z = ltrim(($u > 0 ? (string)$u : '') . $neu, '0') ?: '0';
        }
        return $z;
    }
}

// ===========================================================================
abschnitt('Eufy-Mäher E15 (Tuya 3.5, lokal)');

$fabrikEm = $GLOBALS['robohubTreiberFabrik'];
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) use ($fabrikEm) {
    return $marke === 'eufymaeher' ? new FakeEufyMaeher() : $fabrikEm($marke);
};
FakeEufyMaeher::$privat = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$emSchluessel = '0123456789abcdef';
$emDp = function (string $bin): string { return base64_encode($bin); };

check('Tuya-Passwort = unabhängig gerechneter Wert (openssl)', RoboHubEufyMaeher::tuyaPasswort('eh-0123456789abcdef') === 'a003c37e1751848a4485ba9e044bafd9', RoboHubEufyMaeher::tuyaPasswort('eh-0123456789abcdef'));
check('Tuya-Signatur = unabhängig gerechneter Wert (openssl), unsignierte Felder zählen nicht', RoboHubEufyMaeher::tuyaSignatur(['a' => 'tuya.m.location.list', 'v' => '2.1', 'time' => '1700000000', 'lang' => 'en', 'os' => 'Android',
    'postData' => '{"uid":"x"}', 'osSystem' => '12', 'sid' => 's']) === 'd725f29f5fb3cdb959fee661dabd8984f8b5623dae9e0cd6fd68871e41624945');

$r = RoboHubTuyaSitzung::rahmen($emSchluessel, 7, 0x10, '{}', '123456789012');
check('Rahmen: Präfix, 2 freie Bytes, Folge, Befehl, Länge = Nonce+Inhalt+Tag, Suffix', bin2hex(substr($r, 0, 18)) === '000066990000' . '00000007' . '00000010' . sprintf('%08x', 12 + 2 + 16)
    && substr($r, 18, 12) === '123456789012' && substr($r, -4) === "\x00\x00\x99\x66" && strlen($r) === 18 + 30 + 4, bin2hex($r));
$kaputt = $r;
$kaputt[6] = "\xff";   // authentifizierter Kopf verändert
$p = $kaputt;
$fehler = false;
try {
    RoboHubTuyaSitzung::entnehmen($p, $emSchluessel);
} catch (RoboHubFehler $e) {
    $fehler = true;
}
check('Rahmen: veränderter Kopf fällt durch die Prüfung', $fehler);
$p = substr($r, 0, 20);
$rest = RoboHubTuyaSitzung::entnehmen($p, $emSchluessel);
check('Rahmen: unvollständig bleibt im Puffer', $rest === null && strlen($p) === 20);
$p = 'muell' . $r . 'xy';
$m = RoboHubTuyaSitzung::entnehmen($p, $emSchluessel);
check('Rahmen: Müll davor wird übersprungen, Rest bleibt', $m === [0x10, '{}'] && $p === 'xy', json_encode($m));
check('Inhalt: Rückgabecode und Versionskopf weg, nacktes JSON bleibt', RoboHubTuyaSitzung::inhaltAus("\0\0\0\0{\"a\":1}", false) === '{"a":1}'
    && RoboHubTuyaSitzung::inhaltAus("\0\0\0\0" . "3.5" . str_repeat("\0", 12) . '{"a":1}', false) === '{"a":1}' && RoboHubTuyaSitzung::inhaltAus('{"a":1}', false) === '{"a":1}');
check('Varints: Felder, „\0" und leer = alles 0, kaputt = null', RoboHubEufyMaeher::varintFelder(base64_encode("\x08\x02\x18\x01")) === [1 => 2, 3 => 1] && RoboHubEufyMaeher::varintFelder(base64_encode("\0")) === []
    && RoboHubEufyMaeher::varintFelder('') === [] && RoboHubEufyMaeher::varintFelder('@@') === null && RoboHubEufyMaeher::varintFelder(base64_encode("\x0a\x01")) === null);

// Zustände wie protocol.py derive_activity
$zu = function (?array $a, ?bool $l) { return RoboHubEufyMaeher::zustandAus($a, $l); };
check('Zustand: Fehler vor allem', $zu([1 => 2, 3 => 1, 5 => 1], false) === RoboHubModell::ZUSTAND_FEHLER);
check('Zustand: Mäh-Auftrag läuft/ruht = mäht, Pause = pausiert', $zu([1 => 2, 3 => 1], false) === RoboHubModell::ZUSTAND_REINIGT && $zu([1 => 10, 3 => 0], false) === RoboHubModell::ZUSTAND_REINIGT && $zu([1 => 22, 3 => 2], false) === RoboHubModell::ZUSTAND_PAUSIERT);
check('Zustand: beendeter Auftrag — mit Kontakt Station, sonst gilt der vorige', $zu([1 => 2, 3 => 4], true) === RoboHubModell::ZUSTAND_GELADEN && $zu([1 => 2, 3 => 3], false) === null);
check('Zustand: Heimfahrt, mit Kontakt angekommen', $zu([1 => 1, 3 => 1], false) === RoboHubModell::ZUSTAND_ZURUECK && $zu([1 => 1, 3 => 1], true) === RoboHubModell::ZUSTAND_GELADEN);
check('Zustand: Karte speichern abseits der Station sagt nichts', $zu([1 => 0, 2 => 5], false) === null && $zu([6 => 1], false) === null && $zu([1 => 0, 2 => 5], true) === RoboHubModell::ZUSTAND_GELADEN);
check('Zustand: kein Auftrag — Ladekontakt entscheidet, ohne Kontaktwert nichts', $zu([], true) === RoboHubModell::ZUSTAND_GELADEN && $zu([], false) === RoboHubModell::ZUSTAND_BEREIT && $zu(null, null) === null && $zu([], null) === null);
check('Zustand: Kartieren', $zu([1 => 3, 3 => 1], false) === RoboHubModell::ZUSTAND_KARTIERUNG);

// Anmeldung über das Konto
$em = new RoboHubKonto(10500);
$em->props['Hersteller'] = 'eufymaeher';
$em->props['Konto'] = 'timmy@example.org';
$em->props['Passwort'] = 'Rasen!';
$em->props['MaeherIP'] = '';
$em->Create();
$t = $em->Anmelden();
check('ohne IP: klare Bitte, kein Kontakt zur Cloud', strpos($t, 'IP-Adresse des Mähers') !== false && FakeEufyMaeher::$anfragen === [], $t);
$em->props['MaeherIP'] = 'keine-ip';
$t = $em->Anmelden();
check('falsche IP-Schreibweise abgelehnt', strpos($t, 'keine IP-Adresse') !== false && FakeEufyMaeher::$anfragen === [], $t);

$em->props['MaeherIP'] = '192.168.1.70';
$em->props['Passwort'] = 'falsch';
$t = $em->Anmelden();
check('falsches Passwort: Eufys Text', strpos($t, 'Incorrect email login') !== false && $em->ReadAttributeString('Buendel') === '', $t);
FakeEufyMaeher::$status429 = true;
$em->props['Passwort'] = 'Rasen!';
$t = $em->Anmelden();
check('Eufy bremst: klare Meldung', strpos($t, 'bremst') !== false, $t);
FakeEufyMaeher::$status429 = false;

FakeEufyMaeher::$liste = [['devId' => 'BADEZIMMER', 'localKey' => 'aaaaaaaaaaaaaaaa', 'name' => 'Waage', 'productId' => 'xx'],
    ['devId' => 'MAEHER1', 'localKey' => $emSchluessel, 'name' => 'Timmys Mäher', 'productId' => 'bvxrcjxom787vnit'], ['devId' => 'OHNE', 'name' => 'ohne Schlüssel']];
FakeEufyMaeher::$mobil = 'https://boese.example.com';
FakeEufyMaeher::$geraete = ['192.168.1.70' => new E15Geraet($emSchluessel)];
$t = $em->Anmelden();
check('fremder Tuya-Server aus der Antwort: Abbruch, die Sitzung geht nicht dorthin', strpos($t, 'unerwarteten Server') !== false && strpos((string)json_encode(FakeEufyMaeher::$anfragen), 'boese.example.com') === false, $t);
FakeEufyMaeher::$mobil = 'https://a1.tuyaeu.com';
FakeEufyMaeher::$geraete = [];
FakeEufyMaeher::$liste = [FakeEufyMaeher::$liste[0], FakeEufyMaeher::$liste[1]];
$t = $em->Anmelden();
check('zwei Kandidaten, nichts erreichbar: Fehler statt Raten', strpos($t, 'antwortet kein Mäher') !== false && $em->ReadAttributeString('Buendel') === '', $t);
FakeEufyMaeher::$stumm = ['192.168.1.70' => true];
$t = $em->Anmelden();
check('Verbindung steht, aber keine Antwort: Hinweis auf Schlüssel/andere Sitzung', strpos($t, 'kein Schlüssel aus diesem Konto') !== false && $em->ReadAttributeString('Buendel') === '', $t);
FakeEufyMaeher::$stumm = [];

$emG = new E15Geraet($emSchluessel);
FakeEufyMaeher::$geraete = ['192.168.1.70' => $emG];
FakeEufyMaeher::$anfragen = [];
$t = $em->Anmelden();
$eb = json_decode($em->ReadAttributeString('Buendel'), true);
check('Anmeldung: der passende Schlüssel wird über die Aushandlung gefunden', strpos($t, '✅') !== false && array_keys($eb['roboter'] ?? []) === ['MAEHER1'] && $eb['roboter']['MAEHER1']['ip'] === '192.168.1.70'
    && $eb['roboter']['MAEHER1']['key'] === $emSchluessel && $eb['roboter']['MAEHER1']['geprueft'] === true, $t);
check('Bündel ohne Passwort, Eufy-Zugang und Tuya-Sitzung; Passwort verworfen', strpos($em->ReadAttributeString('Buendel'), 'Rasen') === false && strpos($em->ReadAttributeString('Buendel'), 'EUFYTOKEN') === false
    && strpos($em->ReadAttributeString('Buendel'), 'SID1') === false && $em->props['Passwort'] === '');
check('Eufy-Zugang geht nur an Eufy, Sitzung nur an Tuya', count(array_filter(FakeEufyMaeher::$anfragen, function ($a) { return in_array('token: EUFYTOKEN', $a['headers'], true) && strpos($a['url'], 'eufylife.com') === false; })) === 0);
$liste = (new FakeEufyMaeher())->geraete($eb);
check('Geräteliste: Mäher-Klasse, Name, Modell', count($liste) === 1 && $liste[0]['klasse'] === RoboHubModell::KLASSE_MAEHER && $liste[0]['name'] === 'Timmys Mäher' && $liste[0]['modell'] === 'eufy.mower.e15', json_encode($liste));

FakeEufyMaeher::$geraete = [];
FakeEufyMaeher::$liste = [['devId' => 'MAEHER1', 'localKey' => $emSchluessel, 'name' => '', 'productId' => 'bvxrcjxom787vnit']];
$em->props['Passwort'] = 'Rasen!';
$t = $em->Anmelden();
$eb2 = json_decode($em->ReadAttributeString('Buendel'), true);
check('Winterlager: ein Kandidat, nicht erreichbar → übernommen, mit Warnung', strpos($t, 'antwortet der Mäher gerade nicht') !== false && $eb2['roboter']['MAEHER1']['geprueft'] === false, $t);
FakeEufyMaeher::$geraete = ['192.168.1.70' => $emG];

// Zustand
$emD = new FakeEufyMaeher();
$emGer = ['id' => 'MAEHER1', 'modell' => 'eufy.mower.e15'];
@unlink(sys_get_temp_dir() . '/robohub-eufymaeher-' . md5('MAEHER1') . '.json');
$z = $emD->zustand($eb, $emGer);
check('ohne Meldung: Status ja, Zustand unbekannt statt geraten', $z['online'] === true && $z['werte']['Akku'] === 87 && $z['werte']['Zustand'] === RoboHubModell::ZUSTAND_UNBEKANNT && !isset($z['werte']['Laedt']), json_encode($z['werte']));
check('Werte: Lautstärke schreibbar, Schnitthöhe 25–75, Messer in Stunden', $z['werte']['Lautstaerke'] === 30 && $z['schreibbar'] === ['Lautstaerke'] && $z['werte']['Schnitthoehe'] === 45
    && $z['optionen']['Schnitthoehe'][45] === '45 mm' && !isset($z['optionen']['Schnitthoehe'][47]) && $z['werte']['Messerlaufzeit'] === 1.5, json_encode($z['werte']));
$emG->meldung = ['107' => $emDp("\x08\x02\x18\x01"), '108' => $emDp("\x08\x00\x10\x00")];
$z = $emD->zustand($eb, $emGer);
check('Meldung: mäht', $z['werte']['Zustand'] === RoboHubModell::ZUSTAND_REINIGT && $z['werte']['ZustandRoh'] === 'Auftrag 2, Phase 0, Stand 1' && $z['werte']['Fehler'] === 'kein Fehler', json_encode($z['werte']));
$emG->meldungAlsData = true;
$emG->meldung = ['107' => $emDp("\x08\x01\x18\x01")];
$z = $emD->zustand($eb, $emGer);
check('Meldung unter data.dps: Heimfahrt; neuer Auftrag heißt Ladekontakt weg', $z['werte']['Zustand'] === RoboHubModell::ZUSTAND_ZURUECK, json_encode($z['werte']));
$emG->meldung = ['108' => $emDp("\x10\x01")];
$z = $emD->zustand($eb, $emGer);
check('Ladekontakt kommt: Akku unter 100 = lädt', $z['werte']['Zustand'] === RoboHubModell::ZUSTAND_LAEDT && $z['werte']['Laedt'] === true, json_encode($z['werte']));
$emG->dps['8'] = 100;
$z = $emD->zustand($eb, $emGer);
check('… voll = in der Station', $z['werte']['Zustand'] === RoboHubModell::ZUSTAND_GELADEN && $z['werte']['Laedt'] === false);
$emG->meldung = [];
$z = $emD->zustand($eb, $emGer);
check('ohne neue Meldung gilt der letzte Stand', $z['werte']['Zustand'] === RoboHubModell::ZUSTAND_GELADEN);
$emG->meldung = ['107' => $emDp("\x08\x02\x18\x03"), '108' => $emDp("\x10\x00")];
$z = $emD->zustand($eb, $emGer);
check('Abgebrochen abseits der Station: vorheriger Zustand bleibt', $z['werte']['Zustand'] === RoboHubModell::ZUSTAND_GELADEN, json_encode($z['werte']));
$emG->meldung = ['107' => $emDp("\x08\x02\x18\x01\x28\x01"), '108' => $emDp("\x10\x00")];
$z = $emD->zustand($eb, $emGer);
check('Problem gemeldet: Fehlerzustand und Text', $z['werte']['Zustand'] === RoboHubModell::ZUSTAND_FEHLER && strpos($z['werte']['Fehler'], 'Problem') !== false);
$emG->meldung = ['107' => $emDp("\x08\x00"), '108' => $emDp("\x10\x01")];
$emD->zustand($eb, $emGer);
$emG->meldung = ['107' => $emDp("\x08\x01\x18\x01")];
$z = $emD->zustand($eb, $emGer);
check('neue Heimfahrt ohne DP 108: gemerkter Ladekontakt gilt nicht mehr', $z['werte']['Zustand'] === RoboHubModell::ZUSTAND_ZURUECK, json_encode($z['werte']));
$emG->meldung = ['107' => $emDp("\x08\x02\x18\x01"), '108' => $emDp("\x10\x00")];
$emG->meldungAlsData = false;

// Nicht erreichbar / falscher Schlüssel
$z = $emD->zustand(['roboter' => ['MAEHER1' => ['did' => 'MAEHER1', 'ip' => '192.168.1.99', 'key' => $emSchluessel]]], $emGer);
check('aus/außer Reichweite: kein Fehler, nur nicht erreichbar', $z['online'] === false && $z['werte']['Zustand'] === RoboHubModell::ZUSTAND_UNBEKANNT);
FakeEufyMaeher::$stumm = ['192.168.1.71' => true];
$z = $emD->zustand(['roboter' => ['MAEHER1' => ['did' => 'MAEHER1', 'ip' => '192.168.1.71', 'key' => $emSchluessel]]], $emGer);
check('andere Sitzung offen (keine Antwort): kein Abmelden, Schlüssel bleibt', $z['online'] === false);
FakeEufyMaeher::$stumm = [];
$n = new FakeE15Netz(new E15Geraet($emSchluessel));
$s = new RoboHubTuyaSitzung($n, 'ffffffffffffffff');
$fehler = null;
try {
    $s->aushandeln(microtime(true) + 1);
} catch (RoboHubFehler $e) {
    $fehler = $e;
}
check('falscher Schlüssel: Gerät schweigt → „keine Antwort", kein Abmelden', $fehler !== null && $fehler->anmeldungNoetig === false && $fehler->getCode() === RoboHubTuyaSitzung::KEINE_ANTWORT, (string)($fehler ? $fehler->getMessage() : ''));
$boese = new class {
    public $k;
    public $aus = [];
    public function senden(string $d): void
    {
        $this->aus[] = RoboHubTuyaSitzung::rahmen('0123456789abcdef', 1, 4, random_bytes(16) . str_repeat('x', 32), null, 0);
    }
    public function empfangen(float $ende): ?string { return array_shift($this->aus); }
    public function schliessen(): void {}
};
$s = new RoboHubTuyaSitzung($boese, '0123456789abcdef');
$fehler = null;
try {
    $s->aushandeln(microtime(true) + 1);
} catch (RoboHubFehler $e) {
    $fehler = $e;
}
check('falsche Gegenprüfung der Aushandlung: abgelehnt, neu anmelden', $fehler !== null && $fehler->anmeldungNoetig === true);

// Befehle
$emG->geschrieben = [];
$emG->meldung = ['107' => $emDp("\x08\x00"), '108' => $emDp("\x10\x01")];
$t = $emD->befehl($eb, $emGer, 'start', []);
check('Start aus der Station: DP 1 = true', $emG->geschrieben === [['1' => true]] && $t === 'Start gesendet.', json_encode($emG->geschrieben));
$emG->geschrieben = [];
$emG->meldung = ['107' => $emDp("\x08\x02\x18\x02"), '108' => $emDp("\x10\x00")];
$t = $emD->befehl($eb, $emGer, 'start', []);
check('Start aus der Pause: DP 2 = false', $emG->geschrieben === [['2' => false]] && $t === 'Fortsetzen gesendet.', json_encode($emG->geschrieben));
$emG->geschrieben = [];
$fehler = '';
try {
    $emD->befehl($eb, $emGer, 'pause', []);
} catch (RoboHubFehler $e) {
    $fehler = $e->getMessage();
}
check('Pause nur beim Mähen', strpos($fehler, 'nur, während') !== false && $emG->geschrieben === [], $fehler);
$emG->meldung = ['107' => $emDp("\x08\x02\x18\x01"), '108' => $emDp("\x10\x00")];
$emD->befehl($eb, $emGer, 'pause', []);
check('Pause beim Mähen: DP 2 = true', $emG->geschrieben === [['2' => true]], json_encode($emG->geschrieben));
$emG->geschrieben = [];
$emD->befehl($eb, $emGer, 'station', []);
check('Zur Station: DP 1 = false', $emG->geschrieben === [['1' => false]], json_encode($emG->geschrieben));
$emG->geschrieben = [];
$emG->dps['118'] = 50;
$fehler = '';
try {
    $emD->befehl($eb, $emGer, 'start', []);
} catch (RoboHubFehler $e) {
    $fehler = $e->getMessage();
}
check('Karte wird gespeichert: Befehl abgelehnt', strpos($fehler, 'speichert gerade') !== false && $emG->geschrieben === [], $fehler);
unset($emG->dps['118']);
foreach (['stopp' => 'Anhalten an Ort und Stelle', 'orten' => 'Orten kann', 'raum' => 'nicht'] as $b => $erwartet) {
    $fehler = '';
    try {
        $emD->befehl($eb, $emGer, $b, []);
    } catch (RoboHubFehler $e) {
        $fehler = $e->getMessage();
    }
    check('„' . $b . '" ehrlich abgelehnt, nichts gesendet', strpos($fehler, $erwartet) !== false && $emG->geschrieben === [], $fehler);
}
$emD->befehl($eb, $emGer, 'setze', ['feld' => 'Schnitthoehe', 'wert' => 50]);
$emD->befehl($eb, $emGer, 'setze', ['feld' => 'Lautstaerke', 'wert' => 20]);
check('Einstellungen: Schnitthöhe DP 110, Lautstärke DP 26', $emG->geschrieben === [['110' => 50], ['26' => 20]], json_encode($emG->geschrieben));
$emG->geschrieben = [];
$fehler = 0;
foreach ([['Schnitthoehe', 47], ['Schnitthoehe', 80], ['Lautstaerke', 101], ['Etwas', 1]] as [$feld, $wert]) {
    try {
        $emD->befehl($eb, $emGer, 'setze', ['feld' => $feld, 'wert' => $wert]);
    } catch (RoboHubFehler $e) {
        $fehler++;
    }
}
check('Einstellungen außerhalb des Bereichs abgelehnt', $fehler === 4 && $emG->geschrieben === []);

// Formular
$emF = json_encode(json_decode($em->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
check('Formular: IP-Feld und Hinweis nur beim Eufy-Mäher', strpos($emF, '"name":"MaeherIP","visible":true') !== false && strpos($emF, 'Eufy-Mäher E15: läuft lokal') !== false);
$em->props['Hersteller'] = 'eufy';
$emF = json_encode(json_decode($em->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
check('… beim Saugroboter-Eufy ausgeblendet', strpos($emF, '"name":"MaeherIP","visible":false') !== false);
$em->props['Hersteller'] = 'eufymaeher';

// Netzschicht gegen echtes TCP (zerteilte Rahmen)
$emNb = proc_open([PHP_BINARY, __DIR__ . '/e15-nachbau.php', $emSchluessel], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $emPipes);
$emPort = (int)trim((string)fgets($emPipes[1]));
try {
    $v = RoboHubTuyaVerbindung::oeffnen('127.0.0.1', $emPort, 3.0);
    $s = new RoboHubTuyaSitzung($v, $emSchluessel);
    $s->aushandeln(microtime(true) + 5);
    $s->senden(0x10, '{}');
    $erste = $s->lies(microtime(true) + 5);
    $s->senden(0x12, '{"dpId":[107,108]}');
    $zweite = $s->lies(microtime(true) + 5);
    $s->schliessen();
    check('echte Verbindung: Aushandlung, Status über zerteilte Rahmen', $erste !== null && RoboHubEufyMaeher::dpsAus($erste[1])['8'] === 87, json_encode($erste));
    check('… Meldung mit Versionskopf gelesen, leere Bestätigung übersprungen', $zweite !== null && $zweite[0] === 8 && isset(RoboHubEufyMaeher::dpsAus($zweite[1])['107']), json_encode($zweite));
} catch (Throwable $e) {
    check('echte Verbindung zum Nachbau', false, $e->getMessage());
} finally {
    proc_terminate($emNb);
    proc_close($emNb);
}
$fehler = '';
try {
    RoboHubTuyaVerbindung::oeffnen('127.0.0.1', 1, 1.0);
} catch (RoboHubFehler $e) {
    $fehler = $e->getMessage();
}
check('nicht erreichbar: klare Meldung', strpos($fehler, 'nicht erreichbar') !== false, $fehler);
$emQuelle = (string)file_get_contents(__DIR__ . '/../libs/RoboHubEufyMaeher.php');
check('kein Abschalten der TLS-Prüfung im Anmeldeweg', stripos($emQuelle, 'VERIFYPEER') === false && stripos($emQuelle, 'VERIFYHOST') === false && stripos($emQuelle, 'verify_peer') === false);
