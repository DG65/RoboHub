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
// Merkdateien eines abgebrochenen oder parallelen Laufs (TLS-Weg, Suche, Weg) verfälschen sonst das Koppeln.
foreach (glob(sys_get_temp_dir() . '/robohub-irobot-*') ?: [] as $alt) {
    @unlink($alt);
}
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
$dz = $z(['cleanMissionStatus' => ['phase' => 'run', 'cycle' => 'clean', 'mssnM' => 0, 'sqft' => 0, 'mssnStrtTm' => 1700000000], '_abgerufen' => 1700000000 + 8 * 60 + 30]);
check('Dauer aus dem Startzeitpunkt, wenn mssnM beim Reinigen 0 bleibt (980)', $dz['Dauer'] === 8 && $dz['Flaeche'] === 0);
$dz = $z(['cleanMissionStatus' => ['phase' => 'charge', 'cycle' => 'none', 'mssnM' => 0, 'mssnStrtTm' => 1700000000], '_abgerufen' => 1700009000]);
check('… aber nicht ohne laufende Reinigung, und nie über einen unplausiblen Start', $dz['Dauer'] === 0
    && $z(['cleanMissionStatus' => ['phase' => 'run', 'cycle' => 'clean', 'mssnM' => 3, 'mssnStrtTm' => 1], '_abgerufen' => 1700000000])['Dauer'] === 3);
check('Reinigungen gesamt auch aus cleanMissionStatus.nMssn (980 schickt bbmssn selten)', ($z(['cleanMissionStatus' => ['phase' => 'charge', 'cycle' => 'none', 'nMssn' => 2619]])['GesamtAnzahl'] ?? null) === 2619
    && $z(['cleanMissionStatus' => ['nMssn' => 1], 'bbmssn' => ['nMssn' => 7]])['GesamtAnzahl'] === 7);
check('stuck + Fehler 17 = Fehler „Weg versperrt", Behälter fehlt', $s['Zustand'] === RoboHubModell::ZUSTAND_FEHLER && $s['Fehler'] === 'Weg versperrt (17)' && $s['Staubbeutel'] === 'Fehlt');

// ===========================================================================
abschnitt('iRobot: nachgestellter Roboter (echtes TLS, UDP, MQTT)');
@unlink((new RoboHubIrobot())->suchMerkDatei('127.0.0.1'));
@unlink((new RoboHubIrobot())->wegDatei('3176C41234567890'));   // kein Weg aus einem früheren Lauf
$n1 = roombaNachbau();
check('nachgestellter Roboter läuft', $n1[2] > 0 && $n1[3] > 0 && strlen($n1[4]) === 64, json_encode([$n1[2], $n1[3], $n1[4]]));
$fabrikIr = $GLOBALS['robohubTreiberFabrik'];
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) use ($fabrikIr, &$n1) {
    if ($marke !== 'irobot') {
        return $fabrikIr($marke);
    }
    $t = new RoboHubIrobot();
    $t->posenFenster = 0.5;
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
check('auch lokal: Verbindungsinstanz wird nachgeholt (Symcon 9.1 braucht sie für die Konfigurator-Kette)', ($ik->timers['Verbindung'] ?? 0) === 15000);
$GLOBALS['SKRIPTE'] = [];
$ik->VerbindungPruefen();
$isock = (int)(IPS_GetInstance(7000)['ConnectionID'] ?? 0);
check('ohne Echtzeit: Virtual IO angelegt (immer aktiv, kein „übergeordnete Instanz inaktiv"), benannt, nichts eingestellt', $isock > 0
    && $ik->parent->modul === '{6179ED6A-FC31-413C-BB8E-1204150CF376}' && strpos(IPS_GetName($isock), 'ohne Echtzeit') !== false
    && $GLOBALS['SKRIPTE'] === [] && $ik->GetConfigurationForParent() === '{}', IPS_GetName($isock) . ' ' . implode("\n", $GLOBALS['SKRIPTE']));
IPS_SetName($isock, 'RoboHub Echtzeit (' . IPS_GetName(7000) . ')');   // angelegt, als noch Dreame voreingestellt war
$ik->ApplyChanges();
check('alter Name „Echtzeit" wird bei iRobot zu „ohne Echtzeit"', strpos(IPS_GetName($isock), 'ohne Echtzeit') !== false, IPS_GetName($isock));
// Bestandskonto (bis 0.24.0): geschlossener Client Socket von RoboHub → wird gegen Virtual IO getauscht.
$ik->parent->modul = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
IPS_SetName($isock, 'RoboHub Verbindung (' . IPS_GetName(7000) . ', ungenutzt)');
$ik->ApplyChanges();
$GLOBALS['GELOESCHT'] = [];
$ik->VerbindungPruefen();
check('Bestandskonto: alter RoboHub-Socket entfernt, Virtual IO verbunden', in_array($isock, $GLOBALS['GELOESCHT'], true)
    && $ik->parent->modul === '{6179ED6A-FC31-413C-BB8E-1204150CF376}');
$fremd = $ik->parent;
$fremd->modul = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
IPS_SetName($fremd->InstanceID, 'Mein eigener Socket');
$GLOBALS['GELOESCHT'] = [];
$ik->VerbindungPruefen();
check('fremde Verbindungsinstanz bleibt unangetastet', $GLOBALS['GELOESCHT'] === [] && $ik->parent === $fremd);
$fremd->modul = '{6179ED6A-FC31-413C-BB8E-1204150CF376}';

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
    $altTreiber->posenFenster = 0.5;
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
@unlink((new RoboHubIrobot())->wegDatei('3176C41234567890'));
$ar->Aktualisieren();
check('Einstellungen wie in der iRobot-App gelesen (Thilos Rohdaten): Automatisch, nach Raumgröße, Kanten an, bei vollem Behälter anhalten',
    variable(7300, 'Saugstufe')['value'] === 1 && variable(7300, 'Durchgaenge')['value'] === 0 && variable(7300, 'Kantenreinigung')['value'] === 1
    && variable(7300, 'BehaelterVoll')['value'] === 1 && variable(7300, 'Durchgaenge')['action'] === true);
$weg = json_decode((string)@file_get_contents((new RoboHubIrobot())->wegDatei('3176C41234567890')), true);
check('Positionen beim Abruf während der Fahrt gesammelt (Weg der laufenden Reinigung)', count($weg['punkte'] ?? []) >= 3 && ($weg['zuletzt'] ?? null) === [1200, 900, 90], json_encode($weg));
$ar->RequestAction('Durchgaenge', 2);
$lg = (string)file_get_contents($n3[5]);
check('Durchgänge „Zweifach-Reinigung" als Paar per delta (einzeln ignoriert der Roboter es)', strpos($lg, 'befehl delta {"state":{"noAutoPasses":true,"twoPass":true}}') !== false
    && variable(7300, 'Durchgaenge')['value'] === 2, $lg);
$ar->RequestAction('Saugstufe', 2);
check('Saugstufe „Hoch" = carpetBoost aus, vacHigh an', strpos((string)file_get_contents($n3[5]), '{"carpetBoost":false,"vacHigh":true}') !== false);
$ar->Aktualisieren();
check('Roboter meldet die neuen Werte zurück → Variablen bleiben', variable(7300, 'Durchgaenge')['value'] === 2 && variable(7300, 'Saugstufe')['value'] === 2);
$k = kachelJson($ar->GetVisualizationTile());
check('Kachel-Überblick zeigt die Einstellungen mit Namen', in_array('Reinigungsdurchgänge: Zweifach-Reinigung', $k['info'] ?? [], true), json_encode($k['info'] ?? null, JSON_UNESCAPED_UNICODE));
$ar->props['EinstellungenZeigen'] = false;
$ar->ApplyChanges();
$k = kachelJson($ar->GetVisualizationTile());
$vid = IPS_GetObjectIDByIdent('Durchgaenge', 7300);
check('„Einstellungen zeigen" aus → nur der Kachel-Überblick ohne Einstellungen, Variablen bleiben sichtbar und bedienbar', IPS_GetObject($vid)['ObjectIsHidden'] === false
    && !in_array('Reinigungsdurchgänge: Zweifach-Reinigung', $k['info'] ?? [], true) && variable(7300, 'Durchgaenge')['action'] === true);
// Altlast aus 0.19.0/0.19.1: dort versteckte Variablen werden einmalig wieder sichtbar.
IPS_SetHidden($vid, true);
$ar->WriteAttributeBoolean('EinstellungenFreigegeben', false);
$ar->ApplyChanges();
check('von 0.19.0 versteckte Einstellungs-Variablen werden einmalig wieder sichtbar', IPS_GetObject($vid)['ObjectIsHidden'] === false && $ar->ReadAttributeBoolean('EinstellungenFreigegeben') === true);
IPS_SetHidden($vid, true);   // danach selbst versteckt → bleibt so
$ar->ApplyChanges();
check('… danach respektiert RoboHub selbst versteckte Variablen', IPS_GetObject($vid)['ObjectIsHidden'] === true);
IPS_SetHidden($vid, false);
$ar->props['EinstellungenZeigen'] = true;
$ar->ApplyChanges();
check('Kachel-Überblick: Saugstufe mit Namen („Hoch" allein sagt nichts)', in_array('Saugstufe: Hoch', kachelJson($ar->GetVisualizationTile())['info'] ?? [], true));
// Einzelnen Roboter deaktivieren (Forum Thilo 25.10.2026)
$vorLog = strlen((string)file_get_contents($n3[5]));
$ar->props['Deaktiviert'] = true;
$ar->ApplyChanges();
$t1 = $ar->Aktualisieren();
$t2 = $ar->Starten();
check('deaktivierter Roboter: Status inaktiv, Takte aus, weder Abruf noch Befehl erreichen den Roboter', $ar->status === 104 && ($ar->timers['Abruf'] ?? 1) === 0
    && strpos($t1, 'deaktiviert') !== false && strpos($t2, 'deaktiviert') !== false && strlen((string)file_get_contents($n3[5])) === $vorLog
    && variable(7300, 'Erreichbar')['value'] === true, $t1 . ' | ' . $t2);
$ar->props['Deaktiviert'] = false;
$ar->ApplyChanges();
check('… wieder aktiviert: Status aktiv, Abruf läuft', $ar->status === 102 && ($ar->timers['Abruf'] ?? 0) > 0);
$t = $ar->LiveKarteLaden();
$k = kachelJson($ar->GetVisualizationTile());
check('Live-Karte aus den beim Abruf gesammelten Positionen: Weg, Roboter mit Blickrichtung, Station', count($k['karte']['weg'][0] ?? []) >= 3
    && ($k['karte']['roboter']['winkel'] ?? null) === 90 && isset($k['karte']['station']['x']) && strpos((string)($k['karte']['bild'] ?? ''), 'data:image/svg+xml') === 0, $t . ' ' . json_encode($k['karte'] ?? null));
$ar->props['KarteZeigen'] = false;
$ar->ApplyChanges();
check('Positionskarte abschaltbar („Kartenbild zeigen" aus → keine Karte auf der Kachel)', kachelJson($ar->GetVisualizationTile())['karte'] === null);
$ar->props['KarteZeigen'] = true;
$ar->ApplyChanges();
check('Stand mit QoS 1: jedes Stück bestätigt (PUBACK), sonst käme beim 980 nichts nach', substr_count((string)file_get_contents($n3[5]), 'puback') >= 2, (string)file_get_contents($n3[5]));
AltRoomba::$wege = [];
$ar->Aktualisieren();
check('gemerkter TLS-Weg: beim nächsten Abruf gleich der eigene, kein vergeblicher OpenSSL-Versuch', AltRoomba::$wege === ['eigen'], json_encode(AltRoomba::$wege));
touch($n3[5] . '.nurfunk');
$t = $ar->Aktualisieren();
check('nur Funkmeldungen (980 sporadisch): letzter Stand bleibt, kein Fehler', strpos($t, '✅') === 0 && variable(7300, 'Zustand')['value'] === RoboHubModell::ZUSTAND_REINIGT
    && variable(7300, 'Erreichbar')['value'] === true, $t);
$snapA = json_decode($ak->ReadAttributeString('Schnappschuss'), true);
$snapA['3176C41234567890']['p']['_abgerufen'] = time() - 1000;
$ak->WriteAttributeString('Schnappschuss', json_encode($snapA));
$t = $ar->Aktualisieren();
check('… aber nicht mit einem Stand älter als 15 Minuten: dann ehrlicher Fehler mit den Meldungen', strpos($t, 'keinen Zustand gemeldet') !== false && strpos($t, 'wifistat') !== false, $t);
unlink($n3[5] . '.nurfunk');
$n3Port = $n3[2];
$n3[2] = 1;   // Port zu → sofort abgewiesen
$vor = microtime(true);
$t = $ar->Aktualisieren();
check('abgewiesen → nach 3 s ein zweiter Versuch, dann Hinweis auf iRobot-App/andere Verbindung', microtime(true) - $vor >= 3.0 && strpos($t, 'iRobot-App geöffnet') !== false && strpos($t, 'nicht erreichbar') !== false, $t);
$n3[2] = $n3Port;
$t = $ar->ZurStation();
check('Station mitten in der Reinigung: erst Pause, dann dock — und der Roboter fährt zur Station', strpos($t, 'Roboter meldet: Fährt zur Station') !== false
    && ($lg = (string)file_get_contents($n3[5])) !== '' && strpos($lg, '"command":"pause"') !== false
    && strpos($lg, '"command":"pause"') < strpos($lg, '"command":"dock"'), $t . ' | ' . ($lg ?? ''));
touch($n3[5] . '.taub');
$vor = microtime(true);
$t = $ar->Anhalten();   // Stand meldet „run", Pause bleibt unbestätigt
check('ohne Reaktion: ehrlicher Hinweis nach höchstens ~10 s', strpos($t, 'keine Änderung gemeldet') !== false && microtime(true) - $vor < 14, $t);
unlink($n3[5] . '.taub');
$ar->visu = [];
$ar->Starten();
$kv = json_decode((string)end($ar->visu), true);
check('Befehl per Skript (ROBO_Starten) zeigt die Rückmeldung auch in der Kachel', strpos((string)($kv['rueck'] ?? ''), 'Befehl „Start" gesendet') !== false, json_encode($kv['rueck'] ?? null, JSON_UNESCAPED_UNICODE));
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
@unlink((new RoboHubIrobot())->wegDatei('3176C41234567890'));
