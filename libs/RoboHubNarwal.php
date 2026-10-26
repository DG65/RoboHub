<?php

declare(strict_types=1);

// ===========================================================================
// RoboHub-Treiber für Narwal Saug-/Wischroboter — lokal im Heimnetz, ohne Cloud.
//
// Quelle: sjmotew/NarwalIntegration (MIT, v1.0.11, Stand 26.10.2026):
// docs/PROTOCOL.md, narwal_client/protocol.py, const.py, client.py, models.py.
//
// Protokoll: WebSocket ws://<IP>:9002 ohne Anmeldung. Jede Nachricht ist ein
// Binärrahmen 01 <len+2> 22|2a <len> <Thema> <Protobuf>. 0x22 = Anfrage bzw.
// Rundmeldung (Thema /<Produktschlüssel>/<Geräte-ID>/<kurzes Thema>), 0x2a =
// Antwort mit leerem Thema — Antworten tragen keine Kennung, sie gehören in
// Sendereihenfolge zu den Anfragen, die eine Antwort schulden (Heartbeat nie).
// Protobuf ohne Schema: Feldnummern aus models.py/const.py (dort live belegt).
//
// Schonend abfragen (NarwalIntegration #82/#90/#113): Ein Roboter in der
// Station darf nicht bei jeder Abfrage geweckt werden. Die Abfrage abonniert
// deshalb nur die Rundmeldungen und holt den Grundstatus; kommt binnen
// weniger Sekunden keine Rundmeldung, schläft er — dann ist nur der Akku
// verlässlich (Arbeitszustand in der Antwort ist dann veraltet,
// update_battery_from_base_status). Geweckt wird nur für Befehle.
//
// Start = alle Räume der Karte über clean/start_clean (clean/plan/start tut
// auf neuer Firmware nichts, #69), aus der Pause task/resume. Start/Räume
// gehen nur aus der Station (sonst Antwort 4 „nicht bereit").
// Nicht gebaut: Kartenbild, Live-Karte, Saug-/Wischeinstellungen, Stationsaufgaben,
// Verbrauchsteile, Echtzeit über eine Dauerverbindung.
// ===========================================================================

class RoboHubNarwal implements RoboHubTreiber
{
    /** @var callable|null fn(string $thema, string $text) */
    public $debug = null;

    /** Sekunden, die eine Abfrage auf eine Rundmeldung wartet (Prüfstand kürzer). */
    public $rundmeldungWarten = 3.0;
    /** Sekunden, die ein Befehl aufs Aufwachen wartet. */
    public $weckenWarten = 10.0;

    public const PORT = 9002;

    // Produktschlüssel → Modell (const.py KNOWN_PRODUCT_KEYS, nur lokal belegte zuerst).
    public const MODELLE = [
        'QoEsI5qYXO' => 'Narwal Flow', 'QxMSPG6VSO' => 'Narwal Flow 2', 'iSuVlI1If2' => 'Narwal Flow 2', 'mkbqaprvrb' => 'Narwal Flow 2',
        'DrzDKQ0MU8' => 'Narwal Freo Z10 Ultra', 'qV6BujoYLz' => 'Narwal Freo Z10 Pro', 'hEA7OEshlx' => 'Narwal Freo Z Ultra',
        'fjhpiem4ba' => 'Narwal Freo 20', 'ulonq49mm1' => 'Narwal Freo 20 Edge', 'CGjuB6dzq7' => 'Narwal JX', 'CNbforyZWI' => 'Narwal Freo X10 Pro',
        'BYWBPqSxeC' => 'Narwal', 'LnugwMG9ss' => 'Narwal Freo X Ultra', '5OMbqk58Sc' => 'Narwal Freo X Ultra', 'tPQJmoIbEC' => 'Narwal AX6',
        'HgArZ7KuJL' => 'Narwal AX7', 'Uuug39n0fD' => 'Narwal AX8', 'E9Q8aDzUbp' => 'Narwal AX17', 'jI5rHi4mKa' => 'Narwal AX24',
        'UuTSLsMce4' => 'Narwal AX25', '88OLXLpkjT' => 'Narwal BX4', '3rIGshGNAj' => 'Narwal BX4', '7sSZZ4XfTI' => 'Narwal CX2',
        'OlkUn3oUCu' => 'Narwal CX3', 'mvlduyye85' => 'Narwal X30', 'pcbfh2ldvx' => 'Narwal X31', 'EHf6cRNRGT' => 'Narwal J4',
        '6NjIDYxBXb' => 'Narwal J4 Lite', 'cUlfJN5JYP' => 'Narwal',
    ];

    // WorkingStatus (const.py, live belegt).
    private const AKTIV = [3, 4, 5, 17];
    private const IN_STATION = [2, 10, 14];

    // RoomType (models.py ROOM_TYPE_NAMES, App-Beschriftungen) deutsch.
    private const RAUMTYPEN = [0 => 'Raum', 1 => 'Schlafzimmer', 2 => 'Gästezimmer', 3 => 'Wohnzimmer', 4 => 'Küche', 5 => 'Bad', 6 => 'WC',
        7 => 'Balkon', 8 => 'Esszimmer', 9 => 'Ankleide', 10 => 'Flur', 11 => 'Arbeitszimmer', 12 => 'Kinderzimmer', 13 => 'Spielzimmer',
        14 => 'Abstellraum', 15 => 'Sonstiges'];

    // Antwortcodes (const.py CommandResult).
    private const ABGELEHNT = [2 => 'passt gerade nicht zum Zustand des Roboters', 3 => 'läuft bereits', 4 => 'Roboter ist nicht bereit — Start und Raumreinigung gehen nur aus der Station'];

    // Befehle ohne Nutzlast: [kurzes Thema, Klartext, Wartezeit s].
    private const BEFEHLE = [
        'pause'   => ['task/pause', 'Pause', 5.0],
        'stopp'   => ['task/force_end', 'Stopp', 15.0],   // Roboter antwortet erst nach dem Anhalten (client.py stop)
        'station' => ['supply/recall', 'Zur Station', 5.0],
        'orten'   => ['common/yell', 'Orten', 5.0],
    ];

    // Abonnierte Rundmeldungen (client.py _ALL_BROADCAST_TOPICS, ohne Karte).
    private const RUNDMELDUNGEN = ['status/robot_base_status', 'status/working_status'];

    /** @var array<string, string> zuletzt empfangene Rundmeldungen je kurzem Thema */
    private $rund = [];
    /** @var float[] Ablaufzeiten der Bestätigungen, die noch ausstehen, aber niemand liest */
    private $offen = [];

    // =================================================================
    // Koppeln (Konto: IP eintragen, „Jetzt anmelden")
    // =================================================================

    public function koppeln(string $ip, array $buendel): array
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new RoboHubFehler('Bitte die IP-Adresse des Narwal eintragen (z. B. 192.168.1.60).');
        }
        $v = $this->verbinden($ip);
        try {
            [$produkt, $did, $firmware] = $this->erkennen($v);
        } finally {
            $v->schliessen();
        }
        $buendel['marke'] = 'narwal';
        $buendel['roboter'][$did] = [
            'did' => $did, 'ip' => $ip, 'produkt' => $produkt, 'firmware' => $firmware,
            'name' => self::MODELLE[$produkt] ?? 'Narwal',
        ];
        return $buendel;
    }

    public function koppelText(): string
    {
        return '✅ Gekoppelt. Narwal braucht weder Konto noch Passwort — RoboHub merkt sich nur IP-Adresse, Modellschlüssel und Geräte-ID.';
    }

    public function anmelden(string $konto, string $passwort, string $region): array
    {
        return $this->koppeln($konto, []);
    }

    public function erneuern(array $buendel): array
    {
        if (empty($buendel['roboter'])) {
            $e = new RoboHubFehler('Noch kein Narwal gekoppelt.');
            $e->anmeldungNoetig = true;
            throw $e;
        }
        return $buendel;
    }

    /**
     * Produktschlüssel und Geräte-ID finden (client.py discover_device_id): get_device_info
     * mit allen bekannten Schlüsseln — der Roboter antwortet nur auf seinen eigenen.
     */
    private function erkennen($v): array
    {
        $versuche = ['//common/get_device_info'];
        foreach (array_keys(self::MODELLE) as $schluessel) {
            $versuche[] = '/' . $schluessel . '//common/get_device_info';
        }
        $ende = microtime(true) + 20;
        $i = 0;
        while (microtime(true) < $ende) {
            foreach (array_slice($versuche, $i, 5) as $thema) {
                $v->senden(self::rahmen($thema, ''));
            }
            $i += 5;
            $bis = min($ende, microtime(true) + 1.5);
            while (($roh = $v->empfangen($bis)) !== null) {
                [$feld, $thema, $nutz] = self::zerlege($roh);
                if ($feld === 5) {
                    $d = self::pbLesen($nutz);
                    $did = trim((string)self::pbEins($d, 2, ''));
                    if (preg_match('/^[\x21-\x7e]{4,64}$/', $did)) {
                        $produkt = trim((string)self::pbEins($d, 1, ''));
                        return [$produkt, $did, trim((string)self::pbEins($d, 3, ''))];
                    }
                } elseif (preg_match('#^/([^/]+)/([^/]+)/#', $thema, $m)) {
                    return [$m[1], $m[2], ''];   // Rundmeldung verrät beides (client.py-Rückfall)
                }
            }
            if ($i >= count($versuche)) {
                $i = 0;
            }
        }
        throw new RoboHubFehler('Unter dieser Adresse antwortet kein Narwal (Port ' . self::PORT . '). Manche Modelle (Freo X Ultra, X Plus, J1/J4, T10) haben keine lokale Schnittstelle.');
    }

    // =================================================================
    // Geräte und Zustand
    // =================================================================

    public function geraete(array $buendel): array
    {
        $liste = [];
        foreach ((array)($buendel['roboter'] ?? []) as $r) {
            $liste[] = [
                'id'     => (string)$r['did'],
                'name'   => (string)($r['name'] ?: 'Narwal'),
                'modell' => 'narwal.vacuum.' . strtolower((string)$r['produkt']),
                'klasse' => RoboHubModell::KLASSE_SAUGER,
                'online' => true,
                'roh'    => ['produkt' => $r['produkt'], 'firmware' => $r['firmware'] ?? null, 'ip' => $r['ip']],
            ];
        }
        return $liste;
    }

    private function roboter(array $buendel, array $geraet): array
    {
        $r = $buendel['roboter'][(string)$geraet['id']] ?? null;
        if (!is_array($r)) {
            throw new RoboHubFehler('Dieser Narwal ist im Konto nicht (mehr) gekoppelt.');
        }
        return $r;
    }

    public function zustand(array $buendel, array $geraet): array
    {
        $r = $this->roboter($buendel, $geraet);
        $v = $this->verbinden((string)$r['ip']);
        try {
            $this->rund = [];
            $this->offen = [];
            $this->bestaetigt($v, $r, 'common/active_robot_publish', self::abo(600));
            $antwort = $this->anfrage($v, $r, 'status/get_device_base_status', '', 5.0);
            $ende = microtime(true) + $this->rundmeldungWarten;
            while (!isset($this->rund['status/robot_base_status']) && $this->lies($v, $ende)) {
            }
        } finally {
            $v->schliessen();
        }
        $wach = isset($this->rund['status/robot_base_status']);
        $basis = $wach ? $this->rund['status/robot_base_status'] : (string)self::pbEins(self::pbLesen($antwort), 2, '');
        $p = self::basisAus($basis, $wach);
        $daten = $this->normalisiere((string)($geraet['modell'] ?? ''), $p);
        $daten['online'] = true;
        $daten['bloecke'] = ['ok' => [0], 'fehl' => []];
        $daten['roh'] = ['modell' => $geraet['modell'] ?? null, 'properties' => $p];
        return $daten;
    }

    /** RobotBaseStatus (models.py update_from_base_status) → JSON-taugliche Rohwerte. */
    public static function basisAus(string $bin, bool $wach): array
    {
        $d = self::pbLesen($bin);
        $f3 = self::pbLesen((string)self::pbEins($d, 3, ''));
        $akku = self::pbEins($d, 2, null);
        $fehler = [];
        foreach ($d[1] ?? [] as $eintrag) {
            $code = (int)self::pbEins(self::pbLesen((string)$eintrag), 1, 0);
            if ($code !== 0) {
                $fehler[] = $code;
            }
        }
        $p = ['wach' => $wach, 'akku' => is_int($akku) ? self::alsFloat($akku) : null, 'fehler' => $fehler];
        foreach ([1 => 'status', 2 => 'pause', 3 => 'station', 4 => 'art', 7 => 'rueckfahrt', 10 => 'andocken', 12 => 'stationsarbeit'] as $nr => $name) {
            $p[$name] = isset($f3[$nr]) ? (int)self::pbEins($f3, $nr, 0) : null;
        }
        $p['f11'] = isset($d[11]) ? (int)self::pbEins($d, 11, 0) : null;
        $p['f47'] = isset($d[47]) ? (int)self::pbEins($d, 47, 0) : null;
        return $p;
    }

    public function normalisiere(string $modell, array $p): array
    {
        $w = [];
        if (isset($p['akku']) && is_numeric($p['akku'])) {
            $w['Akku'] = (int)max(0, min(100, round((float)$p['akku'])));
        }
        $fehler = (array)($p['fehler'] ?? []);
        $w['Fehler'] = $fehler === [] ? 'kein Fehler' : 'Fehlercode ' . implode(', ', $fehler);
        [$z, $text] = self::zustandAus($p, $w['Akku'] ?? null);
        $w['Zustand'] = $z;
        $w['ZustandRoh'] = $text . (isset($p['status']) ? ' (' . $p['status'] . ')' : '');
        $w['Laedt'] = $z === RoboHubModell::ZUSTAND_LAEDT;
        return ['werte' => $w, 'optionen' => [], 'schreibbar' => []];
    }

    /** Zustand nach models.py (is_docked, is_returning, is_paused, ACTIVE_CLEANING_STATUSES). */
    private static function zustandAus(array $p, ?int $akku): array
    {
        if (($p['fehler'] ?? []) !== []) {
            return [RoboHubModell::ZUSTAND_FEHLER, 'Fehler'];
        }
        if (empty($p['wach'])) {
            // Ohne Rundmeldung ist der Arbeitszustand der Antwort veraltet — nur „ruht" sagen.
            return [RoboHubModell::ZUSTAND_SCHLAF, 'Ruht (sendet gerade nichts)'];
        }
        $s = (int)($p['status'] ?? 0);
        // Neue Firmware meldet 2 auch beim Reinigen außerhalb der Station, dann mit 3.4 = 3 (#115/#124).
        $aktiv = in_array($s, self::AKTIV, true) || ($s === 2 && ($p['art'] ?? null) === 3);
        if ($aktiv && ($p['pause'] ?? 0) === 1) {
            return [RoboHubModell::ZUSTAND_PAUSIERT, 'Pausiert'];
        }
        if ($aktiv && ($p['rueckfahrt'] ?? 0) === 1 && ($p['andocken'] ?? 0) === 2) {
            return [RoboHubModell::ZUSTAND_ZURUECK, 'Fährt zur Station'];
        }
        if ($aktiv) {
            return [RoboHubModell::ZUSTAND_REINIGT, $s === 17 ? 'Reinigt ausgewählte Räume' : 'Reinigt'];
        }
        if ($s === 7) {
            return [RoboHubModell::ZUSTAND_KARTIERUNG, 'Kartiert'];
        }
        if ($s === 19) {
            return [RoboHubModell::ZUSTAND_ZURUECK, 'Fertig, fährt zur Station'];
        }
        $station = in_array($s, self::IN_STATION, true) || ($p['andocken'] ?? 0) === 1 || in_array($p['station'] ?? 0, [1, 6], true)
            || ($p['stationsarbeit'] ?? 0) > 0 || ($p['f11'] ?? 0) >= 2 || in_array($p['f47'] ?? 0, [1, 3], true);
        if ($station) {
            return ($akku ?? 0) >= 100 ? [RoboHubModell::ZUSTAND_GELADEN, 'In der Station, geladen'] : [RoboHubModell::ZUSTAND_LAEDT, 'Lädt'];
        }
        if ($s === 1) {
            return [RoboHubModell::ZUSTAND_BEREIT, 'Bereit'];
        }
        return [RoboHubModell::ZUSTAND_UNBEKANNT, 'Unbekannt'];
    }

    // =================================================================
    // Karte (Räume)
    // =================================================================

    public function karte(array $buendel, array $geraet): array
    {
        $r = $this->roboter($buendel, $geraet);
        $v = $this->verbinden((string)$r['ip']);
        try {
            $this->rund = [];
            $this->offen = [];
            [, $raeume] = $this->kartenRaeume($v, $r);
        } finally {
            $v->schliessen();
        }
        return ['raeume' => $raeume, 'bild' => '', 'marken' => [], 'station' => null];
    }

    /** map/get_map → [Karten-ID, [Raum-ID => Name]] (models.py MapData.from_response, Feld 2.1/2.12). */
    private function kartenRaeume($v, array $r): array
    {
        $antwort = self::pbLesen($this->anfrage($v, $r, 'map/get_map', '', 15.0));
        $karte = self::pbEins($antwort, 2, '');
        if (!is_string($karte) || $karte === '') {
            throw new RoboHubFehler('Der Narwal hat keine Karte geliefert — ist eine Karte angelegt?');
        }
        $k = self::pbLesen($karte);
        $raeume = [];
        foreach ($k[12] ?? [] as $roh) {
            $raum = self::pbLesen((string)$roh);
            $id = (int)self::pbEins($raum, 1, 0);
            if ($id <= 0) {
                continue;
            }
            $name = trim((string)self::pbEins($raum, 3, ''));
            if ($name === '') {
                $typ = (int)self::pbEins($raum, 2, 0);
                $nr = (int)self::pbEins($raum, 8, 0);
                $name = (self::RAUMTYPEN[$typ] ?? 'Raum') . ($nr > 1 ? ' ' . $nr : '');
            }
            $raeume[$id] = $name;
        }
        return [(int)self::pbEins($k, 1, 0), $raeume];
    }

    public function liveKarte(array $buendel, array $geraet): array
    {
        return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
    }

    // =================================================================
    // Befehle
    // =================================================================

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        $r = $this->roboter($buendel, $geraet);
        if (!in_array($befehl, ['start', 'raum', 'pause', 'stopp', 'station', 'orten'], true)) {
            throw new RoboHubFehler('Diesen Befehl kennt RoboHub für Narwal (noch) nicht: ' . $befehl);
        }
        $v = $this->verbinden((string)$r['ip']);
        try {
            $this->rund = [];
            $this->offen = [];
            $this->wecken($v, $r);
            $p = isset($this->rund['status/robot_base_status']) ? self::basisAus($this->rund['status/robot_base_status'], true) : [];
            [$z] = self::zustandAus($p + ['wach' => $p !== []], null);
            if ($befehl === 'start' && $z === RoboHubModell::ZUSTAND_PAUSIERT) {
                return $this->ausfuehren($v, $r, 'task/resume', '', 'Fortsetzen', 5.0);
            }
            if (isset(self::BEFEHLE[$befehl])) {
                [$thema, $text, $warten] = self::BEFEHLE[$befehl];
                return $this->ausfuehren($v, $r, $thema, '', $text, $warten);
            }
            [$kartenId, $raeume] = $this->kartenRaeume($v, $r);
            if ($befehl === 'raum') {
                $gewaehlt = array_values(array_unique(array_map('intval', (array)($parameter['raeume'] ?? []))));
                $fremd = array_diff($gewaehlt, array_keys($raeume));
                if ($gewaehlt === [] || $fremd !== []) {
                    throw new RoboHubFehler('Unbekannte Raum-ID(s): ' . implode(', ', $fremd ?: ['keine gewählt']) . ' — „Räume laden" im Roboter-Formular zeigt die gültigen.');
                }
                $text = 'Räume reinigen (' . implode(', ', array_map(function ($id) use ($raeume) { return $raeume[$id]; }, $gewaehlt)) . ')';
            } else {
                $gewaehlt = array_keys($raeume);
                $text = 'Start (alle ' . count($gewaehlt) . ' Räume)';
            }
            if ($kartenId === 0 || $gewaehlt === []) {
                throw new RoboHubFehler('Der Narwal hat keine Karte mit Räumen — bitte zuerst in der App kartieren.');
            }
            return $this->ausfuehren($v, $r, 'clean/start_clean', self::reinigungsAuftrag($kartenId, $gewaehlt), $text, 10.0);
        } finally {
            $v->schliessen();
        }
    }

    /**
     * StartClean{1: CleanTask{1: Karte, 2: [CleanItem{1: {1: 1, 2: Raum}, 2: CleanParam, 3: Reihenfolge}], 3: {}, 5: Art}}
     * mit den App-Vorgaben „Saugen und Wischen", Saugstufe normal, Wasser normal, ein Durchgang
     * (client.py _build_start_clean_payload, _WORK_MODE_PARAM VACUUM_AND_MOP = (4, Feld 7)).
     */
    public static function reinigungsAuftrag(int $kartenId, array $raeume): string
    {
        $param = self::pbZahl(1, 4) . self::pbZahl(2, 2) . self::pbZahl(3, 1) . self::pbZahl(4, 2) . self::pbZahl(7, 1);
        $auftrag = self::pbZahl(1, $kartenId);
        foreach (array_values($raeume) as $i => $id) {
            $auftrag .= self::pbTeil(2, self::pbTeil(1, self::pbZahl(1, 1) . self::pbZahl(2, (int)$id)) . self::pbTeil(2, $param) . self::pbZahl(3, $i + 1));
        }
        $auftrag .= self::pbTeil(3, '') . self::pbZahl(5, 4);
        return self::pbTeil(1, $auftrag);
    }

    private function ausfuehren($v, array $r, string $thema, string $nutzlast, string $text, float $warten): string
    {
        $antwort = self::pbLesen($this->anfrage($v, $r, $thema, $nutzlast, $warten));
        $code = self::pbEins($antwort, 1, 0);
        $code = is_int($code) ? $code : 1;   // Feld 1 als Daten statt Zahl = Datenantwort = angenommen (client.py)
        if (!in_array($code, [0, 1, 6], true)) {
            throw new RoboHubFehler('Narwal lehnt „' . $text . '" ab: ' . (self::ABGELEHNT[$code] ?? 'Code ' . $code) . '.');
        }
        return 'Befehl „' . $text . '" gesendet.';
    }

    public function zuruecksetzen(array $buendel, array $geraet, string $teil): string
    {
        throw new RoboHubFehler('Verschleißteile zurücksetzen ist bei Narwal noch nicht eingebaut — bitte in der App.');
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
    // Sitzung: senden, Antworten zuordnen, Rundmeldungen sammeln
    // =================================================================

    /** Weckfolge (client.py _build_wake_commands), dann bis zur ersten Rundmeldung warten. */
    private function wecken($v, array $r): void
    {
        $this->bestaetigt($v, $r, 'common/notify_app_event', self::pbZahl(1, 1));
        $this->bestaetigt($v, $r, 'common/active_robot_publish', self::abo(600));
        $this->bestaetigt($v, $r, 'common/active_robot_publish', self::pbZahl(1, 600));
        $v->senden(self::rahmen(self::thema($r, 'status/app_status_heartbeat'), self::pbZahl(1, 1)));   // nie beantwortet
        $this->bestaetigt($v, $r, 'status/get_device_base_status', '');
        $ende = microtime(true) + $this->weckenWarten;
        while (!isset($this->rund['status/robot_base_status']) && $this->lies($v, $ende)) {
        }
        if (!isset($this->rund['status/robot_base_status'])) {
            $this->dbg('Narwal', 'keine Rundmeldung nach dem Wecken — Befehl geht trotzdem raus');
        }
    }

    /**
     * Anfrage, deren Bestätigung niemand braucht — sie bekommt trotzdem ihren Platz in der
     * Reihenfolge, aber nur 5 s lang (client.py UNAWAITED_ACK_WINDOW), damit eine nie
     * gesendete Bestätigung nicht die echte Antwort verschluckt.
     */
    private function bestaetigt($v, array $r, string $kurz, string $nutzlast): void
    {
        $v->senden(self::rahmen(self::thema($r, $kurz), $nutzlast));
        $this->offen[] = microtime(true) + 5.0;
    }

    /** true, wenn die eingetroffene Antwort eine offene Bestätigung war (und damit verbraucht ist). */
    private function bestaetigungVerbrauchen(): bool
    {
        $jetzt = microtime(true);
        while ($this->offen !== []) {
            if (array_shift($this->offen) >= $jetzt) {
                return true;
            }
        }
        return false;
    }

    /** Anfrage senden und die zugehörige Antwort (nach allen offenen Bestätigungen) liefern. */
    private function anfrage($v, array $r, string $kurz, string $nutzlast, float $warten): string
    {
        $v->senden(self::rahmen(self::thema($r, $kurz), $nutzlast));
        $this->dbg('Narwal', '→ ' . $kurz . ' (' . strlen($nutzlast) . ' Bytes)');
        $ende = microtime(true) + $warten;
        while (true) {
            $antwort = $this->lies($v, $ende, true);
            if ($antwort === null) {
                throw new RoboHubFehler('Der Narwal antwortet nicht auf „' . $kurz . '" — eingeschaltet und im WLAN?');
            }
            if ($this->bestaetigungVerbrauchen()) {
                continue;   // Bestätigung einer früheren Anfrage
            }
            return $antwort;
        }
    }

    /**
     * Einen Rahmen lesen. Rundmeldungen werden gemerkt; mit $antwort = true wird die
     * nächste Antwort (Feld 5) zurückgegeben. Ohne: true, solange gelesen wurde.
     */
    private function lies($v, float $ende, bool $antwort = false)
    {
        while (($roh = $v->empfangen($ende)) !== null) {
            try {
                [$feld, $thema, $nutz] = self::zerlege($roh);
            } catch (RoboHubFehler $e) {
                continue;
            }
            if ($feld === 5) {
                if ($antwort) {
                    return $nutz;
                }
                $this->bestaetigungVerbrauchen();
                return true;
            }
            $teile = explode('/', $thema, 4);
            if (count($teile) === 4) {
                $this->rund[$teile[3]] = $nutz;
            }
            if (!$antwort) {
                return true;
            }
        }
        return $antwort ? null : false;
    }

    private static function thema(array $r, string $kurz): string
    {
        return '/' . $r['produkt'] . '/' . $r['did'] . '/' . $kurz;
    }

    /** active_robot_publish: je Thema TopicDuration{1: Thema, 2: Sekunden} (client.py _build_topic_subscription). */
    private static function abo(int $sekunden): string
    {
        $aus = '';
        foreach (self::RUNDMELDUNGEN as $t) {
            $aus .= self::pbTeil(1, self::pbTeil(1, $t) . self::pbZahl(2, $sekunden));
        }
        return $aus;
    }

    // =================================================================
    // Rahmen und Protobuf (ohne Schema)
    // =================================================================

    /** 01 <len+2> 22 <len> Thema Nutzlast (protocol.py build_frame; Kopfbyte muss stimmen, sonst legt der Roboter auf). */
    public static function rahmen(string $thema, string $nutzlast): string
    {
        $n = strlen($thema);
        if ($n === 0 || $n > 125) {
            throw new RoboHubFehler('Narwal-Thema hat eine ungültige Länge.');
        }
        return "\x01" . chr($n + 2) . "\x22" . chr($n) . $thema . $nutzlast;
    }

    /** Rahmen → [Feld 4|5, Thema, Nutzlast] (protocol.py parse_frame). */
    public static function zerlege(string $roh): array
    {
        if (strlen($roh) < 4 || $roh[0] !== "\x01" || !in_array($roh[2], ["\x22", "\x2a"], true)) {
            throw new RoboHubFehler('Unbekannter Narwal-Rahmen.');
        }
        $n = ord($roh[3]);
        if (strlen($roh) < 4 + $n) {
            throw new RoboHubFehler('Narwal-Rahmen abgeschnitten.');
        }
        return [$roh[2] === "\x2a" ? 5 : 4, substr($roh, 4, $n), (string)substr($roh, 4 + $n)];
    }

    /** Protobuf lesen: [Feld => [Wert, …]]; Varint/fixed32/fixed64 als int, Längenfelder als Bytes. */
    public static function pbLesen(string $b): array
    {
        $aus = [];
        $i = 0;
        $n = strlen($b);
        while ($i < $n) {
            $schluessel = self::varint($b, $i);
            if ($schluessel === null) {
                return $aus;
            }
            $feld = $schluessel >> 3;
            switch ($schluessel & 7) {
                case 0:
                    $wert = self::varint($b, $i);
                    break;
                case 1:
                    if ($i + 8 > $n) {
                        return $aus;
                    }
                    $wert = unpack('P', substr($b, $i, 8))[1];
                    $i += 8;
                    break;
                case 2:
                    $laenge = self::varint($b, $i);
                    if ($laenge === null || $i + $laenge > $n) {
                        return $aus;
                    }
                    $wert = (string)substr($b, $i, $laenge);
                    $i += $laenge;
                    break;
                case 5:
                    if ($i + 4 > $n) {
                        return $aus;
                    }
                    $wert = unpack('V', substr($b, $i, 4))[1];
                    $i += 4;
                    break;
                default:
                    return $aus;   // Gruppen u. Ä. kommen nicht vor
            }
            if ($wert === null) {
                return $aus;
            }
            $aus[$feld][] = $wert;
        }
        return $aus;
    }

    private static function varint(string $b, int &$i): ?int
    {
        $wert = 0;
        $schub = 0;
        $n = strlen($b);
        while ($i < $n && $schub < 64) {
            $c = ord($b[$i++]);
            $wert |= ($c & 0x7F) << $schub;
            if (($c & 0x80) === 0) {
                return $wert;
            }
            $schub += 7;
        }
        return null;
    }

    public static function pbEins(array $m, int $feld, $vorgabe)
    {
        return $m[$feld][0] ?? $vorgabe;
    }

    public static function pbVarint(int $wert): string
    {
        $aus = '';
        while ($wert > 0x7F || $wert < 0) {
            $aus .= chr(($wert & 0x7F) | 0x80);
            $wert = ($wert >> 7) & PHP_INT_MAX >> 6;
        }
        return $aus . chr($wert);
    }

    public static function pbZahl(int $feld, int $wert): string
    {
        return self::pbVarint($feld << 3) . self::pbVarint($wert);
    }

    public static function pbTeil(int $feld, string $daten): string
    {
        return self::pbVarint(($feld << 3) | 2) . self::pbVarint(strlen($daten)) . $daten;
    }

    /** fixed32-Bitmuster als float32 (models.py _to_float32: Akku 1118175232 → 83,0). */
    public static function alsFloat(int $bits): float
    {
        return unpack('g', pack('V', $bits & 0xFFFFFFFF))[1];
    }

    // =================================================================
    // Verbindung (im Prüfstand ersetzbar)
    // =================================================================

    /** @return RoboHubNarwalVerbindung */
    protected function verbinden(string $ip)
    {
        return RoboHubNarwalVerbindung::oeffnen($ip, self::PORT, microtime(true) + 8);
    }

    private function dbg(string $thema, string $text): void
    {
        if (is_callable($this->debug)) {
            ($this->debug)($thema, $text);
        }
    }
}

/** Unverschlüsseltes WebSocket zum Narwal (RFC 6455), ganze Binärnachrichten. */
class RoboHubNarwalVerbindung
{
    private $sock;
    private $roh = '';
    private $teil = '';
    /** @var string[] fertige Nachrichten */
    private $fertig = [];
    /** Der Roboter hat geschlossen (Schließen-Rahmen oder Verbindungsende). */
    private $zu = false;

    private function __construct($sock, string $rest)
    {
        $this->sock = $sock;
        $this->roh = $rest;
    }

    public static function oeffnen(string $ip, int $port, float $ende): self
    {
        $host = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
        $sock = @stream_socket_client('tcp://' . $host . ':' . $port, $nr, $text, 5);
        if ($sock === false) {
            throw new RoboHubFehler('Narwal unter ' . $ip . ':' . $port . ' nicht erreichbar (' . $text . ').');
        }
        stream_set_blocking($sock, true);
        $schluessel = base64_encode(random_bytes(16));
        fwrite($sock, 'GET / HTTP/1.1' . "\r\nHost: " . $host . ':' . $port . "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: " . $schluessel . "\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $kopf = '';
        while (strpos($kopf, "\r\n\r\n") === false) {
            $rest = $ende - microtime(true);
            $lesen = [$sock];
            $schreiben = null;
            $ausnahme = null;
            if ($rest <= 0 || @stream_select($lesen, $schreiben, $ausnahme, (int)$rest, (int)(($rest - floor($rest)) * 1000000)) < 1) {
                fclose($sock);
                throw new RoboHubFehler('Narwal nimmt die Verbindung nicht an (keine Antwort auf Port ' . $port . ').');
            }
            $stueck = fread($sock, 4096);
            if ($stueck === false || $stueck === '') {
                fclose($sock);
                throw new RoboHubFehler('Narwal hat die Verbindung geschlossen.');
            }
            $kopf .= $stueck;
        }
        [$antwort, $rest] = explode("\r\n\r\n", $kopf, 2);
        if (!preg_match('#^HTTP/1\.1 101#', $antwort) || !preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $antwort, $m) || $m[1] !== RoboHubMqttSitzung::wsAkzeptanz($schluessel)) {
            fclose($sock);
            throw new RoboHubFehler('Narwal lehnt die WebSocket-Verbindung ab: ' . strtok($antwort, "\r\n"));
        }
        return new self($sock, $rest);
    }

    public function senden(string $nachricht): void
    {
        @fwrite($this->sock, RoboHubMqttSitzung::wsRahmen($nachricht, random_bytes(4)));
    }

    /** Nächste vollständige Binärnachricht oder null bei Fristablauf/Verbindungsende. */
    public function empfangen(float $ende): ?string
    {
        while ($this->fertig === [] && !$this->zu) {
            foreach (RoboHubMqttSitzung::wsLesen($this->roh) as $r) {
                if ($r['op'] === 0x9) {
                    @fwrite($this->sock, RoboHubMqttSitzung::wsRahmen($r['daten'], random_bytes(4), 0xA));
                } elseif ($r['op'] === 0x8) {
                    // Erst die schon fertigen Nachrichten ausliefern — Antwort und Schließen kommen oft im selben Paket.
                    $this->zu = true;
                    break;
                } elseif ($r['op'] === 0x2 || $r['op'] === 0x0) {
                    $this->teil .= $r['daten'];
                    if ($r['fin']) {
                        $this->fertig[] = $this->teil;
                        $this->teil = '';
                    }
                }
            }
            if ($this->fertig !== [] || $this->zu) {
                break;
            }
            $rest = $ende - microtime(true);
            $lesen = [$this->sock];
            $schreiben = null;
            $ausnahme = null;
            if ($rest <= 0 || @stream_select($lesen, $schreiben, $ausnahme, (int)$rest, (int)(($rest - floor($rest)) * 1000000)) < 1) {
                return null;
            }
            $stueck = fread($this->sock, 65536);
            if ($stueck === false || ($stueck === '' && feof($this->sock))) {
                $this->zu = true;
                break;
            }
            $this->roh .= $stueck;
        }
        return $this->fertig === [] ? null : array_shift($this->fertig);
    }

    public function schliessen(): void
    {
        if (is_resource($this->sock)) {
            @fwrite($this->sock, RoboHubMqttSitzung::wsRahmen('', random_bytes(4), 0x8));
            @fclose($this->sock);
        }
    }
}
