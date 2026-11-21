<?php

declare(strict_types=1);

require_once __DIR__ . '/RoboHubRoborockKarte.php';

// ===========================================================================
// RoboHub-Treiber für Roborock (App „Roborock") — Saug-/Wischroboter mit
// dem Gerätprotokoll „1.0" (S-, Q-, Qrevo-, Saros-Reihen).
//
// Quelle: Python-roborock (Apache-2.0, github.com/Python-roborock/
// python-roborock, Stand 12.10.2026): web_api.py (Anmeldung, Hawk),
// protocol.py (Rahmen, Verschlüsselung, MQTT-Zugang), protocols/
// v1_protocol.py (Nutzlast), data/v1/* (Felder und Codes).
//
// Ablauf:
//   - Anmeldung per E-Mail-Code (kein Passwort): Code anfordern, Code
//     eintragen. Gespeichert wird nur das Nutzer-Bündel (rriot + token).
//   - Geräteliste und Raumnamen über die Roborock-Web-API (Hawk-signiert).
//   - Zustand und Befehle gehen als verschlüsselte Rahmen über den
//     MQTT-Server von Roborock (rr/m/i/… hin, rr/m/o/… zurück). Je Abruf
//     baut der Treiber eine kurze TLS-Sitzung auf (Zertifikat gegen die
//     DigiCert-Wurzel geprüft), schickt alle Anfragen und wartet auf die
//     Antworten mit passender ID.
//
// Programme (App-Routinen) seit 0.51.0 über die Web-API.
// Noch nicht gebaut: Zeitpläne, Geräte mit den Protokollen A01/B01 (werden als „noch nicht
// unterstützt" gemeldet).
// ===========================================================================

class RoboHubRoborock implements RoboHubTreiber
{
    /** @var callable|null fn(string $thema, string $text) */
    public $debug = null;

    /**
     * Dauerhafte Verbindung des Kontos (0.50.0, Forum alsk1 144636/140): steht sie, setzt das Konto hier
     * fn(string $abo, string $ziel, array $rahmen, callable $verarbeite, float $frist): void — dann laufen alle
     * Anfragen über diese eine Verbindung statt über je eine kurze Sitzung (Roborock sperrte nach ~700/h).
     * @var callable|null
     */
    public $transport = null;

    // Unaufgeforderte Meldungen (python-roborock RoborockDataProtocol) → Schlüssel wie in get_status/get_consumable.
    private const PUSH_FELDER = [120 => 'error_code', 121 => 'state', 122 => 'battery', 123 => 'fan_power', 124 => 'water_box_mode',
        125 => 'main_brush_work_time', 126 => 'side_brush_work_time', 127 => 'filter_work_time'];

    // Einstiegs-Server; welcher das Konto kennt, sagt getUrlByEmail.
    private const BASIS = [
        'https://euiot.roborock.com',
        'https://usiot.roborock.com',
        'https://cniot.roborock.com',
        'https://ruiot.roborock.com',
    ];
    private const SALZ = 'TXdfu$jyZ#TZHsg4';
    private const PROTOKOLL_ANFRAGE = 101;
    private const PROTOKOLL_ANTWORT = 102;
    // Kartenantwort (roborock_message.py MAP_RESPONSE 301, MAP_RESPONSE_PART 300)
    private const PROTOKOLL_KARTE = 301;
    private const PROTOKOLL_KARTE_TEIL = 300;
    // App-Kennung wie python-roborock code_login_v4.
    private const APP_VERSION = '4.54.02';

    // Wurzel der Roborock-Server (mqtt-*.roborock.com: RapidSSL TLS RSA CA G1
    // → DigiCert Global Root G2). SHA-256 CB:3C:CB:B7:…:5A:B1:CB:5F, gültig bis 2038.
    public const MQTT_WURZEL = <<<PEM
-----BEGIN CERTIFICATE-----
MIIDjjCCAnagAwIBAgIQAzrx5qcRqaC7KGSxHQn65TANBgkqhkiG9w0BAQsFADBh
MQswCQYDVQQGEwJVUzEVMBMGA1UEChMMRGlnaUNlcnQgSW5jMRkwFwYDVQQLExB3
d3cuZGlnaWNlcnQuY29tMSAwHgYDVQQDExdEaWdpQ2VydCBHbG9iYWwgUm9vdCBH
MjAeFw0xMzA4MDExMjAwMDBaFw0zODAxMTUxMjAwMDBaMGExCzAJBgNVBAYTAlVT
MRUwEwYDVQQKEwxEaWdpQ2VydCBJbmMxGTAXBgNVBAsTEHd3dy5kaWdpY2VydC5j
b20xIDAeBgNVBAMTF0RpZ2lDZXJ0IEdsb2JhbCBSb290IEcyMIIBIjANBgkqhkiG
9w0BAQEFAAOCAQ8AMIIBCgKCAQEAuzfNNNx7a8myaJCtSnX/RrohCgiN9RlUyfuI
2/Ou8jqJkTx65qsGGmvPrC3oXgkkRLpimn7Wo6h+4FR1IAWsULecYxpsMNzaHxmx
1x7e/dfgy5SDN67sH0NO3Xss0r0upS/kqbitOtSZpLYl6ZtrAGCSYP9PIUkY92eQ
q2EGnI/yuum06ZIya7XzV+hdG82MHauVBJVJ8zUtluNJbd134/tJS7SsVQepj5Wz
tCO7TG1F8PapspUwtP1MVYwnSlcUfIKdzXOS0xZKBgyMUNGPHgm+F6HmIcr9g+UQ
vIOlCsRnKPZzFBQ9RnbDhxSJITRNrw9FDKZJobq7nMWxM4MphQIDAQABo0IwQDAP
BgNVHRMBAf8EBTADAQH/MA4GA1UdDwEB/wQEAwIBhjAdBgNVHQ4EFgQUTiJUIBiV
5uNu5g/6+rkS7QYXjzkwDQYJKoZIhvcNAQELBQADggEBAGBnKJRvDkhj6zHd6mcY
1Yl9PMWLSn/pvtsrF9+wX3N3KjITOYFnQoQj8kVnNeyIv/iPsGEMNKSuIEyExtv4
NeF22d+mQrvHRAiGfzZ0JFrabA0UWTW98kndth/Jsw1HKj2ZL7tcu7XUIOGZX1NG
Fdtom/DzMNU+MeKNhJ7jitralj41E6Vf8PlwUHBHQRFXGU7Aj64GxJUTFy8bJZ91
8rGOmaFvE7FBcf6IKshPECBV1/MUReXgRPTqh5Uykw7+U0b6LJ3/iyK5S9kJRaTe
pLiaWN0bfVKfjllDiIGknibVb63dDcY3fe0Dkhvld1927jyNxF1WW6LZZm6zNTfl
MrY=
-----END CERTIFICATE-----
PEM;

    // Zustandscodes (data/v1/v1_code_mappings.py RoborockStateCode).
    private const ZUSTAND = [
        1 => ['Starting', RoboHubModell::ZUSTAND_REINIGT],
        2 => ['Disconnected from charger', RoboHubModell::ZUSTAND_BEREIT],
        3 => ['Ready', RoboHubModell::ZUSTAND_BEREIT],
        4 => ['Remote control', RoboHubModell::ZUSTAND_REINIGT],
        5 => ['Cleaning', RoboHubModell::ZUSTAND_REINIGT],
        6 => ['Returning', RoboHubModell::ZUSTAND_ZURUECK],
        7 => ['Manual mode', RoboHubModell::ZUSTAND_REINIGT],
        8 => ['Charging', RoboHubModell::ZUSTAND_LAEDT],
        9 => ['Charging problem', RoboHubModell::ZUSTAND_FEHLER],
        10 => ['Paused', RoboHubModell::ZUSTAND_PAUSIERT],
        11 => ['Spot cleaning', RoboHubModell::ZUSTAND_REINIGT],
        12 => ['Error', RoboHubModell::ZUSTAND_FEHLER],
        13 => ['Shutting down', RoboHubModell::ZUSTAND_SCHLAF],
        14 => ['Updating', RoboHubModell::ZUSTAND_AKTUALISIERUNG],
        15 => ['Docking', RoboHubModell::ZUSTAND_ZURUECK],
        16 => ['Going to target', RoboHubModell::ZUSTAND_REINIGT],
        17 => ['Zone cleaning', RoboHubModell::ZUSTAND_REINIGT],
        18 => ['Room cleaning', RoboHubModell::ZUSTAND_REINIGT],
        22 => ['Emptying the dust bin', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        23 => ['Washing the mop', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        25 => ['Washing the mop', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        26 => ['Going to wash the mop', RoboHubModell::ZUSTAND_ZURUECK],
        28 => ['In call', RoboHubModell::ZUSTAND_BEREIT],
        29 => ['Mapping', RoboHubModell::ZUSTAND_KARTIERUNG],
        30 => ['Cleaning', RoboHubModell::ZUSTAND_REINIGT],
        32 => ['Patrol', RoboHubModell::ZUSTAND_REINIGT],
        33 => ['Attaching the mop', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        34 => ['Removing the mop', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        100 => ['Charged', RoboHubModell::ZUSTAND_GELADEN],
        101 => ['Offline', RoboHubModell::ZUSTAND_UNBEKANNT],
        103 => ['Locked', RoboHubModell::ZUSTAND_FEHLER],
        202 => ['Drying ending', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        6301 => ['Mopping', RoboHubModell::ZUSTAND_REINIGT],
        6302 => ['Vacuuming and mopping', RoboHubModell::ZUSTAND_REINIGT],
        6303 => ['Mopping', RoboHubModell::ZUSTAND_REINIGT],
        6304 => ['Mopping rooms', RoboHubModell::ZUSTAND_REINIGT],
        6305 => ['Vacuuming and mopping rooms', RoboHubModell::ZUSTAND_REINIGT],
        6306 => ['Mopping rooms', RoboHubModell::ZUSTAND_REINIGT],
        6307 => ['Mopping zones', RoboHubModell::ZUSTAND_REINIGT],
        6308 => ['Vacuuming and mopping zones', RoboHubModell::ZUSTAND_REINIGT],
        6309 => ['Mopping zones', RoboHubModell::ZUSTAND_REINIGT],
        6310 => ['Returning to wash', RoboHubModell::ZUSTAND_ZURUECK],
    ];

    // Fehlercodes (RoborockErrorCode), die häufigen auf Deutsch.
    private const FEHLER = [
        1 => 'Laser sensor blocked', 2 => 'Bumper stuck', 3 => 'Wheels in the air', 4 => 'Cliff sensor',
        5 => 'Main brush blocked', 6 => 'Side brush blocked', 7 => 'Wheels blocked', 8 => 'Robot stuck',
        9 => 'Dust bin missing', 10 => 'Filter wet or clogged', 11 => 'Strong magnetic field', 12 => 'Battery low',
        13 => 'Charging error', 14 => 'Battery error', 15 => 'Wall sensor dirty', 16 => 'Robot tilted',
        17 => 'Side brush error', 18 => 'Fan error', 19 => 'Station without power', 20 => 'Floor sensor dirty',
        21 => 'Bumper pressed', 22 => 'Station not found', 23 => 'Return to base failed',
        24 => 'No-go zone detected', 25 => 'Camera error', 26 => 'Wall sensor error', 27 => 'Mop lift stuck',
        28 => 'Robot on carpet', 29 => 'Filter clogged', 30 => 'Virtual wall detected', 31 => 'Carpet cannot be crossed',
        32 => 'Internal error', 34 => 'Clean the auto-empty dock', 35 => 'Auto-empty dock voltage error',
        36 => 'Washing roller blocked', 37 => 'Washing roller not lowered', 38 => 'Check clean water tank',
        39 => 'Check dirty water tank', 40 => 'Insert water filter', 41 => 'Clean water tank empty',
        44 => 'Clean the station\'s water filter', 45 => 'Washing roller blocked', 48 => 'Water supply disturbed',
        49 => 'Water drain disturbed', 51 => 'Temperature protection', 56 => 'Speaker error',
    ];

    /** Pause nach abgewiesener MQTT-Anmeldung (Drosselung), statt abzumelden. */
    private const MQTT_DROSSEL_S = 900;

    /**
     * Abgewiesene MQTT-Anmeldung → Fehler. 4/5 („Benutzer/Zugangsschlüssel ungültig", „nicht berechtigt")
     * meldet Roborock auch, wenn es drosselt — python-roborock roborock_session.py: „unauthorized/rate-limit
     * error", dort nur lange Wartezeit, keine Abmeldung. Ein wirklich ungültiger Zugang fällt über HTTP 401 auf
     * (Geräteliste). Forum alsk1 144636/125: nach einer Reinigung mit Live-Karte abgemeldet.
     */
    public static function connackFehler(int $code): RoboHubFehler
    {
        if (in_array($code, [4, 5], true)) {
            $e = new RoboHubFehler(RoboHubText::t('Roborock server refuses the connection right now (%s) — usually too many connections in a short time. RoboHub pauses for 15 minutes, no new sign-in needed.', RoboHubMqtt::connackText($code)));
            $e->warteSekunden = self::MQTT_DROSSEL_S;
            return $e;
        }
        return new RoboHubFehler(RoboHubText::t('Roborock server rejects the sign-in: %s.', RoboHubMqtt::connackText($code)));
    }

    // Verbrauchsteile: Feld in get_consumable → [RoboHub-Ident, Lebensdauer, Sekunden?]
    // (roborock/const.py *_REPLACE_TIME). Die Stationsteile zählen keine Sekunden (STRAINER 150,
    // CLEANING_BRUSH 300) — dort nur Prozent, keine Reststunden. Nur Geräte mit Station melden sie.
    private const TEILE = [
        'main_brush_work_time'      => ['Hauptbuerste', 1080000, true],
        'side_brush_work_time'      => ['Seitenbuerste', 720000, true],
        'filter_work_time'          => ['Filter', 540000, true],
        'sensor_dirty_time'         => ['Sensoren', 108000, true],
        'moproller_work_time'       => ['Mopp', 1080000, true],
        'strainer_work_times'       => ['Waschwanne', 150, false],
        'cleaning_brush_work_times' => ['Reinigungsbuerste', 300, false],
    ];

    // Saugstufen. Neuere Geräte nutzen 101–108, ältere 38–100
    // (RoborockFanSpeedV1/V2/V3/E2) — welche gilt, zeigt der gemeldete Wert.
    private const SAUGSTUFEN_NEU = [105 => 'Gentle', 101 => 'Quiet', 102 => 'Balanced', 103 => 'Turbo', 104 => 'Max', 108 => 'Max+', 106 => 'Custom'];
    private const SAUGSTUFEN_ALT = [38 => 'Quiet', 60 => 'Standard', 75 => 'Medium', 100 => 'Turbo'];
    // Reinigungsmodus wie bei Dreame (0 Saugen, 1 Wischen, 2 Saugen und Wischen) — python-roborock
    // v1_clean_modes.py CleaningMode; Anpassen/SmartPlan nur Anzeige (in der App eingestellt).
    private const MODUS_SAUGEN = 0;
    private const MODUS_WISCHEN = 1;
    private const MODUS_BEIDES = 2;
    private const MODUS_ANGEPASST = 4;
    private const MODUS_SMART = 5;
    private const SAUG_AUS = 105;        // CleanModeZero: bei Geräten mit reinem Wischen „Saugen aus" (sonst „Sanft")
    private const WASSER_AUS = 200;

    // Kurzmodelle ohne reines Wischen (python-roborock device_features.py PRODUCTS_WITHOUT_PURE_CLEAN_MOP,
    // Kurzmodelle aus data/code_mappings.py RoborockProductNickname). Neuere, dort noch nicht geführte Modelle
    // (z. B. Saros 20 a307) können es nach allem, was bekannt ist — sie bekommen „Wischen" angeboten.
    private const OHNE_NUR_WISCHEN = ['t6', 's6', 't7', 'a11', 't7p', 'a09', 'a10', 'a37', 'a38', 'a33', 'a34', 'a39', 'a40',
        'a73', 'a85', 'a72', 'a84', 't4', 's4', 'p6', 's5e', 'a05', 'p5', 'a08', 'a19'];

    private const WASSERMENGEN = [200 => 'Off', 201 => 'Low', 202 => 'Medium', 203 => 'High', 204 => 'Custom', 207 => 'Custom'];
    // Wasserfluss-Regler (python-roborock clean_modes.py, „water slide“, z. B. Saros 20): Gerät meldet 220 + Reglerstellung
    // 1–30, die App fasst Bereiche zu Namen zusammen (letzte Stellung => Name). Forum lugge 14.11.2026: 235 = App „Mittel“, Regler 15.
    private const WASSERREGLER = [3 => 'Slight', 7 => 'Low', 12 => 'Gentle', 17 => 'Medium', 22 => 'Moderate', 27 => 'High', 30 => 'Extreme'];
    // Weitere Wasser-Codes (python-roborock WaterModes), die ein Gerät melden kann, z. B. während einer App-Routine
    // (Forum lugge 144636/160, Saros 20: 210 während „Flur Test“). Nur zur Anzeige des aktuellen Werts, nicht als Wahl angeboten.
    private const WASSER_WEITERE = [205 => 'Minimum', 206 => 'Maximum', 208 => 'Extreme', 209 => 'Smart mode', 210 => 'Smart water'];
    private const WASSERREGLER_WAHL = [200 => 'Off', 221 => 'Slight', 225 => 'Low', 230 => 'Gentle', 235 => 'Medium', 240 => 'Moderate', 245 => 'High', 250 => 'Extreme'];

    /** Name des App-Bereichs für einen Reglerwert (221–250), sonst null. */
    public static function wasserreglerName(int $wert): ?string
    {
        $stellung = $wert - 220;
        if ($stellung < 1 || $stellung > 30) {
            return null;
        }
        foreach (self::WASSERREGLER as $bis => $name) {
            if ($stellung <= $bis) {
                return $name;
            }
        }
        return null;
    }

    // =================================================================
    // Anmeldung
    // =================================================================

    /** Regionen-Auswahl: Roborock findet den Server über die E-Mail selbst. */
    public static function regionen(): array
    {
        return [['caption' => 'automatic (via the e-mail)', 'value' => 'eu']];
    }

    /** Schritt 1: Roborock schickt einen Anmeldecode an die E-Mail. */
    public function codeAnfordern(string $konto, array $vorstufe = []): string
    {
        $info = $this->kontoServer($konto);
        $kopf = $this->grundKopf($konto) + ['Content-Type' => 'application/x-www-form-urlencoded', 'header_clientlang' => 'de'];
        if ($info['land'] !== '' && $info['landcode'] !== '') {
            $antwort = $this->anfrage('POST', $info['basis'] . '/api/v4/email/code/send', $kopf, [], ['email' => $konto, 'type' => 'login', 'platform' => '']);
        } else {
            $antwort = $this->anfrage('POST', $info['basis'] . '/api/v1/sendEmailCode', $this->grundKopf($konto), ['username' => $konto, 'type' => 'auth'], null);
        }
        $this->pruefeCode($antwort, RoboHubText::t('Request code'));
        return RoboHubText::t('Roborock sent a sign-in code to %s.', $konto);
    }

    /** Schritt 2: Anmeldung mit dem Code aus der E-Mail (im Passwortfeld). */
    public function anmelden(string $konto, string $passwort, string $region): array
    {
        $code = trim($passwort);
        if (!preg_match('/^[0-9]{4,8}$/', $code)) {
            throw new RoboHubFehler(RoboHubText::t('For Roborock please first click "📧 Request code" and then enter the code from the e-mail (digits only) — not the password.'));
        }
        $info = $this->kontoServer($konto);
        if ($info['land'] !== '' && $info['landcode'] !== '') {
            $s = self::zufallsText(16);
            $signatur = $this->anfrage('POST', $info['basis'] . '/api/v3/key/sign', $this->grundKopf($konto), ['s' => $s], null);
            $k = (string)($signatur['data']['k'] ?? '');
            if ($k === '') {
                throw new RoboHubFehler(RoboHubText::t('Roborock did not prepare the sign-in (key signature missing).'));
            }
            $version = $this->vertragsVersion($info);
            $kopf = $this->grundKopf($konto) + [
                'x-mercy-ks' => $s, 'x-mercy-k' => $k,
                'Content-Type' => 'application/x-www-form-urlencoded', 'header_clientlang' => 'de',
                'header_appversion' => self::APP_VERSION, 'header_phonesystem' => 'iOS', 'header_phonemodel' => 'iPhone16,1',
            ];
            $antwort = $this->anfrage('POST', $info['basis'] . '/api/v4/auth/email/login/code', $kopf, [], [
                'country' => $info['land'], 'countryCode' => $info['landcode'], 'email' => $konto, 'code' => $code,
                'majorVersion' => $version[0], 'minorVersion' => $version[1],
            ]);
        } else {
            $antwort = $this->anfrage('POST', $info['basis'] . '/api/v1/loginWithCode', $this->grundKopf($konto),
                ['username' => $konto, 'verifycode' => $code, 'verifycodetype' => 'AUTH_EMAIL_CODE'], null);
        }
        $this->pruefeCode($antwort, RoboHubText::t('Sign-in'));
        $nutzer = $antwort['data'] ?? null;
        if (!is_array($nutzer) || !isset($nutzer['rriot']['u'], $nutzer['rriot']['s'], $nutzer['rriot']['h'], $nutzer['rriot']['k'], $nutzer['token'])) {
            throw new RoboHubFehler(RoboHubText::t('Roborock did not deliver complete credentials.'));
        }
        return [
            'marke'  => 'roborock',
            'basis'  => $info['basis'],
            'konto'  => $konto,
            'token'  => (string)$nutzer['token'],
            'rriot'  => $nutzer['rriot'],
            'uid'    => $nutzer['uid'] ?? null,
        ];
    }

    /** Der Roborock-Zugang läuft nicht von selbst ab; abgelehnt wird er über HTTP 401. */
    public function erneuern(array $buendel): array
    {
        if (!isset($buendel['rriot']['u'], $buendel['token'])) {
            $e = new RoboHubFehler(RoboHubText::t('Credentials incomplete — please sign in again.'));
            $e->anmeldungNoetig = true;
            throw $e;
        }
        return $buendel;
    }

    /** Welcher Server kennt das Konto (und mit welchem Land)? */
    private function kontoServer(string $konto): array
    {
        $letzter = '';
        foreach (self::BASIS as $basis) {
            $antwort = $this->anfrage('POST', $basis . '/api/v1/getUrlByEmail', [], ['email' => $konto, 'needtwostepauth' => 'false'], null, false);
            $code = (int)($antwort['code'] ?? 0);
            if ($code === 2003) {
                throw new RoboHubFehler(RoboHubText::t('The e-mail address has an invalid format.'));
            }
            if ($code !== 200) {
                $letzter = (string)($antwort['msg'] ?? '');
                continue;
            }
            $d = (array)($antwort['data'] ?? []);
            if (($d['country'] ?? null) === null && ($d['countrycode'] ?? null) === null) {
                continue;
            }
            return [
                'basis'    => (string)($d['url'] ?? $basis),
                'land'     => (string)($d['country'] ?? ''),
                'landcode' => (string)($d['countrycode'] ?? ''),
            ];
        }
        throw new RoboHubFehler(RoboHubText::t('No Roborock account found with this e-mail%s.', ($letzter !== '' ? ' (' . $letzter . ')' : '')));
    }

    /** Aktuelle Version der Nutzungsbedingungen (Anmeldung lehnt alte mit 3006 ab). */
    private function vertragsVersion(array $info): array
    {
        try {
            $a = $this->anfrage('GET', $info['basis'] . '/api/v3/app/agreement/latest', ['header_clientlang' => 'de'], ['country' => $info['land']], null, false);
            $d = (array)($a['data'] ?? []);
            if ((int)($a['code'] ?? 0) === 200 && is_int($d['majorVersion'] ?? null) && is_int($d['minorVersion'] ?? null)) {
                return [$d['majorVersion'], $d['minorVersion']];
            }
        } catch (RoboHubFehler $e) {
            $this->dbg('Anmeldung', 'Vertragsversion nicht lesbar: ' . $e->getMessage());
        }
        return [1, 0];
    }

    private function pruefeCode(array $antwort, string $schritt): void
    {
        $code = (int)($antwort['code'] ?? 0);
        if ($code === 200) {
            return;
        }
        $texte = [
            2008 => RoboHubText::t('Account does not exist — check the e-mail.'),
            9002 => RoboHubText::t('Too many codes requested — please try again later.'),
            2018 => RoboHubText::t('The code is wrong or expired — request a new code.'),
            3009 => RoboHubText::t('Please first accept the terms of use in the Roborock app.'),
            3006 => RoboHubText::t('Accept the terms of use again (Roborock app) — or it is a Mi Home account.'),
            3039 => RoboHubText::t('Account not found in this region.'),
        ];
        throw new RoboHubFehler(RoboHubText::t('%s failed: %s', $schritt, $texte[$code] ?? RoboHubText::t('%s (code %s)', (string)($antwort['msg'] ?? RoboHubText::t('unknown')), $code)));
    }

    /** Stabile Client-Kennung je Konto (python-roborock: md5(Konto + Geräte-ID), base64). */
    private function grundKopf(string $konto): array
    {
        return ['header_clientid' => base64_encode(md5($konto . md5('RoboHub' . $konto), true))];
    }

    // =================================================================
    // Geräte und Räume (Web-API mit Hawk-Signatur)
    // =================================================================

    public function geraete(array $buendel): array
    {
        $heim = $this->heim($buendel);
        $produkte = [];
        foreach ((array)($heim['products'] ?? []) as $p) {
            $produkte[(string)($p['id'] ?? '')] = $p;
        }
        $raeume = [];
        foreach ((array)($heim['rooms'] ?? []) as $r) {
            $raeume[(string)($r['id'] ?? '')] = (string)($r['name'] ?? '');
        }
        $liste = [];
        foreach (array_merge((array)($heim['devices'] ?? []), (array)($heim['receivedDevices'] ?? [])) as $g) {
            $p = $produkte[(string)($g['productId'] ?? '')] ?? [];
            $modell = (string)($p['model'] ?? 'roborock.unbekannt');
            $liste[] = [
                'id'         => (string)($g['duid'] ?? ''),
                'name'       => (string)($g['name'] ?? ($p['name'] ?? 'Roborock')),
                'modell'     => $modell,
                'klasse'     => self::klasseVon($modell, (string)($p['category'] ?? '')),
                'online'     => !empty($g['online']),
                'schluessel' => (string)($g['localKey'] ?? ''),
                'protokoll'  => (string)($g['pv'] ?? ''),
                'heimRaeume' => $raeume,
                'bild'       => self::bildVon($p, $g),
                'roh'        => ['fv' => $g['fv'] ?? null, 'pv' => $g['pv'] ?? null, 'produkt' => $p['name'] ?? null, 'kategorie' => $p['category'] ?? null],
            ];
        }
        return $liste;
    }

    private function heim(array $buendel): array
    {
        $detail = $this->anfrage('GET', $buendel['basis'] . '/api/v1/getHomeDetail', ['Authorization' => (string)$buendel['token']] + $this->grundKopf((string)$buendel['konto']), [], null);
        $this->pruefeCode($detail, 'Hausdaten');
        $heimId = (string)($detail['data']['rrHomeId'] ?? '');
        if ($heimId === '') {
            throw new RoboHubFehler(RoboHubText::t('No home has been created in the Roborock account.'));
        }
        $pfad = '/v3/user/homes/' . $heimId;
        $antwort = $this->hawk($buendel, 'GET', $pfad);
        return (array)($antwort['result'] ?? []);
    }

    /** Raumnamen frisch aus der Web-API (Umbenennungen in der App). */
    private function heimRaeume(array $buendel): array
    {
        $raeume = [];
        foreach ((array)($this->heim($buendel)['rooms'] ?? []) as $r) {
            $raeume[(string)($r['id'] ?? '')] = (string)($r['name'] ?? '');
        }
        return $raeume;
    }

    private function hawk(array $buendel, string $methode, string $pfad): array
    {
        $r = (array)$buendel['rriot'];
        $basis = rtrim((string)($r['r']['a'] ?? ''), '/');
        if ($basis === '') {
            throw new RoboHubFehler(RoboHubText::t('Credentials without API address — please sign in again.'));
        }
        $antwort = $this->anfrage($methode, $basis . $pfad, ['Authorization' => self::hawkKopf($r, $pfad, time(), self::zufallsText(8))], [], null);
        if (empty($antwort['success'])) {
            throw new RoboHubFehler(RoboHubText::t('Roborock API: %s', (string)($antwort['msg'] ?? json_encode($antwort))));
        }
        return $antwort;
    }

    /** Hawk-Kopf wie python-roborock _get_hawk_authentication (ohne Parameter/Formular). */
    public static function hawkKopf(array $rriot, string $pfad, int $zeit, string $nonce): string
    {
        $vorlage = implode(':', [$rriot['u'], $rriot['s'], $nonce, (string)$zeit, md5($pfad), '', '']);
        $mac = base64_encode(hash_hmac('sha256', $vorlage, (string)$rriot['h'], true));
        return 'Hawk id="' . $rriot['u'] . '",s="' . $rriot['s'] . '",ts="' . $zeit . '",nonce="' . $nonce . '",mac="' . $mac . '"';
    }

    // =================================================================
    // Zustand
    // =================================================================

    public function zustand(array $buendel, array $geraet): array
    {
        $this->pruefeProtokoll($geraet);
        $antworten = $this->rpcMehrere($buendel, $geraet, [
            ['get_status', []],
            ['get_consumable', []],
            ['get_clean_summary', []],
            ['get_dnd_timer', []],
            ['get_sound_volume', []],
        ]);
        $p = [];
        $ok = [];
        $fehl = [];
        foreach (['status', 'teile', 'summe', 'dnd', 'laut'] as $i => $block) {
            $r = $antworten[$i] ?? null;
            if ($r === null || isset($r['fehler'])) {
                $fehl[] = $i;
                continue;
            }
            $ok[] = $i;
            $wert = $r['ergebnis'];
            if ($block === 'status' || $block === 'teile') {
                $p += (array)(is_array($wert) && isset($wert[0]) && is_array($wert[0]) ? $wert[0] : $wert);
            } elseif ($block === 'summe') {
                $p['summe'] = $wert;
            } elseif ($block === 'dnd') {
                $p['dnd'] = is_array($wert) && isset($wert[0]) ? $wert[0] : $wert;
            } else {
                $p['lautstaerke'] = is_array($wert) ? ($wert[0] ?? null) : $wert;
            }
        }
        if (count($ok) === 0) {
            throw RoboHubFehler::offline(RoboHubText::t('The Roborock does not respond (offline or sleeping?).'));
        }
        $daten = $this->normalisiere((string)($geraet['modell'] ?? ''), $p);
        $daten['online'] = true;
        if (($geraet['bild'] ?? '') !== '') {
            $daten['bild'] = (string)$geraet['bild'];
        }
        $daten['bloecke'] = ['ok' => $ok, 'fehl' => $fehl];
        $daten['roh'] = ['modell' => $geraet['modell'] ?? null, 'protokoll' => $geraet['protokoll'] ?? null, 'properties' => $p];
        return $daten;
    }

    public function normalisiere(string $modell, array $p): array
    {
        $w = [];
        $optionen = [];
        if (isset($p['state'])) {
            $roh = (int)$p['state'];
            [$text, $zustand] = self::ZUSTAND[$roh] ?? [RoboHubText::t('State %s', $roh), RoboHubModell::ZUSTAND_UNBEKANNT];
            $text = RoboHubText::wert($text);
            $w['Zustand'] = $zustand;
            $w['ZustandRoh'] = $text . ' (' . $roh . ')';
            // Lädt nur im Zustand 8 (HA binary_sensor/python-roborock get_dock_state). charge_status = 1 meldet
            // der Qrevo auch beim Putzen (Forum baraka 144636/89), sagt also allein nichts übers Laden.
            $w['Laedt'] = $roh === 8;
        }
        if (isset($p['error_code'])) {
            $code = (int)$p['error_code'];
            $w['Fehler'] = $code === 0 ? RoboHubText::t('no error') : RoboHubText::wert(self::FEHLER[$code] ?? 'Error code') . ' (' . $code . ')';
        }
        $w['Akku'] = isset($p['battery']) ? (int)$p['battery'] : null;
        $w['Dauer'] = isset($p['clean_time']) ? (int)round((int)$p['clean_time'] / 60) : null;
        $w['Flaeche'] = isset($p['clean_area']) ? (int)round((int)$p['clean_area'] / 1000000) : null;

        if (isset($p['fan_power'])) {
            $stufe = (int)$p['fan_power'];
            $liste = $stufe > 100 ? self::SAUGSTUFEN_NEU : self::SAUGSTUFEN_ALT;
            if (!isset($liste[$stufe])) {
                $liste[$stufe] = RoboHubText::t('Level %s', $stufe);
            }
            if ($stufe > 100 && self::kannNurWischen($modell)) {
                $liste[self::SAUG_AUS] = 'Off';   // 105 = Saugen aus (reines Wischen), nicht „Sanft"
            }
            $w['Saugstufe'] = $stufe;
            $optionen['Saugstufe'] = $liste;
        }
        // Reinigungsmodus aus Saugstufe, Wasser und Route (python-roborock get_current_cleaning_mode); nur bei Geräten mit Wischmodul.
        if (isset($p['water_box_mode'], $p['fan_power']) && (int)$p['water_box_mode'] >= 200) {
            $fan = (int)$p['fan_power'];
            $wasser = (int)$p['water_box_mode'];
            $route = isset($p['mop_mode']) ? (int)$p['mop_mode'] : null;
            $nurWischen = self::kannNurWischen($modell);
            $liste = [self::MODUS_SAUGEN => 'Vacuum'] + ($nurWischen ? [self::MODUS_WISCHEN => 'Mop'] : []) + [self::MODUS_BEIDES => RoboHubText::t('Vacuum and mop')];
            if ($fan === 110 || $wasser === 209 || $route === 306) {
                $modus = self::MODUS_SMART;
                $liste[$modus] = RoboHubText::t('SmartPlan (set in the app)');
            } elseif ($fan === 106 || $wasser === 204 || $route === 302) {
                $modus = self::MODUS_ANGEPASST;
                $liste[$modus] = RoboHubText::t('Custom (set in the app)');
            } elseif ($wasser !== self::WASSER_AUS && $nurWischen && $fan === self::SAUG_AUS) {
                $modus = self::MODUS_WISCHEN;
            } else {
                $modus = $wasser === self::WASSER_AUS ? self::MODUS_SAUGEN : self::MODUS_BEIDES;
            }
            $w['Reinigungsmodus'] = $modus;
            $optionen['Reinigungsmodus'] = $liste;
        }
        if (isset($p['water_box_mode']) && (int)$p['water_box_mode'] >= 200) {
            $menge = (int)$p['water_box_mode'];
            $regler = self::wasserreglerName($menge);
            $liste = $regler !== null ? self::WASSERREGLER_WAHL : self::WASSERMENGEN;
            if ($regler === null) {
                unset($liste[$menge === 207 ? 204 : 207]);
            }
            if (!isset($liste[$menge]) && $regler !== null) {
                $liste[$menge] = $regler;
            } elseif (!isset($liste[$menge]) && isset(self::WASSER_WEITERE[$menge])) {
                $liste[$menge] = self::WASSER_WEITERE[$menge];
            } elseif (!isset($liste[$menge])) {
                $liste[$menge] = RoboHubText::t('Level %s', $menge);
            }
            $w['Wassermenge'] = $menge;
            $optionen['Wassermenge'] = $liste;
        }

        foreach (self::TEILE as $feld => [$ident, $max, $sekunden]) {
            if (isset($p[$feld]) && is_numeric($p[$feld])) {
                $rest = max(0, $max - (int)$p[$feld]);
                $w[$ident] = (int)max(0, min(100, round($rest * 100 / $max)));
                if ($sekunden) {
                    $w[$ident . 'Rest'] = (float)round($rest / 3600);
                }
            }
        }

        // Station (dss, Bitfelder wie StatusV2: Frischwasser >>2, Schmutzwasser >>4, Staubbeutel >>6).
        // Nur 1 meldet ein Problem; jeder andere Wert gilt als in Ordnung (python-roborock RoborockDssCodes._missing_ →
        // okay). Forum lugge 13.11.2026: neuer Saros 20 meldet dss 2216 (alle drei Felder = 2) bei voller Station.
        if (!empty($p['dss']) && is_numeric($p['dss'])) {
            $dss = (int)$p['dss'];
            $w['Frischwasser'] = (($dss >> 2) & 3) === 1 ? RoboHubText::t('Empty or missing') : RoboHubText::t('OK');
            $w['Schmutzwasser'] = (($dss >> 4) & 3) === 1 ? RoboHubText::t('Full or missing') : RoboHubText::t('OK');
            $w['Staubbeutel'] = (($dss >> 6) & 3) === 1 ? RoboHubText::t('Full or missing') : RoboHubText::t('OK');
        }

        // Gesamtstatistik: Liste [Zeit s, Fläche mm², Anzahl, …] oder Objekt.
        if (isset($p['summe'])) {
            $s = $p['summe'];
            if (is_array($s) && array_key_exists(0, $s)) {
                $s = ['clean_time' => $s[0] ?? null, 'clean_area' => $s[1] ?? null, 'clean_count' => $s[2] ?? null];
            }
            if (is_array($s)) {
                $w['GesamtDauer'] = is_numeric($s['clean_time'] ?? null) ? (int)round((int)$s['clean_time'] / 3600) : null;
                $w['GesamtFlaeche'] = is_numeric($s['clean_area'] ?? null) ? (int)round((float)$s['clean_area'] / 1000000) : null;
                $w['GesamtAnzahl'] = is_numeric($s['clean_count'] ?? null) ? (int)$s['clean_count'] : null;
            }
        }

        $schreibbar = [];
        if (isset($p['dnd']) && is_array($p['dnd']) && isset($p['dnd']['start_hour'])) {
            $d = $p['dnd'];
            $w['NichtStoeren'] = !empty($d['enabled']);
            $w['NichtStoerenVon'] = sprintf('%02d:%02d', (int)$d['start_hour'], (int)($d['start_minute'] ?? 0));
            $w['NichtStoerenBis'] = sprintf('%02d:%02d', (int)($d['end_hour'] ?? 0), (int)($d['end_minute'] ?? 0));
            array_push($schreibbar, 'NichtStoeren', 'NichtStoerenVon', 'NichtStoerenBis');
        }
        if (isset($p['lautstaerke']) && is_numeric($p['lautstaerke'])) {
            $w['Lautstaerke'] = (int)$p['lautstaerke'];
            $schreibbar[] = 'Lautstaerke';
        }
        return ['werte' => $w, 'optionen' => $optionen, 'schreibbar' => $schreibbar];
    }

    // =================================================================
    // Räume
    // =================================================================

    public function karte(array $buendel, array $geraet): array
    {
        $this->pruefeProtokoll($geraet);
        $zuordnung = $this->rpc($buendel, $geraet, 'get_room_mapping', []);
        try {
            $namen = $this->heimRaeume($buendel);
        } catch (RoboHubFehler $e) {
            $namen = (array)($geraet['heimRaeume'] ?? []);
        }
        $raeume = [];
        foreach (self::raumZuordnung(is_array($zuordnung) ? $zuordnung : []) as $segment => $iot) {
            $raeume[$segment] = ($namen[$iot] ?? '') !== '' ? $namen[$iot] : 'Room ' . $segment;
        }
        ksort($raeume);
        $daten = ['raeume' => $raeume] + $this->kartenBild($buendel, $geraet, $raeume, false);
        $programme = $this->programmeLesen($buendel, $geraet);
        if ($programme !== null) {
            $daten['programme'] = $programme;
        }
        return $daten;
    }

    // =================================================================
    // Programme (in der App „Routinen", Cloud-Szenen)
    // Quelle: python-roborock web_api/scenes.py — GET /user/scene/device/{duid}
    // liefert [{id, name, …}], POST /user/scene/{id}/execute startet (Hawk).
    // =================================================================

    /** Programme dieses Roboters (ID => Name); null, wenn die Cloud sie nicht liefert. */
    public function programmeLesen(array $buendel, array $geraet): ?array
    {
        if (!isset($buendel['rriot'])) {
            return null;   // Mi-Home-Konto: keine Roborock-Routinen
        }
        try {
            $antwort = $this->hawk($buendel, 'GET', '/user/scene/device/' . (string)$geraet['id']);
        } catch (RoboHubFehler $e) {
            if ($e->anmeldungNoetig) {
                throw $e;
            }
            $this->dbg('Programme', 'Nicht abrufbar: ' . $e->getMessage());
            return null;
        }
        return self::programmListe($antwort['result'] ?? null);
    }

    /** Antwort result → [ID => Name], nur Einträge mit ID > 0; leerer Name → „Routine n". */
    public static function programmListe($liste): array
    {
        $aus = [];
        foreach (is_array($liste) ? $liste : [] as $p) {
            $id = (int)($p['id'] ?? 0);
            if ($id > 0) {
                $name = trim((string)($p['name'] ?? ''));
                $aus[$id] = $name !== '' ? $name : 'Routine ' . $id;
            }
        }
        return $aus;
    }

    private function programmStarten(array $buendel, array $parameter): string
    {
        if (!isset($buendel['rriot'])) {
            throw new RoboHubFehler(RoboHubText::t('Routines are only available with a Roborock account (not Mi Home).'));
        }
        $id = (int)($parameter['id'] ?? 0);
        if ($id <= 0) {
            throw new RoboHubFehler(RoboHubText::t('No routine given.'));
        }
        $this->hawk($buendel, 'POST', '/user/scene/' . $id . '/execute');
        $name = (string)($parameter['name'] ?? '');
        return RoboHubText::t('Routine "%s" started.', $name !== '' ? $name : (string)$id);
    }

    /** get_room_mapping: [[Segment, IoT-Raum-ID, …], …] oder ein einzelnes Paar. */
    public static function raumZuordnung(array $antwort): array
    {
        if (count($antwort) === 2 && !is_array($antwort[0])) {
            return [(int)$antwort[0] => (string)$antwort[1]];
        }
        $aus = [];
        foreach ($antwort as $teil) {
            if (is_array($teil) && count($teil) >= 2) {
                $aus[(int)$teil[0]] = (string)$teil[1];
            }
        }
        return $aus;
    }

    public function liveKarte(array $buendel, array $geraet): array
    {
        $this->pruefeProtokoll($geraet);
        $raeume = [];
        foreach ((array)($geraet['raeume'] ?? []) as $id => $name) {
            $raeume[(int)$id] = (string)$name;
        }
        return ['raeume' => []] + $this->kartenBild($buendel, $geraet, $raeume, true);
    }

    // =================================================================
    // Kartenbild (RoboHubRoborockKarte)
    // =================================================================

    /** Karte holen und zeichnen; ohne Karte leeres Bild (die Raumliste bleibt nutzbar). */
    protected function kartenBild(array $buendel, array $geraet, array $raeume, bool $live): array
    {
        $leer = ['bild' => '', 'marken' => [], 'station' => null];
        try {
            $rr = $this->karteAbrufen($buendel, $geraet);
        } catch (RoboHubFehler $e) {
            if ($e->anmeldungNoetig) {
                throw $e;
            }
            $this->dbg('Karte', 'Karte nicht abrufbar: ' . $e->getMessage());
            return $leer;
        }
        $roh = $rr === null ? null : RoboHubRoborockKarte::lesen($rr);
        if ($roh === null) {
            $this->dbg('Karte', $rr === null ? 'Keine Kartendatei erhalten.' : 'Kartendatei ohne lesbares Bild (' . strlen($rr) . ' Byte, Anfang ' . bin2hex(substr($rr, 0, 24)) . ').');
            return $leer;
        }
        $bild = RoboHubRoborockKarte::bild($roh, $raeume, $live, !empty($geraet['teppich']));
        $this->dbg('Karte', $roh['breite'] . '×' . $roh['hoehe'] . ' Punkte, Räume im Bild ' . json_encode($roh['raeume']) . ', Station ' . json_encode($roh['station'])
            . ', Roboter ' . json_encode($roh['roboter']) . ', Weg ' . count($roh['weg'][0] ?? []) . ' Punkte, ausgeblendet ' . $roh['entfernt']
            . ' Punkte, Teppich ' . ($roh['teppich'] === '' ? 'keiner' : substr_count($roh['teppich'], "\1") . ' Punkte') . '.');
        return $bild;
    }

    /**
     * get_map_v1 über MQTT; die Karte kommt als eigene Nachricht mit Protokoll 301 (große Karten als
     * Teile 300…301), verschlüsselt mit der Nonce dieser Anfrage (python-roborock v1_protocol.py
     * create_map_response_decoder). Rückgabe: „rr"-Datei oder null.
     */
    protected function karteAbrufen(array $buendel, array $geraet): ?string
    {
        $r = (array)$buendel['rriot'];
        $schluessel = (string)$geraet['schluessel'];
        [$host, $port] = self::mqttServer($r);
        $benutzer = substr(md5($r['u'] . ':' . $r['k']), 2, 8);
        $passwort = substr(md5($r['s'] . ':' . $r['k']), 16);
        $duid = (string)$geraet['id'];
        $endpunkt = base64_encode(substr(md5((string)$r['k'], true), 8, 6));
        $nonce = random_bytes(16);
        $id = random_int(10000, 32767);
        $zeit = time();
        $rahmen = [self::rahmenBauen(self::anfrageNutzlast($id, 'get_map_v1', [], $zeit, $endpunkt, bin2hex($nonce)),
            $schluessel, random_int(100000, 999999), random_int(10000, 99999), $zeit, self::PROTOKOLL_ANFRAGE)];
        $rr = null;
        $gruppen = [];
        $verarbeite = function (string $daten) use ($schluessel, $endpunkt, $nonce, $id, &$rr, &$gruppen): bool {
            foreach (self::rahmenLesen($daten, $schluessel) as $m) {
                $p = (int)$m['protokoll'];
                if ($p !== self::PROTOKOLL_KARTE && $p !== self::PROTOKOLL_KARTE_TEIL) {
                    continue;
                }
                $gruppe = $m['zufall'] . '/' . $m['zeit'];
                $letzter = $p === self::PROTOKOLL_KARTE;
                if (!$letzter || isset($gruppen[$gruppe]) || strpos($m['nutzlast'], $endpunkt) !== 0) {
                    $gruppen[$gruppe][(int)$m['seq']] = [$letzter, $m['nutzlast']];
                    $ganz = RoboHubRoborockKarte::teileZusammen($gruppen[$gruppe], $endpunkt);
                    if ($ganz === null) {
                        continue;
                    }
                    unset($gruppen[$gruppe]);
                } else {
                    $ganz = $m['nutzlast'];
                }
                $a = RoboHubRoborockKarte::kartenAntwort($ganz, $endpunkt, $nonce);
                if ($a !== null && $a['id'] === $id) {
                    $rr = $a['rr'];
                    return true;
                }
            }
            return false;
        };
        $this->austausch($host, $port, $benutzer, $passwort,
            'rr/m/o/' . $r['u'] . '/' . $benutzer . '/' . $duid,
            'rr/m/i/' . $r['u'] . '/' . $benutzer . '/' . $duid,
            $rahmen, $verarbeite, 15.0);
        $this->dbg('Karte', $rr === null ? 'Keine Kartenantwort (get_map_v1).' : 'Kartendatei ' . strlen($rr) . ' Byte.');
        return $rr;
    }

    // =================================================================
    // Befehle
    // =================================================================

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        if ($befehl === 'programm') {
            // Läuft über die Cloud, nicht über das Gerätprotokoll.
            return $this->programmStarten($buendel, $parameter);
        }
        $this->pruefeProtokoll($geraet);
        $stand = (array)($geraet['stand'] ?? []);
        switch ($befehl) {
            case 'start':
                // Unterbrochene Raum-/Zonenreinigung fortsetzen statt neu zu starten.
                $methode = 'app_start';
                if ((int)($stand['state'] ?? 0) === 10) {
                    $offen = (int)($stand['in_cleaning'] ?? 0);
                    $methode = $offen === 3 ? 'resume_segment_clean' : ($offen === 2 ? 'resume_zoned_clean' : 'app_start');
                }
                $this->rpc($buendel, $geraet, $methode, []);
                return RoboHubText::t('Command "Start" sent.');
            case 'pause':
                $this->rpc($buendel, $geraet, 'app_pause', []);
                return RoboHubText::t('Command "Pause" sent.');
            case 'stopp':
                $this->rpc($buendel, $geraet, 'app_stop', []);
                return RoboHubText::t('Command "Stop" sent.');
            case 'station':
                $this->rpc($buendel, $geraet, 'app_charge', []);
                return RoboHubText::t('Command "Return to base" sent.');
            case 'orten':
                $this->rpc($buendel, $geraet, 'find_me', []);
                return RoboHubText::t('Command "Locate" sent.');
            case 'raum':
                return $this->raumReinigung($buendel, $geraet, $parameter);
            case 'setze':
                return $this->setze($buendel, $geraet, (string)($parameter['feld'] ?? ''), (int)($parameter['wert'] ?? 0));
            case 'einstellung':
                return $this->einstellung($buendel, $geraet, (string)($parameter['feld'] ?? ''), $parameter['wert'] ?? null);
        }
        throw new RoboHubFehler(RoboHubText::t('This command is not supported for Roborock yet: %s', $befehl));
    }

    private function raumReinigung(array $buendel, array $geraet, array $parameter): string
    {
        $raeume = array_values(array_unique(array_filter(array_map('intval', (array)($parameter['raeume'] ?? [])), function (int $r): bool {
            return $r > 0;
        })));
        if (count($raeume) === 0) {
            throw new RoboHubFehler(RoboHubText::t('No room ID given.'));
        }
        $durchgaenge = (int)($parameter['durchgaenge'] ?? -1);
        if ($durchgaenge > 3 || $durchgaenge === 0) {
            throw new RoboHubFehler(RoboHubText::t('Passes 1 to 3.'));
        }
        // Erst der Modus (setzt Saugstufe, Wasser und Route zusammen), dann eine ausdrücklich gewählte Saugstufe.
        $modus = (int)($parameter['modus'] ?? -1);
        if ($modus >= 0) {
            $this->motorModusSetzen($buendel, $geraet, $modus);
        }
        $saug = (int)($parameter['saugstufe'] ?? -1);
        if ($saug >= 0 && $modus !== self::MODUS_WISCHEN) {
            if (!isset(self::SAUGSTUFEN_NEU[$saug]) && !isset(self::SAUGSTUFEN_ALT[$saug])) {
                throw new RoboHubFehler(RoboHubText::t('Unknown Roborock suction level %s.', $saug));
            }
            $this->rpc($buendel, $geraet, 'set_custom_mode', [$saug]);
        }
        $wiederholung = $durchgaenge >= 1 ? $durchgaenge : 1;
        try {
            $this->rpc($buendel, $geraet, 'app_segment_clean', [['segments' => $raeume, 'repeat' => $wiederholung]]);
        } catch (RoboHubFehler $e) {
            // Ältere Firmware kennt nur die einfache Liste der Segmente.
            $this->dbg('Befehl', 'Raumreinigung mit Objekt abgelehnt (' . $e->getMessage() . '), versuche einfache Liste.');
            $this->rpc($buendel, $geraet, 'app_segment_clean', $raeume);
        }
        return RoboHubText::t('Cleaning %s sent.', (count($raeume) === 1 ? RoboHubText::t('of one room') : RoboHubText::t('of %s rooms', count($raeume))));
    }

    /** Modelle mit reinem Wischen (Saugstufe 105 = aus); siehe OHNE_NUR_WISCHEN. */
    public static function kannNurWischen(string $modell): bool
    {
        $kurz = strtolower((string)substr((string)strrchr('.' . $modell, '.'), 1));
        return $kurz !== '' && !in_array($kurz, self::OHNE_NUR_WISCHEN, true);
    }

    /**
     * Parameter für set_clean_motor_mode (python-roborock get_cleaning_mode_parameters): Saugstufe, Wasser und —
     * wenn das Gerät eine Route meldet — Route. Die eingestellten Werte bleiben, soweit sie zum Modus passen;
     * sonst die Vorgaben der Bibliothek (Ausgewogen, Wasser Standard bzw. Regler-Mitte 235, Route Standard).
     */
    /**
     * Setzt den Modus. War das Wasser aus, ist unbekannt, ob das Gerät einen Wasser-Schieberegler hat (dann gilt
     * 202 nicht, python-roborock _get_default_mopping_water_mode) — lehnt es 202 ab, einmal mit der Regler-Mitte 235.
     */
    private function motorModusSetzen(array $buendel, array $geraet, int $modus): void
    {
        $p = $this->motorModus($geraet, $modus);
        try {
            $this->rpc($buendel, $geraet, 'set_clean_motor_mode', $p);
        } catch (RoboHubFehler $e) {
            if (($p[0]['water_box_mode'] ?? 0) !== 202 || (int)(($geraet['stand'] ?? [])['water_box_mode'] ?? 0) === 202) {
                throw $e;
            }
            $this->dbg('Befehl', 'Wasser 202 abgelehnt (' . $e->getMessage() . ') — Gerät mit Wasser-Regler? Neuer Versuch mit 235.');
            $p[0]['water_box_mode'] = 235;
            $this->rpc($buendel, $geraet, 'set_clean_motor_mode', $p);
        }
    }

    private function motorModus(array $geraet, int $modus): array
    {
        $stand = (array)($geraet['stand'] ?? []);
        $modell = (string)($geraet['modell'] ?? '');
        if (!in_array($modus, [self::MODUS_SAUGEN, self::MODUS_WISCHEN, self::MODUS_BEIDES], true)) {
            throw new RoboHubFehler(RoboHubText::t('This cleaning mode can only be chosen in the Roborock app.'));
        }
        if (!isset($stand['water_box_mode'])) {
            throw new RoboHubFehler(RoboHubText::t('This robot has no mop — the cleaning mode cannot be chosen.'));
        }
        if ($modus === self::MODUS_WISCHEN && !self::kannNurWischen($modell)) {
            throw new RoboHubFehler(RoboHubText::t('This robot cannot mop without vacuuming.'));
        }
        $fan = (int)($stand['fan_power'] ?? 102);
        if (in_array($fan, [self::SAUG_AUS, 106, 110], true) || $fan < 101) {
            $fan = 102;
        }
        $wasser = (int)$stand['water_box_mode'];
        $regler = self::wasserreglerName($wasser) !== null && $wasser !== self::WASSER_AUS;
        if (in_array($wasser, [self::WASSER_AUS, 204, 209, 210], true)) {
            $wasser = ($regler || $wasser === 210) ? 235 : 202;
        }
        $p = [
            'fan_power'      => $modus === self::MODUS_WISCHEN ? self::SAUG_AUS : $fan,
            'water_box_mode' => $modus === self::MODUS_SAUGEN ? self::WASSER_AUS : $wasser,
        ];
        if (isset($stand['mop_mode'])) {
            $route = (int)$stand['mop_mode'];
            $p['mop_mode'] = in_array($route, [302, 306], true) ? 300 : $route;
        }
        $this->dbg('Befehl', 'Reinigungsmodus ' . $modus . ' → set_clean_motor_mode ' . json_encode($p));
        return [$p];
    }

    private function setze(array $buendel, array $geraet, string $feld, int $wert): string
    {
        if ($feld === 'Saugstufe') {
            if (!isset(self::SAUGSTUFEN_NEU[$wert]) && !isset(self::SAUGSTUFEN_ALT[$wert])) {
                throw new RoboHubFehler(RoboHubText::t('Unknown suction level %s.', $wert));
            }
            $this->rpc($buendel, $geraet, 'set_custom_mode', [$wert]);
            return RoboHubText::t('Suction level set.');
        }
        if ($feld === 'Reinigungsmodus') {
            $this->motorModusSetzen($buendel, $geraet, $wert);
            return RoboHubText::t('Cleaning mode set.');
        }
        if ($feld === 'Wassermenge') {
            if (!isset(self::WASSERMENGEN[$wert]) && self::wasserreglerName((int)$wert) === null) {
                throw new RoboHubFehler(RoboHubText::t('Unknown water level %s.', $wert));
            }
            $this->rpc($buendel, $geraet, 'set_water_box_custom_mode', [$wert]);
            return RoboHubText::t('Water level set.');
        }
        throw new RoboHubFehler(RoboHubText::t('"%s" cannot be set on Roborock (yet).', $feld));
    }

    private function einstellung(array $buendel, array $geraet, string $feld, $wert): string
    {
        if ($feld === 'Lautstaerke') {
            $v = (int)$wert;
            if ($v < 0 || $v > 100) {
                throw new RoboHubFehler(RoboHubText::t('Volume 0 to 100.'));
            }
            $this->rpc($buendel, $geraet, 'change_sound_volume', [$v]);
            return RoboHubText::t('Volume set.');
        }
        if (!in_array($feld, ['NichtStoeren', 'NichtStoerenVon', 'NichtStoerenBis'], true)) {
            throw new RoboHubFehler(RoboHubText::t('Unknown setting: %s', $feld));
        }
        // Read-Modify-Write: aktuelles Fenster lesen, nur das eine Feld ändern.
        $d = $this->rpc($buendel, $geraet, 'get_dnd_timer', []);
        $d = is_array($d) && isset($d[0]) ? $d[0] : $d;
        if (!is_array($d) || !isset($d['start_hour'])) {
            throw new RoboHubFehler(RoboHubText::t('"Do not disturb" could not be read — nothing changed.'));
        }
        $an = !empty($d['enabled']);
        $von = [(int)$d['start_hour'], (int)($d['start_minute'] ?? 0)];
        $bis = [(int)($d['end_hour'] ?? 0), (int)($d['end_minute'] ?? 0)];
        if ($feld === 'NichtStoeren') {
            if (!$wert) {
                $this->rpc($buendel, $geraet, 'close_dnd_timer', []);
                return RoboHubText::t('"Do not disturb" switched off.');
            }
            $this->rpc($buendel, $geraet, 'set_dnd_timer', [$von[0], $von[1], $bis[0], $bis[1]]);
            return RoboHubText::t('"Do not disturb" switched on.');
        }
        $t = trim((string)$wert);
        if (!RoboHubModell::istUhrzeit($t)) {
            throw new RoboHubFehler(RoboHubText::t('Please enter the time as HH:MM (e.g. 22:00).'));
        }
        [$h, $m] = array_map('intval', explode(':', $t));
        if ($feld === 'NichtStoerenVon') {
            $von = [$h, $m];
        } else {
            $bis = [$h, $m];
        }
        if ($von === $bis) {
            throw new RoboHubFehler(RoboHubText::t('Start and end must not be the same.'));
        }
        if (!$an) {
            // set_dnd_timer schaltet bei Roborock zugleich ein — ausgeschaltet nichts ungefragt einschalten.
            throw new RoboHubFehler(RoboHubText::t('"Do not disturb" is off — please switch it on first, then change the time.'));
        }
        $this->rpc($buendel, $geraet, 'set_dnd_timer', [$von[0], $von[1], $bis[0], $bis[1]]);
        return RoboHubText::t('Time for "Do not disturb" set.');
    }

    // reset_consumable mit dem Feldnamen (consumeable.py ConsumableAttribute).
    private const RUECKSETZEN = [
        'Hauptbuerste' => 'main_brush_work_time', 'Seitenbuerste' => 'side_brush_work_time',
        'Filter' => 'filter_work_time', 'Sensoren' => 'sensor_dirty_time', 'Mopp' => 'moproller_work_time',
        'Waschwanne' => 'strainer_work_times', 'Reinigungsbuerste' => 'cleaning_brush_work_times',
    ];

    public function zuruecksetzen(array $buendel, array $geraet, string $teil): string
    {
        $this->pruefeProtokoll($geraet);
        if (!isset(self::RUECKSETZEN[$teil])) {
            throw new RoboHubFehler(RoboHubText::t('This part cannot be reset on Roborock: %s', $teil));
        }
        $this->rpc($buendel, $geraet, 'reset_consumable', [self::RUECKSETZEN[$teil]]);
        return RoboHubText::t('Reset sent.');
    }

    // =================================================================
    // Echtzeit über die Konto-Verbindung: noch nicht gebaut
    // =================================================================

    // -----------------------------------------------------------------
    // Dauerhafte Verbindung über den Client Socket des Kontos (0.50.0) — wie python-roborock
    // (eine MQTT-Sitzung je Konto, rr/m/o/… je Gerät abonniert).
    // -----------------------------------------------------------------

    public function echtzeitMoeglich(): bool
    {
        return true;
    }

    /** Server aus rriot.r.m; ohne Anmeldung leer. */
    public function mqttZiel(array $geraete, array $buendel): array
    {
        if (!isset($buendel['rriot'])) {
            return ['', 0];
        }
        return self::mqttServer((array)$buendel['rriot']);
    }

    public function mqttTopic(array $geraet, array $buendel): string
    {
        [$benutzer] = self::mqttAnmeldung((array)($buendel['rriot'] ?? []));
        return 'rr/m/o/' . ($buendel['rriot']['u'] ?? '') . '/' . $benutzer . '/' . (string)$geraet['id'];
    }

    public function mqttZugang(array $buendel): array
    {
        return self::mqttAnmeldung((array)($buendel['rriot'] ?? []));
    }

    /** Benutzer md5(u:k)[2:10], Passwort md5(s:k)[16:] (python-roborock mqtt/session). */
    public static function mqttAnmeldung(array $r): array
    {
        return [substr(md5(($r['u'] ?? '') . ':' . ($r['k'] ?? '')), 2, 8), substr(md5(($r['s'] ?? '') . ':' . ($r['k'] ?? '')), 16)];
    }

    /** Wurzelzertifikat für den Client Socket des Kontos (statt Dreames eigener Wurzel). */
    public function mqttWurzel(): string
    {
        return self::MQTT_WURZEL;
    }

    /** Abgewiesene Daueranmeldung (CONNACK 4/5) = Drosselung: so lange pausiert das Konto (wie connackFehler). */
    public function echtzeitDrosselSekunden(int $code): int
    {
        return in_array($code, [4, 5], true) ? self::MQTT_DROSSEL_S : 0;
    }

    public function pushLesen(string $payload): array
    {
        return [];
    }

    /**
     * Unaufgeforderte Meldung eines Geräts (Thema rr/m/o/…/<duid>) → [duid => [Feld => Wert]].
     * Antworten auf Anfragen (dps 102) holt sich die wartende Anfrage selbst — hier übergangen.
     */
    public function pushLesenThema(string $thema, string $payload, array $buendel, array $geraete): array
    {
        $duid = (string)substr($thema, (int)strrpos($thema, '/') + 1);
        $schluessel = '';
        foreach ($geraete as $g) {
            if ((string)($g['id'] ?? '') === $duid) {
                $schluessel = (string)($g['schluessel'] ?? '');
            }
        }
        if ($duid === '' || $schluessel === '') {
            return [];
        }
        $delta = [];
        foreach (self::rahmenLesen($payload, $schluessel) as $n) {
            if ($n['protokoll'] !== self::PROTOKOLL_ANTWORT) {
                continue;
            }
            $dps = json_decode($n['nutzlast'], true)['dps'] ?? null;
            if (!is_array($dps)) {
                continue;
            }
            foreach (self::PUSH_FELDER as $nr => $feld) {
                if (isset($dps[(string)$nr]) && is_numeric($dps[(string)$nr])) {
                    $delta[$feld] = (int)$dps[(string)$nr];
                }
            }
        }
        return $delta === [] ? [] : [$duid => $delta];
    }

    // =================================================================
    // Gerät-RPC über den Roborock-MQTT-Server
    // =================================================================

    protected function pruefeProtokoll(array $geraet): void
    {
        $pv = (string)($geraet['protokoll'] ?? '1.0');
        if ($pv !== '' && $pv !== '1.0') {
            throw new RoboHubFehler(RoboHubText::t('This Roborock device speaks the protocol "%s", which RoboHub does not support yet.', $pv));
        }
        if ((string)($geraet['schluessel'] ?? '') === '') {
            throw new RoboHubFehler(RoboHubText::t('Device key missing — please click "Search devices now" in the account instance.'));
        }
    }

    /** Eine Anfrage; Rückgabe: result oder RoboHubFehler. */
    protected function rpc(array $buendel, array $geraet, string $methode, array $params)
    {
        $r = $this->rpcMehrere($buendel, $geraet, [[$methode, $params]])[0] ?? null;
        if ($r === null) {
            throw new RoboHubFehler(RoboHubText::t('The Roborock did not respond to "%s" (offline?).', $methode));
        }
        if (isset($r['fehler'])) {
            throw new RoboHubFehler(RoboHubText::t('The Roborock rejects "%s": %s', $methode, $r['fehler']));
        }
        return $r['ergebnis'];
    }

    /**
     * Mehrere Anfragen in einer MQTT-Sitzung. Rückgabe je Index:
     * ['ergebnis' => …] | ['fehler' => Text] | null (keine Antwort).
     */
    protected function rpcMehrere(array $buendel, array $geraet, array $anfragen): array
    {
        $r = (array)$buendel['rriot'];
        $schluessel = (string)$geraet['schluessel'];
        [$host, $port] = self::mqttServer($r);
        $benutzer = substr(md5($r['u'] . ':' . $r['k']), 2, 8);
        $passwort = substr(md5($r['s'] . ':' . $r['k']), 16);
        $duid = (string)$geraet['id'];
        $endpunkt = base64_encode(substr(md5((string)$r['k'], true), 8, 6));

        $rahmen = [];
        $idZuIndex = [];
        foreach (array_values($anfragen) as $i => [$methode, $params]) {
            $id = random_int(10000, 32767);
            while (isset($idZuIndex[$id])) {
                $id = random_int(10000, 32767);
            }
            $idZuIndex[$id] = $i;
            $zeit = time();
            $nutzlast = self::anfrageNutzlast($id, (string)$methode, $params, $zeit, $endpunkt, bin2hex(random_bytes(16)));
            $rahmen[] = self::rahmenBauen($nutzlast, $schluessel, random_int(100000, 999999), random_int(10000, 99999), $zeit, self::PROTOKOLL_ANFRAGE);
        }

        $ergebnisse = array_fill(0, count($anfragen), null);
        $offen = count($anfragen);
        $verarbeite = function (string $daten) use ($schluessel, &$idZuIndex, &$ergebnisse, &$offen): bool {
            foreach (self::rahmenLesen($daten, $schluessel) as $nachricht) {
                if ($nachricht['protokoll'] !== self::PROTOKOLL_ANTWORT) {
                    continue;
                }
                $antwort = self::antwortLesen($nachricht['nutzlast']);
                if ($antwort === null || !isset($idZuIndex[$antwort['id']])) {
                    continue;
                }
                $i = $idZuIndex[$antwort['id']];
                unset($idZuIndex[$antwort['id']]);
                $ergebnisse[$i] = $antwort['fehler'] !== null ? ['fehler' => $antwort['fehler']] : ['ergebnis' => $antwort['ergebnis']];
                $offen--;
            }
            return $offen <= 0;
        };
        $this->austausch($host, $port, $benutzer, $passwort,
            'rr/m/o/' . $r['u'] . '/' . $benutzer . '/' . $duid,
            'rr/m/i/' . $r['u'] . '/' . $benutzer . '/' . $duid,
            $rahmen, $verarbeite, 10.0);
        $this->dbg('Roborock', count($anfragen) - $offen . ' von ' . count($anfragen) . ' Anfragen beantwortet.');
        return $ergebnisse;
    }

    /** Host/Port aus rriot.r.m („ssl://mqtt-eu-3.roborock.com:8883"). */
    public static function mqttServer(array $rriot): array
    {
        $teile = parse_url((string)($rriot['r']['m'] ?? ''));
        if (!isset($teile['host'], $teile['port'])) {
            throw new RoboHubFehler(RoboHubText::t('Credentials without MQTT address — please sign in again.'));
        }
        return [(string)$teile['host'], (int)$teile['port']];
    }

    /** Nutzlast einer Anfrage (v1_protocol.py RequestMessage._as_payload mit SecurityData). */
    public static function anfrageNutzlast(int $id, string $methode, array $params, int $zeit, string $endpunkt, string $nonceHex): string
    {
        $innen = json_encode([
            'id' => $id, 'method' => $methode, 'params' => $params,
            'security' => ['endpoint' => $endpunkt, 'nonce' => $nonceHex],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return (string)json_encode(['dps' => ['101' => $innen], 't' => $zeit], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Antwort-Nutzlast → ['id', 'ergebnis', 'fehler'] oder null. */
    public static function antwortLesen(string $nutzlast): ?array
    {
        $j = json_decode($nutzlast, true);
        $innen = is_array($j) ? ($j['dps']['102'] ?? null) : null;
        if (!is_string($innen)) {
            return null;
        }
        $a = json_decode($innen, true);
        if (!is_array($a) || !isset($a['id'])) {
            return null;
        }
        $fehler = null;
        if (isset($a['error'])) {
            $fehler = is_array($a['error']) ? (string)($a['error']['message'] ?? json_encode($a['error'])) : (string)$a['error'];
        }
        $ergebnis = $a['result'] ?? null;
        if ($fehler === null && $ergebnis === 'unknown_method') {
            $fehler = RoboHubText::t('Command unknown (model cannot do this)');
        } elseif ($fehler === null && is_string($ergebnis) && $ergebnis !== 'ok') {
            $fehler = RoboHubText::t('unexpected response "%s"', $ergebnis);
        } elseif ($fehler === null && $ergebnis === null) {
            $fehler = RoboHubText::t('Response without result');
        }
        return ['id' => (int)$a['id'], 'ergebnis' => $ergebnis, 'fehler' => $fehler];
    }

    // -----------------------------------------------------------------
    // Rahmen (protocol.py _Message, Version „1.0", ohne Längenpräfix bei MQTT)
    // -----------------------------------------------------------------

    /** Zeitstempel als 8 Hex-Zeichen, umsortiert (Utils.encode_timestamp). */
    public static function zeitKodiert(int $zeit): string
    {
        $hex = str_pad(dechex($zeit), 8, '0', STR_PAD_LEFT);
        $aus = '';
        foreach ([5, 6, 3, 7, 1, 2, 0, 4] as $i) {
            $aus .= $hex[$i];
        }
        return $aus;
    }

    private static function rahmenSchluessel(int $zeit, string $lokalSchluessel): string
    {
        return md5(self::zeitKodiert($zeit) . $lokalSchluessel . self::SALZ, true);
    }

    public static function rahmenBauen(string $nutzlast, string $lokalSchluessel, int $seq, int $zufall, int $zeit, int $protokoll): string
    {
        $kopf = '1.0' . pack('NNNn', $seq, $zufall, $zeit, $protokoll);
        $verschluesselt = $nutzlast === '' ? '' : (string)openssl_encrypt($nutzlast, 'aes-128-ecb', self::rahmenSchluessel($zeit, $lokalSchluessel), OPENSSL_RAW_DATA);
        $nachricht = $kopf . pack('n', strlen($verschluesselt)) . $verschluesselt;
        return $nachricht . pack('N', crc32($nachricht));
    }

    /** Liest alle Rahmen aus einem MQTT-Inhalt; ungültige werden übergangen. */
    public static function rahmenLesen(string $daten, string $lokalSchluessel): array
    {
        $aus = [];
        $pos = 0;
        $n = strlen($daten);
        while ($n - $pos >= 19) {
            if (substr($daten, $pos, 3) !== '1.0') {
                $naechste = strpos($daten, '1.0', $pos + 1);
                if ($naechste === false) {
                    break;
                }
                $pos = $naechste;
                continue;
            }
            $k = unpack('Nseq/Nzufall/Nzeit/nprotokoll/nlaenge', substr($daten, $pos + 3, 16));
            $ende = $pos + 19 + $k['laenge'];
            if ($ende + 4 > $n) {
                break;
            }
            $nachricht = substr($daten, $pos, 19 + $k['laenge']);
            $pruef = unpack('N', substr($daten, $ende, 4))[1];
            if ($pruef !== crc32($nachricht)) {
                $pos += 3;
                continue;
            }
            $inhalt = '';
            if ($k['laenge'] > 0) {
                $inhalt = openssl_decrypt(substr($nachricht, 19), 'aes-128-ecb', self::rahmenSchluessel($k['zeit'], $lokalSchluessel), OPENSSL_RAW_DATA);
                if ($inhalt === false) {
                    $pos = $ende + 4;
                    continue;
                }
            }
            $aus[] = ['seq' => $k['seq'], 'zufall' => $k['zufall'], 'zeit' => $k['zeit'], 'protokoll' => $k['protokoll'], 'nutzlast' => (string)$inhalt];
            $pos = $ende + 4;
        }
        return $aus;
    }

    // -----------------------------------------------------------------
    // MQTT-Sitzung (RoboHubMqttSitzung, TLS geprüft gegen MQTT_WURZEL).
    // Eigene Methode, damit der Prüfstand sie ersetzen kann.
    // -----------------------------------------------------------------

    /**
     * Verbindet, abonniert $abo, schickt alle $rahmen an $ziel und reicht jeden
     * empfangenen Inhalt an $verarbeite, bis dieser true liefert oder $frist
     * Sekunden um sind.
     */
    /** Weiche: über die dauerhafte Verbindung des Kontos, sonst eine eigene kurze Sitzung. */
    private function austausch(string $host, int $port, string $benutzer, string $passwort, string $abo, string $ziel, array $rahmen, callable $verarbeite, float $frist): void
    {
        if (is_callable($this->transport)) {
            // Über die dauerhafte Verbindung des Kontos — keine neue Anmeldung beim Roborock-Server.
            $this->dbg('Roborock', 'über die dauerhafte Verbindung des Kontos');
            ($this->transport)($abo, $ziel, $rahmen, $verarbeite, $frist);
            return;
        }
        $this->mqttAustausch($host, $port, $benutzer, $passwort, $abo, $ziel, $rahmen, $verarbeite, $frist);
    }

    protected function mqttAustausch(string $host, int $port, string $benutzer, string $passwort, string $abo, string $ziel, array $rahmen, callable $verarbeite, float $frist): void
    {
        $ende = microtime(true) + $frist;
        $sitzung = RoboHubMqttSitzung::oeffnen($host, $port, self::MQTT_WURZEL, null, $ende);
        try {
            $sitzung->senden(RoboHubMqtt::connect('robohub_' . bin2hex(random_bytes(6)), $benutzer, $passwort, 30));
            $p = $sitzung->warte(RoboHubMqtt::CONNACK, null, $ende);
            if ($p === null) {
                throw new RoboHubFehler(RoboHubText::t('Roborock server did not answer the sign-in.'));
            }
            $code = RoboHubMqtt::connackCode($p['body']);
            if ($code !== 0) {
                throw self::connackFehler($code);
            }
            $sitzung->senden(RoboHubMqtt::subscribe(1, [$abo], 0));
            if ($sitzung->warte(RoboHubMqtt::SUBACK, null, $ende) === null) {
                throw new RoboHubFehler(RoboHubText::t('Roborock server did not confirm the subscription.'));
            }
            foreach ($rahmen as $r) {
                $sitzung->senden(RoboHubMqtt::publish($ziel, $r));
            }
            $sitzung->warte(RoboHubMqtt::PUBLISH, function (array $paket) use ($abo, $verarbeite): bool {
                $pub = RoboHubMqtt::publishLesen($paket['flags'], $paket['body']);
                return $pub['topic'] === $abo && $verarbeite($pub['payload']);
            }, $ende);
        } finally {
            $sitzung->schliessen();
        }
    }

    // =================================================================
    // Hilfen
    // =================================================================

    public static function klasseVon(string $modell, string $kategorie = ''): string
    {
        return (stripos($modell, 'mower') !== false || stripos($kategorie, 'mower') !== false)
            ? RoboHubModell::KLASSE_MAEHER : RoboHubModell::KLASSE_SAUGER;
    }

    private static function bildVon(array $produkt, array $geraet): string
    {
        foreach ([$produkt['iconUrl'] ?? null, $geraet['iconUrl'] ?? null] as $url) {
            if (is_string($url) && strpos($url, 'https://') === 0) {
                return $url;
            }
        }
        return '';
    }

    private static function zufallsText(int $laenge): string
    {
        $zeichen = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $aus = '';
        for ($i = 0; $i < $laenge; $i++) {
            $aus .= $zeichen[random_int(0, strlen($zeichen) - 1)];
        }
        return $aus;
    }

    /**
     * HTTP-Anfrage an die Roborock-API; Rückgabe: JSON als Array.
     * $pruefen = false: auch HTTP-Fehler als (leeres) Array zurück.
     */
    private function anfrage(string $methode, string $url, array $kopf, array $query, ?array $formular, bool $pruefen = true): array
    {
        if ($query !== []) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }
        $zeilen = [];
        foreach ($kopf as $k => $v) {
            $zeilen[] = $k . ': ' . $v;
        }
        $body = $formular !== null ? http_build_query($formular) : ($methode === 'POST' ? '' : null);
        [$status, $text] = $this->http($methode, $url, $zeilen, $body);
        $this->dbg('HTTP', $methode . ' ' . preg_replace('/(email|username)=[^&]*/', '$1=…', $url) . ' → ' . $status);
        if ($status === 0) {
            throw new RoboHubFehler(RoboHubText::t('Roborock not reachable: %s', $text));
        }
        if ($status === 401) {
            $e = new RoboHubFehler(RoboHubText::t('Roborock rejected the access — please sign in again.'));
            $e->anmeldungNoetig = true;
            throw $e;
        }
        if ($status === 429) {
            $e = new RoboHubFehler(RoboHubText::t('Roborock is throttling right now (too many requests).'));
            $e->warteSekunden = 600;
            throw $e;
        }
        $j = json_decode($text, true);
        if (!is_array($j)) {
            if (!$pruefen) {
                return [];
            }
            throw new RoboHubFehler(RoboHubText::t('Unexpected response from Roborock (HTTP %s).', $status));
        }
        return $j;
    }

    /** Wie RoboHubDreame::http — curl, damit POST geht; im Prüfstand ersetzbar. */
    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $methode,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
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

    protected function dbg(string $thema, string $text): void
    {
        if (is_callable($this->debug)) {
            ($this->debug)($thema, $text);
        }
    }
}
