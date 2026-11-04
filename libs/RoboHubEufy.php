<?php

declare(strict_types=1);

// ===========================================================================
// RoboHub-Treiber für Eufy RoboVac (App „Eufy Clean" bzw. „Eufy") — Geräte mit MQTT-Anbindung.
//
// Quelle: jeppesens/eufy-clean (Fork von martijnpoppen/eufy-clean, eigene „Eufy-Clean License":
// Weitergabe erlaubt mit Namensnennung und gekennzeichneten Änderungen). RoboHub übernimmt
// daraus NUR Protokollwissen (Endpunkte, DPS-Nummern, Protobuf-Feldnummern aus den .proto-Dateien
// der App, Zustandsregeln), keinen Code. Stand 27.10.2026.
//
// Anmeldung: home-api.eufylife.com v2 (App „Eufy", client eufy-app), sonst v1 (App „Eufy Clean",
// client eufyhome-app) mit E-Mail + Passwort → access_token; user_center_info → user_center_token
// + id (gtoken = md5(id)); get_user_mqtt_info → AWS-IoT-Endpunkt, thing_name, Client-Zertifikat
// und -Schlüssel. Einen Erneuerungsschlüssel belegt keine Quelle (HA speichert das Passwort).
// RoboHub speichert das Passwort NICHT: lehnt Eufy den Zugang ab, ist eine neue Anmeldung nötig.
// Live 27.10.2026 ohne Konto: beide Anmeldewege → res_code 5002, user_center_info → 401,
// get_user_mqtt_info → 401 „token error".
//
// Zustand: AIOT get_device_list liefert je Gerät die letzten DPS-Werte (Abruf per HTTP).
// Zwei Formen: „novel" (Protobuf, base64 mit Längenpräfix; 153 WorkStatus, 163 Akku, 158 Saugstufe,
// 177 ErrorCode, 167 CleanStatistics, 168 ConsumableResponse) und „scalar" (Tuya-artige Zahlen,
// z. B. G50: 15 Zustand, 104 Akku, 102 Saugstufe, 106/177 Fehler, 109 Sekunden, 110 m², 150 Verschleiß).
// Befehle per MQTT an cmd/eufy_home/{Modell}/{SN}/req (mTLS mit dem Client-Zertifikat, Server gegen
// Amazon Root CA 1 geprüft). Ältere reine Tuya-Geräte (ohne MQTT) unterstützt RoboHub nicht.
// ===========================================================================

class RoboHubEufy implements RoboHubTreiber
{
    /** @var callable|null fn(string $thema, string $text) */
    public $debug = null;

    private const ANMELDUNG = [
        ['https://home-api.eufylife.com/v1/user/v2/email/login', 'eufy-app', '8FHf22gaTKu7MZXqz5zytw', 'Health'],
        ['https://home-api.eufylife.com/v1/user/email/login', 'eufyhome-app', 'GQCpr9dSp3uQpsOMgJ4xQ', 'Home'],
    ];
    private const NUTZER = 'https://api.eufylife.com/v1/user/user_center_info';
    private const GERAETE = 'https://aiot-clean-api-pr.eufylife.com/app/devicerelation/get_device_list';
    private const MQTT_INFO = 'https://aiot-clean-api-pr.eufylife.com/app/devicemanage/get_user_mqtt_info';
    private const AGENT = 'EufyHome-Android-3.1.3-753';

    // Modellcodes (const.py EUFY_CLEAN_DEVICES, Auswahl).
    private const MODELLE = [
        'T2080' => 'Omni S1 Pro', 'T2118' => 'RoboVac 30C', 'T2210' => 'Robot Vacuum G50', 'T2261' => 'X8 Hybrid', 'T2262' => 'X8',
        'T2266' => 'X8 Pro', 'T2267' => 'L60', 'T2268' => 'L60 Hybrid', 'T2276' => 'X8 Pro SES', 'T2278' => 'L60 SES',
        'T2320' => 'X9 Pro', 'T2351' => 'X10 Pro Omni', 'T2080A' => 'Omni S1 Pro', 'T2292' => 'C10', 'T2265' => 'C20',
    ];

    // WorkStatus.state (work_status.proto) → Zustand (parser.py _map_work_status, eigene Feinheiten unten).
    private const SAUGSTUFEN_NEU = [0 => 'Quiet', 1 => 'Standard', 2 => 'Turbo', 3 => 'Max', 4 => 'Boost IQ'];
    private const SAUGSTUFEN_ALT = [0 => 'Quiet', 1 => 'Standard', 2 => 'Turbo', 3 => 'Max'];

    // Lebensdauer in Stunden (const.py ACCESSORY_MAX_LIFE; novel zählt Stunden, scalar Minuten).
    private const TEILE_NEU = ['Filter' => [3, 360], 'Hauptbuerste' => [2, 360], 'Seitenbuerste' => [1, 180], 'Sensoren' => [5, 60], 'Mopp' => [6, 180]];
    private const TEILE_ALT = ['Filter' => ['dust_filter', 200], 'Hauptbuerste' => ['roller_brush', 360], 'Seitenbuerste' => ['side_brush', 250], 'Sensoren' => ['sensors', 35]];

    // Fehlercodes (const.py EUFY_CLEAN_ERROR_CODES, Auswahl, eigene Übersetzung).
    private const FEHLER = [
        1 => 'Bumper stuck', 2 => 'Wheel blocked', 3 => 'Side brush blocked', 4 => 'Main brush blocked', 5 => 'Robot stuck — remove obstacle',
        6 => 'Robot stuck — please move it', 7 => 'Wheel hanging in the air', 8 => 'Battery empty, switched off', 13 => 'Robot is tilted', 14 => 'Dust bin missing',
        17 => 'No-go area detected', 18 => 'Laser cover stuck', 19 => 'Laser sensor stuck', 20 => 'Laser covered', 21 => 'Docking failed',
        26 => 'Scheduled start failed (battery)', 31 => 'Suction inlet clogged', 32 => 'Mop holder motor stuck', 33 => 'Wiper holder motor stuck',
        39 => 'Position lost, cleaning ended', 40 => 'Mop cloth fell off', 41 => 'Dryer heater fault', 50 => 'Robot is on a carpet',
        51 => 'Camera covered', 52 => 'Cannot leave the station', 55 => 'Station not found', 70 => 'Clean the dust collector', 71 => 'Wall sensor fault',
        72 => 'Low water in the robot', 73 => 'Dirty water tank full', 74 => 'Clean water low', 75 => 'Water tank missing', 76 => 'Camera fault',
        77 => '3D sensor fault', 78 => 'Ultrasonic sensor fault', 79 => 'Cleaning tray missing', 80 => 'Connection to the robot disturbed',
        81 => 'Dirty water tank leaking', 82 => 'Clean the cleaning tray', 83 => 'Poor charging contacts', 101 => 'Battery fault', 102 => 'Wheel module fault',
        103 => 'Side brush fault', 104 => 'Fan fault', 105 => 'Main brush motor fault', 106 => 'Pump fault', 107 => 'Laser sensor fault',
        2310 => 'Dust bin or filter missing', 2311 => 'Empty the dust bin', 3013 => 'Water tank empty', 3020 => 'Water tank removed',
        3110 => 'Left mop missing', 3111 => 'Right mop missing', 4011 => 'Laser covered', 4031 => 'Distance sensor covered', 4041 => 'Camera covered',
        5014 => 'Station without power', 5015 => 'Battery too low for scheduled cleaning', 6010 => 'Station: clean water tank missing',
        6011 => 'Station: clean water empty', 6020 => 'Station: dirty water tank missing', 6021 => 'Station: dirty water tank full',
        6025 => 'Station: dirty water tank full or missing', 6030 => 'Station: cleaning tray missing', 6031 => 'Station: cleaning tray full',
        6113 => 'Station: no dust bag', 7000 => 'Area too narrow', 7001 => 'Robot hanging', 7002 => 'Robot lifted', 7004 => 'Robot stuck',
        7010 => 'In no-go area', 7020 => 'Position not found', 7021 => 'Position not found', 7031 => 'Return to base failed',
        7034 => 'Starting point not found', 7035 => 'Docking failed (station without power)', 7040 => 'Leaving the station failed',
        7050 => 'Target not reachable', 7051 => 'Schedule failed', 7053 => 'Robot is tilted', 7055 => 'Station not found',
    ];

    // ModeCtrlRequest.Method (control.proto).
    private const START = 0;
    private const HEIM = 6;
    private const STOPP = 12;
    private const PAUSE = 13;
    private const WEITER = 14;

    // =================================================================
    // Anmeldung
    // =================================================================

    public function anmelden(string $konto, string $passwort, string $region): array
    {
        $konto = trim($konto);
        if (!filter_var($konto, FILTER_VALIDATE_EMAIL) || $passwort === '') {
            throw new RoboHubFehler(RoboHubText::t('Please enter e-mail and password of the Eufy account (app "Eufy Clean" or "Eufy").'));
        }
        $udid = bin2hex(random_bytes(8));
        $sitzung = null;
        $grund = '';
        foreach (self::ANMELDUNG as [$url, $id, $geheim, $kategorie]) {
            [$status, $text] = $this->http('POST', $url, ['category: ' . $kategorie, 'Accept: */*', 'openudid: ' . $udid, 'Content-Type: application/json',
                'clientType: 1', 'User-Agent: ' . self::AGENT], (string)json_encode(['email' => $konto, 'password' => $passwort, 'client_id' => $id, 'client_secret' => $geheim]));
            $this->dbg('HTTP', 'POST ' . parse_url($url, PHP_URL_PATH) . ' → ' . $status);
            if ($status === 0 || $status >= 500) {
                throw new RoboHubFehler(RoboHubText::t('Eufy sign-in not reachable%s.', ($status === 0 ? ': ' . $text : ' (HTTP ' . $status . ')')));
            }
            if ($status === 429) {
                $e = new RoboHubFehler(RoboHubText::t('Eufy is throttling the sign-in right now (too many attempts) — please try again later.'));
                $e->warteSekunden = 900;
                throw $e;
            }
            $j = json_decode($text, true);
            if ($status === 200 && is_array($j) && (string)($j['access_token'] ?? '') !== '') {
                $nutzer = $this->nutzer((string)$j['access_token'], $udid);
                if ($nutzer !== null) {
                    $sitzung = [(string)$j['access_token'], $nutzer];
                    break;
                }
                $grund = RoboHubText::t('Account without access to the robot cloud');
                continue;
            }
            $grund = $grund !== '' ? $grund : (is_array($j) && isset($j['message']) ? (string)$j['message'] : 'HTTP ' . $status);
        }
        if ($sitzung === null) {
            throw new RoboHubFehler(RoboHubText::t('Eufy rejects the sign-in (%s) — check e-mail and password.', $grund));
        }
        [$token, $nutzer] = $sitzung;
        $b = ['token' => $token, 'udid' => $udid, 'ucToken' => (string)$nutzer['user_center_token'], 'gtoken' => md5((string)$nutzer['user_center_id'])];
        $m = $this->aiot($b, self::MQTT_INFO, null);
        foreach (['endpoint_addr', 'thing_name', 'certificate_pem', 'private_key', 'user_id'] as $feld) {
            if ((string)($m[$feld] ?? '') === '') {
                throw new RoboHubFehler(RoboHubText::t('Eufy did not deliver complete MQTT access (%s missing).', $feld));
            }
        }
        if (!preg_match('/^[a-z0-9-]+\.iot\.[a-z0-9-]+\.amazonaws\.com$/i', (string)$m['endpoint_addr'])) {
            throw new RoboHubFehler(RoboHubText::t('Eufy names the MQTT server "%s", RoboHub only connects to AWS IoT here. Robot mowers like the E15 do not use this way: for them choose the vendor "Eufy robot mower E15" in the account.', (string)$m['endpoint_addr']));
        }
        return $b + ['mqtt' => (string)$m['endpoint_addr'], 'thing' => (string)$m['thing_name'], 'zert' => (string)$m['certificate_pem'],
            'schluessel' => (string)$m['private_key'], 'userId' => (string)$m['user_id'], 'app' => (string)($m['app_name'] ?? 'eufy_home')];
    }

    public function anmeldeText(array $buendel): string
    {
        return RoboHubText::t('✅ Signed in. The password was discarded; stored are Eufy\'s access token and the MQTT certificate for the commands. If Eufy rejects the access at some point, sign in here again.');
    }

    public function erneuern(array $buendel): array
    {
        return $buendel;   // kein Erneuerungsweg belegt — Ablehnung führt über anfrage() zur neuen Anmeldung
    }

    private function nutzer(string $token, string $udid): ?array
    {
        [$status, $text] = $this->http('GET', self::NUTZER, ['content-type: application/x-www-form-urlencoded; charset=UTF-8', 'user-agent: ' . self::AGENT,
            'category: Home', 'token: ' . $token, 'openudid: ' . $udid, 'clienttype: 2'], null);
        $this->dbg('HTTP', 'GET user_center_info → ' . $status);
        $j = json_decode($text, true);
        return $status === 200 && is_array($j) && (string)($j['user_center_id'] ?? '') !== '' && (string)($j['user_center_token'] ?? '') !== '' ? $j : null;
    }

    /** POST an die AIOT-API; Rückgabe: „data". 401 = Zugang beendet. */
    private function aiot(array $b, string $url, ?array $koerper): array
    {
        [$status, $text] = $this->http('POST', $url, ['content-type: application/json', 'user-agent: ' . self::AGENT, 'openudid: ' . $b['udid'],
            'os-version: Android', 'model-type: PHONE', 'app-name: eufy_home', 'x-auth-token: ' . $b['ucToken'], 'gtoken: ' . $b['gtoken']],
            $koerper === null ? null : (string)json_encode($koerper));
        $this->dbg('HTTP', 'POST ' . basename((string)parse_url($url, PHP_URL_PATH)) . ' → ' . $status);
        if ($status === 0 || $status >= 500) {
            throw new RoboHubFehler(RoboHubText::t('Eufy cloud not reachable%s.', ($status === 0 ? ': ' . $text : ' (HTTP ' . $status . ')')));
        }
        $j = json_decode($text, true);
        if ($status === 401 || (is_array($j) && (int)($j['code'] ?? 0) === 401)) {
            $e = new RoboHubFehler(RoboHubText::t('Eufy ended the access and has no way to renew without a password — please sign in again in the account instance.'));
            $e->anmeldungNoetig = true;
            throw $e;
        }
        if ($status !== 200 || !is_array($j)) {
            throw new RoboHubFehler(RoboHubText::t('Unexpected response from the Eufy cloud (HTTP %s).', $status));
        }
        return is_array($j['data'] ?? null) ? $j['data'] : [];
    }

    // =================================================================
    // Geräte und Zustand
    // =================================================================

    /** AIOT-Geräteliste: [SN => Eintrag mit dps]. */
    private function liste(array $buendel): array
    {
        $aus = [];
        foreach ((array)($this->aiot($buendel, self::GERAETE, ['attribute' => 3])['devices'] ?? []) as $e) {
            $g = is_array($e['device'] ?? null) ? $e['device'] : $e;
            if (is_array($g) && (string)($g['device_sn'] ?? '') !== '') {
                $aus[(string)$g['device_sn']] = $g;
            }
        }
        return $aus;
    }

    public function geraete(array $buendel): array
    {
        $liste = [];
        foreach ($this->liste($buendel) as $sn => $g) {
            $code = strtoupper((string)($g['device_model'] ?? ''));
            $code = isset(self::MODELLE[$code]) ? $code : substr($code, 0, 5);
            if ($code === '') {
                continue;
            }
            $name = self::MODELLE[$code] ?? $code;
            $liste[] = ['id' => $sn, 'name' => trim((string)($g['alias_name'] ?? $g['device_name'] ?? '')) ?: 'Eufy ' . $name,
                'modell' => 'eufy.vacuum.' . strtolower($code), 'klasse' => RoboHubModell::KLASSE_SAUGER, 'online' => true,
                'code' => $code, 'roh' => ['modell' => $name, 'code' => $code, 'art' => self::art((array)($g['dps'] ?? []))]];
        }
        return $liste;
    }

    /** „novel" (Protobuf), „scalar" (Zahlen) oder „legacy" (kein MQTT-Gerät) — wie cloud.py checkApiType. */
    public static function art(array $dps): string
    {
        foreach (['153', '154'] as $k) {
            if (isset($dps[$k])) {
                return is_string($dps[$k]) && !is_numeric($dps[$k]) && base64_decode($dps[$k], true) !== false ? 'novel' : 'scalar';
            }
        }
        if (isset($dps['15']) && is_numeric($dps['15'])) {
            return 'scalar';
        }
        return $dps === [] ? 'novel' : 'legacy';
    }

    public function zustand(array $buendel, array $geraet): array
    {
        $g = $this->liste($buendel)[(string)$geraet['id']] ?? null;
        if (!is_array($g)) {
            throw new RoboHubFehler(RoboHubText::t('This Eufy robot is not (or no longer) in the account.'));
        }
        $dps = is_array($g['dps'] ?? null) ? $g['dps'] : [];
        $daten = $this->normalisiere((string)($geraet['modell'] ?? ''), $dps);
        $daten['online'] = $dps !== [];
        $daten['bloecke'] = ['ok' => [0], 'fehl' => []];
        $daten['roh'] = ['modell' => $geraet['modell'] ?? null, 'art' => self::art($dps), 'properties' => $dps];
        return $daten;
    }

    /** Protobuf-DPS-Wert: base64, vorn die Länge als Varint (utils.py decode has_length). */
    public static function pb(string $wert): array
    {
        $bin = base64_decode($wert, true);
        if ($bin === false || $bin === '') {
            return [];
        }
        $i = 0;
        while ($i < strlen($bin) && (ord($bin[$i]) & 0x80)) {
            $i++;
        }
        return RoboHubNarwal::pbLesen((string)substr($bin, $i + 1));
    }

    public function normalisiere(string $modell, array $p): array
    {
        return self::art($p) === 'scalar' ? $this->normalisiereSkalar($p) : $this->normalisiereNeu($p);
    }

    private function normalisiereNeu(array $p): array
    {
        $w = [];
        $ws = isset($p['153']) ? self::pb((string)$p['153']) : [];
        $zustand = (int)RoboHubNarwal::pbEins($ws, 2, 0);
        $laden = isset($ws[3]) ? RoboHubNarwal::pbLesen($ws[3][0]) : null;   // Charging: vorhanden = in der Station
        $reinigung = isset($ws[6]) ? RoboHubNarwal::pbLesen($ws[6][0]) : null;
        $waschen = isset($ws[7]) ? RoboHubNarwal::pbLesen($ws[7][0]) : null;
        $station = isset($ws[14]) ? RoboHubNarwal::pbLesen($ws[14][0]) : [];
        $akku = is_numeric($p['163'] ?? null) ? (int)$p['163'] : null;
        $text = '';
        if (!isset($p['153'])) {
            $z = RoboHubModell::ZUSTAND_UNBEKANNT;
        } elseif ($zustand === 1) {
            $z = RoboHubModell::ZUSTAND_SCHLAF;
        } elseif ($zustand === 2) {
            $z = RoboHubModell::ZUSTAND_FEHLER;
        } elseif ($zustand === 3) {
            $voll = $laden !== null && (int)RoboHubNarwal::pbEins($laden, 1, 0) === 1;
            $z = $voll || ($akku !== null && $akku >= 100) ? RoboHubModell::ZUSTAND_GELADEN : RoboHubModell::ZUSTAND_LAEDT;
        } elseif ($zustand === 4) {
            $z = RoboHubModell::ZUSTAND_KARTIERUNG;
        } elseif ($zustand === 5) {
            // Station (work_status.proto): 2 = Staubabsaugung, 3 = Waschen/Trocknen (state 1 = Trocknen).
            $waschModus = $waschen !== null ? (int)RoboHubNarwal::pbEins($waschen, 2, 0) : -1;
            $trocknet = isset($station[3]) && (int)RoboHubNarwal::pbEins(RoboHubNarwal::pbLesen($station[3][0]), 1, 0) === 1;
            if (in_array($waschModus, [1, 2], true) || isset($station[3]) || isset($station[2])) {
                $z = RoboHubModell::ZUSTAND_STATIONSARBEIT;
                $text = isset($station[2]) && !isset($station[3]) ? RoboHubText::t('Emptying the dust bin') : ($waschModus === 2 || $trocknet ? RoboHubText::t('Drying mop') : RoboHubText::t('Washing mop'));
            } elseif ($reinigung !== null && (int)RoboHubNarwal::pbEins($reinigung, 1, 0) === 1 && $waschen === null) {
                $z = RoboHubModell::ZUSTAND_PAUSIERT;
            } else {
                $z = RoboHubModell::ZUSTAND_REINIGT;
            }
        } elseif ($zustand === 6 || $zustand === 8) {
            $z = RoboHubModell::ZUSTAND_REINIGT;
        } elseif ($zustand === 7) {
            $z = RoboHubModell::ZUSTAND_ZURUECK;
        } elseif ($zustand === 15) {
            $z = RoboHubModell::ZUSTAND_PAUSIERT;
        } else {
            $z = RoboHubModell::ZUSTAND_BEREIT;
        }
        $w['Zustand'] = $z;
        $w['ZustandRoh'] = ($text !== '' ? $text : RoboHubText::wert(RoboHubModell::ZUSTAENDE[$z][0])) . ' (' . $zustand . ')';
        $fehler = 0;
        if (isset($p['177'])) {
            $f = self::pb((string)$p['177']);
            $fehler = (int)($f[2][0] ?? $f[3][0] ?? 0);   // error, sonst warn (parser.py liest warn)
        }
        $w['Fehler'] = $fehler !== 0 ? RoboHubText::wert(self::FEHLER[$fehler] ?? 'Error') . ' (' . $fehler . ')' : RoboHubText::t('no error');
        $w['Akku'] = $akku;
        $w['Laedt'] = $z === RoboHubModell::ZUSTAND_LAEDT;
        if (isset($p['167'])) {
            $s = self::pb((string)$p['167']);
            if (isset($s[1])) {
                $einzel = RoboHubNarwal::pbLesen($s[1][0]);
                $w['Dauer'] = (int)round((int)RoboHubNarwal::pbEins($einzel, 1, 0) / 60);
                $w['Flaeche'] = (int)RoboHubNarwal::pbEins($einzel, 2, 0);
            }
            if (isset($s[3])) {
                $ges = RoboHubNarwal::pbLesen($s[3][0]);
                $w['GesamtFlaeche'] = (int)RoboHubNarwal::pbEins($ges, 2, 0);
                $w['GesamtDauer'] = (int)round((int)RoboHubNarwal::pbEins($ges, 1, 0) / 3600);
                $w['GesamtAnzahl'] = (int)RoboHubNarwal::pbEins($ges, 3, 0);
            }
        }
        if (isset($p['168'])) {
            $c = self::pb((string)$p['168']);
            $lauf = isset($c[1]) ? RoboHubNarwal::pbLesen($c[1][0]) : [];
            foreach (self::TEILE_NEU as $feld => [$nr, $leben]) {
                if (isset($lauf[$nr])) {
                    $stunden = (int)RoboHubNarwal::pbEins(RoboHubNarwal::pbLesen($lauf[$nr][0]), 1, 0);
                    $rest = max(0, $leben - $stunden);
                    $w[$feld] = (int)floor($rest / $leben * 100);
                    $w[$feld . 'Rest'] = (float)$rest;
                }
            }
        }
        if (is_numeric($p['158'] ?? null) && isset(self::SAUGSTUFEN_NEU[(int)$p['158']])) {
            $w['Saugstufe'] = (int)$p['158'];
        }
        return ['werte' => $w, 'optionen' => ['Saugstufe' => self::SAUGSTUFEN_NEU], 'schreibbar' => []];
    }

    private function normalisiereSkalar(array $p): array
    {
        $w = [];
        $s = (int)($p['15'] ?? -1);
        $z = [0 => RoboHubModell::ZUSTAND_BEREIT, 1 => RoboHubModell::ZUSTAND_BEREIT, 2 => RoboHubModell::ZUSTAND_REINIGT, 4 => RoboHubModell::ZUSTAND_ZURUECK,
            5 => RoboHubModell::ZUSTAND_LAEDT, 6 => RoboHubModell::ZUSTAND_GELADEN, 7 => RoboHubModell::ZUSTAND_PAUSIERT][$s] ?? RoboHubModell::ZUSTAND_UNBEKANNT;
        $fehler = (int)($p['106'] ?? 0) ?: (int)($p['177'] ?? 0);   // welcher Kanal echte Fehler trägt, ist unbelegt — der von null verschiedene gewinnt
        if ($fehler !== 0 && in_array($z, [RoboHubModell::ZUSTAND_BEREIT, RoboHubModell::ZUSTAND_UNBEKANNT], true)) {
            $z = RoboHubModell::ZUSTAND_FEHLER;
        }
        $w['Zustand'] = $z;
        $w['ZustandRoh'] = RoboHubText::wert(RoboHubModell::ZUSTAENDE[$z][0]) . ' (' . $s . ')';
        $w['Fehler'] = $fehler !== 0 ? RoboHubText::wert(self::FEHLER[$fehler] ?? 'Error') . ' (' . $fehler . ')' : RoboHubText::t('no error');
        $w['Akku'] = is_numeric($p['104'] ?? null) ? (int)$p['104'] : null;
        $w['Laedt'] = $z === RoboHubModell::ZUSTAND_LAEDT;
        if (is_numeric($p['109'] ?? null)) {
            $w['Dauer'] = (int)round((int)$p['109'] / 60);
        }
        if (is_numeric($p['110'] ?? null)) {
            $w['Flaeche'] = (int)$p['110'];
        }
        $teile = isset($p['150']) ? (is_array($p['150']) ? $p['150'] : json_decode((string)$p['150'], true)) : null;
        foreach (self::TEILE_ALT as $feld => [$schl, $leben]) {
            if (is_array($teile) && is_numeric($teile[$schl] ?? null)) {
                $rest = max(0.0, $leben - (int)$teile[$schl] / 60);
                $w[$feld] = (int)floor($rest / $leben * 100);
                $w[$feld . 'Rest'] = round($rest, 1);
            }
        }
        if (is_numeric($p['102'] ?? null) && isset(self::SAUGSTUFEN_ALT[(int)$p['102']])) {
            $w['Saugstufe'] = (int)$p['102'];
        }
        return ['werte' => $w, 'optionen' => ['Saugstufe' => self::SAUGSTUFEN_ALT], 'schreibbar' => []];
    }

    // =================================================================
    // Befehle
    // =================================================================

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        $g = $this->liste($buendel)[(string)$geraet['id']] ?? null;
        if (!is_array($g)) {
            throw new RoboHubFehler(RoboHubText::t('This Eufy robot is not (or no longer) in the account.'));
        }
        $dps = is_array($g['dps'] ?? null) ? $g['dps'] : [];
        $art = self::art($dps);
        if ($art === 'legacy') {
            throw new RoboHubFehler(RoboHubText::t('This Eufy robot only speaks the old Tuya protocol — RoboHub does not control that (yet).'));
        }
        $pausiert = $this->normalisiere('', $dps)['werte']['Zustand'] === RoboHubModell::ZUSTAND_PAUSIERT;
        if ($befehl === 'setze') {
            $wert = (int)($parameter['wert'] ?? -1);
            $stufen = $art === 'scalar' ? self::SAUGSTUFEN_ALT : self::SAUGSTUFEN_NEU;
            if (($parameter['feld'] ?? '') !== 'Saugstufe' || !isset($stufen[$wert])) {
                throw new RoboHubFehler(RoboHubText::t('This setting does not exist on Eufy.'));
            }
            $this->senden($buendel, $geraet, [$art === 'scalar' ? '102' : '158' => $wert]);
            return RoboHubText::t('Suction level "%s" sent.', RoboHubText::wert($stufen[$wert]));
        }
        if ($art === 'scalar') {
            // G50-Mitschnitt (docs/g50_capture/FINDINGS.md): Start 5=1, Heim 5=3, Pause 122=1, Weiter 122=2 (nicht 0).
            $map = ['start' => [$pausiert ? ['122' => 2] : ['5' => 1], 'Start'], 'pause' => [['122' => 1], 'Pause'],
                'stopp' => [['122' => 1], RoboHubText::t('Stop (on this model like pause)')], 'station' => [['5' => 3], RoboHubText::t('Return to base')], 'orten' => [['103' => 1], 'Locate']];
        } else {
            $map = ['start' => [['152' => self::modusBefehl($pausiert ? self::WEITER : self::START)], 'Start'], 'pause' => [['152' => self::modusBefehl(self::PAUSE)], 'Pause'],
                'stopp' => [['152' => self::modusBefehl(self::STOPP)], 'Stop'], 'station' => [['152' => self::modusBefehl(self::HEIM)], RoboHubText::t('Return to base')],
                'orten' => [['160' => true], 'Locate']];
        }
        if (!isset($map[$befehl])) {
            throw new RoboHubFehler($befehl === 'raum' ? RoboHubText::t('RoboHub does not control rooms on Eufy (yet).') : RoboHubText::t('This command is not supported by Eufy: %s', $befehl));
        }
        $this->senden($buendel, $geraet, $map[$befehl][0]);
        return RoboHubText::t('Command "%s" sent.', RoboHubText::wert($map[$befehl][1]));
    }

    /** ModeCtrlRequest (control.proto) als DPS-Wert; Start mit auto_clean{clean_times 1} wie commands.py. Nullwerte weggelassen (proto3). */
    public static function modusBefehl(int $methode): string
    {
        $m = ($methode !== 0 ? RoboHubNarwal::pbZahl(1, $methode) : '')
            . ($methode === self::START ? RoboHubNarwal::pbTeil(3, RoboHubNarwal::pbZahl(1, 1)) : '');
        return base64_encode(RoboHubNarwal::pbVarint(strlen($m)) . $m);
    }

    /** Hülle wie client.py send_command (head + payload als JSON-Text). */
    public static function huelle(array $buendel, string $sn, array $daten, int $zeit): array
    {
        $client = 'android-' . $buendel['app'] . '-eufy_android_' . $buendel['udid'] . '_' . $buendel['userId'];
        return [$client, (string)json_encode(['head' => ['client_id' => $client, 'cmd' => 65537, 'cmd_status' => 2, 'msg_seq' => 1, 'seed' => '',
            'sess_id' => $client, 'sign_code' => 0, 'timestamp' => $zeit, 'version' => '1.0.0.1'],
            'payload' => (string)json_encode(['account_id' => $buendel['userId'], 'data' => $daten, 'device_sn' => $sn, 'protocol' => 2, 't' => $zeit])])];
    }

    private function senden(array $buendel, array $geraet, array $daten): void
    {
        $code = (string)($geraet['code'] ?? strtoupper(substr((string)($geraet['modell'] ?? ''), strlen('eufy.vacuum.'))));
        [$client, $inhalt] = self::huelle($buendel, (string)$geraet['id'], $daten, (int)floor(microtime(true) * 1000));
        $this->mqttSenden($buendel, $client, 'cmd/eufy_home/' . $code . '/' . $geraet['id'] . '/req', $inhalt);
    }

    /** Eine kurze mTLS-Sitzung: verbinden, senden, trennen. Im Prüfstand ersetzbar. */
    protected function mqttSenden(array $buendel, string $client, string $thema, string $inhalt): void
    {
        $ende = microtime(true) + 8.0;
        $sitzung = RoboHubMqttSitzung::oeffnen((string)$buendel['mqtt'], 8883, RoboHubWorx::MQTT_WURZEL, null, $ende,
            (string)$buendel['zert'] . "\n" . (string)$buendel['schluessel']);
        try {
            $sitzung->senden(RoboHubMqtt::connect($client, (string)$buendel['thing'], '', 60));
            $p = $sitzung->warte(RoboHubMqtt::CONNACK, null, $ende);
            if ($p === null) {
                throw new RoboHubFehler(RoboHubText::t('Eufy MQTT did not confirm the connection (certificate expired?) — sign in again if needed.'));
            }
            $code = RoboHubMqtt::connackCode($p['body']);
            if ($code !== 0) {
                $e = new RoboHubFehler(RoboHubText::t('Eufy MQTT rejects the access: %s — please sign in again.', RoboHubMqtt::connackText($code)));
                $e->anmeldungNoetig = in_array($code, [4, 5], true);
                throw $e;
            }
            $sitzung->senden(RoboHubMqtt::publish($thema, $inhalt));
            $this->dbg('MQTT', 'gesendet an ' . $thema);
        } finally {
            $sitzung->schliessen();
        }
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
        throw new RoboHubFehler(RoboHubText::t('Resetting wear parts on Eufy is (still) only possible in the app.'));
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
    // HTTP
    // =================================================================

    /** curl mit Zertifikatsprüfung (System-Wurzeln); im Prüfstand ersetzbar. */
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
