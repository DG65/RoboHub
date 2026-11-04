<?php

declare(strict_types=1);

// ===========================================================================
// RoboHub-Treiber für Kärcher Home Robots (RCV/RCF/RVC/RVM/RVF) — Cloud von 3iRobotix.
//
// Quellen: lafriks/python-karcher (MIT, Stand 27.10.2026: karcher.py, utils.py,
// consts.py, PROTOCOL.md — Anmeldung, Signatur, Verschlüsselung, MQTT-Themen) und
// vosadci/karcher-rcv5-ha (MIT, 27.10.2026: const.py work_mode-Gruppen und
// Fehlercodes, state.py Zustandsregeln, vacuum.py Befehle, sensor.py Verschleiß,
// _model_profile.py Produkt-IDs).
//
// Anmeldung: POST user-center/auth/login mit AES-128-ECB-verschlüsselter E-Mail und
// Passwort (Schlüssel aus der Tenant-ID, steht offen in beiden Quellen). Antwort:
// AUTH (Zugangsschlüssel), EMQ_TOKEN (MQTT-Passwort), id (Nutzer-ID).
// Erneuern ohne Passwort: POST user-center/auth/login/token (TokenLoginReq: Felder wie
// beim Login, token + userId statt Zugangsdaten, versionCode/phoneSys als Text). Keine
// Quelle nutzt ihn (karcher-rcv5-ha speichert E-Mail + Passwort); Format live 27.10.2026
// ermittelt (Server nennt fehlende Felder, erfundener Schlüssel → 401). Mit einem echten
// Schlüssel noch nie gesehen. RoboHub speichert das Passwort NICHT (Credential-Regel):
// scheitert die Erneuerung, muss neu angemeldet werden.
//
// TLS: 3iRobotix nutzt ein selbst signiertes Zertifikat (CN=*.3irobotix.net,
// gültig bis 29.11.2031, SHA-256 68:A3:68:92:…:4A:10 — gleicher Fingerabdruck wie in
// python-karcher). RoboHub prüft gegen genau dieses Zertifikat als Wurzel, mit
// Namensprüfung — für REST (curl) und MQTT. Keine Abschaltung der Prüfung.
//
// Zustand: je Abruf eine kurze MQTT-Sitzung (prop.get → get_reply). Befehle als
// service_invoke (set_room_clean ctrl_value 1/2/0, start_recharge, stop_recharge,
// find_device), Einstellungen als prop.set (wind/water/mode).
// Nicht gebaut: Räume/Karte (verschlüsselte Kartendatei), Echtzeit, Stationsaufgaben.
// ===========================================================================

class RoboHubKaercher implements RoboHubTreiber
{
    /** @var callable|null fn(string $thema, string $text) */
    public $debug = null;

    private const TENANT = '1528983614213726208';
    private const PROJEKT = 'android_iot.karcher';

    private const REGIONEN = [
        'eu' => ['Europe (also DE/AT/CH)', 'https://eu-appaiot.3irobotix.net'],
        'us' => ['North America (untested)', 'https://us-appaiot.3irobotix.net'],
        'cn' => ['China/Asia (untested)', 'https://cn-appaiot.3irobotix.net'],
    ];

    // Selbst signiertes Zertifikat der 3iRobotix-Server (live gelesen 27.10.2026 von
    // eu-appaiot und eu-gamqttaiot, beide identisch).
    public const ZERTIFIKAT = "-----BEGIN CERTIFICATE-----\n"
        . "MIICCDCCAa4CCQD98hA2cug6ejAKBggqhkjOPQQDAjCBizELMAkGA1UEBhMCQ04x\n"
        . "CzAJBgNVBAgMAkdEMQswCQYDVQQHDAJTWjESMBAGA1UECgwJM2lyb2JvdGl4MQww\n"
        . "CgYDVQQLDANJT1QxGDAWBgNVBAMMDyouM2lyb2JvdGl4Lm5ldDEmMCQGCSqGSIb3\n"
        . "DQEJARYXYWlvdF9mYXFAM2lyb2JvdGljcy5jb20wHhcNMjExMjAxMDgxNjAyWhcN\n"
        . "MzExMTI5MDgxNjAyWjCBizELMAkGA1UEBhMCQ04xCzAJBgNVBAgMAkdEMQswCQYD\n"
        . "VQQHDAJTWjESMBAGA1UECgwJM2lyb2JvdGl4MQwwCgYDVQQLDANJT1QxGDAWBgNV\n"
        . "BAMMDyouM2lyb2JvdGl4Lm5ldDEmMCQGCSqGSIb3DQEJARYXYWlvdF9mYXFAM2ly\n"
        . "b2JvdGljcy5jb20wWTATBgcqhkjOPQIBBggqhkjOPQMBBwNCAATlxne4NxTPIsm7\n"
        . "Qbd1RrWTO90C5rvT/cG0V7nP/eEeXWNg/yeSJN2QFLULAxuhQG/FRTGlIzna4g1V\n"
        . "sdZ6IgdFMAoGCCqGSM49BAMCA0gAMEUCIQCsXq8C3GuG0yz18XVdvqDey6IybJMn\n"
        . "OPAjihl0jgwmBAIgFDKWpj+CvgxD0pjE7+IWV/dd3l4XwR6iNPFr/8bd1cQ=\n"
        . "-----END CERTIFICATE-----\n";

    // Produkt-IDs (karcher-rcv5-ha _model_profile.py) → Modellname.
    private const PRODUKTE = [
        '1540149850806333440' => 'RCV 5', '1528986273083777024' => 'RCV 3', '1599715149861306368' => 'RCF 3',
        '1703609713493610496' => 'RCV 2', '1946027907671224320' => 'RVC 3', '1946028477060575232' => 'RVC 3 Comfort',
        '1946123509838999552' => 'RVM 4 Comfort', '1950097634462887936' => 'RVF 7', '1950097614355394560' => 'RVF 7 Comfort',
        '1670775876502392832' => 'RCV 3', '1670774796888543232' => 'RCV 5',
    ];
    // RVF 7 zählt die Saugstufen 1–4 statt 0–3 (_model_profile.py RVF7_WIND_LEVELS).
    private const SAUGSTUFE_AB_1 = ['1950097634462887936', '1950097614355394560'];

    private const EIGENSCHAFTEN = ['status', 'firmware', 'fault', 'mode', 'wind', 'water', 'charge_state', 'quantity', 'work_mode',
        'cleaning_area', 'cleaning_time', 'volume', 'main_brush', 'side_brush', 'hypa', 'mop_life'];

    // work_mode-Gruppen (const.py, Mitschnitt 28.03.2026).
    private const ARBEITET = [1, 7, 25, 30, 36, 81];
    private const HEIMWEG = [5, 10, 11, 12, 21, 26, 32, 38, 47];
    private const PAUSE = [4, 9, 27, 31, 37, 82];
    private const RUHT = [0, 2, 14, 23, 29, 35, 40, 85];
    private const ZONE = [30, 31, 32, 35];   // Bereichsreinigung: Pause/Fortsetzen über set_zone_clean

    // Lebensdauer in Minuten (sensor.py); die Eigenschaft zählt die verbrauchten Minuten.
    private const TEILE = ['Hauptbuerste' => ['main_brush', 21600], 'Seitenbuerste' => ['side_brush', 10800],
        'Filter' => ['hypa', 10800], 'Mopp' => ['mop_life', 10800]];

    // 21xx sind Statusmeldungen, keine Fehler (App: isStatusNoThisFault).
    private const KEIN_FEHLER = [2100, 2101, 2102, 2103, 2104, 2105, 2106, 2107, 2108, 2109, 2110, 2111, 2112, 2118];

    // Fehlercodes (const.py FAULT_CODE_DESCRIPTIONS, Texte eigene Übersetzung).
    private const FEHLER = [
        100 => 'Hardware error', 500 => 'Laser tower not responding', 501 => 'Wheel lifted', 502 => 'Battery too low to start',
        503 => 'Dust bin missing', 504 => 'Magnetic sensor fault', 505 => 'Start from the station failed',
        506 => 'Infrared guide sensor fault', 507 => 'Position not found again', 508 => 'Cannot start on a slope',
        509 => 'Cliff sensor fault', 510 => 'Bumper fault', 511 => 'Return to base failed',
        512 => 'Put the robot into the station', 513 => 'Navigation failed', 514 => 'Robot stuck',
        515 => 'Station: contact fault', 516 => 'Battery temperature fault', 517 => 'Firmware is being updated',
        518 => 'Waiting to charge', 519 => 'Main brush blocked', 520 => 'Side brush blocked', 521 => 'Water tank missing',
        522 => 'Mop cloth missing', 523 => 'Dust bin full', 524 => 'Main switch off', 525 => 'Water tank empty',
        526 => 'Mop cloth dirty', 527 => 'Dust bin full', 530 => 'Battery temperature unusual',
        531 => 'Battery temperature back to normal', 2000 => 'Dust bin full', 2001 => 'Left brush blocked',
        2002 => 'Right brush blocked', 2003 => 'No power / schedule off', 2007 => 'Cleaning interrupted',
        2008 => 'Cleaning finished', 2009 => 'Scheduled cleaning finished', 2010 => 'Distance sensor fault',
        2100 => 'Return to base interrupted', 2101 => 'Charging interrupted', 2102 => 'Returning to base',
        2103 => 'Changing state', 2104 => 'Sent to base', 2105 => 'Charging finished',
        2106 => 'Waiting to charge interrupted', 2107 => 'Scheduled cleaning running', 2108 => 'Searching for its position',
        2109 => 'Cleaning again', 2110 => 'Self-test', 4002 => 'Map error',
    ];

    private const SAUGSTUFEN = [0 => 'Quiet', 1 => 'Standard', 2 => 'Medium', 3 => 'Turbo'];
    private const WASSER = [0 => 'Low', 1 => 'Medium', 2 => 'High'];
    private const MODI = [0 => 'Vacuum', 1 => 'Vacuum and mop', 2 => 'Mop'];
    private const EINSTELLUNGEN = ['Saugstufe' => ['wind', self::SAUGSTUFEN], 'Wassermenge' => ['water', self::WASSER],
        'Reinigungsmodus' => ['mode', self::MODI]];

    public static function regionen(): array
    {
        $aus = [];
        foreach (self::REGIONEN as $wert => [$text]) {
            $text = RoboHubText::wert($text);
            $aus[] = ['caption' => $text, 'value' => $wert];
        }
        return $aus;
    }

    // =================================================================
    // Anmeldung
    // =================================================================

    public function anmelden(string $konto, string $passwort, string $region): array
    {
        $konto = trim($konto);
        if (!filter_var($konto, FILTER_VALIDATE_EMAIL) || $passwort === '') {
            throw new RoboHubFehler(RoboHubText::t('Please enter e-mail and password of the Kärcher account (app "Kärcher Home Robots").'));
        }
        $region = isset(self::REGIONEN[$region]) ? $region : 'eu';
        $basis = self::REGIONEN[$region][1];
        // Serverliste: die Cloud nennt die eigentlichen API- und MQTT-Server (verschlüsselt).
        $d = $this->anfrage(['api' => $basis], 'GET', '/network-service/domains/list', ['tenantId' => self::TENANT,
            'productModeCode' => self::PROJEKT, 'version' => 'v1'], null);
        $server = json_decode(self::entschluesseln((string)($d['domain'] ?? '')), true);
        $api = self::serverAus((string)($server['APP_api'] ?? ''), 443) ?? [parse_url($basis, PHP_URL_HOST), 443];
        $mqtt = self::serverAus((string)($server['MQTT'] ?? ''), 8883);
        if ($mqtt === null) {
            throw new RoboHubFehler(RoboHubText::t('Kärcher cloud names no valid MQTT server.'));
        }
        $b = ['region' => $region, 'api' => 'https://' . $api[0] . ($api[1] !== 443 ? ':' . $api[1] : ''), 'mqtt' => $mqtt[0], 'mqttPort' => $mqtt[1],
            'geraetId' => self::zufall(19)];
        $r = $this->anfrage(['api' => $b['api']], 'POST', '/user-center/auth/login', null, self::anmeldeKoerper($b['geraetId'], [
            'token' => null, 'userId' => null, 'password' => self::verschluesseln($passwort), 'username' => self::verschluesseln($konto)], false), true);
        return self::zugangUebernehmen($b, $r);
    }

    /** Körper wie die App (Reihenfolge zählt für die Signatur); TokenLoginReq will versionCode/phoneSys als Text. */
    private static function anmeldeKoerper(string $geraetId, array $zugang, bool $alsText): array
    {
        return ['tenantId' => self::TENANT, 'lang' => 'de', 'token' => $zugang['token'], 'userId' => $zugang['userId'],
            'password' => $zugang['password'], 'username' => $zugang['username'], 'authcode' => null,
            'projectType' => self::PROJEKT, 'versionCode' => $alsText ? '10004' : 10004, 'versionName' => '1.0.4',
            'phoneBrand' => self::verschluesseln('xiaomi_mi 9'), 'phoneSys' => $alsText ? '1' : 1,
            'noticeSetting' => ['andIpad' => $geraetId, 'android' => $geraetId]];
    }

    private static function zugangUebernehmen(array $b, array $r): array
    {
        $auth = (string)($r['AUTH'] ?? $r['auth'] ?? $r['token'] ?? '');
        $emq = (string)($r['EMQ_TOKEN'] ?? $r['emq_token'] ?? $b['emq'] ?? '');
        $id = (string)($r['id'] ?? $r['userId'] ?? $b['userId'] ?? '');
        if ($auth === '' || $emq === '' || $id === '') {
            throw new RoboHubFehler(RoboHubText::t('Kärcher sign-in did not deliver complete access (AUTH/EMQ_TOKEN/id missing).'));
        }
        return ['auth' => $auth, 'emq' => $emq, 'userId' => $id, 'ablauf' => self::ablaufAus($auth), 'erneuert' => time()] + $b;
    }

    public function anmeldeText(array $buendel): string
    {
        return RoboHubText::t('✅ Signed in. The password was discarded. Kärcher has no refresh token — if access expires, RoboHub reports it and you sign in here again%s.', ((int)($buendel['ablauf'] ?? 0) > 0 ? RoboHubText::t(' (valid until %s according to the token)', date('d.m.Y H:i', (int)$buendel['ablauf'])) : ''));
    }

    /**
     * Erneuert per login/token, wenn die Cloud den Schlüssel abgelehnt hat (Merkdatei) oder
     * er laut „exp" in 3 Tagen abläuft. Scheitert das, ist eine neue Anmeldung nötig.
     */
    public function erneuern(array $buendel): array
    {
        $ablauf = (int)($buendel['ablauf'] ?? 0);
        if (!is_file($this->erneuernDatei($buendel)) && ($ablauf === 0 || $ablauf - 3 * 86400 > time())) {
            return $buendel;
        }
        try {
            $r = $this->anfrage($buendel, 'POST', '/user-center/auth/login/token', null, self::anmeldeKoerper((string)($buendel['geraetId'] ?? self::zufall(19)), [
                'token' => (string)$buendel['auth'], 'userId' => (string)$buendel['userId'], 'password' => null, 'username' => null], true));
            $neu = self::zugangUebernehmen($buendel, $r);
        } catch (RoboHubFehler $f) {
            if ($f->getCode() === 503) {
                throw $f;   // Cloud gerade nicht erreichbar: beim nächsten Abruf noch einmal
            }
            @unlink($this->erneuernDatei($buendel));
            $e = new RoboHubFehler(RoboHubText::t('Kärcher ended the access and does not allow renewing it without a password — please sign in again in the account instance.'));
            $e->anmeldungNoetig = true;
            throw $e;
        }
        @unlink($this->erneuernDatei($buendel));
        $this->dbg('Anmeldung', 'Zugang per login/token erneuert');
        return $neu;
    }

    private function erneuernDatei(array $buendel): string
    {
        return sys_get_temp_dir() . '/robohub-kaercher-erneuern-' . substr(md5((string)($buendel['auth'] ?? '')), 0, 12);
    }

    /** Ablaufzeit aus dem Zugangsschlüssel (JWT „exp"), 0 = unbekannt. */
    public static function ablaufAus(string $jwt): int
    {
        $teile = explode('.', $jwt);
        if (count($teile) < 2) {
            return 0;
        }
        $j = json_decode((string)base64_decode(strtr($teile[1], '-_', '+/')), true);
        return is_array($j) && is_numeric($j['exp'] ?? null) ? (int)$j['exp'] : 0;
    }

    private static function serverAus(string $text, int $standard): ?array
    {
        if (!preg_match('/^([a-z0-9.-]+)(?::(\d{1,5}))?$/i', $text, $m)) {
            return null;
        }
        // Nur 3iRobotix-Server: nur für die gilt das gepinnte Zertifikat.
        if (substr(strtolower($m[1]), -strlen('.3irobotix.net')) !== '.3irobotix.net') {
            return null;
        }
        return [strtolower($m[1]), isset($m[2]) && $m[2] !== '' ? (int)$m[2] : $standard];
    }

    // =================================================================
    // Geräte und Zustand
    // =================================================================

    public function geraete(array $buendel): array
    {
        $liste = [];
        $roh = $this->anfrage($buendel, 'GET', '/smart-home-service/smartHome/user/getDeviceInfoByUserId/' . rawurlencode((string)$buendel['userId']), [], null);
        foreach ($roh as $g) {
            if (!is_array($g) || (string)($g['sn'] ?? '') === '' || (string)($g['productId'] ?? '') === '') {
                continue;
            }
            $produkt = (string)$g['productId'];
            $name = self::PRODUKTE[$produkt] ?? (string)($g['productModeCode'] ?? 'Kärcher');
            $liste[] = ['id' => (string)$g['sn'], 'name' => trim((string)($g['nickname'] ?? '')) ?: 'Kärcher ' . $name,
                'modell' => 'kaercher.vacuum.' . (strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '', $name)) ?: 'robot'),
                'klasse' => RoboHubModell::KLASSE_SAUGER, 'online' => (int)($g['status'] ?? 0) === 1, 'produkt' => $produkt,
                'bild' => strpos((string)($g['photoUrl'] ?? ''), 'https://') === 0 ? (string)$g['photoUrl'] : '',
                'roh' => ['produkt' => $produkt, 'modell' => $name]];
        }
        return $liste;
    }

    public function zustand(array $buendel, array $geraet): array
    {
        $antwort = $this->mqttAustausch($buendel, $geraet, 'service/property/get', self::umschlag('prop.get', ['property' => self::EIGENSCHAFTEN], '3.0'), 'service/property/get_reply', 8.0);
        if ($antwort === null) {
            // Keine Antwort = Roboter schläft oder ist nicht im WLAN (karcher-rcv5-ha: Zeitüberschreitung).
            return ['werte' => ['Zustand' => RoboHubModell::ZUSTAND_UNBEKANNT, 'ZustandRoh' => RoboHubText::t('No response from the robot')],
                'optionen' => array_map(function (array $e): array { return $e[1]; }, self::EINSTELLUNGEN), 'schreibbar' => [], 'online' => false,
                'bloecke' => ['ok' => [], 'fehl' => [0]], 'roh' => ['properties' => null]];
        }
        if ((int)($antwort['code'] ?? 0) !== 0) {
            throw new RoboHubFehler(RoboHubText::t('The robot rejects the query (code %s).', (int)$antwort['code']));
        }
        $p = is_array($antwort['data'] ?? null) ? $antwort['data'] : [];
        $daten = $this->normalisiere((string)($geraet['modell'] ?? ''), $p + ['_produkt' => (string)($geraet['produkt'] ?? '')]);
        $daten['online'] = true;
        if (($geraet['bild'] ?? '') !== '') {
            $daten['bild'] = (string)$geraet['bild'];
        }
        $daten['bloecke'] = ['ok' => [0], 'fehl' => []];
        $daten['roh'] = ['modell' => $geraet['modell'] ?? null, 'properties' => $p];
        return $daten;
    }

    public function normalisiere(string $modell, array $p): array
    {
        $w = [];
        $modus = (int)($p['work_mode'] ?? -1);
        $fehler = (int)($p['fault'] ?? 0);
        $akku = is_numeric($p['quantity'] ?? null) ? (int)$p['quantity'] : null;
        $inStation = (int)($p['status'] ?? 0) === 4 || !empty($p['charge_state']);
        $echterFehler = $fehler !== 0 && !in_array($fehler, self::KEIN_FEHLER, true);
        if (in_array($modus, self::ARBEITET, true)) {
            $z = RoboHubModell::ZUSTAND_REINIGT;
        } elseif (in_array($modus, self::PAUSE, true)) {
            $z = RoboHubModell::ZUSTAND_PAUSIERT;
        } elseif (in_array($modus, self::HEIMWEG, true)) {
            $z = $inStation ? RoboHubModell::ZUSTAND_GELADEN : RoboHubModell::ZUSTAND_ZURUECK;
        } elseif (in_array($modus, self::RUHT, true)) {
            // Fehler nur, wenn er außerhalb der Station steht (state.py) — unterwegs sind Meldungen vorübergehend.
            $z = $inStation ? RoboHubModell::ZUSTAND_GELADEN : ($echterFehler ? RoboHubModell::ZUSTAND_FEHLER : RoboHubModell::ZUSTAND_BEREIT);
        } else {
            $z = $inStation ? RoboHubModell::ZUSTAND_GELADEN : RoboHubModell::ZUSTAND_UNBEKANNT;
        }
        if ($z === RoboHubModell::ZUSTAND_GELADEN && !empty($p['charge_state']) && $akku !== null && $akku < 100) {
            $z = RoboHubModell::ZUSTAND_LAEDT;
        }
        $w['Zustand'] = $z;
        $w['ZustandRoh'] = RoboHubText::wert(RoboHubModell::ZUSTAENDE[$z][0]) . ' (work_mode ' . $modus . ')';
        $w['Fehler'] = $echterFehler ? RoboHubText::wert(self::FEHLER[$fehler] ?? 'Error') . ' (' . $fehler . ')' : RoboHubText::t('no error');
        if ($fehler !== 0 && !$echterFehler) {
            $w['Meldung'] = RoboHubText::wert(self::FEHLER[$fehler] ?? 'Message') . ' (' . $fehler . ')';
        }
        $w['Akku'] = $akku;
        $w['Laedt'] = $z === RoboHubModell::ZUSTAND_LAEDT;
        if (is_numeric($p['cleaning_area'] ?? null)) {
            $w['Flaeche'] = (int)round((int)$p['cleaning_area'] / 100);
        }
        if (is_numeric($p['cleaning_time'] ?? null)) {
            $w['Dauer'] = (int)$p['cleaning_time'];
        }
        foreach (self::TEILE as $feld => [$eig, $leben]) {
            if (is_numeric($p[$eig] ?? null)) {
                $rest = max(0, $leben - (int)$p[$eig]);
                $w[$feld] = (int)floor($rest / $leben * 100);
                $w[$feld . 'Rest'] = round($rest / 60, 1);
            }
        }
        if (is_string($p['firmware'] ?? null) && $p['firmware'] !== '') {
            $w['Firmware'] = $p['firmware'];
        }
        if (is_numeric($p['volume'] ?? null)) {
            $w['Lautstaerke'] = (int)$p['volume'];
        }
        $ab1 = in_array((string)($p['_produkt'] ?? ''), self::SAUGSTUFE_AB_1, true);
        $optionen = [];
        foreach (self::EINSTELLUNGEN as $feld => [$eig, $werte]) {
            $optionen[$feld] = $werte;
            if (is_numeric($p[$eig] ?? null)) {
                $roh = (int)$p[$eig] - ($feld === 'Saugstufe' && $ab1 ? 1 : 0);
                if (isset($werte[$roh])) {
                    $w[$feld] = $roh;
                }
            }
        }
        return ['werte' => $w, 'optionen' => $optionen, 'schreibbar' => []];
    }

    // =================================================================
    // Befehle
    // =================================================================

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        if ($befehl === 'setze') {
            $feld = (string)($parameter['feld'] ?? '');
            $wert = (int)($parameter['wert'] ?? -1);
            if (!isset(self::EINSTELLUNGEN[$feld]) || !isset(self::EINSTELLUNGEN[$feld][1][$wert])) {
                throw new RoboHubFehler(RoboHubText::t('This setting does not exist on Kärcher.'));
            }
            [$eig, $werte] = self::EINSTELLUNGEN[$feld];
            $roh = $wert + ($feld === 'Saugstufe' && in_array((string)($geraet['produkt'] ?? ''), self::SAUGSTUFE_AB_1, true) ? 1 : 0);
            $this->mqttAustausch($buendel, $geraet, 'service/property/set', self::umschlag('prop.set', [$eig => $roh], '1.0'), null, 5.0);
            return RoboHubText::t('%s "%s" sent.', RoboHubText::wert(RoboHubModell::FELDER[$feld][0] ?? $feld), RoboHubText::wert($werte[$wert]));
        }
        // Pause/Fortsetzen/Stopp einer Bereichsreinigung laufen über set_zone_clean (vacuum.py) — dafür den Arbeitsmodus kennen.
        $zone = false;
        if (in_array($befehl, ['start', 'pause', 'stopp'], true)) {
            $a = $this->mqttAustausch($buendel, $geraet, 'service/property/get', self::umschlag('prop.get', ['property' => ['work_mode']], '3.0'), 'service/property/get_reply', 6.0);
            $zone = in_array((int)($a['data']['work_mode'] ?? -1), self::ZONE, true);
            $pausiert = in_array((int)($a['data']['work_mode'] ?? -1), self::PAUSE, true);
            if ($befehl === 'start' && !$pausiert) {
                $zone = false;   // neuer Start ist immer eine ganze Reinigung
            }
            if ($befehl === 'stopp' && in_array((int)($a['data']['work_mode'] ?? -1), self::HEIMWEG, true)) {
                $befehl = 'heimwegStopp';
            }
        }
        $map = [
            'start'        => ['set_room_clean', ['room_ids' => [], 'ctrl_value' => 1, 'clean_type' => 0], 'Start'],
            'pause'        => ['set_room_clean', ['room_ids' => [], 'ctrl_value' => 2, 'clean_type' => 0], 'Pause'],
            'stopp'        => ['set_room_clean', ['room_ids' => [], 'ctrl_value' => 0, 'clean_type' => 0], 'Stopp'],
            'heimwegStopp' => ['stop_recharge', new stdClass(), 'Stopp'],
            'station'      => ['start_recharge', new stdClass(), RoboHubText::t('Return to base')],
            'orten'        => ['find_device', new stdClass(), 'Orten'],
        ];
        if (!isset($map[$befehl])) {
            throw new RoboHubFehler($befehl === 'raum' ? RoboHubText::t('RoboHub does not control rooms on Kärcher (yet).') : RoboHubText::t('This command is not supported by Kärcher: %s', $befehl));
        }
        [$dienst, $param, $text] = $map[$befehl];
        if ($zone && in_array($befehl, ['start', 'pause', 'stopp'], true)) {
            [$dienst, $param] = ['set_zone_clean', ['ctrl_value' => $param['ctrl_value']]];
        }
        $this->mqttAustausch($buendel, $geraet, 'service_invoke/' . $dienst, self::umschlag('service.' . $dienst, $param, '3.0'), null, 5.0);
        return RoboHubText::t('Command "%s" sent.', $text);
    }

    /** Nutzlast wie in der App: method, msgId (ms als Text), tenantId, version, params. */
    public static function umschlag(string $methode, $params, string $version): string
    {
        return (string)json_encode(['method' => $methode, 'msgId' => (string)(int)floor(microtime(true) * 1000), 'tenantId' => self::TENANT,
            'version' => $version, 'params' => $params], JSON_UNESCAPED_SLASHES);
    }

    public function karte(array $buendel, array $geraet): array
    {
        return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
    }

    public function liveKarte(array $buendel, array $geraet): array
    {
        return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
    }

    public function zuruecksetzen(array $buendel, array $geraet, string $teil): string
    {
        throw new RoboHubFehler(RoboHubText::t('Resetting wear parts on Kärcher is (still) only possible in the app.'));
    }

    public function echtzeitMoeglich(): bool
    {
        return false;
    }

    public function mqttZiel(array $geraete, array $buendel): array
    {
        return ['', 0];
    }

    public function mqttTopic(array $geraet, array $buendel): string
    {
        return '';
    }

    public function mqttZugang(array $buendel): array
    {
        return ['', ''];
    }

    public function pushLesen(string $payload): array
    {
        return [];
    }

    // =================================================================
    // MQTT (kurze Sitzung je Abruf/Befehl) — im Prüfstand ersetzbar
    // =================================================================

    /**
     * Schickt $inhalt an /mqtt/{produkt}/{sn}/thing/{$ziel}; mit $antwortThema wartet sie
     * auf die Antwort dort und liefert sie als Array (null = keine Antwort in der Frist).
     */
    protected function mqttAustausch(array $buendel, array $geraet, string $ziel, string $inhalt, ?string $antwortThema, float $frist): ?array
    {
        $basis = '/mqtt/' . (string)($geraet['produkt'] ?? '') . '/' . (string)$geraet['id'] . '/thing/';
        $ende = microtime(true) + $frist;
        $sitzung = RoboHubMqttSitzung::oeffnen((string)$buendel['mqtt'], (int)($buendel['mqttPort'] ?? 8883), self::ZERTIFIKAT, null, $ende);
        try {
            $nutzer = (string)$buendel['userId'];
            $sitzung->senden(RoboHubMqtt::connect($nutzer . '_' . bin2hex(random_bytes(8)), $nutzer, (string)$buendel['emq'], 60));
            $p = $sitzung->warte(RoboHubMqtt::CONNACK, null, $ende);
            if ($p === null) {
                throw new RoboHubFehler(RoboHubText::t('Kärcher server did not answer the sign-in.'));
            }
            $code = RoboHubMqtt::connackCode($p['body']);
            if ($code === 4 || $code === 5) {
                @touch($this->erneuernDatei($buendel));   // nicht abmelden: nächster Abruf erneuert
                throw new RoboHubFehler(RoboHubText::t('Kärcher MQTT rejects the access (%s) — RoboHub renews it at the next update.', RoboHubMqtt::connackText($code)));
            }
            if ($code !== 0) {
                throw new RoboHubFehler(RoboHubText::t('Kärcher server rejects the connection: %s.', RoboHubMqtt::connackText($code)));
            }
            if ($antwortThema !== null) {
                $sitzung->senden(RoboHubMqtt::subscribe(1, [$basis . $antwortThema], 0));
                if ($sitzung->warte(RoboHubMqtt::SUBACK, null, $ende) === null) {
                    throw new RoboHubFehler(RoboHubText::t('Kärcher server did not confirm the subscription.'));
                }
            }
            $sitzung->senden(RoboHubMqtt::publish($basis . $ziel, $inhalt));
            $this->dbg('MQTT', $ziel . ' gesendet');
            if ($antwortThema === null) {
                return null;
            }
            $antwort = null;
            $sitzung->warte(RoboHubMqtt::PUBLISH, function (array $paket) use ($basis, $antwortThema, &$antwort): bool {
                $pub = RoboHubMqtt::publishLesen($paket['flags'], $paket['body']);
                if ($pub['topic'] !== $basis . $antwortThema) {
                    return false;
                }
                $j = json_decode($pub['payload'], true);
                $antwort = is_array($j) ? $j : null;
                return $antwort !== null;
            }, $ende);
            $this->dbg('MQTT', $antwortThema . ($antwort === null ? RoboHubText::t(' did not arrive') : ' erhalten'));
            return $antwort;
        } finally {
            $sitzung->schliessen();
        }
    }

    // =================================================================
    // REST mit Signatur
    // =================================================================

    public static function schluessel(): string
    {
        return substr(md5(self::TENANT), 8, 16);
    }

    public static function verschluesseln(string $text): string
    {
        return (string)openssl_encrypt($text, 'aes-128-ecb', self::schluessel());
    }

    public static function entschluesseln(string $text): string
    {
        $aus = openssl_decrypt($text, 'aes-128-ecb', self::schluessel());
        return $aus === false ? '' : $aus;
    }

    /** Signaturtext eines POST-Körpers: Schlüssel + Wert hintereinander (null → „null", Objekte als kompaktes JSON). */
    public static function koerperText(array $koerper): string
    {
        $aus = '';
        foreach ($koerper as $k => $v) {
            $aus .= $k . ($v === null ? 'null' : (is_array($v) ? json_encode($v, JSON_UNESCAPED_SLASHES) : (string)$v));
        }
        return $aus;
    }

    private static function zufall(int $laenge): string
    {
        $zeichen = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $aus = '';
        for ($i = 0; $i < $laenge; $i++) {
            $aus .= $zeichen[random_int(0, 61)];
        }
        return $aus;
    }

    /** Signierte Anfrage; Rückgabe: „result" (Array). $anmeldung: 620 als Anmeldefehler melden. */
    private function anfrage(array $buendel, string $methode, string $pfad, ?array $query, ?array $koerper, bool $anmeldung = false): array
    {
        $auth = (string)($buendel['auth'] ?? '');
        $ts = (string)time();
        $nonce = self::zufall(32);
        $daten = $methode === 'GET' ? http_build_query((array)$query, '', '&', PHP_QUERY_RFC1738) : self::koerperText((array)$koerper);
        $kopf = ['User-Agent: Android_' . self::TENANT, 'tenantId: ' . self::TENANT, 'sign: ' . md5($auth . $ts . $nonce . $daten),
            'ts: ' . $ts, 'nonce: ' . $nonce, 'Accept: application/json'];
        if ($auth !== '') {
            $kopf[] = 'authorization: ' . $auth;
        }
        if ((string)($buendel['userId'] ?? '') !== '') {
            $kopf[] = 'id: ' . $buendel['userId'];
        }
        $url = (string)$buendel['api'] . $pfad . ($methode === 'GET' && $daten !== '' ? '?' . $daten : '');
        $body = null;
        if ($methode !== 'GET') {
            $kopf[] = 'Content-Type: application/json';
            $body = (string)json_encode($koerper ?? new stdClass(), JSON_UNESCAPED_SLASHES);
        }
        [$status, $text] = $this->http($methode, $url, $kopf, $body);
        $this->dbg('HTTP', $methode . ' ' . preg_replace('#/getDeviceInfoByUserId/.*#', '/getDeviceInfoByUserId/…', $pfad) . ' → ' . $status);
        if ($status === 0 || $status >= 500) {
            throw new RoboHubFehler(RoboHubText::t('Kärcher cloud not reachable%s.', ($status === 0 ? ': ' . $text : ' (HTTP ' . $status . ')')), 503);
        }
        $j = json_decode($text, true);
        if ($status !== 200 || !is_array($j)) {
            throw new RoboHubFehler(RoboHubText::t('Unexpected response from the Kärcher cloud (HTTP %s).', $status));
        }
        $code = (int)($j['code'] ?? -1);
        if ($code === 0) {
            return is_array($j['result'] ?? null) ? $j['result'] : [];
        }
        if ($anmeldung && ($code === 620 || $code === 623)) {
            throw new RoboHubFehler($code === 620 ? RoboHubText::t('Kärcher rejects the sign-in: e-mail or password wrong.')
                : RoboHubText::t('Kärcher does not know this e-mail — check spelling and region.'));
        }
        if (in_array($code, [401, 609, 613], true)) {
            if ($pfad === '/user-center/auth/login/token') {
                throw new RoboHubFehler(RoboHubText::t('Kärcher rejects the renewal (code %s).', $code), 401);
            }
            @touch($this->erneuernDatei($buendel));   // nicht abmelden: nächster Abruf erneuert per login/token
            throw new RoboHubFehler(RoboHubText::t('Kärcher rejected the access token (code %s) — RoboHub renews it at the next update.', $code));
        }
        throw new RoboHubFehler(RoboHubText::t('Kärcher cloud reports code %s%s.', $code, (isset($j['msg']) && is_string($j['msg']) ? ': ' . $j['msg'] : '')));
    }

    /** curl gegen das gepinnte 3iRobotix-Zertifikat (mit Namensprüfung); im Prüfstand ersetzbar. */
    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $methode,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO         => RoboHubMqttSitzung::caDatei(self::ZERTIFIKAT),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $text = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = curl_error($ch);
        if ($text === false) {
            return [0, $fehler];
        }
        return [$status, (string)$text];
    }

    private function dbg(string $thema, string $text): void
    {
        if (is_callable($this->debug)) {
            ($this->debug)($thema, $text);
        }
    }
}
