<?php

declare(strict_types=1);

// ===========================================================================
// RoboHub-Treiber für Bosch Indego (Bosch-Cloud, App „Bosch Smart Gardening").
//
// Quellen: sander1988/pyIndego (MIT, const.py, states.py,
// indego_async_client.py, tests/test_indego.py), sander1988/Indego
// (Apache-2.0, const.py, application_credentials.py, config_flow.py,
// __init__.py) und iobroker-community-adapters/ioBroker.boschindego (MIT,
// Anleitung „Adresse aus der Entwicklerkonsole kopieren").
//
// Anmeldung: Bosch SingleKey ID (Azure B2C) mit der Client-ID der Bosch-App.
// Nach der Anmeldung leitet Bosch auf die App-Adresse
// com.bosch.indegoconnect://login?code=… um; der Browser kann sie nicht
// öffnen und zeigt sie in der Entwicklerkonsole (F12). Diese Adresse fügt der
// Nutzer ins Feld „Code" ein — wie bei ioBroker. Kein Passwort bei RoboHub.
// Live geprüft (26.10.2026, ohne Konto): Bosch nimmt diese Rücksprungadresse
// ohne PKCE an, eine fremde (http://localhost/…) lehnt es ab (AADB2C90006).
//
// Boschs Firewall weist manche User-Agents ab (pyIndego-Issue 119) → eigener.
// Akku: operatingData nur beim Mähen/Laden abfragen (wie HA), sonst bleibt der
// letzte Wert; Indego 1000/1100/1200/10C/13C melden Spannung statt Prozent.
// Nicht gebaut: Kartenbild (SVG), Kalender, SmartMowing, Meldungen.
// ===========================================================================

class RoboHubIndego implements RoboHubTreiber
{
    /** @var callable|null fn(string $thema, string $text) */
    public $debug = null;

    private const CLIENT_ID = '65bb8c9d-1070-4fb4-aa95-853618acc876';
    private const B2C = 'https://prodindego.b2clogin.com/prodindego.onmicrosoft.com/b2c_1a_signup_signin/oauth2/v2.0/';
    private const SCOPE = 'openid profile email offline_access https://prodindego.onmicrosoft.com/indego-mobile-api/Indego.Mower.User';
    public const WEITERLEITUNG = 'com.bosch.indegoconnect://login';
    private const API = 'https://api.indego-cloud.iot.bosch-si.com/api/v1/';
    private const AGENT = 'RoboHub/Indego';

    // Spannungsbereich der Modelle, die Volt statt Prozent melden (pyIndego MOWER_MODEL_VOLTAGE).
    private const SPANNUNG = ['3600HA2300' => [285, 369], '3600HA2301' => [285, 369], '3600HA2302' => [285, 369], '3600HA2303' => [285, 369], '3600HA2304' => [285, 369]];

    // pyIndego MOWER_MODEL_DESCRIPTION.
    private const MODELLE = [
        '3600HA2300' => 'Indego 1000', '3600HA2301' => 'Indego 1200', '3600HA2302' => 'Indego 1100', '3600HA2303' => 'Indego 13C',
        '3600HA2304' => 'Indego 10C', '3600HB0100' => 'Indego 350', '3600HB0101' => 'Indego 400', '3600HB0102' => 'Indego S+ 350',
        '3600HB0103' => 'Indego S+ 400', '3600HB0105' => 'Indego S+ 350', '3600HB0106' => 'Indego S+ 400', '3600HB0302' => 'Indego S+ 500',
        '3600HB0301' => 'Indego M+ 700', '3600HB0303' => 'Indego M+ 700',
    ];

    // pyIndego MOWER_STATE_DESCRIPTION_DETAIL → [RoboHub-Zustand, Klartext].
    private const ZUSTAENDE = [
        0 => [RoboHubModell::ZUSTAND_UNBEKANNT, 'Reading status'],
        101 => [RoboHubModell::ZUSTAND_FEHLER, 'Angehoben'],
        257 => [RoboHubModell::ZUSTAND_LAEDT, 'Charging'],
        258 => [RoboHubModell::ZUSTAND_GELADEN, 'Docked'],
        259 => [RoboHubModell::ZUSTAND_AKTUALISIERUNG, 'In the station – software update'],
        260 => [RoboHubModell::ZUSTAND_LAEDT, 'Charging'],
        261 => [RoboHubModell::ZUSTAND_GELADEN, 'Docked'],
        262 => [RoboHubModell::ZUSTAND_GELADEN, 'In the station – loading map'],
        263 => [RoboHubModell::ZUSTAND_GELADEN, 'In the station – saving map'],
        266 => [RoboHubModell::ZUSTAND_REINIGT, 'Leaving the station'],
        512 => [RoboHubModell::ZUSTAND_REINIGT, 'Leaving the station'],
        513 => [RoboHubModell::ZUSTAND_REINIGT, 'Mowing'],
        514 => [RoboHubModell::ZUSTAND_REINIGT, 'Mowing – orienting'],
        515 => [RoboHubModell::ZUSTAND_REINIGT, 'Mowing – loading map'],
        516 => [RoboHubModell::ZUSTAND_KARTIERUNG, 'Learning the lawn'],
        517 => [RoboHubModell::ZUSTAND_PAUSIERT, 'Pausiert'],
        518 => [RoboHubModell::ZUSTAND_REINIGT, 'Kantenschnitt'],
        519 => [RoboHubModell::ZUSTAND_BEREIT, 'Standing on the lawn'],
        520 => [RoboHubModell::ZUSTAND_PAUSIERT, 'Lawn learning paused'],
        521 => [RoboHubModell::ZUSTAND_REINIGT, 'Kantenschnitt'],
        523 => [RoboHubModell::ZUSTAND_REINIGT, 'Spot mowing'],
        524 => [RoboHubModell::ZUSTAND_REINIGT, 'Mowing – random'],
        525 => [RoboHubModell::ZUSTAND_REINIGT, 'Mowing – random, finished'],
        768 => [RoboHubModell::ZUSTAND_ZURUECK, 'Returning to base'],
        769 => [RoboHubModell::ZUSTAND_ZURUECK, 'Returning to base'],
        770 => [RoboHubModell::ZUSTAND_ZURUECK, 'Returning to base'],
        771 => [RoboHubModell::ZUSTAND_ZURUECK, 'Returning to base – battery low'],
        772 => [RoboHubModell::ZUSTAND_ZURUECK, 'Returning to base – time window over'],
        773 => [RoboHubModell::ZUSTAND_ZURUECK, 'Returning to base – battery temperature'],
        774 => [RoboHubModell::ZUSTAND_ZURUECK, 'Returning to base – on request'],
        775 => [RoboHubModell::ZUSTAND_ZURUECK, 'Returning to base – lawn finished'],
        776 => [RoboHubModell::ZUSTAND_ZURUECK, 'Returning to base – orienting'],
        1005 => [RoboHubModell::ZUSTAND_FEHLER, 'Station not reached'],
        1025 => [RoboHubModell::ZUSTAND_FEHLER, 'Diagnosemodus'],
        1026 => [RoboHubModell::ZUSTAND_FEHLER, 'Lebensende'],
        1027 => [RoboHubModell::ZUSTAND_FEHLER, 'Service is querying status'],
        1038 => [RoboHubModell::ZUSTAND_FEHLER, 'Mower locked'],
        1281 => [RoboHubModell::ZUSTAND_AKTUALISIERUNG, 'Software-Update'],
        1537 => [RoboHubModell::ZUSTAND_FEHLER, 'Stuck, help needed'],
        64513 => [RoboHubModell::ZUSTAND_SCHLAF, 'Sleeping'],
        99999 => [RoboHubModell::ZUSTAND_UNBEKANNT, 'Offline'],
    ];

    // pyIndego ALERT_ERROR_CODE (Auszug, deutsch).
    private const FEHLER = [
        104 => 'STOP button pressed', 101 => 'Lifted', 115 => 'Stuck', 1008 => 'Stuck',
        149 => 'Outside the boundary wire', 151 => 'No wire signal', 1005 => 'Station not reached',
        1108 => 'Slope too steep', 1138 => 'Mower needs help',
    ];

    private const BEFEHLE = ['start' => ['mow', 'Mow'], 'pause' => ['pause', 'Pause'], 'station' => ['returnToDock', 'Return to base']];

    // =================================================================
    // Anmeldung
    // =================================================================

    /** Bosch-Anmeldeseite (HA config_flow extra_authorize_data + application_credentials). */
    public function anmeldeLink(): string
    {
        return self::B2C . 'authorize?' . http_build_query([
            'client_id'     => self::CLIENT_ID,
            'response_type' => 'code',
            'redirect_uri'  => self::WEITERLEITUNG,
            'scope'         => self::SCOPE,
            'state'         => bin2hex(random_bytes(8)),
        ]);
    }

    /** $passwort = Adresse com.bosch.indegoconnect://login?…code=… aus der Konsole, oder nur der Code. */
    public function anmelden(string $konto, string $passwort, string $region): array
    {
        $code = self::codeAus($passwort);
        if ($code === '') {
            throw new RoboHubFehler(RoboHubText::t('Please enter the address "com.bosch.indegoconnect://login?…" from the browser\'s developer console (or just the code from it) into the "Code" field.'));
        }
        return $this->tokenHolen(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::WEITERLEITUNG], []);
    }

    /** Code aus der eingefügten Adresse (auch aus einer ganzen Konsolenzeile) oder der Code selbst. */
    public static function codeAus(string $eingabe): string
    {
        $eingabe = trim($eingabe, " \t\n\r\0\x0B'\"");
        if (preg_match('#indegoconnect[^?\s]*\?(\S+)#i', $eingabe, $m)) {
            parse_str(rtrim($m[1], "'\".,"), $q);
            return trim((string)($q['code'] ?? ''));
        }
        return preg_match('/^[A-Za-z0-9._~\-]+$/', $eingabe) ? $eingabe : '';
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

    /** Merkzeichen „Schlüssel vorzeitig abgelehnt" → beim nächsten erneuern() sofort erneuern. */
    private function erneuernDatei(array $buendel): string
    {
        return sys_get_temp_dir() . '/robohub-indego-erneuern-' . substr(md5((string)($buendel['refresh_token'] ?? '')), 0, 12);
    }

    private function tokenHolen(array $felder, array $alt): array
    {
        $felder += ['client_id' => self::CLIENT_ID, 'scope' => self::SCOPE];
        [$status, $text] = $this->http('POST', self::B2C . 'token', ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query($felder));
        $this->dbg('HTTP', 'POST oauth2/v2.0/token (' . $felder['grant_type'] . ') → ' . $status);
        if ($status === 0 || $status >= 500) {
            throw new RoboHubFehler(RoboHubText::t('Bosch sign-in not reachable%s.', ($status === 0 ? ': ' . $text : ' (HTTP ' . $status . ')')));
        }
        $j = json_decode($text, true);
        if ($status !== 200 || !is_array($j) || (string)($j['access_token'] ?? '') === '') {
            // Nur den Fehlercode nennen (AADB2C…), nicht die ganze Antwort.
            $grund = is_array($j) ? (string)($j['error'] ?? '') : '';
            if (is_array($j) && preg_match('/AADB2C\d+/', (string)($j['error_description'] ?? ''), $m)) {
                $grund .= ($grund !== '' ? ', ' : '') . $m[0];
            }
            $e = new RoboHubFehler($felder['grant_type'] === 'authorization_code'
                ? RoboHubText::t('Bosch rejects the code%s — it is only valid for a few minutes and only once. Please open the sign-in page again.', ($grund !== '' ? ' (' . $grund . ')' : ''))
                : RoboHubText::t('Bosch rejects the renewal%s — please sign in again.', ($grund !== '' ? ' (' . $grund . ')' : '')));
            $e->anmeldungNoetig = true;
            throw $e;
        }
        return [
            'access_token'  => (string)$j['access_token'],
            'refresh_token' => (string)($j['refresh_token'] ?? $alt['refresh_token'] ?? ''),
            'expires_at'    => time() + max(300, (int)($j['expires_in'] ?? 3600)),
        ];
    }

    // =================================================================
    // Geräte und Zustand
    // =================================================================

    public function geraete(array $buendel): array
    {
        $liste = [];
        foreach ((array)$this->anfrage($buendel, 'GET', 'alms') as $m) {
            $sn = is_array($m) ? (string)($m['alm_sn'] ?? '') : '';
            if ($sn === '') {
                continue;
            }
            $g = (array)$this->anfrage($buendel, 'GET', 'alms/' . rawurlencode($sn));
            $nummer = (string)($g['bareToolnumber'] ?? '');
            $name = trim((string)($g['alm_name'] ?? $m['alm_name'] ?? ''));
            $liste[] = [
                'id'     => $sn,
                'name'   => $name !== '' ? $name : (self::MODELLE[$nummer] ?? 'Indego') . ' ' . $sn,
                'modell' => 'indego.mower.' . (strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $nummer)) ?: 'indego'),
                'klasse' => RoboHubModell::KLASSE_MAEHER,
                'online' => true,
                'roh'    => ['modell' => self::MODELLE[$nummer] ?? $nummer, 'firmware' => $g['alm_firmware_version'] ?? null],
            ];
        }
        return $liste;
    }

    public function zustand(array $buendel, array $geraet): array
    {
        $sn = rawurlencode((string)$geraet['id']);
        $p = (array)$this->anfrage($buendel, 'GET', 'alms/' . $sn . '/state');
        if (!isset($p['state'])) {
            throw new RoboHubFehler(RoboHubText::t('Bosch delivers no state for %s — is the mower still in the account?', $geraet['id']));
        }
        $s = (int)$p['state'];
        // Akku nur beim Mähen/Laden frisch (HA __init__: 500–799, 257, 260) oder ohne bekannten Wert.
        $alt = (array)($geraet['stand'] ?? []);
        if (($s >= 500 && $s <= 799) || in_array($s, [257, 260], true) || !isset($alt['_akku'])) {
            $b = (array)(($this->anfrage($buendel, 'GET', 'alms/' . $sn . '/operatingData'))['battery'] ?? []);
            if (isset($b['percent']) && is_numeric($b['percent'])) {
                $p['_akku'] = self::akkuProzent((float)$b['percent'], (string)($geraet['modell'] ?? ''));
            }
        } else {
            $p['_akku'] = $alt['_akku'];
        }
        $daten = $this->normalisiere((string)($geraet['modell'] ?? ''), $p);
        $daten['online'] = $s !== 99999;
        $daten['bloecke'] = ['ok' => [0], 'fehl' => []];
        $daten['roh'] = ['modell' => $geraet['modell'] ?? null, 'properties' => $p];
        return $daten;
    }

    /** Volt-Modelle (Indego 1000 & Co.) auf Prozent umrechnen (pyIndego update_percent_adjusted). */
    public static function akkuProzent(float $wert, string $modell): int
    {
        $nummer = strtoupper((string)preg_replace('/^indego\.mower\./', '', $modell));
        if (isset(self::SPANNUNG[$nummer])) {
            [$min, $max] = self::SPANNUNG[$nummer];
            $wert = ($wert - $min) / (($max - $min) / 100);
        }
        return (int)max(0, min(100, round($wert)));
    }

    public function normalisiere(string $modell, array $p): array
    {
        $w = [];
        if (isset($p['state'])) {
            $s = (int)$p['state'];
            [$z, $text] = self::ZUSTAENDE[$s] ?? [RoboHubModell::ZUSTAND_UNBEKANNT, 'Unknown'];
            $text = RoboHubText::wert($text);
            $w['Zustand'] = $z;
            $w['ZustandRoh'] = $text . ' (' . $s . ')';
            $code = (int)($p['error'] ?? 0);
            if ($code !== 0) {
                $w['Fehler'] = RoboHubText::wert(self::FEHLER[$code] ?? 'Error code') . ' (' . $code . ')';
            } else {
                $w['Fehler'] = $z === RoboHubModell::ZUSTAND_FEHLER ? $text : RoboHubText::t('no error');
            }
        }
        if (isset($p['mowed']) && is_numeric($p['mowed'])) {
            $w['Fortschritt'] = (int)$p['mowed'];
        }
        $gesamt = (array)($p['runtime']['total'] ?? []);
        if (isset($gesamt['operate']) && is_numeric($gesamt['operate'])) {
            // Hundertstelstunden; Mähzeit = Betrieb − Laden (pyIndego Runtime/RuntimeDetail.update_cut).
            $w['GesamtDauer'] = (int)round(((float)$gesamt['operate'] - (float)($gesamt['charge'] ?? 0)) / 100);
        }
        if (isset($p['_akku'])) {
            $w['Akku'] = (int)$p['_akku'];
        }
        return ['werte' => $w, 'optionen' => [], 'schreibbar' => []];
    }

    // =================================================================
    // Befehle
    // =================================================================

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        if (!isset(self::BEFEHLE[$befehl])) {
            throw new RoboHubFehler(($befehl === 'stopp' ? RoboHubText::t('Indego has no "Stop" — use "Pause" or "Return to base".')
                : ($befehl === 'orten' ? RoboHubText::t('The Bosch cloud does not offer "Locate".') : ($befehl === 'raum' ? RoboHubText::t('The Bosch cloud does not offer mowing zones.') : RoboHubText::t('This command (%s) is not offered by the Bosch cloud.', $befehl)))));
        }
        [$kommando, $text] = self::BEFEHLE[$befehl];
        $text = RoboHubText::wert($text);
        $this->anfrage($buendel, 'PUT', 'alms/' . rawurlencode((string)$geraet['id']) . '/state', ['state' => $kommando]);
        return RoboHubText::t('Command "%s" sent.', $text);
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
        throw new RoboHubFehler(RoboHubText::t('Resetting wear parts on Indego is only possible in the app.'));
    }

    /** Keine Echtzeit — der Client Socket am Konto bleibt geschlossen. */
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

    /** JSON-Antwort (Liste oder Objekt); 200 ohne JSON (Befehle) = []. */
    private function anfrage(array $buendel, string $methode, string $pfad, ?array $body = null): array
    {
        $kopf = ['Authorization: Bearer ' . $buendel['access_token'], 'Content-Type: application/json', 'Accept: application/json', 'User-Agent: ' . self::AGENT];
        [$status, $text] = $this->http($methode, self::API . $pfad, $kopf, $body !== null ? (string)json_encode($body) : null);
        $this->dbg('HTTP', $methode . ' ' . $pfad . ' → ' . $status);
        if ($status === 0 || $status >= 500) {
            throw new RoboHubFehler(RoboHubText::t('Bosch cloud not reachable%s.', ($status === 0 ? ': ' . $text : ' (HTTP ' . $status . ')')));
        }
        if ($status === 401) {
            // Nicht abmelden: der Erneuerungsschlüssel gilt meist noch — beim nächsten Abruf neu holen.
            @touch($this->erneuernDatei($buendel));
            throw new RoboHubFehler(RoboHubText::t('Bosch rejected the access token — RoboHub renews it at the next update.'));
        }
        if ($status === 403) {
            throw new RoboHubFehler(RoboHubText::t('The Bosch cloud is blocking the request (HTTP 403, firewall) — try again later.'));
        }
        if ($status === 429) {
            $e = new RoboHubFehler(RoboHubText::t('Bosch is throttling the requests — RoboHub is waiting.'));
            $e->warteSekunden = 600;
            throw $e;
        }
        if ($status >= 300) {
            throw new RoboHubFehler(RoboHubText::t('Bosch responds with HTTP %s to %s.', $status, $pfad));
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
