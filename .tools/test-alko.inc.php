<?php
/**
 * AL-KO-Teil des Prüfstands (von test-robohub.php eingebunden).
 * Datenformat wie trx222/alko-cli docs/API.md (MIT, am Robolinho 700 W gemessen).
 */

declare(strict_types=1);

class FakeAlko extends RoboHubAlko
{
    public static $anfragen = [];
    public static $reported = [];
    public static $gueltig = 'K1';
    public static $refresh = 'KR1';
    public static $runde = 1;

    public static function patches(): array
    {
        return array_values(array_map(function ($a) { return json_decode($a['body'], true); }, array_filter(self::$anfragen, function ($a) { return $a['methode'] === 'PATCH'; })));
    }

    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        self::$anfragen[] = ['methode' => $methode, 'url' => $url, 'headers' => $headers, 'body' => (string)$body];
        if ($url === 'https://idp.al-ko.com/connect/token') {
            parse_str((string)$body, $f);
            if (($f['client_id'] ?? '') !== 'robohub-test' || ($f['client_secret'] ?? '') !== 'GEHEIM-AL') {
                return [400, '{"error":"invalid_client"}'];
            }
            $ok = ($f['grant_type'] === 'password' && ($f['username'] ?? '') === 'garten@example.com' && ($f['password'] ?? '') === 'Robo!'
                    && ($f['scope'] ?? '') === 'alkoCustomerId alkoCulture offline_access introspection')
                || ($f['grant_type'] === 'refresh_token' && ($f['refresh_token'] ?? '') === self::$refresh);
            if (!$ok) {
                return [400, '{"error":"invalid_grant"}'];
            }
            self::$runde++;
            self::$gueltig = 'K' . self::$runde;
            self::$refresh = 'KR' . self::$runde;
            return [200, json_encode(['access_token' => self::$gueltig, 'expires_in' => 3600, 'token_type' => 'Bearer', 'refresh_token' => self::$refresh])];
        }
        if (strpos(implode("\n", $headers), 'Authorization: Bearer ' . self::$gueltig) === false) {
            return [401, ''];
        }
        $api = 'https://api.al-ko.com/v1/iot/';
        if ($methode === 'GET' && $url === $api . 'things?pimInfo=true&thingState=true&accesses=true&thingCategory=ALKO-ROBOLINHO') {
            return [200, json_encode([['thingName' => 'abcdef0123456789abcdef0123456789abcdef01', 'thingType' => 'ALKO-ROBOLINHO',
                'thingAttributes' => ['thingModel' => 'Robolinho700', 'firmwareVersion' => '1.9.95', 'articleNumber' => '127569'],
                'thingState' => ['state' => ['reported' => ['isConnected' => true]]]], ['thingType' => 'ohne Namen']])];
        }
        if ($methode === 'GET' && $url === $api . 'things/abcdef0123456789abcdef0123456789abcdef01/state') {
            return [200, json_encode(['state' => ['reported' => self::$reported, 'desired' => [], 'delta' => []], 'version' => 1])];
        }
        if ($methode === 'PATCH' && $url === $api . 'things/abcdef0123456789abcdef0123456789abcdef01/state/desired') {
            return [200, '{}'];
        }
        return [404, '{}'];
    }
}

// ===========================================================================
abschnitt('AL-KO Robolinho');
$fabrikAl = $GLOBALS['robohubTreiberFabrik'];
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) use ($fabrikAl) {
    return $marke === 'alko' ? new FakeAlko() : $fabrikAl($marke);
};
$ak = new RoboHubKonto(10000);
$ak->props['Hersteller'] = 'alko';
$ak->Create();
$ak->ApplyChanges();
$alSichtbar = [];
$alSuche = function ($k) use (&$alSuche, &$alSichtbar) {
    if (!is_array($k)) {
        return;
    }
    if (isset($k['caption']) && is_string($k['caption']) && ($k['visible'] ?? true) !== false) {
        $alSichtbar[] = $k['caption'] . (isset($k['name']) ? ' [' . $k['name'] . ']' : '');
    }
    foreach ($k as $w) {
        $alSuche($w);
    }
};
$alSuche(json_decode($ak->GetConfigurationForm(), true));
$alText = implode("\n", $alSichtbar);
check('Formular: Anleitung zum API-Zugang, Felder Client-ID/Secret sichtbar, offen erklärt warum gespeichert', strpos($alText, 'iot-api-zugang-anfordern') !== false
    && strpos($alText, '[ClientId]') !== false && strpos($alText, '[ClientSecret]') !== false && strpos($alText, 'weil AL-KO den Zugang nur damit erneuert') !== false);
$ak->props['Konto'] = 'garten@example.com';
$ak->props['Passwort'] = 'Robo!';
$t = $ak->Anmelden();
check('ohne Client-ID/Secret: Hinweis, keine Anfrage', strpos($t, 'Client-ID und Client-Secret eintragen') !== false && FakeAlko::$anfragen === [], $t);
$ak->props['ClientId'] = 'robohub-test';
$ak->props['ClientSecret'] = 'FALSCH';
$t = $ak->Anmelden();
check('falsches Secret: verständliche Ablehnung', strpos($t, 'AL-KO lehnt die Anmeldung ab (invalid_client)') !== false, $t);
$ak->props['ClientSecret'] = 'GEHEIM-AL';
$ak->props['Passwort'] = 'Robo!';
$t = $ak->Anmelden();
$ab = json_decode($ak->ReadAttributeString('Buendel'), true);
$ag = json_decode($ak->ReadAttributeString('Geraete'), true);
check('angemeldet: Passwort-Grant mit offline_access, Bündel mit Client-ID/Secret, OHNE Passwort; ein Mäher', strpos($t, '✅ Angemeldet. Client-ID und Client-Secret bleiben') === 0
    && array_keys($ab) === ['clientId', 'clientSecret', 'access_token', 'refresh_token', 'expires_at'] && strpos(json_encode($ab), 'Robo!') === false
    && count($ag) === 1 && $ag[0]['id'] === 'abcdef0123456789abcdef0123456789abcdef01' && $ag[0]['modell'] === 'alko.mower.robolinho700' && $ag[0]['name'] === 'Robolinho700', $t);
check('Passwort und Secret danach aus dem Formular geleert, Client-ID bleibt', $ak->props['Passwort'] === '' && $ak->props['ClientSecret'] === '' && $ak->props['ClientId'] === 'robohub-test');

$am = new RoboHubGeraet(10050);
$am->props = ['GeraetID' => 'abcdef0123456789abcdef0123456789abcdef01', 'Modell' => 'alko.mower.robolinho700', 'Klasse' => 'maeher', 'Intervall' => 120];
$am->Create();
$am->parent = $ak;
$am->ApplyChanges();
$mitFenster = ['monday' => ['window_1' => ['activityMode' => true, 'marginMode' => true, 'startHour' => 7, 'startMinute' => 0, 'duration' => 180, 'entryPoint' => 33, 'narrowPassageMode' => false],
    'window_2' => ['activityMode' => false, 'startHour' => 0, 'startMinute' => 0, 'duration' => 0]], 'thursday' => ['window_1' => ['activityMode' => true, 'startHour' => 14, 'startMinute' => 30, 'duration' => 90]],
    'saturday' => ['window_1' => ['activityMode' => false, 'startHour' => 9, 'startMinute' => 0, 'duration' => 120]]];   // ausgeschaltet, aber mit Dauer
FakeAlko::$reported = ['isConnected' => true, 'rssi' => -65, 'batteryLevel' => 100, 'operationState' => 'CHARGING', 'operationSubState' => 'READY',
    'operationSituation' => 'OPERATION_PERMITTED', 'operationError' => ['code' => 999, 'type' => 'ISSUE', 'description' => 'UNKNOWN'],
    'rainDelay' => 30, 'operationTimeMowing' => 167602, 'operationTimeBlade' => 127640, 'mowingCycles' => 6670, 'chargingCycles' => 45434, 'remainingBladeLifetime' => 100,
    'mowingWindows' => $mitFenster, 'situationFlags' => ['chargerActive' => true, 'rainDetected' => false, 'rainAllowsMowing' => true]];
$am->Aktualisieren();
check('lädt, Akku 100, −65 dBm, Mähzeit 2793 h, Messer 2127,3 h, 6670 Zyklen, 45434 Ladezyklen, Messer 100 %, Regenverzögerung 30 min, kein Fehler (999)',
    variable(10050, 'Zustand')['value'] === RoboHubModell::ZUSTAND_LAEDT && variable(10050, 'Akku')['value'] === 100 && variable(10050, 'WlanSignal')['value'] === -65
    && variable(10050, 'GesamtDauer')['value'] === 2793 && variable(10050, 'Messerlaufzeit')['value'] === 2127.3 && variable(10050, 'GesamtAnzahl')['value'] === 6670
    && variable(10050, 'Ladezyklen')['value'] === 45434 && variable(10050, 'Messer')['value'] === 100 && variable(10050, 'Regenverzoegerung')['value'] === '30 min'
    && variable(10050, 'Fehler')['value'] === 'kein Fehler' && variable(10050, 'Laedt')['value'] === true);
check('Wochenplan: nur aktive Fenster, mit Randmähen', strpos((string)variable(10050, 'Zeitplaene')['value'], 'Mo 07:00 · 180 min · mit Randmähen') !== false
    && strpos((string)variable(10050, 'Zeitplaene')['value'], 'Do 14:30 · 90 min') !== false && substr_count((string)variable(10050, 'Zeitplaene')['value'], ' min') === 2,
    (string)variable(10050, 'Zeitplaene')['value']);
$zustandAl = function (array $r) use ($am): int {
    FakeAlko::$reported = $r + ['isConnected' => true, 'operationError' => ['code' => 999, 'description' => 'UNKNOWN']];
    $am->Aktualisieren();
    return variable(10050, 'Zustand')['value'];
};
check('WORKING mäht, IDLE_BASE_STATION in der Station, IDLE angehalten', $zustandAl(['operationState' => 'WORKING']) === RoboHubModell::ZUSTAND_REINIGT
    && $zustandAl(['operationState' => 'IDLE_BASE_STATION']) === RoboHubModell::ZUSTAND_GELADEN && $zustandAl(['operationState' => 'IDLE']) === RoboHubModell::ZUSTAND_PAUSIERT);
check('HOMING mit Grund aus situationFlags', $zustandAl(['operationState' => 'HOMING', 'situationFlags' => ['homingTriggerRain' => true]]) === RoboHubModell::ZUSTAND_ZURUECK
    && variable(10050, 'ZustandRoh')['value'] === 'Fährt zur Station (Regen) (HOMING)');
check('Regen verbietet Mähen in der Station = Wetterpause', $zustandAl(['operationState' => 'IDLE_BASE_STATION', 'situationFlags' => ['rainDetected' => true, 'rainAllowsMowing' => false]]) === RoboHubModell::ZUSTAND_WETTERPAUSE
    && variable(10050, 'Regensensor')['value'] === true);
check('Fehlercode ≠ 999 = Fehler mit Beschreibung', $zustandAl(['operationState' => 'IDLE', 'operationError' => ['code' => 21, 'type' => 'ERROR', 'description' => 'LIFTED']]) === RoboHubModell::ZUSTAND_FEHLER
    && variable(10050, 'Fehler')['value'] === 'Fehler 21 (LIFTED)');
check('PIN-Sperre = Fehler mit Hinweis', $zustandAl(['operationState' => 'CHARGING', 'operationSubState' => 'LOCKED_PIN']) === RoboHubModell::ZUSTAND_FEHLER
    && strpos((string)variable(10050, 'Fehler')['value'], 'PIN-Sperre') === 0);
check('nicht verbunden = offline', $zustandAl(['operationState' => 'CHARGING', 'isConnected' => false]) === RoboHubModell::ZUSTAND_UNBEKANNT && variable(10050, 'Erreichbar')['value'] === false);

FakeAlko::$reported = ['isConnected' => true, 'operationState' => 'CHARGING', 'operationError' => ['code' => 999]];
$am->Aktualisieren();
FakeAlko::$anfragen = [];
$am->Starten();
$am->Anhalten();
$am->ZurStation();
$p = FakeAlko::patches();
check('Start/Pause/Station als desired operationState WORKING/IDLE/HOMING, mit Uhrzeit', array_column($p, 'operationState') === ['WORKING', 'IDLE', 'HOMING']
    && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d$/', (string)($p[0]['rtc'] ?? '')) === 1, json_encode($p));
FakeAlko::$anfragen = [];
$t = $am->Stoppen();
check('Stopp: ehrlicher Hinweis, kein Befehl', strpos($t, '„Stopp" gibt es beim Robolinho nicht') !== false && FakeAlko::patches() === [], $t);
FakeAlko::$reported = ['isConnected' => true, 'operationState' => 'CHARGING', 'operationSubState' => 'LOCKED_PIN', 'operationError' => ['code' => 999]];
$am->Aktualisieren();
FakeAlko::$anfragen = [];
$t = $am->Starten();
check('Start bei PIN-Sperre: nicht gesendet, Hinweis', FakeAlko::patches() === [] && strpos($t, 'per PIN gesperrt') !== false, $t);

FakeAlko::$gueltig = 'WEG';
$am->Aktualisieren();
check('401: angemeldet bleiben …', $ak->ReadAttributeString('Buendel') !== '');
FakeAlko::$gueltig = 'K' . FakeAlko::$runde;
$alt = json_decode($ak->ReadAttributeString('Buendel'), true);
$am->Aktualisieren();
$neu = json_decode($ak->ReadAttributeString('Buendel'), true);
check('… nächster Abruf erneuert mit Client-ID/Secret + Erneuerungsschlüssel', $neu['refresh_token'] !== $alt['refresh_token'] && $neu['clientSecret'] === 'GEHEIM-AL');
$GLOBALS['robohubTreiberFabrik'] = $fabrikAl;
