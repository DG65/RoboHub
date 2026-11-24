<?php

declare(strict_types=1);

require_once __DIR__ . '/RoboHubModell.php';
require_once __DIR__ . '/RoboHubText.php';

/**
 * Roborock Q7-Reihe (Protokoll „B01“, Modellkennung roborock.vacuum.sc…) — Stufe 1 (0.54.0): Zustand, Akku,
 * Fehler, Saugstufe, Wassermenge, Reinigungsmodus, Verbrauchsteile, Lautstärke; Start/Pause/Stopp/Station/Orten.
 *
 * Quelle: Python-roborock/python-roborock (Apache-2.0, Stand 93f0d98 vom 22.11.2026):
 *   protocol.py (Rahmen B01: AES-CBC mit local_key, IV = md5hex(%08x(random) + B01_HASH)[9:25], Inhalt gepolstert),
 *   protocols/b01_q7_protocol.py (Anfrage {"dps":{"10000":{method,msgId,params}}}, doppelt gepolstert),
 *   devices/rpc/b01_q7_channel.py (Antwort: dps-Wert = JSON-Text {msgId, code, data}, Zuordnung über msgId),
 *   devices/traits/b01/q7/__init__.py (Befehle), data/b01_q7/* (Felder, Codes, Teile-Lebensdauer).
 * Nur MQTT (keine lokale Verbindung). Q10 (Modellkennung ss…) spricht ein anderes Datenpunkt-Protokoll — nicht gebaut.
 * Nicht gebaut: Räume/Karte (SCMap-Protobuf), Zonen, Echtzeit-Pushes, Verbrauchsteile zurücksetzen (keine Quelle).
 */
class RoboHubRoborockQ7
{
    public const B01_HASH = '5wwh9ikChRjASpMU8cxg7o1d2E';
    public const DPS_ANFRAGE = 10000;

    // prop.get: Felder, die RoboHub auswertet (B01Props in data/b01_q7/b01_q7_containers.py).
    public const ABFRAGE = ['status', 'fault', 'wind', 'water', 'mode', 'quantity', 'charge_state', 'volume',
        'main_brush', 'side_brush', 'hypa', 'mop_life', 'main_sensor', 'work_mode', 'station_act', 'dust_action'];

    // WorkStatusMapping (Rohwert => [Text, RoboHub-Zustand]).
    public const ZUSTAND = [
        0  => ['Sleeping', RoboHubModell::ZUSTAND_SCHLAF],
        1  => ['Waiting for orders', RoboHubModell::ZUSTAND_BEREIT],
        2  => ['Paused', RoboHubModell::ZUSTAND_PAUSIERT],
        3  => ['Returning to dock', RoboHubModell::ZUSTAND_ZURUECK],
        4  => ['Charging', RoboHubModell::ZUSTAND_LAEDT],
        5  => ['Vacuuming and mopping', RoboHubModell::ZUSTAND_REINIGT],
        6  => ['Vacuuming and mopping', RoboHubModell::ZUSTAND_REINIGT],
        7  => ['Mopping', RoboHubModell::ZUSTAND_REINIGT],
        8  => ['Updating', RoboHubModell::ZUSTAND_AKTUALISIERUNG],
        9  => ['Washing mop', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        10 => ['Drying mop', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        11 => ['Sleeping while working', RoboHubModell::ZUSTAND_PAUSIERT],
    ];

    // SCWindMapping, WaterLevelMapping, CleanTypeMapping.
    public const SAUGSTUFEN = [1 => 'Quiet', 2 => 'Balanced', 3 => 'Turbo', 4 => 'Max', 5 => 'Max+'];
    public const WASSERMENGEN = [1 => 'Low', 2 => 'Medium', 3 => 'High'];
    // RoboHub-Modus (0 Saugen, 1 Wischen, 2 Saugen und Wischen) => Q7-Code (0 vacuum, 2 mop, 1 vac_and_mop).
    public const MODUS_ZU_Q7 = [0 => 0, 1 => 2, 2 => 1];

    // B01Fault: benannte Codes (übrige nur als Nummer, wie in der Quelle „fault_5xx").
    public const FEHLER = [
        407 => 'Cleaning in progress, scheduled cleaning ignored',
        500 => 'LiDAR blocked',
        501 => 'Robot suspended (wheels lifted)',
        502 => 'Battery low',
        503 => 'Dustbin or filter not installed',
        509 => 'Cliff sensor error',
        522 => 'Mop not installed',
        533 => 'Shutting down after a long sleep — charge the robot',
        560 => 'Side brush entangled',
    ];

    // Teile: Feld => [Variable, Lebensdauer in Minuten] (B01Props *_time_left: verbrauchte Minuten).
    public const TEILE = [
        'main_brush'  => ['Hauptbuerste', 18000],
        'side_brush'  => ['Seitenbuerste', 12000],
        'hypa'        => ['Filter', 9000],
        'mop_life'    => ['Mopp', 10800],
        'main_sensor' => ['Sensoren', 1800],
    ];

    /** Q7 = Protokoll B01 und Modellkennung mit „sc“ (device_manager.py: Q10 „ss“, Q7 „sc“). */
    public static function istQ7(array $geraet): bool
    {
        if ((string)($geraet['protokoll'] ?? '') !== 'B01') {
            return false;
        }
        $teile = explode('.', (string)($geraet['modell'] ?? ''));
        return strpos((string)end($teile), 'sc') !== false;
    }

    /** Nutzlast einer Anfrage (vor dem Rahmen): ein Mal gepolstert, der Rahmen polstert noch einmal (wie python-roborock). */
    public static function anfrageNutzlast(string $msgId, string $methode, $params): string
    {
        $json = (string)json_encode(['dps' => [(string)self::DPS_ANFRAGE => [
            'method' => $methode,
            'msgId'  => $msgId,
            'params' => $params ?? [],
        ]]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return self::polstern($json);
    }

    public static function polstern(string $text): string
    {
        $n = 16 - (strlen($text) % 16);
        return $text . str_repeat(chr($n), $n);
    }

    /** PKCS#7 entfernen, falls gültig — sonst unverändert (wie decode_rpc_response). */
    public static function entpolstern(string $text): string
    {
        $l = strlen($text);
        if ($l === 0 || $l % 16 !== 0) {
            return $text;
        }
        $n = ord($text[$l - 1]);
        if ($n < 1 || $n > 16 || substr($text, -$n) !== str_repeat(chr($n), $n)) {
            return $text;
        }
        return substr($text, 0, $l - $n);
    }

    /** Antworten aus einer entschlüsselten Nutzlast: Liste von ['msgId', 'code', 'data']. */
    public static function antworten(string $nutzlast): array
    {
        $j = json_decode(self::entpolstern($nutzlast), true);
        $aus = [];
        foreach ((array)(is_array($j) ? ($j['dps'] ?? []) : []) as $wert) {
            $innen = is_string($wert) ? json_decode($wert, true) : null;
            if (is_array($innen) && isset($innen['msgId'])) {
                $aus[] = ['msgId' => (string)$innen['msgId'], 'code' => (int)($innen['code'] ?? 0), 'data' => $innen['data'] ?? null];
            }
        }
        return $aus;
    }

    /** prop.get-Ergebnis → RoboHub-Werte. */
    public static function normalisiere(array $p): array
    {
        $w = [];
        $optionen = [];
        if (isset($p['status']) && is_numeric($p['status'])) {
            $roh = (int)$p['status'];
            [$text, $zustand] = self::ZUSTAND[$roh] ?? [RoboHubText::t('State %s', $roh), RoboHubModell::ZUSTAND_UNBEKANNT];
            $w['Zustand'] = $zustand;
            $w['ZustandRoh'] = RoboHubText::wert($text) . ' (' . $roh . ')';
            $w['Laedt'] = $roh === 4;
        }
        if (isset($p['fault']) && is_numeric($p['fault'])) {
            $code = (int)$p['fault'];
            if ($code === 0) {
                $w['Fehler'] = RoboHubText::t('no error');
            } else {
                $w['Fehler'] = (RoboHubText::wert(self::FEHLER[$code] ?? null) ?? RoboHubText::t('Error code %s', $code)) . ' (' . $code . ')';
                if ($code !== 407 && ($w['Zustand'] ?? null) !== RoboHubModell::ZUSTAND_REINIGT) {
                    $w['Zustand'] = RoboHubModell::ZUSTAND_FEHLER;
                }
            }
        }
        $w['Akku'] = isset($p['quantity']) && is_numeric($p['quantity']) ? (int)$p['quantity'] : null;
        if (isset($p['wind']) && is_numeric($p['wind'])) {
            $w['Saugstufe'] = (int)$p['wind'];
            $optionen['Saugstufe'] = self::SAUGSTUFEN + [(int)$p['wind'] => RoboHubText::t('Level %s', (int)$p['wind'])];
        }
        if (isset($p['water']) && is_numeric($p['water'])) {
            $w['Wassermenge'] = (int)$p['water'];
            $optionen['Wassermenge'] = self::WASSERMENGEN + [(int)$p['water'] => RoboHubText::t('Level %s', (int)$p['water'])];
        }
        if (isset($p['mode']) && is_numeric($p['mode'])) {
            $modus = array_search((int)$p['mode'], self::MODUS_ZU_Q7, true);
            if ($modus !== false) {
                $w['Reinigungsmodus'] = (int)$modus;
                $optionen['Reinigungsmodus'] = [0 => 'Vacuum', 1 => 'Mop', 2 => RoboHubText::t('Vacuum and mop')];
            }
        }
        foreach (self::TEILE as $feld => [$ident, $leben]) {
            if (isset($p[$feld]) && is_numeric($p[$feld])) {
                $rest = max(0, $leben - (int)$p[$feld]);
                $w[$ident] = (int)round(100 * $rest / $leben);
                $w[$ident . 'Rest'] = (float)round($rest / 60);
            }
        }
        $schreibbar = [];
        if (isset($p['volume']) && is_numeric($p['volume'])) {
            $w['Lautstaerke'] = (int)$p['volume'];
            $schreibbar[] = 'Lautstaerke';
        }
        return ['werte' => $w, 'optionen' => $optionen, 'schreibbar' => $schreibbar];
    }

    /** Befehl → [Methode, Parameter] (devices/traits/b01/q7/__init__.py). */
    public static function befehl(string $befehl): ?array
    {
        $raum = function (int $ctrl): array {
            return ['service.set_room_clean', ['clean_type' => 0, 'ctrl_value' => $ctrl, 'room_ids' => []]];
        };
        switch ($befehl) {
            case 'start':
                return $raum(1);
            case 'pause':
                return $raum(2);
            case 'stopp':
                return $raum(0);
            case 'station':
                return ['service.start_recharge', new stdClass()];
            case 'orten':
                return ['service.find_device', new stdClass()];
        }
        return null;
    }

    /** Einstellung → [Methode, Parameter] (prop.set {feld: wert}); null = unbekannt/ungültig. */
    public static function setze(string $feld, int $wert): ?array
    {
        if ($feld === 'Saugstufe' && isset(self::SAUGSTUFEN[$wert])) {
            return ['prop.set', ['wind' => $wert]];
        }
        if ($feld === 'Wassermenge' && isset(self::WASSERMENGEN[$wert])) {
            return ['prop.set', ['water' => $wert]];
        }
        if ($feld === 'Reinigungsmodus' && isset(self::MODUS_ZU_Q7[$wert])) {
            return ['prop.set', ['mode' => self::MODUS_ZU_Q7[$wert]]];
        }
        if ($feld === 'Lautstaerke' && $wert >= 0 && $wert <= 100) {
            return ['prop.set', ['volume' => $wert]];
        }
        return null;
    }
}
