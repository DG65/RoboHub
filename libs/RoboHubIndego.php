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
        0 => [RoboHubModell::ZUSTAND_UNBEKANNT, 'Liest Status'],
        101 => [RoboHubModell::ZUSTAND_FEHLER, 'Angehoben'],
        257 => [RoboHubModell::ZUSTAND_LAEDT, 'Lädt'],
        258 => [RoboHubModell::ZUSTAND_GELADEN, 'In der Station'],
        259 => [RoboHubModell::ZUSTAND_AKTUALISIERUNG, 'In der Station – Software-Update'],
        260 => [RoboHubModell::ZUSTAND_LAEDT, 'Lädt'],
        261 => [RoboHubModell::ZUSTAND_GELADEN, 'In der Station'],
        262 => [RoboHubModell::ZUSTAND_GELADEN, 'In der Station – lädt Karte'],
        263 => [RoboHubModell::ZUSTAND_GELADEN, 'In der Station – speichert Karte'],
        266 => [RoboHubModell::ZUSTAND_REINIGT, 'Verlässt die Station'],
        512 => [RoboHubModell::ZUSTAND_REINIGT, 'Verlässt die Station'],
        513 => [RoboHubModell::ZUSTAND_REINIGT, 'Mäht'],
        514 => [RoboHubModell::ZUSTAND_REINIGT, 'Mäht – orientiert sich'],
        515 => [RoboHubModell::ZUSTAND_REINIGT, 'Mäht – lädt Karte'],
        516 => [RoboHubModell::ZUSTAND_KARTIERUNG, 'Lernt den Rasen'],
        517 => [RoboHubModell::ZUSTAND_PAUSIERT, 'Pausiert'],
        518 => [RoboHubModell::ZUSTAND_REINIGT, 'Kantenschnitt'],
        519 => [RoboHubModell::ZUSTAND_BEREIT, 'Steht im Rasen'],
        520 => [RoboHubModell::ZUSTAND_PAUSIERT, 'Rasen lernen pausiert'],
        521 => [RoboHubModell::ZUSTAND_REINIGT, 'Kantenschnitt'],
        523 => [RoboHubModell::ZUSTAND_REINIGT, 'Punktmähen'],
        524 => [RoboHubModell::ZUSTAND_REINIGT, 'Mäht – zufällig'],
        525 => [RoboHubModell::ZUSTAND_REINIGT, 'Mäht – zufällig, fertig'],
        768 => [RoboHubModell::ZUSTAND_ZURUECK, 'Fährt zur Station'],
        769 => [RoboHubModell::ZUSTAND_ZURUECK, 'Fährt zur Station'],
        770 => [RoboHubModell::ZUSTAND_ZURUECK, 'Fährt zur Station'],
        771 => [RoboHubModell::ZUSTAND_ZURUECK, 'Fährt zur Station – Akku schwach'],
        772 => [RoboHubModell::ZUSTAND_ZURUECK, 'Fährt zur Station – Zeitfenster vorbei'],
        773 => [RoboHubModell::ZUSTAND_ZURUECK, 'Fährt zur Station – Akkutemperatur'],
        774 => [RoboHubModell::ZUSTAND_ZURUECK, 'Fährt zur Station – auf Wunsch'],
        775 => [RoboHubModell::ZUSTAND_ZURUECK, 'Fährt zur Station – Rasen fertig'],
        776 => [RoboHubModell::ZUSTAND_ZURUECK, 'Fährt zur Station – orientiert sich'],
        1005 => [RoboHubModell::ZUSTAND_FEHLER, 'Station nicht erreicht'],
        1025 => [RoboHubModell::ZUSTAND_FEHLER, 'Diagnosemodus'],
        1026 => [RoboHubModell::ZUSTAND_FEHLER, 'Lebensende'],
        1027 => [RoboHubModell::ZUSTAND_FEHLER, 'Service fragt Status ab'],
        1038 => [RoboHubModell::ZUSTAND_FEHLER, 'Mäher gesperrt'],
        1281 => [RoboHubModell::ZUSTAND_AKTUALISIERUNG, 'Software-Update'],
        1537 => [RoboHubModell::ZUSTAND_FEHLER, 'Steckt fest, Hilfe nötig'],
        64513 => [RoboHubModell::ZUSTAND_SCHLAF, 'Schläft'],
        99999 => [RoboHubModell::ZUSTAND_UNBEKANNT, 'Offline'],
    ];

    // pyIndego ALERT_ERROR_CODE (Auszug, deutsch).
    private const FEHLER = [
        104 => 'STOP-Taste gedrückt', 101 => 'Angehoben', 115 => 'Steckt fest', 1008 => 'Steckt fest',
        149 => 'Außerhalb des Begrenzungsdrahts', 151 => 'Kein Drahtsignal', 1005 => 'Station nicht erreicht',
        1108 => 'Zu starke Neigung', 1138 => 'Mäher braucht Hilfe',
    ];

    private const BEFEHLE = ['start' => ['mow', 'Mähen'], 'pause' => ['pause', 'Pause'], 'station' => ['returnToDock', 'Zur Station']];

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
            throw new RoboHubFehler('Bitte die Adresse „com.bosch.indegoconnect://login?…" aus der Entwicklerkonsole des Browsers (oder nur den Code daraus) ins Feld „Code" eintragen.');
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
            throw new RoboHubFehler('Bosch-Anmeldung nicht erreichbar' . ($status === 0 ? ': ' . $text : ' (HTTP ' . $status . ')') . '.');
        }
        $j = json_decode($text, true);
        if ($status !== 200 || !is_array($j) || (string)($j['access_token'] ?? '') === '') {
            // Nur den Fehlercode nennen (AADB2C…), nicht die ganze Antwort.
            $grund = is_array($j) ? (string)($j['error'] ?? '') : '';
            if (is_array($j) && preg_match('/AADB2C\d+/', (string)($j['error_description'] ?? ''), $m)) {
                $grund .= ($grund !== '' ? ', ' : '') . $m[0];
            }
            $e = new RoboHubFehler($felder['grant_type'] === 'authorization_code'
                ? 'Bosch lehnt den Code ab' . ($grund !== '' ? ' (' . $grund . ')' : '') . ' — er gilt nur wenige Minuten und nur einmal. Bitte die Anmeldeseite neu öffnen.'
                : 'Bosch lehnt die Erneuerung ab' . ($grund !== '' ? ' (' . $grund . ')' : '') . ' — bitte neu anmelden.');
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
            throw new RoboHubFehler('Bosch liefert für ' . $geraet['id'] . ' keinen Zustand — ist der Mäher noch im Konto?');
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
            [$z, $text] = self::ZUSTAENDE[$s] ?? [RoboHubModell::ZUSTAND_UNBEKANNT, 'Unbekannt'];
            $w['Zustand'] = $z;
            $w['ZustandRoh'] = $text . ' (' . $s . ')';
            $code = (int)($p['error'] ?? 0);
            if ($code !== 0) {
                $w['Fehler'] = (self::FEHLER[$code] ?? 'Fehlercode') . ' (' . $code . ')';
            } else {
                $w['Fehler'] = $z === RoboHubModell::ZUSTAND_FEHLER ? $text : 'kein Fehler';
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
            throw new RoboHubFehler(($befehl === 'stopp' ? '„Stopp" gibt es bei Indego nicht — „Pause" oder „Zur Station" nehmen.'
                : ($befehl === 'orten' ? '„Orten"' : ($befehl === 'raum' ? 'Zonen mähen' : 'Dieser Befehl (' . $befehl . ')')) . ' bietet die Bosch-Cloud nicht.'));
        }
        [$kommando, $text] = self::BEFEHLE[$befehl];
        $this->anfrage($buendel, 'PUT', 'alms/' . rawurlencode((string)$geraet['id']) . '/state', ['state' => $kommando]);
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
        throw new RoboHubFehler('Verschleißteile zurücksetzen geht bei Indego nur in der App.');
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
            throw new RoboHubFehler('Bosch-Cloud nicht erreichbar' . ($status === 0 ? ': ' . $text : ' (HTTP ' . $status . ')') . '.');
        }
        if ($status === 401) {
            // Nicht abmelden: der Erneuerungsschlüssel gilt meist noch — beim nächsten Abruf neu holen.
            @touch($this->erneuernDatei($buendel));
            throw new RoboHubFehler('Bosch hat den Zugangsschlüssel abgelehnt — RoboHub erneuert ihn beim nächsten Abruf.');
        }
        if ($status === 403) {
            throw new RoboHubFehler('Die Bosch-Cloud blockiert die Anfrage (HTTP 403, Firewall) — später erneut versuchen.');
        }
        if ($status === 429) {
            $e = new RoboHubFehler('Bosch bremst die Anfragen — RoboHub wartet.');
            $e->warteSekunden = 600;
            throw $e;
        }
        if ($status >= 300) {
            throw new RoboHubFehler('Bosch antwortet mit HTTP ' . $status . ' auf ' . $pfad . '.');
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
