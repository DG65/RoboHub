<?php

declare(strict_types=1);

// ===========================================================================
// RoboHub-Treiber für Positec-Mähroboter: Worx Landroid, Kress, LandXcape,
// Ferrex (gleiche Cloud, je Marke eigene Server und App-Kennung).
//
// Quelle: iobroker-community-adapters/ioBroker.worx (MIT, Stand 02.10.2026):
// main.js (clouds, login/refresh, product-items, sendMessage/sendPing,
// error_states), lib/helper.js (Statuscodes, Felder dat/cfg). Zweite Quelle
// nur als Protokollwissen: MTrab/pyworxcloud (GPL-3.0, kein Code übernommen)
// für die MQTT-Anmeldung über WebSocket mit Benutzername-Parametern.
//
// Ablauf:
//   - Anmeldung mit Passwort (OAuth „password"), danach nur noch mit dem
//     rotierenden Erneuerungsschlüssel — das Passwort wird nicht gespeichert.
//   - Zustand: letzter Status aus der Geräteliste (product-items?status=1),
//     den der Mäher selbst laufend an die Cloud meldet.
//   - Befehle: MQTT über WebSocket zum AWS-IoT-Endpunkt der Marke
//     (Anmeldung über den Zugangsschlüssel im Benutzernamen), Topic
//     mqtt_topics.command_in, {"cmd": n}.
//
// Noch nicht gebaut: Zonen, Zeitpläne, Regenverzögerung, Echtzeit über die
// Konto-Verbindung, Messer-Rücksetzen.
// ===========================================================================

class RoboHubWorx implements RoboHubTreiber
{
    /** @var callable|null fn(string $thema, string $text) */
    public $debug = null;

    /** @var string */
    private $marke;

    // ioBroker.worx main.js this.clouds
    public const MARKEN = [
        'worx'      => ['api' => 'api.worxlandroid.com', 'login' => 'https://id.worx.com/', 'client' => '150da4d2-bb44-433b-9429-3773adc70a2a', 'praefix' => 'WX', 'name' => 'Worx'],
        'kress'     => ['api' => 'api.kress-robotik.com', 'login' => 'https://id.kress.com/', 'client' => '931d4bc4-3192-405a-be78-98e43486dc59', 'praefix' => 'KR', 'name' => 'Kress'],
        'landxcape' => ['api' => 'api.landxcape-services.com', 'login' => 'https://id.landxcape-services.com/', 'client' => 'dec998a9-066f-433b-987a-f5fc54d3af7c', 'praefix' => 'LX', 'name' => 'LandXcape'],
        'ferrex'    => ['api' => 'api.watermelon.smartmower.cloud', 'login' => 'https://id.watermelon.smartmower.cloud/', 'client' => '10078D10-3840-474A-848A-5EED949AB0FC', 'praefix' => 'FE', 'name' => 'Ferrex'],
    ];
    private const ERNEUERN_SCOPE = 'user:profile mower:firmware mower:view mower:pair user:manage mower:update mower:activity_log user:certificate data:products mower:unpair mower:warranty mobile:notifications mower:lawn';
    private const ERSATZ_ENDPUNKT = 'iot.eu-west-1.worxlandroid.com';

    // Wurzel der AWS-IoT-Endpunkte (iot.*.worxlandroid.com: Amazon RSA 2048 M04 →
    // Amazon Root CA 1). SHA-256 8E:CD:E6:88:…:AE:98:19:6E, gültig bis 2038.
    public const MQTT_WURZEL = <<<PEM
-----BEGIN CERTIFICATE-----
MIIDQTCCAimgAwIBAgITBmyfz5m/jAo54vB4ikPmljZbyjANBgkqhkiG9w0BAQsF
ADA5MQswCQYDVQQGEwJVUzEPMA0GA1UEChMGQW1hem9uMRkwFwYDVQQDExBBbWF6
b24gUm9vdCBDQSAxMB4XDTE1MDUyNjAwMDAwMFoXDTM4MDExNzAwMDAwMFowOTEL
MAkGA1UEBhMCVVMxDzANBgNVBAoTBkFtYXpvbjEZMBcGA1UEAxMQQW1hem9uIFJv
b3QgQ0EgMTCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBALJ4gHHKeNXj
ca9HgFB0fW7Y14h29Jlo91ghYPl0hAEvrAIthtOgQ3pOsqTQNroBvo3bSMgHFzZM
9O6II8c+6zf1tRn4SWiw3te5djgdYZ6k/oI2peVKVuRF4fn9tBb6dNqcmzU5L/qw
IFAGbHrQgLKm+a/sRxmPUDgH3KKHOVj4utWp+UhnMJbulHheb4mjUcAwhmahRWa6
VOujw5H5SNz/0egwLX0tdHA114gk957EWW67c4cX8jJGKLhD+rcdqsq08p8kDi1L
93FcXmn/6pUCyziKrlA4b9v7LWIbxcceVOF34GfID5yHI9Y/QCB/IIDEgEw+OyQm
jgSubJrIqg0CAwEAAaNCMEAwDwYDVR0TAQH/BAUwAwEB/zAOBgNVHQ8BAf8EBAMC
AYYwHQYDVR0OBBYEFIQYzIU07LwMlJQuCFmcx7IQTgoIMA0GCSqGSIb3DQEBCwUA
A4IBAQCY8jdaQZChGsV2USggNiMOruYou6r4lK5IpDB/G/wkjUu0yKGX9rbxenDI
U5PMCCjjmCXPI6T53iHTfIUJrU6adTrCC2qJeHZERxhlbI1Bjjt/msv0tadQ1wUs
N+gDS63pYaACbvXy8MWy7Vu33PqUXHeeE6V/Uq2V8viTO96LXFvKWlJbYK8U90vv
o/ufQJVtMVT8QtPHRh8jrdkPSHCa2XV4cdFyQzR1bldZwgJcJmApzyMZFo6IQ6XU
5MsI+yMRQ+hDKXJioaldXgjUkK642M4UwtBV8ob2xJNDd2ZhwLnoQdeXeGADbkpy
rqXRfboQnoZsG4q5WTP468SQvvG5
-----END CERTIFICATE-----
PEM;

    // Statuscodes dat.ls (lib/helper.js mower.status).
    private const ZUSTAND = [
        0 => ['Leerlauf', RoboHubModell::ZUSTAND_BEREIT],
        1 => ['Zu Hause', RoboHubModell::ZUSTAND_GELADEN],
        2 => ['Startsequenz', RoboHubModell::ZUSTAND_REINIGT],
        3 => ['Verlässt die Station', RoboHubModell::ZUSTAND_REINIGT],
        4 => ['Folgt dem Begrenzungsdraht', RoboHubModell::ZUSTAND_REINIGT],
        5 => ['Sucht die Station', RoboHubModell::ZUSTAND_ZURUECK],
        6 => ['Sucht den Begrenzungsdraht', RoboHubModell::ZUSTAND_ZURUECK],
        7 => ['Mäht', RoboHubModell::ZUSTAND_REINIGT],
        8 => ['Angehoben', RoboHubModell::ZUSTAND_FEHLER],
        9 => ['Steckt fest', RoboHubModell::ZUSTAND_FEHLER],
        10 => ['Messer blockiert', RoboHubModell::ZUSTAND_FEHLER],
        11 => ['Diagnose', RoboHubModell::ZUSTAND_UNBEKANNT],
        12 => ['Fährt', RoboHubModell::ZUSTAND_REINIGT],
        13 => ['Verlässt digitalen Zaun', RoboHubModell::ZUSTAND_REINIGT],
        30 => ['Fährt nach Hause', RoboHubModell::ZUSTAND_ZURUECK],
        31 => ['Zonentraining', RoboHubModell::ZUSTAND_KARTIERUNG],
        32 => ['Kantenschnitt', RoboHubModell::ZUSTAND_REINIGT],
        33 => ['Sucht die Zone', RoboHubModell::ZUSTAND_REINIGT],
        34 => ['Pause', RoboHubModell::ZUSTAND_PAUSIERT],
        100 => ['Kartentraining', RoboHubModell::ZUSTAND_KARTIERUNG],
        101 => ['Verarbeitet die Karte', RoboHubModell::ZUSTAND_KARTIERUNG],
        102 => ['Aktualisiert die Firmware', RoboHubModell::ZUSTAND_AKTUALISIERUNG],
        103 => ['Fährt zur Zone', RoboHubModell::ZUSTAND_REINIGT],
        104 => ['Fährt nach Hause', RoboHubModell::ZUSTAND_ZURUECK],
        105 => ['Bereit fürs Training', RoboHubModell::ZUSTAND_KARTIERUNG],
        106 => ['Lädt die Karte herunter', RoboHubModell::ZUSTAND_KARTIERUNG],
        107 => ['Lädt die Karte hoch', RoboHubModell::ZUSTAND_KARTIERUNG],
        108 => ['Kartentraining pausiert', RoboHubModell::ZUSTAND_KARTIERUNG],
        109 => ['Kartentraining', RoboHubModell::ZUSTAND_KARTIERUNG],
        110 => ['Überquert die Grenze', RoboHubModell::ZUSTAND_REINIGT],
        111 => ['Erkundet den Rasen', RoboHubModell::ZUSTAND_REINIGT],
        112 => ['Fährt zum Wiederaufnahmepunkt', RoboHubModell::ZUSTAND_REINIGT],
        113 => ['Wartet auf Position', RoboHubModell::ZUSTAND_BEREIT],
        114 => ['Kartentraining (fährt)', RoboHubModell::ZUSTAND_KARTIERUNG],
        115 => ['Kartentraining (zurück)', RoboHubModell::ZUSTAND_KARTIERUNG],
    ];

    // Fehlercodes dat.le (main.js error_states).
    private const FEHLER = [
        1 => 'Steckt fest', 2 => 'Angehoben', 3 => 'Begrenzungsdraht fehlt', 4 => 'Außerhalb der Begrenzung',
        5 => 'Regenverzögerung', 6 => 'Klappe schließen zum Mähen', 7 => 'Klappe schließen zur Heimfahrt',
        8 => 'Fehler Messermotor', 9 => 'Fehler Radmotor', 10 => 'Festgefahren (Zeitüberschreitung)', 11 => 'Kopfüber',
        12 => 'Akku schwach', 13 => 'Draht vertauscht', 14 => 'Ladefehler', 15 => 'Station nicht gefunden (Zeitüberschreitung)',
        16 => 'WLAN gesperrt', 17 => 'Akku zu warm', 18 => 'Vorführmodell', 19 => 'Akkuklappe offen (Zeitüberschreitung)',
        20 => 'Drahtsignal nicht synchron', 100 => 'Andockfehler an der Station', 101 => 'HBI-Fehler', 102 => 'Firmware-Update fehlgeschlagen',
        103 => 'Kartenfehler', 104 => 'Zu steil', 105 => 'Zone nicht erreichbar', 106 => 'Station nicht erreichbar',
        107 => 'Kalibrierung nötig', 108 => 'Zu wenig Sensordaten', 109 => 'Training nicht erlaubt', 110 => 'Kamerafehler',
        111 => 'Rasenerkundung nötig', 112 => 'Kartenerkundung fehlgeschlagen', 113 => 'RFID-Lesefehler', 114 => 'Scheinwerferfehler',
        115 => 'Station fehlt', 116 => 'Schnitthöhenverstellung blockiert', 117 => 'Schnitthöhe nicht unterstützt',
        118 => 'Manuelles Firmware-Update nötig', 119 => 'Flächengrenze überschritten', 120 => 'Abdockfehler an der Station',
    ];

    // Befehle (mqtt.py Command / ioBroker sendMessage): 1 Start, 2 Pause, 3 Nach Hause.
    private const BEFEHLE = ['start' => [1, 'Start'], 'pause' => [2, 'Pause'], 'stopp' => [2, 'Stopp'], 'station' => [3, 'Zur Station']];

    public function __construct(string $marke)
    {
        if (!isset(self::MARKEN[$marke])) {
            throw new RoboHubFehler('Unbekannte Marke: ' . $marke);
        }
        $this->marke = $marke;
    }

    // =================================================================
    // Anmeldung
    // =================================================================

    public function anmelden(string $konto, string $passwort, string $region): array
    {
        $m = self::MARKEN[$this->marke];
        $antwort = $this->json('POST', $m['login'] . 'oauth/token', null, [
            'client_id' => $m['client'], 'username' => $konto, 'password' => $passwort, 'scope' => '*', 'grant_type' => 'password',
        ], true);
        return $this->buendelAus($antwort);
    }

    public function erneuern(array $buendel): array
    {
        if ((int)($buendel['expires_at'] ?? 0) - 300 > time()) {
            return $buendel;
        }
        if (($buendel['refresh_token'] ?? '') === '') {
            $e = new RoboHubFehler('Kein Erneuerungsschlüssel — bitte neu anmelden.');
            $e->anmeldungNoetig = true;
            throw $e;
        }
        $m = self::MARKEN[$this->marke];
        $antwort = $this->json('POST', $m['login'] . 'oauth/token', null, [
            'client_id' => $m['client'], 'scope' => self::ERNEUERN_SCOPE, 'refresh_token' => $buendel['refresh_token'], 'grant_type' => 'refresh_token',
        ], true);
        return $this->buendelAus($antwort);
    }

    private function buendelAus(array $antwort): array
    {
        if (($antwort['access_token'] ?? '') === '') {
            throw new RoboHubFehler(self::MARKEN[$this->marke]['name'] . '-Anmeldung ohne Zugangsschlüssel.');
        }
        return [
            'marke'         => $this->marke,
            'access_token'  => (string)$antwort['access_token'],
            'refresh_token' => (string)($antwort['refresh_token'] ?? ''),
            'expires_at'    => time() + (int)($antwort['expires_in'] ?? 3600),
        ];
    }

    // =================================================================
    // Geräte und Zustand
    // =================================================================

    public function geraete(array $buendel): array
    {
        $liste = [];
        foreach ($this->json('GET', $this->api('product-items?status=1&gps_status=1'), $buendel, null) as $m) {
            if (!is_array($m) || ($m['serial_number'] ?? '') === '') {
                continue;
            }
            $liste[] = [
                'id'      => (string)$m['serial_number'],
                'name'    => (string)($m['name'] ?? self::MARKEN[$this->marke]['name']),
                'modell'  => $this->marke . '.mower.' . preg_replace('/[^a-z0-9]+/', '', strtolower((string)($m['product_id'] ?? $m['model'] ?? 'landroid'))),
                'klasse'  => RoboHubModell::KLASSE_MAEHER,
                'online'  => !empty($m['online']),
                'mqtt'    => [
                    'endpunkt' => (string)($m['mqtt_endpoint'] ?? self::ERSATZ_ENDPUNKT),
                    'befehl'   => (string)($m['mqtt_topics']['command_in'] ?? ''),
                    'antwort'  => (string)($m['mqtt_topics']['command_out'] ?? ''),
                ],
                'nutzer'  => (string)($m['user_id'] ?? ''),
                'uuid'    => (string)($m['uuid'] ?? ''),
                'art'     => self::artVon((array)($m['capabilities'] ?? [])),
                'roh'     => ['product_id' => $m['product_id'] ?? null, 'protocol' => $m['protocol'] ?? null, 'capabilities' => $m['capabilities'] ?? null, 'firmware' => $m['firmware_version'] ?? null],
            ];
        }
        return $liste;
    }

    /** Geräteart wie ioBroker main.js: wire | vision | cloud | rtk. */
    public static function artVon(array $faehigkeiten): string
    {
        if (in_array('vision', $faehigkeiten, true)) {
            return in_array('rtk', $faehigkeiten, true) ? 'cloud' : 'vision';
        }
        return in_array('maps', $faehigkeiten, true) ? 'rtk' : 'wire';
    }

    public function zustand(array $buendel, array $geraet): array
    {
        $m = $this->json('GET', $this->api('product-items/' . rawurlencode((string)$geraet['id']) . '?status=1&gps_status=1'), $buendel, null);
        $p = (array)($m['last_status']['payload'] ?? []);
        $daten = $this->normalisiere((string)($geraet['modell'] ?? ''), $p);
        $daten['online'] = !empty($m['online']);
        $daten['bloecke'] = ['ok' => [0], 'fehl' => []];
        $daten['roh'] = ['modell' => $geraet['modell'] ?? null, 'art' => $geraet['art'] ?? null, 'properties' => $p,
            'zeit' => $m['last_status']['timestamp'] ?? null];
        return $daten;
    }

    public function normalisiere(string $modell, array $p): array
    {
        $dat = (array)($p['dat'] ?? []);
        $w = [];
        $laedt = isset($dat['bt']['c']) ? (int)$dat['bt']['c'] === 1 : null;
        if (isset($dat['ls'])) {
            $roh = (int)$dat['ls'];
            [$text, $zustand] = self::ZUSTAND[$roh] ?? ['Status ' . $roh, RoboHubModell::ZUSTAND_UNBEKANNT];
            if ($roh === 1 && $laedt) {
                $zustand = RoboHubModell::ZUSTAND_LAEDT;
            }
            if ((int)($dat['le'] ?? 0) === 5 && in_array($roh, [0, 1], true)) {
                $zustand = RoboHubModell::ZUSTAND_WETTERPAUSE;
            }
            $w['Zustand'] = $zustand;
            $w['ZustandRoh'] = $text . ' (' . $roh . ')';
        }
        if (isset($dat['le'])) {
            $code = (int)$dat['le'];
            $w['Fehler'] = $code === 0 ? 'kein Fehler' : (self::FEHLER[$code] ?? 'Fehlercode') . ' (' . $code . ')';
            // Fehler bei stehendem Mäher (Leerlauf) ist ein Fehlerzustand; Regen (5) ist Wetterpause.
            if ($code !== 0 && $code !== 5 && (int)($dat['ls'] ?? -1) === 0) {
                $w['Zustand'] = RoboHubModell::ZUSTAND_FEHLER;
            }
        }
        $w['Akku'] = isset($dat['bt']['p']) ? (int)$dat['bt']['p'] : null;
        $w['Laedt'] = $laedt;
        if (isset($dat['rain']['s'])) {
            $w['Regensensor'] = (int)$dat['rain']['s'] === 1;
        }
        // Statistik: st.wt Mähzeit in Minuten (ioBroker: /60 → Stunden).
        $w['GesamtDauer'] = isset($dat['st']['wt']) && is_numeric($dat['st']['wt']) ? (int)round((float)$dat['st']['wt'] / 60) : null;
        return ['werte' => $w, 'optionen' => [], 'schreibbar' => []];
    }

    // =================================================================
    // Räume, Karte, Befehle
    // =================================================================

    public function karte(array $buendel, array $geraet): array
    {
        return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
    }

    public function liveKarte(array $buendel, array $geraet): array
    {
        return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
    }

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        if (!isset(self::BEFEHLE[$befehl])) {
            throw new RoboHubFehler(($befehl === 'orten' ? '„Orten" gibt es' : 'Dieser Befehl wird') . ' bei ' . self::MARKEN[$this->marke]['name'] . '-Mährobotern (noch) nicht' . ($befehl === 'orten' ? '.' : ' unterstützt: ' . $befehl));
        }
        [$cmd, $name] = self::BEFEHLE[$befehl];
        $this->senden($buendel, $geraet, ['cmd' => $cmd]);
        return 'Befehl „' . $name . '" gesendet.';
    }

    public function zuruecksetzen(array $buendel, array $geraet, string $teil): string
    {
        throw new RoboHubFehler('Zurücksetzen wird bei ' . self::MARKEN[$this->marke]['name'] . ' noch nicht unterstützt.');
    }

    /** Nachricht wie ioBroker sendPing: id, cmd, Sprache, Seriennummer bzw. uuid und Uhrzeit. */
    public static function nachricht(array $geraet, array $inhalt, int $id, int $zeit): array
    {
        $grund = ['id' => $id, 'cmd' => 0, 'lg' => 'de'];
        if (in_array((string)($geraet['art'] ?? 'wire'), ['vision', 'cloud'], true)) {
            $grund += ['uuid' => (string)($geraet['uuid'] ?? ''), 'tm' => date('Y-m-d\TH:i:s', $zeit) . '.000Z'];
        } else {
            $grund += ['sn' => (string)$geraet['id'], 'tm' => date('H:i:s', $zeit), 'dt' => date('d/m/Y', $zeit)];
        }
        return array_merge($grund, $inhalt);
    }

    /** MQTT-Benutzername mit Zugangsschlüssel (Positec-Autorisierung bei AWS IoT). */
    public static function mqttBenutzer(string $zugang): array
    {
        $teile = explode('.', str_replace(['_', '-'], ['/', '+'], $zugang));
        if (count($teile) !== 3) {
            throw new RoboHubFehler('Zugangsschlüssel hat ein unerwartetes Format — bitte neu anmelden.');
        }
        $q = function (string $s): string {
            return str_replace('%2F', '/', rawurlencode($s));
        };
        return ['robohub?jwt=' . $q($teile[0]) . '.' . $q($teile[1]) . "&x-amz-customauthorizer-name=''&x-amz-customauthorizer-signature=" . $q($teile[2]), ''];
    }

    private function senden(array $buendel, array $geraet, array $inhalt): void
    {
        $mqtt = (array)($geraet['mqtt'] ?? []);
        if (($mqtt['befehl'] ?? '') === '' || ($geraet['nutzer'] ?? '') === '') {
            throw new RoboHubFehler('Befehlskanal des Mähers unbekannt — bitte in der Konto-Instanz „Geräte jetzt suchen".');
        }
        $nachricht = json_encode(self::nachricht($geraet, $inhalt, random_int(1025, 65534), time()));
        [$benutzer, $passwort] = self::mqttBenutzer((string)$buendel['access_token']);
        $client = self::MARKEN[$this->marke]['praefix'] . '/USER/' . $geraet['nutzer'] . '/robohub/' . bin2hex(random_bytes(8));
        $this->dbg('Befehl', 'an ' . $mqtt['befehl'] . ': ' . $nachricht);
        $this->mqttSenden((string)($mqtt['endpunkt'] ?: self::ERSATZ_ENDPUNKT), $client, $benutzer, $passwort, (string)$mqtt['befehl'], (string)$nachricht);
    }

    /** Eine MQTT-Nachricht mit QoS 1 senden und auf die Bestätigung warten (im Prüfstand ersetzt). */
    protected function mqttSenden(string $host, string $client, string $benutzer, string $passwort, string $topic, string $nachricht): void
    {
        $ende = microtime(true) + 10.0;
        $sitzung = RoboHubMqttSitzung::oeffnen($host, 443, self::MQTT_WURZEL, '/mqtt', $ende);
        try {
            $sitzung->senden(RoboHubMqtt::connect($client, $benutzer, $passwort, 30));
            $p = $sitzung->warte(RoboHubMqtt::CONNACK, null, $ende);
            if ($p === null) {
                throw new RoboHubFehler('Der Befehlsserver hat die Anmeldung nicht beantwortet.');
            }
            $code = RoboHubMqtt::connackCode($p['body']);
            if ($code !== 0) {
                $e = new RoboHubFehler('Der Befehlsserver lehnt die Anmeldung ab: ' . RoboHubMqtt::connackText($code) . '.');
                $e->anmeldungNoetig = in_array($code, [4, 5], true);
                throw $e;
            }
            $sitzung->senden(RoboHubMqtt::publish($topic, $nachricht, 1, 1));
            if ($sitzung->warte(RoboHubMqtt::PUBACK, null, $ende) === null) {
                throw new RoboHubFehler('Der Befehlsserver hat den Befehl nicht bestätigt.');
            }
        } finally {
            $sitzung->schliessen();
        }
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
    // HTTP
    // =================================================================

    private function api(string $pfad): string
    {
        return 'https://' . self::MARKEN[$this->marke]['api'] . '/api/v2/' . $pfad;
    }

    private function json(string $methode, string $url, ?array $buendel, ?array $body, bool $anmeldung = false): array
    {
        $kopf = ['Accept: application/json'];
        if ($body !== null) {
            $kopf[] = 'Content-Type: application/json';
        }
        if ($buendel !== null) {
            $kopf[] = 'Authorization: Bearer ' . $buendel['access_token'];
        }
        [$status, $text] = $this->http($methode, $url, $kopf, $body !== null ? (string)json_encode($body) : null);
        $this->dbg('HTTP', $methode . ' ' . $url . ' → ' . $status);
        if ($status === 0) {
            throw new RoboHubFehler(self::MARKEN[$this->marke]['name'] . '-Cloud nicht erreichbar: ' . $text);
        }
        if ($status === 429) {
            $e = new RoboHubFehler(self::MARKEN[$this->marke]['name'] . '-Cloud bremst gerade (zu viele Anfragen).');
            $e->warteSekunden = 900;
            throw $e;
        }
        if ($status === 401 || ($anmeldung && in_array($status, [400, 403], true))) {
            $e = new RoboHubFehler($anmeldung ? 'Anmeldung abgelehnt — E-Mail/Passwort prüfen.' : 'Zugang abgelaufen — bitte neu anmelden.');
            $e->anmeldungNoetig = !$anmeldung || $status === 401;
            throw $e;
        }
        $j = json_decode($text, true);
        if ($status >= 300 || !is_array($j)) {
            throw new RoboHubFehler(self::MARKEN[$this->marke]['name'] . '-Cloud antwortet mit HTTP ' . $status . '.');
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

    private function dbg(string $thema, string $text): void
    {
        if (is_callable($this->debug)) {
            ($this->debug)($thema, $text);
        }
    }
}
