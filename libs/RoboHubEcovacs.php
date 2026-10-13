<?php

declare(strict_types=1);

// ===========================================================================
// RoboHub-Treiber für Ecovacs (App „Ecovacs Home"): Deebot-Saugroboter und
// GOAT-Mähroboter mit dem JSON-Protokoll.
//
// Quelle (nur als Protokollbeschreibung, KEIN Code übernommen, weil GPL-3.0):
// DeebotUniverse/client.py (deebot_client, Stand 10.09.2026):
// authentication.py (Anmeldung, Signatur, Gerätebestätigung),
// api_client.py (Geräteliste), command.py + commands/json/* (Befehle),
// hardware/* (Geräteklassen). Zweitquelle mrbungle64/ecovacs-deebot.js (GPL).
//
// Zugangsdaten (Dietmars Entscheidung 13.10.2026): Ecovacs kennt keinen
// Erneuerungsschlüssel, der Zugang läuft nach 7 Tagen ab und neu anmelden
// geht nur mit md5(Passwort). Deshalb bleibt der md5-Hash im Bündel
// (Konto-Attribut, nie Property/Formular/Protokoll); das Klartext-Passwort
// wird verworfen.
//
// Alle Befehle laufen per HTTP über iot/devmanager.do — keine MQTT-Sitzung.
// Noch nicht gebaut: Kartenbild, Echtzeit, Zeitpläne, Geräte mit XML-Protokoll.
// ===========================================================================

class RoboHubEcovacs implements RoboHubTreiber
{
    /** @var callable|null fn(string $thema, string $text) */
    public $debug = null;

    private const CLIENT_KEY = '1520391301804';
    private const CLIENT_SECRET = '6c319b2a5cd3e66e39159c2e28f2fce9';
    private const AUTH_CLIENT_KEY = '1520391491841';
    private const AUTH_CLIENT_SECRET = '77ef58ce3afbe337da74aa8c5ab963a9';
    private const META = ['lang' => 'EN', 'appCode' => 'global_e', 'appVersion' => '3.14.0', 'channel' => 'google_play', 'deviceType' => '1'];
    private const REALM = 'ecouser.net';
    private const USER_AGENT = 'Dalvik/2.1.0 (Linux; U; Android 5.1.1; A5010 Build/LMY48Z)';

    // Land → Kontinent der Portal-Adresse (util/continents.py, Auszug).
    private const LAENDER = [
        'DE' => ['Deutschland', 'eu'], 'AT' => ['Österreich', 'eu'], 'CH' => ['Schweiz', 'eu'], 'NL' => ['Niederlande', 'eu'],
        'BE' => ['Belgien', 'eu'], 'LU' => ['Luxemburg', 'eu'], 'FR' => ['Frankreich', 'eu'], 'IT' => ['Italien', 'eu'],
        'ES' => ['Spanien', 'eu'], 'PT' => ['Portugal', 'eu'], 'GB' => ['Vereinigtes Königreich', 'eu'], 'IE' => ['Irland', 'eu'],
        'PL' => ['Polen', 'eu'], 'CZ' => ['Tschechien', 'eu'], 'DK' => ['Dänemark', 'eu'], 'SE' => ['Schweden', 'eu'],
        'NO' => ['Norwegen', 'eu'], 'FI' => ['Finnland', 'eu'], 'US' => ['USA', 'na'], 'CA' => ['Kanada', 'na'], 'AU' => ['Australien', 'ww'],
    ];

    // Geräteklassen aus deebot_client/hardware (Stand 10.09.2026): Datentyp XML (nicht unterstützt),
    // Befehle clean_V2/getCleanInfo_V2, Mähroboter (GOAT).
    private const KLASSEN_XML = [
        '02uwxm', '2pv572', '9akc61', 'aqdd5p', 'dl8fht', 'emzppx', 'ls1ok3', 'r8ead0',
        'vsc5ia', 'y79a7u',
    ];
    private const KLASSEN_V2 = [
        '02qwum', '0jbd6s', '0jv4ti', '2ap5uq', '2i0fns', '2jdx8d', '2kuxj0', '2px96q',
        '300lc5', '36hdj8', '3gwbbm', '4bx3w9', '51rcxt', '53qyvr', '5xu9h3', '62asng',
        '6cibhb', '6n9pcz', '6q3rfp', '77atlz', '7c26ui', '7hwfxz', '7piq03', '84tx3j',
        '9bts2s', '9eamof', '9jc32i', '9kpees', 'a2ywac', 'aadham', 'aasdks', 'ao7fpw',
        'bheggm', 'buom7k', 'c4wu9j', 'c8rj4y', 'co3fyu', 'cr0e4u', 'cuoipb', 'czjwet',
        'e4gqia', 'e6ofmn', 'e6rcnf', 'edoodo', 'elrxgb', 'eom321', 'fd60kt', 'fhaf9g',
        'fmk2h4', 'fmprq4', 'gcu5tt', 'guzput', 'gwtll7', 'hu94nh', 'huhcip', 'i35yb6',
        'ilt3k8', 'itk04l', 'jp3knd', 'k8qkc7', 'kdnfi5', 'kl54s5', 'kr0277', 'lf3bn4',
        'lwmdoj', 'm17zko', 'm2nkyq', 'mezar1', 'mxse7w', 'n0vyif', 'n9f2g8', 'neiwny',
        'npwtuz', 'ns7gtw', 'nxeux7', 'nyik8q', 'o073ti', 'o4kvvk', 'p0l0af', 'p1wg05',
        'p7l7iu', 'pv7pmv', 'qcc555', 'qg1d6t', 'qhe2o2', 'qhq6i0', 'qnkybo', 're9vhz',
        's69g6z', 'ss87ia', 'udw5xh', 'uke6j4', 'ulzked', 'va8ygm', 'vihhjp', 'viq3mw',
        'vmssec', 'w7y3cb', 'wdhjmt', 'wwswjm', 'xmp9ds', 'xw7zp9', 'xztz07', 'y72606',
        'yinacl', 'yq2xhc',
    ];
    private const KLASSEN_MAEHER = [
        '0jbd6s', '2ap5uq', '2i0fns', '2px96q', '300lc5', '51rcxt', '5xu9h3', '6cibhb',
        '6n9pcz', '77atlz', '7hwfxz', '9bts2s', 'aadham', 'ao7fpw', 'cr0e4u', 'e4gqia',
        'guzput', 'itk04l', 'neiwny', 'o4kvvk', 'qhq6i0', 's69g6z', 'udw5xh', 'wwswjm', 'xmp9ds',
    ];

    private const FEHLER = [
        101 => 'Akku schwach', 102 => 'Angehoben', 103 => 'Antriebsrad gestört', 104 => 'Absturzsensoren verschmutzt',
        105 => 'Steckt fest', 106 => 'Seitenbürsten verbraucht', 107 => 'Staubbehälterfilter verbraucht',
        108 => 'Seitenbürsten verheddert', 109 => 'Hauptbürste verheddert', 110 => 'Staubbehälter fehlt',
        111 => 'Stoßsensor klemmt', 112 => 'Laser-Abstandssensor gestört', 113 => 'Hauptbürste verbraucht',
        114 => 'Staubbehälter voll', 115 => 'Akkufehler', 116 => 'Frontsensor gestört', 117 => 'Gyroskop gestört',
        118 => 'Sieb verstopft', 119 => 'Lüfterfehler', 120 => 'Wassertank gestört',
    ];

    // Verschleißteile (LifeSpan) → RoboHub-Ident.
    private const TEILE = [
        'brush' => 'Hauptbuerste', 'sideBrush' => 'Seitenbuerste', 'heap' => 'Filter', 'unitCare' => 'Wartung',
        'roundMop' => 'Mopp', 'blade' => 'Messer', 'lensBrush' => 'Reinigungsbuerste',
    ];
    private const SAUGSTUFEN = [1000 => 'Leise', 0 => 'Standard', 1 => 'Max', 2 => 'Max+'];
    private const RAUMTYPEN = [
        1 => 'Wohnzimmer', 2 => 'Esszimmer', 3 => 'Schlafzimmer', 4 => 'Arbeitszimmer', 5 => 'Küche', 6 => 'Bad',
        7 => 'Waschküche', 8 => 'Aufenthaltsraum', 9 => 'Abstellraum', 10 => 'Kinderzimmer', 11 => 'Wintergarten',
        12 => 'Flur', 13 => 'Balkon', 14 => 'Fitnessraum',
    ];

    public static function regionen(): array
    {
        $aus = [];
        foreach (self::LAENDER as $code => [$name]) {
            $aus[] = ['caption' => $name, 'value' => $code];
        }
        return $aus;
    }

    // =================================================================
    // Anmeldung
    // =================================================================

    public function anmelden(string $konto, string $passwort, string $region): array
    {
        $land = isset(self::LAENDER[strtoupper($region)]) ? strtoupper($region) : 'DE';
        $vorstufe = [
            'marke' => 'ecovacs', 'konto' => $konto, 'land' => $land,
            'pwmd5' => md5($passwort), 'geraetId' => bin2hex(random_bytes(16)),
        ];
        return $this->vollAnmelden($vorstufe);
    }

    /** Zweiter Schritt bei verlangter Gerätebestätigung: Code aus der E-Mail. */
    public function anmeldenMitCode(string $konto, string $code, array $vorstufe): array
    {
        $code = trim($code);
        if (!preg_match('/^[0-9A-Za-z]{4,8}$/', $code)) {
            throw new RoboHubFehler('Bitte den Bestätigungscode aus der E-Mail von Ecovacs eintragen.');
        }
        $antwort = $this->privat($vorstufe, 'user/verifyDevice', [
            'encryptAccount' => $this->verschluesseln($vorstufe, (string)$vorstufe['konto']),
            'backUpEmail' => '', 'verifyCode' => $code, 'model' => 'Pixel 7', 'system' => 'Android 14',
        ]);
        return $this->abschliessen($vorstufe, $antwort);
    }

    /** Bestätigungscode an die E-Mail des Kontos schicken lassen. */
    public function codeAnfordern(string $konto, array $vorstufe = []): string
    {
        if (($vorstufe['pwmd5'] ?? '') === '') {
            throw new RoboHubFehler('Ein Code ist erst nötig, wenn Ecovacs bei der Anmeldung danach fragt — bitte zuerst mit dem Passwort anmelden.');
        }
        $this->privat($vorstufe, 'user/sendEmailVerifyCode', [
            'encryptEmail' => $this->verschluesseln($vorstufe, (string)$vorstufe['konto']),
            'verifyType' => 'EMAIL_VERIFY_DEVICE', 'supportChar' => 'N', 'isForce' => 'N',
        ]);
        return 'Ecovacs hat einen Bestätigungscode an ' . $konto . ' geschickt.';
    }

    /** Ecovacs-Zugang läuft nach 7 Tagen ab: rechtzeitig mit dem gespeicherten Hash neu anmelden. */
    public function erneuern(array $buendel): array
    {
        if ((int)($buendel['expires_at'] ?? 0) - 3600 > time()) {
            return $buendel;
        }
        try {
            return $this->vollAnmelden($buendel);
        } catch (RoboHubFehler $e) {
            if ($e->vorstufe !== []) {
                $e->anmeldungNoetig = true;
            }
            throw $e;
        }
    }

    private function vollAnmelden(array $vorstufe): array
    {
        $antwort = $this->privat($vorstufe, 'user/login', ['account' => $vorstufe['konto'], 'password' => $vorstufe['pwmd5']]);
        return $this->abschliessen($vorstufe, $antwort);
    }

    private function abschliessen(array $vorstufe, $antwort): array
    {
        if (!is_array($antwort) || !isset($antwort['uid'], $antwort['accessToken'])) {
            throw new RoboHubFehler('Ecovacs-Anmeldung ohne Zugangsdaten.');
        }
        $uid = (string)$antwort['uid'];
        $code = $this->signiertGet(self::authUrl($vorstufe) . '/v1/global/auth/getAuthCode', [
            'uid' => $uid, 'accessToken' => (string)$antwort['accessToken'], 'bizType' => 'ECOVACS_IOT',
            'deviceId' => $vorstufe['geraetId'], 'authTimespan' => (int)(microtime(true) * 1000),
        ], ['openId' => 'global'], self::AUTH_CLIENT_KEY, self::AUTH_CLIENT_SECRET);
        if (!is_array($code) || ($code['authCode'] ?? '') === '') {
            throw new RoboHubFehler('Ecovacs hat keinen Anmeldecode geliefert.');
        }
        $token = null;
        for ($i = 0; $i < 3; $i++) {
            $r = $this->portal($vorstufe, 'users/user.do', [
                'edition' => 'ECOGLOBLE', 'userId' => $uid, 'token' => (string)$code['authCode'], 'realm' => self::REALM,
                'resource' => $vorstufe['geraetId'], 'org' => 'ECOWW', 'last' => '', 'country' => self::landEcovacs($vorstufe),
                'todo' => 'loginByItToken',
            ], null);
            if (($r['result'] ?? '') === 'ok') {
                $token = $r;
                break;
            }
            if (($r['error'] ?? '') !== 'set token error.') {
                throw new RoboHubFehler('Ecovacs-Anmeldung fehlgeschlagen: ' . (string)($r['error'] ?? 'unbekannt'));
            }
        }
        if ($token === null) {
            throw new RoboHubFehler('Ecovacs-Anmeldung fehlgeschlagen (Token nicht gesetzt).');
        }
        $gueltig = is_numeric($token['last'] ?? null) ? (int)$token['last'] / 1000 : 604800;
        return [
            'marke' => 'ecovacs', 'konto' => $vorstufe['konto'], 'land' => $vorstufe['land'],
            'pwmd5' => $vorstufe['pwmd5'], 'geraetId' => $vorstufe['geraetId'],
            'userId' => (string)($token['userId'] ?? $uid), 'token' => (string)$token['token'],
            'expires_at' => time() + (int)($gueltig * 0.99),
        ];
    }

    /** Signierter Aufruf der privaten Anmelde-API (GET mit authSign). */
    private function privat(array $vorstufe, string $endpunkt, array $params)
    {
        $meta = self::META + ['country' => strtolower(self::landEcovacs($vorstufe)), 'deviceId' => (string)$vorstufe['geraetId']];
        $pfad = '/v1/private/' . $meta['country'] . '/' . $meta['lang'] . '/' . $meta['deviceId'] . '/' . $meta['appCode'] . '/'
            . $meta['appVersion'] . '/' . $meta['channel'] . '/' . $meta['deviceType'] . '/' . $endpunkt;
        $params += ['requestId' => md5((string)microtime(true)), 'authTimespan' => (int)(microtime(true) * 1000), 'authTimeZone' => 'GMT-8'];
        return $this->signiertGet(self::loginUrl($vorstufe) . $pfad, $params, $meta, self::CLIENT_KEY, self::CLIENT_SECRET, $vorstufe);
    }

    /** authSign = md5(Schlüssel + sortierte „k=v" aus Zusatz- und Aufrufparametern + Geheimnis). */
    public static function signieren(array $params, array $zusatz, string $schluessel, string $geheimnis): array
    {
        $alle = array_merge($zusatz, $params);
        ksort($alle, SORT_STRING);
        $text = $schluessel;
        foreach ($alle as $k => $v) {
            $text .= $k . '=' . $v;
        }
        $params['authSign'] = md5($text . $geheimnis);
        $params['authAppkey'] = $schluessel;
        return $params;
    }

    private function signiertGet(string $url, array $params, array $zusatz, string $schluessel, string $geheimnis, array $vorstufe = [])
    {
        $url .= '?' . http_build_query(self::signieren($params, $zusatz, $schluessel, $geheimnis));
        [$status, $text] = $this->http('GET', $url, ['Accept: application/json'], null);
        $this->dbg('HTTP', 'GET ' . preg_replace('/\?.*/', '', $url) . ' → ' . $status);
        $j = $this->pruefeHttp($status, $text);
        $code = (string)($j['code'] ?? '');
        if ($code === '0000') {
            return $j['data'] ?? null;
        }
        if ($code === '1005' || $code === '1010') {
            $e = new RoboHubFehler('Ecovacs lehnt die Anmeldung ab — E-Mail/Passwort und Land prüfen.');
            $e->anmeldungNoetig = true;
            throw $e;
        }
        if ($code === '1012') {
            throw new RoboHubFehler('Der Bestätigungscode ist falsch oder abgelaufen — neuen Code anfordern.');
        }
        if ($code === '1013') {
            $e = new RoboHubFehler('Ecovacs verlangt eine Bestätigung dieses Zugangs per E-Mail: „📧 Code anfordern", den Code ins Passwortfeld eintragen, „Übernehmen", „🔑 Jetzt anmelden".');
            $e->vorstufe = $vorstufe;
            throw $e;
        }
        throw new RoboHubFehler('Ecovacs-Anmeldung: ' . (string)($j['msg'] ?? 'Fehler') . ' (Code ' . $code . ')');
    }

    /** Konto per RSA (öffentlicher Schlüssel aus common/getConfig, PKCS#1 v1.5) verschlüsseln. */
    private function verschluesseln(array $vorstufe, string $text): string
    {
        $config = $this->privat($vorstufe, 'common/getConfig', ['keys' => 'PUBLIC.KEY.CONFIG']);
        $der = null;
        foreach ((array)$config as $eintrag) {
            if (is_array($eintrag) && ($eintrag['key'] ?? '') === 'PUBLIC.KEY.CONFIG' && is_string($eintrag['value'] ?? null)) {
                $der = json_decode($eintrag['value'], true)['publicKey'] ?? null;
            }
        }
        if (!is_string($der) || base64_decode($der, true) === false) {
            throw new RoboHubFehler('Öffentlicher Schlüssel von Ecovacs fehlt.');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($der, 64, "\n") . "-----END PUBLIC KEY-----\n";
        $aus = '';
        if (!openssl_public_encrypt($text, $aus, $pem, OPENSSL_PKCS1_PADDING)) {
            throw new RoboHubFehler('Öffentlicher Schlüssel von Ecovacs ist ungültig.');
        }
        return base64_encode($aus);
    }

    private static function landEcovacs(array $b): string
    {
        return $b['land'] === 'GB' ? 'UK' : (string)$b['land'];
    }

    private static function loginUrl(array $b): string
    {
        return 'https://gl-' . strtolower(self::landEcovacs($b)) . '-api.ecovacs.com';
    }

    private static function authUrl(array $b): string
    {
        return 'https://gl-' . strtolower(self::landEcovacs($b)) . '-openapi.ecovacs.com';
    }

    private static function portalUrl(array $b): string
    {
        return 'https://portal-' . (self::LAENDER[$b['land']][1] ?? 'eu') . '.ecouser.net/api/';
    }

    // =================================================================
    // Geräte
    // =================================================================

    public function geraete(array $buendel): array
    {
        $roh = [];
        foreach ([['users/user.do', 'GetDeviceList'], ['appsvr/app.do', 'GetGlobalDeviceList']] as [$pfad, $todo]) {
            $r = $this->portal($buendel, $pfad, ['userid' => $buendel['userId'], 'todo' => $todo], $buendel);
            foreach ((array)($r['devices'] ?? []) as $g) {
                if (is_array($g) && isset($g['did'])) {
                    $roh[(string)$g['did']] = $g;
                }
            }
        }
        $liste = [];
        foreach ($roh as $did => $g) {
            $klasse = (string)($g['class'] ?? '');
            $name = (string)($g['nick'] ?? '') !== '' ? (string)$g['nick'] : (string)($g['deviceName'] ?? 'Ecovacs');
            $liste[] = [
                'id'        => $did,
                'name'      => $name,
                'modell'    => 'ecovacs.' . (self::istMaeher($klasse, (string)($g['deviceName'] ?? '')) ? 'mower' : 'vacuum') . '.' . $klasse,
                'klasse'    => self::istMaeher($klasse, (string)($g['deviceName'] ?? '')) ? RoboHubModell::KLASSE_MAEHER : RoboHubModell::KLASSE_SAUGER,
                'online'    => !isset($g['status']) || (int)$g['status'] === 1,
                'ressource' => (string)($g['resource'] ?? ''),
                'geraetKlasse' => $klasse,
                'firma'     => (string)($g['company'] ?? ''),
                'bild'      => is_string($g['icon'] ?? null) && strpos($g['icon'], 'https://') === 0 ? $g['icon'] : '',
                'roh'       => ['class' => $klasse, 'company' => $g['company'] ?? null, 'deviceName' => $g['deviceName'] ?? null],
            ];
        }
        return $liste;
    }

    public static function istMaeher(string $klasse, string $geraetName): bool
    {
        return in_array($klasse, self::KLASSEN_MAEHER, true) || stripos($geraetName, 'GOAT') !== false;
    }

    // =================================================================
    // Zustand
    // =================================================================

    public function zustand(array $buendel, array $geraet): array
    {
        $this->pruefeGeraet($geraet);
        $maeher = ($geraet['klasse'] ?? '') === RoboHubModell::KLASSE_MAEHER;
        $v2 = self::nutztV2((string)$geraet['geraetKlasse']);
        $abfragen = [
            'battery' => ['getBattery', []],
            'charge'  => ['getChargeState', []],
            'clean'   => [$v2 ? 'getCleanInfo_V2' : 'getCleanInfo', []],
            'error'   => ['getError', []],
            'stats'   => ['getTotalStats', []],
            'volume'  => ['getVolume', []],
            'life'    => ['getLifeSpan', $maeher ? ['blade', 'lensBrush'] : ['brush', 'sideBrush', 'heap']],
        ];
        if (!$maeher) {
            $abfragen['speed'] = ['getSpeed', []];
            $abfragen['life2'] = ['getLifeSpan', ['unitCare', 'roundMop']];
        }
        $p = [];
        $ok = [];
        $fehl = [];
        $i = 0;
        foreach ($abfragen as $schluessel => [$befehl, $daten]) {
            try {
                $p[$schluessel] = $this->befehlRoh($buendel, $geraet, $befehl, $daten);
                $ok[] = $i;
            } catch (RoboHubFehler $e) {
                if ($e->anmeldungNoetig || $e->warteSekunden > 0 || $e->getCode() === 4200) {
                    throw $e;
                }
                $fehl[] = $i;
            }
            $i++;
        }
        if (count($ok) === 0) {
            throw new RoboHubFehler('Der Ecovacs-Roboter antwortet nicht (offline?).');
        }
        if (isset($p['life2']) && is_array($p['life2'])) {
            $p['life'] = array_merge((array)($p['life'] ?? []), $p['life2']);
            unset($p['life2']);
        }
        $daten = $this->normalisiere((string)($geraet['modell'] ?? ''), $p);
        $daten['online'] = true;
        if (($geraet['bild'] ?? '') !== '') {
            $daten['bild'] = (string)$geraet['bild'];
        }
        $daten['bloecke'] = ['ok' => $ok, 'fehl' => $fehl];
        $daten['roh'] = ['modell' => $geraet['modell'] ?? null, 'klasse' => $geraet['geraetKlasse'] ?? null, 'properties' => $p];
        return $daten;
    }

    public function normalisiere(string $modell, array $p): array
    {
        $w = [];
        $optionen = [];
        $laedt = isset($p['charge']['isCharging']) ? (int)$p['charge']['isCharging'] === 1 : null;
        $akku = isset($p['battery']['value']) ? (int)$p['battery']['value'] : null;
        $fehler = 0;
        if (isset($p['error']['code']) && is_array($p['error']['code'])) {
            $codes = $p['error']['code'];
            $fehler = $codes === [] ? 0 : (int)end($codes);
            $w['Fehler'] = in_array($fehler, [0, 100], true) ? 'kein Fehler' : (self::FEHLER[$fehler] ?? 'Fehlercode') . ' (' . $fehler . ')';
        }
        if (isset($p['clean']) && is_array($p['clean'])) {
            [$zustand, $text] = self::zustandAus($p['clean'], $laedt, $akku);
            if (!in_array($fehler, [0, 100], true) && $zustand !== RoboHubModell::ZUSTAND_REINIGT) {
                $zustand = RoboHubModell::ZUSTAND_FEHLER;
            }
            $w['Zustand'] = $zustand;
            $w['ZustandRoh'] = $text;
        }
        $w['Akku'] = $akku;
        $w['Laedt'] = $laedt;
        if (isset($p['speed']['speed']) && is_numeric($p['speed']['speed'])) {
            $stufe = (int)$p['speed']['speed'];
            $liste = self::SAUGSTUFEN;
            if (!isset($liste[$stufe])) {
                $liste[$stufe] = 'Stufe ' . $stufe;
            }
            $w['Saugstufe'] = $stufe;
            $optionen['Saugstufe'] = $liste;
        }
        foreach ((array)($p['life'] ?? []) as $teil) {
            $ident = self::TEILE[(string)($teil['type'] ?? '')] ?? null;
            $total = (int)($teil['total'] ?? 0);
            if ($ident !== null && $total > 0 && isset($teil['left'])) {
                $w[$ident] = (int)max(0, min(100, round((int)$teil['left'] * 100 / $total)));
            }
        }
        if (isset($p['stats']) && is_array($p['stats'])) {
            $w['GesamtFlaeche'] = is_numeric($p['stats']['area'] ?? null) ? (int)$p['stats']['area'] : null;
            $w['GesamtDauer'] = is_numeric($p['stats']['time'] ?? null) ? (int)round((int)$p['stats']['time'] / 3600) : null;
            $w['GesamtAnzahl'] = is_numeric($p['stats']['count'] ?? null) ? (int)$p['stats']['count'] : null;
        }
        $schreibbar = [];
        if (isset($p['volume']['volume']) && is_numeric($p['volume']['volume'])) {
            $gesamt = max(1, (int)($p['volume']['total'] ?? 10));
            $w['Lautstaerke'] = (int)round((int)$p['volume']['volume'] * 100 / $gesamt);
            $schreibbar[] = 'Lautstaerke';
        }
        return ['werte' => $w, 'optionen' => $optionen, 'schreibbar' => $schreibbar];
    }

    /** getCleanInfo(_V2): state/cleanState.motionState (clean.py GetCleanInfo). */
    private static function zustandAus(array $c, ?bool $laedt, ?int $akku): array
    {
        $state = (string)($c['state'] ?? '');
        if (($c['trigger'] ?? '') === 'alert') {
            return [RoboHubModell::ZUSTAND_FEHLER, 'Alarm'];
        }
        if ($state === 'clean' || $state === 'washing') {
            $bewegung = (string)($c['cleanState']['motionState'] ?? '');
            if ($bewegung === 'pause') {
                return [RoboHubModell::ZUSTAND_PAUSIERT, 'Pausiert'];
            }
            if ($bewegung === 'goCharging') {
                return [RoboHubModell::ZUSTAND_ZURUECK, 'Fährt zur Station'];
            }
            return [RoboHubModell::ZUSTAND_REINIGT, $state === 'washing' ? 'Wäscht' : 'Arbeitet'];
        }
        if ($state === 'goCharging') {
            return [RoboHubModell::ZUSTAND_ZURUECK, 'Fährt zur Station'];
        }
        if ($laedt) {
            return ($akku !== null && $akku >= 100) ? [RoboHubModell::ZUSTAND_GELADEN, 'In der Station'] : [RoboHubModell::ZUSTAND_LAEDT, 'Lädt'];
        }
        if ($state === 'idle') {
            return [RoboHubModell::ZUSTAND_BEREIT, 'Bereit'];
        }
        return [RoboHubModell::ZUSTAND_UNBEKANNT, $state !== '' ? 'Zustand „' . $state . '"' : 'Unbekannt'];
    }

    // =================================================================
    // Räume
    // =================================================================

    public function karte(array $buendel, array $geraet): array
    {
        $this->pruefeGeraet($geraet);
        $leer = ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
        if (($geraet['klasse'] ?? '') === RoboHubModell::KLASSE_MAEHER) {
            return $leer;
        }
        $info = $this->befehlRoh($buendel, $geraet, 'getCachedMapInfo', []);
        $mid = null;
        foreach ((array)($info['info'] ?? []) as $karte) {
            if ((int)($karte['using'] ?? 0) === 1) {
                $mid = (string)$karte['mid'];
            }
        }
        if ($mid === null) {
            return $leer;
        }
        try {
            $set = $this->befehlRoh($buendel, $geraet, 'getMapSet', ['mid' => $mid, 'type' => 'ar']);
        } catch (RoboHubFehler $e) {
            $this->dbg('Karte', 'getMapSet abgelehnt (' . $e->getMessage() . ') — Raumliste dieses Modells noch nicht lesbar.');
            return $leer;
        }
        if (!is_array($set['subsets'] ?? null)) {
            $this->dbg('Karte', 'Raumliste komprimiert oder leer — noch nicht unterstützt.');
            return $leer;
        }
        $raeume = [];
        foreach ($set['subsets'] as $s) {
            $id = (int)($s['mssid'] ?? -1);
            if ($id < 0) {
                continue;
            }
            $name = trim((string)($s['name'] ?? ''));
            if ($name === '') {
                try {
                    $teil = $this->befehlRoh($buendel, $geraet, 'getMapSubSet', ['mid' => $mid, 'msid' => (string)($set['msid'] ?? ''), 'type' => 'ar', 'mssid' => (string)$id]);
                    $name = trim((string)($teil['name'] ?? ''));
                    if ($name === '') {
                        $name = self::RAUMTYPEN[(int)($teil['subtype'] ?? $teil['subType'] ?? 0)] ?? '';
                    }
                } catch (RoboHubFehler $e) {
                    $name = '';
                }
            }
            $raeume[$id] = $name !== '' ? $name : 'Raum ' . $id;
        }
        ksort($raeume);
        return ['raeume' => $raeume, 'bild' => '', 'marken' => [], 'station' => null];
    }

    public function liveKarte(array $buendel, array $geraet): array
    {
        return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
    }

    // =================================================================
    // Befehle
    // =================================================================

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        $this->pruefeGeraet($geraet);
        $v2 = self::nutztV2((string)$geraet['geraetKlasse']);
        $name = $v2 ? 'clean_V2' : 'clean';
        switch ($befehl) {
            case 'start':
                $pausiert = (string)($geraet['stand']['clean']['cleanState']['motionState'] ?? '') === 'pause';
                $this->befehlRoh($buendel, $geraet, $name, self::reinigung($pausiert ? 'resume' : 'start', $v2));
                return 'Befehl „Start" gesendet.';
            case 'pause':
                $this->befehlRoh($buendel, $geraet, $name, self::reinigung('pause', $v2));
                return 'Befehl „Pause" gesendet.';
            case 'stopp':
                $this->befehlRoh($buendel, $geraet, $name, self::reinigung('stop', $v2));
                return 'Befehl „Stopp" gesendet.';
            case 'station':
                $this->befehlRoh($buendel, $geraet, 'charge', ['act' => 'go']);
                return 'Befehl „Zur Station" gesendet.';
            case 'orten':
                $this->befehlRoh($buendel, $geraet, 'playSound', ['count' => 1, 'sid' => 30]);
                return 'Befehl „Orten" gesendet.';
            case 'raum':
                return $this->raumReinigung($buendel, $geraet, $parameter, $v2);
            case 'setze':
                if ((string)($parameter['feld'] ?? '') === 'Saugstufe' && isset(self::SAUGSTUFEN[(int)($parameter['wert'] ?? -1)])) {
                    $this->befehlRoh($buendel, $geraet, 'setSpeed', ['speed' => (int)$parameter['wert']]);
                    return 'Saugstufe gesetzt.';
                }
                throw new RoboHubFehler('Dieser Wert lässt sich bei Ecovacs (noch) nicht setzen.');
            case 'einstellung':
                if ((string)($parameter['feld'] ?? '') === 'Lautstaerke') {
                    $p = (int)($parameter['wert'] ?? -1);
                    if ($p < 0 || $p > 100) {
                        throw new RoboHubFehler('Lautstärke 0 bis 100.');
                    }
                    $gesamt = max(1, (int)($geraet['stand']['volume']['total'] ?? 10));
                    $this->befehlRoh($buendel, $geraet, 'setVolume', ['volume' => (int)round($p * $gesamt / 100)]);
                    return 'Lautstärke gesetzt.';
                }
                throw new RoboHubFehler('Unbekannte Einstellung.');
        }
        throw new RoboHubFehler('Dieser Befehl wird für Ecovacs noch nicht unterstützt: ' . $befehl);
    }

    /** Argumente für clean / clean_V2 (clean.py Clean._get_args / CleanV2._get_args). */
    public static function reinigung(string $aktion, bool $v2): array
    {
        if (!$v2) {
            return $aktion === 'start' ? ['act' => 'start', 'type' => 'auto'] : ['act' => $aktion];
        }
        $inhalt = [];
        if ($aktion === 'start') {
            $inhalt['type'] = 'auto';
        } elseif ($aktion === 'stop' || $aktion === 'pause') {
            $inhalt['type'] = '';
        }
        return ['act' => $aktion, 'content' => (object)$inhalt];
    }

    private function raumReinigung(array $buendel, array $geraet, array $parameter, bool $v2): string
    {
        $raeume = array_values(array_unique(array_filter(array_map('intval', (array)($parameter['raeume'] ?? [])), function (int $r): bool {
            return $r >= 0;
        })));
        if (count($raeume) === 0) {
            throw new RoboHubFehler('Keine Raum-ID angegeben.');
        }
        $saug = (int)($parameter['saugstufe'] ?? -1);
        if ($saug >= 0 && isset(self::SAUGSTUFEN[$saug])) {
            $this->befehlRoh($buendel, $geraet, 'setSpeed', ['speed' => $saug]);
        }
        $anzahl = (int)($parameter['durchgaenge'] ?? -1);
        $anzahl = $anzahl >= 1 && $anzahl <= 3 ? $anzahl : 1;
        $liste = implode(',', $raeume);
        $args = $v2
            ? ['act' => 'start', 'content' => ['type' => 'spotArea', 'value' => $liste]]
            : ['act' => 'start', 'type' => 'spotArea', 'content' => $liste, 'count' => $anzahl];
        if ($v2 && $anzahl > 1) {
            $this->dbg('Befehl', 'Durchgänge bei clean_V2 nicht übertragbar — einmal gereinigt.');
        }
        $this->befehlRoh($buendel, $geraet, $v2 ? 'clean_V2' : 'clean', $args);
        return 'Reinigung ' . (count($raeume) === 1 ? 'eines Raums' : 'von ' . count($raeume) . ' Räumen') . ' gesendet.';
    }

    public function zuruecksetzen(array $buendel, array $geraet, string $teil): string
    {
        $this->pruefeGeraet($geraet);
        $typ = array_search($teil, self::TEILE, true);
        if ($typ === false) {
            throw new RoboHubFehler('Dieses Teil lässt sich bei Ecovacs nicht zurücksetzen: ' . $teil);
        }
        $this->befehlRoh($buendel, $geraet, 'resetLifeSpan', ['type' => $typ]);
        return 'Zurücksetzen gesendet.';
    }

    // =================================================================
    // Echtzeit: noch nicht gebaut
    // =================================================================

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
    // Gerätebefehl über iot/devmanager.do
    // =================================================================

    public static function nutztV2(string $klasse): bool
    {
        return in_array($klasse, self::KLASSEN_V2, true);
    }

    private function pruefeGeraet(array $geraet): void
    {
        if (in_array((string)($geraet['geraetKlasse'] ?? ''), self::KLASSEN_XML, true) || ($geraet['firma'] ?? 'eco-ng') === 'eco-legacy') {
            throw new RoboHubFehler('Dieses Ecovacs-Modell spricht das ältere XML-Protokoll, das RoboHub noch nicht unterstützt.');
        }
        if (($geraet['ressource'] ?? '') === '' || ($geraet['geraetKlasse'] ?? '') === '') {
            throw new RoboHubFehler('Gerätedaten unvollständig — bitte in der Konto-Instanz „Geräte jetzt suchen".');
        }
    }

    /** JSON-Befehl senden; Rückgabe: body.data (oder [] ohne Daten). */
    private function befehlRoh(array $buendel, array $geraet, string $befehl, array $daten)
    {
        $nutzlast = ['header' => ['pri' => '1', 'ts' => (string)microtime(true), 'tzm' => 480, 'ver' => '0.0.50']];
        if ($daten !== []) {
            $nutzlast['body'] = ['data' => $daten];
        }
        $anfrage = [
            'cmdName' => $befehl, 'payload' => $nutzlast, 'payloadType' => 'j', 'td' => 'q',
            'toId' => (string)$geraet['id'], 'toRes' => (string)$geraet['ressource'], 'toType' => (string)$geraet['geraetKlasse'],
        ];
        $query = ['mid' => $anfrage['toType'], 'did' => $anfrage['toId'], 'td' => 'q', 'u' => $buendel['userId'], 'cv' => '1.67.3', 't' => 'a', 'av' => '1.3.1'];
        $r = $this->portal($buendel, 'iot/devmanager.do', $anfrage, $buendel, $query);
        if (($r['ret'] ?? '') !== 'ok') {
            $errno = (int)($r['errno'] ?? 0);
            if ($errno === 4200) {
                throw new RoboHubFehler('Der Ecovacs-Roboter ist offline.', 4200);
            }
            if ($errno === 3 || in_array((string)($r['error'] ?? ''), ['auth error', 'token error'], true)) {
                $e = new RoboHubFehler('Ecovacs-Zugang abgelehnt — bitte neu anmelden.');
                $e->anmeldungNoetig = true;
                throw $e;
            }
            throw new RoboHubFehler('„' . $befehl . '" ohne Antwort' . ($errno === 500 ? ' (Gerät antwortet nicht oder kennt den Befehl nicht)' : ' (' . $errno . ')') . '.');
        }
        $body = $r['resp']['body'] ?? $r['resp'] ?? [];
        $code = $body['code'] ?? 0;
        if ((string)$code !== '0' && (string)$code !== '30007') {
            throw new RoboHubFehler('Der Roboter lehnt „' . $befehl . '" ab: ' . (string)($body['msg'] ?? 'Code ' . $code));
        }
        return $body['data'] ?? [];
    }

    /** POST an das Portal; mit Bündel wird der auth-Block angehängt. */
    private function portal(array $basis, string $pfad, array $json, ?array $buendel, array $query = []): array
    {
        if ($buendel !== null) {
            $json['auth'] = ['with' => 'users', 'userid' => $buendel['userId'], 'realm' => self::REALM, 'token' => $buendel['token'], 'resource' => $buendel['geraetId']];
        }
        $url = self::portalUrl($basis) . $pfad . ($query !== [] ? '?' . http_build_query($query) : '');
        [$status, $text] = $this->http('POST', $url, ['Content-Type: application/json', 'User-Agent: ' . self::USER_AGENT], (string)json_encode($json));
        $this->dbg('HTTP', 'POST ' . preg_replace('/\?.*/', '', $url) . ' ' . ($json['cmdName'] ?? $json['todo'] ?? '') . ' → ' . $status);
        return $this->pruefeHttp($status, $text);
    }

    private function pruefeHttp(int $status, string $text): array
    {
        if ($status === 0) {
            throw new RoboHubFehler('Ecovacs nicht erreichbar: ' . $text);
        }
        if ($status === 429) {
            $e = new RoboHubFehler('Ecovacs bremst gerade (zu viele Anfragen).');
            $e->warteSekunden = 600;
            throw $e;
        }
        $j = json_decode($text, true);
        if ($status >= 300 || !is_array($j)) {
            throw new RoboHubFehler('Ecovacs antwortet mit HTTP ' . $status . '.');
        }
        return $j;
    }

    /** curl wie die anderen Treiber; im Prüfstand ersetzbar. */
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
