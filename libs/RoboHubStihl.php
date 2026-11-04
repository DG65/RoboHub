<?php

declare(strict_types=1);

// ===========================================================================
// RoboHub-Treiber für STIHL iMOW — beide Cloud-Generationen in einem Konto.
//
// Neue Generation (iMOW 5/6/7, auch EVO; App „iMOW", myimow.stihl.com):
//   Quelle simontims/ha-imow-v2 (MIT, Stand 16.10.2026: const.py, auth.py, api.py,
//   coordinator.py, sensor.py, lawn_mower.py), Endpunkte bestätigt durch
//   TA2k/ioBroker.imow (MIT, Typ „myimow"). Anmeldung STIHL-ID (Azure B2C,
//   PKCE, offline_access): E-Mail/Passwort einmal, danach Erneuerungsschlüssel.
//
// Ältere RMI-Serie (bis Gen. 4; app.imow.stihl.com):
//   Quelle ChrisHaPunkt/stihl-imow-webapi (MIT, Stand 15.07.2026: api/__init__.py,
//   common/messages.py — daraus die Zustands-/Fehlertabelle mit deutschen Texten),
//   ioBroker.imow (Typ „imow"). Impliziter OAuth-Ablauf mit Formular-Anmeldung,
//   Zugang 30 Tage, KEIN Erneuerungsschlüssel. Dietmars Entscheidung 26.10.2026:
//   Passwort wird nicht gespeichert — nach 30 Tagen neu anmelden, RoboHub warnt
//   drei Tage vorher (Variable „Letzte Meldung").
//
// Erkennung: Anmeldung erst neue Generation; lehnt STIHL dort ab oder hängt kein
// Mäher am Konto, mit denselben Daten die RMI-Anmeldung.
// Nicht gebaut: Echtzeit (SignalR „deviceevents"), Position, Mähplan, Schnitthöhe,
// RMI-Startpunkte/Kantenschnitt.
// ===========================================================================

class RoboHubStihl implements RoboHubTreiber
{
    /** @var callable|null fn(string $thema, string $text) */
    public $debug = null;

    // --- Neue Generation (const.py) ---
    private const B2C = 'https://login.stihl.com/stihlidproduction.onmicrosoft.com/';
    private const B2C_POLICY = 'b2c_1a_production_flow_signin';
    private const B2C_POLICY_GROSS = 'B2C_1A_production_Flow_SignIn';
    private const B2C_CLIENT = '0d947284-c186-454e-96fd-0094f4510b3f';
    private const B2C_REDIRECT = 'imow://www.imow.com/welcome/login';
    private const B2C_SCOPE = 'offline_access https://login.stihl.com/scopes/profile openid';
    private const APIM_KEY = '52659909060946fc88f2d3368c16d9c7';
    private const API = 'https://apim.stihl.cloud/imow/p/';
    private const FEHLERTEXTE = 'https://myimow.stihl.com/app-i18n-messages/de-DE.json';
    private const AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1';

    // --- RMI-Serie (consts.py) ---
    private const RMI_OAUTH = 'https://oauth2.imow.stihl.com';
    private const RMI_API = 'https://api.imow.stihl.com/';
    private const RMI_APP = 'https://app.imow.stihl.com';
    private const RMI_CLIENT = '9526273B-1477-47C6-801C-4356F58EF883';
    private const RMI_WARNTAGE = 3;

    // overallState → Gruppe wie die myimow-App (const.py OVERALL_STATE_GROUPS).
    private const GRUPPEN = [
        'docked'    => ['docked', 'dockedBatteryWithJob', 'dockedNoJob', 'dockedPrepareMowing', 'dockedRainWithJob', 'cleaningDockConnection',
            'waitLearningStartingPoints', 'TeamSetupAtBase', 'TeamSetupAtMain'],
        'charging'  => ['charging', 'chargingBatteryWithJob', 'chargingNoJob', 'chargingRainWithJob'],
        'returning' => ['drivingHome', 'drivingHomeBattery', 'drivingHomeMowplan', 'drivingHomeRain'],
        'driving'   => ['drivingToZone', 'learningStartingPoints', 'completingLearningStartingPoints', 'pausedOrIdlePrepareMowing'],
        'mowing'    => ['mowing'],
        'paused'    => ['pausedOrIdle', 'parkedInSideArea', 'parkedInSideAreaBattery', 'parkedInSideAreaMowplan', 'parkedInSideAreaRain'],
        'error'     => ['error', 'invalid', 'blockedLearningStartingPoints', 'selfTesting', 'stopped'],
        'locked'    => ['beingCarried', 'locked', 'outsideGeofence'],
        'offline'   => ['offline', 'resettingSecretData'],
        'sleeping'  => ['sleeping'],
        'busy'      => ['internalWorkInProgress'],
    ];
    private const GRUPPE_ZUSTAND = [
        'docked' => [RoboHubModell::ZUSTAND_GELADEN, 'Docked'], 'charging' => [RoboHubModell::ZUSTAND_LAEDT, 'Charging'],
        'returning' => [RoboHubModell::ZUSTAND_ZURUECK, 'Returning to base'], 'driving' => [RoboHubModell::ZUSTAND_REINIGT, 'Going to zone'],
        'mowing' => [RoboHubModell::ZUSTAND_REINIGT, 'Mowing'], 'paused' => [RoboHubModell::ZUSTAND_PAUSIERT, 'Paused'],
        'error' => [RoboHubModell::ZUSTAND_FEHLER, 'Error'], 'locked' => [RoboHubModell::ZUSTAND_FEHLER, 'Locked or lifted'],
        'offline' => [RoboHubModell::ZUSTAND_UNBEKANNT, 'Offline'], 'sleeping' => [RoboHubModell::ZUSTAND_SCHLAF, 'Sleeping'],
    ];

    // RMI mainState (messages.py success_messages): 1 = Fehler (Text über extraStatus).
    private const RMI_ZUSTAND = [
        1 => [RoboHubModell::ZUSTAND_FEHLER, 'Error'], 5 => [RoboHubModell::ZUSTAND_REINIGT, 'Mowing'],
        6 => [RoboHubModell::ZUSTAND_GELADEN, 'In the dock'], 7 => [RoboHubModell::ZUSTAND_LAEDT, 'Charging'],
        11 => [RoboHubModell::ZUSTAND_ZURUECK, 'On the way'],
    ];

    // RMI extraStatus → [Meldungs-ID, Text] (messages.py error_messages, MIT).
    private const RMI_FEHLER = [
        0 => ['M0000', 'No error'], 1 => ['M0001', 'EEPROM data loss'], 2 => ['M0002', 'RTC error'], 3 => ['M0003', 'EEPROM error'],
        4 => ['M0004', 'SPI flash error'], 5 => ['M0005', 'Wrong hardware'], 6 => ['M0006', 'Device has no serial number'],
        7 => ['M0007', 'Wire signal error'], 8 => ['M0008', 'Invalid battery type'], 9 => ['M0009', 'Wrong software'],
        10 => ['M0100', 'Battery undervoltage'], 11 => ['M0101', 'Battery overvoltage'], 12 => ['M0102', '12V undervoltage'],
        13 => ['M0103', '12V overvoltage'], 14 => ['M0180', 'Circuit board temperature too low'],
        15 => ['M0181', 'Circuit board temperature too high'], 16 => ['M0183', 'Charger board temperature too high'],
        17 => ['M0185', 'Motor board temperature too high'], 18 => ['M0186', 'Temperature in the battery too low'],
        19 => ['M0187', 'Temperature in the battery too high'], 20 => ['M0200', 'Communication with left wheel disturbed'],
        21 => ['M0201', 'Communication with right wheel disturbed'], 22 => ['M0202', 'Communication with mowing motor 1 disturbed'],
        23 => ['M0203', 'Communication with mowing motor 2 disturbed'], 24 => ['M0204', 'Communication with remote control disturbed'],
        25 => ['M0205', 'Communication with SR controller disturbed'], 26 => ['M0206', 'Communication with battery disturbed'],
        27 => ['M0207', 'Communication acceleration sensor'], 28 => ['M0208', 'Communication magnetic field sensor'],
        29 => ['M0209', 'Communication charger electronics'], 30 => ['M0300', 'Left wheel: battery voltage out of range (battery)'],
        31 => ['M0301', 'Left wheel: supply voltage out of range (12V)'], 32 => ['M0302', 'Left wheel: temperature too high'],
        33 => ['M0303', 'Left wheel: controller unstable'], 34 => ['M0304', 'Left wheel: Hall sensor(s) defective'], 35 => ['M0305', 'Left wheel: overload'],
        36 => ['M0306', 'Left wheel: wrong hardware'], 37 => ['M0307', 'Left wheel: no safe release'],
        38 => ['M0400', 'Right wheel: battery voltage out of range (battery)'],
        39 => ['M0401', 'Right wheel: supply voltage out of range (12V)'], 40 => ['M0402', 'Right wheel: temperature too high'],
        41 => ['M0403', 'Right wheel: controller unstable'], 42 => ['M0404', 'Right wheel: Hall sensor(s) defective'],
        43 => ['M0405', 'Right wheel: overload'], 44 => ['M0406', 'Right wheel: wrong hardware'],
        45 => ['M0407', 'Right wheel: no safe release'], 46 => ['M0500', 'Blade 1: battery voltage out of range (battery)'],
        47 => ['M0501', 'Blade 1: supply voltage out of range (12V)'], 48 => ['M0502', 'Blade 1: temperature too high'],
        49 => ['M0503', 'Blade 1: controller unstable'], 50 => ['M0504', 'Blade 1: Hall sensor(s) defective'], 51 => ['M0505', 'Blade 1: overload'],
        52 => ['M0506', 'Blade 1: wrong hardware'], 53 => ['M0507', 'Blade 1: no safe release '],
        54 => ['M0600', 'Blade 2: battery voltage out of range (battery)'], 55 => ['M0601', 'Blade 2: supply voltage out of range (12V) '],
        56 => ['M0602', 'Blade 2: temperature too high'], 57 => ['M0603', 'Blade 2: controller unstable '],
        58 => ['M0604', 'Blade 2: Hall sensor(s) defective'], 59 => ['M0605', 'Blade 2: overload'], 60 => ['M0606', 'Blade 2: wrong hardware '],
        61 => ['M0607', 'Blade 2: no safe release'], 62 => ['M0700', 'Battery overvoltage '], 63 => ['M0701', 'Battery temperature error'],
        64 => ['M0702', 'Battery wire break'], 65 => ['M0703', 'Battery undervoltage'], 66 => ['M0704', 'Battery undervoltage'],
        67 => ['M0705', 'Battery cells unbalanced'], 68 => ['M0706', 'Battery NTC error'], 69 => ['M0707', 'Battery alarm line error'],
        70 => ['M0708', 'Battery voltage measurement inaccurate'], 71 => ['M0709', 'Battery internal error'], 72 => ['M1000', 'Rollover detected'],
        73 => ['M1010', 'Lifted for more than 10 s'], 74 => ['M1020', 'Stop button pressed'], 75 => ['M1030', 'Hood removed'],
        76 => ['M1100', 'Remote control removed in automatic mode'], 77 => ['M1110', 'Lifting detected in automatic mode'],
        78 => ['M1120', 'Automatic mode shut down by bumper'], 79 => ['M1125', 'Reference run shut down by bumper'],
        80 => ['M1130', 'Stuck in automatic mode'], 81 => ['M1135', 'iMow outside'], 82 => ['M1140', 'Max. slope exceeded'],
        83 => ['M1150', 'Disabling device in automatic mode'], 84 => ['M1160', 'Carrying handle in automatic mode'],
        85 => ['M1170', 'Edge signal in automatic mode'], 86 => ['M1180', 'Docking station not found'],
        87 => ['M1190', 'Docking station not free'], 88 => ['M1200', 'Starting the mowing motor failed'], 89 => ['M1210', 'Wheels blocked/overloaded'],
        90 => ['M1220', 'Automatic mode interrupted due to rain'], 91 => ['M2000', 'Edge signal not taught in '],
        92 => ['M2010', 'Blade 1 must be replaced'], 93 => ['M2011', 'Blade 2 must be replaced'], 94 => ['M2020', 'Maintenance notice'],
        95 => ['M2030', 'Battery low'], 96 => ['M2031', 'Battery charging failed several times'], 97 => ['M2032', 'Battery charging temperature'],
        98 => ['M2040', 'Battery temperature for starting to mow'], 99 => ['M2050', 'Available mowing times insufficient'],
        100 => ['M2060', 'Secondary area finished'], 101 => ['M2070', 'No GPS reception  reference run'],
        102 => ['M2071', 'No GPS reception start point 1'], 103 => ['M2072', 'No GPS reception start point 2'],
        104 => ['M2073', 'No GPS reception start point 3'], 105 => ['M2074', 'No GPS reception start point 4'], 106 => ['M2080', 'No GPRS reception'],
        107 => ['M2090', 'Hardware/communication error in extension module'], 108 => ['M2100', 'Theft alarm'],
        109 => ['M2110', 'iMow installed elsewhere, but no reinstallation was performed'],
        110 => ['M4000', 'SR: over- or undervoltage'], 111 => ['M4001', 'SR: over- or undertemperature'],
        112 => ['M4002', 'SR: rollover detected'], 113 => ['M4003', 'SR: lifted 10 s'],
        114 => ['M4004', 'SR: wheel motors shut down due to timeout'],
        115 => ['M4005', 'SR: mowing motor shut down due to timeout'],
        116 => ['M4006', 'SR: charger shut down due to timeout'], 117 => ['M4008', 'SR: remote insertion sensor error'],
        118 => ['M4009', 'SR: bumper error'], 119 => ['M4010', 'SR: repeated mowing motor shutdown due to timeout'],
        120 => ['M4011', 'SR: rollover sensor error'], 121 => ['M4012', 'SR: remote buttons error'],
        122 => ['M4013', 'SR: error checking the wire signal'], 123 => ['M4014', 'SR: external temperature sensors error'],
        124 => ['M4015', 'SR: lifting handle switch error'], 125 => ['M4016', 'SR: stop button error'], 126 => ['M4017', 'SR: DC bus error'],
        127 => ['M4018', 'SR: voltage error'], 128 => ['M4019', 'SR: error on the main board'],
        129 => ['M4020', 'SR: internal temperature sensors error'], 130 => ['M4021', 'SR: safe signal error'], 131 => ['M4022', 'SR: charger error'],
        132 => ['M4023', 'SR: alarm lines error'], 133 => ['M4024', 'SR: speed measurement error'],
        134 => ['M4025', 'SR: SR controller detected an internal error'], 135 => ['M4026', 'SR: voltage measurement is not calibrated'],
        136 => ['M2075', 'No GPS reception in preferred zone'], 137 => ['M2076', 'Preferred zone not reached'],
        138 => ['M2077', 'Preferred zone outside the home area'], 139 => ['M2200', 'SIM card contract expired'],
        140 => ['M0104', 'Overvoltage at charging contacts'], 141 => ['M2120', 'Child lock active'], 142 => ['M1131', 'Stuck detection'],
        143 => ['M1230', 'Right or left wheel: overload'], 144 => ['M4027', 'Stop button pressed'],
        145 => ['M2095', 'GPS antenna not connected'], 146 => ['M1105', 'animation.message_M1105_long'],
    ];

    // =================================================================
    // Anmeldung
    // =================================================================

    public function anmelden(string $konto, string $passwort, string $region): array
    {
        if (!filter_var($konto, FILTER_VALIDATE_EMAIL) || $passwort === '') {
            throw new RoboHubFehler(RoboHubText::t('Please enter e-mail and password of the STIHL account (as in the iMOW app).'));
        }
        $grund5 = '';
        try {
            $b = $this->b2cAnmelden($konto, $passwort);
            if (count($this->gen5Maeher($b)) > 0) {
                return $b;
            }
            $grund5 = RoboHubText::t('no mower of the new generation in the account');
        } catch (RoboHubFehler $e) {
            if (!$e->anmeldungNoetig) {
                throw $e;   // Netz/Server: nicht stillschweigend auf die alte Anmeldung ausweichen
            }
            $grund5 = $e->getMessage();
        }
        try {
            $b = $this->rmiAnmelden($konto, $passwort);
        } catch (RoboHubFehler $e) {
            $f = new RoboHubFehler(RoboHubText::t('STIHL rejects the sign-in — new iMOW generation: %s; older iMOW: %s', $grund5, $e->getMessage()));
            $f->anmeldungNoetig = true;
            throw $f;
        }
        return $b;
    }

    /** Text nach erfolgreicher Anmeldung (Konto). */
    public function anmeldeText(array $buendel): string
    {
        if (($buendel['gen'] ?? '') === 'rmi') {
            return RoboHubText::t('✅ Signed in (older iMOW generation). STIHL grants access for 30 days without renewal here — RoboHub deliberately does not store your password. Please sign in again on %s; a notice appears three days before.', date('d.m.Y', (int)$buendel['expires_at']));
        }
        return RoboHubText::t('✅ Signed in (new iMOW generation). The access token is stored, the password was discarded.');
    }

    public function erneuern(array $buendel): array
    {
        if (($buendel['gen'] ?? '') === 'rmi') {
            if ((int)($buendel['expires_at'] ?? 0) <= time() + 300) {
                $e = new RoboHubFehler(RoboHubText::t('STIHL access (older iMOW) expired after 30 days — please sign in again in the account.'));
                $e->anmeldungNoetig = true;
                throw $e;
            }
            return $buendel;
        }
        if (!is_file($this->erneuernDatei($buendel)) && (int)($buendel['expires_at'] ?? 0) - 300 > time()) {
            return $buendel;
        }
        [$status, $text] = $this->http('POST', self::B2C . self::B2C_POLICY . '/oauth2/v2.0/token', ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['grant_type' => 'refresh_token', 'refresh_token' => (string)($buendel['refresh_token'] ?? ''), 'client_id' => self::B2C_CLIENT]));
        $this->dbg('HTTP', 'POST token (refresh) → ' . $status);
        if ($status === 0 || $status >= 500) {
            throw new RoboHubFehler(RoboHubText::t('STIHL sign-in not reachable%s.', ($status === 0 ? ': ' . $text : ' (HTTP ' . $status . ')')));
        }
        $j = json_decode($text, true);
        if (!is_array($j) || (string)($j['access_token'] ?? '') === '') {
            $e = new RoboHubFehler(RoboHubText::t('STIHL rejects the renewal%s — please sign in again.', (is_array($j) && isset($j['error']) ? ' (' . $j['error'] . ')' : '')));
            $e->anmeldungNoetig = true;
            throw $e;
        }
        @unlink($this->erneuernDatei($buendel));
        return self::gen5Buendel($j, $buendel);
    }

    private static function gen5Buendel(array $j, array $alt): array
    {
        return [
            'gen'           => '5',
            'access_token'  => (string)$j['access_token'],
            'refresh_token' => (string)($j['refresh_token'] ?? $alt['refresh_token'] ?? ''),
            'expires_at'    => time() + max(300, (int)($j['expires_in'] ?? 3600)),
        ];
    }

    private function erneuernDatei(array $buendel): string
    {
        return sys_get_temp_dir() . '/robohub-stihl-erneuern-' . substr(md5((string)($buendel['refresh_token'] ?? '')), 0, 12);
    }

    /** STIHL-ID (Azure B2C) mit PKCE wie ha-imow-v2 auth.py, Schritte 1–4. */
    private function b2cAnmelden(string $mail, string $passwort): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = bin2hex(random_bytes(12));
        $kekse = [];
        // 1: Anmeldeseite, SETTINGS (transId, csrf) und Sitzungs-Cookies
        $url = self::B2C . self::B2C_POLICY . '/oauth2/v2.0/authorize?' . http_build_query([
            'response_type' => 'code', 'code_challenge_method' => 'S256', 'scope' => self::B2C_SCOPE, 'code_challenge' => $challenge,
            'response_mode' => 'query', 'redirect_uri' => self::B2C_REDIRECT, 'client_id' => self::B2C_CLIENT, 'state' => $state,
        ]);
        [$status, $html] = $this->folgen('GET', $url, ['User-Agent: ' . self::AGENT, 'Accept: text/html,*/*'], null, $kekse);
        if (!preg_match('/var SETTINGS\s*=\s*(\{.*?\});\s*\n/s', $html, $m) || !is_array($s = json_decode($m[1], true)) || !isset($s['transId'], $s['csrf'])) {
            throw new RoboHubFehler(RoboHubText::t('STIHL sign-in page unexpected (HTTP %s) — please try again later.', $status));
        }
        // 2: Zugangsdaten
        [$status, $text, $kopf] = $this->http('POST', self::B2C . self::B2C_POLICY_GROSS . '/SelfAsserted?tx=' . rawurlencode($s['transId']) . '&p=' . self::B2C_POLICY_GROSS, [
            'User-Agent: ' . self::AGENT, 'X-CSRF-TOKEN: ' . $s['csrf'], 'X-Requested-With: XMLHttpRequest',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8', 'Accept: application/json, text/javascript, */*; q=0.01', self::keksKopf($kekse),
        ], http_build_query(['request_type' => 'RESPONSE', 'signInName' => $mail, 'password' => $passwort], '', '&', PHP_QUERY_RFC3986));
        self::kekseMerken($kopf ?? [], $kekse);
        $j = json_decode($text, true);
        if (!is_array($j)) {
            throw new RoboHubFehler(RoboHubText::t('STIHL sign-in responds unexpectedly (HTTP %s).', $status));
        }
        if ((string)($j['status'] ?? '200') !== '200') {
            $e = new RoboHubFehler(RoboHubText::t('E-mail or password wrong'));
            $e->anmeldungNoetig = true;
            throw $e;
        }
        // 3: Bestätigung → Weiterleitung imow://…?code=
        [$status, , $kopf] = $this->http('GET', self::B2C . self::B2C_POLICY_GROSS . '/api/CombinedSigninAndSignup/confirmed?' . http_build_query([
            'rememberMe' => 'true', 'csrf_token' => $s['csrf'], 'tx' => $s['transId'], 'p' => self::B2C_POLICY_GROSS,
        ]), ['User-Agent: ' . self::AGENT, self::keksKopf($kekse)], null);
        $ziel = self::kopfWert($kopf ?? [], 'location');
        parse_str((string)parse_url($ziel, PHP_URL_QUERY), $q);
        if (($q['state'] ?? $state) !== $state || (string)($q['code'] ?? '') === '') {
            $e = new RoboHubFehler(RoboHubText::t('STIHL did not deliver a sign-in code%s.', (isset($q['error']) ? ' (' . $q['error'] . ')' : '')));
            $e->anmeldungNoetig = isset($q['error']);
            throw $e;
        }
        // 4: Code gegen Schlüssel tauschen
        [$status, $text] = $this->http('POST', self::B2C . self::B2C_POLICY . '/oauth2/v2.0/token', ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8'],
            http_build_query(['code' => $q['code'], 'code_verifier' => $verifier, 'redirect_uri' => self::B2C_REDIRECT, 'client_id' => self::B2C_CLIENT, 'grant_type' => 'authorization_code']));
        $j = json_decode($text, true);
        if (!is_array($j) || (string)($j['access_token'] ?? '') === '') {
            throw new RoboHubFehler(RoboHubText::t('STIHL key exchange failed (HTTP %s).', $status));
        }
        return self::gen5Buendel($j, []);
    }

    /** RMI: Formular-Anmeldung, Schlüssel aus der Weiterleitung (stihl-imow-webapi __authenticate). */
    private function rmiAnmelden(string $mail, string $passwort): array
    {
        $kekse = [];
        $autorisierung = self::RMI_OAUTH . '/authorization/?response_type=token&client_id=' . self::RMI_CLIENT
            . '&redirect_uri=' . rawurlencode(self::RMI_APP . '/#/authorize') . '&state=' . bin2hex(random_bytes(12));
        [$status, $html] = $this->folgen('GET', self::RMI_OAUTH . '/authentication/?lang=de_DE&authorizationRedirectUrl=' . rawurlencode($autorisierung),
            ['User-Agent: ' . self::AGENT], null, $kekse);
        $csrf = preg_match('/name="csrf-token"[^>]*value="([^"]+)"|value="([^"]+)"[^>]*name="csrf-token"/', $html, $m) ? ($m[1] ?: $m[2]) : '';
        $anfrage = preg_match('/name="requestId"[^>]*value="([^"]+)"|value="([^"]+)"[^>]*name="requestId"/', $html, $m) ? ($m[1] ?: $m[2]) : '';
        if ($csrf === '' || $anfrage === '') {
            throw new RoboHubFehler(RoboHubText::t('Sign-in page of the older iMOW unexpected (HTTP %s).', $status));
        }
        $ziel = '';
        [$status, $text] = $this->folgen('POST', self::RMI_OAUTH . '/authentication/authenticate/?lang=de', [
            'User-Agent: ' . self::AGENT, 'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query(['mail' => $mail, 'password' => $passwort, 'csrf-token' => $csrf, 'requestId' => $anfrage]), $kekse, $ziel);
        if (!preg_match('/[#&?]access_token=([^&#]+)/', $ziel, $t)) {
            $e = new RoboHubFehler(RoboHubText::t('E-mail or password wrong'));
            $e->anmeldungNoetig = true;
            throw $e;
        }
        $dauer = preg_match('/[#&?]expires_in=(\d+)/', $ziel, $x) ? (int)$x[1] : 30 * 86400;
        return ['gen' => 'rmi', 'access_token' => rawurldecode($t[1]), 'expires_at' => time() + $dauer];
    }

    // =================================================================
    // Geräte und Zustand
    // =================================================================

    public function geraete(array $buendel): array
    {
        $liste = [];
        if (($buendel['gen'] ?? '') === 'rmi') {
            foreach ($this->rmiAnfrage($buendel, 'GET', 'mowers/') as $m) {
                if (!is_array($m) || (string)($m['id'] ?? '') === '') {
                    continue;
                }
                $liste[] = ['id' => (string)$m['id'], 'name' => (string)($m['name'] ?? 'iMOW'), 'modell' => 'stihl.mower.' . self::modellKurz((string)($m['deviceTypeDescription'] ?? 'imow')),
                    'klasse' => RoboHubModell::KLASSE_MAEHER, 'online' => !empty($m['status']['online']), 'extern' => (string)($m['externalId'] ?? ''),
                    'roh' => ['modell' => $m['deviceTypeDescription'] ?? null, 'firmware' => $m['firmwareVersion'] ?? null, 'generation' => RoboHubText::t('RMI (up to gen. 4)')]];
            }
            return $liste;
        }
        foreach ($this->gen5Maeher($buendel) as $m) {
            $id = (string)($m['deviceId'] ?? $m['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $typ = (string)($m['device']['type'] ?? 'iMOW');
            $liste[] = ['id' => $id, 'name' => (string)($m['setting']['deviceNickname'] ?? '') ?: $typ, 'modell' => 'stihl.mower.' . self::modellKurz($typ),
                'klasse' => RoboHubModell::KLASSE_MAEHER, 'online' => self::gruppe($m) !== 'offline',
                'roh' => ['modell' => $typ, 'generation' => 'Gen. 5+']];
        }
        return $liste;
    }

    private static function modellKurz(string $typ): string
    {
        return strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '', $typ)) ?: 'imow';
    }

    public function zustand(array $buendel, array $geraet): array
    {
        $id = (string)$geraet['id'];
        if (($buendel['gen'] ?? '') === 'rmi') {
            $m = $this->rmiAnfrage($buendel, 'GET', 'mowers/' . rawurlencode($id) . '/');
            if (!isset($m['status'])) {
                throw new RoboHubFehler(RoboHubText::t('STIHL delivers no state for this mower — is it still in the account?'));
            }
            try {
                $m['_statistik'] = $this->rmiAnfrage($buendel, 'GET', 'mowers/' . rawurlencode($id) . '/statistic/');
            } catch (RoboHubFehler $e) {
                $this->dbg('Statistik', $e->getMessage());   // Statistik ist Beiwerk
            }
            $m['_ablauf'] = (int)$buendel['expires_at'];
        } else {
            $m = [];
            foreach ($this->gen5Maeher($buendel) as $x) {
                if ((string)($x['deviceId'] ?? $x['id'] ?? '') === $id) {
                    $m = $x;
                }
            }
            if ($m === []) {
                throw new RoboHubFehler(RoboHubText::t('Mower %s is not (or no longer) in this STIHL account.', $id));
            }
            $m['_fehlertexte'] = $this->fehlertexte(array_map(function ($f) { return (string)($f['code'] ?? ''); }, (array)($m['device']['errorsActive'] ?? [])));
        }
        $daten = $this->normalisiere((string)($geraet['modell'] ?? ''), $m);
        $daten['online'] = isset($m['status']) ? !empty($m['status']['online']) : self::gruppe($m) !== 'offline';
        $daten['bloecke'] = ['ok' => [0], 'fehl' => []];
        $daten['roh'] = ['modell' => $geraet['modell'] ?? null, 'properties' => $m];
        return $daten;
    }

    private static function gruppe(array $m): ?string
    {
        $roh = (string)($m['device']['overallState']['state'] ?? '');
        foreach (self::GRUPPEN as $g => $mitglieder) {
            if (in_array($roh, $mitglieder, true)) {
                return $g;
            }
        }
        return null;
    }

    public function normalisiere(string $modell, array $p): array
    {
        return isset($p['status']) ? self::rmiWerte($p) : self::gen5Werte($p);
    }

    private static function gen5Werte(array $p): array
    {
        $w = [];
        $roh = (string)($p['device']['overallState']['state'] ?? '');
        $g = self::gruppe($p);
        if ($roh !== '') {
            if ($g === 'busy') {
                $g = !empty($p['device']['isDocked']) ? 'docked' : 'mowing';   // kurz nach einem Befehl (lawn_mower.py)
            }
            [$z, $text] = self::GRUPPE_ZUSTAND[$g] ?? [RoboHubModell::ZUSTAND_UNBEKANNT, 'Unknown'];
            $text = RoboHubText::wert($text);
            if (stripos($roh, 'Rain') !== false && in_array($g, ['docked', 'charging', 'paused'], true)) {
                [$z, $text] = [RoboHubModell::ZUSTAND_WETTERPAUSE, 'Rain pause'];
            }
            $w['Zustand'] = $z;
            $w['ZustandRoh'] = $text . ' (' . $roh . ')';
        }
        $fehler = [];
        foreach ((array)($p['device']['errorsActive'] ?? []) as $f) {
            $code = (string)($f['code'] ?? '');
            if ($code !== '') {
                $fehler[] = ($p['_fehlertexte'][$code] ?? 'Fehlercode') . ' (' . $code . ')';
            }
        }
        $w['Fehler'] = $fehler === [] ? RoboHubText::t('no error') : implode('; ', $fehler);
        if (isset($p['battery']['level']) && is_numeric($p['battery']['level'])) {
            $w['Akku'] = (int)round((float)$p['battery']['level'] * 100);
        }
        $s = (array)($p['statistics'] ?? []);
        $w['GesamtDauer'] = isset($s['totalWorkingSeconds']) ? (int)round((float)$s['totalWorkingSeconds'] / 3600) : null;
        $w['GesamtStrecke'] = isset($s['totalDrivenDistance']) ? round((float)$s['totalDrivenDistance'] / 1000, 1) : null;
        $w['Messerlaufzeit'] = isset($s['totalWorkingTimeOfCurrentCuttingKnifes']) ? round((float)$s['totalWorkingTimeOfCurrentCuttingKnifes'] / 3600, 1) : null;
        $w['GesamtAnzahl'] = isset($s['totalStartedMowingJobs']) ? (int)$s['totalStartedMowingJobs'] : null;
        return ['werte' => $w, 'optionen' => [], 'schreibbar' => []];
    }

    private static function rmiWerte(array $p): array
    {
        $w = [];
        $st = (array)$p['status'];
        $haupt = (int)($st['mainState'] ?? -1);
        [$z, $text] = self::RMI_ZUSTAND[$haupt] ?? [RoboHubModell::ZUSTAND_BEREIT, 'Standby'];
        $text = RoboHubText::wert($text);
        if (empty($st['online'])) {
            [$z, $text] = [RoboHubModell::ZUSTAND_UNBEKANNT, 'Offline'];
        }
        $w['Zustand'] = $z;
        $w['ZustandRoh'] = $text . ' (' . $haupt . ')';
        if ($haupt === 1) {
            $extra = (int)($st['extraStatus'] ?? -1);
            [$mid, $t] = self::RMI_FEHLER[$extra] ?? ['', RoboHubText::t('Error code %s', $extra)];
            $t = RoboHubText::wert($t);
            $w['Fehler'] = $t . ($mid !== '' ? ' (' . $mid . ')' : '');
        } else {
            $w['Fehler'] = RoboHubText::t('no error');
        }
        if (isset($st['chargeLevel']) && is_numeric($st['chargeLevel'])) {
            $w['Akku'] = (int)$st['chargeLevel'];
        }
        if (isset($st['rainStatus'])) {
            $w['Regensensor'] = (bool)$st['rainStatus'];
        }
        $s = (array)($p['_statistik'] ?? []);
        $w['GesamtDauer'] = isset($s['totalOperatingTime']) ? (int)round((float)$s['totalOperatingTime'] / 3600) : null;
        $w['GesamtStrecke'] = isset($s['totalDistanceTravelled']) ? round((float)$s['totalDistanceTravelled'] / 1000, 1) : null;
        $w['Messerlaufzeit'] = isset($s['totalBladeOperatingTime']) ? round((float)$s['totalBladeOperatingTime'] / 3600, 1) : null;
        $w['Firmware'] = isset($p['firmwareVersion']) && (string)$p['firmwareVersion'] !== '' ? (string)$p['firmwareVersion'] : null;
        $ablauf = (int)($p['_ablauf'] ?? 0);
        if ($ablauf > 0 && $ablauf - time() < self::RMI_WARNTAGE * 86400) {
            $w['Meldung'] = RoboHubText::t('STIHL sign-in expires on %s — please sign in again in the RoboHub account.', date('d.m.Y', $ablauf));
        }
        return ['werte' => $w, 'optionen' => [], 'schreibbar' => []];
    }

    /** Fehlertexte der myimow-App (öffentliche Datei, einmal am Tag), Schlüssel dB_errorDetails_headline_dtc_<6 Stellen>. */
    private function fehlertexte(array $codes): array
    {
        $codes = array_values(array_filter($codes, 'ctype_digit'));
        if ($codes === []) {
            return [];
        }
        $datei = sys_get_temp_dir() . '/robohub-stihl-fehlertexte.json';
        $alle = is_file($datei) && time() - (int)filemtime($datei) < 86400 ? json_decode((string)file_get_contents($datei), true) : null;
        if (!is_array($alle)) {
            $alle = [];
            [$status, $text] = $this->http('GET', self::FEHLERTEXTE, ['Accept: application/json', 'User-Agent: ' . self::AGENT], null);
            $j = $status === 200 ? json_decode($text, true) : null;
            foreach (is_array($j) ? $j : [] as $k => $v) {
                if (preg_match('/^dB_errorDetails_headline_dtc_(\d+)$/', (string)$k, $m)) {
                    // ICU-kompiliert: Liste von Teilen, Typ 0 = Klartext (api.py get_error_texts)
                    $alle[(string)(int)$m[1]] = trim(is_string($v) ? $v : implode('', array_map(function ($t) { return is_array($t) ? (string)($t['value'] ?? '') : ''; }, (array)$v)));
                }
            }
            if ($alle !== []) {
                @file_put_contents($datei, (string)json_encode($alle, JSON_UNESCAPED_UNICODE));
            }
        }
        $aus = [];
        foreach ($codes as $c) {
            if (isset($alle[(string)(int)$c])) {
                $aus[$c] = $alle[(string)(int)$c];
            }
        }
        return $aus;
    }

    // =================================================================
    // Zonen und Befehle
    // =================================================================

    public function karte(array $buendel, array $geraet): array
    {
        $raeume = [];
        if (($buendel['gen'] ?? '') !== 'rmi') {
            foreach ($this->gen5Maeher($buendel) as $m) {
                if ((string)($m['deviceId'] ?? $m['id'] ?? '') !== (string)$geraet['id']) {
                    continue;
                }
                foreach ((array)($m['setting']['mowingZones'] ?? []) as $z) {
                    if (isset($z['mowingZoneId'])) {
                        $raeume[(int)$z['mowingZoneId']] = trim((string)($z['name'] ?? '')) ?: RoboHubText::t('Zone %s', (int)$z['mowingZoneId']);
                    }
                }
            }
        }
        return ['raeume' => $raeume, 'bild' => '', 'marken' => [], 'station' => null];
    }

    public function liveKarte(array $buendel, array $geraet): array
    {
        return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
    }

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        return ($buendel['gen'] ?? '') === 'rmi' ? $this->rmiBefehl($buendel, $geraet, $befehl) : $this->gen5Befehl($buendel, $geraet, $befehl, $parameter);
    }

    private function gen5Befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        $stand = (array)($geraet['stand'] ?? []);
        $dauer = (int)($stand['setting']['defaultManualMowingDuration'] ?? 0) ?: 3600;   // Vorgabe des Mähers (coordinator.py)
        switch ($befehl) {
            case 'start':
                if (self::gruppe($stand) === 'paused') {
                    [$cmd, $daten, $text] = ['resume', null, 'Resume'];
                } else {
                    [$cmd, $daten, $text] = ['start-mowing', ['durationInSeconds' => $dauer], RoboHubText::t('Mow (%s min)', (int)round($dauer / 60))];
                }
                break;
            case 'pause':
                [$cmd, $daten, $text] = ['pause', null, 'Pause'];
                break;
            case 'station':
                [$cmd, $daten, $text] = ['end-job-and-return-to-dock', null, RoboHubText::t('End task and return to base')];
                break;
            case 'raum':
                $zonen = array_values(array_unique(array_map('intval', (array)($parameter['raeume'] ?? []))));
                if (count($zonen) !== 1) {
                    throw new RoboHubFehler(RoboHubText::t('STIHL iMOW always mows exactly one zone — please choose one.'));
                }
                [$cmd, $daten, $text] = ['start-mowing', ['durationInSeconds' => $dauer, 'mowingZoneId' => $zonen[0]], RoboHubText::t('Mow zone (%s min)', (int)round($dauer / 60))];
                break;
            default:
                throw new RoboHubFehler($befehl === 'stopp' ? RoboHubText::t('The iMOW has no "Stop" — use "Pause" or "Return to base".')
                    : ($befehl === 'orten' ? RoboHubText::t('STIHL does not offer "Locate".') : RoboHubText::t('This command is not supported by STIHL iMOW: %s', $befehl)));
        }
        $this->gen5Anfrage($buendel, 'POST', 'mowerctrl/api/v1/mower-commands/' . rawurlencode((string)$geraet['id']) . '/' . $cmd, $daten);
        return RoboHubText::t('Command "%s" sent.', RoboHubText::wert($text));
    }

    private function rmiBefehl(array $buendel, array $geraet, string $befehl): string
    {
        $extern = (string)($geraet['extern'] ?? '');
        if (strlen($extern) !== 16) {
            throw new RoboHubFehler(RoboHubText::t('The mower\'s action ID is missing — please click "Search devices now" in the account.'));
        }
        if ($befehl === 'start') {
            // startMowing: „<extId>,<Ende>" (Ende 2 h ab jetzt, Ortszeit — wie stihl-imow-webapi)
            [$aktion, $wert, $text] = ['startMowing', $extern . ',' . date('Y-m-d H:i', time() + 7200), RoboHubText::t('Mow (2 h)')];
        } elseif ($befehl === 'station') {
            [$aktion, $wert, $text] = ['toDocking', $extern, RoboHubText::t('Return to base')];
        } else {
            throw new RoboHubFehler(RoboHubText::t('Via the cloud the older iMOW generation only knows "Start" and "Return to base".'));
        }
        $this->rmiAnfrage($buendel, 'POST', 'mower-actions/', ['actionName' => $aktion, 'actionValue' => $wert]);
        return RoboHubText::t('Command "%s" sent.', RoboHubText::wert($text));
    }

    public function zuruecksetzen(array $buendel, array $geraet, string $teil): string
    {
        throw new RoboHubFehler(RoboHubText::t('Resetting wear parts on STIHL iMOW is only possible in the app.'));
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

    /** @return array<int, array> */
    private function gen5Maeher(array $buendel): array
    {
        $j = $this->gen5Anfrage($buendel, 'GET', 'mowertwin/api/v1/mowers', null);
        $liste = isset($j['mowers']) ? (array)$j['mowers'] : (array_is_list($j) ? $j : [$j]);
        return array_values(array_filter($liste, function ($m) { return is_array($m) && $m !== []; }));
    }

    private function gen5Anfrage(array $buendel, string $methode, string $pfad, ?array $body): array
    {
        $kopf = ['Ocp-Apim-Subscription-Key: ' . self::APIM_KEY, 'Accept: application/json', 'User-Agent: ' . self::AGENT,
            'Authorization: Bearer ' . $buendel['access_token'], 'Content-Type: application/json'];
        [$status, $text] = $this->http($methode, self::API . $pfad, $kopf, $body !== null ? (string)json_encode($body) : ($methode === 'POST' ? '' : null));
        $this->dbg('HTTP', $methode . ' ' . $pfad . ' → ' . $status);
        return $this->auswerten($buendel, $status, $text, $pfad);
    }

    private function rmiAnfrage(array $buendel, string $methode, string $pfad, ?array $body = null): array
    {
        $kopf = ['User-Agent: ' . self::AGENT, 'Accept: application/json, text/plain, */*', 'Authorization: Bearer ' . $buendel['access_token'],
            'Origin: ' . self::RMI_APP, 'Referer: ' . self::RMI_APP . '/', 'Content-Type: application/json'];
        [$status, $text] = $this->http($methode, self::RMI_API . $pfad, $kopf, $body !== null ? (string)json_encode($body) : null);
        $this->dbg('HTTP', $methode . ' ' . $pfad . ' → ' . $status);
        return $this->auswerten($buendel, $status, $text, $pfad);
    }

    private function auswerten(array $buendel, int $status, string $text, string $pfad): array
    {
        if ($status === 0 || $status >= 500) {
            throw new RoboHubFehler(RoboHubText::t('STIHL cloud not reachable%s.', ($status === 0 ? ': ' . $text : ' (HTTP ' . $status . ')')));
        }
        if ($status === 401) {
            if (($buendel['gen'] ?? '') === 'rmi') {
                $e = new RoboHubFehler(RoboHubText::t('STIHL rejected the access (older iMOW) — please sign in again in the account.'));
                $e->anmeldungNoetig = true;
                throw $e;
            }
            @touch($this->erneuernDatei($buendel));   // nicht abmelden: beim nächsten Abruf mit dem Erneuerungsschlüssel
            throw new RoboHubFehler(RoboHubText::t('STIHL rejected the access token — RoboHub renews it at the next update.'));
        }
        if ($status === 429) {
            $e = new RoboHubFehler(RoboHubText::t('STIHL is throttling the requests — RoboHub is waiting.'));
            $e->warteSekunden = 600;
            throw $e;
        }
        if ($status >= 300) {
            throw new RoboHubFehler(RoboHubText::t('STIHL responds with HTTP %s to %s.', $status, $pfad));
        }
        $j = json_decode($text, true);
        return is_array($j) ? $j : [];
    }

    /** Weiterleitungen selbst verfolgen und dabei Cookies sammeln; $ziel = erste Weiterleitung auf ein fremdes Schema/Fragment mit Schlüssel. */
    private function folgen(string $methode, string $url, array $kopf, ?string $body, array &$kekse, string &$ziel = ''): array
    {
        for ($i = 0; $i < 10; $i++) {
            [$status, $text, $antwortKopf] = $this->http($methode, $url, array_merge($kopf, [self::keksKopf($kekse)]), $body);
            self::kekseMerken($antwortKopf ?? [], $kekse);
            $wohin = self::kopfWert($antwortKopf ?? [], 'location');
            if ($status < 300 || $status >= 400 || $wohin === '') {
                return [$status, $text];
            }
            if (strpos($wohin, 'access_token=') !== false || !preg_match('#^(https?://|/)#', $wohin)) {
                $ziel = $wohin;
                return [$status, $text];
            }
            $url = $wohin[0] === '/' ? preg_replace('#^(https?://[^/]+).*$#', '$1', $url) . $wohin : $wohin;
            [$methode, $body] = ['GET', null];
        }
        throw new RoboHubFehler(RoboHubText::t('STIHL sign-in: too many redirects.'));
    }

    private static function keksKopf(array $kekse): string
    {
        $paare = [];
        foreach ($kekse as $k => $v) {
            $paare[] = $k . '=' . $v;
        }
        return 'Cookie: ' . implode('; ', $paare);
    }

    private static function kekseMerken(array $kopf, array &$kekse): void
    {
        foreach ($kopf as $zeile) {
            if (preg_match('/^set-cookie:\s*([^=;\s]+)=([^;]*)/i', $zeile, $m)) {
                $kekse[$m[1]] = $m[2];
            }
        }
    }

    private static function kopfWert(array $kopf, string $name): string
    {
        foreach ($kopf as $zeile) {
            if (stripos($zeile, $name . ':') === 0) {
                return trim(substr($zeile, strlen($name) + 1));
            }
        }
        return '';
    }

    /**
     * curl ohne automatische Weiterleitung; Rückgabe [Status, Text, Kopfzeilen].
     * Im Prüfstand ersetzbar.
     */
    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        $kopf = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $methode,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_ENCODING       => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, string $zeile) use (&$kopf): int {
                if (trim($zeile) !== '') {
                    $kopf[] = rtrim($zeile, "\r\n");
                }
                return strlen($zeile);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $text = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = curl_error($ch);
        if ($text === false) {
            return [0, $fehler, []];
        }
        return [$status, (string)$text, $kopf];
    }

    private function dbg(string $thema, string $text): void
    {
        if (is_callable($this->debug)) {
            ($this->debug)($thema, $text);
        }
    }
}
