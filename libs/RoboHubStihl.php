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
        'docked' => [RoboHubModell::ZUSTAND_GELADEN, 'In der Station'], 'charging' => [RoboHubModell::ZUSTAND_LAEDT, 'Lädt'],
        'returning' => [RoboHubModell::ZUSTAND_ZURUECK, 'Fährt zur Station'], 'driving' => [RoboHubModell::ZUSTAND_REINIGT, 'Fährt zur Zone'],
        'mowing' => [RoboHubModell::ZUSTAND_REINIGT, 'Mäht'], 'paused' => [RoboHubModell::ZUSTAND_PAUSIERT, 'Pausiert'],
        'error' => [RoboHubModell::ZUSTAND_FEHLER, 'Fehler'], 'locked' => [RoboHubModell::ZUSTAND_FEHLER, 'Gesperrt oder angehoben'],
        'offline' => [RoboHubModell::ZUSTAND_UNBEKANNT, 'Offline'], 'sleeping' => [RoboHubModell::ZUSTAND_SCHLAF, 'Schläft'],
    ];

    // RMI mainState (messages.py success_messages): 1 = Fehler (Text über extraStatus).
    private const RMI_ZUSTAND = [
        1 => [RoboHubModell::ZUSTAND_FEHLER, 'Fehler'], 5 => [RoboHubModell::ZUSTAND_REINIGT, 'Am Mähen'],
        6 => [RoboHubModell::ZUSTAND_GELADEN, 'Im Dock'], 7 => [RoboHubModell::ZUSTAND_LAEDT, 'Laden'],
        11 => [RoboHubModell::ZUSTAND_ZURUECK, 'Unterwegs'],
    ];

    // RMI extraStatus → [Meldungs-ID, Text] (messages.py error_messages, MIT).
    private const RMI_FEHLER = [
        0 => ['M0000', 'Kein Fehler'], 1 => ['M0001', 'EEPROM Datenverlust'], 2 => ['M0002', 'Fehler RTC'], 3 => ['M0003', 'Fehler EEPROM'],
        4 => ['M0004', 'Fehler SPI Flash'], 5 => ['M0005', 'Falsche Hardware'], 6 => ['M0006', 'Gerät hat keine Seriennummer'],
        7 => ['M0007', 'Fehler Drahtsignal'], 8 => ['M0008', 'Ungültiger Akkutyp'], 9 => ['M0009', 'Falsche Software'],
        10 => ['M0100', 'Unterspannung Akku'], 11 => ['M0101', 'Überspannung Akku'], 12 => ['M0102', 'Unterspannung 12V'],
        13 => ['M0103', 'Überspannung 12V'], 14 => ['M0180', 'Temperatur Leiterplatte zu niedrig'],
        15 => ['M0181', 'Temperatur Leiterplatte zu hoch'], 16 => ['M0183', 'Temperatur Loaderplatien zu hoch'],
        17 => ['M0185', 'Temperatur Motorplatine zu hoch'], 18 => ['M0186', 'Temperatur im Akku zu niedrig'],
        19 => ['M0187', 'Temperatur im Akku zu hoch'], 20 => ['M0200', 'Kommunikation linkes Rad gestört'],
        21 => ['M0201', 'Kommunikation rechtes Rad gestört'], 22 => ['M0202', 'Kommunikation Mähmotor 1 gestört'],
        23 => ['M0203', 'Kommunikation Mähmotor 2 gestört'], 24 => ['M0204', 'Kommunikation Fernsteuerung gestört'],
        25 => ['M0205', 'Kommunikation SR Controller gestört'], 26 => ['M0206', 'Kommunikation Akku gestört'],
        27 => ['M0207', 'Kommunikation Beschleunigungssensor'], 28 => ['M0208', 'Kommunikation Magnetfeldsensor'],
        29 => ['M0209', 'Kommunikation Laderelektronik'], 30 => ['M0300', 'Linkes Rad: Batteriespannung außer Bereich (Akku)'],
        31 => ['M0301', 'Linkes Rad: Versorgungsspannung außer Bereich (12V)'], 32 => ['M0302', 'Linkes Rad: Temperatur zu hoch'],
        33 => ['M0303', 'Linkes Rad: Regler instabil'], 34 => ['M0304', 'Linkes Rad: Hallsensor(en) defekt'], 35 => ['M0305', 'Linkes Rad: Überlast'],
        36 => ['M0306', 'Linkes Rad: falsche Hardware'], 37 => ['M0307', 'Linkes Rad: keine Safe-Freigabe'],
        38 => ['M0400', 'Rechtes Rad: Batteriespannung außer Bereich (Akku)'],
        39 => ['M0401', 'Rechtes Rad: Versorgungsspannung außer Bereich (12V)'], 40 => ['M0402', 'Rechtes Rad: Temperatur zu hoch'],
        41 => ['M0403', 'Rechtes Rad: Regler instabil'], 42 => ['M0404', 'Rechtes Rad: Hallsensor(en) defekt'],
        43 => ['M0405', 'Rechtes Rad: Überlast'], 44 => ['M0406', 'Rechtes Rad: falsche Hardware'],
        45 => ['M0407', 'Rechtes Rad: keine Safe-Freigabe'], 46 => ['M0500', 'Messer 1: Batteriespannung außer Bereich (Akku)'],
        47 => ['M0501', 'Messer 1: Versorgungsspannung außer Bereich (12V)'], 48 => ['M0502', 'Messer 1: Temperatur zu hoch'],
        49 => ['M0503', 'Messer 1: Regler instabil'], 50 => ['M0504', 'Messer 1: Hallsensor(en) defekt'], 51 => ['M0505', 'Messer 1: Überlast'],
        52 => ['M0506', 'Messer 1: falsche Hardware'], 53 => ['M0507', 'Messer 1: keine Safe-Freigabe '],
        54 => ['M0600', 'Messer 2: Batteriespannung außer Bereich (Akku)'], 55 => ['M0601', 'Messer 2: Versorgungsspannung außer Bereich (12V) '],
        56 => ['M0602', 'Messer 2: Temperatur zu hoch'], 57 => ['M0603', 'Messer 2: Regler instabil '],
        58 => ['M0604', 'Messer 2: Hallsensor(en) defekt'], 59 => ['M0605', 'Messer 2: Überlast'], 60 => ['M0606', 'Messer 2: falsche Hardware '],
        61 => ['M0607', 'Messer 2: keine Safe-Freigabe'], 62 => ['M0700', 'Akku Überspannung '], 63 => ['M0701', 'Akku Temperaturfehler'],
        64 => ['M0702', 'Akku Drahtbruch'], 65 => ['M0703', 'Akku Unterspannung'], 66 => ['M0704', 'Akku Unterspannung'],
        67 => ['M0705', 'Akku Zellen debalanciert'], 68 => ['M0706', 'Akku NTC Fehler'], 69 => ['M0707', 'Akku Fehler Alarmleitung'],
        70 => ['M0708', 'Akku Spannungsmessung ungenau'], 71 => ['M0709', 'Akku interner Fehler'], 72 => ['M1000', 'Überschlag wurde erkannt'],
        73 => ['M1010', 'Länger als 10s angehoben'], 74 => ['M1020', 'Stopptaster wurde betätigt'], 75 => ['M1030', 'Haube wurde abgenommen'],
        76 => ['M1100', 'Fernsteuerung im Automatikmode entnommen'], 77 => ['M1110', 'Anheben im Automatikmode erkannt'],
        78 => ['M1120', 'Abschaltung Automatikmode durch Bumper'], 79 => ['M1125', 'Abschaltung Referenzfahrt durch Bumper'],
        80 => ['M1130', 'Im Automatikmode festgefahren'], 81 => ['M1135', 'iMow außerhalb'], 82 => ['M1140', 'Max. Steigung überschritten'],
        83 => ['M1150', 'Disabling Device im Automatikmode'], 84 => ['M1160', 'Tragegriff im Automatikmode'],
        85 => ['M1170', 'Randsignal im Automatikmode'], 86 => ['M1180', 'Dockingstation nicht gefunden'],
        87 => ['M1190', 'Dockingstation nicht frei'], 88 => ['M1200', 'Start Mähmotor fehlgeschlagen'], 89 => ['M1210', 'Räder blockiert/überlastet'],
        90 => ['M1220', 'Unterbrechung Automatikbetrieb wegen Regen'], 91 => ['M2000', 'Randsignal nicht eingelernt '],
        92 => ['M2010', 'Messer 1 muss gewechselt werden'], 93 => ['M2011', 'Messer 2 muss gewechselt werden'], 94 => ['M2020', 'Wartungshinweis'],
        95 => ['M2030', 'Akku schwach'], 96 => ['M2031', 'Akku laden mehrfach gescheitert'], 97 => ['M2032', 'Akku Ladetemperatur'],
        98 => ['M2040', 'Akku Temperatur für Start Mähbetrieb'], 99 => ['M2050', 'Verfügbare Mähzeiten ungenügend'],
        100 => ['M2060', 'Nebenfläche fertig'], 101 => ['M2070', 'Kein GPS Empfang  Referenzfahrt'],
        102 => ['M2071', 'Kein GPS Empfang Startpunkt 1'], 103 => ['M2072', 'Kein GPS Empfang Startpunkt 2'],
        104 => ['M2073', 'Kein GPS Empfang Startpunkt 3'], 105 => ['M2074', 'Kein GPS Empfang Startpunkt 4'], 106 => ['M2080', 'Kein GPRS Empfang'],
        107 => ['M2090', 'Hardware/Kommunikation- Fehler in Erweiterungsmodul'], 108 => ['M2100', 'Diebstahl'],
        109 => ['M2110', 'iMow woanders installiert, aber es wurde keine Neuinstallation durchgeführt'],
        110 => ['M4000', 'SR: Über- oder Unterspannung'], 111 => ['M4001', 'SR: Über- oder Untertemperatur'],
        112 => ['M4002', 'SR: Überschlag erkannt'], 113 => ['M4003', 'SR: Anheben 10 Sek.'],
        114 => ['M4004', 'SR: Radmotoren Abschaltung aufgrund Zeitüberschreitung'],
        115 => ['M4005', 'SR: Mähmotor Abschaltung aufgrund Zeitüberschreitung'],
        116 => ['M4006', 'SR: Lader Abschaltung aufgrund Zeitüberschreitung'], 117 => ['M4008', 'SR: Fehler Remoteeinlege Sensor'],
        118 => ['M4009', 'SR: Fehler Bumper'], 119 => ['M4010', 'SR: Wiederholte Mähmotor Abschaltung aufgrund Zeitüberschreitung'],
        120 => ['M4011', 'SR: Fehler Überschlagsensor'], 121 => ['M4012', 'SR: Fehler Remote Tasten'],
        122 => ['M4013', 'SR: Fehler bei der Überprüfung vom Drahtsignal'], 123 => ['M4014', 'SR: Fehler externe Temperatursensoren'],
        124 => ['M4015', 'SR: Fehler Anhebegriffschalter'], 125 => ['M4016', 'SR: Fehler Stoptaster'], 126 => ['M4017', 'SR: DC-Bus-Fehler'],
        127 => ['M4018', 'SR: Spannungsfehler'], 128 => ['M4019', 'SR: Fehler auf der Main Platine'],
        129 => ['M4020', 'SR: Fehler interne Temperatursensoren'], 130 => ['M4021', 'SR: Fehler Safe Signal'], 131 => ['M4022', 'SR: Fehler Lader'],
        132 => ['M4023', 'SR: Fehler Alarmleitungen'], 133 => ['M4024', 'SR: Fehler Drehzahlmessung'],
        134 => ['M4025', 'SR: SR-Controller hat internen Fehler erkannt'], 135 => ['M4026', 'SR: Spannungsmessung ist nicht kalibriert'],
        136 => ['M2075', 'Kein GPS Empfang in Wunschzone'], 137 => ['M2076', 'Wunschzone nicht erreicht'],
        138 => ['M2077', 'Wunschzone außerhalb des Heimbereichs'], 139 => ['M2200', 'Vertrag für SIM-Karte abgelaufen'],
        140 => ['M0104', 'Überspannung Ladekontakte'], 141 => ['M2120', 'Kinderschutz aktiv'], 142 => ['M1131', 'Festfahrerkennung'],
        143 => ['M1230', 'Rechtes oder linkes Rad: Überlast'], 144 => ['M4027', 'Stopptaster wurde betätigt'],
        145 => ['M2095', 'GPS Antenne nicht angeschlossen'], 146 => ['M1105', 'animation.message_M1105_long'],
    ];

    // =================================================================
    // Anmeldung
    // =================================================================

    public function anmelden(string $konto, string $passwort, string $region): array
    {
        if (!filter_var($konto, FILTER_VALIDATE_EMAIL) || $passwort === '') {
            throw new RoboHubFehler('Bitte E-Mail und Passwort des STIHL-Kontos eintragen (wie in der iMOW-App).');
        }
        $grund5 = '';
        try {
            $b = $this->b2cAnmelden($konto, $passwort);
            if (count($this->gen5Maeher($b)) > 0) {
                return $b;
            }
            $grund5 = 'kein Mäher der neuen Generation im Konto';
        } catch (RoboHubFehler $e) {
            if (!$e->anmeldungNoetig) {
                throw $e;   // Netz/Server: nicht stillschweigend auf die alte Anmeldung ausweichen
            }
            $grund5 = $e->getMessage();
        }
        try {
            $b = $this->rmiAnmelden($konto, $passwort);
        } catch (RoboHubFehler $e) {
            $f = new RoboHubFehler('STIHL lehnt die Anmeldung ab — neue iMOW-Generation: ' . $grund5 . '; ältere iMOW: ' . $e->getMessage());
            $f->anmeldungNoetig = true;
            throw $f;
        }
        return $b;
    }

    /** Text nach erfolgreicher Anmeldung (Konto). */
    public function anmeldeText(array $buendel): string
    {
        if (($buendel['gen'] ?? '') === 'rmi') {
            return '✅ Angemeldet (ältere iMOW-Generation). STIHL vergibt hier einen Zugang für 30 Tage ohne Erneuerung — RoboHub speichert dein Passwort bewusst nicht. Am '
                . date('d.m.Y', (int)$buendel['expires_at']) . ' bitte einmal neu anmelden; drei Tage vorher erscheint ein Hinweis.';
        }
        return '✅ Angemeldet (neue iMOW-Generation). Der Zugangsschlüssel ist gespeichert, das Passwort wurde verworfen.';
    }

    public function erneuern(array $buendel): array
    {
        if (($buendel['gen'] ?? '') === 'rmi') {
            if ((int)($buendel['expires_at'] ?? 0) <= time() + 300) {
                $e = new RoboHubFehler('Der STIHL-Zugang (ältere iMOW) ist nach 30 Tagen abgelaufen — bitte im Konto neu anmelden.');
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
            throw new RoboHubFehler('STIHL-Anmeldung nicht erreichbar' . ($status === 0 ? ': ' . $text : ' (HTTP ' . $status . ')') . '.');
        }
        $j = json_decode($text, true);
        if (!is_array($j) || (string)($j['access_token'] ?? '') === '') {
            $e = new RoboHubFehler('STIHL lehnt die Erneuerung ab' . (is_array($j) && isset($j['error']) ? ' (' . $j['error'] . ')' : '') . ' — bitte neu anmelden.');
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
            throw new RoboHubFehler('STIHL-Anmeldeseite unerwartet (HTTP ' . $status . ') — bitte später erneut versuchen.');
        }
        // 2: Zugangsdaten
        [$status, $text, $kopf] = $this->http('POST', self::B2C . self::B2C_POLICY_GROSS . '/SelfAsserted?tx=' . rawurlencode($s['transId']) . '&p=' . self::B2C_POLICY_GROSS, [
            'User-Agent: ' . self::AGENT, 'X-CSRF-TOKEN: ' . $s['csrf'], 'X-Requested-With: XMLHttpRequest',
            'Content-Type: application/x-www-form-urlencoded; charset=UTF-8', 'Accept: application/json, text/javascript, */*; q=0.01', self::keksKopf($kekse),
        ], http_build_query(['request_type' => 'RESPONSE', 'signInName' => $mail, 'password' => $passwort], '', '&', PHP_QUERY_RFC3986));
        self::kekseMerken($kopf ?? [], $kekse);
        $j = json_decode($text, true);
        if (!is_array($j)) {
            throw new RoboHubFehler('STIHL-Anmeldung antwortet unerwartet (HTTP ' . $status . ').');
        }
        if ((string)($j['status'] ?? '200') !== '200') {
            $e = new RoboHubFehler('E-Mail oder Passwort falsch');
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
            $e = new RoboHubFehler('STIHL hat keinen Anmeldecode geliefert' . (isset($q['error']) ? ' (' . $q['error'] . ')' : '') . '.');
            $e->anmeldungNoetig = isset($q['error']);
            throw $e;
        }
        // 4: Code gegen Schlüssel tauschen
        [$status, $text] = $this->http('POST', self::B2C . self::B2C_POLICY . '/oauth2/v2.0/token', ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8'],
            http_build_query(['code' => $q['code'], 'code_verifier' => $verifier, 'redirect_uri' => self::B2C_REDIRECT, 'client_id' => self::B2C_CLIENT, 'grant_type' => 'authorization_code']));
        $j = json_decode($text, true);
        if (!is_array($j) || (string)($j['access_token'] ?? '') === '') {
            throw new RoboHubFehler('STIHL-Schlüsseltausch fehlgeschlagen (HTTP ' . $status . ').');
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
            throw new RoboHubFehler('Anmeldeseite der älteren iMOW unerwartet (HTTP ' . $status . ').');
        }
        $ziel = '';
        [$status, $text] = $this->folgen('POST', self::RMI_OAUTH . '/authentication/authenticate/?lang=de', [
            'User-Agent: ' . self::AGENT, 'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query(['mail' => $mail, 'password' => $passwort, 'csrf-token' => $csrf, 'requestId' => $anfrage]), $kekse, $ziel);
        if (!preg_match('/[#&?]access_token=([^&#]+)/', $ziel, $t)) {
            $e = new RoboHubFehler('E-Mail oder Passwort falsch');
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
                    'roh' => ['modell' => $m['deviceTypeDescription'] ?? null, 'firmware' => $m['firmwareVersion'] ?? null, 'generation' => 'RMI (bis Gen. 4)']];
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
                throw new RoboHubFehler('STIHL liefert für diesen Mäher keinen Zustand — ist er noch im Konto?');
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
                throw new RoboHubFehler('Mäher ' . $id . ' ist in diesem STIHL-Konto nicht (mehr) vorhanden.');
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
            [$z, $text] = self::GRUPPE_ZUSTAND[$g] ?? [RoboHubModell::ZUSTAND_UNBEKANNT, 'Unbekannt'];
            if (stripos($roh, 'Rain') !== false && in_array($g, ['docked', 'charging', 'paused'], true)) {
                [$z, $text] = [RoboHubModell::ZUSTAND_WETTERPAUSE, 'Regenpause'];
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
        $w['Fehler'] = $fehler === [] ? 'kein Fehler' : implode('; ', $fehler);
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
        [$z, $text] = self::RMI_ZUSTAND[$haupt] ?? [RoboHubModell::ZUSTAND_BEREIT, 'Ruhezustand'];
        if (empty($st['online'])) {
            [$z, $text] = [RoboHubModell::ZUSTAND_UNBEKANNT, 'Offline'];
        }
        $w['Zustand'] = $z;
        $w['ZustandRoh'] = $text . ' (' . $haupt . ')';
        if ($haupt === 1) {
            $extra = (int)($st['extraStatus'] ?? -1);
            [$mid, $t] = self::RMI_FEHLER[$extra] ?? ['', 'Fehlercode ' . $extra];
            $w['Fehler'] = $t . ($mid !== '' ? ' (' . $mid . ')' : '');
        } else {
            $w['Fehler'] = 'kein Fehler';
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
            $w['Meldung'] = 'STIHL-Anmeldung läuft am ' . date('d.m.Y', $ablauf) . ' ab — bitte im RoboHub-Konto neu anmelden.';
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
                        $raeume[(int)$z['mowingZoneId']] = trim((string)($z['name'] ?? '')) ?: 'Zone ' . (int)$z['mowingZoneId'];
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
                    [$cmd, $daten, $text] = ['resume', null, 'Fortsetzen'];
                } else {
                    [$cmd, $daten, $text] = ['start-mowing', ['durationInSeconds' => $dauer], 'Mähen (' . (int)round($dauer / 60) . ' min)'];
                }
                break;
            case 'pause':
                [$cmd, $daten, $text] = ['pause', null, 'Pause'];
                break;
            case 'station':
                [$cmd, $daten, $text] = ['end-job-and-return-to-dock', null, 'Auftrag beenden und zur Station'];
                break;
            case 'raum':
                $zonen = array_values(array_unique(array_map('intval', (array)($parameter['raeume'] ?? []))));
                if (count($zonen) !== 1) {
                    throw new RoboHubFehler('STIHL iMOW mäht immer genau eine Zone — bitte eine wählen.');
                }
                [$cmd, $daten, $text] = ['start-mowing', ['durationInSeconds' => $dauer, 'mowingZoneId' => $zonen[0]], 'Zone mähen (' . (int)round($dauer / 60) . ' min)'];
                break;
            default:
                throw new RoboHubFehler($befehl === 'stopp' ? '„Stopp" gibt es bei der iMOW nicht — „Pause" oder „Zur Station" nehmen.'
                    : ($befehl === 'orten' ? '„Orten" bietet STIHL nicht.' : 'Dieser Befehl wird bei STIHL iMOW nicht unterstützt: ' . $befehl));
        }
        $this->gen5Anfrage($buendel, 'POST', 'mowerctrl/api/v1/mower-commands/' . rawurlencode((string)$geraet['id']) . '/' . $cmd, $daten);
        return 'Befehl „' . $text . '" gesendet.';
    }

    private function rmiBefehl(array $buendel, array $geraet, string $befehl): string
    {
        $extern = (string)($geraet['extern'] ?? '');
        if (strlen($extern) !== 16) {
            throw new RoboHubFehler('Die Aktionskennung des Mähers fehlt — bitte im Konto „Geräte jetzt suchen".');
        }
        if ($befehl === 'start') {
            // startMowing: „<extId>,<Ende>" (Ende 2 h ab jetzt, Ortszeit — wie stihl-imow-webapi)
            [$aktion, $wert, $text] = ['startMowing', $extern . ',' . date('Y-m-d H:i', time() + 7200), 'Mähen (2 h)'];
        } elseif ($befehl === 'station') {
            [$aktion, $wert, $text] = ['toDocking', $extern, 'Zur Station'];
        } else {
            throw new RoboHubFehler('Die ältere iMOW-Generation kennt über die Cloud nur „Start" und „Zur Station".');
        }
        $this->rmiAnfrage($buendel, 'POST', 'mower-actions/', ['actionName' => $aktion, 'actionValue' => $wert]);
        return 'Befehl „' . $text . '" gesendet.';
    }

    public function zuruecksetzen(array $buendel, array $geraet, string $teil): string
    {
        throw new RoboHubFehler('Verschleißteile zurücksetzen geht bei STIHL iMOW nur in der App.');
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
            throw new RoboHubFehler('STIHL-Cloud nicht erreichbar' . ($status === 0 ? ': ' . $text : ' (HTTP ' . $status . ')') . '.');
        }
        if ($status === 401) {
            if (($buendel['gen'] ?? '') === 'rmi') {
                $e = new RoboHubFehler('STIHL hat den Zugang (ältere iMOW) abgelehnt — bitte im Konto neu anmelden.');
                $e->anmeldungNoetig = true;
                throw $e;
            }
            @touch($this->erneuernDatei($buendel));   // nicht abmelden: beim nächsten Abruf mit dem Erneuerungsschlüssel
            throw new RoboHubFehler('STIHL hat den Zugangsschlüssel abgelehnt — RoboHub erneuert ihn beim nächsten Abruf.');
        }
        if ($status === 429) {
            $e = new RoboHubFehler('STIHL bremst die Anfragen — RoboHub wartet.');
            $e->warteSekunden = 600;
            throw $e;
        }
        if ($status >= 300) {
            throw new RoboHubFehler('STIHL antwortet mit HTTP ' . $status . ' auf ' . $pfad . '.');
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
        throw new RoboHubFehler('STIHL-Anmeldung: zu viele Weiterleitungen.');
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
