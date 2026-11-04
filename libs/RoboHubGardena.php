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
        'OK_CUTTING'                  => ['Mowing', RoboHubModell::ZUSTAND_REINIGT],
        'OK_CUTTING_TIMER_OVERRIDDEN' => ['Mowing (manual)', RoboHubModell::ZUSTAND_REINIGT],
        'OK_LEAVING'                  => ['Leaving the station', RoboHubModell::ZUSTAND_REINIGT],
        'OK_SEARCHING'                => ['Searching for the station', RoboHubModell::ZUSTAND_ZURUECK],
        'OK_CHARGING'                 => ['Charging', RoboHubModell::ZUSTAND_LAEDT],
        'PARKED_TIMER'                => ['Parked (schedule)', RoboHubModell::ZUSTAND_GELADEN],
        'PARKED_PARK_SELECTED'        => ['Parked until further notice', RoboHubModell::ZUSTAND_GELADEN],
        'PARKED_AUTOTIMER'            => ['Parked (SensorControl)', RoboHubModell::ZUSTAND_WETTERPAUSE],
        'PARKED_FROST'                => ['Parked (frost)', RoboHubModell::ZUSTAND_WETTERPAUSE],
        'PAUSED'                      => ['Paused', RoboHubModell::ZUSTAND_PAUSIERT],
        'STOPPED_IN_GARDEN'           => ['Standing in the garden', RoboHubModell::ZUSTAND_BEREIT],
        'SEARCHING_FOR_SATELLITES'    => ['Searching for satellites', RoboHubModell::ZUSTAND_BEREIT],
        'NONE'                        => ['No activity', RoboHubModell::ZUSTAND_BEREIT],
    ];

    // MOWER.lastErrorCode (Auszug, deutsch).
    private const FEHLER = [
        'NO_MESSAGE' => null, 'OUTSIDE_WORKING_AREA' => 'Outside the working area', 'NO_LOOP_SIGNAL' => 'No loop signal',
        'WRONG_LOOP_SIGNAL' => 'Wrong loop signal', 'TRAPPED' => 'Trapped', 'UPSIDE_DOWN' => 'Upside down',
        'EMPTY_BATTERY' => 'Battery empty', 'NO_DRIVE' => 'No drive', 'TEMPORARILY_LIFTED' => 'Briefly lifted', 'LIFTED' => 'Lifted',
        'STUCK_IN_CHARGING_STATION' => 'Stuck in the charging station', 'CHARGING_STATION_BLOCKED' => 'Charging station blocked',
        'CUTTING_SYSTEM_BLOCKED' => 'Cutting system blocked', 'MOWER_TILTED' => 'Mower tilted', 'ALARM_MOWER_LIFTED' => 'Alarm: lifted',
        'ALARM_MOWER_STOPPED' => 'Alarm: stopped', 'ALARM_MOWER_SWITCHED_OFF' => 'Alarm: switched off', 'OFF_DISABLED' => 'Switched off',
        'OFF_HATCH_OPEN' => 'Hatch open', 'OFF_HATCH_CLOSED' => 'Switched off (hatch closed)', 'PARKED_DAILY_LIMIT_REACHED' => 'Daily limit reached',
        'SLOPE_TOO_STEEP' => 'Too steep', 'WHEEL_MOTOR_BLOCKED_LEFT' => 'Left wheel motor blocked', 'WHEEL_MOTOR_BLOCKED_RIGHT' => 'Right wheel motor blocked',
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
            throw new RoboHubFehler(RoboHubText::t('No GARDENA location with a robot mower found.'));
        }
        $j = $this->anfrage($buendel, 'POST', 'websocket', ['data' => ['type' => 'WEBSOCKET', 'attributes' => ['locationId' => $ort], 'id' => 'robohub']]);
        $url = (string)($j['data']['attributes']['url'] ?? '');
        if (strpos($url, 'wss://') !== 0) {
            throw new RoboHubFehler(RoboHubText::t('GARDENA did not deliver a real-time address.'));
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
                'name'   => (string)($c['name'] ?? RoboHubText::t('GARDENA robot mower')),
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
            [$text, $z] = self::TAETIGKEIT[$taetigkeit] ?? [RoboHubText::t('Activity "%s"', $taetigkeit), RoboHubModell::ZUSTAND_UNBEKANNT];
            $text = RoboHubText::wert($text);
            if ($zustand === 'ERROR') {
                $z = RoboHubModell::ZUSTAND_FEHLER;
            } elseif ($zustand === 'UNAVAILABLE') {
                $z = RoboHubModell::ZUSTAND_UNBEKANNT;
                $text = RoboHubText::t('Not reachable');
            }
            $w['Zustand'] = $z;
            $w['ZustandRoh'] = $text . ' (' . strtolower($taetigkeit) . ', ' . strtolower($zustand) . ')';
            $w['Laedt'] = $taetigkeit === 'OK_CHARGING';
        }
        if (array_key_exists('lastErrorCode', $m) || $zustand !== '') {
            $code = strtoupper((string)($m['lastErrorCode'] ?? 'NO_MESSAGE'));
            $w['Fehler'] = in_array($zustand, ['ERROR', 'WARNING'], true) && $code !== 'NO_MESSAGE'
                ? (RoboHubText::wert(self::FEHLER[$code] ?? null) ?? str_replace('_', ' ', strtolower($code))) . ' (' . $code . ')'
                : RoboHubText::t('no error');
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
            'start'   => $pausiert ? [['command' => 'START_DONT_OVERRIDE'], RoboHubText::t('Resume according to schedule')]
                : [['command' => 'START_SECONDS_TO_OVERRIDE', 'seconds' => self::START_SEKUNDEN], RoboHubText::t('Start (3 h)')],
            'station' => [['command' => 'PARK_UNTIL_NEXT_TASK'], RoboHubText::t('Park until the next schedule')],
            'stopp'   => [['command' => 'PARK_UNTIL_FURTHER_NOTICE'], RoboHubText::t('Park until further notice')],
        ];
        if (!isset($befehle[$befehl])) {
            throw new RoboHubFehler(['pause' => RoboHubText::t('The GARDENA API does not know "Pause" — "Return to base" parks the mower.'), 'orten' => RoboHubText::t('GARDENA mowers have no "Locate".')][$befehl]
                ?? RoboHubText::t('This command is not supported by GARDENA (yet): %s', $befehl));
        }
        $g = $this->einer($buendel, (string)$geraet['id']);
        [$attribute, $text] = $befehle[$befehl];
        $this->anfrage($buendel, 'PUT', 'command/' . rawurlencode((string)$g['dienst']), ['data' => [
            'id' => self::anfrageId(), 'type' => 'MOWER_CONTROL', 'attributes' => $attribute,
        ]]);
        @unlink($this->speicherDatei($buendel));
        return RoboHubText::t('Command "%s" sent.', RoboHubText::wert($text));
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
