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
    public const LAENDER = ['de-DE' => 'Germany', 'de-AT' => 'Austria', 'de-CH' => 'Switzerland (German)', 'fr-CH' => 'Switzerland (French)',
        'it-CH' => 'Switzerland (Italian)', 'nl-NL' => 'Netherlands', 'nl-BE' => 'Belgium (Dutch)', 'fr-BE' => 'Belgium (French)',
        'de-LU' => 'Luxembourg', 'fr-FR' => 'France', 'it-IT' => 'Italy', 'es-ES' => 'Spain', 'en-GB' => 'Great Britain',
        'da-DK' => 'Denmark', 'sv-SE' => 'Sweden', 'nb-NO' => 'Norway', 'pl-PL' => 'Poland', 'cs-CZ' => 'Czechia'];

    // programPhase (astrandb/miele const.py) → [Zustand, Klartext]; Fehler F1–F8.
    private const PHASEN = [
        5889 => [RoboHubModell::ZUSTAND_REINIGT, 'Cleaning'],
        5890 => [RoboHubModell::ZUSTAND_ZURUECK, 'Returning to base'],
        5891 => [RoboHubModell::ZUSTAND_PAUSIERT, 'Paused'],
        5892 => [RoboHubModell::ZUSTAND_REINIGT, 'Going to the target area'],
        5893 => [RoboHubModell::ZUSTAND_FEHLER, 'F1: wheel lifted'],
        5894 => [RoboHubModell::ZUSTAND_FEHLER, 'F2: sensors dirty'],
        5895 => [RoboHubModell::ZUSTAND_FEHLER, 'F3: dust bin missing'],
        5896 => [RoboHubModell::ZUSTAND_FEHLER, 'F4: drive wheels blocked'],
        5897 => [RoboHubModell::ZUSTAND_FEHLER, 'F5: brushes blocked'],
        5898 => [RoboHubModell::ZUSTAND_FEHLER, 'F6: check dust bin and filter'],
        5899 => [RoboHubModell::ZUSTAND_FEHLER, 'F7: internal error — restart the robot'],
        5900 => [RoboHubModell::ZUSTAND_FEHLER, 'F8: front wheel blocked'],
        5903 => [RoboHubModell::ZUSTAND_GELADEN, 'Docked'],
        5904 => [RoboHubModell::ZUSTAND_GELADEN, 'Docked'],
        5910 => [RoboHubModell::ZUSTAND_REINIGT, 'Remote controlled'],
    ];

    // Programme (vacuum.py PROG_*): Auswahl „Saugstufe".
    private const PROGRAMME = [1 => 'Auto', 3 => 'Turbo', 4 => 'Quiet'];

    // =================================================================
    // Anmeldung
    // =================================================================

    public function anmelden(string $konto, string $passwort, string $region): array
    {
        $id = trim((string)($this->zusatz['clientId'] ?? ''));
        $geheim = trim((string)($this->zusatz['clientSecret'] ?? ''));
        if ($id === '' || $geheim === '') {
            throw new RoboHubFehler(RoboHubText::t('Please enter the client ID and client secret that Miele gave you for the developer API.'));
        }
        if (!filter_var(trim($konto), FILTER_VALIDATE_EMAIL) || $passwort === '') {
            throw new RoboHubFehler(RoboHubText::t('Please enter e-mail and password of the Miele account (Miele app).'));
        }
        $land = isset(self::LAENDER[$region]) ? $region : 'de-DE';
        return $this->tokenHolen(['grant_type' => 'password', 'username' => trim($konto), 'password' => $passwort, 'vg' => $land],
            ['clientId' => $id, 'clientSecret' => $geheim, 'land' => $land]);
    }

    public function anmeldeText(array $buendel): string
    {
        return RoboHubText::t('✅ Signed in. Client ID and client secret stay stored in the account because Miele only renews access with them; the password was discarded. You can revoke the access at Miele at any time, "Sign out" deletes it here.');
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
            throw new RoboHubFehler(RoboHubText::t('Miele sign-in not reachable%s.', ($status === 0 ? ': ' . $text : ' (HTTP ' . $status . ')')));
        }
        if ($status === 429) {
            $e = new RoboHubFehler(RoboHubText::t('Miele sign-in overloaded right now — RoboHub will try later.'));
            $e->warteSekunden = 600;
            throw $e;
        }
        $j = json_decode($text, true);
        if ($status !== 200 || !is_array($j) || (string)($j['access_token'] ?? '') === '') {
            $grund = is_array($j) ? (string)($j['error'] ?? '') : '';
            $e = new RoboHubFehler($felder['grant_type'] === 'password'
                ? RoboHubText::t('Miele rejects the sign-in%s — check client ID/secret, e-mail/password and country.', ($grund !== '' ? ' (' . $grund . ')' : ''))
                : RoboHubText::t('Miele rejects the renewal%s — please sign in again.', ($grund !== '' ? ' (' . $grund . ')' : '')));
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
            throw new RoboHubFehler(RoboHubText::t('This Miele robot vacuum is not (or no longer) in the account.'));
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
            [$z, $t] = [RoboHubModell::ZUSTAND_UNBEKANNT, RoboHubText::t('Not connected')];
        } elseif (isset(self::PHASEN[$phase])) {
            [$z, $t] = self::PHASEN[$phase];
            $t = RoboHubText::wert($t);
            if ($z === RoboHubModell::ZUSTAND_GELADEN && $akku !== null && $akku < 100) {
                $z = RoboHubModell::ZUSTAND_LAEDT;
            }
        } elseif ($status === 8) {
            [$z, $t] = [RoboHubModell::ZUSTAND_FEHLER, RoboHubText::t('Fault')];
        } elseif ($status === 6) {
            [$z, $t] = [RoboHubModell::ZUSTAND_PAUSIERT, 'Paused'];
        } elseif ($status === 5) {
            [$z, $t] = [RoboHubModell::ZUSTAND_REINIGT, 'Cleaning'];
        } elseif ($status === 1) {
            [$z, $t] = [RoboHubModell::ZUSTAND_SCHLAF, 'Off'];
        } else {
            [$z, $t] = [RoboHubModell::ZUSTAND_BEREIT, 'Ready'];
        }
        // Miele liefert eigene deutsche Texte (language=de) — die bevorzugen, die Tabelle ist der Rückfall.
        $mieleText = trim((string)($p['programPhase']['value_localized'] ?? ''));
        $w['Zustand'] = $z;
        $w['ZustandRoh'] = ($mieleText !== '' ? $mieleText : $t) . ' (' . $status . '/' . $phase . ')';
        $w['Fehler'] = $z === RoboHubModell::ZUSTAND_FEHLER ? ($mieleText !== '' ? $mieleText : $t) : RoboHubText::t('no error');
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
                throw new RoboHubFehler(RoboHubText::t('This setting does not exist on the Miele Scout.'));
            }
            $this->aktion($buendel, $id, ['programId' => $wert]);
            return RoboHubText::t('Program "%s" sent.', RoboHubText::wert(self::PROGRAMME[$wert]));
        }
        $map = ['start' => [1, 'Start'], 'stopp' => [2, 'Stop'], 'pause' => [3, 'Pause']];
        if (!isset($map[$befehl])) {
            throw new RoboHubFehler($befehl === 'station' ? RoboHubText::t('The Miele API does not offer "Return to base" — end with "Stop" and send it back in the app.')
                : ($befehl === 'orten' ? RoboHubText::t('Miele does not offer "Locate".') : ($befehl === 'raum' ? RoboHubText::t('RoboHub does not control rooms on the Miele Scout (yet).') : RoboHubText::t('This command is not supported by Miele: %s', $befehl))));
        }
        // Erlaubte Aktionen fragt Miele je Zustand ab (GET actions) — ehrliche Absage statt stiller 400.
        $erlaubt = (array)($this->anfrage($buendel, 'GET', 'v1/devices/' . rawurlencode($id) . '/actions', null)['processAction'] ?? []);
        if (!in_array($map[$befehl][0], array_map('intval', $erlaubt), true)) {
            throw new RoboHubFehler(RoboHubText::t('"%s" is not possible right now (in the current state Miele only allows: %s). Is "Remote control" allowed on the robot?', RoboHubText::wert($map[$befehl][1]), ($erlaubt === [] ? RoboHubText::t('nothing') : implode(', ', array_map(function ($a) use ($map) {
                    foreach ($map as [$nr, $text]) {
                        if ($nr === (int)$a) {
                            return RoboHubText::wert($text);
                        }
                    }
                    return (string)$a;
                }, $erlaubt)))));
        }
        $this->aktion($buendel, $id, ['processAction' => $map[$befehl][0]]);
        return RoboHubText::t('Command "%s" sent.', RoboHubText::wert($map[$befehl][1]));
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
        throw new RoboHubFehler(RoboHubText::t('Resetting wear parts on the Miele Scout is only possible in the app.'));
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
            throw new RoboHubFehler(RoboHubText::t('Miele cloud not reachable%s.', ($status === 0 ? ': ' . $text : ' (HTTP ' . $status . ')')));
        }
        if ($status === 401) {
            @touch($this->erneuernDatei($buendel));   // nicht abmelden: beim nächsten Abruf erneuern
            throw new RoboHubFehler(RoboHubText::t('Miele rejected the access token — RoboHub renews it at the next update.'));
        }
        if ($status === 429) {
            $e = new RoboHubFehler(RoboHubText::t('Miele is throttling the requests — RoboHub is waiting.'));
            $e->warteSekunden = 600;
            throw $e;
        }
        if ($status >= 300) {
            $j = json_decode($text, true);
            throw new RoboHubFehler(RoboHubText::t('Miele responds with HTTP %s%s.', $status, (is_array($j) && isset($j['message']) ? ': ' . $j['message'] : '')));
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
