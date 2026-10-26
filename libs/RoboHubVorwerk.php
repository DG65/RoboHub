<?php

declare(strict_types=1);

// ===========================================================================
// RoboHub-Treiber für Vorwerk Kobold VR200/VR300 (App „MyKobold").
//
// Quellen: stianaske/pybotvac (MIT: vorwerk.py, session.py, robot.py),
// SornyKonst/Homey-App-Vorwerk-Kobold (MIT, Stand 18.10.2026, live an einem VR300:
// lib/kobold-client.js, lib/robot-state.js) und bndtblds/homeassistant-vorwerk
// (Apache-2.0, 13.10.2026: const.py — Bedeutung der ui_error-Codes).
//
// Anmeldung per E-Mail-Code (Auth0 „passwordless", mykobold.eu.auth0.com) — kein
// Passwort. Mit dem ID-Token holt RoboHub einmal die Roboter samt deren eigenem
// Schlüssel (secret_key) von beehive; Zustand und Befehle gehen danach direkt an
// nucleo, je Nachricht HMAC-SHA256-signiert (Kopf „NEATOAPP"). Der Kontoschlüssel
// wird nur für die Geräteliste gebraucht — läuft er ab, steuert RoboHub weiter.
//
// Nicht unterstützt: Kobold VR7 (andere Cloud „orbital", keine belastbare offene
// Quelle: die einzige setzt eine feste Grundriss-ID und fragt keinen Zustand ab).
// Nicht gebaut: Spot-Reinigung, Eco/Turbo- und Navigationswahl, Zeitplan, Karte.
// ===========================================================================

class RoboHubVorwerk implements RoboHubTreiber
{
    /** @var callable|null fn(string $thema, string $text) */
    public $debug = null;

    private const AUTH = 'https://mykobold.eu.auth0.com/';
    private const CLIENT = 'KY4YbVAvtgB7lp8vIbWQ7zLk3hssZlhR';
    private const BEEHIVE = 'https://beehive.ksecosys.com/';
    private const SCOPE = 'openid email profile read:current_user offline_access';

    // state.action (Neato-Protokoll; robot-state.js ACTIVE_CLEANING_ACTIONS/SUSPENDED_ACTIONS).
    private const REINIGEN = [1 => 'Reinigt', 2 => 'Spot-Reinigung', 3 => 'Manuelle Reinigung', 11 => 'Reinigt nach Karte', 12 => 'Erkundet die Wohnung'];
    private const PAUSE_AKTIONEN = [6, 15];

    // ui_error-Codes zu Gruppen (Bedeutung nach homeassistant-vorwerk const.py, eigene Texte).
    private const FEHLER_GRUPPEN = [
        'ui_error_battery_'        => 'Akku-Fehler — Akku prüfen bzw. tauschen',
        'ui_error_brush_stuck'     => 'Bürste blockiert',
        'ui_error_brush_overloaded' => 'Bürste überlastet',
        'ui_error_bumper_stuck'    => 'Stoßfänger klemmt',
        'ui_error_dust_bin_missing' => 'Staubbehälter fehlt',
        'ui_error_dust_bin_full'   => 'Staubbehälter voll',
        'ui_error_picked_up'       => 'Angehoben',
        'ui_error_stuck'           => 'Steckt fest',
        'ui_error_unable_to_return_to_base' => 'Findet nicht zur Station',
        'ui_error_navigation_pathproblems' => 'Findet nicht zur Station',
        'ui_error_navigation_'     => 'Weg versperrt — bitte freiräumen',
        'ui_error_lwheel_stuck'    => 'Rad links blockiert',
        'ui_error_rwheel_stuck'    => 'Rad rechts blockiert',
        'ui_error_ldrop_stuck'     => 'Weg versperrt — bitte freiräumen',
        'ui_error_rdrop_stuck'     => 'Weg versperrt — bitte freiräumen',
        'ui_error_lds_jammed'      => 'Laserturm blockiert',
        'ui_error_deck_debris'     => 'Schmutz im Bürstenschacht',
        'ui_error_disconnect_chrg_cable' => 'Ladekabel getrennt',
        'ui_error_hardware_failure' => 'Hardwarefehler',
    ];
    private const HINWEISE = [
        'ui_alert_dust_bin_full' => 'Staubbehälter voll', 'ui_alert_return_to_base' => 'Fährt zur Station zurück',
        'ui_alert_return_to_charge' => 'Fährt zum Laden zurück', 'ui_alert_busy_charging' => 'Lädt gerade',
        'ui_alert_recovering_location' => 'Sucht seine Position', 'ui_alert_brush_change' => 'Bürste bald wechseln',
        'ui_alert_filter_change' => 'Filter bald wechseln', 'ui_alert_timed_cleaning' => 'Zeitgesteuerte Reinigung',
    ];

    // =================================================================
    // Anmeldung per E-Mail-Code
    // =================================================================

    public function codeAnfordern(string $konto, array $vorstufe = []): string
    {
        $mail = strtolower(trim($konto));
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            throw new RoboHubFehler('Bitte die E-Mail-Adresse des MyKobold-Kontos eintragen.');
        }
        [$status] = $this->http('POST', self::AUTH . 'passwordless/start', ['Content-Type: application/json'],
            (string)json_encode(['client_id' => self::CLIENT, 'connection' => 'email', 'email' => $mail, 'send' => 'code']));
        $this->dbg('HTTP', 'POST passwordless/start → ' . $status);
        if ($status !== 200) {
            throw new RoboHubFehler('Vorwerk konnte keinen Code senden (HTTP ' . $status . ') — E-Mail prüfen und erneut versuchen.');
        }
        return 'Vorwerk hat einen Anmeldecode an ' . $mail . ' geschickt.';
    }

    public function anmelden(string $konto, string $passwort, string $region): array
    {
        $mail = strtolower(trim($konto));
        $code = preg_replace('/\s+/', '', $passwort);
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{4,8}$/', (string)$code)) {
            throw new RoboHubFehler('Bitte E-Mail eintragen, „📧 Code anfordern" und den Code aus der E-Mail ins Feld „Code" übernehmen.');
        }
        [$status, $text] = $this->http('POST', self::AUTH . 'oauth/token', ['Content-Type: application/json'], (string)json_encode([
            'prompt' => 'login', 'grant_type' => 'http://auth0.com/oauth/grant-type/passwordless/otp', 'scope' => self::SCOPE, 'locale' => 'en',
            'otp' => $code, 'source' => 'vorwerk_auth0', 'platform' => 'ios', 'audience' => self::AUTH . 'userinfo', 'username' => $mail,
            'client_id' => self::CLIENT, 'realm' => 'email', 'country_code' => 'DE',
        ]));
        $this->dbg('HTTP', 'POST oauth/token (otp) → ' . $status);
        $j = json_decode($text, true);
        if ($status !== 200 || !is_array($j) || (string)($j['id_token'] ?? '') === '') {
            $e = new RoboHubFehler('Vorwerk lehnt den Code ab' . (is_array($j) && isset($j['error']) ? ' (' . $j['error'] . ')' : '') . ' — er gilt nur kurz. Bitte einen neuen anfordern.');
            $e->anmeldungNoetig = true;
            throw $e;
        }
        $b = self::kontoBuendel($j, []);
        $b['roboter'] = $this->roboterHolen($b);
        if ($b['roboter'] === []) {
            throw new RoboHubFehler('Im MyKobold-Konto ist kein Kobold VR200/VR300 eingetragen.');
        }
        return $b;
    }

    public function anmeldeText(array $buendel): string
    {
        return '✅ Angemeldet. RoboHub kennt kein Vorwerk-Passwort; gespeichert sind der Kontoschlüssel und je Roboter dessen eigener Schlüssel. Der Code wurde verworfen.';
    }

    private static function kontoBuendel(array $j, array $alt): array
    {
        return [
            'id_token'      => (string)$j['id_token'],
            'refresh_token' => (string)($j['refresh_token'] ?? $alt['refresh_token'] ?? ''),
            'expires_at'    => time() + max(300, (int)($j['expires_in'] ?? 3600)),
            'roboter'       => (array)($alt['roboter'] ?? []),
        ];
    }

    /**
     * Kontoschlüssel nur für die Geräteliste. Scheitert die Erneuerung, laufen die
     * Roboter mit ihrem eigenen Schlüssel weiter — kein Abmelden.
     */
    public function erneuern(array $buendel): array
    {
        if (empty($buendel['roboter'])) {
            $e = new RoboHubFehler('Noch kein Kobold im Konto — bitte anmelden.');
            $e->anmeldungNoetig = true;
            throw $e;
        }
        if ((int)($buendel['expires_at'] ?? 0) - 300 > time() || (string)($buendel['refresh_token'] ?? '') === '' || !empty($buendel['konto_abgelaufen'])) {
            return $buendel;
        }
        [$status, $text] = $this->http('POST', self::AUTH . 'oauth/token', ['Content-Type: application/json'],
            (string)json_encode(['grant_type' => 'refresh_token', 'client_id' => self::CLIENT, 'refresh_token' => $buendel['refresh_token']]));
        $this->dbg('HTTP', 'POST oauth/token (refresh) → ' . $status);
        $j = json_decode($text, true);
        if ($status === 200 && is_array($j) && (string)($j['id_token'] ?? '') !== '') {
            return self::kontoBuendel($j, $buendel);
        }
        if (in_array($status, [400, 401, 403], true)) {
            $buendel['konto_abgelaufen'] = true;   // Roboter bleiben steuerbar; nur die Geräteliste braucht eine neue Anmeldung
        }
        return $buendel;
    }

    /** beehive users/me/robots → [Seriennummer => serial, secret, endpoint, name, model]. */
    private function roboterHolen(array $b): array
    {
        [$status, $text] = $this->http('GET', self::BEEHIVE . 'users/me/robots', ['Accept: application/vnd.neato.beehive.v1+json', 'Authorization: Auth0Bearer ' . $b['id_token']], null);
        $this->dbg('HTTP', 'GET users/me/robots → ' . $status);
        if (in_array($status, [401, 403], true)) {
            throw new RoboHubFehler('Der MyKobold-Kontoschlüssel ist abgelaufen — für eine neue Geräteliste bitte neu anmelden (die bekannten Roboter laufen weiter).');
        }
        $liste = json_decode($text, true);
        if ($status !== 200 || !is_array($liste)) {
            throw new RoboHubFehler('Vorwerk-Cloud liefert keine Roboterliste (HTTP ' . $status . ').');
        }
        $aus = [];
        foreach ($liste as $r) {
            $serial = is_array($r) ? (string)($r['serial'] ?? '') : '';
            $ende = is_array($r) ? (string)($r['nucleo_url'] ?? '') : '';
            // Nur Vorwerks eigener Roboter-Server (Homey-App ROBOT_HOSTS) — der Schlüssel geht an keinen fremden Host.
            if ($serial === '' || (string)($r['secret_key'] ?? '') === '' || !preg_match('#^https://nucleo\.ksecosys\.com(:\d+)?$#', rtrim($ende, '/'))) {
                continue;
            }
            $modell = (string)($r['model'] ?? '');
            $aus[$serial] = ['serial' => $serial, 'secret' => (string)$r['secret_key'], 'endpoint' => rtrim($ende, '/'),
                'name' => trim((string)($r['name'] ?? '')) ?: (stripos($modell, '200') !== false ? 'Kobold VR200' : 'Kobold VR300'), 'model' => $modell];
        }
        return $aus;
    }

    // =================================================================
    // Geräte und Zustand
    // =================================================================

    /** Roboter aus dem Bündel (Liste samt Schlüsseln stammt von der Anmeldung; neuer Roboter → neu anmelden). */
    public function geraete(array $buendel): array
    {
        $liste = [];
        foreach ((array)($buendel['roboter'] ?? []) as $r) {
            $liste[] = ['id' => $r['serial'], 'name' => $r['name'], 'modell' => 'vorwerk.vacuum.' . (strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '', (string)$r['model'])) ?: 'kobold'),
                'klasse' => RoboHubModell::KLASSE_SAUGER, 'online' => true, 'roh' => ['modell' => $r['model']]];
        }
        return $liste;
    }

    private function roboter(array $buendel, array $geraet): array
    {
        $r = $buendel['roboter'][(string)$geraet['id']] ?? null;
        if (!is_array($r)) {
            throw new RoboHubFehler('Dieser Kobold ist im Konto nicht (mehr) bekannt — bitte im Konto „Geräte jetzt suchen".');
        }
        return $r;
    }

    public function zustand(array $buendel, array $geraet): array
    {
        $p = $this->nachricht($this->roboter($buendel, $geraet), 'getRobotState');
        $daten = $this->normalisiere((string)($geraet['modell'] ?? ''), $p);
        $daten['online'] = true;
        $daten['bloecke'] = ['ok' => [0], 'fehl' => []];
        $daten['roh'] = ['modell' => $geraet['modell'] ?? null, 'properties' => $p];
        if (!empty($buendel['konto_abgelaufen'])) {
            $daten['werte']['Meldung'] = 'MyKobold-Kontoschlüssel abgelaufen — Steuerung läuft weiter, für neue Roboter bitte neu anmelden.';
        }
        return $daten;
    }

    /** getRobotState → RoboHub (robot-state.js normalizeRobotState). */
    public function normalisiere(string $modell, array $p): array
    {
        $w = [];
        $s = (int)($p['state'] ?? 0);
        $a = (int)($p['action'] ?? 0);
        $d = (array)($p['details'] ?? []);
        $fehler = trim((string)($p['error'] ?? ''));
        $fehler = $fehler === 'ui_alert_invalid' ? '' : $fehler;   // ältere Firmware meldet das im Normalbetrieb
        if ($s === 4 || $fehler !== '') {
            [$z, $t] = [RoboHubModell::ZUSTAND_FEHLER, 'Fehler'];
        } elseif ($s === 3 || ($s === 2 && in_array($a, self::PAUSE_AKTIONEN, true))) {
            [$z, $t] = [RoboHubModell::ZUSTAND_PAUSIERT, 'Pausiert'];
        } elseif ($s === 1 && !empty($d['isCharging'])) {
            [$z, $t] = [RoboHubModell::ZUSTAND_LAEDT, 'Lädt'];
        } elseif ($s === 1 && !empty($d['isDocked'])) {
            [$z, $t] = [RoboHubModell::ZUSTAND_GELADEN, 'In der Station'];
        } elseif ($s === 1) {
            [$z, $t] = [RoboHubModell::ZUSTAND_BEREIT, 'Bereit'];
        } elseif ($s === 2 && $a === 12) {
            [$z, $t] = [RoboHubModell::ZUSTAND_KARTIERUNG, self::REINIGEN[12]];
        } elseif ($s === 2 && isset(self::REINIGEN[$a])) {
            [$z, $t] = [RoboHubModell::ZUSTAND_REINIGT, self::REINIGEN[$a]];
        } elseif ($s === 2 && $a === 4) {
            [$z, $t] = [RoboHubModell::ZUSTAND_ZURUECK, 'Fährt zur Station'];
        } elseif ($s === 2 && $a === 7) {
            [$z, $t] = [RoboHubModell::ZUSTAND_AKTUALISIERUNG, 'Aktualisiert Firmware'];
        } else {
            [$z, $t] = [RoboHubModell::ZUSTAND_UNBEKANNT, 'Beschäftigt'];
        }
        $w['Zustand'] = $z;
        $w['ZustandRoh'] = $t . ' (' . $s . '/' . $a . ')';
        $w['Fehler'] = $fehler === '' ? 'kein Fehler' : self::fehlerText($fehler) . ' (' . $fehler . ')';
        if (isset($d['charge']) && is_numeric($d['charge'])) {
            $w['Akku'] = (int)$d['charge'];
        }
        $w['Laedt'] = !empty($d['isCharging']);
        $hinweis = trim((string)($p['alert'] ?? ''));
        if ($hinweis !== '' && $hinweis !== 'ui_alert_invalid') {
            $w['Meldung'] = self::HINWEISE[$hinweis] ?? $hinweis;
        }
        $w['Firmware'] = isset($p['meta']['firmware']) && (string)$p['meta']['firmware'] !== '' ? (string)$p['meta']['firmware'] : null;
        return ['werte' => $w, 'optionen' => [], 'schreibbar' => []];
    }

    public static function fehlerText(string $code): string
    {
        foreach (self::FEHLER_GRUPPEN as $anfang => $text) {
            if (strpos($code, $anfang) === 0) {
                return $text;
            }
        }
        return 'Fehler';
    }

    // =================================================================
    // Räume (VR300: gespeicherte Bereiche) und Befehle
    // =================================================================

    /** getMapBoundaries → [Nummer => Name]; Nummer = Position (Bereichs-IDs sind Texte). */
    public function karte(array $buendel, array $geraet): array
    {
        $raeume = [];
        foreach ($this->bereiche($this->roboter($buendel, $geraet))[1] as $i => $b) {
            $raeume[$i + 1] = $b['name'];
        }
        return ['raeume' => $raeume, 'bild' => '', 'marken' => [], 'station' => null];
    }

    /** [Karten-ID, [['id', 'name'], …]] — nur Flächen (polygon), keine Sperrlinien (Homey getAreas). */
    private function bereiche(array $r): array
    {
        $antwort = $this->nachricht($r, 'getMapBoundaries', ['mapId' => null], true);
        $bereiche = [];
        foreach ((array)($antwort['data']['boundaries'] ?? []) as $b) {
            if (is_array($b) && ($b['type'] ?? '') === 'polygon' && is_string($b['id'] ?? null) && trim((string)($b['name'] ?? '')) !== '') {
                $bereiche[$b['id']] = ['id' => $b['id'], 'name' => trim((string)$b['name'])];
            }
        }
        usort($bereiche, function ($x, $y) { return strcmp($x['name'] . $x['id'], $y['name'] . $y['id']); });
        return [(string)($antwort['data']['mapId'] ?? ''), $bereiche];
    }

    public function liveKarte(array $buendel, array $geraet): array
    {
        return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
    }

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        $r = $this->roboter($buendel, $geraet);
        $stand = (array)($geraet['stand'] ?? []);
        $dienst = (string)($stand['availableServices']['houseCleaning'] ?? '');
        switch ($befehl) {
            case 'start':
                $pausiert = (int)($stand['state'] ?? 0) === 3 || ((int)($stand['state'] ?? 0) === 2 && in_array((int)($stand['action'] ?? 0), self::PAUSE_AKTIONEN, true));
                if ($pausiert) {
                    [$cmd, $params, $text] = ['resumeCleaning', null, 'Fortsetzen'];
                } else {
                    [$cmd, $params, $text] = ['startCleaning', self::startParameter($dienst, null), 'Start'];
                }
                break;
            case 'pause':
                [$cmd, $params, $text] = ['pauseCleaning', null, 'Pause'];
                break;
            case 'stopp':
                [$cmd, $params, $text] = ['stopCleaning', null, 'Stopp'];
                break;
            case 'station':
                [$cmd, $params, $text] = ['sendToBase', null, 'Zur Station'];
                break;
            case 'orten':
                [$cmd, $params, $text] = ['findMe', null, 'Orten'];
                break;
            case 'raum':
                $nr = array_values(array_unique(array_map('intval', (array)($parameter['raeume'] ?? []))));
                if (count($nr) !== 1) {
                    throw new RoboHubFehler('Der Kobold reinigt immer genau einen gespeicherten Bereich — bitte einen wählen.');
                }
                if ($dienst === 'basic-1') {
                    throw new RoboHubFehler('Der VR200 kennt keine gespeicherten Bereiche.');
                }
                [$karte, $bereiche] = $this->bereiche($r);
                $b = $bereiche[$nr[0] - 1] ?? null;
                if ($b === null || $karte === '') {
                    throw new RoboHubFehler('Unbekannter Bereich ' . $nr[0] . ' — „Räume laden" im Roboter-Formular zeigt die gültigen.');
                }
                [$cmd, $params, $text] = ['startCleaning', self::startParameter($dienst, ['mapId' => $karte, 'boundaryId' => $b['id']]), 'Bereich „' . $b['name'] . '" reinigen'];
                break;
            default:
                throw new RoboHubFehler('Dieser Befehl wird beim Kobold nicht unterstützt: ' . $befehl);
        }
        $this->nachricht($r, $cmd, $params);
        return 'Befehl „' . $text . '" gesendet.';
    }

    /**
     * startCleaning-Parameter je houseCleaning-Dienst (robot.py start_cleaning, kobold-client.js cleaningParams):
     * basic-1 (VR200) nur Haus ohne Karte; sonst gespeicherte Karte (Kategorie 4) mit Turbo, normaler Navigation.
     */
    public static function startParameter(string $dienst, ?array $bereich): array
    {
        if ($dienst === 'basic-1') {
            return ['category' => 2, 'mode' => 2, 'modifier' => 1];
        }
        if ($dienst === 'minimal-2') {
            return ['category' => 4, 'navigationMode' => 1] + (array)$bereich;
        }
        return ['category' => 4, 'mode' => 2, 'modifier' => 1, 'navigationMode' => 1] + (array)$bereich;
    }

    public function zuruecksetzen(array $buendel, array $geraet, string $teil): string
    {
        throw new RoboHubFehler('Verschleißteile zurücksetzen geht beim Kobold nur in der MyKobold-App.');
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
    // Signierte Nachricht an den Roboter (robot.py Auth, kobold-client.js _message)
    // =================================================================

    /**
     * Signatur: HMAC-SHA256(secret, „serial(klein)\nDatum\nKörper"), Datum im HTTP-Format (RFC 7231).
     * $nurDaten: Antwort ohne Zustandsprüfung (z. B. getMapBoundaries).
     */
    private function nachricht(array $r, string $cmd, ?array $params = null, bool $nurDaten = false): array
    {
        $body = ['reqId' => '1', 'cmd' => $cmd];
        if ($params !== null) {
            $body['params'] = $params;
        }
        $text = (string)json_encode($body);
        $datum = gmdate('D, d M Y H:i:s') . ' GMT';
        $signatur = hash_hmac('sha256', strtolower((string)$r['serial']) . "\n" . $datum . "\n" . $text, (string)$r['secret']);
        [$status, $antwort] = $this->http('POST', $r['endpoint'] . '/vendors/vorwerk/robots/' . rawurlencode((string)$r['serial']) . '/messages', [
            'Accept: application/vnd.neato.nucleo.v1', 'Content-Type: application/json', 'Date: ' . $datum, 'Authorization: NEATOAPP ' . $signatur,
        ], $text);
        $this->dbg('HTTP', 'POST ' . $cmd . ' → ' . $status);
        if ($status === 0) {
            throw new RoboHubFehler('Vorwerk-Roboterserver nicht erreichbar: ' . $antwort);
        }
        if (in_array($status, [401, 403], true)) {
            $e = new RoboHubFehler('Der Roboter-Schlüssel wurde abgelehnt — bitte im Konto neu anmelden.');
            $e->anmeldungNoetig = true;
            throw $e;
        }
        if ($status === 404) {
            throw new RoboHubFehler('Der Kobold ist gerade nicht mit der Cloud verbunden (offline?).');
        }
        if ($status === 429) {
            $e = new RoboHubFehler('Vorwerk bremst die Anfragen — RoboHub wartet.');
            $e->warteSekunden = 600;
            throw $e;
        }
        $j = json_decode($antwort, true);
        if ($status >= 300 || !is_array($j)) {
            throw new RoboHubFehler('Vorwerk-Roboterserver antwortet mit HTTP ' . $status . '.');
        }
        if (($j['result'] ?? 'ok') !== 'ok') {
            throw new RoboHubFehler('Der Kobold lehnt „' . $cmd . '" ab (' . $j['result'] . ').');
        }
        if (!$nurDaten && $cmd === 'getRobotState' && !isset($j['state'])) {
            throw new RoboHubFehler('Der Kobold hat keinen gültigen Zustand geliefert.');
        }
        return $j;
    }

    /** curl wie die anderen Treiber (Zertifikat wird geprüft); im Prüfstand ersetzbar. */
    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $methode,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
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
