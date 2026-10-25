<?php

declare(strict_types=1);

// ===========================================================================
// RoboHub-Treiber für GARDENA smart system Mähroboter (Sileno) — offizielle
// GARDENA smart system API. Anmeldung wie Husqvarna (gleiches Portal,
// client_credentials mit Application Key/Secret); deshalb von
// RoboHubHusqvarna abgeleitet.
//
// Quelle: py-smart-gardena (MIT, Stand 29.07.2025): smart_system.py
// (Adressen, Köpfe, PUT /v2/command/{Dienst}), devices/mower.py (Befehle,
// Felder), tests/fixtures.py (Datenformat).
//
// Daten: GET /v2/locations, dann je Ort GET /v2/locations/{id} — die Antwort
// enthält unter „included" die Geräte und ihre Dienste (COMMON, MOWER, …),
// deren Werte als {"value": …} stehen. Ein Gerät = Dienst-IDs mit gleichem
// Teil vor dem „:".
//
// Abfragelimit: GARDENA erlaubt deutlich weniger REST-Abfragen als Husqvarna;
// alle Mäher teilen sich deshalb einen Abruf höchstens alle 15 Minuten.
// Echtzeit (0.20.0): POST /v2/websocket {locationId} liefert eine Adresse (enthält die
// Anmeldung), die Symcons WebSocket Client öffnet; Meldungen sind Dienst-Objekte wie in
// „included" (py-smart-gardena smart_system.py start_ws/on_message). Nur der erste Ort.
// Noch nicht gebaut: Ventile/Sensoren/Steckdosen.
// ===========================================================================

class RoboHubGardena extends RoboHubHusqvarna
{
    public $zwischenspeicherSekunden = 900;

    private const API = 'https://api.smart.gardena.dev/v2/';
    private const START_SEKUNDEN = 10800;

    // MOWER.activity → [Text, Zustand]
    private const TAETIGKEIT = [
        'OK_CUTTING'                  => ['Mäht', RoboHubModell::ZUSTAND_REINIGT],
        'OK_CUTTING_TIMER_OVERRIDDEN' => ['Mäht (manuell)', RoboHubModell::ZUSTAND_REINIGT],
        'OK_LEAVING'                  => ['Verlässt die Station', RoboHubModell::ZUSTAND_REINIGT],
        'OK_SEARCHING'                => ['Sucht die Station', RoboHubModell::ZUSTAND_ZURUECK],
        'OK_CHARGING'                 => ['Lädt', RoboHubModell::ZUSTAND_LAEDT],
        'PARKED_TIMER'                => ['Geparkt (Zeitplan)', RoboHubModell::ZUSTAND_GELADEN],
        'PARKED_PARK_SELECTED'        => ['Geparkt bis auf Weiteres', RoboHubModell::ZUSTAND_GELADEN],
        'PARKED_AUTOTIMER'            => ['Geparkt (SensorControl)', RoboHubModell::ZUSTAND_WETTERPAUSE],
        'PARKED_FROST'                => ['Geparkt (Frost)', RoboHubModell::ZUSTAND_WETTERPAUSE],
        'PAUSED'                      => ['Pausiert', RoboHubModell::ZUSTAND_PAUSIERT],
        'STOPPED_IN_GARDEN'           => ['Steht im Garten', RoboHubModell::ZUSTAND_BEREIT],
        'SEARCHING_FOR_SATELLITES'    => ['Sucht Satelliten', RoboHubModell::ZUSTAND_BEREIT],
        'NONE'                        => ['Keine Tätigkeit', RoboHubModell::ZUSTAND_BEREIT],
    ];

    // MOWER.lastErrorCode (Auszug, deutsch).
    private const FEHLER = [
        'NO_MESSAGE' => null, 'OUTSIDE_WORKING_AREA' => 'Außerhalb des Arbeitsbereichs', 'NO_LOOP_SIGNAL' => 'Kein Schleifensignal',
        'WRONG_LOOP_SIGNAL' => 'Falsches Schleifensignal', 'TRAPPED' => 'Eingeklemmt', 'UPSIDE_DOWN' => 'Kopfüber',
        'EMPTY_BATTERY' => 'Akku leer', 'NO_DRIVE' => 'Kein Antrieb', 'TEMPORARILY_LIFTED' => 'Kurz angehoben', 'LIFTED' => 'Angehoben',
        'STUCK_IN_CHARGING_STATION' => 'Steckt in der Ladestation', 'CHARGING_STATION_BLOCKED' => 'Ladestation blockiert',
        'CUTTING_SYSTEM_BLOCKED' => 'Schneidsystem blockiert', 'MOWER_TILTED' => 'Mäher gekippt', 'ALARM_MOWER_LIFTED' => 'Alarm: angehoben',
        'ALARM_MOWER_STOPPED' => 'Alarm: angehalten', 'ALARM_MOWER_SWITCHED_OFF' => 'Alarm: ausgeschaltet', 'OFF_DISABLED' => 'Ausgeschaltet',
        'OFF_HATCH_OPEN' => 'Klappe offen', 'OFF_HATCH_CLOSED' => 'Ausgeschaltet (Klappe zu)', 'PARKED_DAILY_LIMIT_REACHED' => 'Tageslimit erreicht',
        'SLOPE_TOO_STEEP' => 'Zu steil', 'WHEEL_MOTOR_BLOCKED_LEFT' => 'Radmotor links blockiert', 'WHEEL_MOTOR_BLOCKED_RIGHT' => 'Radmotor rechts blockiert',
    ];

    /** Neue Adresse je Verbindung (gilt nur kurz) — für den ersten Ort mit einem Mäher. */
    public function wsZiel(array $buendel): array
    {
        $ort = '';
        foreach ($this->maeher($buendel, false) as $g) {
            $ort = (string)($g['ort'] ?? '');
            if ($ort !== '') {
                break;
            }
        }
        if ($ort === '') {
            throw new RoboHubFehler('Kein GARDENA-Ort mit Mähroboter gefunden.');
        }
        $j = $this->anfrage($buendel, 'POST', 'websocket', ['data' => ['type' => 'WEBSOCKET', 'attributes' => ['locationId' => $ort], 'id' => 'robohub']]);
        $url = (string)($j['data']['attributes']['url'] ?? '');
        if (strpos($url, 'wss://') !== 0) {
            throw new RoboHubFehler('GARDENA hat keine Echtzeit-Adresse geliefert.');
        }
        return ['url' => $url, 'kopf' => []];
    }

    /** GARDENA: kein Lebenszeichen auf Anwendungsebene (py-smart-gardena nutzt nur WebSocket-Ping). */
    public function wsLebenszeichen(): ?string
    {
        return null;
    }

    /** Dienst-Meldung (MOWER/COMMON) einarbeiten → [Geräte-ID => ['common' => …, 'mower' => …]]. */
    public function pushLesenMit(string $payload, array $buendel): array
    {
        $d = json_decode($payload, true);
        $typ = strtolower((string)($d['type'] ?? ''));
        if (!in_array($typ, ['mower', 'common'], true)) {
            return [];
        }
        $id = explode(':', (string)($d['id'] ?? ''))[0];
        $werte = self::geraeteAus([$d])[0][$typ] ?? [];
        $aus = [];
        $this->zwischenspeicherAendern($buendel, function (array &$liste) use ($id, $typ, $werte, &$aus): void {
            foreach ($liste as &$g) {
                if ((string)($g['id'] ?? '') === $id) {
                    $g[$typ] = array_replace((array)($g[$typ] ?? []), $werte);
                    $aus = ['common' => $g['common'], 'mower' => $g['mower']];
                    return;
                }
            }
        });
        return $aus === [] ? [] : [$id => $aus];
    }

    protected function marke(): string
    {
        return 'gardena';
    }

    protected function apiName(): string
    {
        return 'GARDENA smart system API';
    }

    protected function apiBasis(): string
    {
        return self::API;
    }

    /** Alle Mäher aller Orte: ['id', 'dienst', 'ort', 'common' => [Feld => Wert], 'mower' => [Feld => Wert]]. */
    protected function maeherHolen(array $buendel): array
    {
        $liste = [];
        foreach ((array)($this->anfrage($buendel, 'GET', 'locations', null)['data'] ?? []) as $ort) {
            $ortId = (string)($ort['id'] ?? '');
            if ($ortId === '') {
                continue;
            }
            $j = $this->anfrage($buendel, 'GET', 'locations/' . rawurlencode($ortId), null);
            foreach (self::geraeteAus((array)($j['included'] ?? [])) as $g) {
                if ($g['dienst'] !== null) {
                    $liste[] = $g + ['ort' => $ortId];
                }
            }
        }
        return $liste;
    }

    /** „included" nach Geräten bündeln (Dienst-ID bis zum „:"), Werte aus {"value": …} lösen. */
    public static function geraeteAus(array $included): array
    {
        $geraete = [];
        foreach ($included as $d) {
            $typ = (string)($d['type'] ?? '');
            if (!in_array($typ, ['COMMON', 'MOWER'], true)) {
                continue;
            }
            $id = explode(':', (string)($d['id'] ?? ''))[0];
            $werte = [];
            foreach ((array)($d['attributes'] ?? []) as $feld => $a) {
                $werte[$feld] = is_array($a) && array_key_exists('value', $a) ? $a['value'] : $a;
            }
            $geraete[$id]['id'] = $id;
            $geraete[$id][strtolower($typ)] = $werte;
            if ($typ === 'MOWER') {
                $geraete[$id]['dienst'] = (string)$d['id'];
            }
        }
        foreach ($geraete as $id => $g) {
            $geraete[$id] += ['common' => [], 'mower' => [], 'dienst' => null];
        }
        return array_values($geraete);
    }

    public function geraete(array $buendel): array
    {
        $liste = [];
        foreach ($this->maeher($buendel, true) as $g) {
            $c = $g['common'];
            $liste[] = [
                'id'     => (string)$g['id'],
                'name'   => (string)($c['name'] ?? 'GARDENA Mähroboter'),
                'modell' => 'gardena.mower.' . (preg_replace('/[^a-z0-9]+/', '', strtolower(str_replace(['GARDENA', 'smart'], '', (string)($c['modelType'] ?? 'sileno')))) ?: 'sileno'),
                'klasse' => RoboHubModell::KLASSE_MAEHER,
                'online' => strtoupper((string)($c['rfLinkState'] ?? 'ONLINE')) === 'ONLINE',
                'roh'    => ['modelType' => $c['modelType'] ?? null, 'serial' => $c['serial'] ?? null, 'ort' => $g['ort'] ?? null],
            ];
        }
        return $liste;
    }

    public function zustand(array $buendel, array $geraet): array
    {
        $g = $this->einer($buendel, (string)$geraet['id']);
        $p = ['common' => $g['common'], 'mower' => $g['mower'], 'dienst' => $g['dienst']];
        $daten = $this->normalisiere((string)($geraet['modell'] ?? ''), $p);
        $daten['online'] = strtoupper((string)($g['common']['rfLinkState'] ?? 'ONLINE')) === 'ONLINE';
        $daten['bloecke'] = ['ok' => [0], 'fehl' => []];
        $daten['roh'] = ['modell' => $geraet['modell'] ?? null, 'properties' => $p];
        return $daten;
    }

    public function normalisiere(string $modell, array $p): array
    {
        $w = [];
        $m = (array)($p['mower'] ?? []);
        $c = (array)($p['common'] ?? []);
        $taetigkeit = strtoupper((string)($m['activity'] ?? ''));
        $zustand = strtoupper((string)($m['state'] ?? ''));
        if ($taetigkeit !== '' || $zustand !== '') {
            [$text, $z] = self::TAETIGKEIT[$taetigkeit] ?? ['Tätigkeit „' . $taetigkeit . '"', RoboHubModell::ZUSTAND_UNBEKANNT];
            if ($zustand === 'ERROR') {
                $z = RoboHubModell::ZUSTAND_FEHLER;
            } elseif ($zustand === 'UNAVAILABLE') {
                $z = RoboHubModell::ZUSTAND_UNBEKANNT;
                $text = 'Nicht erreichbar';
            }
            $w['Zustand'] = $z;
            $w['ZustandRoh'] = $text . ' (' . strtolower($taetigkeit) . ', ' . strtolower($zustand) . ')';
            $w['Laedt'] = $taetigkeit === 'OK_CHARGING';
        }
        if (array_key_exists('lastErrorCode', $m) || $zustand !== '') {
            $code = strtoupper((string)($m['lastErrorCode'] ?? 'NO_MESSAGE'));
            $w['Fehler'] = in_array($zustand, ['ERROR', 'WARNING'], true) && $code !== 'NO_MESSAGE'
                ? (self::FEHLER[$code] ?? str_replace('_', ' ', strtolower($code))) . ' (' . $code . ')'
                : 'kein Fehler';
        }
        $w['Akku'] = isset($c['batteryLevel']) && is_numeric($c['batteryLevel']) ? (int)$c['batteryLevel'] : null;
        $w['GesamtDauer'] = isset($m['operatingHours']) && is_numeric($m['operatingHours']) ? (int)$m['operatingHours'] : null;
        return ['werte' => $w, 'optionen' => [], 'schreibbar' => []];
    }

    public function karte(array $buendel, array $geraet): array
    {
        return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
    }

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        $pausiert = strtoupper((string)($geraet['stand']['mower']['activity'] ?? '')) === 'PAUSED';
        $befehle = [
            'start'   => $pausiert ? [['command' => 'START_DONT_OVERRIDE'], 'Fortsetzen nach Zeitplan']
                : [['command' => 'START_SECONDS_TO_OVERRIDE', 'seconds' => self::START_SEKUNDEN], 'Start (3 h)'],
            'station' => [['command' => 'PARK_UNTIL_NEXT_TASK'], 'Parken bis zum nächsten Zeitplan'],
            'stopp'   => [['command' => 'PARK_UNTIL_FURTHER_NOTICE'], 'Parken bis auf Weiteres'],
        ];
        if (!isset($befehle[$befehl])) {
            throw new RoboHubFehler(['pause' => '„Pause" kennt die GARDENA-Schnittstelle nicht — „Zur Station" parkt den Mäher.', 'orten' => '„Orten" gibt es bei GARDENA-Mähern nicht.'][$befehl]
                ?? 'Dieser Befehl wird bei GARDENA (noch) nicht unterstützt: ' . $befehl);
        }
        $g = $this->einer($buendel, (string)$geraet['id']);
        [$attribute, $text] = $befehle[$befehl];
        $this->anfrage($buendel, 'PUT', 'command/' . rawurlencode((string)$g['dienst']), ['data' => [
            'id' => self::anfrageId(), 'type' => 'MOWER_CONTROL', 'attributes' => $attribute,
        ]]);
        @unlink($this->speicherDatei($buendel));
        return 'Befehl „' . $text . '" gesendet.';
    }

    /** Eindeutige Anfrage-ID (die API verlangt eine beliebige ID je Befehl). */
    private static function anfrageId(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
