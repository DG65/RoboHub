<?php

declare(strict_types=1);

// ===========================================================================
// RoboHub-Treiber für Eufy-Mähroboter E15 (T2880) — lokal im Heimnetz.
//
// Der E15 läuft nicht über Eufys Saugroboter-Cloud (RoboHubEufy): Er fehlt dort in
// der Geräteliste, der MQTT-Server lehnt das Abo ab (mega-yfue/eufy-sdk PR 366).
// Er spricht Tuya 3.5 auf TCP 6668 und erlaubt nur EINE lokale Sitzung.
//
// Quellen: Thomvanoorschot/ha-eufy-e15 (MIT, Stand 20.10.2026): const.py, protocol.py,
// lawn_mower.py, tools/get-local-key.sh. jasonacox/tinytuya (MIT): Rahmen und
// Schlüsselaushandlung (message_helper.py, XenonDevice.py).
//
// Anmeldung einmalig: Eufy-Konto → Tuya-Sitzung der App → localKey je Gerät. Passwort,
// Eufy-Zugang und Tuya-Sitzung werden danach verworfen; im Bündel steht nur der
// Geräteschlüssel. Zur Laufzeit keine Cloud. Der Schlüssel ändert sich, wenn der
// Mäher neu gekoppelt wird → dann neu anmelden.
//
// DP 107/108 (Auftrag, Ladekontakt) kommen nur als Meldung, nie in der Statusantwort.
// Deshalb bittet jede Abfrage um eine neue Meldung (UPDATEDPS) und merkt sich den
// letzten Stand in einer Temp-Datei — ohne Meldung gilt der letzte Stand.
//
// Befehle nur die an echter Hardware belegten: DP 1 true = Start, DP 2 = Pause/Weiter,
// DP 1 false = Auftrag beenden und heimfahren. Stopp an Ort und Stelle gibt es lokal
// nicht (DP 3 wird ignoriert). Nicht gebaut, weil lokal nicht verfügbar: Karte, Zonen.
// E18/C15 nutzen vermutlich dasselbe Protokoll, belegt ist das nicht.
// ===========================================================================

class RoboHubEufyMaeher implements RoboHubTreiber
{
    /** @var callable|null fn(string $thema, string $text) */
    public $debug = null;
    /** Vom Konto gesetzt: ['ip' => 'a.b.c.d[, …]']. */
    public $zusatz = [];
    /** Sekunden, die eine Abfrage auf die Meldung von DP 107/108 wartet (Prüfstand kürzer). */
    public $meldungWarten = 3.0;

    public const PORT = 6668;
    private const PRODUKTE = ['bvxrcjxom787vnit' => 'E15'];

    private const EUFY_LOGIN = 'https://home-api.eufylife.com/v1/user/email/login';
    private const EUFY_ID = 'eufyhome-app';
    private const EUFY_GEHEIM = 'GQCpr9dSp3uQpsOMgJ4xQ';
    private const EUFY_AGENT = 'EufyHome-Android-3.1.3-753';

    // Kennungen der Tuya-Sitzung in der Eufy-App (get-local-key.sh).
    private const TUYA_REGION = ['EU' => 'https://a1.tuyaeu.com', 'AZ' => 'https://a1.tuyaus.com', 'AY' => 'https://a1.tuyacn.com', 'IN' => 'https://a1.tuyain.com'];
    private const TUYA_CLIENT = 'yx5v9uc3ef9wg3v9atje';
    private const TUYA_APP = 's8x78u7xwymasd9kqa7a73pjhxqsedaj';
    private const TUYA_BMP = 'cepev5pfnhua4dkqkdpmnrdxx378mpjr';
    private const TUYA_PW_KEY = '244e6d8a56ac879124432d8b6cbca2c4';
    private const TUYA_PW_IV = '772456f2a7664cf3392c3597e93e5747';
    private const TUYA_SIGNIERT = ['a', 'v', 'lat', 'lon', 'lang', 'deviceId', 'appVersion', 'ttid', 'isH5', 'h5Token', 'os', 'clientId', 'postData', 'time', 'requestId', 'et', 'n4h5', 'sid', 'sp'];

    // Datenpunkte (const.py, Schema des T2880).
    private const DP_START = '1';
    private const DP_PAUSE = '2';
    private const DP_AKKU = '8';
    private const DP_LAUTSTAERKE = '26';
    private const DP_AUFTRAG = '107';
    private const DP_LADEKONTAKT = '108';
    private const DP_SIGNAL = '109';
    private const DP_SCHNITTHOEHE = '110';
    private const DP_KARTE_SPEICHERN = '118';
    private const DP_BASIS_MIN = '125';
    private const DP_MESSER_MIN = '126';

    // DP 107 Feld 1 (protocol.py).
    private const AUFTRAG_LADEN = 1;
    private const AUFTRAG_KARTE = 3;
    private const AUFTRAEGE_MAEHEN = [2, 4, 5, 7, 8, 9, 10, 16, 17, 18, 22];
    private const PHASE_KARTE_SPEICHERN = 5;
    private const STAND_RUHT = 0;
    private const STAND_LAEUFT = 1;
    private const STAND_PAUSE = 2;

    // =================================================================
    // Anmeldung
    // =================================================================

    public function anmelden(string $konto, string $passwort, string $region): array
    {
        $ips = array_values(array_filter(array_map('trim', explode(',', (string)($this->zusatz['ip'] ?? '')))));
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new RoboHubFehler('„' . $ip . '" ist keine IP-Adresse. Bitte die IP-Adresse des Mähers eintragen (am besten im Router fest vergeben).');
            }
        }
        if ($ips === []) {
            throw new RoboHubFehler('Bitte die IP-Adresse des Mähers eintragen (am besten im Router fest vergeben), „Übernehmen", dann erneut anmelden.');
        }
        $kandidaten = $this->geraeteSchluessel($konto, $passwort);
        if ($kandidaten === []) {
            throw new RoboHubFehler('In diesem Eufy-Konto ist kein Gerät mit lokalem Schlüssel. Ist der Mäher in genau diesem Konto eingerichtet (nicht nur geteilt)?');
        }
        $roboter = [];
        $erreicht = false;
        foreach ($ips as $ip) {
            foreach ($kandidaten as $did => $k) {
                if (isset($roboter[$did])) {
                    continue;
                }
                try {
                    $s = $this->sitzung($ip, (string)$k['key']);
                } catch (RoboHubFehler $e) {
                    if ($e->anmeldungNoetig || $e->getCode() === RoboHubTuyaSitzung::KEINE_ANTWORT) {
                        $erreicht = true;
                        continue;   // anderer Schlüssel: Verbindung steht, aber nicht mit diesem
                    }
                    continue 2;     // unter dieser IP antwortet nichts
                }
                $s->schliessen();
                $erreicht = true;
                $roboter[$did] = $k + ['ip' => $ip, 'geprueft' => true];
                continue 2;
            }
        }
        if ($roboter === [] && $erreicht) {
            throw new RoboHubFehler('Unter ' . implode(', ', $ips) . ' antwortet ein Gerät, aber kein Schlüssel aus diesem Konto passt. Stimmt die IP-Adresse, und ist keine andere lokale Verbindung zum Mäher offen?');
        }
        if ($roboter === []) {
            // Mäher aus oder außer WLAN-Reichweite (z. B. Winterlager): bei eindeutiger Lage trotzdem übernehmen.
            if (count($kandidaten) === 1 && count($ips) === 1) {
                $did = (string)array_key_first($kandidaten);
                $roboter[$did] = $kandidaten[$did] + ['ip' => $ips[0], 'geprueft' => false];
            } else {
                throw new RoboHubFehler('Unter ' . implode(', ', $ips) . ':' . self::PORT . ' antwortet kein Mäher. Ist er eingeschaltet und im WLAN? Die IP-Adresse steht im Router.');
            }
        }
        return ['marke' => 'eufymaeher', 'roboter' => $roboter];
    }

    public function anmeldeText(array $buendel): string
    {
        $text = '✅ Angemeldet. Passwort und Cloud-Zugang sind verworfen, gespeichert ist nur der Geräteschlüssel. Ab jetzt läuft alles lokal im Heimnetz.';
        foreach ((array)($buendel['roboter'] ?? []) as $r) {
            if (empty($r['geprueft'])) {
                $text .= "\n⚠️ Unter " . $r['ip'] . ' antwortet der Mäher gerade nicht. RoboHub versucht es weiter; ist er eingeschaltet und im WLAN, erscheint er von selbst.';
            }
        }
        return $text;
    }

    public function erneuern(array $buendel): array
    {
        if (empty($buendel['roboter'])) {
            $e = new RoboHubFehler('Noch kein Mäher angemeldet.');
            $e->anmeldungNoetig = true;
            throw $e;
        }
        return $buendel;
    }

    /** Einmaliger Weg über die Cloud (get-local-key.sh): Rückgabe devId => ['did', 'key', 'name', 'produkt']. */
    private function geraeteSchluessel(string $konto, string $passwort): array
    {
        [$status, $text] = $this->http('POST', self::EUFY_LOGIN, array_merge(self::eufyKoepfe(), ['Content-Type: application/json']),
            (string)json_encode(['client_id' => self::EUFY_ID, 'client_Secret' => self::EUFY_GEHEIM, 'email' => $konto, 'password' => $passwort]));
        if ($status === 429) {
            $e = new RoboHubFehler('Eufy bremst die Anmeldung (zu viele Versuche). Bitte später erneut versuchen.');
            $e->warteSekunden = 900;
            throw $e;
        }
        $l = json_decode($text, true);
        $token = (string)($l['access_token'] ?? '');
        $uid = (string)($l['user_info']['id'] ?? '');
        if ($token === '' || $uid === '') {
            throw new RoboHubFehler($status === 0 ? 'Eufy nicht erreichbar: ' . $text : 'Eufy lehnt die Anmeldung ab: ' . (string)($l['msg'] ?? $l['message'] ?? ('HTTP ' . $status)));
        }
        $land = (string)($l['user_info']['phone_code'] ?? '49') ?: '49';
        $basis = self::TUYA_REGION[$this->tuyaRegion($l, $token, $uid)] ?? null;
        if ($basis === null) {
            throw new RoboHubFehler('Eufy nennt eine unbekannte Tuya-Region.');
        }

        $tuyaUid = 'eh-' . $uid;
        $geraetId = '8534c8ec0ed0' . bin2hex(random_bytes(16));
        $c = $this->tuya($basis, 'tuya.m.user.uid.token.create', '1.0', (string)json_encode(['uid' => $tuyaUid, 'countryCode' => $land]), [], '', $geraetId);
        $verschl = self::rsaHex(self::tuyaPasswort($tuyaUid), (string)($c['publicKey'] ?? ''), (string)($c['exponent'] ?? ''));
        $s = $this->tuya($basis, 'tuya.m.user.uid.password.login.reg', '1.0', (string)json_encode(['uid' => $tuyaUid, 'countryCode' => $land, 'createGroup' => true,
            'ifencrypt' => 1, 'passwd' => $verschl, 'options' => '{"group": 1}', 'token' => (string)($c['token'] ?? '')]), [], '', $geraetId);
        $sid = (string)($s['sid'] ?? '');
        $mobil = (string)($s['domain']['mobileApiUrl'] ?? '');
        // Die Sitzung geht nur an Tuyas eigene Server, egal was die Antwort nennt.
        if ($sid === '' || !preg_match('#^https://[a-z0-9.-]+\.tuya(eu|us|cn|in)\.com$#i', rtrim($mobil, '/'))) {
            throw new RoboHubFehler('Tuya nennt einen unerwarteten Server — RoboHub bricht die Anmeldung ab.');
        }
        $mobil = rtrim($mobil, '/');

        $geraete = [];
        foreach ((array)$this->tuya($mobil, 'tuya.m.location.list', '2.1', '', [], $sid, $geraetId) as $heim) {
            $gid = (string)($heim['groupId'] ?? $heim['locationId'] ?? '');
            if ($gid !== '') {
                $geraete = array_merge($geraete, (array)$this->tuya($mobil, 'tuya.m.my.group.device.list', '1.0', '', ['gid' => $gid], $sid, $geraetId));
            }
        }
        $geraete = array_merge($geraete, (array)$this->tuya($mobil, 'tuya.m.my.shared.device.list', '1.0', '', [], $sid, $geraetId));
        $liste = [];
        foreach ($geraete as $g) {
            $did = (string)($g['devId'] ?? '');
            if ($did !== '' && (string)($g['localKey'] ?? '') !== '') {
                $liste[$did] = ['did' => $did, 'key' => (string)$g['localKey'], 'name' => (string)($g['name'] ?? ''), 'produkt' => (string)($g['productId'] ?? '')];
            }
        }
        return $liste;
    }

    private function tuyaRegion(array $l, string $token, string $uid): string
    {
        $host = (string)preg_replace('#^(https://[^/]+).*#', '$1', (string)($l['user_info']['request_host'] ?? 'https://api.eufylife.com'));
        // Der Eufy-Zugang geht nur an Eufys eigene Server.
        if (!preg_match('#^https://[a-z0-9.-]+\.eufylife\.com$#i', $host)) {
            $host = 'https://api.eufylife.com';
        }
        [, $text] = $this->http('GET', $host . '/v1/user/setting', array_merge(self::eufyKoepfe(), ['token: ' . $token, 'id: ' . $uid]), null);
        $d = json_decode($text, true);
        return (string)($d['setting']['home_setting']['tuya_home']['tuya_region_code'] ?? 'EU') ?: 'EU';
    }

    private static function eufyKoepfe(): array
    {
        return ['User-Agent: ' . self::EUFY_AGENT, 'category: Home', 'clientType: 2', 'language: en', 'country: DE', 'timezone: Europe/Berlin', 'openudid: robohub'];
    }

    private function tuya(string $basis, string $aktion, string $version, string $post, array $weitere, string $sid, string $geraetId)
    {
        $p = ['a' => $aktion, 'v' => $version, 'appVersion' => '2.4.0', 'clientId' => self::TUYA_CLIENT, 'deviceId' => $geraetId, 'et' => '0.0.1', 'lang' => 'en',
            'os' => 'Android', 'osSystem' => '12', 'platform' => 'robohub', 'requestId' => bin2hex(random_bytes(16)), 'sdkVersion' => '3.0.8cAnker',
            'time' => (string)time(), 'timeZoneId' => 'Europe/Berlin', 'ttid' => 'android'] + $weitere;
        if ($sid !== '') {
            $p['sid'] = $sid;
        }
        $p['sign'] = self::tuyaSignatur($p + ($post !== '' ? ['postData' => $post] : []));
        [$status, $text] = $this->http('POST', $basis . '/api.json?' . http_build_query($p, '', '&', PHP_QUERY_RFC3986),
            ['User-Agent: TY-UA=APP/Android/2.4.0/SDK/null', 'Content-Type: application/x-www-form-urlencoded'], $post !== '' ? 'postData=' . rawurlencode($post) : '');
        $d = json_decode($text, true);
        if (!is_array($d) || !array_key_exists('result', $d)) {
            throw new RoboHubFehler($status === 0 ? 'Tuya nicht erreichbar: ' . $text : 'Tuya lehnt ' . $aktion . ' ab: ' . (string)($d['errorMsg'] ?? $d['errorCode'] ?? ('HTTP ' . $status)));
        }
        return $d['result'];
    }

    /** HMAC-SHA256 über die signierten Parameter, sortiert, mit „||" verbunden; postData als umsortierter MD5. */
    public static function tuyaSignatur(array $p): string
    {
        ksort($p, SORT_STRING);
        $teile = [];
        foreach ($p as $k => $v) {
            if (in_array((string)$k, self::TUYA_SIGNIERT, true)) {
                if ($k === 'postData') {
                    $h = md5((string)$v);
                    $v = substr($h, 8, 8) . substr($h, 0, 8) . substr($h, 24, 8) . substr($h, 16, 8);
                }
                $teile[] = $k . '=' . $v;
            }
        }
        return hash_hmac('sha256', implode('||', $teile), 'A_' . self::TUYA_BMP . '_' . self::TUYA_APP);
    }

    /** Passwort des Tuya-Kontos hinter dem Eufy-Konto: aus der UID abgeleitet, nicht das Eufy-Passwort. */
    public static function tuyaPasswort(string $uid): string
    {
        $laenge = (int)(ceil(strlen($uid) / 16) * 16);
        $roh = (string)openssl_encrypt(str_pad($uid, $laenge, '0', STR_PAD_LEFT), 'aes-128-cbc', (string)hex2bin(self::TUYA_PW_KEY),
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, (string)hex2bin(self::TUYA_PW_IV));
        return md5(strtoupper(bin2hex($roh)));
    }

    /** RSA ohne Polsterung mit Modulus und Exponent als Dezimaltext. */
    public static function rsaHex(string $klartext, string $modulus, string $exponent): string
    {
        $n = self::dezimalZuBytes($modulus);
        $e = self::dezimalZuBytes($exponent);
        $n = ltrim($n, "\0");
        if ($n === '' || $e === '') {
            throw new RoboHubFehler('Tuya liefert keinen gültigen Schlüssel für die Anmeldung.');
        }
        $folge = self::derTeil("\x02", (ord($n[0]) & 0x80 ? "\0" : '') . $n) . self::derTeil("\x02", (ord($e[0]) & 0x80 ? "\0" : '') . $e);
        $algo = self::derTeil("\x30", "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $der = self::derTeil("\x30", $algo . self::derTeil("\x03", "\0" . self::derTeil("\x30", $folge)));
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $aus = '';
        if (!openssl_public_encrypt(str_pad($klartext, strlen($n), "\0", STR_PAD_LEFT), $aus, $pem, OPENSSL_NO_PADDING)) {
            throw new RoboHubFehler('Verschlüsseln für die Tuya-Anmeldung fehlgeschlagen.');
        }
        return bin2hex($aus);
    }

    private static function derTeil(string $art, string $inhalt): string
    {
        $l = strlen($inhalt);
        if ($l < 0x80) {
            return $art . chr($l) . $inhalt;
        }
        $b = ltrim(pack('N', $l), "\0");
        return $art . chr(0x80 | strlen($b)) . $b . $inhalt;
    }

    private static function dezimalZuBytes(string $dez): string
    {
        if (!ctype_digit($dez)) {
            return '';
        }
        $aus = '';
        while ($dez !== '' && $dez !== '0') {
            $rest = 0;
            $neu = '';
            foreach (str_split($dez) as $z) {
                $wert = $rest * 10 + (int)$z;
                $q = $wert >> 8;
                $rest = $wert & 0xff;
                if ($neu !== '' || $q > 0) {
                    $neu .= (string)$q;
                }
            }
            $aus = chr($rest) . $aus;
            $dez = $neu;
        }
        return $aus;
    }

    // =================================================================
    // Geräte und Zustand
    // =================================================================

    public function geraete(array $buendel): array
    {
        $liste = [];
        foreach ((array)($buendel['roboter'] ?? []) as $r) {
            $modell = self::PRODUKTE[(string)$r['produkt']] ?? '';
            $liste[] = [
                'id'     => (string)$r['did'],
                'name'   => (string)($r['name'] ?: 'Eufy ' . ($modell ?: 'Mäher')),
                'modell' => 'eufy.mower.' . strtolower($modell ?: (string)$r['produkt']),
                'klasse' => RoboHubModell::KLASSE_MAEHER,
                'online' => true,
                'roh'    => ['produkt' => $r['produkt'], 'ip' => $r['ip']],
            ];
        }
        return $liste;
    }

    private function roboter(array $buendel, array $geraet): array
    {
        $r = $buendel['roboter'][(string)$geraet['id']] ?? null;
        if (!is_array($r)) {
            throw new RoboHubFehler('Dieser Mäher ist im Konto nicht (mehr) angemeldet.');
        }
        return $r;
    }

    public function zustand(array $buendel, array $geraet): array
    {
        $r = $this->roboter($buendel, $geraet);
        try {
            $dps = $this->gesperrt($r, function () use ($r): array {
                return $this->abfragen($r);
            });
        } catch (RoboHubFehler $e) {
            if ($e->anmeldungNoetig) {
                throw $e;
            }
            // Aus oder außer WLAN-Reichweite ist bei einem Mäher normal, kein Fehlerfall.
            $this->dbg('Eufy-Mäher', $e->getMessage());
            return ['werte' => ['Zustand' => RoboHubModell::ZUSTAND_UNBEKANNT, 'ZustandRoh' => 'Keine Antwort vom Mäher (aus, außer WLAN-Reichweite oder neu gekoppelt)'], 'optionen' => self::optionen(),
                'schreibbar' => [], 'online' => false, 'bloecke' => ['ok' => [], 'fehl' => [0]], 'roh' => ['modell' => $geraet['modell'] ?? null, 'properties' => null]];
        }
        $stand = $this->merkeStand($r, $dps);
        $daten = $this->normalisiere((string)($geraet['modell'] ?? ''), $dps + $stand);
        $daten['online'] = true;
        $daten['bloecke'] = ['ok' => [0], 'fehl' => []];
        $daten['roh'] = ['modell' => $geraet['modell'] ?? null, 'properties' => $dps, 'stand' => $stand];
        return $daten;
    }

    /** Eine kurze Sitzung: Status holen, um DP 107/108 bitten, kurz auf die Meldungen warten. */
    private function abfragen(array $r): array
    {
        $s = $this->sitzung((string)$r['ip'], (string)$r['key']);
        try {
            $dps = [];
            $s->senden(0x10, '{}');
            $s->senden(0x12, '{"dpId":[107,108]}');
            $ende = microtime(true) + $this->meldungWarten;
            while (!(isset($dps[self::DP_AUFTRAG], $dps[self::DP_LADEKONTAKT], $dps[self::DP_AKKU]))) {
                $m = $s->lies($ende);
                if ($m === null) {
                    break;
                }
                $dps = self::dpsAus($m[1]) + $dps;
            }
            return $dps;
        } finally {
            $s->schliessen();
        }
    }

    /** Antworttext → Datenpunkte (je nach Befehl unter „dps" oder „data.dps"). */
    public static function dpsAus(string $inhalt): array
    {
        $d = json_decode($inhalt, true);
        if (!is_array($d)) {
            return [];
        }
        $dps = $d['dps'] ?? $d['data']['dps'] ?? [];
        return is_array($dps) ? $dps : [];
    }

    /**
     * Letzter Stand von Auftrag und Ladekontakt: DP 107/108 kommen nur als Meldung. Ein neuer Mäh-/Heimfahrt-Auftrag
     * heißt „weg von der Station", bis DP 108 etwas anderes sagt (coordinator.py _handle_dps).
     */
    private function merkeStand(array $r, array $dps): array
    {
        $datei = sys_get_temp_dir() . '/robohub-eufymaeher-' . md5((string)$r['did']) . '.json';
        $alt = json_decode((string)@file_get_contents($datei), true);
        $stand = is_array($alt) ? $alt : [];
        $auftrag = isset($dps[self::DP_AUFTRAG]) ? self::varintFelder((string)$dps[self::DP_AUFTRAG]) : null;
        if ($auftrag !== null) {
            $stand['_auftrag'] = $auftrag;
            if (in_array((int)($auftrag[1] ?? 0), self::AUFTRAEGE_MAEHEN, true) || (int)($auftrag[1] ?? 0) === self::AUFTRAG_LADEN) {
                $stand['_lader'] = false;
            }
        }
        $lade = isset($dps[self::DP_LADEKONTAKT]) ? self::varintFelder((string)$dps[self::DP_LADEKONTAKT]) : null;
        if ($lade !== null) {
            $stand['_lader'] = (bool)($lade[2] ?? 0);
        }
        $z = self::zustandAus($stand['_auftrag'] ?? null, $stand['_lader'] ?? null);
        if ($z !== null) {
            $stand['_zustand'] = $z;
        }
        @file_put_contents($datei, (string)json_encode($stand));
        @chmod($datei, 0600);
        return $stand;
    }

    /** Base64-Text mit Protobuf nur aus Varints (protocol.py); null = kaputt. „\0" und leer heißen: alles 0. */
    public static function varintFelder(string $wert): ?array
    {
        $b = base64_decode($wert, true);
        if ($b === false) {
            return null;
        }
        if ($b === '' || $b === "\0") {
            return [];
        }
        $felder = [];
        $i = 0;
        $n = strlen($b);
        while ($i < $n) {
            $tag = self::varint($b, $i);
            if ($tag === null || ($tag >> 3) === 0 || ($tag & 7) !== 0) {
                return null;
            }
            $w = self::varint($b, $i);
            if ($w === null) {
                return null;
            }
            $felder[$tag >> 3] = $w;
        }
        return $felder;
    }

    private static function varint(string $b, int &$i): ?int
    {
        $w = 0;
        for ($s = 0; $s < 63; $s += 7) {
            if ($i >= strlen($b)) {
                return null;
            }
            $c = ord($b[$i++]);
            $w |= ($c & 0x7f) << $s;
            if (($c & 0x80) === 0) {
                return $w;
            }
        }
        return null;
    }

    /** protocol.py derive_activity; null = die Daten sagen nichts, der vorige Zustand gilt weiter. */
    public static function zustandAus(?array $auftrag, ?bool $lader): ?int
    {
        if ($auftrag !== null) {
            $art = (int)($auftrag[1] ?? 0);
            $phase = (int)($auftrag[2] ?? 0);
            $stand = (int)($auftrag[3] ?? 0);
            if (!empty($auftrag[5])) {
                return RoboHubModell::ZUSTAND_FEHLER;
            }
            if (in_array($art, self::AUFTRAEGE_MAEHEN, true)) {
                if ($stand === self::STAND_PAUSE) {
                    return RoboHubModell::ZUSTAND_PAUSIERT;
                }
                if ($stand === self::STAND_LAEUFT || $stand === self::STAND_RUHT) {
                    return RoboHubModell::ZUSTAND_REINIGT;
                }
                return $lader ? RoboHubModell::ZUSTAND_GELADEN : null;   // beendet: gleich folgt die Heimfahrt
            }
            if ($art === self::AUFTRAG_LADEN && $stand !== self::STAND_PAUSE) {
                return $lader ? RoboHubModell::ZUSTAND_GELADEN : RoboHubModell::ZUSTAND_ZURUECK;
            }
            if ($art === self::AUFTRAG_KARTE && $stand === self::STAND_LAEUFT) {
                return RoboHubModell::ZUSTAND_KARTIERUNG;
            }
            if ($art === 0 && ($phase === self::PHASE_KARTE_SPEICHERN || !empty($auftrag[6])) && !$lader) {
                return null;   // Karte wird gespeichert: wohin es danach geht, ist noch offen
            }
        }
        if ($lader === null) {
            return null;
        }
        return $lader ? RoboHubModell::ZUSTAND_GELADEN : RoboHubModell::ZUSTAND_BEREIT;
    }

    public function normalisiere(string $modell, array $p): array
    {
        $w = [];
        if (is_numeric($p[self::DP_AKKU] ?? null)) {
            $w['Akku'] = (int)max(0, min(100, (int)$p[self::DP_AKKU]));
        }
        $z = isset($p['_zustand']) ? (int)$p['_zustand'] : RoboHubModell::ZUSTAND_UNBEKANNT;
        if ($z === RoboHubModell::ZUSTAND_GELADEN && isset($w['Akku']) && $w['Akku'] < 100) {
            $z = RoboHubModell::ZUSTAND_LAEDT;
        }
        $w['Zustand'] = $z;
        $a = (array)($p['_auftrag'] ?? []);
        $w['ZustandRoh'] = isset($p['_auftrag']) ? 'Auftrag ' . (int)($a[1] ?? 0) . ', Phase ' . (int)($a[2] ?? 0) . ', Stand ' . (int)($a[3] ?? 0) : 'Noch keine Meldung vom Mäher';
        $w['Fehler'] = !empty($a[5]) ? 'Der Mäher meldet ein Problem — Einzelheiten in der App' : 'kein Fehler';
        if (isset($p['_lader'])) {
            $w['Laedt'] = $z === RoboHubModell::ZUSTAND_LAEDT;
        }
        if (is_numeric($p[self::DP_MESSER_MIN] ?? null)) {
            $w['Messerlaufzeit'] = round((int)$p[self::DP_MESSER_MIN] / 60, 1);
        }
        $schreibbar = [];
        if (is_numeric($p[self::DP_LAUTSTAERKE] ?? null)) {
            $w['Lautstaerke'] = (int)$p[self::DP_LAUTSTAERKE];
            $schreibbar[] = 'Lautstaerke';
        }
        if (is_numeric($p[self::DP_SCHNITTHOEHE] ?? null)) {
            $w['Schnitthoehe'] = (int)$p[self::DP_SCHNITTHOEHE];
        }
        return ['werte' => $w, 'optionen' => self::optionen(), 'schreibbar' => $schreibbar];
    }

    /** Schnitthöhe 25–75 mm in 5er-Schritten (const.py). */
    private static function optionen(): array
    {
        $h = [];
        for ($mm = 25; $mm <= 75; $mm += 5) {
            $h[$mm] = $mm . ' mm';
        }
        return ['Schnitthoehe' => $h];
    }

    // =================================================================
    // Befehle
    // =================================================================

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        $r = $this->roboter($buendel, $geraet);
        if ($befehl === 'setze') {
            return $this->setzen($r, (string)($parameter['feld'] ?? ''), (int)($parameter['wert'] ?? -1));
        }
        if ($befehl === 'stopp') {
            throw new RoboHubFehler('Anhalten an Ort und Stelle kann der E15 im Heimnetz nicht. „Pause" hält ihn an, „Zur Station" schickt ihn heim.');
        }
        if ($befehl === 'orten') {
            throw new RoboHubFehler('Orten kann der E15 im Heimnetz nicht — bitte in der App.');
        }
        if (!in_array($befehl, ['start', 'pause', 'station'], true)) {
            throw new RoboHubFehler('Diesen Befehl kennt RoboHub für den Eufy-Mäher nicht: ' . $befehl);
        }
        return $this->gesperrt($r, function () use ($r, $befehl): string {
            $dps = $this->abfragen($r);
            $stand = $this->merkeStand($r, $dps);
            $karte = $dps[self::DP_KARTE_SPEICHERN] ?? null;
            // Während die Karte gespeichert wird, ignoriert der Mäher Befehle (lawn_mower.py).
            if (is_numeric($karte) && $karte > 0 && $karte < 100 && $befehl !== 'pause') {
                throw new RoboHubFehler('Der Mäher speichert gerade seine Karte und nimmt keine Befehle an. Bitte gleich noch einmal.');
            }
            $z = (int)($stand['_zustand'] ?? RoboHubModell::ZUSTAND_UNBEKANNT);
            if ($befehl === 'pause' && $z !== RoboHubModell::ZUSTAND_REINIGT) {
                throw new RoboHubFehler('Pause geht nur, während der Mäher mäht.');
            }
            if ($befehl === 'start') {
                return $z === RoboHubModell::ZUSTAND_PAUSIERT
                    ? $this->schreiben($r, [self::DP_PAUSE => false], 'Fortsetzen')
                    : $this->schreiben($r, [self::DP_START => true], 'Start');
            }
            return $befehl === 'pause'
                ? $this->schreiben($r, [self::DP_PAUSE => true], 'Pause')
                : $this->schreiben($r, [self::DP_START => false], 'Zur Station');
        });
    }

    private function setzen(array $r, string $feld, int $wert): string
    {
        if ($feld === 'Schnitthoehe' && isset(self::optionen()['Schnitthoehe'][$wert])) {
            $dp = [self::DP_SCHNITTHOEHE => $wert];
            $text = 'Schnitthöhe ' . $wert . ' mm';
        } elseif ($feld === 'Lautstaerke' && $wert >= 0 && $wert <= 100) {
            $dp = [self::DP_LAUTSTAERKE => $wert];
            $text = 'Lautstärke ' . $wert . ' %';
        } else {
            throw new RoboHubFehler('Diese Einstellung gibt es beim Eufy-Mäher nicht.');
        }
        return $this->gesperrt($r, function () use ($r, $dp, $text): string {
            return $this->schreiben($r, $dp, $text);
        });
    }

    private function schreiben(array $r, array $dps, string $text): string
    {
        $s = $this->sitzung((string)$r['ip'], (string)$r['key']);
        try {
            $s->senden(0x0d, (string)json_encode(['protocol' => 5, 't' => time(), 'data' => ['dps' => $dps]]));
            // Der Mäher bestätigt nur über die folgenden Meldungen; kurz lesen, damit der Rahmen nicht mit dem Schließen untergeht.
            $s->lies(microtime(true) + min(1.0, $this->meldungWarten));
        } finally {
            $s->schliessen();
        }
        return $text . ' gesendet.';
    }

    public function zuruecksetzen(array $buendel, array $geraet, string $teil): string
    {
        throw new RoboHubFehler('Verschleißteile zurücksetzen geht beim Eufy-Mäher nur in der App.');
    }

    public function karte(array $buendel, array $geraet): array
    {
        return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
    }

    public function liveKarte(array $buendel, array $geraet): array
    {
        return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null, 'weg' => [], 'roboter' => null, 'geometrie' => null];
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
    // Sitzung und Netz
    // =================================================================

    /** Nur eine lokale Sitzung je Mäher: Abruf und Befehle nacheinander. */
    private function gesperrt(array $r, callable $tun)
    {
        $sperre = 'RoboHub_EufyMaeher_' . md5((string)$r['did']);
        if (function_exists('IPS_SemaphoreEnter') && !IPS_SemaphoreEnter($sperre, 30000)) {
            throw new RoboHubFehler('Der Mäher ist gerade mit einem anderen Befehl beschäftigt — bitte gleich noch einmal.');
        }
        try {
            return $tun();
        } finally {
            if (function_exists('IPS_SemaphoreLeave')) {
                IPS_SemaphoreLeave($sperre);
            }
        }
    }

    private function sitzung(string $ip, string $schluessel): RoboHubTuyaSitzung
    {
        $s = new RoboHubTuyaSitzung($this->verbinden($ip), $schluessel);
        try {
            $s->aushandeln(microtime(true) + 4.0);
        } catch (RoboHubFehler $e) {
            $s->schliessen();
            throw $e;
        }
        return $s;
    }

    protected function verbinden(string $ip)
    {
        return RoboHubTuyaVerbindung::oeffnen($ip, self::PORT, 3.0);
    }

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

/**
 * Tuya-3.5-Sitzung: Rahmen 00006699 | 0000 | Folge | Befehl | Länge | Nonce(12) | AES-GCM | Tag(16) | 00009966,
 * zusätzlich authentifiziert sind die 14 Kopfbytes nach dem Präfix (tinytuya pack_message).
 */
final class RoboHubTuyaSitzung
{
    public const KEINE_ANTWORT = 1;
    private const VERSION_KOPF = "3.5\0\0\0\0\0\0\0\0\0\0\0\0";
    private const OHNE_KOPF = [0x03, 0x04, 0x05, 0x09, 0x0a, 0x10, 0x12];

    private $netz;
    private $geraeteSchluessel;
    private $schluessel;
    private $folge = 1;
    private $puffer = '';

    public function __construct($netz, string $geraeteSchluessel)
    {
        $this->netz = $netz;
        $this->geraeteSchluessel = $geraeteSchluessel;
        $this->schluessel = $geraeteSchluessel;
    }

    public static function rahmen(string $schluessel, int $folge, int $befehl, string $klar, ?string $nonce = null, ?int $rueckgabe = null): string
    {
        $nonce = $nonce ?? random_bytes(12);
        $roh = ($rueckgabe === null ? '' : pack('N', $rueckgabe)) . $klar;
        $kopf = pack('nNNN', 0, $folge, $befehl, strlen($roh) + 12 + 16);
        $tag = '';
        $ct = (string)openssl_encrypt($roh, 'aes-128-gcm', $schluessel, OPENSSL_RAW_DATA, $nonce, $tag, $kopf, 16);
        return "\x00\x00\x66\x99" . $kopf . $nonce . $ct . $tag . "\x00\x00\x99\x66";
    }

    /**
     * Einen Rahmen aus dem Puffer nehmen. Rückgabe [Befehl, Klartext] oder null, wenn er noch unvollständig ist;
     * der Rest bleibt im Puffer. Falscher Tag → Ausnahme, nie ungeprüfte Daten weitergeben.
     */
    public static function entnehmen(string &$puffer, string $schluessel): ?array
    {
        $p = strpos($puffer, "\x00\x00\x66\x99");
        if ($p === false) {
            $puffer = substr($puffer, -3);
            return null;
        }
        $puffer = substr($puffer, $p);
        if (strlen($puffer) < 18) {
            return null;
        }
        $h = unpack('nfrei/Nfolge/Nbefehl/Nlaenge', substr($puffer, 4, 14));
        if ($h['laenge'] < 28 || $h['laenge'] > 262144) {
            throw new RoboHubFehler('Ungültiger Rahmen vom Gerät.');
        }
        $gesamt = 18 + $h['laenge'] + 4;
        if (strlen($puffer) < $gesamt) {
            return null;
        }
        $kopf = substr($puffer, 4, 14);
        $rumpf = substr($puffer, 18, $h['laenge']);
        $puffer = (string)substr($puffer, $gesamt);
        $klar = openssl_decrypt(substr($rumpf, 12, -16), 'aes-128-gcm', $schluessel, OPENSSL_RAW_DATA, substr($rumpf, 0, 12), substr($rumpf, -16), $kopf);
        if ($klar === false) {
            throw new RoboHubFehler('Antwort des Geräts lässt sich nicht entschlüsseln.');
        }
        return [(int)$h['befehl'], $klar];
    }

    /** Schlüsselaushandlung (XenonDevice._negotiate_session_key, Version 3.5). Falscher Schlüssel → anmeldungNoetig. */
    public function aushandeln(float $ende): void
    {
        $meins = random_bytes(16);
        $this->netz->senden(self::rahmen($this->geraeteSchluessel, $this->folge++, 0x03, $meins));
        try {
            $m = $this->lies($ende, true);
        } catch (RoboHubFehler $e) {
            $this->schluesselFalsch();
        }
        if ($m === null) {
            // Verbindung steht, aber keine Antwort: falscher Schlüssel ODER schon eine andere lokale Sitzung offen.
            // Nicht unterscheidbar, deshalb kein Abmelden (das würde den Schlüssel wegwerfen).
            throw new RoboHubFehler('Der Mäher antwortet nicht auf die Schlüsselaushandlung. Ist eine andere lokale Verbindung offen, oder wurde er neu gekoppelt (dann neu anmelden)?', self::KEINE_ANTWORT);
        }
        $inhalt = $m[1];
        if ($m[0] !== 0x04 || strlen($inhalt) < 48) {
            $this->schluesselFalsch();
        }
        $seins = substr($inhalt, 0, 16);
        if (!hash_equals(hash_hmac('sha256', $meins, $this->geraeteSchluessel, true), substr($inhalt, 16, 32))) {
            $this->schluesselFalsch();
        }
        $this->netz->senden(self::rahmen($this->geraeteSchluessel, $this->folge++, 0x05, hash_hmac('sha256', $seins, $this->geraeteSchluessel, true)));
        $tag = '';
        $this->schluessel = (string)openssl_encrypt($meins ^ $seins, 'aes-128-gcm', $this->geraeteSchluessel, OPENSSL_RAW_DATA, substr($meins, 0, 12), $tag, '', 16);
    }

    private function schluesselFalsch(): void
    {
        $e = new RoboHubFehler('Der Mäher nimmt den Geräteschlüssel nicht an. Wurde er neu gekoppelt, oder ist er kein Tuya-3.5-Gerät? Dann bitte neu anmelden.');
        $e->anmeldungNoetig = true;
        throw $e;
    }

    public function senden(int $befehl, string $inhalt): void
    {
        $this->netz->senden(self::rahmen($this->schluessel, $this->folge++, $befehl, (in_array($befehl, self::OHNE_KOPF, true) ? '' : self::VERSION_KOPF) . $inhalt));
    }

    /** Nächster Rahmen mit Inhalt: [Befehl, Inhalt ohne Rückgabecode und Versionskopf]; null bei Fristende oder Verbindungsende. */
    public function lies(float $ende, bool $roh = false): ?array
    {
        while (true) {
            $m = self::entnehmen($this->puffer, $this->schluessel);
            if ($m !== null) {
                $inhalt = self::inhaltAus($m[1], $roh);
                if ($inhalt === '') {
                    continue;   // reine Empfangsbestätigung
                }
                return [$m[0], $inhalt];
            }
            $mehr = $this->netz->empfangen($ende);
            if ($mehr === null) {
                return null;
            }
            $this->puffer .= $mehr;
        }
    }

    /** Geräterahmen tragen vorn den Rückgabecode (tinytuya no_retcode=False), Antworten danach ggf. den Versionskopf. */
    public static function inhaltAus(string $klar, bool $roh): string
    {
        if ($roh) {
            return strlen($klar) >= 52 ? (string)substr($klar, 4) : $klar;
        }
        if (($klar[0] ?? '') !== '{' && strncmp($klar, '3.5', 3) !== 0) {
            $klar = (string)substr($klar, 4);
        }
        return strncmp($klar, '3.5', 3) === 0 ? (string)substr($klar, 15) : $klar;
    }

    public function schliessen(): void
    {
        $this->netz->schliessen();
    }
}

/** TCP zum Gerät. */
final class RoboHubTuyaVerbindung
{
    private $sock;

    private function __construct($sock)
    {
        $this->sock = $sock;
    }

    public static function oeffnen(string $ip, int $port, float $frist): self
    {
        $ziel = (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip) . ':' . $port;
        $nr = 0;
        $text = '';
        $sock = @stream_socket_client('tcp://' . $ziel, $nr, $text, $frist);
        if ($sock === false) {
            throw new RoboHubFehler('Mäher unter ' . $ziel . ' nicht erreichbar (' . $text . ').');
        }
        stream_set_blocking($sock, false);
        return new self($sock);
    }

    public function senden(string $daten): void
    {
        $rest = $daten;
        $ende = microtime(true) + 3.0;
        while ($rest !== '') {
            $n = @fwrite($this->sock, $rest);
            if ($n === false || ($n === 0 && microtime(true) > $ende)) {
                throw new RoboHubFehler('Verbindung zum Mäher abgerissen.');
            }
            $rest = (string)substr($rest, $n);
            if ($rest !== '') {
                usleep(20000);
            }
        }
    }

    /** Daten bis zur Frist; null bei Fristende oder geschlossener Verbindung. */
    public function empfangen(float $ende): ?string
    {
        while (microtime(true) < $ende) {
            $lesen = [$this->sock];
            $schreiben = $fehler = null;
            $warte = max(0.0, $ende - microtime(true));
            $zahl = @stream_select($lesen, $schreiben, $fehler, (int)$warte, (int)(($warte - (int)$warte) * 1000000));
            if ($zahl === false) {
                return null;
            }
            if ($zahl === 0) {
                continue;
            }
            $d = @fread($this->sock, 8192);
            if ($d !== false && $d !== '') {
                return $d;
            }
            if (feof($this->sock)) {
                return null;
            }
        }
        return null;
    }

    public function schliessen(): void
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
    }
}
