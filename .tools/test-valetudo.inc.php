<?php
/**
 * Valetudo-Teil des Prüfstands (von test-robohub.php eingebunden).
 * Antworten nach Valetudos OpenAPI-Beschreibung und Datenklassen (Hypfer/Valetudo, Apache-2.0).
 */

declare(strict_types=1);

/** Nachgebauter Valetudo-Roboter im Speicher: ersetzt nur http(). */
class FakeValetudo extends RoboHubValetudo
{
    public static $anfragen = [];       // [methode, url, koepfe, inhalt]
    public static $erreichbar = true;
    public static $schutz = null;       // null = kein Zugangsschutz, sonst [benutzer, passwort]
    public static $antworten = [];      // Pfad => Antwort (Array)
    public static $status = [];         // Pfad => HTTP-Status (für PUT)

    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        self::$anfragen[] = [$methode, $url, $headers, $body];
        if (!self::$erreichbar) {
            return [0, 'Failed to connect'];
        }
        if (self::$schutz !== null && !in_array('Authorization: Basic ' . base64_encode(self::$schutz[0] . ':' . self::$schutz[1]), $headers, true)) {
            return [401, 'Unauthorized'];
        }
        $pfad = (string)parse_url($url, PHP_URL_PATH);
        if ($methode === 'PUT') {
            return [self::$status[$pfad] ?? 200, 'OK'];
        }
        if (!array_key_exists($pfad, self::$antworten)) {
            return [404, 'Not Found'];
        }
        return [200, (string)json_encode(self::$antworten[$pfad])];
    }

    public static function letztePut(): ?array
    {
        for ($i = count(self::$anfragen) - 1; $i >= 0; $i--) {
            if (self::$anfragen[$i][0] === 'PUT') {
                return [(string)parse_url(self::$anfragen[$i][1], PHP_URL_PATH), json_decode((string)self::$anfragen[$i][3], true)];
            }
        }
        return null;
    }
}

/** Kleine ValetudoMap: 5 cm je Punkt, Boden 10×6 ab (100, 200), Raum 16 „Küche" links, Raum 17 ohne Namen rechts, Wandzeile oben. */
function valetudoTestKarte(): array
{
    $rle = function (int $x0, int $y0, int $breite, int $hoehe): array {
        $aus = [];
        for ($y = $y0; $y < $y0 + $hoehe; $y++) {
            array_push($aus, $x0, $y, $breite);
        }
        return $aus;
    };
    return [
        '__class' => 'ValetudoMap', 'size' => ['x' => 5120, 'y' => 5120], 'pixelSize' => 5,
        'layers' => [
            ['__class' => 'MapLayer', 'type' => 'wall', 'pixels' => [], 'compressedPixels' => $rle(100, 200, 10, 1), 'metaData' => []],
            ['__class' => 'MapLayer', 'type' => 'segment', 'pixels' => [], 'compressedPixels' => $rle(100, 201, 5, 5), 'metaData' => ['segmentId' => '16', 'name' => 'Küche', 'active' => false]],
            ['__class' => 'MapLayer', 'type' => 'segment', 'pixels' => [], 'compressedPixels' => $rle(105, 201, 5, 5), 'metaData' => ['segmentId' => '17', 'active' => false]],
            ['__class' => 'MapLayer', 'type' => 'floor', 'pixels' => [], 'compressedPixels' => $rle(100, 200, 10, 6), 'metaData' => []],
        ],
        'entities' => [
            ['__class' => 'PointMapEntity', 'type' => 'charger_location', 'points' => [512, 1027], 'metaData' => []],
            ['__class' => 'PointMapEntity', 'type' => 'robot_position', 'points' => [537, 1017], 'metaData' => ['angle' => 90]],
            ['__class' => 'PathMapEntity', 'type' => 'path', 'points' => [512, 1027, 525, 1015, 537, 1017], 'metaData' => []],
        ],
        'metaData' => ['version' => 2],
    ];
}

function valetudoGrundAntworten(): array
{
    $c = '/api/v2/robot/capabilities/';
    return [
        '/api/v2/valetudo' => ['embedded' => true, 'systemId' => 'SauberesKlugesSchaf', 'welcomeDialogDismissed' => true],
        '/api/v2/robot' => ['manufacturer' => 'Dreame', 'modelName' => 'L10S Ultra', 'implementation' => 'DreameL10SUltraValetudoRobot'],
        '/api/v2/robot/capabilities' => ['BasicControlCapability', 'FanSpeedControlCapability', 'WaterUsageControlCapability', 'OperationModeControlCapability',
            'ConsumableMonitoringCapability', 'TotalStatisticsCapability', 'CurrentStatisticsCapability', 'LocateCapability', 'MapSegmentationCapability'],
        '/api/v2/robot/state/attributes' => [
            ['__class' => 'StatusStateAttribute', 'value' => 'docked', 'flag' => 'none', 'metaData' => []],
            ['__class' => 'BatteryStateAttribute', 'level' => 83, 'flag' => 'charging', 'metaData' => []],
            ['__class' => 'PresetSelectionStateAttribute', 'type' => 'fan_speed', 'value' => 'high', 'metaData' => []],
            ['__class' => 'PresetSelectionStateAttribute', 'type' => 'water_grade', 'value' => 'low', 'metaData' => []],
            ['__class' => 'PresetSelectionStateAttribute', 'type' => 'operation_mode', 'value' => 'vacuum_and_mop', 'metaData' => []],
            ['__class' => 'DockStatusStateAttribute', 'value' => 'idle', 'metaData' => []],
        ],
        $c . 'FanSpeedControlCapability/presets' => ['low', 'medium', 'high', 'max'],
        $c . 'WaterUsageControlCapability/presets' => ['low', 'medium', 'high'],
        $c . 'OperationModeControlCapability/presets' => ['vacuum', 'mop', 'vacuum_and_mop'],
        $c . 'ConsumableMonitoringCapability' => [
            ['__class' => 'ValetudoConsumable', 'type' => 'brush', 'subType' => 'main', 'remaining' => ['value' => 9000, 'unit' => 'minutes']],
            ['__class' => 'ValetudoConsumable', 'type' => 'brush', 'subType' => 'side_right', 'remaining' => ['value' => 600, 'unit' => 'minutes']],
            ['__class' => 'ValetudoConsumable', 'type' => 'filter', 'subType' => 'main', 'remaining' => ['value' => 42, 'unit' => 'percent']],
            ['__class' => 'ValetudoConsumable', 'type' => 'bin', 'subType' => 'dock', 'remaining' => ['value' => 10, 'unit' => 'percent']],
        ],
        $c . 'ConsumableMonitoringCapability/properties' => ['availableConsumables' => [
            ['type' => 'brush', 'subType' => 'main', 'unit' => 'minutes', 'maxValue' => 18000],
            ['type' => 'brush', 'subType' => 'side_right', 'unit' => 'minutes', 'maxValue' => 12000],
            ['type' => 'filter', 'subType' => 'main', 'unit' => 'percent'],
        ]],
        $c . 'TotalStatisticsCapability' => [['type' => 'time', 'value' => 360000], ['type' => 'area', 'value' => 12500000], ['type' => 'count', 'value' => 42]],
        $c . 'CurrentStatisticsCapability' => [['type' => 'time', 'value' => 900], ['type' => 'area', 'value' => 150000]],
        '/api/v2/robot/state/map' => valetudoTestKarte(),
    ];
}

// ===========================================================================
abschnitt('Valetudo: Adresse, Karte');
check('Adresse: IP, IP:Port, Hostname, http:// wird entfernt', RoboHubValetudo::host('192.168.1.50') === '192.168.1.50' && RoboHubValetudo::host('192.168.1.50:8080') === '192.168.1.50:8080'
    && RoboHubValetudo::host('http://roboter.fritz.box/') === 'roboter.fritz.box');
$vFalsch = false;
try {
    RoboHubValetudo::host('192.168.1.50/../x y');
} catch (RoboHubFehler $e) {
    $vFalsch = true;
}
check('Adresse: Unsinn abgelehnt', $vFalsch);
$vk = RoboHubValetudo::kartenRoh(valetudoTestKarte());
check('Karte: 10×6 Punkte, 50 mm je Punkt, Räume 16 „Küche" und 17 „Room 17"', is_array($vk) && $vk['breite'] === 10 && $vk['hoehe'] === 6 && $vk['raster'] === 50
    && $vk['namen'] === [16 => 'Küche', 17 => 'Room 17'], json_encode($vk === null ? null : array_diff_key($vk, ['pixel' => 1]), JSON_UNESCAPED_UNICODE));
// Zeile 0 der Rohkarte = unten (Valetudo y = 205), oberste Zeile (y = 200) = Wand.
check('Karte: unten Räume (16 links, 17 rechts), oben Wand — gespiegelt wie sauger() es erwartet',
    substr($vk['pixel'], 0, 10) === str_repeat(chr(16 << 2), 5) . str_repeat(chr(17 << 2), 5) && substr($vk['pixel'], 50, 10) === str_repeat(chr(63 << 2), 10));
check('Station und Roboter in mm (y nach oben gespiegelt), Winkel 90° Ost → RoboHub 0°', $vk['station'] === [5120, -20, 0] && $vk['roboter'] === [5370, 80, 0]
    && count($vk['weg'][0] ?? []) === 3, json_encode([$vk['station'], $vk['roboter'], $vk['weg']]));
$vBild = RoboHubKartenbild::sauger($vk, $vk['namen'], true);
check('Kartenbild: PNG, Namen, Station, Roboter mit Blickrichtung', strpos($vBild['bild'], 'data:image/png;base64,') === 0 && array_column($vBild['marken'], 'name') === ['Küche', 'Room 17']
    && $vBild['station'] !== null && ($vBild['roboter']['winkel'] ?? null) === 0 && count($vBild['weg'] ?? []) === 1, json_encode(array_diff_key($vBild, ['bild' => 1]), JSON_UNESCAPED_UNICODE));
check('leere Karte → null', RoboHubValetudo::kartenRoh(['pixelSize' => 5, 'layers' => []]) === null && RoboHubValetudo::kartenRoh([]) === null);

// ===========================================================================
abschnitt('Valetudo: Koppeln, Zustand, Befehle');
$fabrikVt = $GLOBALS['robohubTreiberFabrik'];
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) use ($fabrikVt) {
    return $marke === 'valetudo' ? new FakeValetudo() : $fabrikVt($marke);
};
FakeValetudo::$antworten = valetudoGrundAntworten();
$vtk = new RoboHubKonto(9800);
$vtk->props['Hersteller'] = 'valetudo';
$vtk->Create();
$vtk->ApplyChanges();
$vtSichtbar = [];
$vtSuche = function ($k) use (&$vtSuche, &$vtSichtbar) {
    if (!is_array($k)) {
        return;
    }
    if (isset($k['caption']) && is_string($k['caption']) && ($k['visible'] ?? true) !== false) {
        $vtSichtbar[] = $k['caption'] . (isset($k['name']) ? ' [' . $k['name'] . ']' : '');
    }
    foreach ($k as $w) {
        $vtSuche($w);
    }
};
$vtSuche(json_decode(konsole($vtk->GetConfigurationForm()), true));
$vtText = implode("\n", $vtSichtbar);
check('Formular: Valetudo-Anleitung, IP-Feld, Benutzer/Passwort nur mit Zugangsschutz erklärt, keine iRobot-/Narwal-Anleitung',
    strpos($vtText, 'Valetudo läuft lokal, ohne Cloud') !== false && strpos($vtText, 'IP-Adresse des Roboters [Konto]') !== false
    && strpos($vtText, 'Benutzername (nur bei Zugangsschutz) [ClientId]') !== false && strpos($vtText, '[Passwort]') !== false
    && strpos($vtText, 'HOME-Taste') === false && strpos($vtText, 'Narwal läuft lokal') === false, $vtText);

FakeValetudo::$erreichbar = false;
$vtk->props['Konto'] = '192.168.1.77';
$t = $vtk->Anmelden();
check('nicht erreichbar: verständlicher Fehler', strpos($t, 'nicht erreichbar') !== false, $t);
FakeValetudo::$erreichbar = true;
FakeValetudo::$schutz = ['robo', 'geheim'];
$t = $vtk->Anmelden();
check('Zugangsschutz an, kein Passwort: Hinweis auf Benutzer/Passwort', strpos($t, 'Benutzername und Passwort') !== false, $t);
$vtk->props['ClientId'] = 'robo';
$vtk->props['Passwort'] = 'geheim';
$t = $vtk->Anmelden();
$vtb = json_decode($vtk->ReadAttributeString('Buendel'), true);
$vtg = json_decode($vtk->ReadAttributeString('Geraete'), true);
check('gekoppelt: System-ID, Hersteller/Modell, Zugang im Bündel, Passwort aus der Eigenschaft genommen',
    strpos($t, 'Gekoppelt') !== false && ($vtb['roboter']['SauberesKlugesSchaf'] ?? null) === ['host' => '192.168.1.77', 'benutzer' => 'robo', 'passwort' => 'geheim',
        'id' => 'SauberesKlugesSchaf', 'hersteller' => 'Dreame', 'modell' => 'L10S Ultra']
    && $vtk->props['Passwort'] === '' && count($vtg) === 1 && $vtg[0]['name'] === 'Dreame L10S Ultra (Valetudo)' && $vtg[0]['modell'] === 'valetudo.vacuum.dreame_l10s_ultra', $t);
check('Passwort nie im Debug', strpos(json_encode($vtk->debug ?? []), 'geheim') === false);

$vtr = new RoboHubGeraet(9850);
$vtr->props = ['GeraetID' => 'SauberesKlugesSchaf', 'Modell' => 'valetudo.vacuum.dreame_l10s_ultra', 'Klasse' => 'sauger', 'Intervall' => 60];
$vtr->Create();
$vtr->parent = $vtk;
$vtr->ApplyChanges();
FakeValetudo::$anfragen = [];
$t = $vtr->Aktualisieren();
check('jede Anfrage mit Basic-Auth', FakeValetudo::$anfragen !== [] && count(array_filter(FakeValetudo::$anfragen, function ($a) {
    return in_array('Authorization: Basic ' . base64_encode('robo:geheim'), $a[2], true);
})) === count(FakeValetudo::$anfragen), $t);
check('in der Station, lädt 83 %', variable(9850, 'Zustand')['value'] === RoboHubModell::ZUSTAND_LAEDT && variable(9850, 'Akku')['value'] === 83
    && variable(9850, 'Laedt')['value'] === true && variable(9850, 'Fehler')['value'] === 'kein Fehler' && variable(9850, 'ZustandRoh')['value'] === 'docked');
$vso = array_column(json_decode((string)variable(9850, 'Saugstufe')['pres']['OPTIONS'], true), 'Caption', 'Value');
check('Saugstufe „high" = 4, Auswahl nur die Stufen des Roboters, schaltbar', variable(9850, 'Saugstufe')['value'] === 4 && array_keys($vso) === [2, 3, 4, 5]
    && variable(9850, 'Saugstufe')['action'] === true, json_encode($vso, JSON_UNESCAPED_UNICODE));
check('Wassermenge „low" = 2, Modus „Saugen und Wischen" = 2', variable(9850, 'Wassermenge')['value'] === 2 && variable(9850, 'Reinigungsmodus')['value'] === 2);
check('Verbrauchsteile: Hauptbürste 50 % / 150 h (aus maxValue), Seitenbürste 5 % / 10 h, Filter 42 %, Absaugbeutel übergangen',
    variable(9850, 'Hauptbuerste')['value'] === 50 && variable(9850, 'HauptbuersteRest')['value'] === 150.0 && variable(9850, 'Seitenbuerste')['value'] === 5
    && variable(9850, 'SeitenbuersteRest')['value'] === 10.0 && variable(9850, 'Filter')['value'] === 42);
check('Statistik: 100 h, 1250 m², 42 Reinigungen; laufend 15 min, 15 m²', variable(9850, 'GesamtDauer')['value'] === 100 && variable(9850, 'GesamtFlaeche')['value'] === 1250
    && variable(9850, 'GesamtAnzahl')['value'] === 42 && variable(9850, 'Dauer')['value'] === 15 && variable(9850, 'Flaeche')['value'] === 15);
check('Station „Bereit"', variable(9850, 'Station')['value'] === 'Bereit');
check('Räume aus der Karte: 16 „Küche", 17 „Raum 17"', array_column(json_decode((string)variable(9850, 'Raum')['pres']['OPTIONS'], true), 'Caption', 'Value') === [16 => 'Küche', 17 => 'Raum 17']);

$vZustand = function (array $attribute) use ($vtr): int {
    FakeValetudo::$antworten['/api/v2/robot/state/attributes'] = $attribute;
    $vtr->Aktualisieren();
    return variable(9850, 'Zustand')['value'];
};
$st = function (string $wert, string $flag = 'none', array $mehr = []): array {
    return array_merge([['__class' => 'StatusStateAttribute', 'value' => $wert, 'flag' => $flag] + ($wert === 'error' ? ['error' => ['message' => 'Hauptbürste blockiert']] : [])], $mehr);
};
check('reinigt / kartiert / pausiert / fährt zurück / bereit', $vZustand($st('cleaning', 'segment')) === RoboHubModell::ZUSTAND_REINIGT
    && $vZustand($st('cleaning', 'mapping')) === RoboHubModell::ZUSTAND_KARTIERUNG && $vZustand($st('paused')) === RoboHubModell::ZUSTAND_PAUSIERT
    && $vZustand($st('returning')) === RoboHubModell::ZUSTAND_ZURUECK && $vZustand($st('idle')) === RoboHubModell::ZUSTAND_BEREIT);
check('Fehler mit Text aus Valetudo', $vZustand($st('error')) === RoboHubModell::ZUSTAND_FEHLER && variable(9850, 'Fehler')['value'] === 'Hauptbürste blockiert');
check('Station voll geladen / Station saugt ab', $vZustand($st('docked', 'none', [['__class' => 'BatteryStateAttribute', 'level' => 100, 'flag' => 'charged']])) === RoboHubModell::ZUSTAND_GELADEN
    && $vZustand($st('docked', 'none', [['__class' => 'DockStatusStateAttribute', 'value' => 'emptying']])) === RoboHubModell::ZUSTAND_STATIONSARBEIT);
FakeValetudo::$antworten = valetudoGrundAntworten();

FakeValetudo::$anfragen = [];
$vtr->Starten();
check('Start = BasicControl {"action":"start"}', FakeValetudo::letztePut() === ['/api/v2/robot/capabilities/BasicControlCapability', ['action' => 'start']]);
$vtr->ZurStation();
check('Zur Station = {"action":"home"}', FakeValetudo::letztePut() === ['/api/v2/robot/capabilities/BasicControlCapability', ['action' => 'home']]);
$vtr->RequestAction('Befehl', RoboHubModell::BEFEHL_ORTEN);
check('Orten = LocateCapability {"action":"locate"}', FakeValetudo::letztePut() === ['/api/v2/robot/capabilities/LocateCapability', ['action' => 'locate']]);
$vtr->RequestAction('Saugstufe', 5);
check('Saugstufe 5 = Preset „max"', FakeValetudo::letztePut() === ['/api/v2/robot/capabilities/FanSpeedControlCapability/preset', ['name' => 'max']] && variable(9850, 'Saugstufe')['value'] === 5);
FakeValetudo::$anfragen = [];
$vtr->RaeumeReinigenMit('17,16', 1, -1, 2);
$puts = array_values(array_filter(FakeValetudo::$anfragen, function ($a) { return $a[0] === 'PUT'; }));
check('Räume mit Wahl: erst Modus „mop", dann Segmente ["17","16"] in dieser Reihenfolge, 2 Durchgänge', count($puts) === 2
    && json_decode((string)$puts[0][3], true) === ['name' => 'mop'] && strpos($puts[0][1], 'OperationModeControlCapability/preset') !== false
    && json_decode((string)$puts[1][3], true) === ['action' => 'start_segment_action', 'segment_ids' => ['17', '16'], 'iterations' => 2, 'customOrder' => true], json_encode($puts));
$t = $vtr->VerbrauchsteilZuruecksetzen('Seitenbuerste');
check('Seitenbürste zurücksetzen = PUT …/brush/side_right {"action":"reset"}', FakeValetudo::letztePut() === ['/api/v2/robot/capabilities/ConsumableMonitoringCapability/brush/side_right', ['action' => 'reset']], $t);
FakeValetudo::$status['/api/v2/robot/capabilities/BasicControlCapability'] = 500;
$t = $vtr->Starten();
check('Ablehnung durch Valetudo wird gemeldet', strpos($t, '⛔') === 0 && strpos($t, 'HTTP 500') !== false, $t);
FakeValetudo::$status = [];
FakeValetudo::$erreichbar = false;
$vtr->Aktualisieren();
check('Roboter aus: „nicht erreichbar", Konto bleibt angemeldet', variable(9850, 'Erreichbar')['value'] === false && $vtk->ReadAttributeString('Buendel') !== '');
FakeValetudo::$erreichbar = true;

// ---- Station (0.52.0, Forum tobitobsen 144636/171) ----
$befehlWerte = function () { return array_column(json_decode((string)variable(9850, 'Befehl')['pres']['OPTIONS'], true), 'Value'); };
$vtr->Aktualisieren();
check('ohne Stations-Fähigkeiten: nur die Grundbefehle (keine Stationsknöpfe)', $befehlWerte() === [1, 2, 3, 4, 5], json_encode($befehlWerte()));
$c = '/api/v2/robot/capabilities/';
FakeValetudo::$antworten['/api/v2/robot/capabilities'] = array_merge(FakeValetudo::$antworten['/api/v2/robot/capabilities'], [
    'AutoEmptyDockManualTriggerCapability', 'MopDockCleanManualTriggerCapability', 'MopDockDryManualTriggerCapability',
    'AutoEmptyDockAutoEmptyIntervalControlCapability', 'MopDockMopDryingTimeControlCapability', 'MopDockMopAutoDryingControlCapability']);
FakeValetudo::$antworten[$c . 'AutoEmptyDockAutoEmptyIntervalControlCapability'] = ['interval' => 'normal'];
FakeValetudo::$antworten[$c . 'AutoEmptyDockAutoEmptyIntervalControlCapability/properties'] = ['supportedIntervals' => ['off', 'normal', 'frequent']];
FakeValetudo::$antworten[$c . 'MopDockMopDryingTimeControlCapability'] = ['duration' => '3h'];
FakeValetudo::$antworten[$c . 'MopDockMopDryingTimeControlCapability/properties'] = ['supportedDurations' => ['2h', '3h', '4h']];
FakeValetudo::$antworten[$c . 'MopDockMopAutoDryingControlCapability'] = ['enabled' => true];
$vtr->Aktualisieren();
check('mit Stations-Fähigkeiten: Absaugen, Mopp waschen, Mopp trocknen, Stationsaufgabe beenden', $befehlWerte() === [1, 2, 3, 4, 5, 6, 7, 8, 9], json_encode($befehlWerte()));
$optWerte = function (string $ident) { return array_column(json_decode((string)variable(9850, $ident)['pres']['OPTIONS'], true), 'Caption', 'Value'); };
check('Absaug-Häufigkeit „normal" = 2, Auswahl nur die gemeldeten Werte', variable(9850, 'AbsaugIntervall')['value'] === 2 && array_keys($optWerte('AbsaugIntervall')) === [0, 2, 3], json_encode($optWerte('AbsaugIntervall')));
check('Trockendauer 3 h, Auswahl 2/3/4 h; automatisch trocknen an', variable(9850, 'Trockendauer')['value'] === 3 && array_keys($optWerte('Trockendauer')) === [2, 3, 4]
    && variable(9850, 'MoppTrocknenAuto')['value'] === 1 && variable(9850, 'Waschtemperatur') === null && variable(9850, 'AbsaugDauer') === null);
$vtr->RequestAction('Befehl', RoboHubModell::BEFEHL_ABSAUGEN);
check('Absaugen = PUT AutoEmptyDockManualTrigger {"action":"trigger"}', FakeValetudo::letztePut() === [$c . 'AutoEmptyDockManualTriggerCapability', ['action' => 'trigger']]);
$vtr->RequestAction('Befehl', RoboHubModell::BEFEHL_MOPP_WASCHEN);
check('Mopp waschen = MopDockCleanManualTrigger {"action":"start"}', FakeValetudo::letztePut() === [$c . 'MopDockCleanManualTriggerCapability', ['action' => 'start']]);
$vtr->RequestAction('Befehl', RoboHubModell::BEFEHL_MOPP_TROCKNEN);
check('Mopp trocknen = MopDockDryManualTrigger {"action":"start"}', FakeValetudo::letztePut() === [$c . 'MopDockDryManualTriggerCapability', ['action' => 'start']]);
$attrGrund = FakeValetudo::$antworten['/api/v2/robot/state/attributes'];
FakeValetudo::$antworten['/api/v2/robot/state/attributes'] = array_merge(array_slice($attrGrund, 0, 5), [['__class' => 'DockStatusStateAttribute', 'value' => 'drying']]);
$vtr->RequestAction('Befehl', RoboHubModell::BEFEHL_STATION_STOPP);
check('Stationsaufgabe beenden beim Trocknen = MopDockDryManualTrigger {"action":"stop"}', FakeValetudo::letztePut() === [$c . 'MopDockDryManualTriggerCapability', ['action' => 'stop']]);
FakeValetudo::$antworten['/api/v2/robot/state/attributes'] = array_merge(array_slice($attrGrund, 0, 5), [['__class' => 'DockStatusStateAttribute', 'value' => 'cleaning']]);
$vtr->RequestAction('Befehl', RoboHubModell::BEFEHL_STATION_STOPP);
check('… beim Waschen = MopDockCleanManualTrigger {"action":"stop"}', FakeValetudo::letztePut() === [$c . 'MopDockCleanManualTriggerCapability', ['action' => 'stop']]);
FakeValetudo::$antworten['/api/v2/robot/state/attributes'] = $attrGrund;
FakeValetudo::$anfragen = [];
$t = $vtr->StationsaufgabeBeenden();
check('… Station ruht: verständlicher Hinweis, nichts gesendet', FakeValetudo::letztePut() === null && strpos($t, 'wäscht oder trocknet gerade nicht') !== false, $t);
$vtr->RequestAction('Trockendauer', 4);
check('Trockendauer 4 = PUT {"duration":"4h"}', FakeValetudo::letztePut() === [$c . 'MopDockMopDryingTimeControlCapability', ['duration' => '4h']] && variable(9850, 'Trockendauer')['value'] === 4);
$vtr->RequestAction('AbsaugIntervall', 0);
check('Absaug-Häufigkeit aus = PUT {"interval":"off"}', FakeValetudo::letztePut() === [$c . 'AutoEmptyDockAutoEmptyIntervalControlCapability', ['interval' => 'off']]);
$vtr->RequestAction('MoppTrocknenAuto', 0);
check('automatisch trocknen aus = PUT {"action":"disable"}', FakeValetudo::letztePut() === [$c . 'MopDockMopAutoDryingControlCapability', ['action' => 'disable']]);
$vk = kachelJson($vtr->GetVisualizationTile());
check('Kachel: Stationsbefehle im Befehlsumfang, Stationseinstellungen unter „Einstellungen"', array_values(array_intersect([6, 7, 8, 9], (array)($vk['befehle'] ?? []))) === [6, 7, 8, 9]
    && count(array_intersect(['AbsaugIntervall', 'Trockendauer', 'MoppTrocknenAuto'], array_column((array)($vk['einstellungen'] ?? []), 'id'))) === 3, json_encode([$vk['befehle'] ?? null, array_column((array)($vk['einstellungen'] ?? []), 'id')]));
FakeValetudo::$antworten = valetudoGrundAntworten();

// ---- Fehlermeldungen an Visualisierungen (0.52.0) ----
if (!function_exists('VISU_PostNotificationEx')) {
    function VISU_PostNotificationEx(int $id, string $titel, string $text, string $icon, string $ton, int $ziel) { $GLOBALS['VISU_MELDUNGEN'][] = [$id, $titel, $text, $icon, $ton, $ziel]; return true; }
}
$GLOBALS['VISU_MELDUNGEN'] = [];
$visu = new FakeSocket(9890);
$visu->modul = '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}';
$vtr->Aktualisieren();
check('ohne Ziel: keine Meldung, auch nicht bei Fehler', ($vZustand($st('error')) === RoboHubModell::ZUSTAND_FEHLER) && $GLOBALS['VISU_MELDUNGEN'] === []);
$vZustand($st('idle'));
$vtr->props['Meldeziele'] = json_encode([['Ziel' => 9890]]);
$vZustand($st('error'));
check('neuer Fehler → eine Meldung an die Kachel-Visualisierung (Name + Fehlertext, Ton, Ziel = Roboter)', count($GLOBALS['VISU_MELDUNGEN']) === 1
    && $GLOBALS['VISU_MELDUNGEN'][0][0] === 9890 && strpos($GLOBALS['VISU_MELDUNGEN'][0][2], 'Hauptbürste blockiert') !== false
    && $GLOBALS['VISU_MELDUNGEN'][0][4] === 'alarm' && $GLOBALS['VISU_MELDUNGEN'][0][5] === 9850, json_encode($GLOBALS['VISU_MELDUNGEN']));
$vZustand($st('error'));
$vZustand($st('error'));
check('derselbe Fehler bei weiteren Abrufen: keine weitere Meldung', count($GLOBALS['VISU_MELDUNGEN']) === 1);
$vZustand($st('idle'));
$vZustand($st('error'));
check('Fehler behoben und neu aufgetreten → wieder eine Meldung', count($GLOBALS['VISU_MELDUNGEN']) === 2);
$t = $vtr->MeldungTesten();
check('Testmeldung: zugestellt, Status mit Zielnamen', count($GLOBALS['VISU_MELDUNGEN']) === 3 && strpos($t, '✅') === 0, $t);
$vtr->props['Meldeziele'] = json_encode([['Ziel' => 9850]]);
$t = $vtr->MeldungTesten();
check('Ziel ohne Visualisierung: gemeldet, nicht stillschweigend übergangen', strpos($t, '⛔') === 0 && strpos($t, 'keine Visualisierung') !== false, $t);
$vtr->props['Meldeziele'] = '[]';
FakeValetudo::$antworten = valetudoGrundAntworten();
$vtr->Aktualisieren();
FakeValetudo::$schutz = null;
$GLOBALS['robohubTreiberFabrik'] = $fabrikVt;
