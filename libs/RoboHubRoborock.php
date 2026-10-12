<?php

declare(strict_types=1);

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
// Noch nicht gebaut: Kartenbild, Echtzeit über die Konto-Verbindung,
// Zeitpläne, Geräte mit den Protokollen A01/B01 (werden als „noch nicht
// unterstützt" gemeldet).
// ===========================================================================

class RoboHubRoborock implements RoboHubTreiber
{
    /** @var callable|null fn(string $thema, string $text) */
    public $debug = null;

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
        1 => ['Startet', RoboHubModell::ZUSTAND_REINIGT],
        2 => ['Vom Ladegerät getrennt', RoboHubModell::ZUSTAND_BEREIT],
        3 => ['Bereit', RoboHubModell::ZUSTAND_BEREIT],
        4 => ['Fernsteuerung', RoboHubModell::ZUSTAND_REINIGT],
        5 => ['Reinigt', RoboHubModell::ZUSTAND_REINIGT],
        6 => ['Kehrt zurück', RoboHubModell::ZUSTAND_ZURUECK],
        7 => ['Manueller Betrieb', RoboHubModell::ZUSTAND_REINIGT],
        8 => ['Lädt', RoboHubModell::ZUSTAND_LAEDT],
        9 => ['Ladeproblem', RoboHubModell::ZUSTAND_FEHLER],
        10 => ['Pausiert', RoboHubModell::ZUSTAND_PAUSIERT],
        11 => ['Punktreinigung', RoboHubModell::ZUSTAND_REINIGT],
        12 => ['Fehler', RoboHubModell::ZUSTAND_FEHLER],
        13 => ['Schaltet ab', RoboHubModell::ZUSTAND_SCHLAF],
        14 => ['Aktualisiert', RoboHubModell::ZUSTAND_AKTUALISIERUNG],
        15 => ['Dockt an', RoboHubModell::ZUSTAND_ZURUECK],
        16 => ['Fährt zum Ziel', RoboHubModell::ZUSTAND_REINIGT],
        17 => ['Zonenreinigung', RoboHubModell::ZUSTAND_REINIGT],
        18 => ['Raumreinigung', RoboHubModell::ZUSTAND_REINIGT],
        22 => ['Entleert den Staubbehälter', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        23 => ['Wäscht den Mopp', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        25 => ['Wäscht den Mopp', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        26 => ['Fährt zum Moppwaschen', RoboHubModell::ZUSTAND_ZURUECK],
        28 => ['Im Gespräch', RoboHubModell::ZUSTAND_BEREIT],
        29 => ['Kartiert', RoboHubModell::ZUSTAND_KARTIERUNG],
        30 => ['Reinigt', RoboHubModell::ZUSTAND_REINIGT],
        32 => ['Patrouille', RoboHubModell::ZUSTAND_REINIGT],
        33 => ['Setzt den Mopp ein', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        34 => ['Nimmt den Mopp ab', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        100 => ['Geladen', RoboHubModell::ZUSTAND_GELADEN],
        101 => ['Offline', RoboHubModell::ZUSTAND_UNBEKANNT],
        103 => ['Gesperrt', RoboHubModell::ZUSTAND_FEHLER],
        202 => ['Trocknung endet', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        6301 => ['Wischt', RoboHubModell::ZUSTAND_REINIGT],
        6302 => ['Saugt und wischt', RoboHubModell::ZUSTAND_REINIGT],
        6303 => ['Wischt', RoboHubModell::ZUSTAND_REINIGT],
        6304 => ['Wischt Räume', RoboHubModell::ZUSTAND_REINIGT],
        6305 => ['Saugt und wischt Räume', RoboHubModell::ZUSTAND_REINIGT],
        6306 => ['Wischt Räume', RoboHubModell::ZUSTAND_REINIGT],
        6307 => ['Wischt Zonen', RoboHubModell::ZUSTAND_REINIGT],
        6308 => ['Saugt und wischt Zonen', RoboHubModell::ZUSTAND_REINIGT],
        6309 => ['Wischt Zonen', RoboHubModell::ZUSTAND_REINIGT],
        6310 => ['Kehrt zum Waschen zurück', RoboHubModell::ZUSTAND_ZURUECK],
    ];

    // Fehlercodes (RoborockErrorCode), die häufigen auf Deutsch.
    private const FEHLER = [
        1 => 'Lasersensor blockiert', 2 => 'Stoßfänger klemmt', 3 => 'Räder in der Luft', 4 => 'Absturzsensor',
        5 => 'Hauptbürste blockiert', 6 => 'Seitenbürste blockiert', 7 => 'Räder blockiert', 8 => 'Roboter steckt fest',
        9 => 'Staubbehälter fehlt', 10 => 'Filter nass oder verstopft', 11 => 'Starkes Magnetfeld', 12 => 'Akku schwach',
        13 => 'Ladefehler', 14 => 'Akkufehler', 15 => 'Wandsensor verschmutzt', 16 => 'Roboter gekippt',
        17 => 'Seitenbürstenfehler', 18 => 'Lüfterfehler', 19 => 'Station ohne Strom', 20 => 'Bodensensor verschmutzt',
        21 => 'Stoßfänger gedrückt', 22 => 'Station nicht gefunden', 23 => 'Rückkehr zur Station fehlgeschlagen',
        24 => 'Sperrzone erkannt', 25 => 'Kamerafehler', 26 => 'Wandsensorfehler', 27 => 'Moppanhebung klemmt',
        28 => 'Roboter auf Teppich', 29 => 'Filter verstopft', 30 => 'Virtuelle Wand erkannt', 31 => 'Teppich nicht überquerbar',
        32 => 'Interner Fehler', 34 => 'Absaugstation reinigen', 35 => 'Absaugstation Spannungsfehler',
        36 => 'Waschrolle blockiert', 37 => 'Waschrolle nicht abgesenkt', 38 => 'Frischwassertank prüfen',
        39 => 'Schmutzwassertank prüfen', 40 => 'Wasserfilter einsetzen', 41 => 'Frischwassertank leer',
        44 => 'Wasserfilter der Station reinigen', 45 => 'Waschrolle blockiert', 48 => 'Wasserzufuhr gestört',
        49 => 'Wasserablauf gestört', 51 => 'Temperaturschutz', 56 => 'Lautsprecherfehler',
    ];

    // Verbrauchsteile: Feld in get_consumable → [RoboHub-Ident, Lebensdauer in Sekunden]
    // (roborock/const.py *_REPLACE_TIME).
    private const TEILE = [
        'main_brush_work_time' => ['Hauptbuerste', 1080000],
        'side_brush_work_time' => ['Seitenbuerste', 720000],
        'filter_work_time'     => ['Filter', 540000],
        'sensor_dirty_time'    => ['Sensoren', 108000],
        'moproller_work_time'  => ['Mopp', 1080000],
    ];

    // Saugstufen. Neuere Geräte nutzen 101–108, ältere 38–100
    // (RoborockFanSpeedV1/V2/V3/E2) — welche gilt, zeigt der gemeldete Wert.
    private const SAUGSTUFEN_NEU = [105 => 'Sanft', 101 => 'Leise', 102 => 'Ausgewogen', 103 => 'Turbo', 104 => 'Max', 108 => 'Max+', 106 => 'Benutzerdefiniert'];
    private const SAUGSTUFEN_ALT = [38 => 'Leise', 60 => 'Standard', 75 => 'Mittel', 100 => 'Turbo'];
    private const WASSERMENGEN = [200 => 'Aus', 201 => 'Niedrig', 202 => 'Mittel', 203 => 'Hoch', 204 => 'Benutzerdefiniert', 207 => 'Benutzerdefiniert'];

    // =================================================================
    // Anmeldung
    // =================================================================

    /** Regionen-Auswahl: Roborock findet den Server über die E-Mail selbst. */
    public static function regionen(): array
    {
        return [['caption' => 'automatisch (über die E-Mail)', 'value' => 'eu']];
    }

    /** Schritt 1: Roborock schickt einen Anmeldecode an die E-Mail. */
    public function codeAnfordern(string $konto): string
    {
        $info = $this->kontoServer($konto);
        $kopf = $this->grundKopf($konto) + ['Content-Type' => 'application/x-www-form-urlencoded', 'header_clientlang' => 'de'];
        if ($info['land'] !== '' && $info['landcode'] !== '') {
            $antwort = $this->anfrage('POST', $info['basis'] . '/api/v4/email/code/send', $kopf, [], ['email' => $konto, 'type' => 'login', 'platform' => '']);
        } else {
            $antwort = $this->anfrage('POST', $info['basis'] . '/api/v1/sendEmailCode', $this->grundKopf($konto), ['username' => $konto, 'type' => 'auth'], null);
        }
        $this->pruefeCode($antwort, 'Code anfordern');
        return 'Roborock hat einen Anmeldecode an ' . $konto . ' geschickt.';
    }

    /** Schritt 2: Anmeldung mit dem Code aus der E-Mail (im Passwortfeld). */
    public function anmelden(string $konto, string $passwort, string $region): array
    {
        $code = trim($passwort);
        if (!preg_match('/^[0-9]{4,8}$/', $code)) {
            throw new RoboHubFehler('Bei Roborock bitte zuerst „📧 Code anfordern" und dann den Code aus der E-Mail (nur Ziffern) eintragen — nicht das Passwort.');
        }
        $info = $this->kontoServer($konto);
        if ($info['land'] !== '' && $info['landcode'] !== '') {
            $s = self::zufallsText(16);
            $signatur = $this->anfrage('POST', $info['basis'] . '/api/v3/key/sign', $this->grundKopf($konto), ['s' => $s], null);
            $k = (string)($signatur['data']['k'] ?? '');
            if ($k === '') {
                throw new RoboHubFehler('Roborock hat die Anmeldung nicht vorbereitet (Schlüssel-Signatur fehlt).');
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
        $this->pruefeCode($antwort, 'Anmeldung');
        $nutzer = $antwort['data'] ?? null;
        if (!is_array($nutzer) || !isset($nutzer['rriot']['u'], $nutzer['rriot']['s'], $nutzer['rriot']['h'], $nutzer['rriot']['k'], $nutzer['token'])) {
            throw new RoboHubFehler('Roborock hat keine vollständigen Zugangsdaten geliefert.');
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
            $e = new RoboHubFehler('Zugangsdaten unvollständig — bitte neu anmelden.');
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
                throw new RoboHubFehler('Die E-Mail-Adresse hat ein ungültiges Format.');
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
        throw new RoboHubFehler('Kein Roborock-Konto mit dieser E-Mail gefunden' . ($letzter !== '' ? ' (' . $letzter . ')' : '') . '.');
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
            2008 => 'Konto existiert nicht — E-Mail prüfen.',
            9002 => 'Zu viele Codes angefordert — bitte später erneut versuchen.',
            2018 => 'Der Code ist falsch oder abgelaufen — neuen Code anfordern.',
            3009 => 'Bitte zuerst in der Roborock-App die Nutzungsbedingungen bestätigen.',
            3006 => 'Nutzungsbedingungen neu bestätigen (Roborock-App) — oder es ist ein Mi-Home-Konto.',
            3039 => 'Konto in dieser Region nicht gefunden.',
        ];
        throw new RoboHubFehler($schritt . ' fehlgeschlagen: ' . ($texte[$code] ?? ((string)($antwort['msg'] ?? 'unbekannt') . ' (Code ' . $code . ')')));
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
            throw new RoboHubFehler('Im Roborock-Konto ist kein Zuhause angelegt.');
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
            throw new RoboHubFehler('Zugangsdaten ohne API-Adresse — bitte neu anmelden.');
        }
        $antwort = $this->anfrage($methode, $basis . $pfad, ['Authorization' => self::hawkKopf($r, $pfad, time(), self::zufallsText(8))], [], null);
        if (empty($antwort['success'])) {
            throw new RoboHubFehler('Roborock-API: ' . (string)($antwort['msg'] ?? json_encode($antwort)));
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
            throw new RoboHubFehler('Der Roborock antwortet nicht (offline oder im Ruhezustand?).');
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
            [$text, $zustand] = self::ZUSTAND[$roh] ?? ['Zustand ' . $roh, RoboHubModell::ZUSTAND_UNBEKANNT];
            $w['Zustand'] = $zustand;
            $w['ZustandRoh'] = $text . ' (' . $roh . ')';
            $w['Laedt'] = in_array($roh, [8], true) || (isset($p['charge_status']) && (int)$p['charge_status'] === 1);
        }
        if (isset($p['error_code'])) {
            $code = (int)$p['error_code'];
            $w['Fehler'] = $code === 0 ? 'kein Fehler' : (self::FEHLER[$code] ?? 'Fehlercode') . ' (' . $code . ')';
        }
        $w['Akku'] = isset($p['battery']) ? (int)$p['battery'] : null;
        $w['Dauer'] = isset($p['clean_time']) ? (int)round((int)$p['clean_time'] / 60) : null;
        $w['Flaeche'] = isset($p['clean_area']) ? (int)round((int)$p['clean_area'] / 1000000) : null;

        if (isset($p['fan_power'])) {
            $stufe = (int)$p['fan_power'];
            $liste = $stufe > 100 ? self::SAUGSTUFEN_NEU : self::SAUGSTUFEN_ALT;
            if (!isset($liste[$stufe])) {
                $liste[$stufe] = 'Stufe ' . $stufe;
            }
            $w['Saugstufe'] = $stufe;
            $optionen['Saugstufe'] = $liste;
        }
        if (isset($p['water_box_mode']) && (int)$p['water_box_mode'] >= 200) {
            $menge = (int)$p['water_box_mode'];
            $liste = self::WASSERMENGEN;
            unset($liste[$menge === 207 ? 204 : 207]);
            if (!isset($liste[$menge])) {
                $liste[$menge] = 'Stufe ' . $menge;
            }
            $w['Wassermenge'] = $menge;
            $optionen['Wassermenge'] = $liste;
        }

        foreach (self::TEILE as $feld => [$ident, $max]) {
            if (isset($p[$feld]) && is_numeric($p[$feld])) {
                $rest = max(0, $max - (int)$p[$feld]);
                $w[$ident] = (int)max(0, min(100, round($rest * 100 / $max)));
                $w[$ident . 'Rest'] = (float)round($rest / 3600);
            }
        }

        // Station (dss, Bitfelder wie StatusV2: Frischwasser >>2, Schmutzwasser >>4, Staubbeutel >>6).
        if (!empty($p['dss']) && is_numeric($p['dss'])) {
            $dss = (int)$p['dss'];
            $w['Frischwasser'] = (($dss >> 2) & 3) === 0 ? 'In Ordnung' : 'Leer oder fehlt';
            $w['Schmutzwasser'] = (($dss >> 4) & 3) === 0 ? 'In Ordnung' : 'Voll oder fehlt';
            $w['Staubbeutel'] = (($dss >> 6) & 3) === 0 ? 'In Ordnung' : 'Voll oder fehlt';
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
            $raeume[$segment] = ($namen[$iot] ?? '') !== '' ? $namen[$iot] : 'Raum ' . $segment;
        }
        ksort($raeume);
        return ['raeume' => $raeume, 'bild' => '', 'marken' => [], 'station' => null];
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
        return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
    }

    // =================================================================
    // Befehle
    // =================================================================

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
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
                return 'Befehl „Start" gesendet.';
            case 'pause':
                $this->rpc($buendel, $geraet, 'app_pause', []);
                return 'Befehl „Pause" gesendet.';
            case 'stopp':
                $this->rpc($buendel, $geraet, 'app_stop', []);
                return 'Befehl „Stopp" gesendet.';
            case 'station':
                $this->rpc($buendel, $geraet, 'app_charge', []);
                return 'Befehl „Zur Station" gesendet.';
            case 'orten':
                $this->rpc($buendel, $geraet, 'find_me', []);
                return 'Befehl „Orten" gesendet.';
            case 'raum':
                return $this->raumReinigung($buendel, $geraet, $parameter);
            case 'setze':
                return $this->setze($buendel, $geraet, (string)($parameter['feld'] ?? ''), (int)($parameter['wert'] ?? 0));
            case 'einstellung':
                return $this->einstellung($buendel, $geraet, (string)($parameter['feld'] ?? ''), $parameter['wert'] ?? null);
        }
        throw new RoboHubFehler('Dieser Befehl wird für Roborock noch nicht unterstützt: ' . $befehl);
    }

    private function raumReinigung(array $buendel, array $geraet, array $parameter): string
    {
        $raeume = array_values(array_unique(array_filter(array_map('intval', (array)($parameter['raeume'] ?? [])), function (int $r): bool {
            return $r > 0;
        })));
        if (count($raeume) === 0) {
            throw new RoboHubFehler('Keine Raum-ID angegeben.');
        }
        $durchgaenge = (int)($parameter['durchgaenge'] ?? -1);
        if ($durchgaenge > 3 || $durchgaenge === 0) {
            throw new RoboHubFehler('Durchgänge 1 bis 3.');
        }
        $saug = (int)($parameter['saugstufe'] ?? -1);
        if ($saug >= 0) {
            if (!isset(self::SAUGSTUFEN_NEU[$saug]) && !isset(self::SAUGSTUFEN_ALT[$saug])) {
                throw new RoboHubFehler('Unbekannte Roborock-Saugstufe ' . $saug . '.');
            }
            $this->rpc($buendel, $geraet, 'set_custom_mode', [$saug]);
        }
        if ((int)($parameter['modus'] ?? -1) >= 0) {
            $this->dbg('Befehl', 'Reinigungsmodus je Start wird bei Roborock noch nicht unterstützt — ignoriert.');
        }
        $wiederholung = $durchgaenge >= 1 ? $durchgaenge : 1;
        try {
            $this->rpc($buendel, $geraet, 'app_segment_clean', [['segments' => $raeume, 'repeat' => $wiederholung]]);
        } catch (RoboHubFehler $e) {
            // Ältere Firmware kennt nur die einfache Liste der Segmente.
            $this->dbg('Befehl', 'Raumreinigung mit Objekt abgelehnt (' . $e->getMessage() . '), versuche einfache Liste.');
            $this->rpc($buendel, $geraet, 'app_segment_clean', $raeume);
        }
        return 'Reinigung ' . (count($raeume) === 1 ? 'eines Raums' : 'von ' . count($raeume) . ' Räumen') . ' gesendet.';
    }

    private function setze(array $buendel, array $geraet, string $feld, int $wert): string
    {
        if ($feld === 'Saugstufe') {
            if (!isset(self::SAUGSTUFEN_NEU[$wert]) && !isset(self::SAUGSTUFEN_ALT[$wert])) {
                throw new RoboHubFehler('Unbekannte Saugstufe ' . $wert . '.');
            }
            $this->rpc($buendel, $geraet, 'set_custom_mode', [$wert]);
            return 'Saugstufe gesetzt.';
        }
        if ($feld === 'Wassermenge') {
            if (!isset(self::WASSERMENGEN[$wert])) {
                throw new RoboHubFehler('Unbekannte Wassermenge ' . $wert . '.');
            }
            $this->rpc($buendel, $geraet, 'set_water_box_custom_mode', [$wert]);
            return 'Wassermenge gesetzt.';
        }
        throw new RoboHubFehler('„' . $feld . '" lässt sich bei Roborock (noch) nicht setzen.');
    }

    private function einstellung(array $buendel, array $geraet, string $feld, $wert): string
    {
        if ($feld === 'Lautstaerke') {
            $v = (int)$wert;
            if ($v < 0 || $v > 100) {
                throw new RoboHubFehler('Lautstärke 0 bis 100.');
            }
            $this->rpc($buendel, $geraet, 'change_sound_volume', [$v]);
            return 'Lautstärke gesetzt.';
        }
        if (!in_array($feld, ['NichtStoeren', 'NichtStoerenVon', 'NichtStoerenBis'], true)) {
            throw new RoboHubFehler('Unbekannte Einstellung: ' . $feld);
        }
        // Read-Modify-Write: aktuelles Fenster lesen, nur das eine Feld ändern.
        $d = $this->rpc($buendel, $geraet, 'get_dnd_timer', []);
        $d = is_array($d) && isset($d[0]) ? $d[0] : $d;
        if (!is_array($d) || !isset($d['start_hour'])) {
            throw new RoboHubFehler('„Nicht stören" ließ sich nicht lesen — nichts geändert.');
        }
        $an = !empty($d['enabled']);
        $von = [(int)$d['start_hour'], (int)($d['start_minute'] ?? 0)];
        $bis = [(int)($d['end_hour'] ?? 0), (int)($d['end_minute'] ?? 0)];
        if ($feld === 'NichtStoeren') {
            if (!$wert) {
                $this->rpc($buendel, $geraet, 'close_dnd_timer', []);
                return '„Nicht stören" ausgeschaltet.';
            }
            $this->rpc($buendel, $geraet, 'set_dnd_timer', [$von[0], $von[1], $bis[0], $bis[1]]);
            return '„Nicht stören" eingeschaltet.';
        }
        $t = trim((string)$wert);
        if (!RoboHubModell::istUhrzeit($t)) {
            throw new RoboHubFehler('Uhrzeit bitte als HH:MM angeben (z. B. 22:00).');
        }
        [$h, $m] = array_map('intval', explode(':', $t));
        if ($feld === 'NichtStoerenVon') {
            $von = [$h, $m];
        } else {
            $bis = [$h, $m];
        }
        if ($von === $bis) {
            throw new RoboHubFehler('Beginn und Ende dürfen nicht gleich sein.');
        }
        if (!$an) {
            // set_dnd_timer schaltet bei Roborock zugleich ein — ausgeschaltet nichts ungefragt einschalten.
            throw new RoboHubFehler('„Nicht stören" ist aus — bitte erst einschalten, dann die Zeit ändern.');
        }
        $this->rpc($buendel, $geraet, 'set_dnd_timer', [$von[0], $von[1], $bis[0], $bis[1]]);
        return 'Zeit für „Nicht stören" gesetzt.';
    }

    // reset_consumable mit dem Feldnamen (consumeable.py).
    private const RUECKSETZEN = [
        'Hauptbuerste' => 'main_brush_work_time', 'Seitenbuerste' => 'side_brush_work_time',
        'Filter' => 'filter_work_time', 'Sensoren' => 'sensor_dirty_time', 'Mopp' => 'moproller_work_time',
    ];

    public function zuruecksetzen(array $buendel, array $geraet, string $teil): string
    {
        $this->pruefeProtokoll($geraet);
        if (!isset(self::RUECKSETZEN[$teil])) {
            throw new RoboHubFehler('Dieses Teil lässt sich bei Roborock nicht zurücksetzen: ' . $teil);
        }
        $this->rpc($buendel, $geraet, 'reset_consumable', [self::RUECKSETZEN[$teil]]);
        return 'Zurücksetzen gesendet.';
    }

    // =================================================================
    // Echtzeit über die Konto-Verbindung: noch nicht gebaut
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
    // Gerät-RPC über den Roborock-MQTT-Server
    // =================================================================

    private function pruefeProtokoll(array $geraet): void
    {
        $pv = (string)($geraet['protokoll'] ?? '1.0');
        if ($pv !== '' && $pv !== '1.0') {
            throw new RoboHubFehler('Dieses Roborock-Gerät spricht das Protokoll „' . $pv . '", das RoboHub noch nicht unterstützt.');
        }
        if ((string)($geraet['schluessel'] ?? '') === '') {
            throw new RoboHubFehler('Gerätschlüssel fehlt — bitte in der Konto-Instanz „Geräte jetzt suchen".');
        }
    }

    /** Eine Anfrage; Rückgabe: result oder RoboHubFehler. */
    private function rpc(array $buendel, array $geraet, string $methode, array $params)
    {
        $r = $this->rpcMehrere($buendel, $geraet, [[$methode, $params]])[0] ?? null;
        if ($r === null) {
            throw new RoboHubFehler('Der Roborock hat auf „' . $methode . '" nicht geantwortet (offline?).');
        }
        if (isset($r['fehler'])) {
            throw new RoboHubFehler('Der Roborock lehnt „' . $methode . '" ab: ' . $r['fehler']);
        }
        return $r['ergebnis'];
    }

    /**
     * Mehrere Anfragen in einer MQTT-Sitzung. Rückgabe je Index:
     * ['ergebnis' => …] | ['fehler' => Text] | null (keine Antwort).
     */
    private function rpcMehrere(array $buendel, array $geraet, array $anfragen): array
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
        $this->mqttAustausch($host, $port, $benutzer, $passwort,
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
            throw new RoboHubFehler('Zugangsdaten ohne MQTT-Adresse — bitte neu anmelden.');
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
            $fehler = 'Befehl unbekannt (Modell kann das nicht)';
        } elseif ($fehler === null && is_string($ergebnis) && $ergebnis !== 'ok') {
            $fehler = 'unerwartete Antwort „' . $ergebnis . '"';
        } elseif ($fehler === null && $ergebnis === null) {
            $fehler = 'Antwort ohne Ergebnis';
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
            $aus[] = ['seq' => $k['seq'], 'zeit' => $k['zeit'], 'protokoll' => $k['protokoll'], 'nutzlast' => (string)$inhalt];
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
    protected function mqttAustausch(string $host, int $port, string $benutzer, string $passwort, string $abo, string $ziel, array $rahmen, callable $verarbeite, float $frist): void
    {
        $ende = microtime(true) + $frist;
        $sitzung = RoboHubMqttSitzung::oeffnen($host, $port, self::MQTT_WURZEL, null, $ende);
        try {
            $sitzung->senden(RoboHubMqtt::connect('robohub_' . bin2hex(random_bytes(6)), $benutzer, $passwort, 30));
            $p = $sitzung->warte(RoboHubMqtt::CONNACK, null, $ende);
            if ($p === null) {
                throw new RoboHubFehler('Roborock-Server hat die Anmeldung nicht beantwortet.');
            }
            $code = RoboHubMqtt::connackCode($p['body']);
            if ($code !== 0) {
                $e = new RoboHubFehler('Roborock-Server lehnt die Anmeldung ab: ' . RoboHubMqtt::connackText($code) . '.');
                $e->anmeldungNoetig = in_array($code, [4, 5], true);
                throw $e;
            }
            $sitzung->senden(RoboHubMqtt::subscribe(1, [$abo], 0));
            if ($sitzung->warte(RoboHubMqtt::SUBACK, null, $ende) === null) {
                throw new RoboHubFehler('Roborock-Server hat das Abonnement nicht bestätigt.');
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
            throw new RoboHubFehler('Roborock nicht erreichbar: ' . $text);
        }
        if ($status === 401) {
            $e = new RoboHubFehler('Roborock hat den Zugang abgelehnt — bitte neu anmelden.');
            $e->anmeldungNoetig = true;
            throw $e;
        }
        if ($status === 429) {
            $e = new RoboHubFehler('Roborock bremst gerade (zu viele Anfragen).');
            $e->warteSekunden = 600;
            throw $e;
        }
        $j = json_decode($text, true);
        if (!is_array($j)) {
            if (!$pruefen) {
                return [];
            }
            throw new RoboHubFehler('Unerwartete Antwort von Roborock (HTTP ' . $status . ').');
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
        curl_close($ch);
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
