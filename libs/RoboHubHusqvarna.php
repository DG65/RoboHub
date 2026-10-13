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
// Noch nicht gebaut: Echtzeit (WebSocket), Zeitpläne, Schnitthöhe, Gardena.
// ===========================================================================

class RoboHubHusqvarna implements RoboHubTreiber
{
    /** @var callable|null fn(string $thema, string $text) */
    public $debug = null;

    /** Sekunden, die ein Abruf von /mowers für alle Mäher gilt (Prüfstand: 0). */
    public $zwischenspeicherSekunden = 300;

    private const API = 'https://api.amc.husqvarna.dev/v1/';
    private const AUTH = 'https://api.authentication.husqvarnagroup.dev/v1/oauth2/token';
    // Dauer für „Start"/„In Bereich starten" (Minuten) — die API verlangt eine Dauer.
    private const START_MINUTEN = 180;

    // model_mower.py ERRORCODES (Auszug, deutsch).
    private const FEHLER = [
        1 => 'Außerhalb des Arbeitsbereichs', 2 => 'Kein Schleifensignal', 3 => 'Falsches Schleifensignal',
        8 => 'Falscher PIN-Code', 9 => 'Eingeklemmt', 10 => 'Kopfüber', 11 => 'Akku schwach', 12 => 'Akku leer',
        13 => 'Kein Antrieb', 14 => 'Mäher angehoben', 15 => 'Angehoben', 16 => 'Steckt in der Ladestation',
        17 => 'Ladestation blockiert', 20 => 'Radmotor rechts blockiert', 21 => 'Radmotor links blockiert',
        24 => 'Schneidsystem blockiert', 25 => 'Schneidsystem blockiert', 29 => 'Zu steil', 30 => 'Problem am Ladesystem',
        31 => 'Problem mit der STOP-Taste', 33 => 'Mäher gekippt', 34 => 'Mähen gestoppt – zu steil',
        39 => 'Problem am Schneidmotor', 46 => 'Schnitthöhe blockiert', 50 => 'Leitkabel 1 nicht gefunden',
        53 => 'GPS-Navigationsproblem', 54 => 'Schwaches GPS-Signal', 55 => 'Ladestation schwer zu finden',
        58 => 'Vorübergehendes Akkuproblem', 62 => 'Akku durch Umgebungstemperatur eingeschränkt',
    ];

    // =================================================================
    // Anmeldung
    // =================================================================

    public function anmelden(string $konto, string $passwort, string $region): array
    {
        $schluessel = trim($konto);
        $geheimnis = trim($passwort);
        if (!preg_match('/^[0-9a-f-]{20,}$/i', $schluessel)) {
            throw new RoboHubFehler('Bitte den Application Key aus dem Husqvarna-Entwicklerportal eintragen (nicht die E-Mail).');
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
            throw new RoboHubFehler('Husqvarna nicht erreichbar: ' . $text);
        }
        $j = json_decode($text, true);
        if ($status !== 200 || !is_array($j) || ($j['access_token'] ?? '') === '') {
            $e = new RoboHubFehler('Husqvarna lehnt App-Schlüssel/Geheimnis ab (HTTP ' . $status . ') — im Entwicklerportal prüfen, ob „Authentication API" und „' . $this->apiName() . '" mit der App verbunden sind.');
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
        throw new RoboHubFehler('Mäher ' . $id . ' ist in diesem Husqvarna-Konto nicht (mehr) vorhanden.');
    }

    /** Alle Mäher; aus dem Zwischenspeicher, solange er jünger als $zwischenspeicherSekunden ist. */
    protected function maeher(array $buendel, bool $frisch): array
    {
        $datei = $this->speicherDatei($buendel);
        if (!$frisch && $this->zwischenspeicherSekunden > 0 && is_file($datei) && time() - (int)filemtime($datei) < $this->zwischenspeicherSekunden) {
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
            $w['Fehler'] = ($code === 0 || !$fehlerZustand) ? 'kein Fehler' : (self::FEHLER[$code] ?? 'Fehlercode') . ' (' . $code . ')';
        }
        $w['Akku'] = $akku;
        $s = (array)($p['statistics'] ?? []);
        $w['GesamtDauer'] = isset($s['totalCuttingTime']) && is_numeric($s['totalCuttingTime']) ? (int)round((int)$s['totalCuttingTime'] / 3600) : null;
        $grundText = ['WEEK_SCHEDULE' => 'Zeitplan', 'PARK_OVERRIDE' => 'Geparkt', 'SENSOR' => 'Wettersensor', 'DAILY_LIMIT' => 'Tageslimit',
            'FOTA' => 'Firmware-Update', 'FROST' => 'Frost', 'ALL_WORK_AREAS_COMPLETED' => 'Alle Bereiche fertig', 'EXTERNAL' => 'Externe Pause'][$grund] ?? null;
        if ($grundText !== null && $zustand === 'RESTRICTED') {
            $w['Meldung'] = 'Eingeschränkt: ' . $grundText;
        }
        return ['werte' => $w, 'optionen' => [], 'schreibbar' => []];
    }

    /** state/activity (model_mower.py) → RoboHub-Zustand. */
    private static function zustandAus(string $zustand, string $taetigkeit, string $grund, ?int $akku): array
    {
        switch ($zustand) {
            case 'ERROR':
            case 'FATAL_ERROR':
            case 'ERROR_AT_POWER_UP':
                return [RoboHubModell::ZUSTAND_FEHLER, 'Fehler'];
            case 'STOPPED':
                return [RoboHubModell::ZUSTAND_FEHLER, 'Angehalten – Eingriff am Mäher nötig'];
            case 'PAUSED':
                return [RoboHubModell::ZUSTAND_PAUSIERT, 'Pausiert'];
            case 'OFF':
                return [RoboHubModell::ZUSTAND_SCHLAF, 'Ausgeschaltet'];
            case 'WAIT_UPDATING':
                return [RoboHubModell::ZUSTAND_AKTUALISIERUNG, 'Aktualisiert'];
        }
        switch ($taetigkeit) {
            case 'MOWING':
            case 'LEAVING':
                return [RoboHubModell::ZUSTAND_REINIGT, $taetigkeit === 'LEAVING' ? 'Verlässt die Station' : 'Mäht'];
            case 'GOING_HOME':
                return [RoboHubModell::ZUSTAND_ZURUECK, 'Fährt nach Hause'];
            case 'CHARGING':
                return [RoboHubModell::ZUSTAND_LAEDT, 'Lädt'];
            case 'PARKED_IN_CS':
                if (in_array($grund, ['SENSOR', 'FROST'], true)) {
                    return [RoboHubModell::ZUSTAND_WETTERPAUSE, 'Geparkt (Wetter)'];
                }
                return [RoboHubModell::ZUSTAND_GELADEN, 'In der Station'];
            case 'STOPPED_IN_GARDEN':
                return [RoboHubModell::ZUSTAND_BEREIT, 'Steht im Garten'];
        }
        return [RoboHubModell::ZUSTAND_UNBEKANNT, 'Unbekannt'];
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
            $raeume[$id] = $name !== '' ? $name : ($id === 0 ? 'Hauptbereich' : 'Bereich ' . $id);
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
                $text = $pausiert ? 'Fortsetzen' : 'Start (' . self::START_MINUTEN . ' min)';
                break;
            case 'pause':
                $daten = ['type' => 'Pause'];
                $text = 'Pause';
                break;
            case 'stopp':
                $daten = ['type' => 'ParkUntilFurtherNotice'];
                $text = 'Parken bis auf Weiteres';
                break;
            case 'station':
                $daten = ['type' => 'ParkUntilNextSchedule'];
                $text = 'Parken bis zum nächsten Zeitplan';
                break;
            case 'raum':
                $bereiche = array_values(array_unique(array_map('intval', (array)($parameter['raeume'] ?? []))));
                if (count($bereiche) !== 1) {
                    throw new RoboHubFehler('Husqvarna mäht immer genau einen Arbeitsbereich — bitte einen wählen.');
                }
                $daten = ['type' => 'StartInWorkArea', 'attributes' => ['duration' => self::START_MINUTEN, 'workAreaId' => $bereiche[0]]];
                $text = 'Mähen im Arbeitsbereich (' . self::START_MINUTEN . ' min)';
                break;
            default:
                throw new RoboHubFehler(($befehl === 'orten' ? '„Orten" gibt es' : 'Dieser Befehl wird') . ' bei Husqvarna-Mähern (noch) nicht' . ($befehl === 'orten' ? '.' : ' unterstützt: ' . $befehl));
        }
        $this->anfrage($buendel, 'POST', 'mowers/' . rawurlencode((string)$geraet['id']) . '/actions', ['data' => $daten]);
        @unlink($this->speicherDatei($buendel));
        return 'Befehl „' . $text . '" gesendet.';
    }

    public function zuruecksetzen(array $buendel, array $geraet, string $teil): string
    {
        throw new RoboHubFehler('Zurücksetzen der Messerzeit ist bei Husqvarna noch nicht eingebaut — bitte in der App.');
    }

    // =================================================================
    // Echtzeit: noch nicht gebaut (WebSocket der API)
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
            throw new RoboHubFehler('Husqvarna nicht erreichbar: ' . $text);
        }
        if ($status === 401) {
            $e = new RoboHubFehler('Husqvarna-Zugang abgelaufen — bitte neu anmelden.');
            $e->anmeldungNoetig = true;
            throw $e;
        }
        if ($status === 429) {
            $e = new RoboHubFehler('Husqvarna-Anfragelimit erreicht (10.000 im Monat) — RoboHub wartet.');
            $e->warteSekunden = 3600;
            throw $e;
        }
        if ($status === 403) {
            throw new RoboHubFehler('Husqvarna verweigert den Zugriff — ist die „' . $this->apiName() . '" mit der App verbunden?');
        }
        $j = json_decode($text, true);
        if ($status >= 300) {
            $detail = is_array($j) ? (string)($j['errors'][0]['detail'] ?? $j['errors'][0]['title'] ?? '') : '';
            throw new RoboHubFehler('Husqvarna antwortet mit HTTP ' . $status . ($detail !== '' ? ': ' . $detail : '') . '.');
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
