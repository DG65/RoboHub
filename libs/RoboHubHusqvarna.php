<?php

declare(strict_types=1);

// ===========================================================================
// RoboHub-Treiber für Husqvarna Automower — offizielle Automower Connect API.
//
// Quelle: Thomas55555/aioautomower (MIT, Stand 12.10.2026): const.py
// (Adressen), auth.py (Köpfe), commands.py (Aktionen), model/* (Felder,
// Fehlercodes). Anmeldung „client_credentials" mit App-Schlüssel und
// App-Geheimnis aus dem Husqvarna-Entwicklerportal (openHAB/Logic Machine/
// aioautomower übereinstimmend).
//
// Zugangsdaten (Dietmars Entscheidung 13.10.2026): App-Schlüssel und
// App-Geheimnis bleiben im Bündel (Konto-Attribut), weil der Zugang nach
// 24 h nur damit erneuert werden kann. Der Nutzer legt sie selbst an und
// kann sie im Portal jederzeit widerrufen.
//
// Limit 10.000 Anfragen/Monat je App: Alle Mäher eines Kontos teilen sich
// einen Abruf von /mowers, der höchstens alle 5 Minuten neu geholt wird
// (≈ 8.700/Monat); nach einem Befehl wird er verworfen.
//
// Echtzeit (0.20.0): WebSocket wss://ws.openapi.husqvarna.dev/v1 mit Bearer-Kopf
// über Symcons WebSocket Client am Konto (aioautomower auth.py/session.py:
// Ereignisse *-event-v2, Lebenszeichen = leere Textnachricht). Ereignisse werden in
// den Zwischenspeicher eingearbeitet; solange die Verbindung steht, reicht ein
// Vollabruf je Stunde. Schnitthöhe und Scheinwerfer: POST mowers/{id}/settings.
// Noch nicht gebaut: Zeitpläne (Kalender), Messerzeit zurücksetzen.
// ===========================================================================

class RoboHubHusqvarna implements RoboHubTreiber
{
    /** @var callable|null fn(string $thema, string $text) */
    public $debug = null;

    /** Sekunden, die ein Abruf von /mowers für alle Mäher gilt (Prüfstand: 0). */
    public $zwischenspeicherSekunden = 300;

    /** Vom Konto gesetzt: Echtzeit-Verbindung steht → Vollabruf nur noch stündlich. */
    public $echtzeitVerbunden = false;

    private const WS = 'wss://ws.openapi.husqvarna.dev/v1';

    // Scheinwerfer (model_settings.py HeadlightModes).
    private const SCHEINWERFER = [0 => ['ALWAYS_ON', 'Always on'], 1 => ['ALWAYS_OFF', 'Always off'], 2 => ['EVENING_ONLY', 'Evening only'], 3 => ['EVENING_AND_NIGHT', 'Evening and night']];

    private const API = 'https://api.amc.husqvarna.dev/v1/';
    private const AUTH = 'https://api.authentication.husqvarnagroup.dev/v1/oauth2/token';
    // Dauer für „Start"/„In Bereich starten" (Minuten) — die API verlangt eine Dauer.
    private const START_MINUTEN = 180;

    // model_mower.py ERRORCODES (Auszug, deutsch).
    private const FEHLER = [
        1 => 'Outside the working area', 2 => 'No loop signal', 3 => 'Wrong loop signal',
        8 => 'Wrong PIN code', 9 => 'Trapped', 10 => 'Upside down', 11 => 'Battery low', 12 => 'Battery empty',
        13 => 'No drive', 14 => 'Mower lifted', 15 => 'Lifted', 16 => 'Stuck in the charging station',
        17 => 'Charging station blocked', 20 => 'Right wheel motor blocked', 21 => 'Left wheel motor blocked',
        24 => 'Cutting system blocked', 25 => 'Cutting system blocked', 29 => 'Too steep', 30 => 'Problem with the charging system',
        31 => 'Problem with the STOP button', 33 => 'Mower tilted', 34 => 'Mowing stopped – too steep',
        39 => 'Problem with the cutting motor', 46 => 'Cutting height blocked', 50 => 'Guide wire 1 not found',
        53 => 'GPS-Navigationsproblem', 54 => 'Weak GPS signal', 55 => 'Charging station hard to find',
        58 => 'Temporary battery problem', 62 => 'Battery limited by ambient temperature',
    ];

    // =================================================================
    // Anmeldung
    // =================================================================

    public function anmelden(string $konto, string $passwort, string $region): array
    {
        $schluessel = trim($konto);
        $geheimnis = trim($passwort);
        if (!preg_match('/^[0-9a-f-]{20,}$/i', $schluessel)) {
            throw new RoboHubFehler(RoboHubText::t('Please enter the application key from the Husqvarna developer portal (not the e-mail).'));
        }
        return $this->tokenHolen(['marke' => $this->marke(), 'appKey' => $schluessel, 'appGeheimnis' => $geheimnis]);
    }

    public function erneuern(array $buendel): array
    {
        if ((int)($buendel['expires_at'] ?? 0) - 600 > time()) {
            return $buendel;
        }
        return $this->tokenHolen($buendel);
    }

    private function tokenHolen(array $b): array
    {
        $body = http_build_query(['grant_type' => 'client_credentials', 'client_id' => $b['appKey'], 'client_secret' => $b['appGeheimnis']]);
        [$status, $text] = $this->http('POST', self::AUTH, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], $body);
        $this->dbg('HTTP', 'POST oauth2/token → ' . $status);
        if ($status === 0) {
            throw new RoboHubFehler(RoboHubText::t('Husqvarna not reachable: %s', $text));
        }
        $j = json_decode($text, true);
        if ($status !== 200 || !is_array($j) || ($j['access_token'] ?? '') === '') {
            $e = new RoboHubFehler(RoboHubText::t('Husqvarna rejects app key/secret (HTTP %s) — check in the developer portal whether "Authentication API" and "%s" are connected to the app.', $status, $this->apiName()));
            $e->anmeldungNoetig = in_array($status, [400, 401, 403], true);
            throw $e;
        }
        $b['token'] = (string)$j['access_token'];
        $b['expires_at'] = time() + (int)($j['expires_in'] ?? 86399);
        return $b;
    }

    // =================================================================
    // Geräte und Zustand (ein gemeinsamer Abruf je Konto)
    // =================================================================

    public function geraete(array $buendel): array
    {
        $liste = [];
        foreach ($this->maeher($buendel, true) as $m) {
            $s = (array)($m['attributes']['system'] ?? []);
            $liste[] = [
                'id'     => (string)$m['id'],
                'name'   => (string)($s['name'] ?? 'Automower'),
                'modell' => 'husqvarna.mower.' . preg_replace('/[^a-z0-9]+/', '', strtolower((string)($s['model'] ?? 'automower'))),
                'klasse' => RoboHubModell::KLASSE_MAEHER,
                'online' => !empty($m['attributes']['metadata']['connected']),
                'roh'    => ['modell' => $s['model'] ?? null, 'seriennummer' => $s['serialNumber'] ?? null, 'faehigkeiten' => $m['attributes']['capabilities'] ?? null],
            ];
        }
        return $liste;
    }

    public function zustand(array $buendel, array $geraet): array
    {
        $m = $this->einer($buendel, (string)$geraet['id']);
        $p = (array)($m['attributes'] ?? []);
        $daten = $this->normalisiere((string)($geraet['modell'] ?? ''), $p);
        $daten['online'] = !empty($p['metadata']['connected']);
        $daten['bloecke'] = ['ok' => [0], 'fehl' => []];
        $daten['roh'] = ['modell' => $geraet['modell'] ?? null, 'properties' => $p];
        return $daten;
    }

    protected function einer(array $buendel, string $id): array
    {
        foreach ($this->maeher($buendel, false) as $m) {
            if ((string)($m['id'] ?? '') === $id) {
                return $m;
            }
        }
        throw new RoboHubFehler(RoboHubText::t('Mower %s is not (or no longer) in this Husqvarna account.', $id));
    }

    /** Alle Mäher; aus dem Zwischenspeicher, solange er jünger als $zwischenspeicherSekunden ist. */
    protected function maeher(array $buendel, bool $frisch): array
    {
        $datei = $this->speicherDatei($buendel);
        $gueltig = $this->echtzeitVerbunden ? max($this->zwischenspeicherSekunden, 3600) : $this->zwischenspeicherSekunden;
        if (!$frisch && $this->zwischenspeicherSekunden > 0 && is_file($datei) && time() - (int)filemtime($datei) < $gueltig) {
            $j = json_decode((string)file_get_contents($datei), true);
            if (is_array($j)) {
                return $j;
            }
        }
        $liste = $this->maeherHolen($buendel);
        if ($this->zwischenspeicherSekunden > 0) {
            @file_put_contents($datei, (string)json_encode($liste));
        }
        return $liste;
    }

    /** Alle Mäher frisch von der API (Gardena überschreibt das). */
    protected function maeherHolen(array $buendel): array
    {
        $j = $this->anfrage($buendel, 'GET', 'mowers', null);
        return array_values(array_filter((array)($j['data'] ?? []), 'is_array'));
    }

    protected function speicherDatei(array $buendel): string
    {
        return sys_get_temp_dir() . '/robohub-' . $this->marke() . '-' . substr(md5((string)$buendel['appKey']), 0, 12) . '.json';
    }

    protected function marke(): string
    {
        return 'husqvarna';
    }

    protected function apiName(): string
    {
        return 'Automower Connect API';
    }

    protected function apiBasis(): string
    {
        return self::API;
    }

    public function normalisiere(string $modell, array $p): array
    {
        $w = [];
        $m = (array)($p['mower'] ?? []);
        $akku = isset($p['battery']['batteryPercent']) ? (int)$p['battery']['batteryPercent'] : null;
        $zustand = strtoupper((string)($m['state'] ?? ''));
        $taetigkeit = strtoupper((string)($m['activity'] ?? ''));
        $grund = strtoupper((string)($p['planner']['restrictedReason'] ?? ''));
        if ($zustand !== '' || $taetigkeit !== '') {
            [$z, $text] = self::zustandAus($zustand, $taetigkeit, $grund, $akku);
            $w['Zustand'] = $z;
            $w['ZustandRoh'] = $text . ' (' . strtolower($zustand) . ($taetigkeit !== '' ? ', ' . strtolower($taetigkeit) : '') . ')';
            $w['Laedt'] = $taetigkeit === 'CHARGING';
        }
        if (isset($m['errorCode'])) {
            $code = (int)$m['errorCode'];
            $fehlerZustand = in_array($zustand, ['ERROR', 'FATAL_ERROR', 'ERROR_AT_POWER_UP'], true);
            $w['Fehler'] = ($code === 0 || !$fehlerZustand) ? RoboHubText::t('no error') : RoboHubText::wert(self::FEHLER[$code] ?? 'Error code') . ' (' . $code . ')';
        }
        $w['Akku'] = $akku;
        $s = (array)($p['statistics'] ?? []);
        $w['GesamtDauer'] = isset($s['totalCuttingTime']) && is_numeric($s['totalCuttingTime']) ? (int)round((int)$s['totalCuttingTime'] / 3600) : null;
        $optionen = [];
        $st = (array)($p['settings'] ?? []);
        if (isset($st['cuttingHeight']) && is_numeric($st['cuttingHeight'])) {
            $w['Schnitthoehe'] = (int)$st['cuttingHeight'];
            $optionen['Schnitthoehe'] = [];
            foreach (range(1, 9) as $stufe) {
                $optionen['Schnitthoehe'][$stufe] = RoboHubText::t('Level %s', $stufe);
            }
        }
        $modus = strtoupper((string)($st['headlight']['mode'] ?? ''));
        if ($modus !== '' && !empty($p['capabilities']['headlights'])) {
            foreach (self::SCHEINWERFER as $nr => [$code, $text]) {
                if ($code === $modus) {
                    $w['Scheinwerfer'] = $nr;
                }
                $optionen['Scheinwerfer'][$nr] = $text;
            }
        }
        $grundText = ['WEEK_SCHEDULE' => 'Schedule', 'PARK_OVERRIDE' => 'Parked', 'SENSOR' => 'Weather sensor', 'DAILY_LIMIT' => 'Daily limit',
            'FOTA' => 'Firmware update', 'FROST' => 'Frost', 'ALL_WORK_AREAS_COMPLETED' => RoboHubText::t('All areas finished'), 'EXTERNAL' => RoboHubText::t('External pause')][$grund] ?? null;
        if ($grundText !== null && $zustand === 'RESTRICTED') {
            $w['Meldung'] = RoboHubText::t('Restricted: %s', RoboHubText::wert($grundText));
        }
        return ['werte' => $w, 'optionen' => $optionen, 'schreibbar' => []];
    }

    /** state/activity (model_mower.py) → RoboHub-Zustand. */
    private static function zustandAus(string $zustand, string $taetigkeit, string $grund, ?int $akku): array
    {
        switch ($zustand) {
            case 'ERROR':
            case 'FATAL_ERROR':
            case 'ERROR_AT_POWER_UP':
                return [RoboHubModell::ZUSTAND_FEHLER, 'Error'];
            case 'STOPPED':
                return [RoboHubModell::ZUSTAND_FEHLER, RoboHubText::t('Stopped – intervention at the mower needed')];
            case 'PAUSED':
                return [RoboHubModell::ZUSTAND_PAUSIERT, 'Paused'];
            case 'OFF':
                return [RoboHubModell::ZUSTAND_SCHLAF, 'Switched off'];
            case 'WAIT_UPDATING':
                return [RoboHubModell::ZUSTAND_AKTUALISIERUNG, 'Updating'];
        }
        switch ($taetigkeit) {
            case 'MOWING':
            case 'LEAVING':
                return [RoboHubModell::ZUSTAND_REINIGT, $taetigkeit === 'LEAVING' ? RoboHubText::t('Leaving the station') : RoboHubText::t('Mowing')];
            case 'GOING_HOME':
                return [RoboHubModell::ZUSTAND_ZURUECK, RoboHubText::t('Going home')];
            case 'CHARGING':
                return [RoboHubModell::ZUSTAND_LAEDT, RoboHubText::t('Charging')];
            case 'PARKED_IN_CS':
                if (in_array($grund, ['SENSOR', 'FROST'], true)) {
                    return [RoboHubModell::ZUSTAND_WETTERPAUSE, RoboHubText::t('Parked (weather)')];
                }
                return [RoboHubModell::ZUSTAND_GELADEN, RoboHubText::t('Docked')];
            case 'STOPPED_IN_GARDEN':
                return [RoboHubModell::ZUSTAND_BEREIT, RoboHubText::t('Standing in the garden')];
        }
        return [RoboHubModell::ZUSTAND_UNBEKANNT, 'Unknown'];
    }

    // =================================================================
    // Arbeitsbereiche und Befehle
    // =================================================================

    public function karte(array $buendel, array $geraet): array
    {
        $raeume = [];
        foreach ((array)($this->einer($buendel, (string)$geraet['id'])['attributes']['workAreas'] ?? []) as $a) {
            $id = (int)($a['workAreaId'] ?? -1);
            if ($id < 0) {
                continue;
            }
            $name = trim((string)($a['name'] ?? ''));
            $raeume[$id] = $name !== '' ? $name : ($id === 0 ? 'Main area' : RoboHubText::t('Area %s', $id));
        }
        return ['raeume' => $raeume, 'bild' => '', 'marken' => [], 'station' => null];
    }

    public function liveKarte(array $buendel, array $geraet): array
    {
        return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
    }

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        $pausiert = strtoupper((string)($geraet['stand']['mower']['state'] ?? '')) === 'PAUSED';
        switch ($befehl) {
            case 'start':
                $daten = $pausiert ? ['type' => 'ResumeSchedule'] : ['type' => 'Start', 'attributes' => ['duration' => self::START_MINUTEN]];
                $text = $pausiert ? RoboHubText::t('Resume') : RoboHubText::t('Start (%s min)', self::START_MINUTEN);
                break;
            case 'pause':
                $daten = ['type' => 'Pause'];
                $text = 'Pause';
                break;
            case 'stopp':
                $daten = ['type' => 'ParkUntilFurtherNotice'];
                $text = RoboHubText::t('Park until further notice');
                break;
            case 'station':
                $daten = ['type' => 'ParkUntilNextSchedule'];
                $text = RoboHubText::t('Park until the next schedule');
                break;
            case 'setze':
                return $this->einstellungSetzen($buendel, (string)$geraet['id'], (string)($parameter['feld'] ?? ''), (int)($parameter['wert'] ?? -1));
            case 'raum':
                $bereiche = array_values(array_unique(array_map('intval', (array)($parameter['raeume'] ?? []))));
                if (count($bereiche) !== 1) {
                    throw new RoboHubFehler(RoboHubText::t('Husqvarna always mows exactly one work area — please choose one.'));
                }
                $daten = ['type' => 'StartInWorkArea', 'attributes' => ['duration' => self::START_MINUTEN, 'workAreaId' => $bereiche[0]]];
                $text = RoboHubText::t('Mow in work area (%s min)', self::START_MINUTEN);
                break;
            default:
                throw new RoboHubFehler($befehl === 'orten' ? RoboHubText::t('Husqvarna mowers have no "Locate" (yet).') : RoboHubText::t('This command is not supported on Husqvarna mowers (yet): %s', $befehl));
        }
        $this->anfrage($buendel, 'POST', 'mowers/' . rawurlencode((string)$geraet['id']) . '/actions', ['data' => $daten]);
        @unlink($this->speicherDatei($buendel));
        return RoboHubText::t('Command "%s" sent.', RoboHubText::wert($text));
    }

    /** Schnitthöhe/Scheinwerfer (aioautomower commands.py set_cutting_height/set_headlight_mode). */
    private function einstellungSetzen(array $buendel, string $id, string $feld, int $wert): string
    {
        if ($feld === 'Schnitthoehe' && $wert >= 1 && $wert <= 9) {
            $attribute = ['cuttingHeight' => $wert];
            $text = RoboHubText::t('Cutting height level %s', $wert);
        } elseif ($feld === 'Scheinwerfer' && isset(self::SCHEINWERFER[$wert])) {
            $attribute = ['headlight' => ['mode' => self::SCHEINWERFER[$wert][0]]];
            $text = RoboHubText::t('Headlights "%s"', RoboHubText::wert(self::SCHEINWERFER[$wert][1]));
        } else {
            throw new RoboHubFehler(RoboHubText::t('This setting does not exist on Husqvarna: %s = %s', $feld, $wert));
        }
        $this->anfrage($buendel, 'POST', 'mowers/' . rawurlencode($id) . '/settings', ['data' => ['type' => 'settings', 'attributes' => $attribute]]);
        @unlink($this->speicherDatei($buendel));
        return RoboHubText::t('%s sent.', RoboHubText::wert($text));
    }

    public function zuruecksetzen(array $buendel, array $geraet, string $teil): string
    {
        throw new RoboHubFehler(RoboHubText::t('Resetting the blade time is not built in for Husqvarna yet — please use the app.'));
    }

    // =================================================================
    // Echtzeit über WebSocket (Konto: WebSocket Client)
    // =================================================================

    public function echtzeitMoeglich(): bool
    {
        return true;
    }

    public function echtzeitArt(): string
    {
        return 'websocket';
    }

    /** Adresse und Anmeldekopf für den WebSocket Client. */
    public function wsZiel(array $buendel): array
    {
        return ['url' => self::WS, 'kopf' => [['Name' => 'Authorization', 'Value' => 'Bearer ' . $buendel['token']]]];
    }

    /** Lebenszeichen alle 60 s: eine leere Textnachricht (aioautomower send_empty_message). */
    public function wsLebenszeichen(): ?string
    {
        return '';
    }

    /**
     * Ereignis einarbeiten: in den zwischengespeicherten Mäher und als vollständige
     * Teilobjekte zurück [Mäher-ID => [Schlüssel => Wert]]. Ohne Zwischenspeicher
     * nichts — Teilwerte allein würden den Stand verfälschen; der nächste Abruf holt alles.
     */
    public function pushLesenMit(string $payload, array $buendel): array
    {
        $e = json_decode($payload, true);
        $typ = (string)($e['type'] ?? '');
        $id = (string)($e['id'] ?? '');
        if ($id === '' || substr($typ, -9) !== '-event-v2' || in_array($typ, ['message-event-v2', 'calendar-event-v2'], true)) {
            return [];
        }
        $a = (array)($e['attributes'] ?? []);
        $delta = [];
        $this->zwischenspeicherAendern($buendel, function (array &$liste) use ($id, $a, &$delta): void {
            foreach ($liste as &$m) {
                if ((string)($m['id'] ?? '') !== $id) {
                    continue;
                }
                $at = &$m['attributes'];
                if (isset($a['cuttingHeight']['height'])) {
                    $at['settings']['cuttingHeight'] = $a['cuttingHeight']['height'];
                    $schluessel = ['settings'];
                } elseif (isset($a['headlights']['mode'])) {
                    $at['settings']['headlight']['mode'] = $a['headlights']['mode'];
                    $schluessel = ['settings'];
                } elseif (isset($a['position']) && is_array($a['position'])) {
                    $at['positions'] = array_slice(array_merge([$a['position']], (array)($at['positions'] ?? [])), 0, 50);
                    $schluessel = ['positions'];
                } else {
                    foreach ($a as $k => $v) {
                        $at[$k] = is_array($v) && is_array($at[$k] ?? null) ? array_replace_recursive($at[$k], $v) : $v;
                    }
                    $schluessel = array_keys($a);
                }
                foreach ($schluessel as $k) {
                    $delta[$k] = $at[$k];
                }
                return;
            }
        });
        return $delta === [] ? [] : [$id => $delta];
    }

    /** Zwischenspeicher bearbeiten, ohne sein Alter zu ändern (der stündliche Vollabruf bleibt). */
    protected function zwischenspeicherAendern(array $buendel, callable $aendern): void
    {
        $datei = $this->speicherDatei($buendel);
        $liste = is_file($datei) ? json_decode((string)file_get_contents($datei), true) : null;
        if (!is_array($liste)) {
            return;
        }
        $alter = (int)filemtime($datei);
        $aendern($liste);
        @file_put_contents($datei, (string)json_encode($liste));
        @touch($datei, $alter);
        clearstatcache(true, $datei);
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

    protected function anfrage(array $buendel, string $methode, string $pfad, ?array $body): array
    {
        $kopf = [
            'Authorization: Bearer ' . $buendel['token'],
            'Authorization-Provider: husqvarna',
            'X-Api-Key: ' . $buendel['appKey'],
            'Content-Type: application/vnd.api+json',
            'Accept: application/vnd.api+json',
        ];
        [$status, $text] = $this->http($methode, $this->apiBasis() . $pfad, $kopf, $body !== null ? (string)json_encode($body) : null);
        $this->dbg('HTTP', $methode . ' ' . $pfad . ' → ' . $status);
        if ($status === 0) {
            throw new RoboHubFehler(RoboHubText::t('Husqvarna not reachable: %s', $text));
        }
        if ($status === 401) {
            $e = new RoboHubFehler(RoboHubText::t('Husqvarna access expired — please sign in again.'));
            $e->anmeldungNoetig = true;
            throw $e;
        }
        if ($status === 429) {
            $e = new RoboHubFehler(RoboHubText::t('Husqvarna request limit reached (10,000 per month) — RoboHub is waiting.'));
            $e->warteSekunden = 3600;
            throw $e;
        }
        if ($status === 403) {
            throw new RoboHubFehler(RoboHubText::t('Husqvarna denies access — is the "%s" connected to the app?', $this->apiName()));
        }
        $j = json_decode($text, true);
        if ($status >= 300) {
            $detail = is_array($j) ? (string)($j['errors'][0]['detail'] ?? $j['errors'][0]['title'] ?? '') : '';
            throw new RoboHubFehler(RoboHubText::t('Husqvarna responds with HTTP %s%s.', $status, ($detail !== '' ? ': ' . $detail : '')));
        }
        return is_array($j) ? $j : [];
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

    protected function dbg(string $thema, string $text): void
    {
        if (is_callable($this->debug)) {
            ($this->debug)($thema, $text);
        }
    }
}
