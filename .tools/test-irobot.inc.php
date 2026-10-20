<?php
/**
 * iRobot-Teil des Prüfstands (von test-robohub.php eingebunden). Neben reinen
 * Funktionsprüfungen läuft ein nachgestellter Roboter (roomba-nachbau.php) mit
 * echtem UDP, echtem TLS (selbst ausgestelltes Zertifikat) und MQTT.
 */

declare(strict_types=1);

/** Startet einen nachgestellten Roboter; Rückgabe [Prozess, Pipes, TLS-Port, UDP-Port, Fingerabdruck, Protokolldatei]. */
function roombaNachbau(string $art = ''): array
{
    $log = tempnam(sys_get_temp_dir(), 'roombalog');
    $proc = proc_open([PHP_BINARY, __DIR__ . '/roomba-nachbau.php', $log, $art], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $zeile = trim((string)fgets($pipes[1]));
    [$port, $udp, $fp] = array_pad(explode(' ', $zeile), 3, '');
    return [$proc, $pipes, (int)$port, (int)$udp, $fp, $log];
}
function roombaEnde(array $n): void
{
    proc_terminate($n[0]);
    foreach ($n[1] as $p) {
        fclose($p);
    }
    proc_close($n[0]);
    @unlink($n[5]);
}

// ===========================================================================
abschnitt('iRobot: Bausteine');
check('Passwort aus der Antwort (ab Byte 7, Nullen weg)', RoboHubIrobot::passwortAus("\xf0\x24\xef\xcc\x3b\x29\x00:1:16:Abc\0\0") === ':1:16:Abc' && RoboHubIrobot::passwortAus("\xf0\x05") === '');
check('Fingerabdruck: beim Koppeln jeder, danach nur der gemerkte, leer nie', RoboHubIrobot::fingerabdruckPasst(null, 'ab12') && RoboHubIrobot::fingerabdruckPasst('AB12', 'ab12')
    && !RoboHubIrobot::fingerabdruckPasst('ab12', 'cd34') && !RoboHubIrobot::fingerabdruckPasst(null, ''));
check('Befehl wie roombapy: command, time, initiator localApp', RoboHubIrobot::kommandoNachricht('dock', 1700000000) === '{"command":"dock","time":1700000000,"initiator":"localApp"}');
$st = ['cleanMissionStatus' => ['phase' => 'run', 'cycle' => 'clean'], 'batPct' => 50];
RoboHubIrobot::zusammenfuehren($st, ['cleanMissionStatus' => ['phase' => 'stop'], 'bin' => ['full' => true], 'langs' => [1, 2]]);
check('Zustand rekursiv zusammengeführt (Listen ersetzt)', $st === ['cleanMissionStatus' => ['phase' => 'stop', 'cycle' => 'clean'], 'batPct' => 50, 'bin' => ['full' => true], 'langs' => [1, 2]]);
$ir = new RoboHubIrobot();
$z = function (array $p) use ($ir) { return $ir->normalisiere('', $p)['werte']; };
check('charge + kein Zyklus + 100 % = In der Station', $z(['batPct' => 100, 'cleanMissionStatus' => ['phase' => 'charge', 'cycle' => 'none', 'error' => 0]])['Zustand'] === RoboHubModell::ZUSTAND_GELADEN);
check('charge mitten in der Reinigung = Lädt', $z(['batPct' => 40, 'cleanMissionStatus' => ['phase' => 'charge', 'cycle' => 'clean', 'error' => 0]])['Zustand'] === RoboHubModell::ZUSTAND_LAEDT);
check('stop mit Zyklus = Pausiert, ohne = Bereit', $z(['cleanMissionStatus' => ['phase' => 'stop', 'cycle' => 'clean']])['Zustand'] === RoboHubModell::ZUSTAND_PAUSIERT
    && $z(['cleanMissionStatus' => ['phase' => 'stop', 'cycle' => 'none']])['Zustand'] === RoboHubModell::ZUSTAND_BEREIT);
$s = $z(['cleanMissionStatus' => ['phase' => 'stuck', 'cycle' => 'clean', 'error' => 17], 'bin' => ['present' => false]]);
check('stuck + Fehler 17 = Fehler „Weg versperrt", Behälter fehlt', $s['Zustand'] === RoboHubModell::ZUSTAND_FEHLER && $s['Fehler'] === 'Weg versperrt (17)' && $s['Staubbeutel'] === 'Fehlt');

// ===========================================================================
abschnitt('iRobot: nachgestellter Roboter (echtes TLS, UDP, MQTT)');
@unlink((new RoboHubIrobot())->suchMerkDatei('127.0.0.1'));
$n1 = roombaNachbau();
check('nachgestellter Roboter läuft', $n1[2] > 0 && $n1[3] > 0 && strlen($n1[4]) === 64, json_encode([$n1[2], $n1[3], $n1[4]]));
$fabrikIr = $GLOBALS['robohubTreiberFabrik'];
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) use ($fabrikIr, &$n1) {
    if ($marke !== 'irobot') {
        return $fabrikIr($marke);
    }
    $t = new RoboHubIrobot();
    $t->port = $n1[2];
    $t->suchPort = $n1[3];
    return $t;
};
$ik = new RoboHubKonto(7000);
$ik->props['Hersteller'] = 'irobot';
$ik->Create();
$ik->ApplyChanges();
$f = json_encode(json_decode($ik->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
check('Formular: IP-Feld, Anleitung HOME-Taste, kein Passwortfeld sichtbar', strpos($f, 'IP-Adresse des Roboters') !== false && strpos($f, 'HOME-Taste') !== false && strpos($f, '"name":"Passwort","visible":false') !== false);
$ik->props['Konto'] = 'kein.ip';
check('ungültige IP abgelehnt', strpos($ik->Anmelden(), 'IP-Adresse') !== false);
$ik->props['Konto'] = '127.0.0.1';
$ik->WriteAttributeString('Buendel', json_encode(['marke' => 'irobot', 'roboter' => ['ALT1' => ['blid' => 'ALT1', 'ip' => '10.0.0.9', 'passwort' => 'x', 'fingerabdruck' => 'ab', 'name' => 'Erster', 'sku' => 'r980']]]));
$t = $ik->Anmelden();
$ib = json_decode($ik->ReadAttributeString('Buendel'), true);
$rob = $ib['roboter']['3176C41234567890'] ?? [];
check('gekoppelt: BLID aus dem Hostnamen, Passwort geholt', strpos($t, '✅ Gekoppelt') === 0 && ($rob['passwort'] ?? '') === ':1:1700000000:GeheimesPasswort' && ($rob['name'] ?? '') === 'Krümel', $t);
check('Fingerabdruck des Gerätezertifikats gemerkt', ($rob['fingerabdruck'] ?? '') === $n1[4]);
check('Konto angemeldet, weiterer Roboter ergänzt (der erste bleibt)', $ik->status === 102 && count(json_decode($ik->ReadAttributeString('Geraete'), true)) === 2 && isset($ib['roboter']['ALT1']));
check('kein Client-Socket-Timer (lokal, eine Verbindung je Roboter)', ($ik->timers['Verbindung'] ?? 0) === 0);

$irr = new RoboHubGeraet(7100);
$irr->props = ['GeraetID' => '3176C41234567890', 'Modell' => 'irobot.vacuum.i755840', 'Klasse' => 'sauger', 'Intervall' => 120];
$irr->Create();
$irr->parent = $ik;
$irr->ApplyChanges();
$t = $irr->Aktualisieren();
check('Zustand über lokales MQTT: reinigt, Akku 87', variable(7100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_REINIGT && variable(7100, 'Akku')['value'] === 87, $t);
check('Fläche (215 sqft ≈ 20 m²), Dauer, Behälter, Gesamtwerte', variable(7100, 'Flaeche')['value'] === 20 && variable(7100, 'Dauer')['value'] === 12
    && variable(7100, 'Staubbeutel')['value'] === 'In Ordnung' && variable(7100, 'GesamtDauer')['value'] === 123 && variable(7100, 'GesamtAnzahl')['value'] === 321);
$irr->ZurStation();
usleep(300000);
$log = (string)file_get_contents($n1[5]);
check('Befehl „dock" auf Topic cmd angekommen', preg_match('/befehl cmd \{"command":"dock","time":\d+,"initiator":"localApp"\}/', $log) === 1, $log);
check('Anmeldung am Roboter mit BLID und Gerätepasswort', substr_count($log, 'connect') >= 2 && strpos($log, 'connect-abgelehnt') === false);

$snap = json_decode($ik->ReadAttributeString('Schnappschuss'), true);
$snap['3176C41234567890']['p']['cleanMissionStatus'] = ['phase' => 'stop', 'cycle' => 'clean'];
$ik->WriteAttributeString('Schnappschuss', json_encode($snap));
$irr->Starten();
usleep(300000);
check('Start nach Pause mitten in der Reinigung = „resume"', strpos((string)file_get_contents($n1[5]), '"command":"resume"') !== false);

// Anderes Gerät (anderes Zertifikat) unter derselben Adresse → abgelehnt, nichts gesendet.
$n2 = roombaNachbau();
$alterPort = $n1[2];
$n1[2] = $n2[2];
$t = $irr->Aktualisieren();
$log2 = (string)file_get_contents($n2[5]);
check('fremdes Zertifikat: Verbindung abgelehnt, keine Anmeldedaten gesendet', strpos($t, 'Zertifikat') !== false && strpos($log2, 'connect') === false, $t . ' | ' . $log2);
$n1[2] = $alterPort;
roombaEnde($n2);

$t = $irr->VerbrauchsteilZuruecksetzen('Filter');
check('Zurücksetzen: ehrlicher Hinweis', strpos($t, 'iRobot-App') !== false || strpos($t, '⛔') === 0, $t);
roombaEnde($n1);
$GLOBALS['robohubTreiberFabrik'] = $fabrikIr;

$quelle = new ReflectionMethod('RoboHubMqttSitzung', 'oeffnen');
$text = implode('', array_slice(file($quelle->getFileName()), $quelle->getStartLine() - 1, $quelle->getEndLine() - $quelle->getStartLine() + 1));
check('Cloud-Sitzungen bleiben streng (kein verify_peer false in oeffnen)', strpos($text, "'verify_peer' => true") !== false && strpos($text, '=> false') === false);

// ===========================================================================
abschnitt('iRobot: eigener TLS-1.2-Weg (Roomba 980, OpenSSL 3 ohne RFC 5746)');
check('PRF nach RFC 5246 (Prüfvektor der IETF-Liste, SHA-256)', bin2hex(substr(RoboHubTls::prf(hex2bin('9bbe436ba940f017b17652849a71db35'), 'test label', hex2bin('a0ba9f936cda311827a6f796ffd5198c'), 100), 0, 16)) === 'e3f229ba727be17b8d122620557cd453');

class AltRoomba extends RoboHubIrobot
{
    public $opensslAus = true;
    public static $wege = [];
    protected function opensslVerbinden(string $ip): array
    {
        self::$wege[] = 'openssl';
        if ($this->opensslAus) {
            throw new RoboHubFehler('error:0A000152:SSL routines::unsafe legacy renegotiation disabled');
        }
        return parent::opensslVerbinden($ip);
    }
    protected function eigenVerbinden(string $ip): array
    {
        self::$wege[] = 'eigen';
        return parent::eigenVerbinden($ip);
    }
}
$n3 = roombaNachbau('alt');
$tls = RoboHubTls::verbinden('127.0.0.1', $n3[2], 5.0);
check('Handschlag mit echtem OpenSSL-Server (nur RSA + AES-CBC, TLS 1.2)', in_array($tls->suite, [0x003C, 0x003D, 0x002F, 0x0035], true) && hash('sha256', $tls->zertifikat) === $n3[4], sprintf('0x%04X', $tls->suite));
$tls->schreiben("\xf0\x05\xef\xcc\x3b\x29\x00");
$antwort = '';
for ($i = 0; $i < 5 && strlen($antwort) < 7; $i++) {
    $antwort .= (string)$tls->lesen(2.0);
}
check('Passwort-Magie über den eigenen Weg beantwortet', RoboHubIrobot::passwortAus($antwort) === ':1:1700000000:GeheimesPasswort', bin2hex($antwort));
check('Server schließt sauber → lesen meldet Ende', $tls->lesen(2.0) === null);
$tls->schliessen();

$GLOBALS['robohubTreiberFabrik'] = function (string $marke) use ($fabrikIr, &$n3, &$altTreiber) {
    if ($marke !== 'irobot') {
        return $fabrikIr($marke);
    }
    $altTreiber = new AltRoomba();
    $altTreiber->port = $n3[2];
    $altTreiber->suchPort = $n3[3];
    return $altTreiber;
};
@unlink((new RoboHubIrobot())->suchMerkDatei('127.0.0.1'));
$ak = new RoboHubKonto(7200);
$ak->props['Hersteller'] = 'irobot';
$ak->Create();
$ak->ApplyChanges();
$ak->props['Konto'] = '127.0.0.1';
$t = $ak->Anmelden();
$ab = json_decode($ak->ReadAttributeString('Buendel'), true)['roboter']['3176C41234567890'] ?? [];
check('Koppeln: OpenSSL scheitert → eigener Weg, Passwort und Fingerabdruck gemerkt', strpos($t, '✅ Gekoppelt') === 0 && AltRoomba::$wege === ['openssl', 'eigen']
    && ($ab['passwort'] ?? '') === ':1:1700000000:GeheimesPasswort' && ($ab['fingerabdruck'] ?? '') === $n3[4], $t . ' ' . json_encode(AltRoomba::$wege));
$ar = new RoboHubGeraet(7300);
$ar->props = ['GeraetID' => '3176C41234567890', 'Modell' => 'irobot.vacuum.r980', 'Klasse' => 'sauger', 'Intervall' => 120];
$ar->Create();
$ar->parent = $ak;
$ar->ApplyChanges();
$t = $ar->Aktualisieren();
check('MQTT über den eigenen Weg: Zustand und Akku', variable(7300, 'Zustand')['value'] === RoboHubModell::ZUSTAND_REINIGT && variable(7300, 'Akku')['value'] === 87, $t);
$ar->ZurStation();
usleep(300000);
check('Befehl über den eigenen Weg angekommen', preg_match('/befehl cmd \{"command":"dock"/', (string)file_get_contents($n3[5])) === 1);

// Koppelfenster: Roboter schweigt auf UDP → Antwort vom Klick davor (gemerkt).
$n3[3] = 1;
$t = $ak->Anmelden();
check('UDP still nach HOME-Druck: gemerkte Antwort genutzt, Koppeln klappt', strpos($t, '✅ Gekoppelt') === 0, $t);
@unlink((new RoboHubIrobot())->suchMerkDatei('127.0.0.1'));
$t = $ak->Anmelden();
check('ohne gemerkte Antwort: Hinweis, erst ohne HOME zu suchen', strpos($t, 'OHNE HOME-Taste') !== false, $t);

// Fremdes Zertifikat auch auf dem eigenen Weg abgelehnt.
$n4 = roombaNachbau('alt');
$n3Port = $n3[2];
$n3[2] = $n4[2];
$t = $ar->Aktualisieren();
check('eigener Weg: fremdes Zertifikat abgelehnt, keine Anmeldedaten gesendet', strpos($t, 'Zertifikat') !== false && strpos((string)file_get_contents($n4[5]), 'connect') === false, $t);
roombaEnde($n4);

// Angreifer im Netz: verfälschte Bytes müssen auffallen.
$stoerer = function (int $ziel, string $art): array {
    $proc = proc_open([PHP_BINARY, __DIR__ . '/tls-stoerer.php', (string)$ziel, $art], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return [$proc, $pipes, (int)trim((string)fgets($pipes[1]))];
};
$stoererEnde = function (array $s): void {
    proc_terminate($s[0]);
    foreach ($s[1] as $p) {
        fclose($p);
    }
    proc_close($s[0]);
};
$s1 = $stoerer($n3Port, 'sitzung');
try {
    RoboHubTls::verbinden('127.0.0.1', $s1[2], 5.0);
    $fehler = '';
} catch (RoboHubFehler $e) {
    $fehler = $e->getMessage();
}
// Der Roboter merkt den abweichenden Verlauf zuerst (Alarm 51 auf unser Finished);
// die eigene Finished-Prüfung ist zusätzliche Absicherung und so nicht erreichbar.
check('verfälschter Handschlag (Sitzungskennung) → Verbindung abgelehnt', strpos($fehler, 'TLS-Fehler 51') !== false || strpos($fehler, 'Abschluss') !== false, $fehler);
$stoererEnde($s1);
$s2 = $stoerer($n3Port, 'daten');
$fehler = '';
try {
    $tls = RoboHubTls::verbinden('127.0.0.1', $s2[2], 5.0);
    $tls->schreiben("\xf0\x05\xef\xcc\x3b\x29\x00");
    $tls->lesen(2.0);
} catch (RoboHubFehler $e) {
    $fehler = $e->getMessage();
}
check('verfälschte Nutzdaten → Prüfsumme schlägt an', strpos($fehler, 'Prüfsumme') !== false, $fehler);
$stoererEnde($s2);
roombaEnde($n3);
$GLOBALS['robohubTreiberFabrik'] = $fabrikIr;
@unlink((new RoboHubIrobot())->suchMerkDatei('127.0.0.1'));
