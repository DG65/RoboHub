<?php
/**
 * Narwal-Teil des Prüfstands (von test-robohub.php eingebunden).
 * Rahmenformat und Feldnummern wie NarwalIntegration docs/PROTOCOL.md und models.py (MIT).
 */

declare(strict_types=1);

/** Nachgebauter Narwal im Speicher: antwortet in Sendereihenfolge, Rundmeldungen nur im Wachzustand. */
class FakeNarwalRoboter
{
    public static $produkt = 'QoEsI5qYXO';
    public static $did = 'dev-abc123';
    public static $wach = false;
    public static $basis = '';
    public static $karte = '';
    public static $codes = [];        // kurzes Thema => Antwortcode
    public static $gesendet = [];     // kurze Themen in Sendereihenfolge
    public static $nutzlast = [];     // kurzes Thema => letzte Nutzlast
    public static $stumm = false;     // antwortet gar nicht (falsche IP)
    private $aus = [];

    public function senden(string $nachricht): void
    {
        [, $thema, $nutz] = RoboHubNarwal::zerlege($nachricht);
        if (self::$stumm) {
            return;
        }
        $teile = explode('/', $thema, 4);
        $kurz = $teile[3] ?? '';
        if ($thema === '//common/get_device_info') {
            $this->antwort(RoboHubNarwal::pbZahl(1, 2));   // Antwort ohne Geräte-ID (nur Code) — darf nicht als Kopplung gelten
            return;
        }
        if ($kurz === 'common/get_device_info') {
            if ($teile[1] === self::$produkt) {
                $this->antwort(RoboHubNarwal::pbTeil(1, self::$produkt) . RoboHubNarwal::pbTeil(2, self::$did) . RoboHubNarwal::pbTeil(3, "v01.08.03.07\n"));
            }
            return;
        }
        if ($teile[1] !== self::$produkt || $teile[2] !== self::$did) {
            return;   // falscher Schlüssel/falsche ID: der echte Roboter schweigt
        }
        self::$gesendet[] = $kurz;
        self::$nutzlast[$kurz] = $nutz;
        if ($kurz === 'status/app_status_heartbeat') {
            return;   // nie beantwortet
        }
        if ($kurz === 'common/notify_app_event') {
            self::$wach = true;
        }
        if ($kurz === 'status/get_device_base_status') {
            $this->antwort(RoboHubNarwal::pbZahl(1, 1) . RoboHubNarwal::pbTeil(2, self::$basis));
        } elseif ($kurz === 'map/get_map') {
            $this->antwort(RoboHubNarwal::pbZahl(1, 1) . RoboHubNarwal::pbTeil(2, self::$karte));
        } else {
            $this->antwort(RoboHubNarwal::pbZahl(1, self::$codes[$kurz] ?? 1));
        }
        if (self::$wach && in_array($kurz, ['common/active_robot_publish', 'common/notify_app_event'], true)) {
            $this->aus[] = RoboHubNarwal::rahmen('/' . self::$produkt . '/' . self::$did . '/status/robot_base_status', self::$basis);
        }
    }

    private function antwort(string $nutz): void
    {
        $this->aus[] = "\x01\x02\x2a\x00" . $nutz;
    }

    public function empfangen(float $ende): ?string
    {
        return array_shift($this->aus);
    }

    public function schliessen(): void
    {
    }
}

class FakeNarwal extends RoboHubNarwal
{
    public $rundmeldungWarten = 0.05;
    public $weckenWarten = 0.05;

    protected function verbinden(string $ip)
    {
        if ($ip !== '192.168.1.60') {
            throw new RoboHubFehler('Narwal unter ' . $ip . ':9002 nicht erreichbar (Connection refused).');
        }
        return new FakeNarwalRoboter();
    }
}

/** RobotBaseStatus bauen: Akku als float32 (Feld 2, fixed32), Feld 3 = Arbeitszustand. */
function narwalBasis(int $status, float $akku, array $f3 = [], array $fehler = [], array $weitere = []): string
{
    $b = '';
    foreach ($fehler as $code) {
        $b .= RoboHubNarwal::pbTeil(1, RoboHubNarwal::pbZahl(1, $code) . RoboHubNarwal::pbZahl(2, 2));
    }
    $b .= RoboHubNarwal::pbVarint((2 << 3) | 5) . pack('g', $akku);
    $drei = RoboHubNarwal::pbZahl(1, $status);
    foreach ($f3 as $nr => $wert) {
        $drei .= RoboHubNarwal::pbZahl($nr, $wert);
    }
    $b .= RoboHubNarwal::pbTeil(3, $drei);
    foreach ($weitere as $nr => $wert) {
        $b .= RoboHubNarwal::pbZahl($nr, $wert);
    }
    return $b;
}

// ===========================================================================
abschnitt('Narwal: Rahmen und Protobuf');
check('Anfrage-Rahmen wie PROTOCOL.md (01, len+2, 22, len, Thema)', RoboHubNarwal::rahmen('/K/D/common/yell', '') === "\x01\x12\x22\x10/K/D/common/yell");
check('Antwort „01 02 2a 00 08 01" = Feld 5, leeres Thema, Ergebnis 1', RoboHubNarwal::zerlege(hex2bin('01022a000801')) === [5, '', "\x08\x01"]
    && RoboHubNarwal::pbLesen("\x08\x01") === [1 => [1]]);
check('Akku-Bitmuster wie models.py: 1118175232 → 83,0; 1120403456 → 100,0', RoboHubNarwal::alsFloat(1118175232) === 83.0 && RoboHubNarwal::alsFloat(1120403456) === 100.0);
check('Varint 300 = ac 02, negativ = 10 Bytes', RoboHubNarwal::pbVarint(300) === "\xac\x02" && strlen(RoboHubNarwal::pbVarint(-1)) === 10);
$pb = RoboHubNarwal::pbLesen(RoboHubNarwal::pbZahl(1, 7) . RoboHubNarwal::pbTeil(2, 'ab') . RoboHubNarwal::pbZahl(1, 8) . RoboHubNarwal::pbVarint((3 << 3) | 5) . pack('V', 5));
check('Protobuf: wiederholte Felder, Bytes, fixed32', $pb === [1 => [7, 8], 2 => ['ab'], 3 => [5]], json_encode($pb));
check('abgeschnittene Nachricht bricht sauber ab', RoboHubNarwal::pbLesen("\x08\x01\x12\x09ab") === [1 => [1]]);
$auftrag = RoboHubNarwal::pbLesen(RoboHubNarwal::pbEins(RoboHubNarwal::pbLesen(RoboHubNarwal::reinigungsAuftrag(42, [3, 5])), 1, ''));
$item = RoboHubNarwal::pbLesen($auftrag[2][1]);
check('Reinigungsauftrag: Karte 42, zwei Räume in Reihenfolge, Saugen+Wischen (Art 4, Durchgänge Feld 7)',
    $auftrag[1] === [42] && count($auftrag[2]) === 2 && $auftrag[3] === [''] && $auftrag[5] === [4]
    && RoboHubNarwal::pbLesen($item[1][0]) === [1 => [1], 2 => [5]] && $item[3] === [2]
    && RoboHubNarwal::pbLesen($item[2][0]) === [1 => [4], 2 => [2], 3 => [1], 4 => [2], 7 => [1]]);

// ===========================================================================
abschnitt('Narwal: Koppeln, Zustand, Befehle');
$fabrikNw = $GLOBALS['robohubTreiberFabrik'];
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) use ($fabrikNw) {
    return $marke === 'narwal' ? new FakeNarwal() : $fabrikNw($marke);
};
$nwk = new RoboHubKonto(9900);
$nwk->props['Hersteller'] = 'narwal';
$nwk->Create();
$nwk->ApplyChanges();
$nwSichtbar = [];
$nwSuche = function ($k) use (&$nwSuche, &$nwSichtbar) {
    if (!is_array($k)) {
        return;
    }
    if (isset($k['caption']) && is_string($k['caption']) && ($k['visible'] ?? true) !== false) {
        $nwSichtbar[] = $k['caption'] . (isset($k['name']) ? ' [' . $k['name'] . ']' : '');
    }
    foreach ($k as $w) {
        $nwSuche($w);
    }
};
$nwSuche(json_decode($nwk->GetConfigurationForm(), true));
$fText = implode("\n", $nwSichtbar);
check('Formular: Narwal-Anleitung sichtbar, iRobot-Anleitung und Passwortfeld nicht, IP-Feld', strpos($fText, 'Narwal läuft lokal, ohne Cloud und ohne Passwort') !== false
    && strpos($fText, 'am Roboter die HOME-Taste') === false && strpos($fText, '[Passwort]') === false && strpos($fText, 'IP-Adresse des Roboters [Konto]') !== false, $fText);
$nwk->props['Konto'] = '192.168.1.99';
$t = $nwk->Anmelden();
check('falsche IP: verständlicher Fehler', strpos($t, 'nicht erreichbar') !== false, $t);
$nwk->props['Konto'] = '192.168.1.60';
$t = $nwk->Anmelden();
$nwb = json_decode($nwk->ReadAttributeString('Buendel'), true);
$nwg = json_decode($nwk->ReadAttributeString('Geraete'), true);
check('gekoppelt: Schlüssel + Geräte-ID erkannt (nur der eigene Schlüssel antwortet), Modellname, kein Passwort',
    strpos($t, 'Narwal braucht weder Konto noch Passwort') !== false && ($nwb['roboter']['dev-abc123'] ?? null) === ['did' => 'dev-abc123', 'ip' => '192.168.1.60', 'produkt' => 'QoEsI5qYXO', 'firmware' => 'v01.08.03.07', 'name' => 'Narwal Flow']
    && count($nwg) === 1 && $nwg[0]['modell'] === 'narwal.vacuum.qoesi5qyxo' && $nwg[0]['klasse'] === 'sauger', $t);

$nwr = new RoboHubGeraet(9950);
$nwr->props = ['GeraetID' => 'dev-abc123', 'Modell' => 'narwal.vacuum.qoesi5qyxo', 'Klasse' => 'sauger', 'Intervall' => 60];
$nwr->Create();
$nwr->parent = $nwk;
$nwr->ApplyChanges();

FakeNarwalRoboter::$wach = false;
FakeNarwalRoboter::$basis = narwalBasis(4, 83.0);   // veralteter Arbeitszustand aus der letzten Reinigung
FakeNarwalRoboter::$gesendet = [];
$nwr->Aktualisieren();
check('schlafender Roboter: nicht geweckt (kein notify_app_event), nur abonniert + Grundstatus (danach einmalig Räume)',
    array_slice(FakeNarwalRoboter::$gesendet, 0, 2) === ['common/active_robot_publish', 'status/get_device_base_status'] && !in_array('common/notify_app_event', FakeNarwalRoboter::$gesendet, true), json_encode(FakeNarwalRoboter::$gesendet));
FakeNarwalRoboter::$gesendet = [];
$nwr->Aktualisieren();
check('… zweiter Abruf: wieder nur Abo + Grundstatus', FakeNarwalRoboter::$gesendet === ['common/active_robot_publish', 'status/get_device_base_status'], json_encode(FakeNarwalRoboter::$gesendet));
check('… Akku 83 übernommen, veralteter Zustand „reinigt" NICHT, sondern „ruht"', variable(9950, 'Akku')['value'] === 83 && variable(9950, 'Zustand')['value'] === RoboHubModell::ZUSTAND_SCHLAF);
$abo = RoboHubNarwal::pbLesen(FakeNarwalRoboter::$nutzlast['common/active_robot_publish']);
check('Abo: je Thema TopicDuration{Thema, 600 s}', count($abo[1]) === 2 && RoboHubNarwal::pbLesen($abo[1][0]) === [1 => ['status/robot_base_status'], 2 => [600]]);

$zustand = function (string $basis) use ($nwr): int {
    FakeNarwalRoboter::$wach = true;
    FakeNarwalRoboter::$basis = $basis;
    $nwr->Aktualisieren();
    return variable(9950, 'Zustand')['value'];
};
check('wach in der Station (10), 100 % = geladen', $zustand(narwalBasis(10, 100.0)) === RoboHubModell::ZUSTAND_GELADEN && variable(9950, 'Akku')['value'] === 100);
check('wach in der Station (14), 64 % = lädt', $zustand(narwalBasis(14, 64.0)) === RoboHubModell::ZUSTAND_LAEDT);
check('Bereitschaft (1) mit Feld 11 = 2 → in der Station (lädt)', $zustand(narwalBasis(1, 50.0, [], [], [11 => 2])) === RoboHubModell::ZUSTAND_LAEDT);
check('Bereitschaft (1) ohne Stationszeichen = bereit', $zustand(narwalBasis(1, 50.0, [], [], [11 => 1, 47 => 2])) === RoboHubModell::ZUSTAND_BEREIT);
check('reinigt (4)', $zustand(narwalBasis(4, 70.0)) === RoboHubModell::ZUSTAND_REINIGT);
check('neue Firmware: 2 mit 3.4 = 3 = reinigt, nicht Station (#124)', $zustand(narwalBasis(2, 70.0, [4 => 3])) === RoboHubModell::ZUSTAND_REINIGT);
check('pausiert (3.2 = 1)', $zustand(narwalBasis(4, 70.0, [2 => 1])) === RoboHubModell::ZUSTAND_PAUSIERT);
check('Rückfahrt nur mit 3.7 = 1 UND 3.10 = 2', $zustand(narwalBasis(4, 70.0, [7 => 1, 10 => 2])) === RoboHubModell::ZUSTAND_ZURUECK
    && $zustand(narwalBasis(4, 70.0, [7 => 1])) === RoboHubModell::ZUSTAND_REINIGT);
check('Fehlercode → Fehler', $zustand(narwalBasis(4, 70.0, [], [7])) === RoboHubModell::ZUSTAND_FEHLER && variable(9950, 'Fehler')['value'] === 'Fehlercode 7');

// Karte: Räume mit eigenem Namen bzw. Typ + laufender Nummer.
$raum = function (int $id, int $typ, string $name = '', int $nr = 0): string {
    return RoboHubNarwal::pbTeil(12, RoboHubNarwal::pbZahl(1, $id) . RoboHubNarwal::pbZahl(2, $typ) . ($name !== '' ? RoboHubNarwal::pbTeil(3, $name) : '') . RoboHubNarwal::pbZahl(4, 1) . ($nr > 0 ? RoboHubNarwal::pbZahl(8, $nr) : ''));
};
FakeNarwalRoboter::$karte = RoboHubNarwal::pbZahl(1, 42) . RoboHubNarwal::pbZahl(3, 60) . $raum(3, 4, 'Küche') . $raum(5, 3, '', 2) . $raum(0, 15);
$nwr->RaeumeLaden();
$nwRaeume = json_decode($nwr->ReadAttributeString('Raeume'), true);
check('Räume: eigener Name, sonst Typ deutsch + Nummer, Raum 0 ausgelassen', $nwRaeume == [3 => 'Küche', 5 => 'Wohnzimmer 2'], json_encode($nwRaeume, JSON_UNESCAPED_UNICODE));

FakeNarwalRoboter::$wach = false;
FakeNarwalRoboter::$basis = narwalBasis(10, 90.0);
FakeNarwalRoboter::$gesendet = [];
FakeNarwalRoboter::$codes = ['task/pause' => 4];
$t = $nwr->Anhalten();
check('Befehl: erst Weckfolge (notify, 2× Abo, Heartbeat, Grundstatus), dann Befehl', FakeNarwalRoboter::$gesendet === ['common/notify_app_event', 'common/active_robot_publish', 'common/active_robot_publish',
    'status/app_status_heartbeat', 'status/get_device_base_status', 'task/pause'], json_encode(FakeNarwalRoboter::$gesendet));
check('… Antwort richtig zugeordnet (Bestätigungen der Weckfolge übersprungen): Ablehnung 4 gemeldet', strpos($t, 'nicht bereit') !== false, $t);
FakeNarwalRoboter::$codes = [];
FakeNarwalRoboter::$gesendet = [];
$nwr->Starten();
$gesendet = array_slice(FakeNarwalRoboter::$gesendet, 5);
$auftrag = RoboHubNarwal::pbLesen(RoboHubNarwal::pbEins(RoboHubNarwal::pbLesen(FakeNarwalRoboter::$nutzlast['clean/start_clean'] ?? ''), 1, ''));
check('Start aus der Station: Karte holen, alle Räume (3, 5) auf Karte 42', $gesendet === ['map/get_map', 'clean/start_clean'] && ($auftrag[1] ?? null) === [42] && count($auftrag[2] ?? []) === 2, json_encode($gesendet));
FakeNarwalRoboter::$basis = narwalBasis(4, 60.0, [2 => 1]);
FakeNarwalRoboter::$gesendet = [];
$nwr->Starten();
check('Start in der Pause = Fortsetzen (task/resume), keine Karte', array_slice(FakeNarwalRoboter::$gesendet, 5) === ['task/resume'], json_encode(FakeNarwalRoboter::$gesendet));
FakeNarwalRoboter::$basis = narwalBasis(10, 90.0);
FakeNarwalRoboter::$gesendet = [];
$nwr->RaeumeReinigen('5');
$auftrag = RoboHubNarwal::pbLesen(RoboHubNarwal::pbEins(RoboHubNarwal::pbLesen(FakeNarwalRoboter::$nutzlast['clean/start_clean'] ?? ''), 1, ''));
check('Raum 5 reinigen: genau ein Raum im Auftrag', count($auftrag[2] ?? []) === 1 && RoboHubNarwal::pbLesen(RoboHubNarwal::pbLesen($auftrag[2][0])[1][0]) === [1 => [1], 2 => [5]]);
FakeNarwalRoboter::$gesendet = [];
$t = $nwr->RaeumeReinigen('9');
check('unbekannter Raum: Fehler, kein Auftrag', !in_array('clean/start_clean', FakeNarwalRoboter::$gesendet, true) && strpos($t, 'Unbekannte Raum-ID(s): 9') !== false, $t);
FakeNarwalRoboter::$gesendet = [];
$nwr->Stoppen();
$nwr->ZurStation();
$nwr->Orten();
$befehle = array_values(array_filter(FakeNarwalRoboter::$gesendet, function ($t) { return in_array($t, ['task/force_end', 'supply/recall', 'common/yell'], true); }));
check('Stopp = task/force_end, Zur Station = supply/recall, Orten = common/yell', $befehle === ['task/force_end', 'supply/recall', 'common/yell'], json_encode($befehle));
FakeNarwalRoboter::$stumm = true;
$t = $nwr->Orten();
check('Roboter antwortet nicht: verständlicher Fehler', strpos($t, 'antwortet nicht auf „common/yell"') !== false, $t);
FakeNarwalRoboter::$stumm = false;
check('Narwal: Echtzeit-Socket bleibt geschlossen', strpos(json_encode(json_decode($nwk->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE), 'Für Narwal gibt es noch keine Echtzeit') !== false);
$GLOBALS['robohubTreiberFabrik'] = $fabrikNw;

// Netzwerkschicht gegen einen echten WebSocket-Gegenspieler (Handschlag, Ping/Pong, zerteilte Nachricht, Schließen).
$nb = proc_open([PHP_BINARY, __DIR__ . '/narwal-nachbau.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $nbPipes);
$nbPort = (int)trim((string)fgets($nbPipes[1]));
try {
    $nv = RoboHubNarwalVerbindung::oeffnen('127.0.0.1', $nbPort, microtime(true) + 5);
    $nv->senden('hallo');
    $erste = $nv->empfangen(microtime(true) + 5);
    $zweite = $nv->empfangen(microtime(true) + 5);
    $dritte = $nv->empfangen(microtime(true) + 2);
    $nv->schliessen();
    check('echte Verbindung: zerteilte Nachricht zusammengesetzt (304 Bytes)', $erste === "\x01\x02\x2a\x00" . str_repeat('K', 300), (string)strlen((string)$erste));
    check('… Anfrage maskiert angekommen, Ping mit Pong beantwortet', $zweite === "\x01\x02\x2a\x00" . 'hallo|10:ping!', (string)$zweite);
    check('… Schließen des Roboters beendet das Lesen', $dritte === null);
} catch (Throwable $e) {
    check('echte Verbindung zum Nachbau', false, $e->getMessage());
} finally {
    proc_terminate($nb);
    foreach ($nbPipes as $p) {
        fclose($p);
    }
    proc_close($nb);
}
try {
    RoboHubNarwalVerbindung::oeffnen('127.0.0.1', $nbPort, microtime(true) + 2);
    check('geschlossener Port: Fehler', false);
} catch (RoboHubFehler $e) {
    check('geschlossener Port: verständlicher Fehler', strpos($e->getMessage(), 'nicht erreichbar') !== false, $e->getMessage());
}
