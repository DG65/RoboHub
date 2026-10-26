<?php

declare(strict_types=1);

// ===========================================================================
// RoboHub-Treiber für AL-KO Robolinho — offizielle AL-KO Gardentech IoT API.
//
// Quellen: trx222/alko-cli (MIT, docs/API.md + docs/FINDINGS.md, Stand
// 28.08.2026, am Robolinho 700 W gemessen), jonkristian/alko (MIT, HA,
// lawn_mower.py), chave/homebridge-alko-mower (MIT, index.js) und AL-KOs
// öffentliche Schema-Doku (AL-KO_Gardentech_IoT_API.html).
//
// Zugang: Der Nutzer beantragt bei AL-KO Client-ID + Secret
// (alko-garden.de/iot-api-zugang-anfordern). Anmeldung OAuth2-Password-Grant
// mit E-Mail/Passwort der inTOUCH-App und offline_access → Erneuerungsschlüssel.
// Dietmars Entscheidung 26.10.2026 (wie Husqvarna): Client-ID + Secret bleiben
// im Bündel (Konto-Attribut), das Passwort wird nach der Anmeldung verworfen.
//
// Gerät = AWS-IoT-Shadow: GET state → state.reported, Befehle als PATCH
// state/desired (operationState WORKING/IDLE/HOMING, dazu rtc wie HA).
// operationError.code 999 = kein Fehler (Sentinel, FINDINGS.md).
// Nicht gebaut: Einstellungen schreiben (Regenverzögerung/Eco/Regensensor —
// Wertebereiche unbelegt), Mähen mit Dauer/Startpunkt, Randmähen, Firmware-Modus.
// ===========================================================================

class RoboHubAlko implements RoboHubTreiber
{
    /** @var callable|null fn(string $thema, string $text) */
    public $debug = null;

    /** Vom Konto vor der Anmeldung gesetzt: ['clientId' => …, 'clientSecret' => …]. */
    public $zusatz = [];

    private const IDP = 'https://idp.al-ko.com/connect/token';
    private const API = 'https://api.al-ko.com/v1/iot/';
    private const SCOPE = 'alkoCustomerId alkoCulture offline_access introspection';

    // operationState (API.md, HA lawn_mower.py) → [RoboHub-Zustand, Klartext].
    private const ZUSTAENDE = [
        'WORKING'           => [RoboHubModell::ZUSTAND_REINIGT, 'Mäht'],
        'HOMING'            => [RoboHubModell::ZUSTAND_ZURUECK, 'Fährt zur Station'],
        'CHARGING'          => [RoboHubModell::ZUSTAND_LAEDT, 'Lädt'],
        'IDLE_BASE_STATION' => [RoboHubModell::ZUSTAND_GELADEN, 'In der Station'],
        'IDLE'              => [RoboHubModell::ZUSTAND_PAUSIERT, 'Angehalten'],
    ];

    // Grund der Heimfahrt aus situationFlags (API.md: „die mit Abstand nützlichsten").
    private const HEIMFAHRT = [
        'homingTriggerBatteryWeak' => 'Akku schwach', 'homingTriggerRain' => 'Regen', 'homingTriggerSchedule' => 'Mähzeit vorbei',
        'homingTriggerUserRequest' => 'auf Wunsch', 'homingTriggerFrost' => 'Frost', 'homingTriggerTemperature' => 'Temperatur',
        'homingTriggerMowingUnitOverload' => 'Messer überlastet', 'homingTriggerRestart' => 'Neustart',
    ];

    private const TAGE = ['monday' => 'Mo', 'tuesday' => 'Di', 'wednesday' => 'Mi', 'thursday' => 'Do', 'friday' => 'Fr', 'saturday' => 'Sa', 'sunday' => 'So'];

    // =================================================================
    // Anmeldung
    // =================================================================

    public function anmelden(string $konto, string $passwort, string $region): array
    {
        $id = trim((string)($this->zusatz['clientId'] ?? ''));
        $geheim = trim((string)($this->zusatz['clientSecret'] ?? ''));
        if ($id === '' || $geheim === '') {
            throw new RoboHubFehler('Bitte Client-ID und Client-Secret eintragen, die AL-KO dir für den API-Zugang geschickt hat.');
        }
        if (!filter_var(trim($konto), FILTER_VALIDATE_EMAIL) || $passwort === '') {
            throw new RoboHubFehler('Bitte E-Mail und Passwort der AL-KO-inTOUCH-App eintragen.');
        }
        return $this->tokenHolen(['grant_type' => 'password', 'username' => trim($konto), 'password' => $passwort, 'scope' => self::SCOPE],
            ['clientId' => $id, 'clientSecret' => $geheim]);
    }

    public function anmeldeText(array $buendel): string
    {
        return '✅ Angemeldet. Client-ID und Client-Secret bleiben im Konto gespeichert, weil AL-KO den Zugang nur damit erneuert; das Passwort wurde verworfen. Den API-Zugang kannst du bei AL-KO jederzeit sperren lassen, „Abmelden" löscht ihn hier.';
    }

    public function erneuern(array $buendel): array
    {
        if (!is_file($this->erneuernDatei($buendel)) && (int)($buendel['expires_at'] ?? 0) - 300 > time()) {
            return $buendel;
        }
        $neu = $this->tokenHolen(['grant_type' => 'refresh_token', 'refresh_token' => (string)($buendel['refresh_token'] ?? '')], $buendel);
        @unlink($this->erneuernDatei($buendel));
        return $neu;
    }

    private function tokenHolen(array $felder, array $b): array
    {
        $felder += ['client_id' => $b['clientId'], 'client_secret' => $b['clientSecret']];
        [$status, $text] = $this->http('POST', self::IDP, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            http_build_query($felder, '', '&', PHP_QUERY_RFC3986));
        $this->dbg('HTTP', 'POST connect/token (' . $felder['grant_type'] . ') → ' . $status);
        if ($status === 0) {
            throw new RoboHubFehler('AL-KO-Anmeldung nicht erreichbar: ' . $text);
        }
        $j = json_decode($text, true);
        if ($status !== 200 || !is_array($j) || (string)($j['access_token'] ?? '') === '') {
            $grund = is_array($j) ? (string)($j['error'] ?? '') : '';
            if ($status >= 500 && $grund === '') {
                throw new RoboHubFehler('AL-KO-Anmeldung antwortet mit HTTP ' . $status . ' — bitte später erneut versuchen.');
            }
            $e = new RoboHubFehler($felder['grant_type'] === 'password'
                ? 'AL-KO lehnt die Anmeldung ab' . ($grund !== '' ? ' (' . $grund . ')' : '') . ' — Client-ID/Secret und E-Mail/Passwort prüfen.'
                : 'AL-KO lehnt die Erneuerung ab' . ($grund !== '' ? ' (' . $grund . ')' : '') . ' — bitte neu anmelden.');
            $e->anmeldungNoetig = true;
            throw $e;
        }
        return [
            'clientId'      => $b['clientId'],
            'clientSecret'  => $b['clientSecret'],
            'access_token'  => (string)$j['access_token'],
            'refresh_token' => (string)($j['refresh_token'] ?? $b['refresh_token'] ?? ''),
            'expires_at'    => time() + max(300, (int)($j['expires_in'] ?? 3600)),
        ];
    }

    private function erneuernDatei(array $buendel): string
    {
        return sys_get_temp_dir() . '/robohub-alko-erneuern-' . substr(md5((string)($buendel['refresh_token'] ?? '')), 0, 12);
    }

    // =================================================================
    // Geräte und Zustand
    // =================================================================

    public function geraete(array $buendel): array
    {
        $liste = [];
        // Antwort ist eine Liste (FINDINGS.md: nicht wie in pyalko ein Objekt).
        foreach ($this->anfrage($buendel, 'GET', 'things?pimInfo=true&thingState=true&accesses=true&thingCategory=ALKO-ROBOLINHO', null) as $t) {
            $name = is_array($t) ? (string)($t['thingName'] ?? '') : '';
            if ($name === '') {
                continue;
            }
            $a = (array)($t['thingAttributes'] ?? []);
            $modell = (string)($a['thingModel'] ?? 'Robolinho');
            $liste[] = [
                'id'     => $name,
                'name'   => trim((string)($t['pim']['name'] ?? $t['thingAttributes']['name'] ?? '')) ?: $modell,
                'modell' => 'alko.mower.' . (strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '', $modell)) ?: 'robolinho'),
                'klasse' => RoboHubModell::KLASSE_MAEHER,
                'online' => !empty($t['thingState']['state']['reported']['isConnected']),
                'roh'    => ['modell' => $modell, 'firmware' => $a['firmwareVersion'] ?? null, 'artikel' => $a['articleNumber'] ?? null],
            ];
        }
        return $liste;
    }

    public function zustand(array $buendel, array $geraet): array
    {
        $s = $this->anfrage($buendel, 'GET', 'things/' . rawurlencode((string)$geraet['id']) . '/state', null);
        $p = (array)($s['state']['reported'] ?? []);
        if ($p === []) {
            throw new RoboHubFehler('AL-KO liefert für diesen Mäher keinen Zustand — ist er noch im Konto?');
        }
        $daten = $this->normalisiere((string)($geraet['modell'] ?? ''), $p);
        $daten['online'] = !empty($p['isConnected']);
        $daten['bloecke'] = ['ok' => [0], 'fehl' => []];
        $daten['roh'] = ['modell' => $geraet['modell'] ?? null, 'properties' => $p];
        return $daten;
    }

    public function normalisiere(string $modell, array $p): array
    {
        $w = [];
        $f = (array)($p['situationFlags'] ?? []);
        $roh = (string)($p['operationState'] ?? '');
        [$z, $text] = self::ZUSTAENDE[$roh] ?? [RoboHubModell::ZUSTAND_UNBEKANNT, 'Unbekannt'];
        if ($roh === 'HOMING') {
            foreach (self::HEIMFAHRT as $flag => $grund) {
                if (!empty($f[$flag])) {
                    $text .= ' (' . $grund . ')';
                    break;
                }
            }
        }
        $wetter = (!empty($f['rainDetected']) && isset($f['rainAllowsMowing']) && !$f['rainAllowsMowing'])
            || (!empty($f['frostDetected']) && isset($f['frostAllowsMowing']) && !$f['frostAllowsMowing']);
        if ($wetter && in_array($z, [RoboHubModell::ZUSTAND_GELADEN, RoboHubModell::ZUSTAND_LAEDT, RoboHubModell::ZUSTAND_PAUSIERT], true)) {
            [$z, $text] = [RoboHubModell::ZUSTAND_WETTERPAUSE, !empty($f['frostDetected']) ? 'Frostpause' : 'Regenpause'];
        }
        $fehler = 'kein Fehler';
        $code = (int)($p['operationError']['code'] ?? 999);
        if ($code !== 999) {
            $beschreibung = (string)($p['operationError']['description'] ?? '');
            $fehler = 'Fehler ' . $code . ($beschreibung !== '' && $beschreibung !== 'UNKNOWN' ? ' (' . $beschreibung . ')' : '');
            [$z, $text] = [RoboHubModell::ZUSTAND_FEHLER, 'Fehler'];
        } elseif (($p['operationSubState'] ?? '') === 'LOCKED_PIN' || ($p['operationSituation'] ?? '') === 'OPERATION_NOT_PERMITTED_LOCKED') {
            // PIN-Sperre (z. B. nach Firmware-Update) — per API nicht lösbar (FINDINGS.md)
            $fehler = 'PIN-Sperre — bitte am Mäher entsperren';
            [$z, $text] = [RoboHubModell::ZUSTAND_FEHLER, 'Gesperrt'];
        }
        if (array_key_exists('isConnected', $p) && !$p['isConnected']) {
            [$z, $text] = [RoboHubModell::ZUSTAND_UNBEKANNT, 'Offline'];
        }
        $w['Zustand'] = $z;
        $w['ZustandRoh'] = $text . ($roh !== '' ? ' (' . $roh . ')' : '');
        $w['Fehler'] = $fehler;
        $w['Akku'] = isset($p['batteryLevel']) && is_numeric($p['batteryLevel']) ? (int)$p['batteryLevel'] : null;
        $w['Laedt'] = $roh === 'CHARGING' || !empty($f['chargerActive']);
        $w['WlanSignal'] = isset($p['rssi']) && is_numeric($p['rssi']) && (int)$p['rssi'] < 0 ? (int)$p['rssi'] : null;
        // Laufzeiten in Minuten (API.md, am Gerät nachgemessen).
        $w['GesamtDauer'] = isset($p['operationTimeMowing']) ? (int)round((float)$p['operationTimeMowing'] / 60) : null;
        $w['Messerlaufzeit'] = isset($p['operationTimeBlade']) ? round((float)$p['operationTimeBlade'] / 60, 1) : null;
        $w['GesamtAnzahl'] = isset($p['mowingCycles']) ? (int)$p['mowingCycles'] : null;
        $w['Ladezyklen'] = isset($p['chargingCycles']) ? (int)$p['chargingCycles'] : null;
        $w['Messer'] = isset($p['remainingBladeLifetime']) && is_numeric($p['remainingBladeLifetime']) ? (int)$p['remainingBladeLifetime'] : null;
        if (isset($f['rainDetected'])) {
            $w['Regensensor'] = (bool)$f['rainDetected'];
        }
        if (isset($p['rainDelay']) && is_numeric($p['rainDelay'])) {
            $w['Regenverzoegerung'] = (int)$p['rainDelay'] === 0 ? 'Aus' : (int)$p['rainDelay'] . ' min';
        }
        if (!empty($f['userInteraction'])) {
            $w['Meldung'] = 'Der Mäher wartet auf eine Eingabe am Gerät.';
        } elseif (!empty($f['bladeService'])) {
            $w['Meldung'] = 'Messer-Service fällig.';
        }
        $aus = ['werte' => $w, 'optionen' => [], 'schreibbar' => []];
        if (isset($p['mowingWindows']) && is_array($p['mowingWindows'])) {
            $aus['zeitplaene'] = self::wochenplan($p['mowingWindows']);
        }
        return $aus;
    }

    /** Mähfenster je Tag als Anzeige-Termine (Art „text"); nur aktive Fenster. */
    public static function wochenplan(array $fenster): array
    {
        $termine = [];
        $i = 0;
        foreach (self::TAGE as $tag => $kurz) {
            foreach ((array)($fenster[$tag] ?? []) as $f) {
                $i++;
                if (!is_array($f) || empty($f['activityMode']) || (int)($f['duration'] ?? 0) <= 0) {
                    continue;
                }
                $termine[] = ['id' => $i, 'an' => true, 'art' => 'text', 'text' => $kurz . ' ' . sprintf('%02d:%02d', (int)($f['startHour'] ?? 0), (int)($f['startMinute'] ?? 0))
                    . ' · ' . (int)$f['duration'] . ' min' . (!empty($f['marginMode']) ? ' · mit Randmähen' : '') . (!empty($f['narrowPassageMode']) ? ' · Engstelle' : '')];
            }
        }
        return $termine;
    }

    // =================================================================
    // Befehle
    // =================================================================

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        $ziel = ['start' => ['WORKING', 'Mähen'], 'pause' => ['IDLE', 'Anhalten'], 'station' => ['HOMING', 'Zur Station']][$befehl] ?? null;
        if ($ziel === null) {
            throw new RoboHubFehler($befehl === 'stopp' ? '„Stopp" gibt es beim Robolinho nicht — „Pause" oder „Zur Station" nehmen.'
                : ($befehl === 'orten' ? '„Orten" bietet AL-KO nicht.' : ($befehl === 'raum' ? 'Zonen kennt der Robolinho nicht.' : 'Dieser Befehl wird bei AL-KO nicht unterstützt: ' . $befehl)));
        }
        $stand = (array)($geraet['stand'] ?? []);
        if (($stand['operationSubState'] ?? '') === 'LOCKED_PIN' && $befehl === 'start') {
            throw new RoboHubFehler('Der Mäher ist per PIN gesperrt — bitte am Gerät entsperren.');
        }
        // Uhrzeit mitsenden wie die HA-Integration (lawn_mower.py).
        $this->anfrage($buendel, 'PATCH', 'things/' . rawurlencode((string)$geraet['id']) . '/state/desired',
            ['operationState' => $ziel[0], 'rtc' => date('Y-m-d\TH:i:s')]);
        return 'Befehl „' . $ziel[1] . '" gesendet.';
    }

    public function karte(array $buendel, array $geraet): array
    {
        return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
    }

    public function liveKarte(array $buendel, array $geraet): array
    {
        return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
    }

    public function zuruecksetzen(array $buendel, array $geraet, string $teil): string
    {
        throw new RoboHubFehler('Messer zurücksetzen geht beim Robolinho (noch) nur in der inTOUCH-App.');
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

    private function anfrage(array $buendel, string $methode, string $pfad, ?array $body): array
    {
        $kopf = ['Authorization: Bearer ' . $buendel['access_token'], 'Accept: application/json', 'Content-Type: application/json', 'User-Agent: RoboHub'];
        [$status, $text] = $this->http($methode, self::API . $pfad, $kopf, $body !== null ? (string)json_encode($body) : null);
        $this->dbg('HTTP', $methode . ' ' . preg_replace('/\?.*/', '', $pfad) . ' → ' . $status);
        if ($status === 0 || $status >= 500) {
            $j = json_decode($text, true);
            throw new RoboHubFehler('AL-KO-Cloud' . ($status === 0 ? ' nicht erreichbar: ' . $text : ' antwortet mit HTTP ' . $status
                . (is_array($j) && isset($j['message']) ? ': ' . $j['message'] : '')) . '.');
        }
        if ($status === 401) {
            @touch($this->erneuernDatei($buendel));   // nicht abmelden: beim nächsten Abruf erneuern
            throw new RoboHubFehler('AL-KO hat den Zugangsschlüssel abgelehnt — RoboHub erneuert ihn beim nächsten Abruf.');
        }
        if ($status === 429) {
            $e = new RoboHubFehler('AL-KO bremst die Anfragen — RoboHub wartet.');
            $e->warteSekunden = 600;
            throw $e;
        }
        if ($status >= 300) {
            throw new RoboHubFehler('AL-KO antwortet mit HTTP ' . $status . '.');
        }
        $j = json_decode($text, true);
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
            CURLOPT_TIMEOUT        => 30,
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
