<?php

declare(strict_types=1);

require_once __DIR__ . '/RoboHubModell.php';

// ===========================================================================
// RoboHub-Treiber für die Dreame- und MOVA-Cloud (Dreamehome-/MOVAhome-App).
//
// Quellen (MIT-lizenziert, am Quellcode gegengelesen am 11.10.2026, nicht aus
// Zusammenfassungen):
//   • github.com/TA2k/ioBroker.dreame 0.4.12 (main.js, lib/dreame.js,
//     lib/specs/*, docs/mower-protocol.md) — Login, Endpunkte, Mäher
//   • github.com/Tasshack/dreame-vacuum 2.0.1 (dreame/types.py, device.py,
//     protocol.py) — Property-Zuordnung, Zustands- und Fehlerwerte
//
// Grundsätze:
//   • Passwort nur für die Erstanmeldung; danach trägt der (rotierende)
//     Refresh-Token. Läuft er ab, meldet der Treiber „Anmeldung nötig".
//   • Abfrage per HTTP (kein MQTT in Version 0.1). Einige neuere Sauger —
//     belegt für dreame.vacuum.r95475 (Aqua10 Ultra Roller Complete) —
//     liefern Zustand und Akku NICHT per get_properties, sondern nur per
//     MQTT-Push. Beides steht aber in der Geräteliste (latestStatus/battery);
//     dieselbe Lösung nutzt ioBroker (_bridgeStatusSummary).
//   • Unbekannte/fehlende Werte bleiben null, nie 0.
// ===========================================================================

class RoboHubDreame implements RoboHubTreiber
{
    private const MARKEN = [
        'dreame' => [
            'domain'  => 'iot.dreame.tech',
            'tenant'  => '000000',
            'basic'   => 'ZHJlYW1lX2FwcHYxOkFQXmR2QHpAU1FZVnhOODg=',
            'rlc'     => 'EETjszu*XI5znHsI',
            'prefix'  => '10000',
        ],
        'mova' => [
            'domain'  => 'iot.mova-tech.com',
            'tenant'  => '000002',
            'basic'   => 'bW92YV9hcHA6VjdLb0NoTFc4dkhBQ3FHYg==',
            'rlc'     => 'gigxlmqwZ]7oWZUF',
            'prefix'  => '20000',
        ],
    ];

    // Salt für den Passwort-Hash, in beiden Referenzprojekten identisch.
    private const SALT = 'RAylYC%fmSKp7%Tq';
    private const PORT = 13267;

    // Erneuern, wenn der Zugangsschlüssel in weniger als 10 min abläuft.
    private const ERNEUERN_VORLAUF_S = 600;

    /** Optionaler Debug-Kanal: function(string $thema, string $text). */
    public $debug = null;

    private $marke;

    public function __construct(string $marke)
    {
        $this->marke = isset(self::MARKEN[$marke]) ? $marke : 'dreame';
    }

    /** Regionen fürs Formular. Nur „Europa" ist an echten Konten belegt. */
    public static function regionen(): array
    {
        return [
            ['caption' => 'Europa (auch DE/AT/CH)', 'value' => 'eu'],
            ['caption' => 'Nordamerika (ungeprüft)', 'value' => 'us'],
            ['caption' => 'Singapur/Asien (ungeprüft)', 'value' => 'sg'],
            ['caption' => 'Russland (ungeprüft)', 'value' => 'ru'],
            ['caption' => 'Südkorea (ungeprüft)', 'value' => 'kr'],
            ['caption' => 'China (ungeprüft)', 'value' => 'cn'],
        ];
    }

    // =================================================================
    // Anmeldung
    // =================================================================

    public function anmelden(string $konto, string $passwort, string $region): array
    {
        $region = $this->pruefeRegion($region);
        $body = http_build_query([
            'grant_type' => 'password',
            'scope'      => 'all',
            'platform'   => 'IOS',
            'type'       => 'account',
            'username'   => $konto,
            'password'   => md5($passwort . self::SALT),
            'country'    => 'DE',
            'lang'       => 'de',
        ]);
        $antwort = $this->tokenAnfrage($region, $body, true);
        return $this->buendelAus($antwort, $region);
    }

    public function erneuern(array $buendel): array
    {
        if (empty($buendel['refresh_token'])) {
            $f = new RoboHubFehler('Kein Erneuerungsschlüssel vorhanden — bitte neu anmelden.');
            $f->anmeldungNoetig = true;
            throw $f;
        }
        if ((int)($buendel['expires_at'] ?? 0) - time() > self::ERNEUERN_VORLAUF_S) {
            return $buendel;
        }
        $region = (string)($buendel['region'] ?? 'eu');
        $body = http_build_query([
            'grant_type'    => 'refresh_token',
            'scope'         => 'all',
            'platform'      => 'IOS',
            'type'          => 'account',
            'refresh_token' => (string)$buendel['refresh_token'],
        ]);
        $antwort = $this->tokenAnfrage($region, $body, false);
        return $this->buendelAus($antwort, $region);
    }

    private function tokenAnfrage(string $region, string $body, bool $erstanmeldung): array
    {
        $headers = $this->basisHeader($region, 'DE');
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Dreame-Auth: bearer';
        [$status, $text] = $this->http('POST', $this->basisUrl($region) . '/dreame-auth/oauth/token', $headers, $body);
        $json = json_decode($text, true);

        if ($status >= 400 && $status < 500 && $status !== 429) {
            $grund = is_array($json) ? (string)($json['error_description'] ?? $json['msg'] ?? $json['error'] ?? '') : '';
            $f = new RoboHubFehler($erstanmeldung
                ? 'Anmeldung abgelehnt (HTTP ' . $status . ($grund !== '' ? ': ' . $grund : '') . '). E-Mail, Passwort, Hersteller und Region prüfen.'
                : 'Der Zugangsschlüssel wurde abgelehnt (HTTP ' . $status . ') — bitte in der Konto-Instanz neu anmelden.');
            $f->anmeldungNoetig = !$erstanmeldung;
            throw $f;
        }
        if ($status === 429 || $status >= 500 || $status === 0) {
            $f = new RoboHubFehler($status === 0
                ? 'Hersteller-Cloud nicht erreichbar (' . $text . ').'
                : 'Hersteller-Cloud antwortet mit HTTP ' . $status . ' — später erneut versuchen.');
            $f->warteSekunden = $status === 0 ? 120 : 300;
            throw $f;
        }
        if (!is_array($json) || empty($json['access_token'])) {
            throw new RoboHubFehler('Unerwartete Antwort bei der Anmeldung (kein Zugangsschlüssel).');
        }
        return $json;
    }

    private function buendelAus(array $antwort, string $region): array
    {
        return [
            'marke'         => $this->marke,
            'region'        => preg_match('/^[a-z]{2}$/', (string)($antwort['region'] ?? '')) ? (string)$antwort['region'] : $region,
            'country'       => (string)($antwort['country'] ?? 'DE'),
            'access_token'  => (string)$antwort['access_token'],
            // Der Refresh-Token rotiert — immer den neuen übernehmen.
            'refresh_token' => (string)($antwort['refresh_token'] ?? ''),
            'expires_at'    => time() + max(300, (int)($antwort['expires_in'] ?? 3600)),
            'uid'           => (string)($antwort['uid'] ?? ''),
            'tenant_id'     => (string)($antwort['tenant_id'] ?? self::MARKEN[$this->marke]['tenant']),
        ];
    }

    // =================================================================
    // Geräte
    // =================================================================

    public function geraete(array $buendel): array
    {
        $out = [];
        foreach ($this->geraeteRoh($buendel) as $r) {
            $did = (string)($r['did'] ?? '');
            if ($did === '') {
                continue;
            }
            $modell = (string)($r['model'] ?? '');
            $name = (string)($r['customName'] ?? '');
            if ($name === '') {
                $name = (string)($r['deviceInfo']['displayName'] ?? $modell);
            }
            $out[] = [
                'id'     => $did,
                'name'   => $name,
                'modell' => $modell,
                'klasse' => self::klasseVon($modell),
                'online' => !empty($r['online']),
                'roh'    => ['bindDomain' => $r['bindDomain'] ?? null, 'ver' => $r['ver'] ?? null],
            ];
        }
        return $out;
    }

    /** Sauger oder Mäher — ioBroker erkennt Mäher ebenfalls am Modellnamen. */
    public static function klasseVon(string $modell): string
    {
        return stripos($modell, 'mower') !== false ? RoboHubModell::KLASSE_MAEHER : RoboHubModell::KLASSE_SAUGER;
    }

    private function geraeteRoh(array $buendel): array
    {
        $antwort = $this->api($buendel, '/dreame-user-iot/iotuserbind/device/listV2', [
            'sharedStatus' => 1, 'current' => 1, 'size' => 100, 'lang' => 'de', 'timestamp' => (int)round(microtime(true) * 1000),
        ]);
        $records = $antwort['data']['page']['records'] ?? null;
        if (!is_array($records)) {
            throw new RoboHubFehler('Unerwartete Antwort der Geräteliste.');
        }
        return $records;
    }

    // =================================================================
    // Zustand
    // =================================================================

    // Abfrageblöcke je Klasse. Klein gehalten, weil ein Block mit einer
    // nicht unterstützten SIID als Ganzes scheitern kann (ioBroker-Befund).
    private const BLOECKE_SAUGER = [
        [[2, 1], [2, 2], [3, 1], [3, 2]],
        [[4, 1], [4, 2], [4, 3], [4, 4], [4, 5], [4, 6], [4, 7], [4, 23], [4, 25], [4, 63]],
        [[9, 2], [10, 2], [11, 1], [16, 1], [18, 1], [35, 2]],
        [[27, 1], [27, 2], [27, 3], [27, 4]],
        [[28, 1]],
        [[12, 2], [12, 3], [12, 4]],
    ];
    private const BLOECKE_MAEHER = [
        [[2, 1], [2, 2], [3, 1], [3, 2]],
        [[4, 1], [4, 2], [4, 3], [4, 7]],
    ];

    public function zustand(array $buendel, array $geraet): array
    {
        $did = (string)$geraet['id'];
        $modell = (string)($geraet['modell'] ?? '');
        $klasse = self::klasseVon($modell);

        // 1) Zusammenfassung aus der Geräteliste (online, latestStatus, battery).
        $eintrag = null;
        foreach ($this->geraeteRoh($buendel) as $r) {
            if ((string)($r['did'] ?? '') === $did) {
                $eintrag = $r;
                break;
            }
        }
        if ($eintrag === null) {
            throw new RoboHubFehler('Gerät ' . $did . ' ist nicht mehr im Konto.');
        }
        $online = !empty($eintrag['online']);

        // 2) Properties blockweise. Ein Offline-Gerät nicht weiter befragen.
        // Blöcke, auf die dieses Gerät nachweislich nicht antwortet, gibt das
        // Konto in 'ueberspringen' mit (Indizes) — jede unbeantwortete Abfrage
        // kostet sonst einige Sekunden Wartezeit (Live-Befund LiDAX, Block 4.x).
        $ueberspringen = array_map('intval', (array)($geraet['ueberspringen'] ?? []));
        $p = [];
        $fehl = [];
        $ok = [];
        if ($online) {
            foreach ($klasse === RoboHubModell::KLASSE_MAEHER ? self::BLOECKE_MAEHER : self::BLOECKE_SAUGER as $i => $block) {
                if (in_array($i, $ueberspringen, true)) {
                    continue;
                }
                try {
                    $p += $this->leseProperties($buendel, $did, $block);
                    $ok[] = $i;
                } catch (RoboHubFehler $e) {
                    if ($e->anmeldungNoetig || $e->warteSekunden > 0) {
                        throw $e;
                    }
                    $fehl[] = $i;
                }
            }
        }
        $fehlBloecke = count($fehl);
        // Zusammenfassung als Grundwert, echte Abfrage gewinnt (Reihenfolge wie ioBroker).
        if (!isset($p['2.1']) && isset($eintrag['latestStatus']) && $klasse === RoboHubModell::KLASSE_SAUGER) {
            $p['2.1'] = (int)$eintrag['latestStatus'];
        }
        if (!isset($p['3.1']) && isset($eintrag['battery'])) {
            $p['3.1'] = (int)$eintrag['battery'];
        }

        $daten = $klasse === RoboHubModell::KLASSE_MAEHER ? $this->normalisiereMaeher($p, $modell) : $this->normalisiereSauger($p);
        if ($klasse === RoboHubModell::KLASSE_MAEHER && $online) {
            $daten['werte'] += $this->maeherVerschleiss($buendel, $did);
        }
        $daten['online'] = $online;
        $daten['bloecke'] = ['ok' => $ok, 'fehl' => $fehl];
        $daten['roh'] = [
            'modell'        => $modell,
            'firmware'      => $eintrag['ver'] ?? null,
            'liste'         => ['online' => $eintrag['online'] ?? null, 'latestStatus' => $eintrag['latestStatus'] ?? null, 'battery' => $eintrag['battery'] ?? null],
            'properties'    => $p,
            'fehlendeBloecke' => $fehlBloecke,
            'uebersprungeneBloecke' => $ueberspringen,
        ];
        return $daten;
    }

    /** get_properties für einen Block; Rückgabe ['siid.piid' => Wert] (nur gelieferte Werte). */
    private function leseProperties(array $buendel, string $did, array $block): array
    {
        $params = [];
        foreach ($block as [$siid, $piid]) {
            $params[] = ['did' => $did, 'siid' => $siid, 'piid' => $piid];
        }
        $ergebnis = $this->sendCommand($buendel, $did, 'get_properties', $params);
        $out = [];
        foreach ((array)$ergebnis as $e) {
            if (!is_array($e) || (int)($e['code'] ?? 0) !== 0 || !array_key_exists('value', $e)) {
                continue;
            }
            $out[(int)$e['siid'] . '.' . (int)$e['piid']] = $e['value'];
        }
        return $out;
    }

    // Zustand 2.1, neue Belegung (Tasshack DreameVacuumState, Fähigkeit NEW_STATE).
    private const SAUGER_ZUSTAND = [
        1 => ['Saugt', RoboHubModell::ZUSTAND_REINIGT],
        2 => ['Leerlauf', RoboHubModell::ZUSTAND_BEREIT],
        3 => ['Pausiert', RoboHubModell::ZUSTAND_PAUSIERT],
        4 => ['Fehler', RoboHubModell::ZUSTAND_FEHLER],
        5 => ['Fährt zur Station', RoboHubModell::ZUSTAND_ZURUECK],
        6 => ['Lädt', RoboHubModell::ZUSTAND_LAEDT],
        7 => ['Wischt', RoboHubModell::ZUSTAND_REINIGT],
        8 => ['Trocknet Mopp', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        9 => ['Wäscht Mopp', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        10 => ['Fährt zum Mopp-Waschen', RoboHubModell::ZUSTAND_ZURUECK],
        11 => ['Kartiert', RoboHubModell::ZUSTAND_KARTIERUNG],
        12 => ['Saugt und wischt', RoboHubModell::ZUSTAND_REINIGT],
        13 => ['Geladen', RoboHubModell::ZUSTAND_GELADEN],
        14 => ['Firmware-Update', RoboHubModell::ZUSTAND_AKTUALISIERUNG],
        15 => ['Rufreinigung', RoboHubModell::ZUSTAND_REINIGT],
        16 => ['Selbstreparatur', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        17 => ['Fährt zum Mopp-Anbringen', RoboHubModell::ZUSTAND_ZURUECK],
        18 => ['Fährt zum Mopp-Abnehmen', RoboHubModell::ZUSTAND_ZURUECK],
        19 => ['Wassertest', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        20 => ['Füllt Wasser nach', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        21 => ['Waschen pausiert', RoboHubModell::ZUSTAND_PAUSIERT],
        22 => ['Saugt Staub ab', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        23 => ['Fernsteuerung', RoboHubModell::ZUSTAND_REINIGT],
        24 => ['Intelligentes Laden', RoboHubModell::ZUSTAND_LAEDT],
        25 => ['Zweite Reinigung', RoboHubModell::ZUSTAND_REINIGT],
        27 => ['Punktreinigung', RoboHubModell::ZUSTAND_REINIGT],
        28 => ['Fährt zum Absaugen', RoboHubModell::ZUSTAND_ZURUECK],
        29 => ['Wartet auf Auftrag', RoboHubModell::ZUSTAND_BEREIT],
        30 => ['Stationsreinigung', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        31 => ['Entleert', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        32 => ['Entleert', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        34 => ['Leert Tank', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        35 => ['Trocknet Staubbeutel', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        36 => ['Trocknet Staubbeutel', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        37 => ['Extrareinigung', RoboHubModell::ZUSTAND_REINIGT],
        38 => ['Extrareinigung', RoboHubModell::ZUSTAND_REINIGT],
        97 => ['Kurzbefehl läuft', RoboHubModell::ZUSTAND_REINIGT],
        101 => ['Erst-Tiefenreinigung', RoboHubModell::ZUSTAND_REINIGT],
        102 => ['Erst-Tiefenreinigung', RoboHubModell::ZUSTAND_REINIGT],
        103 => ['Desinfektion', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        104 => ['Desinfektion', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        105 => ['Moppwechsel', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        106 => ['Moppwechsel', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        116 => ['Bringt Mopp an', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        117 => ['Nimmt Mopp ab', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        121 => ['Fährt in die Station', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        122 => ['Fährt aus der Station', RoboHubModell::ZUSTAND_STATIONSARBEIT],
    ];

    // Fehler 2.2 (Auszug, Tasshack DreameVacuumErrorCode). Unbekannte Codes
    // erscheinen als „Fehlercode N" — nie still verschluckt.
    private const SAUGER_FEHLER = [
        1 => 'Roboter umgekippt/angehoben', 2 => 'Absturzsensor ausgelöst', 3 => 'Stoßfänger klemmt',
        4 => 'Rad blockiert', 5 => 'Rad angehoben', 6 => 'Rad blockiert', 7 => 'Laser-Abstandssensor blockiert',
        8 => 'Staubbehälter fehlt', 9 => 'Filter fehlt', 10 => 'Filter verstopft', 11 => 'Staubbehälter voll',
        12 => 'Hauptbürste blockiert', 13 => 'Seitenbürste blockiert', 14 => 'Lüfter-Fehler',
        15 => 'Wassertank fehlt', 20 => 'Akku schwach', 21 => 'Ladefehler', 22 => 'Akku-Fehler',
        24 => 'Wischmopp fehlt', 47 => 'Roboter festgefahren', 101 => 'Schmutzwassertank fehlt',
        102 => 'Schmutzwassertank voll', 103 => 'Frischwasser leer', 104 => 'Frischwassertank fehlt',
        105 => 'Wasserwanne fehlt', 106 => 'Staubbeutel voll', 107 => 'Wasserwanne verschmutzt',
        117 => 'Staubbeutel fehlt', 210 => 'Wischwalze blockiert', 218 => 'Wischwalze prüfen',
        222 => 'Auflockerungswalze blockiert', 225 => 'Wischwalze prüfen', 1000 => 'Rückkehr zur Station fehlgeschlagen',
    ];

    private function normalisiereSauger(array $p): array
    {
        $w = [];
        $optionen = [];
        if (isset($p['2.1'])) {
            $roh = (int)$p['2.1'];
            [$text, $zustand] = self::SAUGER_ZUSTAND[$roh] ?? ['Zustand ' . $roh, RoboHubModell::ZUSTAND_UNBEKANNT];
            $w['Zustand'] = $zustand;
            $w['ZustandRoh'] = $text . ' (' . $roh . ')';
        }
        if (isset($p['2.2'])) {
            $code = (int)$p['2.2'];
            $w['Fehler'] = $code === 0 ? 'kein Fehler' : (self::SAUGER_FEHLER[$code] ?? 'Fehlercode ' . $code) . ' (' . $code . ')';
            if ($code !== 0 && ($w['Zustand'] ?? null) === null) {
                $w['Zustand'] = RoboHubModell::ZUSTAND_FEHLER;
            }
        }
        $w['Akku'] = isset($p['3.1']) ? (int)$p['3.1'] : null;
        $w['Laedt'] = isset($p['3.2']) ? in_array((int)$p['3.2'], [1], true) : null;
        $w['Dauer'] = isset($p['4.2']) ? (int)$p['4.2'] : null;
        $w['Flaeche'] = isset($p['4.3']) ? (int)$p['4.3'] : null;

        if (isset($p['4.4'])) {
            $w['Saugstufe'] = (int)$p['4.4'];
            $optionen['Saugstufe'] = [0 => 'Leise', 1 => 'Standard', 2 => 'Stark', 3 => 'Turbo'];
        }
        // Wassermenge: neuere Modelle (Feuchte-Stufen 28.1) melden 4.5 nicht
        // oder ohne Wirkung — dann bleibt die Variable weg statt falsch zu sein.
        if (isset($p['4.5']) && !isset($p['28.1'])) {
            $w['Wassermenge'] = (int)$p['4.5'];
            $optionen['Wassermenge'] = [1 => 'Niedrig', 2 => 'Mittel', 3 => 'Hoch'];
        }
        if (isset($p['4.23'])) {
            $w['Reinigungsmodus'] = $this->reinigungsmodusLesen((int)$p['4.23'], $p);
            $optionen['Reinigungsmodus'] = [0 => 'Saugen', 1 => 'Wischen', 2 => 'Saugen und Wischen', 3 => 'Erst saugen, dann wischen'];
        }

        $w['Hauptbuerste']  = isset($p['9.2']) ? (int)$p['9.2'] : null;
        $w['Seitenbuerste'] = isset($p['10.2']) ? (int)$p['10.2'] : null;
        $w['Filter']        = isset($p['11.1']) ? (int)$p['11.1'] : null;   // Filter: % an piid 1 (Belegung so belegt)
        $w['Sensoren']      = isset($p['16.1']) ? (int)$p['16.1'] : null;
        $w['Mopp']          = isset($p['18.1']) ? (int)$p['18.1'] : null;

        if (isset($p['4.25'])) {
            $w['Station'] = [0 => 'Bereit', 1 => 'Wäscht', 2 => 'Trocknet', 3 => 'Roboter kehrt zurück', 4 => 'Pausiert', 5 => 'Füllt Wasser', 6 => 'Füllt Wasser', 7 => 'Kehrt zum Trocknen zurück'][(int)$p['4.25']] ?? 'Zustand ' . (int)$p['4.25'];
        }
        if (isset($p['27.1'])) {
            $w['Frischwasser'] = [0 => 'In Ordnung', 1 => 'Tank fehlt', 2 => 'Wenig Wasser', 3 => 'Prüfen'][(int)$p['27.1']] ?? 'Zustand ' . (int)$p['27.1'];
        }
        if (isset($p['27.2'])) {
            $w['Schmutzwasser'] = (int)$p['27.2'] === 0 ? 'In Ordnung' : 'Fehlt oder voll';
        }
        if (isset($p['27.3'])) {
            $w['Staubbeutel'] = [0 => 'In Ordnung', 1 => 'Fehlt', 2 => 'Prüfen'][(int)$p['27.3']] ?? 'Zustand ' . (int)$p['27.3'];
        }
        if (isset($p['27.4'])) {
            $w['Reinigungsmittel'] = [0 => 'In Ordnung', 1 => 'Abgeschaltet', 2 => 'Wenig'][(int)$p['27.4']] ?? 'Zustand ' . (int)$p['27.4'];
        }
        $w['GesamtDauer']  = isset($p['12.2']) ? (int)round((int)$p['12.2'] / 60) : null;
        $w['GesamtAnzahl'] = isset($p['12.3']) ? (int)$p['12.3'] : null;
        $w['GesamtFlaeche'] = isset($p['12.4']) ? (int)$p['12.4'] : null;

        return ['werte' => $w, 'optionen' => $optionen];
    }

    /**
     * Reinigungsmodus 4.23 ist gepackt (Bits 0–1 Modus). Geräte mit
     * anhebbarem Mopp tauschen 0 und 2 auf der Leitung (ioBroker
     * lib/specs/cleaning.js, Tasshack device.py). ioBroker erkennt sie an
     * „Waschstation + Absaugstation"; hier: Waschstation-Zustand (4.25) UND
     * Staubbeutel-Zustand (27.3) werden geliefert.
     */
    private function hatMoppAnhebung(array $p): bool
    {
        return isset($p['4.25']) && isset($p['27.3']);
    }

    private function reinigungsmodusLesen(int $roh, array $p): int
    {
        return self::tauscheModus($roh & 3, $this->hatMoppAnhebung($p));
    }

    private static function tauscheModus(int $modus, bool $anhebung): int
    {
        if (!$anhebung) {
            return $modus;
        }
        return $modus === 2 ? 0 : ($modus === 0 ? 2 : $modus);
    }

    // Mäher 2.1. Wo sich die Quellen widersprechen, gelten die Beschriftungen
    // der Hersteller-App (EvotecIT app_protocol.py MOWER_STATE_LABELS, en+zh,
    // mit LiDAX-Rückmeldungen; deckt sich mit dem ioBroker-README):
    // 3 = Pause, 4 = Pause wegen Fehler, 15/16 = Laden pausiert (Temperatur).
    // ioBrokers Code nennt dort 3 = Mähen, 4 = Pause, 15/16 = Ortung/Anfahrt.
    // Werte nur aus ioBroker (7–12) ohne Widerspruch übernommen.
    private const MAEHER_ZUSTAND = [
        1 => ['Mäht', RoboHubModell::ZUSTAND_REINIGT],
        2 => ['Bereit', RoboHubModell::ZUSTAND_BEREIT],
        3 => ['Pausiert', RoboHubModell::ZUSTAND_PAUSIERT],
        4 => ['Pausiert wegen Fehler', RoboHubModell::ZUSTAND_FEHLER],
        5 => ['Fährt zur Station', RoboHubModell::ZUSTAND_ZURUECK],
        6 => ['Lädt', RoboHubModell::ZUSTAND_LAEDT],
        7 => ['Fehler', RoboHubModell::ZUSTAND_FEHLER],
        8 => ['Regenpause', RoboHubModell::ZUSTAND_WETTERPAUSE],
        9 => ['Startet', RoboHubModell::ZUSTAND_BEREIT],
        10 => ['Verlässt die Station', RoboHubModell::ZUSTAND_REINIGT],
        11 => ['Kartiert', RoboHubModell::ZUSTAND_KARTIERUNG],
        12 => ['Mäht Kanten', RoboHubModell::ZUSTAND_REINIGT],
        13 => ['Geladen', RoboHubModell::ZUSTAND_GELADEN],
        14 => ['Firmware-Update', RoboHubModell::ZUSTAND_AKTUALISIERUNG],
        15 => ['Laden pausiert — Akku zu heiß', RoboHubModell::ZUSTAND_LAEDT],
        16 => ['Laden pausiert — Akku zu kalt', RoboHubModell::ZUSTAND_LAEDT],
        // 19 kennt keine Referenz (ioBroker, EvotecIT, mova-lidax-ha, Homey-App).
        // Live beobachtet am MOVA LiDAX Ultra 1000 (g2529c, FW 4.3.6_0529) direkt
        // nach „Start", Akku sinkend, Besitzer bestätigt: mäht (11.10.2026).
        19 => ['Mäht', RoboHubModell::ZUSTAND_REINIGT],
        75 => ['Am Wartungspunkt pausiert', RoboHubModell::ZUSTAND_PAUSIERT],
    ];

    // Mäher-Gerätecode 2.2: gemeinsames Register aller Dreame/MOVA-Mäher
    // (EvotecIT/homeassistant-dreamelawnmower, device_code_semantics.py, MIT,
    // gelesen 11.10.2026). 'f' = Fehler/Warnung/Wartung, 'i' = reine Info.
    private const MAEHER_CODES = [
        0 => ['i', ''],
        1 => ['f', 'Roboter gekippt'], 2 => ['f', 'Roboter festgefahren'], 3 => ['f', 'Weg zur Station zu schmal'],
        4 => ['f', 'Linkes Antriebsrad gestört'], 5 => ['f', 'Rechtes Antriebsrad gestört'], 6 => ['f', 'Schnitthöhen-Motor gestört'],
        7 => ['f', 'Messerteller blockiert'], 8 => ['f', 'Messerteller-Seitenmotor gestört'], 9 => ['f', 'Stoßfänger gestört'],
        10 => ['f', 'Ladefehler'], 11 => ['f', 'Akku zu heiß'], 12 => ['f', 'Lidar blockiert'],
        13 => ['f', 'Lidar zu heiß (ohne Karte)'], 14 => ['f', 'Lidar zu heiß (mit Karte)'], 15 => ['f', 'Lidar zu heiß'],
        16 => ['f', 'Lidar verschmutzt'], 17 => ['f', 'Lidar gestört'], 18 => ['f', 'Ortungssignal schwach'],
        19 => ['f', 'Position verloren'], 20 => ['f', 'Sensor gestört'], 21 => ['f', 'Mäher in Sperrzone'],
        22 => ['f', 'Mäher außerhalb der Karte'], 23 => ['f', 'Not-Aus gedrückt'], 24 => ['f', 'Akku kritisch schwach'],
        25 => ['f', 'Kartendatei beschädigt'], 26 => ['f', 'Mäher zu weit von der Karte'], 27 => ['f', 'Person erkannt'],
        37 => ['f', 'Weg blockiert'], 73 => ['f', 'Abdeckung offen'],
        28 => ['f', 'Messer abgenutzt'], 29 => ['f', 'Stationsbürste abgenutzt'], 30 => ['f', 'Wartung fällig'],
        31 => ['f', 'Rückkehr zur Station fehlgeschlagen'], 32 => ['f', 'Andocken fehlgeschlagen'],
        33 => ['f', 'Ortung fehlgeschlagen (mit Karte)'], 34 => ['f', 'Ortung fehlgeschlagen (ohne Karte)'],
        35 => ['f', 'Ortungsfehler'], 36 => ['f', 'Auftrag konnte nicht starten'], 38 => ['f', 'Lidar verschmutzt'],
        39 => ['f', 'Kamera verschmutzt'], 40 => ['f', 'Kamera gestört'], 41 => ['f', 'Kamera verdeckt'],
        42 => ['f', 'Laden pausiert — Akku zu heiß'], 43 => ['f', 'Laden pausiert — Akku zu kalt'],
        44 => ['f', 'Automatische Randerkennung gestoppt'], 45 => ['f', 'Automatische Randerkennung (Seite) gestoppt'],
        46 => ['i', 'Randerkennung abgeschlossen'], 47 => ['i', 'Neue Karte erstellt'], 48 => ['i', 'Mähauftrag abgeschlossen'],
        49 => ['i', 'Einige Zonen nicht erreichbar'], 50 => ['i', 'Mähauftrag gestartet'], 51 => ['i', 'Patrouille gestartet'],
        52 => ['i', 'Zielfahrt gestartet'], 53 => ['i', 'Geplantes Mähen gestartet'], 54 => ['i', 'Akku schwach — kehrt zurück'],
        55 => ['i', 'Geplantes Mähen ausgesetzt — Akku schwach'], 56 => ['i', 'Schlechtwetter-Schutz aktiv'],
        57 => ['i', 'Geplantes Mähen wegen Regen unterbrochen'], 58 => ['i', 'Geplantes Mähen wegen Regen ausgesetzt'],
        59 => ['i', 'Frostschutz — kehrt zurück'], 60 => ['i', 'Geplantes Mähen wegen Frost ausgesetzt'],
        61 => ['i', 'Ruhezeit — kehrt zurück'], 62 => ['i', 'Geplantes Mähen wegen Ruhezeit ausgesetzt'],
        63 => ['i', 'Geplantes Mähen übersprungen — Mäher beschäftigt'], 64 => ['i', 'Geplantes Mähen wegen Fernsteuerung ausgesetzt'],
        65 => ['i', 'Geplantes Mähen wegen Not-Aus ausgesetzt'], 66 => ['i', 'Geplantes Mähen ausgesetzt — Abdeckung offen'],
        67 => ['i', 'Geplantes Mähen wegen Störung ausgesetzt'], 68 => ['i', 'Geplantes Mähen: Zeit abgelaufen'],
        69 => ['i', 'Station nicht mit dem Mähbereich verbunden'], 70 => ['i', 'Setzt unterbrochenen Auftrag fort'],
        71 => ['i', 'Leerlauf zu lang — kehrt zurück'], 72 => ['i', 'Pause zu lang — kehrt zurück'],
    ];

    // LiDAX Ultra 1000/2000 und 2000 AWD belegen 0 und 55 anders (dort mit
    // echten Geräten erfasst; ältere MOVA-Modelle bewusst NICHT einbezogen).
    private const MAEHER_CODES_LIDAX = [
        0 => ['f', 'Roboter angehoben'],
        55 => ['f', 'Start nicht möglich — Akku zu schwach'],
    ];
    private const LIDAX_MODELLE = ['mova.mower.g2529c', 'mova.mower.g2529f', 'mova.mower.g2584a'];

    private function normalisiereMaeher(array $p, string $modell): array
    {
        $w = [];
        if (isset($p['2.1'])) {
            $roh = (int)$p['2.1'];
            [$text, $zustand] = self::MAEHER_ZUSTAND[$roh] ?? ['Zustand ' . $roh, RoboHubModell::ZUSTAND_UNBEKANNT];
            $w['Zustand'] = $zustand;
            $w['ZustandRoh'] = $text . ' (' . $roh . ')';
            $w['Regensensor'] = $roh === 8;
        }
        // 2.2 ist beim Mäher ein Gerätecode: Fehler/Wartung → „Fehler",
        // reine Infos (Auftrag fertig, Regenschutz …) → „Letzte Meldung".
        if (isset($p['2.2'])) {
            $code = (int)$p['2.2'];
            $codes = self::MAEHER_CODES;
            if (in_array(strtolower($modell), self::LIDAX_MODELLE, true)) {
                $codes = self::MAEHER_CODES_LIDAX + $codes;
            }
            [$art, $text] = $codes[$code] ?? ['f', 'Gerätecode ' . $code];
            if ($art === 'f') {
                $w['Fehler'] = $text . ' (' . $code . ')';
            } else {
                $w['Fehler'] = 'kein Fehler';
                if ($text !== '') {
                    $w['Meldung'] = $text . ' (' . $code . ')';
                }
            }
        }
        $w['Akku'] = isset($p['3.1']) ? (int)$p['3.1'] : null;
        $w['Laedt'] = isset($p['3.2']) ? (int)$p['3.2'] === 1 : null;
        $w['Dauer'] = isset($p['4.2']) ? (int)$p['4.2'] : null;
        $w['Flaeche'] = isset($p['4.3']) ? (int)$p['4.3'] : null;
        return ['werte' => $w, 'optionen' => []];
    }

    /** Messer-Verschleiß über den Mäher-Konfigurationskanal (action 2.50, CMS). */
    private function maeherVerschleiss(array $buendel, string $did): array
    {
        try {
            $out = $this->sendCommand($buendel, $did, 'action', ['did' => $did, 'siid' => 2, 'aiid' => 50, 'in' => [['m' => 'g', 't' => 'CMS']]]);
        } catch (RoboHubFehler $e) {
            if ($e->anmeldungNoetig || $e->warteSekunden > 0) {
                throw $e;
            }
            return [];
        }
        $erstes = $out['out'][0] ?? null;
        if (is_string($erstes)) {
            $erstes = json_decode($erstes, true);
        }
        $werte = $erstes['d']['value'] ?? null;
        if (!is_array($werte) || !isset($werte[0])) {
            return [];
        }
        // Gesund in % = (1 − Minuten / Max) × 100; Max je Teil laut
        // docs/mower-protocol.md: Klingen 6000, Reinigungsbürste 30000,
        // Roboter-Wartung 3600 min. Alle drei am LiDAX Ultra 1000 gegen die
        // MOVAhome-App bestätigt (34 % / 87 % / 72 %, 11.10.2026).
        $prozent = function (int $index, int $max) use ($werte): ?int {
            return isset($werte[$index]) && is_numeric($werte[$index])
                ? max(0, min(100, (int)round((1 - (float)$werte[$index] / $max) * 100)))
                : null;
        };
        return array_filter([
            'Messer'            => $prozent(0, 6000),
            'Reinigungsbuerste' => $prozent(1, 30000),
            'Wartung'           => $prozent(2, 3600),
        ], function ($v) { return $v !== null; });
    }

    // =================================================================
    // Befehle
    // =================================================================

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        $did = (string)$geraet['id'];
        $maeher = self::klasseVon((string)($geraet['modell'] ?? '')) === RoboHubModell::KLASSE_MAEHER;

        // Mäher: die Quellen widersprechen sich. EvotecIT (const.py, mit
        // LiDAX-Ultra-1000-Rückmeldung „commands") nutzt Start 5.1, Stopp
        // 5.2, Station 5.3, Pause 5.4; ioBrokers Code nutzt 2.1/2.2/2.4
        // (beschriftet sie aber selbst mit „5-1/5-2/5-4"). Zur Station ist in
        // beiden 5.3 (3.1 wirkt beim Mäher nicht, ioBroker Issue #49).
        // Deshalb: 5.x zuerst, bei Ablehnung einmal der ioBroker-Weg.
        $aktionen = $maeher
            ? ['start' => [[5, 1], [2, 1]], 'stopp' => [[5, 2], [2, 2]], 'pause' => [[5, 4], [2, 4]], 'station' => [[5, 3]]]
            : ['start' => [[2, 1]], 'pause' => [[2, 2]], 'station' => [[3, 1]], 'stopp' => [[4, 2]], 'orten' => [[7, 1]]];

        if (isset($aktionen[$befehl])) {
            $letzterFehler = null;
            foreach ($aktionen[$befehl] as [$siid, $aiid]) {
                try {
                    $this->aktion($buendel, $did, $siid, $aiid, []);
                    $this->dbg('Befehl', self::befehlName($befehl) . ' über ' . $siid . '.' . $aiid . ' angenommen.');
                    return 'Befehl „' . self::befehlName($befehl) . '" gesendet.';
                } catch (RoboHubFehler $e) {
                    if ($e->anmeldungNoetig || $e->warteSekunden > 0) {
                        throw $e;
                    }
                    $this->dbg('Befehl', self::befehlName($befehl) . ' über ' . $siid . '.' . $aiid . ' abgelehnt: ' . $e->getMessage());
                    $letzterFehler = $e;
                }
            }
            throw $letzterFehler;
        }
        if ($befehl === 'orten' && $maeher) {
            // Mäher „Finden" über den Konfigurationskanal (o 9).
            $this->aktion($buendel, $did, 2, 50, [['m' => 'a', 'p' => 0, 'o' => 9]]);
            return 'Befehl „Orten" gesendet.';
        }
        if ($befehl === 'raum' && !$maeher) {
            return $this->raumReinigung($buendel, $did, array_map('intval', (array)($parameter['raeume'] ?? [])));
        }
        if ($befehl === 'setze' && !$maeher) {
            return $this->setze($buendel, $did, (string)($parameter['feld'] ?? ''), (int)($parameter['wert'] ?? 0));
        }
        throw new RoboHubFehler('Dieser Befehl wird für ' . ($maeher ? 'Mähroboter' : 'Saugroboter') . ' noch nicht unterstützt: ' . $befehl);
    }

    private static function befehlName(string $befehl): string
    {
        return ['start' => 'Start', 'pause' => 'Pause', 'stopp' => 'Stopp', 'station' => 'Zur Station', 'orten' => 'Orten'][$befehl] ?? $befehl;
    }

    private function raumReinigung(array $buendel, string $did, array $raeume): string
    {
        if (count($raeume) === 0) {
            throw new RoboHubFehler('Kein Raum angegeben.');
        }
        // Aktuelle Saugstufe/Wassermenge übernehmen (wie ioBroker).
        $p = $this->leseProperties($buendel, $did, [[4, 4], [4, 5]]);
        $saug = isset($p['4.4']) ? (int)$p['4.4'] : 1;
        $wasser = isset($p['4.5']) ? (int)$p['4.5'] : 2;
        $selects = [];
        foreach ($raeume as $seg) {
            // Fünftes Feld fest 1 — sonst bricht Gen-5 nach dem ersten Raum ab (beide Referenzen).
            $selects[] = [$seg, 1, $saug, $wasser, 1];
        }
        $this->aktion($buendel, $did, 4, 1, [['piid' => 1, 'value' => 18], ['piid' => 10, 'value' => json_encode(['selects' => $selects])]]);
        return 'Raumreinigung für ' . count($raeume) . ' Raum/Räume gesendet.';
    }

    private function setze(array $buendel, string $did, string $feld, int $wert): string
    {
        if ($feld === 'Saugstufe') {
            $this->setzeProperty($buendel, $did, 4, 4, $wert);
            return 'Saugstufe gesendet.';
        }
        if ($feld === 'Wassermenge') {
            $this->setzeProperty($buendel, $did, 4, 5, $wert);
            return 'Wassermenge gesendet.';
        }
        if ($feld === 'Reinigungsmodus') {
            // Read-Modify-Write: nur Bits 0–1 ändern, Feuchte/Selbstreinigungsfläche erhalten.
            $p = $this->leseProperties($buendel, $did, [[4, 23], [4, 25], [27, 3]]);
            if (!isset($p['4.23'])) {
                throw new RoboHubFehler('Reinigungsmodus konnte nicht gelesen werden — nichts geändert.');
            }
            $roh = (int)$p['4.23'];
            $neu = ($roh & ~3) | (self::tauscheModus($wert & 3, $this->hatMoppAnhebung($p)) & 3);
            $this->setzeProperty($buendel, $did, 4, 23, $neu);
            return 'Reinigungsmodus gesendet.';
        }
        throw new RoboHubFehler('Einstellung „' . $feld . '" ist nicht schreibbar.');
    }

    /** action siid.aiid; wirft, wenn das Gerät die Aktion ablehnt (result.code ≠ 0). */
    private function aktion(array $buendel, string $did, int $siid, int $aiid, array $in): array
    {
        $erg = $this->sendCommand($buendel, $did, 'action', ['did' => $did, 'siid' => $siid, 'aiid' => $aiid, 'in' => $in]);
        $code = is_array($erg) ? (int)($erg['code'] ?? 0) : 0;
        if ($code !== 0) {
            throw new RoboHubFehler('Der Roboter hat den Befehl abgelehnt (Code ' . $code . ').');
        }
        return is_array($erg) ? $erg : [];
    }

    private function setzeProperty(array $buendel, string $did, int $siid, int $piid, $wert): void
    {
        $erg = $this->sendCommand($buendel, $did, 'set_properties', [['did' => $did, 'siid' => $siid, 'piid' => $piid, 'value' => $wert]]);
        $code = (int)($erg[0]['code'] ?? 0);
        if ($code !== 0) {
            throw new RoboHubFehler('Der Roboter hat die Einstellung abgelehnt (Code ' . $code . ').');
        }
    }

    // =================================================================
    // Räume (Sauger) — aus der gespeicherten Karte, ohne Bild auszuwerten
    // =================================================================

    // Vordefinierte Raumtypen (ioBroker lib/cleanset.js).
    private const RAUMTYPEN = [
        1 => 'Wohnzimmer', 2 => 'Schlafzimmer', 3 => 'Arbeitszimmer', 4 => 'Küche', 5 => 'Esszimmer',
        6 => 'Bad', 7 => 'Balkon', 8 => 'Flur', 9 => 'Hauswirtschaftsraum', 10 => 'Abstellraum',
        11 => 'Besprechungsraum', 12 => 'Büro', 13 => 'Fitnessraum', 14 => 'Freizeitraum', 15 => 'Zweites Schlafzimmer',
    ];

    /** Raum-ID => Name; leeres Array, wenn keine Karte vorhanden/lesbar. */
    public function raeume(array $buendel, array $geraet): array
    {
        if (self::klasseVon((string)($geraet['modell'] ?? '')) === RoboHubModell::KLASSE_MAEHER) {
            return []; // Mäh-Zonen folgen in einer späteren Version.
        }
        $did = (string)$geraet['id'];
        $p = $this->leseProperties($buendel, $did, [[6, 8]]);
        $objekt = json_decode((string)($p['6.8'] ?? ''), true);
        $datei = is_array($objekt) ? (string)($objekt['object_name'] ?? '') : '';
        if ($datei === '') {
            $this->dbg('Karte', 'Keine gespeicherte Karte (6.8 leer).');
            return [];
        }
        $url = $this->api($buendel, '/dreame-user-iot/iotfile/getDownloadUrl', [
            'did' => $did, 'model' => (string)($geraet['modell'] ?? ''), 'filename' => $datei, 'region' => (string)($buendel['region'] ?? 'eu'),
        ])['data'] ?? '';
        if (!is_string($url) || $url === '') {
            return [];
        }
        [$status, $text] = $this->http('GET', $url, ['Accept: */*'], null);
        $inhalt = json_decode($text, true);
        if ($status !== 200 || !is_array($inhalt)) {
            $this->dbg('Karte', 'Kartendatei nicht ladbar (HTTP ' . $status . ').');
            return [];
        }
        $karten = (array)($inhalt['mapstr'] ?? []);
        $erste = $karten[0] ?? null;
        foreach ($karten as $k) {
            if (is_array($k) && ((int)($k['first'] ?? -1) === 0 || (int)($k['id'] ?? -1) === 0)) {
                $erste = $k;
            }
        }
        $kartenText = is_array($erste) ? (string)($erste['thb'] ?? $erste['map'] ?? '') : '';
        $zusatz = self::dekodiereKarte($kartenText, (string)($geraet['modell'] ?? ''));
        if ($zusatz === null) {
            $this->dbg('Karte', 'Karte nicht dekodierbar (verschlüsselt mit unbekanntem Schlüssel oder anderes Format).');
            return [];
        }
        return self::raeumeAusSegmenten((array)($zusatz['seg_inf'] ?? []));
    }

    /**
     * Dekodiert einen Kartenstring bis zum JSON-Anhang (ioBroker
     * lib/dreame.js decodeMultiMapData): URL-sicheres Base64, optional
     * AES-256-CBC (Schlüssel = erste 32 Hex-Zeichen von sha256(Teil nach dem
     * Komma), IV modellabhängig, sonst leer), zlib, 27-Byte-Kopf, Bild,
     * dann JSON. Das Bild selbst wird nicht ausgewertet.
     */
    public static function dekodiereKarte(string $kartenText, string $modell): ?array
    {
        if ($kartenText === '') {
            return null;
        }
        $schluessel = '';
        if (strpos($kartenText, ',') !== false) {
            [$kartenText, $suffix] = explode(',', $kartenText, 2);
            $schluessel = substr(hash('sha256', $suffix), 0, 32);
        }
        $bin = base64_decode(strtr($kartenText, '-_', '+/'), true);
        if ($bin === false || $bin === '') {
            return null;
        }
        if ($schluessel !== '') {
            // Bekannte IVs (ioBroker MODEL_IV_TABLE); sonst leerer IV = 16 Nullbytes.
            $iv = stripos($modell, 'dreame.vacuum.r2253') === 0 ? 'NRwnBj5FsNPgBNbT' : str_repeat("\0", 16);
            $bin = openssl_decrypt($bin, 'aes-256-cbc', $schluessel, OPENSSL_RAW_DATA, $iv);
            if ($bin === false) {
                return null;
            }
        }
        $roh = @zlib_decode($bin);
        if ($roh === false || strlen($roh) < 27) {
            return null;
        }
        $kopf = unpack('vmapId/x2/cframe/x14/vbreite/vhoehe', $roh);
        if ((int)$kopf['frame'] !== 73) {
            return null;
        }
        $anhang = substr($roh, 27 + (int)$kopf['breite'] * (int)$kopf['hoehe']);
        $json = json_decode((string)$anhang, true);
        return is_array($json) ? $json : null;
    }

    public static function raeumeAusSegmenten(array $segmente): array
    {
        $raeume = [];
        foreach ($segmente as $id => $seg) {
            if (!is_array($seg) || !is_numeric($id)) {
                continue;
            }
            $typ = (int)($seg['type'] ?? 0);
            $name = '';
            if ($typ === 0 && !empty($seg['name'])) {
                $name = (string)base64_decode((string)$seg['name'], true);
            } elseif (isset(self::RAUMTYPEN[$typ])) {
                $name = self::RAUMTYPEN[$typ] . ((int)($seg['index'] ?? 0) > 0 ? ' ' . ((int)$seg['index'] + 1) : '');
            }
            $raeume[(int)$id] = $name !== '' ? $name : 'Raum ' . $id;
        }
        ksort($raeume);
        return $raeume;
    }

    // =================================================================
    // HTTP
    // =================================================================

    private function basisUrl(string $region): string
    {
        return 'https://' . $region . '.' . self::MARKEN[$this->marke]['domain'] . ':' . self::PORT;
    }

    private function basisHeader(string $region, string $land): array
    {
        $m = self::MARKEN[$this->marke];
        $rlc = bin2hex((string)openssl_encrypt($region . '|en|' . $land, 'aes-128-ecb', $m['rlc'], OPENSSL_RAW_DATA));
        return [
            'User-Agent: Dart/3.2 (dart:io)',
            'Dreame-Meta: cv=i_829',
            'Dreame-Rlc: ' . $rlc,
            'Tenant-Id: ' . $m['tenant'],
            'Authorization: Basic ' . $m['basic'],
        ];
    }

    /** POST mit JSON an die Cloud-API, prüft code == 0. */
    private function api(array $buendel, string $pfad, array $daten): array
    {
        $region = (string)($buendel['region'] ?? 'eu');
        $headers = $this->basisHeader($region, (string)($buendel['country'] ?? 'DE'));
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Dreame-Auth: bearer ' . (string)($buendel['access_token'] ?? '');
        [$status, $text] = $this->http('POST', $this->basisUrl($region) . $pfad, $headers, (string)json_encode($daten));

        if ($status === 401) {
            $f = new RoboHubFehler('Zugangsschlüssel abgelaufen oder ungültig — bitte neu anmelden.');
            $f->anmeldungNoetig = true;
            throw $f;
        }
        if ($status === 429 || $status >= 500 || $status === 0) {
            $f = new RoboHubFehler($status === 0 ? 'Hersteller-Cloud nicht erreichbar (' . $text . ').' : 'Hersteller-Cloud antwortet mit HTTP ' . $status . '.');
            $f->warteSekunden = $status === 429 ? 600 : 120;
            throw $f;
        }
        $json = json_decode($text, true);
        if (!is_array($json)) {
            throw new RoboHubFehler('Unverständliche Antwort der Hersteller-Cloud (HTTP ' . $status . ').');
        }
        if ((int)($json['code'] ?? -1) !== 0) {
            $code = (int)($json['code'] ?? -1);
            throw new RoboHubFehler(in_array($code, [-8, 80001], true)
                ? 'Roboter antwortet nicht (offline oder im Ruhezustand).'
                : 'Hersteller-Cloud meldet Fehler ' . $code . ((string)($json['msg'] ?? '') !== '' ? ': ' . $json['msg'] : '') . '.');
        }
        return $json;
    }

    /** Befehl an ein Gerät; Rückgabe data.result. */
    private function sendCommand(array $buendel, string $did, string $methode, array $params)
    {
        $id = random_int(1000, 9999);
        $prefix = self::MARKEN[$this->marke]['prefix'];
        $antwort = $this->api($buendel, '/dreame-iot-com-' . $prefix . '/device/sendCommand', [
            'did'  => $did,
            'id'   => $id,
            'data' => ['did' => $did, 'id' => $id, 'method' => $methode, 'params' => $params],
        ]);
        $this->dbg('sendCommand ' . $methode, (string)json_encode(['params' => $params, 'antwort' => $antwort['data'] ?? null], JSON_UNESCAPED_UNICODE));
        return $antwort['data']['result'] ?? [];
    }

    private function pruefeRegion(string $region): string
    {
        return preg_match('/^[a-z]{2}$/', $region) ? $region : 'eu';
    }

    private function dbg(string $thema, string $text): void
    {
        if (is_callable($this->debug)) {
            ($this->debug)($thema, $text);
        }
    }

    /**
     * Ein HTTP-Aufruf; Rückgabe [Statuscode, Text] — Status 0 bei
     * Verbindungsfehler (Text = Fehlermeldung). Eigene Methode, damit der
     * Prüfstand sie ersetzen kann. curl statt Sys_GetURLContentEx, weil
     * diese kein POST kann (SUITE.md Stolperstein 11).
     */
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
}
