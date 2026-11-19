<?php

declare(strict_types=1);

require_once __DIR__ . '/RoboHubKartenbild.php';

// ===========================================================================
// RoboHub-Treiber für Valetudo — freie Firmware für Saugroboter (Dreame,
// Roborock, Viomi …), lokal im Heimnetz, ohne Cloud.
//
// Quelle: Hypfer/Valetudo (Apache-2.0, Stand 18.11.2026): die REST-Schnittstelle
// des Roboters (backend/lib/webserver/doc/*.openapi.json, capabilityRouters/*),
// die Datenklassen (backend/lib/entities/…) und das Frontend (Kartenzeichnung).
//
// Weg: HTTP direkt zum Roboter (http://<IP>/api/v2/…), JSON. Kein MQTT-Broker
// nötig (Forum: Valetudo per MQTT einzubinden war mühsam, 144636/134ff.).
// Was ein Gerät kann, meldet /api/v2/robot/capabilities — RoboHub fragt nur das ab.
//
// Zugangsschutz: Valetudo kann optional HTTP-Basic-Auth verlangen (Standard aus).
// Dann gehen Benutzer und Passwort bei JEDER Anfrage mit → beides im Bündel des
// Kontos (Attribut). Dietmars Ausnahme vom Grundsatz „Passwort nie speichern"
// (19.11.2026), nie in Formular, Protokoll oder Debug.
//
// Karte: /api/v2/robot/state/map = ValetudoMap (layers mit RLE-Bildpunkten
// [xStart, y, Anzahl], entities in cm; y zeigt nach unten wie im Bild; Winkel
// 0° = Norden, im Uhrzeigersinn — Frontend RobotPositionMapStructure). Übersetzt
// in die Kodierung „rahmen" von RoboHubKartenbild (Raum n → n << 2).
// Nicht gebaut: Echtzeit (SSE), Zonen, Nicht stören, Stationsaufgaben, Timer.
// ===========================================================================

class RoboHubValetudo implements RoboHubTreiber
{
    /** @var callable|null fn(string $thema, string $text) */
    public $debug = null;

    /** Vom Konto beim Koppeln gesetzt: ['benutzer' => …, 'passwort' => …] (nur bei Zugangsschutz). */
    public $zugang = [];

    // PresetSelectionStateAttribute.INTENSITY / MODE → feste Zahlen für die Auswahlvariablen.
    public const STUFEN = ['off' => 0, 'min' => 1, 'low' => 2, 'medium' => 3, 'high' => 4, 'max' => 5, 'turbo' => 6, 'custom' => 7];
    private const STUFEN_TEXT = [0 => 'Off', 1 => 'Min', 2 => 'Low', 3 => 'Medium', 4 => 'High', 5 => 'Max', 6 => 'Turbo', 7 => 'Custom'];
    public const MODI = ['vacuum' => 0, 'mop' => 1, 'vacuum_and_mop' => 2, 'vacuum_then_mop' => 3];
    private const MODI_TEXT = [0 => 'Vacuum', 1 => 'Mop', 2 => 'Vacuum and mop', 3 => 'Vacuum first, then mop'];

    // Preset-Art (state attribute type) → [RoboHub-Feld, Capability]
    private const PRESETS = [
        'fan_speed'      => ['Saugstufe', 'FanSpeedControlCapability'],
        'water_grade'    => ['Wassermenge', 'WaterUsageControlCapability'],
        'operation_mode' => ['Reinigungsmodus', 'OperationModeControlCapability'],
    ];

    // Verbrauchsteile: "type/subType" → RoboHub-Ident (ValetudoConsumable.TYPE/SUB_TYPE, wie die Roboter-Implementierungen sie melden).
    public const TEILE = [
        'brush/main' => 'Hauptbuerste', 'brush/side_right' => 'Seitenbuerste', 'brush/side_left' => 'Seitenbuerste',
        'filter/main' => 'Filter', 'cleaning/sensor' => 'Sensoren', 'mop/main' => 'Mopp', 'mop/all' => 'Mopp',
    ];

    private const STATION_TEXT = ['idle' => 'Ready', 'emptying' => 'Emptying', 'cleaning' => 'Washing', 'drying' => 'Drying', 'pause' => 'Paused', 'error' => 'Error'];

    // =================================================================
    // Koppeln (Konto: IP eintragen, ggf. Benutzer/Passwort, „Jetzt anmelden")
    // =================================================================

    public function koppeln(string $adresse, array $buendel): array
    {
        $host = self::host($adresse);
        $r = ['host' => $host, 'benutzer' => (string)($this->zugang['benutzer'] ?? ''), 'passwort' => (string)($this->zugang['passwort'] ?? '')];
        $valetudo = $this->hole($r, '/api/v2/valetudo');
        $id = trim((string)($valetudo['systemId'] ?? ''));
        if ($id === '') {
            throw new RoboHubFehler(RoboHubText::t('The device at %s answers, but not like Valetudo.', $host));
        }
        $roboter = $this->hole($r, '/api/v2/robot');
        $buendel['marke'] = 'valetudo';
        $buendel['roboter'][$id] = $r + [
            'id'         => $id,
            'hersteller' => (string)($roboter['manufacturer'] ?? ''),
            'modell'     => (string)($roboter['modelName'] ?? ''),
        ];
        return $buendel;
    }

    public function koppelText(): string
    {
        return RoboHubText::t('✅ Paired. Valetudo needs no cloud — RoboHub remembers the address and, only if access protection is switched on, user name and password.');
    }

    public function anmelden(string $konto, string $passwort, string $region): array
    {
        return $this->koppeln($konto, []);
    }

    public function erneuern(array $buendel): array
    {
        if (empty($buendel['roboter'])) {
            $e = new RoboHubFehler(RoboHubText::t('No Valetudo robot paired yet.'));
            $e->anmeldungNoetig = true;
            throw $e;
        }
        return $buendel;
    }

    /** „192.168.1.50", „192.168.1.50:8080" oder „roboter.fritz.box" → Host[:Port]; sonst Fehler. */
    public static function host(string $adresse): string
    {
        $a = trim(preg_replace('#^https?://#i', '', trim($adresse)), '/ ');
        if (preg_match('/^([A-Za-z0-9.-]+)(:(\d{1,5}))?$/', $a, $m) && (filter_var($m[1], FILTER_VALIDATE_IP) !== false || preg_match('/^[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*$/', $m[1]))) {
            return $a;
        }
        throw new RoboHubFehler(RoboHubText::t('Please enter the robot\'s IP address (e.g. 192.168.1.50).'));
    }

    // =================================================================
    // Geräte und Zustand
    // =================================================================

    public function geraete(array $buendel): array
    {
        $liste = [];
        foreach ((array)($buendel['roboter'] ?? []) as $r) {
            $name = trim((string)$r['hersteller'] . ' ' . (string)$r['modell']);
            $liste[] = [
                'id'     => (string)$r['id'],
                'name'   => $name !== '' ? $name . ' (Valetudo)' : 'Valetudo',
                'modell' => 'valetudo.vacuum.' . (strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '_', $name)) ?: 'robot'),
                'klasse' => RoboHubModell::KLASSE_SAUGER,
                'online' => true,
                'roh'    => ['host' => $r['host'], 'hersteller' => $r['hersteller'], 'modell' => $r['modell']],
            ];
        }
        return $liste;
    }

    private function roboter(array $buendel, array $geraet): array
    {
        $r = $buendel['roboter'][(string)$geraet['id']] ?? null;
        if (!is_array($r)) {
            throw new RoboHubFehler(RoboHubText::t('This Valetudo robot is not (or no longer) paired in the account.'));
        }
        return $r;
    }

    public function zustand(array $buendel, array $geraet): array
    {
        $r = $this->roboter($buendel, $geraet);
        $kann = array_flip((array)$this->hole($r, '/api/v2/robot/capabilities'));
        $p = ['attribute' => (array)$this->hole($r, '/api/v2/robot/state/attributes'), 'presets' => [], 'teile' => [], 'teileMax' => [], 'gesamt' => [], 'aktuell' => []];
        foreach (self::PRESETS as $art => [, $cap]) {
            if (isset($kann[$cap])) {
                $p['presets'][$art] = (array)$this->hole($r, '/api/v2/robot/capabilities/' . $cap . '/presets');
            }
        }
        if (isset($kann['ConsumableMonitoringCapability'])) {
            $p['teile'] = (array)$this->hole($r, '/api/v2/robot/capabilities/ConsumableMonitoringCapability');
            $p['teileMax'] = (array)($this->hole($r, '/api/v2/robot/capabilities/ConsumableMonitoringCapability/properties')['availableConsumables'] ?? []);
        }
        if (isset($kann['TotalStatisticsCapability'])) {
            $p['gesamt'] = (array)$this->hole($r, '/api/v2/robot/capabilities/TotalStatisticsCapability');
        }
        if (isset($kann['CurrentStatisticsCapability'])) {
            $p['aktuell'] = (array)$this->hole($r, '/api/v2/robot/capabilities/CurrentStatisticsCapability');
        }
        $p['locate'] = isset($kann['LocateCapability']);
        $daten = $this->normalisiere((string)($geraet['modell'] ?? ''), $p);
        $daten['online'] = true;
        $daten['bloecke'] = ['ok' => [0], 'fehl' => []];
        $daten['roh'] = ['modell' => $geraet['modell'] ?? null, 'properties' => $p];
        return $daten;
    }

    public function normalisiere(string $modell, array $p): array
    {
        $w = [];
        $optionen = [];
        $attr = ['StatusStateAttribute' => null, 'BatteryStateAttribute' => null, 'DockStatusStateAttribute' => null];
        $presets = [];
        foreach ((array)($p['attribute'] ?? []) as $a) {
            $klasse = (string)($a['__class'] ?? '');
            if ($klasse === 'PresetSelectionStateAttribute') {
                $presets[(string)($a['type'] ?? '')] = (string)($a['value'] ?? '');
            } elseif (array_key_exists($klasse, $attr)) {
                $attr[$klasse] = $a;
            }
        }
        $akku = $attr['BatteryStateAttribute'];
        if (is_array($akku) && is_numeric($akku['level'] ?? null)) {
            $w['Akku'] = (int)max(0, min(100, round((float)$akku['level'])));
        }
        $flagAkku = (string)($akku['flag'] ?? 'none');
        $w['Laedt'] = $flagAkku === 'charging';
        $status = $attr['StatusStateAttribute'];
        $wert = (string)($status['value'] ?? '');
        $flag = (string)($status['flag'] ?? 'none');
        $dock = (string)($attr['DockStatusStateAttribute']['value'] ?? '');
        $w['Zustand'] = self::zustandAus($wert, $flag, $flagAkku, $w['Akku'] ?? null, $dock);
        $w['ZustandRoh'] = $wert . ($flag !== 'none' && $flag !== '' ? ' / ' . $flag : '');
        $fehler = $status['error'] ?? null;
        $w['Fehler'] = $wert === 'error'
            ? (is_array($fehler) && (string)($fehler['message'] ?? '') !== '' ? (string)$fehler['message'] : RoboHubText::t('Error'))
            : RoboHubText::t('no error');
        if ($dock !== '') {
            $w['Station'] = RoboHubText::wert(self::STATION_TEXT[$dock] ?? $dock);
        }

        foreach (self::PRESETS as $art => [$feld]) {
            $liste = (array)($p['presets'][$art] ?? []);
            if ($liste === []) {
                continue;
            }
            $tabelle = $art === 'operation_mode' ? self::MODI : self::STUFEN;
            $texte = $art === 'operation_mode' ? self::MODI_TEXT : self::STUFEN_TEXT;
            $opt = [];
            foreach ($liste as $name) {
                if (isset($tabelle[$name])) {
                    $opt[$tabelle[$name]] = $texte[$tabelle[$name]];
                }
            }
            if ($opt === []) {
                continue;
            }
            ksort($opt);
            $optionen[$feld] = $opt;
            if (isset($presets[$art], $tabelle[$presets[$art]])) {
                $w[$feld] = $tabelle[$presets[$art]];
            }
        }

        // Verbrauchsteile: Prozent direkt oder aus Restminuten / Lebensdauer (properties.availableConsumables.maxValue).
        $max = [];
        foreach ((array)($p['teileMax'] ?? []) as $m) {
            $max[(string)($m['type'] ?? '') . '/' . (string)($m['subType'] ?? 'none')] = (float)($m['maxValue'] ?? 0);
        }
        foreach ((array)($p['teile'] ?? []) as $t) {
            $schluessel = (string)($t['type'] ?? '') . '/' . (string)($t['subType'] ?? 'none');
            $ident = self::TEILE[$schluessel] ?? null;
            $rest = $t['remaining'] ?? null;
            if ($ident === null || !is_array($rest) || !is_numeric($rest['value'] ?? null) || isset($w[$ident])) {
                continue;
            }
            $v = (float)$rest['value'];
            if (($rest['unit'] ?? '') === 'percent') {
                $w[$ident] = (int)max(0, min(100, round($v)));
            } else {
                $w[$ident . 'Rest'] = (float)round(max(0, $v) / 60);
                if (($max[$schluessel] ?? 0) > 0) {
                    $w[$ident] = (int)max(0, min(100, round($v * 100 / $max[$schluessel])));
                }
            }
        }

        // Statistik (ValetudoDataPoint: time in s, area in cm², count).
        foreach (['gesamt' => ['GesamtDauer', 'GesamtFlaeche', 'GesamtAnzahl'], 'aktuell' => ['Dauer', 'Flaeche', null]] as $art => [$zeit, $flaeche, $anzahl]) {
            foreach ((array)($p[$art] ?? []) as $d) {
                $v = $d['value'] ?? null;
                if (!is_numeric($v)) {
                    continue;
                }
                $typ = (string)($d['type'] ?? '');
                if ($typ === 'time') {
                    $w[$zeit] = $art === 'gesamt' ? (int)round($v / 3600) : (int)round($v / 60);
                } elseif ($typ === 'area') {
                    $w[$flaeche] = (int)round($v / 10000);
                } elseif ($typ === 'count' && $anzahl !== null) {
                    $w[$anzahl] = (int)$v;
                }
            }
        }
        return ['werte' => $w, 'optionen' => $optionen, 'schreibbar' => []];
    }

    /** StatusStateAttribute (+ Akku/Station) → RoboHubModell::ZUSTAND_*. */
    private static function zustandAus(string $wert, string $flag, string $flagAkku, ?int $akku, string $dock): int
    {
        switch ($wert) {
            case 'error':
                return RoboHubModell::ZUSTAND_FEHLER;
            case 'cleaning':
                return $flag === 'mapping' ? RoboHubModell::ZUSTAND_KARTIERUNG : RoboHubModell::ZUSTAND_REINIGT;
            case 'manual_control':
            case 'moving':
                return RoboHubModell::ZUSTAND_REINIGT;
            case 'paused':
                return RoboHubModell::ZUSTAND_PAUSIERT;
            case 'returning':
                return RoboHubModell::ZUSTAND_ZURUECK;
            case 'idle':
                return RoboHubModell::ZUSTAND_BEREIT;
            case 'docked':
                if (in_array($dock, ['emptying', 'cleaning', 'drying'], true)) {
                    return RoboHubModell::ZUSTAND_STATIONSARBEIT;
                }
                if ($flagAkku === 'charging') {
                    return RoboHubModell::ZUSTAND_LAEDT;
                }
                return ($flagAkku === 'charged' || ($akku ?? 0) >= 100) ? RoboHubModell::ZUSTAND_GELADEN : RoboHubModell::ZUSTAND_LAEDT;
        }
        return RoboHubModell::ZUSTAND_UNBEKANNT;
    }

    // =================================================================
    // Karte
    // =================================================================

    public function karte(array $buendel, array $geraet): array
    {
        $r = $this->roboter($buendel, $geraet);
        $roh = self::kartenRoh((array)$this->hole($r, '/api/v2/robot/state/map'));
        if ($roh === null) {
            return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
        }
        $bild = RoboHubKartenbild::sauger($roh, $roh['namen'], false);
        unset($bild['kodierung']);
        $this->dbg('Karte', $roh['breite'] . '×' . $roh['hoehe'] . ' Punkte, Räume ' . json_encode($roh['namen'], JSON_UNESCAPED_UNICODE) . '.');
        return ['raeume' => $roh['namen']] + $bild;
    }

    public function liveKarte(array $buendel, array $geraet): array
    {
        $r = $this->roboter($buendel, $geraet);
        $roh = self::kartenRoh((array)$this->hole($r, '/api/v2/robot/state/map'));
        if ($roh === null) {
            return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
        }
        $raeume = [];
        foreach ((array)($geraet['raeume'] ?? []) as $id => $name) {
            $raeume[(int)$id] = (string)$name;
        }
        $bild = RoboHubKartenbild::sauger($roh, $raeume + $roh['namen'], true);
        unset($bild['kodierung']);
        return ['raeume' => []] + $bild;
    }

    /**
     * ValetudoMap → Rohkarte für RoboHubKartenbild::sauger() (Kodierung „rahmen", Zeile 0 = unten).
     * Valetudo-y zeigt nach unten; sauger() spiegelt — deshalb y_oben = (maxY · pixelSize − y) mm.
     * Räume mit Segment-ID 1…60 bekommen ihre Farbe, andere bleiben Boden.
     */
    public static function kartenRoh(array $karte): ?array
    {
        $ps = (int)($karte['pixelSize'] ?? 0);
        $ebenen = [];
        $minX = $minY = PHP_INT_MAX;
        $maxX = $maxY = PHP_INT_MIN;
        foreach ((array)($karte['layers'] ?? []) as $l) {
            $punkte = self::punkte($l);
            if ($punkte === []) {
                continue;
            }
            foreach ($punkte as [$x, $y]) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
            $ebenen[] = [(string)($l['type'] ?? ''), (array)($l['metaData'] ?? []), $punkte];
        }
        if ($ps <= 0 || $ebenen === []) {
            return null;
        }
        $b = $maxX - $minX + 1;
        $h = $maxY - $minY + 1;
        if ($b * $h > 4000000) {
            return null;
        }
        $pixel = str_repeat("\0", $b * $h);
        $namen = [];
        // Reihenfolge: Boden, Räume, Wände (Wände überdecken).
        $rang = ['floor' => 0, 'segment' => 1, 'wall' => 2];
        usort($ebenen, function ($a, $c) use ($rang) { return ($rang[$a[0]] ?? 0) <=> ($rang[$c[0]] ?? 0); });
        foreach ($ebenen as [$typ, $meta, $punkte]) {
            if ($typ === 'wall') {
                $code = chr(63 << 2);
            } elseif ($typ === 'segment' && ctype_digit((string)($meta['segmentId'] ?? '')) && (int)$meta['segmentId'] >= 1 && (int)$meta['segmentId'] <= 60) {
                $seg = (int)$meta['segmentId'];
                $code = chr($seg << 2);
                $name = trim((string)($meta['name'] ?? ''));
                $namen[$seg] = $name !== '' ? $name : 'Room ' . $seg;
            } else {
                $code = chr(62 << 2);
            }
            foreach ($punkte as [$x, $y]) {
                $pixel[($maxY - $y) * $b + ($x - $minX)] = $code;
            }
        }
        ksort($namen);
        $mm = function ($x, $y) use ($maxY, $ps): array {
            return [(int)round((float)$x * 10), (int)round(((float)$maxY * $ps - (float)$y) * 10)];
        };
        $roh = ['breite' => $b, 'hoehe' => $h, 'pixel' => $pixel, 'json' => ['fsm' => 1], 'raster' => $ps * 10,
            'links' => $minX * $ps * 10, 'oben' => 0, 'station' => null, 'roboter' => null, 'weg' => [], 'namen' => $namen];
        foreach ((array)($karte['entities'] ?? []) as $e) {
            $pt = (array)($e['points'] ?? []);
            $typ = (string)($e['type'] ?? '');
            if ($typ === 'charger_location' && count($pt) >= 2) {
                $roh['station'] = $mm($pt[0], $pt[1]) + [2 => 0];
            } elseif ($typ === 'robot_position' && count($pt) >= 2) {
                // Valetudo: 0° = Norden, im Uhrzeigersinn → RoboHub: 0° = rechts, gegen den Uhrzeigersinn.
                $winkel = 90 - (int)round((float)($e['metaData']['angle'] ?? 0));
                $roh['roboter'] = $mm($pt[0], $pt[1]) + [2 => (($winkel % 360) + 360) % 360];
            } elseif ($typ === 'path' && count($pt) >= 4) {
                $stueck = [];
                for ($i = 0; $i + 1 < count($pt); $i += 2) {
                    $stueck[] = $mm($pt[$i], $pt[$i + 1]);
                }
                $roh['weg'][] = $stueck;
            }
        }
        return $roh;
    }

    /** Bildpunkte einer Ebene: compressedPixels [xStart, y, Anzahl, …] (MapLayer.DECOMPRESS_PIXELS) oder pixels [x, y, …]. */
    private static function punkte(array $l): array
    {
        $aus = [];
        $c = (array)($l['compressedPixels'] ?? []);
        for ($i = 0; $i + 2 < count($c); $i += 3) {
            for ($j = 0; $j < (int)$c[$i + 2]; $j++) {
                $aus[] = [(int)$c[$i] + $j, (int)$c[$i + 1]];
            }
        }
        $p = (array)($l['pixels'] ?? []);
        for ($i = 0; $i + 1 < count($p); $i += 2) {
            $aus[] = [(int)$p[$i], (int)$p[$i + 1]];
        }
        return $aus;
    }

    // =================================================================
    // Befehle
    // =================================================================

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        $r = $this->roboter($buendel, $geraet);
        $grund = ['start' => ['start', 'Start'], 'stopp' => ['stop', 'Stop'], 'pause' => ['pause', 'Pause'], 'station' => ['home', 'Return to base']];
        if (isset($grund[$befehl])) {
            $this->sende($r, '/api/v2/robot/capabilities/BasicControlCapability', ['action' => $grund[$befehl][0]]);
            return RoboHubText::t('Command "%s" sent.', RoboHubText::wert($grund[$befehl][1]));
        }
        if ($befehl === 'orten') {
            $this->sende($r, '/api/v2/robot/capabilities/LocateCapability', ['action' => 'locate']);
            return RoboHubText::t('Command "%s" sent.', RoboHubText::wert('Locate'));
        }
        if ($befehl === 'setze') {
            $this->presetSetzen($r, (string)($parameter['feld'] ?? ''), (int)($parameter['wert'] ?? -1));
            return RoboHubText::t('Setting sent.');
        }
        if ($befehl === 'raum') {
            $raeume = array_values(array_unique(array_map('intval', (array)($parameter['raeume'] ?? []))));
            if ($raeume === []) {
                throw new RoboHubFehler(RoboHubText::t('No room given.'));
            }
            // Wie das App-Startfenster: Modus und Saugstufe vorher setzen, Durchgänge im Startbefehl.
            if ((int)($parameter['modus'] ?? -1) >= 0) {
                $this->presetSetzen($r, 'Reinigungsmodus', (int)$parameter['modus']);
            }
            if ((int)($parameter['saugstufe'] ?? -1) >= 0) {
                $this->presetSetzen($r, 'Saugstufe', (int)$parameter['saugstufe']);
            }
            $durch = (int)($parameter['durchgaenge'] ?? -1);
            $this->sende($r, '/api/v2/robot/capabilities/MapSegmentationCapability', [
                'action' => 'start_segment_action', 'segment_ids' => array_map('strval', $raeume),
                'iterations' => $durch >= 1 ? min(3, $durch) : 1, 'customOrder' => true,
            ]);
            return RoboHubText::t('Room cleaning for %s room(s) sent.', count($raeume));
        }
        throw new RoboHubFehler(RoboHubText::t('RoboHub does not know this command for Valetudo (yet): %s', $befehl));
    }

    /** Saugstufe/Wassermenge/Reinigungsmodus: Zahl → Preset-Name → PUT …/preset {"name"}. */
    private function presetSetzen(array $r, string $feld, int $wert): void
    {
        foreach (self::PRESETS as $art => [$f, $cap]) {
            if ($f !== $feld) {
                continue;
            }
            $name = array_search($wert, $art === 'operation_mode' ? self::MODI : self::STUFEN, true);
            if ($name === false) {
                throw new RoboHubFehler(RoboHubText::t('Invalid value for %s: %s', RoboHubText::wert(RoboHubModell::FELDER[$feld][0]), $wert));
            }
            $this->sende($r, '/api/v2/robot/capabilities/' . $cap . '/preset', ['name' => $name]);
            return;
        }
        throw new RoboHubFehler(RoboHubText::t('This setting cannot be changed on Valetudo: %s', $feld));
    }

    public function zuruecksetzen(array $buendel, array $geraet, string $teil): string
    {
        $r = $this->roboter($buendel, $geraet);
        foreach ((array)$this->hole($r, '/api/v2/robot/capabilities/ConsumableMonitoringCapability') as $t) {
            $typ = (string)($t['type'] ?? '');
            $unter = (string)($t['subType'] ?? 'none');
            if ((self::TEILE[$typ . '/' . $unter] ?? null) === $teil) {
                $this->sende($r, '/api/v2/robot/capabilities/ConsumableMonitoringCapability/' . rawurlencode($typ) . ($unter !== 'none' ? '/' . rawurlencode($unter) : ''), ['action' => 'reset']);
                return RoboHubText::t('Reset sent.');
            }
        }
        throw new RoboHubFehler(RoboHubText::t('This part cannot be reset on Valetudo: %s', $teil));
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

    /** GET → JSON. Nicht erreichbar = Roboter offline (keine Kontowarnung). */
    private function hole(array $r, string $pfad)
    {
        [$status, $text] = $this->http('GET', 'http://' . $r['host'] . $pfad, $this->koepfe($r), null);
        $this->pruefe($status, $text, $pfad, $r);
        $j = json_decode($text, true);
        if ($j === null && trim($text) !== 'null') {
            throw new RoboHubFehler(RoboHubText::t('Valetudo sent an unreadable answer (%s).', $pfad));
        }
        return $j;
    }

    private function sende(array $r, string $pfad, array $inhalt): void
    {
        [$status, $text] = $this->http('PUT', 'http://' . $r['host'] . $pfad, array_merge($this->koepfe($r), ['Content-Type: application/json']), (string)json_encode($inhalt));
        $this->pruefe($status, $text, $pfad, $r);
    }

    private function koepfe(array $r): array
    {
        $k = ['Accept: application/json'];
        if ((string)($r['benutzer'] ?? '') !== '' || (string)($r['passwort'] ?? '') !== '') {
            $k[] = 'Authorization: Basic ' . base64_encode((string)$r['benutzer'] . ':' . (string)$r['passwort']);
        }
        return $k;
    }

    private function pruefe(int $status, string $text, string $pfad, array $r): void
    {
        $this->dbg('HTTP', $pfad . ' → ' . $status);
        if ($status === 0) {
            throw RoboHubFehler::offline(RoboHubText::t('Valetudo robot at %s not reachable: %s', $r['host'], $text));
        }
        if ($status === 401) {
            $e = new RoboHubFehler(RoboHubText::t('Valetudo requires a user name and password (access protection is switched on) — enter both in the account and pair again.'));
            $e->anmeldungNoetig = true;
            throw $e;
        }
        if ($status >= 400) {
            throw new RoboHubFehler(RoboHubText::t('Valetudo rejects the request (HTTP %s): %s', $status, mb_substr(trim(strip_tags($text)), 0, 120)));
        }
    }

    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $methode,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
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
