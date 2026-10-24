<?php

declare(strict_types=1);

// ===========================================================================
// RoboHub-Treiber für iRobot Roomba/Braava — lokal im Heimnetz, ohne Cloud.
//
// Quelle: pschmitt/roombapy (MIT, Stand 06.10.2026): discovery.py (UDP 5678
// „irobotmcs"), getpassword.py (Passwort nach HOME-Taste, Magie f005efcc3b2900),
// roomba.py (MQTT: Client-ID = Benutzer = BLID, Befehle auf „cmd"),
// state.py/const.py (Phasen, Fehlercodes).
//
// Sicherheit (Dietmars Entscheidung 13.10.2026): Jeder Roboter hat ein selbst
// ausgestelltes Zertifikat, das sich gegen keine Wurzel prüfen lässt. RoboHub
// merkt sich beim Koppeln dessen SHA-256-Fingerabdruck und lehnt danach jede
// Verbindung mit einem anderen Zertifikat ab (Trust on first use). Das
// Gerätepasswort bleibt im Bündel (Konto-Attribut).
//
// Ein Roboter erlaubt nur eine lokale Verbindung gleichzeitig; RoboHub hält
// deshalb keine dauerhafte, sondern je Abruf eine kurze Sitzung.
// Noch nicht gebaut: Räume (Namen nur in der Cloud), Karte, Echtzeit, Zeitpläne.
// ===========================================================================

class RoboHubIrobot implements RoboHubTreiber
{
    /** @var callable|null fn(string $thema, string $text) */
    public $debug = null;

    /** true, wenn der letzte stand() den Roboter erreichte, aber nur Nebenmeldungen bekam. */
    private $nurStandFehlte = false;

    /** Ports des Roboters (im Prüfstand auf einen nachgestellten Roboter umlenkbar). */
    public $port = 8883;
    public $suchPort = 5678;
    private const PASSWORT_ANFRAGE = "\xf0\x05\xef\xcc\x3b\x29\x00";
    private const NICHT_UNTERSTUETZT = "\xf0\x05\xef\xcc\x3b\x29\x03";

    // cleanMissionStatus.phase → [Text, Zustand]; „charge" und „stop" hängen vom Zyklus ab.
    private const PHASEN = [
        'run'           => ['Reinigt', RoboHubModell::ZUSTAND_REINIGT],
        'stuck'         => ['Steckt fest', RoboHubModell::ZUSTAND_FEHLER],
        'hmUsrDock'     => ['Fährt zur Station', RoboHubModell::ZUSTAND_ZURUECK],
        'hmMidMsn'      => ['Fährt zum Laden', RoboHubModell::ZUSTAND_ZURUECK],
        'hmPostMsn'     => ['Fährt nach der Reinigung zurück', RoboHubModell::ZUSTAND_ZURUECK],
        'dock'          => ['Dockt an', RoboHubModell::ZUSTAND_ZURUECK],
        'evac'          => ['Entleert den Behälter', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        'chargingerror' => ['Ladefehler', RoboHubModell::ZUSTAND_FEHLER],
        'pause'         => ['Pausiert', RoboHubModell::ZUSTAND_PAUSIERT],
        'cancelled'     => ['Abgebrochen', RoboHubModell::ZUSTAND_BEREIT],
    ];

    // const.py ROOMBA_ERROR_MESSAGES (Auszug, deutsch).
    private const FEHLER = [
        1 => 'Linkes Rad in der Luft', 2 => 'Hauptbürsten blockiert', 3 => 'Rechtes Rad in der Luft', 4 => 'Linkes Rad blockiert',
        5 => 'Rechtes Rad blockiert', 6 => 'Steckt an einer Kante fest', 7 => 'Fehler linkes Rad', 8 => 'Behälterfehler',
        9 => 'Stoßfänger klemmt', 10 => 'Fehler rechtes Rad', 11 => 'Behälterfehler', 12 => 'Absturzsensor', 13 => 'Beide Räder in der Luft',
        14 => 'Behälter fehlt', 15 => 'Neu gestartet', 16 => 'Unerwarteter Stoß', 17 => 'Weg versperrt', 18 => 'Andockproblem',
        19 => 'Abdockproblem', 20 => 'Andockproblem', 21 => 'Navigationsproblem', 22 => 'Navigationsproblem', 23 => 'Akkuproblem',
        24 => 'Navigationsproblem', 25 => 'Neustart nötig', 26 => 'Saugproblem', 27 => 'Saugproblem', 29 => 'Software-Update nötig',
        30 => 'Saugproblem', 31 => 'Neustart nötig', 32 => 'Kartenproblem', 33 => 'Weg versperrt', 34 => 'Neustart nötig',
        35 => 'Wischen nicht möglich', 36 => 'Behälter voll', 37 => 'Tank nachfüllen', 39 => 'Neustart nötig', 40 => 'Navigationsproblem',
        41 => 'Zeitüberschreitung', 42 => 'Ortungsproblem', 43 => 'Navigationsproblem', 44 => 'Pumpenproblem', 45 => 'Deckel offen',
        46 => 'Akku schwach', 47 => 'Neustart nötig', 48 => 'Weg versperrt', 52 => 'Pad prüfen', 53 => 'Software-Update nötig',
        101 => 'Akku nicht verbunden', 102 => 'Ladefehler', 103 => 'Ladefehler', 104 => 'Kein Ladestrom', 106 => 'Akku zu warm',
        116 => 'Ladefehler der Station', 119 => 'Ladezeit überschritten', 121 => 'Ladekontakte reinigen',
    ];

    // =================================================================
    // Koppeln statt Anmelden
    // =================================================================

    /**
     * Roboter unter $ip finden, Passwort holen (HOME-Taste muss gerade
     * gedrückt worden sein) und Zertifikat merken. Ergänzt das vorhandene Bündel.
     */
    public function koppeln(string $ip, array $buendel): array
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new RoboHubFehler('Bitte die IP-Adresse des Roboters eintragen (z. B. 192.168.1.50).');
        }
        $info = $this->suchen($ip);
        $hostname = (string)($info['hostname'] ?? '');
        if (!preg_match('/^(roomba|irobot)-(.+)$/i', $hostname, $m)) {
            throw new RoboHubFehler('Unter ' . $ip . ' antwortet kein iRobot-Roboter.');
        }
        $blid = $m[2];
        [$passwort, $fingerabdruck] = $this->passwortHolen($ip);
        $buendel['marke'] = 'irobot';
        $buendel['roboter'][$blid] = [
            'blid' => $blid, 'ip' => $ip, 'passwort' => $passwort, 'fingerabdruck' => $fingerabdruck,
            'name' => (string)($info['robotname'] ?? 'Roomba'), 'sku' => (string)($info['sku'] ?? ''),
            'firmware' => (string)($info['sw'] ?? ''), 'mac' => (string)($info['mac'] ?? ''),
        ];
        return $buendel;
    }

    public function anmelden(string $konto, string $passwort, string $region): array
    {
        return $this->koppeln($konto, []);
    }

    public function erneuern(array $buendel): array
    {
        if (empty($buendel['roboter'])) {
            $e = new RoboHubFehler('Noch kein Roboter gekoppelt.');
            $e->anmeldungNoetig = true;
            throw $e;
        }
        return $buendel;
    }

    /**
     * UDP-Anfrage „irobotmcs" an Port 5678 (Unicast an die IP), bis zu drei
     * Versuche. Eine Antwort wird eine Stunde gemerkt: Manche Roboter (Roomba 980,
     * Forum 20.10.2026) schweigen auf UDP, solange nach dem HOME-Druck das
     * Koppelfenster offen ist — dann gilt die Antwort vom Klick davor.
     */
    protected function suchen(string $ip): array
    {
        $merk = $this->suchMerkDatei($ip);
        $sock = @stream_socket_client('udp://' . $ip . ':' . $this->suchPort, $nr, $text, 3);
        if ($sock === false) {
            throw new RoboHubFehler('Roboter unter ' . $ip . ' nicht erreichbar: ' . $text);
        }
        $antwort = null;
        try {
            for ($versuch = 0; $versuch < 3 && $antwort === null; $versuch++) {
                @fwrite($sock, 'irobotmcs');
                $lesen = [$sock];
                $schreiben = null;
                $ausnahme = null;
                if (@stream_select($lesen, $schreiben, $ausnahme, 2) > 0) {
                    $roh = json_decode((string)@fread($sock, 4096), true);
                    $antwort = is_array($roh) ? $roh : null;
                }
            }
        } finally {
            fclose($sock);
        }
        if ($antwort !== null) {
            @file_put_contents($merk, json_encode($antwort));
            return $antwort;
        }
        $gemerkt = is_file($merk) && filemtime($merk) > time() - 3600 ? json_decode((string)file_get_contents($merk), true) : null;
        if (is_array($gemerkt)) {
            $this->dbg('Koppeln', 'UDP still, Antwort vom letzten Versuch verwendet');
            return $gemerkt;
        }
        throw new RoboHubFehler('Unter ' . $ip . ' meldet sich kein iRobot-Roboter (UDP 5678). Bitte zuerst OHNE HOME-Taste „Jetzt anmelden" klicken, damit RoboHub den Roboter findet; danach HOME halten und erneut klicken.');
    }

    /** Merkdatei für die letzte UDP-Antwort unter dieser Adresse. */
    public function suchMerkDatei(string $ip): string
    {
        return sys_get_temp_dir() . '/robohub-irobot-' . md5($ip) . '.json';
    }

    /** Passwort per TLS-Magie holen; Rückgabe [Passwort, SHA-256 des Zertifikats]. */
    protected function passwortHolen(string $ip): array
    {
        [$leitung, $fingerabdruck] = $this->geraetVerbinden($ip, null);
        try {
            self::leitungSchreiben($leitung, self::PASSWORT_ANFRAGE);
            $roh = '';
            $laenge = 35;
            $ende = microtime(true) + 10;
            while (strlen($roh) < $laenge + 2 && microtime(true) < $ende) {
                $stueck = self::leitungLesen($leitung, 2.0);
                if ($stueck === null || $stueck === '') {
                    break;
                }
                if ($stueck === self::NICHT_UNTERSTUETZT) {
                    throw new RoboHubFehler('Dieses Modell gibt sein Passwort nur über die iRobot-Cloud heraus — lokal noch nicht unterstützt.');
                }
                $roh .= $stueck;
                if (strlen($roh) >= 2) {
                    $laenge = ord($roh[1]);
                }
            }
        } finally {
            self::leitungSchliessen($leitung);
        }
        $passwort = self::passwortAus($roh);
        if ($passwort === '') {
            throw new RoboHubFehler('Roboter gefunden, aber er hat kein Passwort geliefert. Er muss auf der Station stehen; HOME-Taste etwa 2 Sekunden halten, bis Töne kommen, dann sofort erneut koppeln.');
        }
        return [$passwort, $fingerabdruck];
    }

    /** Antwort der Passwort-Magie: ab Byte 7, Null-Bytes am Ende weg. */
    public static function passwortAus(string $roh): string
    {
        return strlen($roh) > 7 ? rtrim(substr($roh, 7), "\0") : '';
    }

    /**
     * TLS zum Roboter. $erwartet = null nur beim Koppeln (Zertifikat wird gemerkt);
     * sonst muss der Fingerabdruck übereinstimmen, sonst wird abgebrochen.
     * Erst über OpenSSL; scheitert der Handschlag (ältere Firmware ohne RFC 5746,
     * von OpenSSL 3 abgelehnt), über den eigenen TLS-1.2-Client.
     * Rückgabe [Leitung (Strom oder RoboHubTls), Fingerabdruck].
     */
    private function geraetVerbinden(string $ip, ?string $erwartet): array
    {
        $beginn = microtime(true);
        try {
            [$leitung, $fingerabdruck] = $this->tlsVerbinden($ip);
        } catch (RoboHubFehler $e) {
            // Schnell abgewiesen (Port belegt, der Roboter erlaubt nur eine Verbindung und gibt
            // sie verzögert frei) → einmal kurz warten und neu versuchen. Zeitüberschreitungen nicht.
            if (microtime(true) - $beginn > 3.0) {
                throw $e;
            }
            $this->dbg('TLS', 'Abgewiesen, neuer Versuch in 3 s: ' . $e->getMessage());
            sleep(3);
            [$leitung, $fingerabdruck] = $this->tlsVerbinden($ip);
        }
        if (!self::fingerabdruckPasst($erwartet, $fingerabdruck)) {
            self::leitungSchliessen($leitung);
            throw new RoboHubFehler('Das Zertifikat des Roboters unter ' . $ip . ' hat sich geändert — Verbindung abgelehnt. War es ein anderes Gerät, bitte neu koppeln.');
        }
        return [$leitung, $fingerabdruck];
    }

    /**
     * Erst der Weg, der zuletzt bei dieser Adresse geklappt hat (gemerkt in einer Temp-Datei),
     * dann der andere. So kostet ein 980 nicht bei jedem Abruf einen vergeblichen
     * OpenSSL-Versuch — der belegt beim Roboter kurz die einzige Verbindung.
     */
    private function tlsVerbinden(string $ip): array
    {
        $merk = sys_get_temp_dir() . '/robohub-irobot-tls-' . md5($ip . ':' . $this->port) . '.txt';
        $wege = @file_get_contents($merk) === 'eigen' ? ['eigen', 'openssl'] : ['openssl', 'eigen'];
        $fehler = [];
        foreach ($wege as $weg) {
            try {
                $ergebnis = $weg === 'eigen' ? $this->eigenVerbinden($ip) : $this->opensslVerbinden($ip);
                @file_put_contents($merk, $weg);
                return $ergebnis;
            } catch (RoboHubFehler $e) {
                $fehler[$weg] = $e->getMessage();
                $this->dbg('TLS', ($weg === 'eigen' ? 'Eigener TLS-Weg' : 'OpenSSL') . ': ' . $e->getMessage());
            }
        }
        throw new RoboHubFehler('Roboter unter ' . $ip . ' nicht erreichbar (' . ($fehler['openssl'] ?? '') . ' / eigener TLS-Weg: ' . ($fehler['eigen'] ?? '') . ').');
    }

    protected function opensslVerbinden(string $ip): array
    {
        $kontext = stream_context_create(['ssl' => [
            // Selbst ausgestelltes Gerätezertifikat: statt Kette wird der gemerkte Fingerabdruck geprüft.
            'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
            'capture_peer_cert' => true, 'ciphers' => 'DEFAULT:!DH', 'security_level' => 0,
        ]]);
        while (openssl_error_string() !== false) {
        }
        error_clear_last();
        $sock = @stream_socket_client('tls://' . $ip . ':' . $this->port, $nr, $text, 8, STREAM_CLIENT_CONNECT, $kontext);
        if ($sock === false) {
            $gruende = [];
            while (($o = openssl_error_string()) !== false) {
                $gruende[] = $o;
            }
            $letzte = error_get_last()['message'] ?? '';
            if ($gruende === [] && $letzte !== '') {
                $gruende[] = preg_replace('/^stream_socket_client\(\): /', '', $letzte);
            }
            if (trim((string)$text) !== '') {
                array_unshift($gruende, trim((string)$text));
            }
            throw new RoboHubFehler($gruende === [] ? 'TLS-Verbindung fehlgeschlagen' : implode('; ', array_unique($gruende)));
        }
        $zert = stream_context_get_params($sock)['options']['ssl']['peer_certificate'] ?? null;
        return [$sock, $zert ? (string)openssl_x509_fingerprint($zert, 'sha256') : ''];
    }

    protected function eigenVerbinden(string $ip): array
    {
        $tls = RoboHubTls::verbinden($ip, $this->port, 8.0);
        return [$tls, $tls->zertifikat === '' ? '' : hash('sha256', $tls->zertifikat)];
    }

    /** @param resource|RoboHubTls $leitung */
    private static function leitungSchreiben($leitung, string $daten): void
    {
        if ($leitung instanceof RoboHubTls) {
            $leitung->schreiben($daten);
        } else {
            fwrite($leitung, $daten);
        }
    }

    /** @param resource|RoboHubTls $leitung  Rückgabe Daten, '' bei Frist, null bei Ende. */
    private static function leitungLesen($leitung, float $sekunden): ?string
    {
        if ($leitung instanceof RoboHubTls) {
            return $leitung->lesen($sekunden);
        }
        $lesen = [$leitung];
        $schreiben = null;
        $ausnahme = null;
        if (@stream_select($lesen, $schreiben, $ausnahme, (int)$sekunden, (int)(($sekunden - floor($sekunden)) * 1000000)) < 1) {
            return '';
        }
        $stueck = fread($leitung, 1024);
        return $stueck === false || $stueck === '' ? null : $stueck;
    }

    /** @param resource|RoboHubTls $leitung */
    private static function leitungSchliessen($leitung): void
    {
        if ($leitung instanceof RoboHubTls) {
            $leitung->schliessen();
        } else {
            @fclose($leitung);
        }
    }

    /** Trust on first use: beim Koppeln (null) jedes Zertifikat, danach nur das gemerkte. */
    public static function fingerabdruckPasst(?string $erwartet, string $ist): bool
    {
        if ($ist === '') {
            return false;
        }
        return $erwartet === null || hash_equals(strtolower($erwartet), strtolower($ist));
    }

    // =================================================================
    // Geräte und Zustand
    // =================================================================

    public function geraete(array $buendel): array
    {
        $liste = [];
        foreach ((array)($buendel['roboter'] ?? []) as $r) {
            $liste[] = [
                'id'     => (string)$r['blid'],
                'name'   => (string)($r['name'] ?: 'Roomba'),
                'modell' => 'irobot.' . (stripos((string)$r['sku'], 'm') === 0 ? 'mop' : 'vacuum') . '.' . strtolower((string)($r['sku'] ?: 'roomba')),
                'klasse' => RoboHubModell::KLASSE_SAUGER,
                'online' => true,
                'roh'    => ['sku' => $r['sku'] ?? null, 'firmware' => $r['firmware'] ?? null, 'ip' => $r['ip'] ?? null],
            ];
        }
        return $liste;
    }

    public function zustand(array $buendel, array $geraet): array
    {
        $r = $this->roboter($buendel, $geraet);
        try {
            $p = $this->stand($r);
            $p['_abgerufen'] = time();
        } catch (RoboHubFehler $e) {
            // Erreichbar, aber diesmal nur Funkmeldungen (wifistat) statt des Stands — beim 980
            // sporadisch (Forum Thilo 24.10.2026). Dann den letzten Stand weiterführen, solange
            // er höchstens 15 Minuten alt ist.
            $alt = (array)($geraet['stand'] ?? []);
            $alter = time() - (int)($alt['_abgerufen'] ?? 0);
            if (!$this->nurStandFehlte || !isset($alt['cleanMissionStatus']) || $alter > 900) {
                throw $e;
            }
            $this->dbg('Zustand', 'Kein neuer Stand, letzter von vor ' . $alter . ' s bleibt');
            $p = $alt;
        }
        $daten = $this->normalisiere((string)($geraet['modell'] ?? ''), $p);
        $daten['online'] = true;
        $daten['bloecke'] = ['ok' => [0], 'fehl' => []];
        $daten['roh'] = ['modell' => $geraet['modell'] ?? null, 'properties' => $p];
        return $daten;
    }

    private function roboter(array $buendel, array $geraet): array
    {
        $r = $buendel['roboter'][(string)$geraet['id']] ?? null;
        if (!is_array($r) || ($r['passwort'] ?? '') === '' || ($r['fingerabdruck'] ?? '') === '') {
            throw new RoboHubFehler('Roboter ist nicht gekoppelt — bitte in der Konto-Instanz koppeln.');
        }
        return $r;
    }

    /** Kurze Sitzung: Zustand sammeln, bis Phase und Akku gemeldet sind. */
    protected function stand(array $r): array
    {
        $this->nurStandFehlte = false;
        $stand = [];
        $themen = [];
        $beginn = microtime(true);
        // Ältere Roboter (980) schicken ihren Stand langsam und in vielen Stücken → 15 s Fenster.
        $this->sitzung($r, function (RoboHubMqttSitzung $s, float $ende) use (&$stand, &$themen): void {
            $s->senden(RoboHubMqtt::subscribe(1, ['#'], 0));
            $s->warte(RoboHubMqtt::PUBLISH, function (array $paket) use (&$stand, &$themen): bool {
                $pub = RoboHubMqtt::publishLesen($paket['flags'], $paket['body']);
                $themen[] = $pub['topic'] . ' (QoS ' . $pub['qos'] . ', ' . strlen($pub['payload']) . ' B)';
                $j = json_decode($pub['payload'], true);
                if (is_array($j)) {
                    self::zusammenfuehren($stand, (array)($j['state']['reported'] ?? []));
                }
                return isset($stand['cleanMissionStatus']['phase'], $stand['batPct'], $stand['bin']);
            }, $ende);
        }, 15.0);
        $this->dbg('Zustand', count($themen) . ' Meldung(en) in ' . round(microtime(true) - $beginn, 1) . ' s: ' . implode(', ', array_slice($themen, 0, 20)));
        if (!isset($stand['cleanMissionStatus']) && !isset($stand['batPct'])) {
            $this->nurStandFehlte = $themen !== [];
            throw new RoboHubFehler('Der Roboter hat keinen Zustand gemeldet (' . count($themen) . ' Meldung(en) in '
                . round(microtime(true) - $beginn, 1) . ' s' . ($themen === [] ? '' : ': ' . implode(', ', array_slice($themen, 0, 5))) . ').');
        }
        return $stand;
    }

    /** Rekursives Zusammenführen wie roombapy dict_merge. */
    public static function zusammenfuehren(array &$ziel, array $neu): void
    {
        foreach ($neu as $k => $v) {
            if (isset($ziel[$k]) && is_array($ziel[$k]) && is_array($v) && array_keys($v) !== range(0, count($v) - 1)) {
                self::zusammenfuehren($ziel[$k], $v);
            } else {
                $ziel[$k] = $v;
            }
        }
    }

    public function normalisiere(string $modell, array $p): array
    {
        $w = [];
        $c = (array)($p['cleanMissionStatus'] ?? []);
        $akku = isset($p['batPct']) ? (int)$p['batPct'] : null;
        $fehler = isset($c['error']) ? (int)$c['error'] : null;
        if (isset($c['phase'])) {
            $phase = (string)$c['phase'];
            $zyklus = (string)($c['cycle'] ?? 'none');
            if ($phase === 'charge') {
                $voll = $akku !== null && $akku >= 100;
                [$text, $zustand] = $zyklus !== 'none' ? ['Lädt (Reinigung unterbrochen)', RoboHubModell::ZUSTAND_LAEDT]
                    : ($voll ? ['In der Station', RoboHubModell::ZUSTAND_GELADEN] : ['Lädt', RoboHubModell::ZUSTAND_LAEDT]);
                $w['Laedt'] = !$voll || $zyklus !== 'none';
            } elseif ($phase === 'stop') {
                [$text, $zustand] = $zyklus !== 'none' ? ['Pausiert', RoboHubModell::ZUSTAND_PAUSIERT] : ['Bereit', RoboHubModell::ZUSTAND_BEREIT];
                $w['Laedt'] = false;
            } else {
                [$text, $zustand] = self::PHASEN[$phase] ?? ['Phase „' . $phase . '"', RoboHubModell::ZUSTAND_UNBEKANNT];
                $w['Laedt'] = false;
            }
            if ($fehler !== null && $fehler !== 0 && $zustand !== RoboHubModell::ZUSTAND_REINIGT) {
                $zustand = RoboHubModell::ZUSTAND_FEHLER;
            }
            $w['Zustand'] = $zustand;
            $w['ZustandRoh'] = $text . ' (' . $phase . ($zyklus !== 'none' ? ', ' . $zyklus : '') . ')';
        }
        if ($fehler !== null) {
            $w['Fehler'] = $fehler === 0 ? 'kein Fehler' : (self::FEHLER[$fehler] ?? 'Fehlercode') . ' (' . $fehler . ')';
        }
        $w['Akku'] = $akku;
        $w['Dauer'] = isset($c['mssnM']) && is_numeric($c['mssnM']) ? (int)$c['mssnM'] : null;
        $w['Flaeche'] = isset($c['sqft']) && is_numeric($c['sqft']) ? (int)round((float)$c['sqft'] * 0.09290304) : null;
        if (isset($p['bin']) && is_array($p['bin'])) {
            $w['Staubbeutel'] = array_key_exists('present', $p['bin']) && !$p['bin']['present'] ? 'Fehlt' : (!empty($p['bin']['full']) ? 'Voll' : 'In Ordnung');
        }
        if (isset($p['bbrun']['hr']) && is_numeric($p['bbrun']['hr'])) {
            $w['GesamtDauer'] = (int)$p['bbrun']['hr'];
        }
        if (isset($p['bbmssn']['nMssn']) && is_numeric($p['bbmssn']['nMssn'])) {
            $w['GesamtAnzahl'] = (int)$p['bbmssn']['nMssn'];
        }
        return ['werte' => $w, 'optionen' => [], 'schreibbar' => []];
    }

    // =================================================================
    // Befehle
    // =================================================================

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        $r = $this->roboter($buendel, $geraet);
        $c = (array)($geraet['stand']['cleanMissionStatus'] ?? []);
        $mitten = ($c['cycle'] ?? 'none') !== 'none' && in_array($c['phase'] ?? '', ['stop', 'charge'], true);
        $befehle = [
            'start'   => [$mitten ? 'resume' : 'start', 'Start'],
            'pause'   => ['pause', 'Pause'],
            'stopp'   => ['stop', 'Stopp'],
            'station' => ['dock', 'Zur Station'],
            'orten'   => ['find', 'Orten'],
        ];
        if (!isset($befehle[$befehl])) {
            throw new RoboHubFehler('Dieser Befehl wird für iRobot noch nicht unterstützt: ' . $befehl);
        }
        [$kommando, $name] = $befehle[$befehl];
        $this->kommando($r, $kommando);
        return 'Befehl „' . $name . '" gesendet.';
    }

    /** Befehl wie roombapy send_command: {"command", "time", "initiator": "localApp"} auf „cmd". */
    public static function kommandoNachricht(string $kommando, int $zeit): string
    {
        return (string)json_encode(['command' => $kommando, 'time' => $zeit, 'initiator' => 'localApp']);
    }

    protected function kommando(array $r, string $kommando): void
    {
        $this->sitzung($r, function (RoboHubMqttSitzung $s, float $ende) use ($kommando): void {
            $s->senden(RoboHubMqtt::publish('cmd', self::kommandoNachricht($kommando, time())));
        });
    }

    /** MQTT-Sitzung zum Roboter mit gemerktem Zertifikat. */
    private function sitzung(array $r, callable $tun, float $dauer = 8.0): void
    {
        $ende = microtime(true) + $dauer;
        [$sock] = $this->geraetVerbinden((string)$r['ip'], (string)$r['fingerabdruck']);
        $s = RoboHubMqttSitzung::ausStrom($sock);
        try {
            $s->senden(RoboHubMqtt::connect((string)$r['blid'], (string)$r['blid'], (string)$r['passwort'], 30));
            $p = $s->warte(RoboHubMqtt::CONNACK, null, $ende);
            if ($p === null) {
                throw new RoboHubFehler('Der Roboter hat die Anmeldung nicht beantwortet (ist gerade eine andere lokale Verbindung offen?).');
            }
            $code = RoboHubMqtt::connackCode($p['body']);
            if ($code !== 0) {
                $e = new RoboHubFehler('Der Roboter lehnt die Anmeldung ab: ' . RoboHubMqtt::connackText($code) . ' — bitte neu koppeln.');
                $e->anmeldungNoetig = in_array($code, [4, 5], true);
                throw $e;
            }
            $tun($s, $ende);
        } finally {
            $s->schliessen();
        }
    }

    public function zuruecksetzen(array $buendel, array $geraet, string $teil): string
    {
        throw new RoboHubFehler('Verschleißteile meldet ein Roomba lokal nicht — Zurücksetzen nur in der iRobot-App.');
    }

    public function karte(array $buendel, array $geraet): array
    {
        return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
    }

    public function liveKarte(array $buendel, array $geraet): array
    {
        return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
    }

    // =================================================================
    // Echtzeit über die Konto-Verbindung: nicht vorgesehen (eine Verbindung je Roboter)
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

    private function dbg(string $thema, string $text): void
    {
        if (is_callable($this->debug)) {
            ($this->debug)($thema, $text);
        }
    }
}
