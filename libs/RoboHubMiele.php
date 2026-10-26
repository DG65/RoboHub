<?php

declare(strict_types=1);

// ===========================================================================
// RoboHub-Treiber für Miele Scout (Saugroboter) — offizielle Miele-3rd-Party-API.
//
// Quellen: Grizzelbee/ioBroker.mielecloudservice (MIT, Stand 30.09.2026:
// source/mieleConst.js, mieleTools.js — Passwort-Anmeldung, Endpunkte, Aktionen),
// astrandb/miele (MIT, HA, 17.10.2026: const.py Phasen 5889–5910, vacuum.py Befehle
// und Programme) und nordicopen/pymiele (MIT, const.py Adressen).
//
// Zugang: Client-ID + Secret beantragt der Nutzer bei Miele (developer.miele.com).
// Anmeldung OAuth2-Password-Grant am alten Endpunkt thirdparty/token mit „vg"
// (Land des Miele-Kontos) wie ioBroker; danach Erneuerungsschlüssel.
// Dietmars Entscheidung 27.10.2026 (wie AL-KO/Husqvarna): Client-ID + Secret im
// Bündel, Passwort verworfen. Live 27.10.2026: alter und neuer (Keycloak)
// Anmeldeserver antworten.
//
// Geräte: GET v1/devices, nur Typ 23 (Saugroboter). Befehle: PUT v1/devices/{id}/actions
// mit processAction 1/2/3 (Start/Stopp/Pause) bzw. programId 1–4 (Auto/Spot/Turbo/Leise).
// „Zur Station" bietet die Schnittstelle nicht (auch HA schickt dafür nichts).
// Nicht gebaut: Echtzeit (Server-Sent Events v1/devices/all/events), Räume (v1/devices/{id}/rooms
// — in ioBroker auskommentiert, Format unbelegt).
// ===========================================================================

class RoboHubMiele implements RoboHubTreiber
{
    /** @var callable|null fn(string $thema, string $text) */
    public $debug = null;

    /** Vom Konto vor der Anmeldung gesetzt: ['clientId' => …, 'clientSecret' => …]. */
    public $zusatz = [];

    private const BASIS = 'https://api.mcs3.miele.com/';
    private const TYP_SAUGROBOTER = 23;

    // Länder für „vg" (ioBroker admin: oauth2_vg).
    public const LAENDER = ['de-DE' => 'Deutschland', 'de-AT' => 'Österreich', 'de-CH' => 'Schweiz (deutsch)', 'fr-CH' => 'Schweiz (französisch)',
        'it-CH' => 'Schweiz (italienisch)', 'nl-NL' => 'Niederlande', 'nl-BE' => 'Belgien (niederländisch)', 'fr-BE' => 'Belgien (französisch)',
        'de-LU' => 'Luxemburg', 'fr-FR' => 'Frankreich', 'it-IT' => 'Italien', 'es-ES' => 'Spanien', 'en-GB' => 'Großbritannien',
        'da-DK' => 'Dänemark', 'sv-SE' => 'Schweden', 'nb-NO' => 'Norwegen', 'pl-PL' => 'Polen', 'cs-CZ' => 'Tschechien'];

    // programPhase (astrandb/miele const.py) → [Zustand, Klartext]; Fehler F1–F8.
    private const PHASEN = [
        5889 => [RoboHubModell::ZUSTAND_REINIGT, 'Reinigt'],
        5890 => [RoboHubModell::ZUSTAND_ZURUECK, 'Fährt zur Station'],
        5891 => [RoboHubModell::ZUSTAND_PAUSIERT, 'Pausiert'],
        5892 => [RoboHubModell::ZUSTAND_REINIGT, 'Fährt zum Zielbereich'],
        5893 => [RoboHubModell::ZUSTAND_FEHLER, 'F1: Rad angehoben'],
        5894 => [RoboHubModell::ZUSTAND_FEHLER, 'F2: Sensoren verschmutzt'],
        5895 => [RoboHubModell::ZUSTAND_FEHLER, 'F3: Staubbehälter fehlt'],
        5896 => [RoboHubModell::ZUSTAND_FEHLER, 'F4: Antriebsräder blockiert'],
        5897 => [RoboHubModell::ZUSTAND_FEHLER, 'F5: Bürsten blockiert'],
        5898 => [RoboHubModell::ZUSTAND_FEHLER, 'F6: Staubbehälter und Filter prüfen'],
        5899 => [RoboHubModell::ZUSTAND_FEHLER, 'F7: Interner Fehler — Roboter neu starten'],
        5900 => [RoboHubModell::ZUSTAND_FEHLER, 'F8: Vorderrad blockiert'],
        5903 => [RoboHubModell::ZUSTAND_GELADEN, 'In der Station'],
        5904 => [RoboHubModell::ZUSTAND_GELADEN, 'In der Station'],
        5910 => [RoboHubModell::ZUSTAND_REINIGT, 'Ferngesteuert'],
    ];

    // Programme (vacuum.py PROG_*): Auswahl „Saugstufe".
    private const PROGRAMME = [1 => 'Auto', 3 => 'Turbo', 4 => 'Leise'];

    // =================================================================
    // Anmeldung
    // =================================================================

    public function anmelden(string $konto, string $passwort, string $region): array
    {
        $id = trim((string)($this->zusatz['clientId'] ?? ''));
        $geheim = trim((string)($this->zusatz['clientSecret'] ?? ''));
        if ($id === '' || $geheim === '') {
            throw new RoboHubFehler('Bitte Client-ID und Client-Secret eintragen, die Miele dir für die Entwickler-Schnittstelle gegeben hat.');
        }
        if (!filter_var(trim($konto), FILTER_VALIDATE_EMAIL) || $passwort === '') {
            throw new RoboHubFehler('Bitte E-Mail und Passwort des Miele-Kontos (Miele-App) eintragen.');
        }
        $land = isset(self::LAENDER[$region]) ? $region : 'de-DE';
        return $this->tokenHolen(['grant_type' => 'password', 'username' => trim($konto), 'password' => $passwort, 'vg' => $land],
            ['clientId' => $id, 'clientSecret' => $geheim, 'land' => $land]);
    }

    public function anmeldeText(array $buendel): string
    {
        return '✅ Angemeldet. Client-ID und Client-Secret bleiben im Konto gespeichert, weil Miele den Zugang nur damit erneuert; das Passwort wurde verworfen. Den Zugang kannst du bei Miele jederzeit widerrufen, „Abmelden" löscht ihn hier.';
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
        [$status, $text] = $this->http('POST', self::BASIS . 'thirdparty/token/', ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            http_build_query($felder, '', '&', PHP_QUERY_RFC3986));
        $this->dbg('HTTP', 'POST thirdparty/token (' . $felder['grant_type'] . ') → ' . $status);
        if ($status === 0 || ($status >= 500 && $status !== 0)) {
            throw new RoboHubFehler('Miele-Anmeldung nicht erreichbar' . ($status === 0 ? ': ' . $text : ' (HTTP ' . $status . ')') . '.');
        }
        if ($status === 429) {
            $e = new RoboHubFehler('Miele-Anmeldung gerade überlastet — RoboHub versucht es später.');
            $e->warteSekunden = 600;
            throw $e;
        }
        $j = json_decode($text, true);
        if ($status !== 200 || !is_array($j) || (string)($j['access_token'] ?? '') === '') {
            $grund = is_array($j) ? (string)($j['error'] ?? '') : '';
            $e = new RoboHubFehler($felder['grant_type'] === 'password'
                ? 'Miele lehnt die Anmeldung ab' . ($grund !== '' ? ' (' . $grund . ')' : '') . ' — Client-ID/Secret, E-Mail/Passwort und Land prüfen.'
                : 'Miele lehnt die Erneuerung ab' . ($grund !== '' ? ' (' . $grund . ')' : '') . ' — bitte neu anmelden.');
            $e->anmeldungNoetig = true;
            throw $e;
        }
        return [
            'clientId'      => $b['clientId'],
            'clientSecret'  => $b['clientSecret'],
            'land'          => $b['land'] ?? 'de-DE',
            'access_token'  => (string)$j['access_token'],
            'refresh_token' => (string)($j['refresh_token'] ?? $b['refresh_token'] ?? ''),
            'expires_at'    => time() + max(300, (int)($j['expires_in'] ?? 3600)),
        ];
    }

    private function erneuernDatei(array $buendel): string
    {
        return sys_get_temp_dir() . '/robohub-miele-erneuern-' . substr(md5((string)($buendel['refresh_token'] ?? '')), 0, 12);
    }

    // =================================================================
    // Geräte und Zustand
    // =================================================================

    /** v1/devices: Objekt je Seriennummer; nur Saugroboter (Typ 23). */
    private function roboterListe(array $buendel): array
    {
        $aus = [];
        foreach ($this->anfrage($buendel, 'GET', 'v1/devices?language=de', null) as $id => $g) {
            if (is_array($g) && (int)($g['ident']['type']['value_raw'] ?? 0) === self::TYP_SAUGROBOTER) {
                $aus[(string)$id] = $g;
            }
        }
        return $aus;
    }

    public function geraete(array $buendel): array
    {
        $liste = [];
        foreach ($this->roboterListe($buendel) as $id => $g) {
            $modell = (string)($g['ident']['deviceIdentLabel']['techType'] ?? 'Scout');
            $liste[] = ['id' => $id, 'name' => trim((string)($g['ident']['deviceName'] ?? '')) ?: 'Miele ' . $modell,
                'modell' => 'miele.vacuum.' . (strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '', $modell)) ?: 'scout'),
                'klasse' => RoboHubModell::KLASSE_SAUGER, 'online' => (int)($g['state']['status']['value_raw'] ?? 0) !== 255,
                'roh' => ['modell' => $modell, 'firmware' => $g['ident']['xkmIdentLabel']['releaseVersion'] ?? null]];
        }
        return $liste;
    }

    public function zustand(array $buendel, array $geraet): array
    {
        $g = $this->roboterListe($buendel)[(string)$geraet['id']] ?? null;
        if (!is_array($g)) {
            throw new RoboHubFehler('Dieser Miele-Saugroboter ist im Konto nicht (mehr) vorhanden.');
        }
        $p = (array)($g['state'] ?? []);
        $daten = $this->normalisiere((string)($geraet['modell'] ?? ''), $p);
        $daten['online'] = (int)($p['status']['value_raw'] ?? 0) !== 255;
        $daten['bloecke'] = ['ok' => [0], 'fehl' => []];
        $daten['roh'] = ['modell' => $geraet['modell'] ?? null, 'properties' => $p];
        return $daten;
    }

    public function normalisiere(string $modell, array $p): array
    {
        $w = [];
        $status = (int)($p['status']['value_raw'] ?? 0);
        $phase = (int)($p['programPhase']['value_raw'] ?? 0);
        $akku = isset($p['batteryLevel']) && is_numeric($p['batteryLevel']) ? (int)$p['batteryLevel'] : null;
        if ($status === 255) {
            [$z, $t] = [RoboHubModell::ZUSTAND_UNBEKANNT, 'Nicht verbunden'];
        } elseif (isset(self::PHASEN[$phase])) {
            [$z, $t] = self::PHASEN[$phase];
            if ($z === RoboHubModell::ZUSTAND_GELADEN && $akku !== null && $akku < 100) {
                $z = RoboHubModell::ZUSTAND_LAEDT;
            }
        } elseif ($status === 8) {
            [$z, $t] = [RoboHubModell::ZUSTAND_FEHLER, 'Störung'];
        } elseif ($status === 6) {
            [$z, $t] = [RoboHubModell::ZUSTAND_PAUSIERT, 'Pausiert'];
        } elseif ($status === 5) {
            [$z, $t] = [RoboHubModell::ZUSTAND_REINIGT, 'Reinigt'];
        } elseif ($status === 1) {
            [$z, $t] = [RoboHubModell::ZUSTAND_SCHLAF, 'Aus'];
        } else {
            [$z, $t] = [RoboHubModell::ZUSTAND_BEREIT, 'Bereit'];
        }
        // Miele liefert eigene deutsche Texte (language=de) — die bevorzugen, die Tabelle ist der Rückfall.
        $mieleText = trim((string)($p['programPhase']['value_localized'] ?? ''));
        $w['Zustand'] = $z;
        $w['ZustandRoh'] = ($mieleText !== '' ? $mieleText : $t) . ' (' . $status . '/' . $phase . ')';
        $w['Fehler'] = $z === RoboHubModell::ZUSTAND_FEHLER ? ($mieleText !== '' ? $mieleText : $t) : 'kein Fehler';
        $w['Akku'] = $akku;
        $w['Laedt'] = $z === RoboHubModell::ZUSTAND_LAEDT;
        $optionen = [];
        $prog = (int)($p['ProgramID']['value_raw'] ?? 0);
        if (isset(self::PROGRAMME[$prog])) {
            $w['Saugstufe'] = $prog;
        }
        $optionen['Saugstufe'] = self::PROGRAMME;
        return ['werte' => $w, 'optionen' => $optionen, 'schreibbar' => []];
    }

    // =================================================================
    // Befehle
    // =================================================================

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        $id = (string)$geraet['id'];
        if ($befehl === 'setze') {
            $wert = (int)($parameter['wert'] ?? 0);
            if (($parameter['feld'] ?? '') !== 'Saugstufe' || !isset(self::PROGRAMME[$wert])) {
                throw new RoboHubFehler('Diese Einstellung gibt es beim Miele Scout nicht.');
            }
            $this->aktion($buendel, $id, ['programId' => $wert]);
            return 'Programm „' . self::PROGRAMME[$wert] . '" gesendet.';
        }
        $map = ['start' => [1, 'Start'], 'stopp' => [2, 'Stopp'], 'pause' => [3, 'Pause']];
        if (!isset($map[$befehl])) {
            throw new RoboHubFehler($befehl === 'station' ? '„Zur Station" bietet die Miele-Schnittstelle nicht — „Stopp" beenden und in der App zurückschicken.'
                : ($befehl === 'orten' ? '„Orten" bietet Miele nicht.' : ($befehl === 'raum' ? 'Räume steuert RoboHub beim Miele Scout (noch) nicht.' : 'Dieser Befehl wird bei Miele nicht unterstützt: ' . $befehl)));
        }
        // Erlaubte Aktionen fragt Miele je Zustand ab (GET actions) — ehrliche Absage statt stiller 400.
        $erlaubt = (array)($this->anfrage($buendel, 'GET', 'v1/devices/' . rawurlencode($id) . '/actions', null)['processAction'] ?? []);
        if (!in_array($map[$befehl][0], array_map('intval', $erlaubt), true)) {
            throw new RoboHubFehler('„' . $map[$befehl][1] . '" ist gerade nicht möglich (Miele erlaubt im aktuellen Zustand nur: '
                . ($erlaubt === [] ? 'nichts' : implode(', ', array_map(function ($a) use ($map) {
                    foreach ($map as [$nr, $text]) {
                        if ($nr === (int)$a) {
                            return $text;
                        }
                    }
                    return (string)$a;
                }, $erlaubt))) . '). Ist „Fernsteuerung" am Roboter erlaubt?');
        }
        $this->aktion($buendel, $id, ['processAction' => $map[$befehl][0]]);
        return 'Befehl „' . $map[$befehl][1] . '" gesendet.';
    }

    private function aktion(array $buendel, string $id, array $body): void
    {
        $this->anfrage($buendel, 'PUT', 'v1/devices/' . rawurlencode($id) . '/actions', $body);
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
        throw new RoboHubFehler('Verschleißteile zurücksetzen geht beim Miele Scout nur in der App.');
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
        [$status, $text] = $this->http($methode, self::BASIS . $pfad, $kopf, $body !== null ? (string)json_encode($body) : null);
        $this->dbg('HTTP', $methode . ' ' . preg_replace('/\?.*/', '', $pfad) . ' → ' . $status);
        if ($status === 0 || $status >= 500) {
            throw new RoboHubFehler('Miele-Cloud nicht erreichbar' . ($status === 0 ? ': ' . $text : ' (HTTP ' . $status . ')') . '.');
        }
        if ($status === 401) {
            @touch($this->erneuernDatei($buendel));   // nicht abmelden: beim nächsten Abruf erneuern
            throw new RoboHubFehler('Miele hat den Zugangsschlüssel abgelehnt — RoboHub erneuert ihn beim nächsten Abruf.');
        }
        if ($status === 429) {
            $e = new RoboHubFehler('Miele bremst die Anfragen — RoboHub wartet.');
            $e->warteSekunden = 600;
            throw $e;
        }
        if ($status >= 300) {
            $j = json_decode($text, true);
            throw new RoboHubFehler('Miele antwortet mit HTTP ' . $status . (is_array($j) && isset($j['message']) ? ': ' . $j['message'] : '') . '.');
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
