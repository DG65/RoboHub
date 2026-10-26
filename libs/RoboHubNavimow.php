<?php

declare(strict_types=1);

// ===========================================================================
// RoboHub-Treiber für Segway Navimow — Segways Smart-Home-Schnittstelle.
//
// Quellen: niddu85/home-assistant-navimow (MIT, Stand 26.10.2026: const.py,
// api.py, lawn_mower.py, sensor.py, coordinator.py, tests/conftest.py) und
// ioBroker.navimow (MIT: main.js COMMAND_MAP, Code-Übernahme aus der
// Weiterleitungsadresse, lib/states.json).
//
// Anmeldung (Dietmars Entscheidung 26.10.2026, „wie ioBroker, offen
// dokumentiert"): Segway gibt für Smart-Home-Systeme die OAuth-Kennung
// „homeassistant" samt Geheimnis öffentlich heraus; eine eigene für RoboHub
// gibt es nicht. RoboHub nutzt sie offen und sagt das in Formular und README.
// Ablauf wie ioBroker: Anmeldelink öffnen, bei Segway anmelden, der Browser
// landet auf http://localhost:1/callback?code=… (Seite lädt nicht — gewollt),
// diese Adresse oder nur den Code ins Passwortfeld. Kein Passwort bei RoboHub.
// Der Erneuerungsschlüssel rotiert (immer den neuen speichern).
//
// Noch nicht gebaut: Echtzeit (MQTT über WebSocket mit Bearer-Kopf, Zugang an
// den OAuth-Schlüssel gebunden), damit auch Position/Fortschritt/Zone (nur per
// MQTT). Einstellungen bietet die Schnittstelle nicht.
// ===========================================================================

class RoboHubNavimow implements RoboHubTreiber
{
    /** @var callable|null fn(string $thema, string $text) */
    public $debug = null;

    public const CLIENT_ID = 'homeassistant';
    private const CLIENT_SECRET = '57056e15-722e-42be-bbaa-b0cbfb208a52';
    private const TOKEN = 'https://navimow-fra.ninebot.com/openapi/oauth/getAccessToken';
    private const LOGIN = 'https://navimow-h5-fra.willand.com/smartHome/login';
    private const API = 'https://navimow-fra.ninebot.com/openapi/smarthome/';
    public const WEITERLEITUNG = 'http://localhost:1/callback';

    // Zugang abgelehnt (api.py AUTH_ERROR_CODES/AUTH_ERROR_DESCS).
    private const AUTH_CODES = [4005];
    private const AUTH_TEXTE = ['TOKEN_EXPIRED', 'CODE_OAUTH_INFO_ILLEGAL'];

    // vehicleState (lawn_mower.py, ioBroker lib/states.json) → [RoboHub-Zustand, Klartext].
    private const ZUSTAENDE = [
        'isdocked'         => [RoboHubModell::ZUSTAND_GELADEN, 'In der Station'],
        'isidel'           => [RoboHubModell::ZUSTAND_BEREIT, 'Bereit'],
        'isidle'           => [RoboHubModell::ZUSTAND_BEREIT, 'Bereit'],
        'self-checking'    => [RoboHubModell::ZUSTAND_BEREIT, 'Selbsttest'],
        'ismapping'        => [RoboHubModell::ZUSTAND_KARTIERUNG, 'Kartiert'],
        'isrunning'        => [RoboHubModell::ZUSTAND_REINIGT, 'Mäht'],
        'ispaused'         => [RoboHubModell::ZUSTAND_PAUSIERT, 'Pausiert'],
        'insoftwareupdate' => [RoboHubModell::ZUSTAND_AKTUALISIERUNG, 'Aktualisiert'],
        'isdocking'        => [RoboHubModell::ZUSTAND_ZURUECK, 'Fährt zur Station'],
        'error'            => [RoboHubModell::ZUSTAND_FEHLER, 'Fehler'],
        'islifted'         => [RoboHubModell::ZUSTAND_FEHLER, 'Angehoben'],
        'offline'          => [RoboHubModell::ZUSTAND_UNBEKANNT, 'Offline'],
    ];

    // Befehle (ioBroker COMMAND_MAP, HA lawn_mower.py).
    private const BEFEHLE = [
        'start'    => ['action.devices.commands.StartStop', ['on' => true], 'Start'],
        'weiter'   => ['action.devices.commands.PauseUnpause', ['on' => true], 'Fortsetzen'],
        'pause'    => ['action.devices.commands.PauseUnpause', ['on' => false], 'Pause'],
        'stopp'    => ['action.devices.commands.StartStop', ['on' => false], 'Stopp'],
        'station'  => ['action.devices.commands.Dock', null, 'Zur Station'],
    ];

    // =================================================================
    // Anmeldung
    // =================================================================

    /** Adresse der Segway-Anmeldeseite (HA build_authorize_url, Weiterleitung wie ioBroker). */
    public function anmeldeLink(): string
    {
        return self::LOGIN . '?' . http_build_query([
            'channel'       => 'homeassistant',
            'client_id'     => self::CLIENT_ID,
            'response_type' => 'code',
            'redirect_uri'  => self::WEITERLEITUNG,
            'state'         => bin2hex(random_bytes(8)),
        ]);
    }

    /** $passwort = Code oder die ganze Adresse, auf der der Browser gelandet ist. */
    public function anmelden(string $konto, string $passwort, string $region): array
    {
        $code = self::codeAus($passwort);
        if ($code === '') {
            throw new RoboHubFehler('Bitte die Adresse, auf der der Browser nach der Segway-Anmeldung gelandet ist (oder nur den Code daraus), ins Feld „Code" eintragen.');
        }
        return $this->tokenHolen(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::WEITERLEITUNG], []);
    }

    /** Code aus einer eingefügten Adresse (ioBroker: searchParams.get('code')) oder der Code selbst. */
    public static function codeAus(string $eingabe): string
    {
        $eingabe = trim($eingabe);
        if (preg_match('#^https?://#i', $eingabe)) {
            parse_str((string)parse_url($eingabe, PHP_URL_QUERY), $q);
            return trim((string)($q['code'] ?? ''));
        }
        return $eingabe;
    }

    public function erneuern(array $buendel): array
    {
        $erzwingen = is_file($this->erneuernDatei($buendel));
        if (!$erzwingen && (int)($buendel['expires_at'] ?? 0) - 300 > time()) {
            return $buendel;
        }
        $neu = $this->tokenHolen(['grant_type' => 'refresh_token', 'refresh_token' => (string)($buendel['refresh_token'] ?? '')], $buendel);
        @unlink($this->erneuernDatei($buendel));
        return $neu;
    }

    private function tokenHolen(array $felder, array $alt): array
    {
        $felder += ['client_id' => self::CLIENT_ID, 'client_secret' => self::CLIENT_SECRET];
        [$status, $text] = $this->http('POST', self::TOKEN, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query($felder));
        $this->dbg('HTTP', 'POST oauth/getAccessToken (' . $felder['grant_type'] . ') → ' . $status);
        if ($status === 0 || $status >= 500) {
            throw new RoboHubFehler('Navimow nicht erreichbar' . ($status === 0 ? ': ' . $text : ' (HTTP ' . $status . ')') . '.');
        }
        $j = json_decode($text, true);
        if (!is_array($j) || (string)($j['access_token'] ?? '') === '') {
            // Nur die Fehlerfelder nennen — die Antwort kann Zugangsdaten zurückspiegeln (HA api.py).
            $grund = is_array($j) ? (string)($j['error'] ?? $j['desc'] ?? '') : '';
            $e = new RoboHubFehler($felder['grant_type'] === 'authorization_code'
                ? 'Segway lehnt den Code ab' . ($grund !== '' ? ' (' . $grund . ')' : '') . ' — der Code gilt nur kurz und nur einmal. Bitte den Anmeldelink neu öffnen.'
                : 'Segway lehnt die Erneuerung ab' . ($grund !== '' ? ' (' . $grund . ')' : '') . ' — bitte neu anmelden.');
            $e->anmeldungNoetig = true;
            throw $e;
        }
        return [
            'access_token'  => (string)$j['access_token'],
            // Rotiert; fehlt er in der Antwort, bleibt der alte (HA fallback_refresh_token).
            'refresh_token' => (string)($j['refresh_token'] ?? $alt['refresh_token'] ?? ''),
            'expires_at'    => time() + max(300, (int)($j['expires_in'] ?? 3600)),
        ];
    }

    /** Merkzeichen „Schlüssel vorzeitig abgelehnt" → beim nächsten erneuern() sofort erneuern. */
    private function erneuernDatei(array $buendel): string
    {
        return sys_get_temp_dir() . '/robohub-navimow-erneuern-' . substr(md5((string)($buendel['refresh_token'] ?? '')), 0, 12);
    }

    // =================================================================
    // Geräte und Zustand
    // =================================================================

    public function geraete(array $buendel): array
    {
        $liste = [];
        foreach ((array)($this->anfrage($buendel, 'GET', 'authList', null)['payload']['devices'] ?? []) as $d) {
            if (!is_array($d) || (string)($d['id'] ?? '') === '') {
                continue;
            }
            $modell = (string)($d['model'] ?? '');
            $liste[] = [
                'id'     => (string)$d['id'],
                'name'   => (string)($d['name'] ?? 'Navimow'),
                'modell' => 'navimow.mower.' . (preg_replace('/[^a-z0-9]+/', '', strtolower($modell)) ?: 'navimow'),
                'klasse' => RoboHubModell::KLASSE_MAEHER,
                'online' => true,
                'roh'    => ['modell' => $modell, 'firmware' => $d['firmware'] ?? null],
            ];
        }
        return $liste;
    }

    public function zustand(array $buendel, array $geraet): array
    {
        $id = (string)$geraet['id'];
        $p = [];
        foreach ((array)($this->anfrage($buendel, 'POST', 'getVehicleStatus', ['devices' => [['id' => $id]]])['payload']['devices'] ?? []) as $d) {
            if (is_array($d) && (string)($d['id'] ?? '') === $id) {
                $p = $d;
            }
        }
        if ($p === []) {
            throw new RoboHubFehler('Mäher ' . $id . ' fehlt in der Navimow-Antwort — ist er noch mit dem Konto verbunden?');
        }
        $daten = $this->normalisiere((string)($geraet['modell'] ?? ''), $p);
        $zustand = strtolower((string)($p['vehicleState'] ?? ''));
        $daten['online'] = ($p['online'] ?? true) !== false && $zustand !== 'offline';
        $daten['bloecke'] = ['ok' => [0], 'fehl' => []];
        $daten['roh'] = ['modell' => $geraet['modell'] ?? null, 'properties' => $p];
        return $daten;
    }

    public function normalisiere(string $modell, array $p): array
    {
        $w = [];
        $roh = (string)($p['vehicleState'] ?? '');
        if ($roh !== '') {
            [$z, $text] = self::ZUSTAENDE[strtolower($roh)] ?? [RoboHubModell::ZUSTAND_UNBEKANNT, 'Unbekannt'];
            $w['Zustand'] = $z;
            $w['ZustandRoh'] = $text . ' (' . $roh . ')';
            $w['Fehler'] = $z === RoboHubModell::ZUSTAND_FEHLER ? $text : 'kein Fehler';
        }
        // „battery" kommt nur per MQTT und ist dann neuer (HA sensor.py), sonst capacityRemaining.
        $akku = $p['battery'] ?? ($p['capacityRemaining'][0]['rawValue'] ?? null);
        $w['Akku'] = is_numeric($akku) ? (int)round((float)$akku) : null;
        return ['werte' => $w, 'optionen' => [], 'schreibbar' => []];
    }

    // =================================================================
    // Befehle
    // =================================================================

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        if ($befehl === 'start' && strtolower((string)($geraet['stand']['vehicleState'] ?? '')) === 'ispaused') {
            $befehl = 'weiter';   // HA async_start_mowing: aus der Pause fortsetzen statt neu starten
        }
        if (!isset(self::BEFEHLE[$befehl])) {
            throw new RoboHubFehler(($befehl === 'orten' ? '„Orten"' : ($befehl === 'raum' ? 'Zonen mähen' : 'Dieser Befehl (' . $befehl . ')'))
                . ' bietet Segways Smart-Home-Schnittstelle nicht — bitte in der Navimow-App.');
        }
        [$kommando, $param, $text] = self::BEFEHLE[$befehl];
        $ausfuehrung = ['command' => $kommando];
        if ($param !== null) {
            $ausfuehrung['params'] = $param;
        }
        $antwort = $this->anfrage($buendel, 'POST', 'sendCommands', ['commands' => [['devices' => [['id' => (string)$geraet['id']]], 'execution' => $ausfuehrung]]]);
        // code 1 heißt nur „angenommen"; ob der Mäher es ausführt, steht je Gerät (ioBroker sendMowerCommand).
        foreach ((array)($antwort['payload']['commands'] ?? []) as $r) {
            $s = (string)($r['status'] ?? 'SUCCESS');
            if ($s !== 'SUCCESS' && (string)($r['errorCode'] ?? '') !== 'alreadyInState') {
                throw new RoboHubFehler('Der Mäher hat „' . $text . '" abgelehnt (' . $s . (($r['errorCode'] ?? '') !== '' ? ', ' . $r['errorCode'] : '') . ').');
            }
        }
        return 'Befehl „' . $text . '" gesendet.';
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
        throw new RoboHubFehler('Verschleißteile zurücksetzen bietet Segways Smart-Home-Schnittstelle nicht — bitte in der Navimow-App.');
    }

    /** Keine Echtzeit (siehe Kopf) — der Client Socket am Konto bleibt geschlossen. */
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

    /** Antwort {code: 1, data: {...}} → data. */
    private function anfrage(array $buendel, string $methode, string $pfad, ?array $body): array
    {
        $kopf = [
            'Authorization: Bearer ' . $buendel['access_token'],
            'Content-Type: application/json',
            'Accept: application/json',
            'requestId: ' . bin2hex(random_bytes(16)),
        ];
        [$status, $text] = $this->http($methode, self::API . $pfad, $kopf, $body !== null ? (string)json_encode($body) : null);
        $this->dbg('HTTP', $methode . ' ' . $pfad . ' → ' . $status);
        if ($status === 0 || $status >= 500) {
            throw new RoboHubFehler('Navimow nicht erreichbar' . ($status === 0 ? ': ' . $text : ' (HTTP ' . $status . ')') . '.');
        }
        $j = json_decode($text, true);
        $j = is_array($j) ? $j : [];
        if ($status === 401 || in_array($j['code'] ?? null, self::AUTH_CODES, true) || in_array((string)($j['desc'] ?? ''), self::AUTH_TEXTE, true)) {
            // Schlüssel vorzeitig abgelehnt: nicht abmelden, beim nächsten Mal mit dem Erneuerungsschlüssel neu holen (HA _async_request).
            @touch($this->erneuernDatei($buendel));
            throw new RoboHubFehler('Navimow hat den Zugangsschlüssel abgelehnt — RoboHub erneuert ihn beim nächsten Abruf.');
        }
        if ($status === 429) {
            $e = new RoboHubFehler('Navimow bremst die Anfragen — RoboHub wartet.');
            $e->warteSekunden = 600;
            throw $e;
        }
        if (($j['code'] ?? null) !== 1) {
            throw new RoboHubFehler('Navimow antwortet mit Fehler ' . ($j['code'] ?? 'HTTP ' . $status) . (isset($j['desc']) ? ': ' . $j['desc'] : '') . '.');
        }
        return (array)($j['data'] ?? []);
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
