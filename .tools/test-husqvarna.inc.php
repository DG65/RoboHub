<?php
/**
 * Husqvarna-Teil des Prüfstands (von test-robohub.php eingebunden).
 * Antwortformat wie die offizielle Automower Connect API (JSON:API), Felder
 * wie in aioautomower tests/fixtures (MIT).
 */

declare(strict_types=1);

/** Attrappe der Husqvarna-API: ersetzt nur http(). */
class FakeHusqvarna extends RoboHubHusqvarna
{
    public static $anfragen = [];
    public static $maeher = [];
    public static $tokenNr = 0;
    public static $limit = false;

    public static function zaehle(string $teil): int
    {
        return count(array_filter(self::$anfragen, function ($a) use ($teil) { return strpos($a['methode'] . ' ' . $a['url'], $teil) !== false; }));
    }

    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        self::$anfragen[] = ['methode' => $methode, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if ($url === 'https://api.authentication.husqvarnagroup.dev/v1/oauth2/token') {
            parse_str((string)$body, $f);
            if (($f['grant_type'] ?? '') !== 'client_credentials' || ($f['client_secret'] ?? '') !== 'GEHEIM') {
                return [400, '{"error":"invalid_client"}'];
            }
            self::$tokenNr++;
            return [200, json_encode(['access_token' => 'HT' . self::$tokenNr, 'expires_in' => 86399, 'token_type' => 'Bearer', 'provider' => 'husqvarna'])];
        }
        $kopf = implode("\n", $headers);
        if (strpos($kopf, 'Authorization: Bearer HT') === false || strpos($kopf, 'Authorization-Provider: husqvarna') === false || strpos($kopf, 'X-Api-Key: ' . $GLOBALS['hqSchluessel']) === false) {
            return [401, '{}'];
        }
        if (self::$limit) {
            return [429, '{"message":"Limit Exceeded"}'];
        }
        if ($methode === 'GET' && $url === 'https://api.amc.husqvarna.dev/v1/mowers') {
            return [200, json_encode(['data' => array_values(self::$maeher)])];
        }
        if ($methode === 'POST' && preg_match('#/v1/mowers/([^/]+)/settings$#', $url, $m)) {
            return isset(self::$maeher[$m[1]]) ? [202, '{}'] : [404, '{}'];
        }
        if ($methode === 'POST' && preg_match('#/v1/mowers/([^/]+)/actions$#', $url, $m)) {
            return isset(self::$maeher[$m[1]]) ? [202, '{}'] : [404, '{"errors":[{"title":"Not found"}]}'];
        }
        return [404, '{}'];
    }
}

function hqMaeher(string $id, string $name, array $mower, array $zusatz = []): array
{
    return ['type' => 'mower', 'id' => $id, 'attributes' => array_merge([
        'system' => ['name' => $name, 'model' => 'Automower 450X NERA', 'serialNumber' => 123],
        'battery' => ['batteryPercent' => 64, 'remainingChargingTime' => 0],
        'capabilities' => ['workAreas' => true, 'position' => true, 'headlights' => true],
        'settings' => ['cuttingHeight' => 4, 'headlight' => ['mode' => 'EVENING_ONLY']],
        'positions' => [],
        'mower' => array_merge(['mode' => 'MAIN_AREA', 'activity' => 'MOWING', 'inactiveReason' => 'NONE', 'state' => 'IN_OPERATION', 'errorCode' => 0, 'errorCodeTimestamp' => 0], $mower),
        'planner' => ['nextStartTimestamp' => 0, 'override' => ['action' => 'NOT_ACTIVE'], 'restrictedReason' => 'NONE'],
        'metadata' => ['connected' => true, 'statusTimestamp' => 1697669932683],
        'statistics' => ['cuttingBladeUsageTime' => 1234, 'totalCuttingTime' => 4194000, 'numberOfChargingCycles' => 1380],
        'workAreas' => [['workAreaId' => 123456, 'name' => 'Vorgarten'], ['workAreaId' => 654321, 'name' => 'Hinterm Haus'], ['workAreaId' => 0, 'name' => '']],
    ], $zusatz)];
}

// ===========================================================================
abschnitt('Husqvarna: Anmeldung, Geräte, Zustand, Befehle');
$GLOBALS['hqSchluessel'] = bin2hex(random_bytes(16));
$fabrikHq = $GLOBALS['robohubTreiberFabrik'];
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) use ($fabrikHq) {
    return $marke === 'husqvarna' ? new FakeHusqvarna() : $fabrikHq($marke);
};
FakeHusqvarna::$maeher = [
    'M1' => hqMaeher('M1', 'Rasenmeister', []),
    'M2' => hqMaeher('M2', 'Kleiner', ['activity' => 'PARKED_IN_CS', 'state' => 'RESTRICTED'], ['planner' => ['restrictedReason' => 'SENSOR']]),
];
$hk = new RoboHubKonto(8000);
$hk->props['Hersteller'] = 'husqvarna';
$hk->Create();
$hk->ApplyChanges();
$f = json_encode(json_decode($hk->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
check('Formular: Application Key/Secret, Anleitung Entwicklerportal', strpos($f, '"caption":"Application Key"') !== false && strpos($f, 'Application Secret (bleibt im Konto gespeichert)') !== false && strpos($f, 'developer.husqvarnagroup.cloud') !== false);
$hk->props['Konto'] = 'garten@example.org';
$hk->props['Passwort'] = 'GEHEIM';
check('E-Mail statt Application Key → Hinweis, nichts gesendet', strpos($hk->Anmelden(), 'Application Key') !== false && FakeHusqvarna::zaehle('oauth2') === 0);
$hk->props['Konto'] = $GLOBALS['hqSchluessel'];
$hk->props['Passwort'] = 'FALSCH';
check('falsches Secret abgelehnt', strpos($hk->Anmelden(), 'lehnt App-Schlüssel') !== false);
$hk->props['Passwort'] = 'GEHEIM';
$t = $hk->Anmelden();
$hb = json_decode($hk->ReadAttributeString('Buendel'), true);
check('angemeldet, ehrlicher Hinweis auf gespeichertes Secret', strpos($t, '✅ Angemeldet') === 0 && strpos($t, 'bleiben im Konto gespeichert') !== false && $hk->props['Passwort'] === '', $t);
check('Bündel: Key, Secret, Token, ~24 h', ($hb['appGeheimnis'] ?? '') === 'GEHEIM' && ($hb['token'] ?? '') === 'HT1' && $hb['expires_at'] > time() + 86000);
check('Protokoll ohne Secret', strpos(implode("\n", $GLOBALS['LOG']), 'GEHEIM') === false);
$hg = json_decode($hk->ReadAttributeString('Geraete'), true);
check('zwei Mäher aus /mowers', count($hg) === 2 && $hg[0]['klasse'] === 'maeher' && $hg[0]['modell'] === 'husqvarna.mower.automower450xnera' && $hg[1]['name'] === 'Kleiner');

$h1 = new RoboHubGeraet(8100);
$h1->props = ['GeraetID' => 'M1', 'Modell' => 'husqvarna.mower.automower450xnera', 'Klasse' => 'maeher', 'Intervall' => 120];
$h1->Create();
$h1->parent = $hk;
$h1->ApplyChanges();
$h2 = new RoboHubGeraet(8200);
$h2->props = ['GeraetID' => 'M2', 'Modell' => 'husqvarna.mower.automower450xnera', 'Klasse' => 'maeher', 'Intervall' => 120];
$h2->Create();
$h2->parent = $hk;
$h2->ApplyChanges();
$vorher = FakeHusqvarna::zaehle('GET https://api.amc.husqvarna.dev/v1/mowers');
$h1->Aktualisieren();
$h2->Aktualisieren();
$h1->Aktualisieren();
check('ein gemeinsamer Abruf für alle Mäher (Limit 10.000/Monat)', FakeHusqvarna::zaehle('GET https://api.amc.husqvarna.dev/v1/mowers') - $vorher <= 1);
check('mäht, Akku 64, kein Fehler, Mähzeit 1165 h', variable(8100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_REINIGT && variable(8100, 'Akku')['value'] === 64
    && variable(8100, 'Fehler')['value'] === 'kein Fehler' && variable(8100, 'GesamtDauer')['value'] === 1165);
check('geparkt wegen Wettersensor = Wetterpause, Meldung', variable(8200, 'Zustand')['value'] === RoboHubModell::ZUSTAND_WETTERPAUSE && variable(8200, 'Meldung')['value'] === 'Eingeschränkt: Wettersensor');
$zo = array_column(json_decode((string)(variable(8100, 'Raum')['pres']['OPTIONS'] ?? '[]'), true), 'Caption', 'Value');
check('Arbeitsbereiche als Zonen, Bereich 0 = Hauptbereich', ($zo[123456] ?? '') === 'Vorgarten' && ($zo[0] ?? '') === 'Hauptbereich', json_encode($zo, JSON_UNESCAPED_UNICODE));

$aktionen = function (): array {
    $aus = [];
    foreach (FakeHusqvarna::$anfragen as $a) {
        if (strpos($a['url'], '/actions') !== false) {
            $aus[] = json_decode((string)$a['body'], true)['data'];
        }
    }
    return $aus;
};
FakeHusqvarna::$anfragen = [];
$h1->Anhalten();
$h1->ZurStation();
$h1->Stoppen();
$h1->RaeumeReinigen('654321');
check('Pause, Parken bis Zeitplan, Parken bis auf Weiteres, Bereich mähen', $aktionen() === [
    ['type' => 'Pause'], ['type' => 'ParkUntilNextSchedule'], ['type' => 'ParkUntilFurtherNotice'],
    ['type' => 'StartInWorkArea', 'attributes' => ['duration' => 180, 'workAreaId' => 654321]],
], json_encode($aktionen()));
$h1->Aktualisieren();
check('nach einem Befehl holt der nächste Abruf frisch (Zwischenspeicher verworfen)', FakeHusqvarna::zaehle('GET https://api.amc.husqvarna.dev/v1/mowers') === 1);
FakeHusqvarna::$maeher['M1'] = hqMaeher('M1', 'Rasenmeister', ['state' => 'PAUSED', 'activity' => 'MOWING']);
@unlink(sys_get_temp_dir() . '/robohub-husqvarna-' . substr(md5($GLOBALS['hqSchluessel']), 0, 12) . '.json');   // neue Lage erst nach Ablauf sichtbar
$h1->Aktualisieren();
FakeHusqvarna::$anfragen = [];
$h1->Starten();
check('pausiert → Start = ResumeSchedule', $aktionen() === [['type' => 'ResumeSchedule']]);
FakeHusqvarna::$maeher['M1'] = hqMaeher('M1', 'Rasenmeister', ['state' => 'ERROR', 'activity' => 'NOT_APPLICABLE', 'errorCode' => 9]);
$h1->Aktualisieren();
FakeHusqvarna::$anfragen = [];
$h1->Starten();
check('Fehler 9 = Eingeklemmt; Start sonst mit Dauer', variable(8100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_FEHLER && variable(8100, 'Fehler')['value'] === 'Eingeklemmt (9)'
    && $aktionen() === [['type' => 'Start', 'attributes' => ['duration' => 180]]]);
check('mehrere Bereiche auf einmal → klare Meldung', strpos($h1->RaeumeReinigen('123456,654321'), 'genau einen') !== false);

$hb = json_decode($hk->ReadAttributeString('Buendel'), true);
$hb['expires_at'] = time() + 60;
$hk->WriteAttributeString('Buendel', json_encode($hb));
$hk->Erneuern();
check('Token-Erneuerung mit gespeichertem Secret', json_decode($hk->ReadAttributeString('Buendel'), true)['token'] === 'HT' . FakeHusqvarna::$tokenNr && FakeHusqvarna::$tokenNr >= 2);
FakeHusqvarna::$limit = true;
@unlink(sys_get_temp_dir() . '/robohub-husqvarna-' . substr(md5($GLOBALS['hqSchluessel']), 0, 12) . '.json');
$t = $h1->Aktualisieren();
check('Limit erreicht (429) → Konto wartet', $hk->ReadAttributeInteger('WarteBis') > time() + 3000, $t);
FakeHusqvarna::$limit = false;
$hk->WriteAttributeInteger('WarteBis', 0);

// ---------------------------------------------------------------------------
abschnitt('Husqvarna: Echtzeit über WebSocket, Schnitthöhe, Scheinwerfer');
$hqDatei = sys_get_temp_dir() . '/robohub-husqvarna-' . substr(md5($GLOBALS['hqSchluessel']), 0, 12) . '.json';
FakeHusqvarna::$maeher['M1'] = hqMaeher('M1', 'Rasenmeister', []);
@unlink($hqDatei);
$h1->Aktualisieren();
check('Schnitthöhe und Scheinwerfer gelesen, umstellbar', variable(8100, 'Schnitthoehe')['value'] === 4 && variable(8100, 'Scheinwerfer')['value'] === 2
    && variable(8100, 'Schnitthoehe')['action'] === true && variable(8100, 'Scheinwerfer')['action'] === true);
FakeHusqvarna::$anfragen = [];
$h1->RequestAction('Schnitthoehe', 6);
$h1->RequestAction('Scheinwerfer', 3);
$einst = array_values(array_map(function ($a) { return json_decode((string)$a['body'], true); }, array_filter(FakeHusqvarna::$anfragen, function ($a) { return strpos($a['url'], '/settings') !== false; })));
check('Schnitthöhe/Scheinwerfer per POST mowers/{id}/settings (wie aioautomower)', $einst === [
    ['data' => ['type' => 'settings', 'attributes' => ['cuttingHeight' => 6]]],
    ['data' => ['type' => 'settings', 'attributes' => ['headlight' => ['mode' => 'EVENING_AND_NIGHT']]]],
], json_encode($einst));

$GLOBALS['ERZEUGT'] = [];
$GLOBALS['SKRIPTE'] = [];
$hk->VerbindungPruefen();
$hws = $hk->parent;
$cfgSkript = implode("\n", $GLOBALS['SKRIPTE']);
check('Konto bekommt einen WebSocket Client (nicht Client Socket)', ($GLOBALS['ERZEUGT'][0][0] ?? '') === '{D68FD31F-0E90-7019-F16C-1949BD3079EF}' && $hws instanceof FakeSocket
    && strpos(IPS_GetName($hws->InstanceID), 'RoboHub Echtzeit') === 0, json_encode($GLOBALS['ERZEUGT']));
$wsCfg = json_decode($hk->GetConfigurationForParent(), true);
check('WebSocket: Husqvarna-Adresse, Bearer-Kopf, Zertifikatsprüfung an, aktiv (und so an den Client übergeben)', $wsCfg['URL'] === 'wss://ws.openapi.husqvarna.dev/v1'
    && json_decode($wsCfg['Headers'], true) === [['Name' => 'Authorization', 'Value' => 'Bearer HT' . FakeHusqvarna::$tokenNr]] && $wsCfg['VerifyCertificate'] === true
    && $wsCfg['Active'] === true && strpos($cfgSkript, 'IPS_SetConfiguration(' . $hws->InstanceID) !== false, json_encode($wsCfg));
$hk->MessageSink(0, $hws->InstanceID, IM_CHANGESTATUS, [IS_ACTIVE]);
check('verbunden → Echtzeit-Zeile, Lebenszeichen alle 60 s', (json_decode($hk->ReadAttributeString('Mqtt'), true)['zustand'] ?? '') === 'verbunden' && ($hk->timers['MqttPing'] ?? 0) === 60000);
$hws->gesendet = [];
$hk->MqttPing();
check('Lebenszeichen = leere Textnachricht', $hws->gesendet === ['']);

$h1->Aktualisieren();   // Zwischenspeicher + Schnappschuss vorhanden
$vorAbruf = FakeHusqvarna::zaehle('GET https://api.amc.husqvarna.dev/v1/mowers');
$hk->ReceiveData(json_encode(['DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => json_encode(['id' => 'M1', 'type' => 'mower-event-v2', 'attributes' => ['mower' => ['activity' => 'GOING_HOME', 'state' => 'IN_OPERATION']]])]));
$hk->ReceiveData(json_encode(['DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => json_encode(['id' => 'M1', 'type' => 'battery-event-v2', 'attributes' => ['battery' => ['batteryPercent' => 41]]])]));
$hk->ReceiveData(json_encode(['DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => json_encode(['id' => 'M1', 'type' => 'cuttingHeight-event-v2', 'attributes' => ['cuttingHeight' => ['height' => 7]]])]));
check('Ereignisse kommen sofort an: fährt heim, Akku 41, Schnitthöhe 7 — ohne Abruf', variable(8100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_ZURUECK
    && variable(8100, 'Akku')['value'] === 41 && variable(8100, 'Schnitthoehe')['value'] === 7 && FakeHusqvarna::zaehle('GET https://api.amc.husqvarna.dev/v1/mowers') === $vorAbruf);
$h1->Aktualisieren();
check('Ereignis steckt auch im Zwischenspeicher: nächster Abruf zeigt dasselbe (kein Zurückspringen)', variable(8100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_ZURUECK
    && variable(8100, 'Akku')['value'] === 41 && FakeHusqvarna::zaehle('GET https://api.amc.husqvarna.dev/v1/mowers') === $vorAbruf);
@touch($hqDatei, time() - 3500);
clearstatcache();
$hk->ReceiveData(json_encode(['DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => json_encode(['id' => 'M1', 'type' => 'battery-event-v2', 'attributes' => ['battery' => ['batteryPercent' => 40]]])]));
clearstatcache();
check('Ereignis verjüngt den Zwischenspeicher nicht (der stündliche Vollabruf bleibt fällig)', filemtime($hqDatei) <= time() - 3400 && variable(8100, 'Akku')['value'] === 40);
@touch($hqDatei, time() - 900);
clearstatcache();
$h1->Aktualisieren();
check('Echtzeit steht → Zwischenspeicher gilt eine Stunde (Anfragelimit)', FakeHusqvarna::zaehle('GET https://api.amc.husqvarna.dev/v1/mowers') === $vorAbruf);
$hk->MessageSink(0, $hws->InstanceID, IM_CHANGESTATUS, [200]);
$h1->Aktualisieren();
check('Verbindung weg → wieder Abruf nach 5 min; neuer Versuch geplant', FakeHusqvarna::zaehle('GET https://api.amc.husqvarna.dev/v1/mowers') === $vorAbruf + 1
    && ($hk->timers['Verbindung'] ?? 0) === 30000 && ($hk->timers['MqttPing'] ?? 1) === 0);
$hk->MessageSink(0, $hws->InstanceID, IM_CHANGESTATUS, [200]);
check('… wachsender Abstand', ($hk->timers['Verbindung'] ?? 0) === 60000);

// Bestandskonto: alter, ungenutzter Client Socket von RoboHub → wird gegen WebSocket Client getauscht.
$hk2 = new RoboHubKonto(8500);
$hk2->props['Hersteller'] = 'husqvarna';
$hk2->Create();
$alt = new FakeSocket(8501);
IPS_SetName(8501, 'RoboHub Verbindung (Instanz 8500, ungenutzt)');
$hk2->parent = $alt;
$GLOBALS['ERZEUGT'] = [];
$GLOBALS['GELOESCHT'] = [];
$hk2->ApplyChanges();
check('Bestandskonto mit falscher Verbindungsart: Tausch wird eingeplant', ($hk2->timers['Verbindung'] ?? 0) > 0);
$hk2->VerbindungPruefen();
check('… alter RoboHub-Socket gelöscht, WebSocket Client verbunden', in_array(8501, $GLOBALS['GELOESCHT'], true) && $hk2->parent instanceof FakeSocket
    && $hk2->parent->modul === '{D68FD31F-0E90-7019-F16C-1949BD3079EF}');
$fremd = new FakeSocket(8502);
IPS_SetName(8502, 'Mein eigener Socket');
$hk2->parent = $fremd;
$GLOBALS['GELOESCHT'] = [];
$hk2->VerbindungPruefen();
check('fremde Verbindungsinstanz wird nie angefasst', $GLOBALS['GELOESCHT'] === [] && $hk2->parent === $fremd);
unset($GLOBALS['INST'][8500], $GLOBALS['INST'][8501], $GLOBALS['INST'][8502]);

$cfgW = new RoboHubKonfigurator(8600);
$cfgW->Create();
$cfgW->ApplyChanges();
$wf = json_decode($cfgW->GetConfigurationForm(), true);
$kette = null;
foreach ($wf['elements'] as $e) { foreach (($e['items'] ?? []) as $i) { if (($i['type'] ?? '') === 'Configurator') { foreach ($i['values'] as $v) { if (($v['id'] ?? '') === 'M2') { $kette = $v['create'] ?? null; } } } } }
check('Konfigurator-Kette endet beim WebSocket Client des Husqvarna-Kontos', ($kette[2]['moduleID'] ?? '') === '{D68FD31F-0E90-7019-F16C-1949BD3079EF}', json_encode($kette));
unset($GLOBALS['INST'][8600]);
$hk->Abmelden();
check('Abmelden löscht Key und Secret', $hk->ReadAttributeString('Buendel') === '');
@unlink(sys_get_temp_dir() . '/robohub-husqvarna-' . substr(md5($GLOBALS['hqSchluessel']), 0, 12) . '.json');
$GLOBALS['robohubTreiberFabrik'] = $fabrikHq;
