<?php
/**
 * iRobot-Teil des Prüfstands (von test-robohub.php eingebunden). Neben reinen
 * Funktionsprüfungen läuft ein nachgestellter Roboter (roomba-nachbau.php) mit
 * echtem UDP, echtem TLS (selbst ausgestelltes Zertifikat) und MQTT.
 */

declare(strict_types=1);

/** Startet einen nachgestellten Roboter; Rückgabe [Prozess, Pipes, TLS-Port, UDP-Port, Fingerabdruck, Protokolldatei]. */
function roombaNachbau(): array
{
    $log = tempnam(sys_get_temp_dir(), 'roombalog');
    $proc = proc_open([PHP_BINARY, __DIR__ . '/roomba-nachbau.php', $log], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
