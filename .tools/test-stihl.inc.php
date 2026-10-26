<?php
/**
 * STIHL-iMOW-Teil des Prüfstands (von test-robohub.php eingebunden).
 * Neue Generation nach ha-imow-v2 (MIT), RMI nach stihl-imow-webapi (MIT).
 */

declare(strict_types=1);

class FakeStihl extends RoboHubStihl
{
    public static $anfragen = [];
    public static $gen5Konto = ['neu@example.com' => 'Gras5!'];
    public static $rmiKonto = ['alt@example.com' => 'Rasen4!'];
    public static $maeher5 = [];
    public static $maeherRmi = [];
    public static $statusRmi = [];
    public static $challenge = '';
    public static $state = '';
    public static $gueltig5 = 'S1';
    public static $refresh5 = 'SR1';
    public static $runde = 1;
    public static $gueltigRmi = 'RMI-TOKEN';
    public static $fehlertexteAbrufe = 0;

    public static function befehle(): array
    {
        return array_values(array_filter(self::$anfragen, function ($a) { return $a['methode'] === 'POST' && (strpos($a['url'], 'mower-commands') !== false || strpos($a['url'], 'mower-actions') !== false); }));
    }

    private static function kekse(array $headers): string
    {
        foreach ($headers as $h) {
            if (stripos($h, 'Cookie: ') === 0) {
                return substr($h, 8);
            }
        }
        return '';
    }

    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        self::$anfragen[] = ['methode' => $methode, 'url' => $url, 'headers' => $headers, 'body' => (string)$body];
        $kopf = implode("\n", $headers);
        $b2c = 'https://login.stihl.com/stihlidproduction.onmicrosoft.com/';
        // --- STIHL-ID (B2C) ---
        if (strpos($url, $b2c . 'b2c_1a_production_flow_signin/oauth2/v2.0/authorize?') === 0) {
            parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
            self::$challenge = (string)$q['code_challenge'];
            self::$state = (string)$q['state'];
            return [200, "<html><script>var SETTINGS = {\"transId\":\"StateProperties=TX1\",\"csrf\":\"CSRF1\",\"x\":1};\n</script></html>", ['HTTP/2 200', 'set-cookie: x-ms-cpim-trans=T1; path=/; secure', 'Set-Cookie: x-ms-cpim-csrf=C1; path=/']];
        }
        if (strpos($url, $b2c . 'B2C_1A_production_Flow_SignIn/SelfAsserted?tx=StateProperties%3DTX1&p=B2C_1A_production_Flow_SignIn') === 0) {
            parse_str((string)$body, $f);
            if (strpos($kopf, 'X-CSRF-TOKEN: CSRF1') === false || strpos(self::kekse($headers), 'x-ms-cpim-trans=T1') === false) {
                return [400, '{"status":"400","message":"Sitzung fehlt"}', []];
            }
            $ok = (self::$gen5Konto[$f['signInName'] ?? ''] ?? null) === ($f['password'] ?? '');
            return [200, $ok ? '{"status":"200"}' : '{"status":"400","message":"Ihr Kennwort ist falsch."}', $ok ? ['Set-Cookie: x-ms-cpim-sso=S1; path=/'] : []];
        }
        if (strpos($url, $b2c . 'B2C_1A_production_Flow_SignIn/api/CombinedSigninAndSignup/confirmed?') === 0) {
            if (strpos(self::kekse($headers), 'x-ms-cpim-sso=S1') === false) {
                return [200, '<html>Anmeldung</html>', []];
            }
            return [302, '', ['HTTP/2 302', 'Location: imow://www.imow.com/welcome/login?state=' . self::$state . '&code=AUTHCODE']];
        }
        if ($url === $b2c . 'b2c_1a_production_flow_signin/oauth2/v2.0/token') {
            parse_str((string)$body, $f);
            $pkce = rtrim(strtr(base64_encode(hash('sha256', (string)($f['code_verifier'] ?? ''), true)), '+/', '-_'), '=') === self::$challenge;
            $ok = ($f['grant_type'] === 'authorization_code' && ($f['code'] ?? '') === 'AUTHCODE' && $pkce && ($f['redirect_uri'] ?? '') === 'imow://www.imow.com/welcome/login')
                || ($f['grant_type'] === 'refresh_token' && ($f['refresh_token'] ?? '') === self::$refresh5);
            if (!$ok || ($f['client_id'] ?? '') !== '0d947284-c186-454e-96fd-0094f4510b3f') {
                return [400, '{"error":"invalid_grant"}', []];
            }
            self::$runde++;
            self::$gueltig5 = 'S' . self::$runde;
            self::$refresh5 = 'SR' . self::$runde;
            return [200, json_encode(['access_token' => self::$gueltig5, 'refresh_token' => self::$refresh5, 'expires_in' => 3600]), []];
        }
        if ($url === 'https://myimow.stihl.com/app-i18n-messages/de-DE.json') {
            self::$fehlertexteAbrufe++;
            return [200, json_encode(['dB_errorDetails_headline_dtc_065668' => [['type' => 0, 'value' => 'iMOW® ist angehoben']], 'dB_errorDetails_description_dtc_065668' => 'x', 'anderes' => 'y'], JSON_UNESCAPED_UNICODE), []];
        }
        if (strpos($url, 'https://apim.stihl.cloud/imow/p/') === 0) {
            if (strpos($kopf, 'Ocp-Apim-Subscription-Key: 52659909060946fc88f2d3368c16d9c7') === false || strpos($kopf, 'Authorization: Bearer ' . self::$gueltig5) === false) {
                return [401, '', []];
            }
            if ($methode === 'GET' && $url === 'https://apim.stihl.cloud/imow/p/mowertwin/api/v1/mowers') {
                return [200, json_encode(['mowers' => self::$maeher5]), []];
            }
            if ($methode === 'POST' && preg_match('#mowerctrl/api/v1/mower-commands/(\w+)/([a-z-]+)$#', $url, $m)) {
                return $m[1] === 'D500' ? [202, '', []] : [404, '{}', []];
            }
            return [404, '{}', []];
        }
        // --- RMI ---
        if (strpos($url, 'https://oauth2.imow.stihl.com/authentication/?lang=de_DE&authorizationRedirectUrl=') === 0) {
            return [200, '<form><input type="hidden" name="csrf-token" value="RMICSRF"><input name="requestId" type="hidden" value="REQ1"></form>', ['Set-Cookie: imowsess=A1; path=/']];
        }
        if ($url === 'https://oauth2.imow.stihl.com/authentication/authenticate/?lang=de') {
            parse_str((string)$body, $f);
            $ok = ($f['csrf-token'] ?? '') === 'RMICSRF' && ($f['requestId'] ?? '') === 'REQ1' && strpos(self::kekse($headers), 'imowsess=A1') !== false
                && (self::$rmiKonto[$f['mail'] ?? ''] ?? null) === ($f['password'] ?? '');
            return $ok ? [302, '', ['Location: https://oauth2.imow.stihl.com/authorization/?response_type=token&weiter=1', 'Set-Cookie: imowauth=B2; path=/']]
                : [200, '<html>Anmeldung fehlgeschlagen</html>', []];
        }
        if (strpos($url, 'https://oauth2.imow.stihl.com/authorization/?response_type=token&weiter=1') === 0) {
            return strpos(self::kekse($headers), 'imowauth=B2') !== false
                ? [302, '', ['Location: https://app.imow.stihl.com/#/authorize#access_token=' . self::$gueltigRmi . '&token_type=Bearer&expires_in=2592000&state=x']]
                : [200, 'kein Cookie', []];
        }
        if (strpos($url, 'https://api.imow.stihl.com/') === 0) {
            if (strpos($kopf, 'Authorization: Bearer ' . self::$gueltigRmi) === false) {
                return [401, '', []];
            }
            if ($url === 'https://api.imow.stihl.com/mowers/') {
                return [200, json_encode(self::$maeherRmi), []];
            }
            if ($url === 'https://api.imow.stihl.com/mowers/77/') {
                return [200, json_encode(['status' => self::$statusRmi] + self::$maeherRmi[0]), []];
            }
            if ($url === 'https://api.imow.stihl.com/mowers/77/statistic/') {
                return [200, json_encode(['totalOperatingTime' => 3600000, 'totalDistanceTravelled' => 812345, 'totalBladeOperatingTime' => 1800000]), []];
            }
            if ($methode === 'POST' && $url === 'https://api.imow.stihl.com/mower-actions/') {
                return [201, '{}', []];
            }
        }
        return [404, '{}', []];
    }
}

// ===========================================================================
abschnitt('STIHL iMOW: neue Generation (Gen. 5+)');
$fabrikSt = $GLOBALS['robohubTreiberFabrik'];
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) use ($fabrikSt) {
    return $marke === 'stihl' ? new FakeStihl() : $fabrikSt($marke);
};
@unlink(sys_get_temp_dir() . '/robohub-stihl-fehlertexte.json');
FakeStihl::$maeher5 = [[
    'deviceId' => 'D500', 'device' => ['type' => 'iMOW 6 EVO', 'overallState' => ['state' => 'mowing'], 'isDocked' => false, 'errorsActive' => []],
    'battery' => ['level' => 0.83], 'setting' => ['deviceNickname' => 'Stihli', 'defaultManualMowingDuration' => 5400,
        'mowingZones' => [['mowingZoneId' => 0, 'name' => 'Vorgarten'], ['mowingZoneId' => 1, 'name' => '']]],
    'statistics' => ['totalWorkingSeconds' => 1080000, 'totalDrivenDistance' => 412345, 'totalWorkingTimeOfCurrentCuttingKnifes' => 180000, 'totalStartedMowingJobs' => 412],
]];
$sk = new RoboHubKonto(9910);
$sk->props['Hersteller'] = 'stihl';
$sk->Create();
$sk->ApplyChanges();
$sk->props['Konto'] = 'neu@example.com';
$sk->props['Passwort'] = 'falsch';
$t = $sk->Anmelden();
check('falsches Passwort: beide Generationen versucht, verständlich abgelehnt', strpos($t, 'neue iMOW-Generation: E-Mail oder Passwort falsch') !== false
    && strpos($t, 'ältere iMOW: E-Mail oder Passwort falsch') !== false && $sk->ReadAttributeString('Buendel') === '', $t);
FakeStihl::$anfragen = [];
$sk->props['Passwort'] = 'Gras5!';
$t = $sk->Anmelden();
$sb = json_decode($sk->ReadAttributeString('Buendel'), true);
$sg = json_decode($sk->ReadAttributeString('Geraete'), true);
check('angemeldet (STIHL-ID mit PKCE, Cookies, CSRF), Passwort NICHT im Bündel, ein Mäher', strpos($t, '✅ Angemeldet (neue iMOW-Generation)') === 0
    && array_keys($sb) === ['gen', 'access_token', 'refresh_token', 'expires_at'] && $sb['gen'] === '5' && strpos(json_encode($sb), 'Gras5') === false
    && count($sg) === 1 && $sg[0]['id'] === 'D500' && $sg[0]['name'] === 'Stihli' && $sg[0]['modell'] === 'stihl.mower.imow6evo', $t);
check('… keine RMI-Anmeldung versucht (Mäher der neuen Generation gefunden)', count(array_filter(FakeStihl::$anfragen, function ($a) { return strpos($a['url'], 'oauth2.imow.stihl.com') !== false; })) === 0);

$sm = new RoboHubGeraet(9920);
$sm->props = ['GeraetID' => 'D500', 'Modell' => 'stihl.mower.imow6evo', 'Klasse' => 'maeher', 'Intervall' => 120];
$sm->Create();
$sm->parent = $sk;
$sm->ApplyChanges();
$sm->Aktualisieren();
check('mäht, Akku 83 (0,83), 300 h, 412,3 km, Messer 50 h, 412 Aufträge', variable(9920, 'Zustand')['value'] === RoboHubModell::ZUSTAND_REINIGT
    && variable(9920, 'Akku')['value'] === 83 && variable(9920, 'GesamtDauer')['value'] === 300 && variable(9920, 'GesamtStrecke')['value'] === 412.3
    && variable(9920, 'Messerlaufzeit')['value'] === 50.0 && variable(9920, 'GesamtAnzahl')['value'] === 412 && variable(9920, 'Fehler')['value'] === 'kein Fehler');
$sm->RaeumeLaden();
check('Zonen aus setting.mowingZones, leerer Name → „Zone 1"', json_decode($sm->ReadAttributeString('Raeume'), true) == [0 => 'Vorgarten', 1 => 'Zone 1']);
$zustandSt = function (array $device) use ($sm): int {
    FakeStihl::$maeher5[0]['device'] = $device + ['errorsActive' => []];
    $sm->Aktualisieren();
    return variable(9920, 'Zustand')['value'];
};
check('Gruppen wie die myimow-App: docked/charging/drivingHome/parkedInSideArea', $zustandSt(['overallState' => ['state' => 'docked']]) === RoboHubModell::ZUSTAND_GELADEN
    && $zustandSt(['overallState' => ['state' => 'chargingBatteryWithJob']]) === RoboHubModell::ZUSTAND_LAEDT
    && $zustandSt(['overallState' => ['state' => 'drivingHomeBattery']]) === RoboHubModell::ZUSTAND_ZURUECK
    && $zustandSt(['overallState' => ['state' => 'parkedInSideArea']]) === RoboHubModell::ZUSTAND_PAUSIERT);
check('Regen in der Station = Wetterpause, kurz beschäftigt = Lage aus isDocked', $zustandSt(['overallState' => ['state' => 'dockedRainWithJob']]) === RoboHubModell::ZUSTAND_WETTERPAUSE
    && $zustandSt(['overallState' => ['state' => 'internalWorkInProgress'], 'isDocked' => true]) === RoboHubModell::ZUSTAND_GELADEN
    && $zustandSt(['overallState' => ['state' => 'internalWorkInProgress'], 'isDocked' => false]) === RoboHubModell::ZUSTAND_REINIGT);
$vorTexte = FakeStihl::$fehlertexteAbrufe;
FakeStihl::$maeher5[0]['device'] = ['overallState' => ['state' => 'error'], 'errorsActive' => [['code' => '65668', 'activeSince' => '2026-10-26T10:00:00']]];
$sm->Aktualisieren();
$sm->Aktualisieren();
check('Fehler mit STIHLs Text (ICU-Teile), Datei nur einmal geholt', variable(9920, 'Zustand')['value'] === RoboHubModell::ZUSTAND_FEHLER
    && variable(9920, 'Fehler')['value'] === 'iMOW® ist angehoben (65668)' && FakeStihl::$fehlertexteAbrufe - $vorTexte === 1, (string)variable(9920, 'Fehler')['value']);

$befehleSt = function (): array {
    return array_map(function ($a) { return [basename($a['url']), $a['body'] === '' ? null : json_decode($a['body'], true)]; }, FakeStihl::befehle());
};
FakeStihl::$maeher5[0]['device'] = ['overallState' => ['state' => 'docked'], 'errorsActive' => []];
$sm->Aktualisieren();
FakeStihl::$anfragen = [];
$sm->Starten();
$sm->Anhalten();
$sm->ZurStation();
$sm->RaeumeReinigen('1');
check('Start (Vorgabedauer des Mähers 5400 s), Pause, Zur Station, Zone 1', $befehleSt() === [
    ['start-mowing', ['durationInSeconds' => 5400]], ['pause', null], ['end-job-and-return-to-dock', null], ['start-mowing', ['durationInSeconds' => 5400, 'mowingZoneId' => 1]],
], json_encode($befehleSt()));
FakeStihl::$anfragen = [];
$sm->RaeumeReinigen('0');
$sm->RequestAction('Raum', 0);
$sm->RequestAction('Raeume', '0');
check('Zone 0 ist gültig — Skript, Variable und Kachel (früher von array_filter verworfen, betraf auch den Husqvarna-Hauptbereich)',
    array_column($befehleSt(), 1) === array_fill(0, 3, ['durationInSeconds' => 5400, 'mowingZoneId' => 0]), json_encode($befehleSt()));
FakeStihl::$maeher5[0]['device'] = ['overallState' => ['state' => 'pausedOrIdle'], 'errorsActive' => []];
$sm->Aktualisieren();
FakeStihl::$anfragen = [];
$sm->Starten();
check('pausiert → Start = resume', $befehleSt() === [['resume', null]], json_encode($befehleSt()));
FakeStihl::$anfragen = [];
$t1 = $sm->Stoppen();
$t2 = $sm->RaeumeReinigen('0,1');
check('Stopp und zwei Zonen: ehrliche Hinweise, kein Befehl', strpos($t1, '„Stopp" gibt es bei der iMOW nicht') !== false && strpos($t2, 'genau eine Zone') !== false && FakeStihl::befehle() === [], $t1 . ' | ' . $t2);

FakeStihl::$gueltig5 = 'WEG';
$sm->Aktualisieren();
check('401: angemeldet bleiben …', $sk->ReadAttributeString('Buendel') !== '');
$sb = json_decode($sk->ReadAttributeString('Buendel'), true);
FakeStihl::$gueltig5 = 'S' . FakeStihl::$runde;
$sm->Aktualisieren();
$sb2 = json_decode($sk->ReadAttributeString('Buendel'), true);
check('… nächster Abruf erneuert mit dem Erneuerungsschlüssel', $sb2['refresh_token'] !== $sb['refresh_token'] && $sb2['gen'] === '5' && variable(9920, 'Zustand')['value'] === RoboHubModell::ZUSTAND_PAUSIERT);

// ===========================================================================
abschnitt('STIHL iMOW: ältere RMI-Serie');
FakeStihl::$maeherRmi = [['id' => '77', 'externalId' => 'ABCDEF0123456789', 'name' => 'Rasenmax', 'deviceTypeDescription' => 'iMOW 422 PC', 'firmwareVersion' => '01.03.07',
    'status' => ['online' => true]]];
FakeStihl::$statusRmi = ['mainState' => 5, 'extraStatus' => 0, 'chargeLevel' => 64, 'online' => true, 'rainStatus' => false];
$rk = new RoboHubKonto(9930);
$rk->props['Hersteller'] = 'stihl';
$rk->Create();
$rk->ApplyChanges();
$rk->props['Konto'] = 'alt@example.com';
$rk->props['Passwort'] = 'Rasen4!';
$t = $rk->Anmelden();
$rb = json_decode($rk->ReadAttributeString('Buendel'), true);
$rg = json_decode($rk->ReadAttributeString('Geraete'), true);
check('STIHL-ID lehnt ab → RMI-Anmeldung (Formular, Cookies, Weiterleitungen), Schlüssel aus dem Fragment', strpos($t, 'ältere iMOW-Generation') !== false && strpos($t, 'speichert dein Passwort bewusst nicht') !== false
    && ($rb['gen'] ?? '') === 'rmi' && ($rb['access_token'] ?? '') === 'RMI-TOKEN' && abs(($rb['expires_at'] ?? 0) - time() - 2592000) < 10 && strpos(json_encode($rb), 'Rasen4') === false
    && count($rg) === 1 && $rg[0]['extern'] === 'ABCDEF0123456789' && $rg[0]['modell'] === 'stihl.mower.imow422pc', $t);
$rm = new RoboHubGeraet(9940);
$rm->props = ['GeraetID' => '77', 'Modell' => 'stihl.mower.imow422pc', 'Klasse' => 'maeher', 'Intervall' => 120];
$rm->Create();
$rm->parent = $rk;
$rm->ApplyChanges();
$rm->Aktualisieren();
check('mäht, Akku 64, 1000 h, 812,3 km, Messer 500 h, Firmware, kein Regen', variable(9940, 'Zustand')['value'] === RoboHubModell::ZUSTAND_REINIGT && variable(9940, 'Akku')['value'] === 64
    && variable(9940, 'GesamtDauer')['value'] === 1000 && variable(9940, 'GesamtStrecke')['value'] === 812.3 && variable(9940, 'Messerlaufzeit')['value'] === 500.0
    && variable(9940, 'Firmware')['value'] === '01.03.07' && variable(9940, 'Regensensor')['value'] === false && variable(9940, 'Meldung') === null);
FakeStihl::$statusRmi = ['mainState' => 1, 'extraStatus' => 144, 'chargeLevel' => 50, 'online' => true];
$rm->Aktualisieren();
check('Fehler über extraStatus mit Text der Tabelle (144 = STOP-Taste)', variable(9940, 'Zustand')['value'] === RoboHubModell::ZUSTAND_FEHLER
    && variable(9940, 'Fehler')['value'] === 'Stopptaster wurde betätigt (M4027)', (string)variable(9940, 'Fehler')['value']);
FakeStihl::$statusRmi = ['mainState' => 6, 'chargeLevel' => 100, 'online' => false];
$rm->Aktualisieren();
check('offline schlägt den Zustand', variable(9940, 'Zustand')['value'] === RoboHubModell::ZUSTAND_UNBEKANNT);
FakeStihl::$statusRmi = ['mainState' => 6, 'chargeLevel' => 100, 'online' => true];
FakeStihl::$anfragen = [];
$rm->Starten();
$rm->ZurStation();
$t = $rm->Anhalten();
$aktionen = array_map(function ($a) { return json_decode($a['body'], true); }, FakeStihl::befehle());
check('Start = startMowing „<extId>,<Ende in 2 h>", Zur Station = toDocking, Pause ehrlich abgelehnt', count($aktionen) === 2
    && $aktionen[0]['actionName'] === 'startMowing' && preg_match('/^ABCDEF0123456789,\d{4}-\d\d-\d\d \d\d:\d\d$/', $aktionen[0]['actionValue']) === 1
    && $aktionen[1] === ['actionName' => 'toDocking', 'actionValue' => 'ABCDEF0123456789'] && strpos($t, 'nur „Start" und „Zur Station"') !== false, json_encode($aktionen) . ' ' . $t);
// 30-Tage-Zugang: Warnung drei Tage vorher, danach Anmeldung nötig (Dietmars Entscheidung: Passwort nicht speichern).
$rb['expires_at'] = time() + 2 * 86400;
$rk->WriteAttributeString('Buendel', json_encode($rb));
$rm->Aktualisieren();
check('zwei Tage vor Ablauf: Hinweis in „Letzte Meldung"', strpos((string)(variable(9940, 'Meldung')['value'] ?? ''), 'STIHL-Anmeldung läuft am ' . date('d.m.Y', $rb['expires_at']) . ' ab') === 0);
$rb['expires_at'] = time() - 60;
$rk->WriteAttributeString('Buendel', json_encode($rb));
$rm->Aktualisieren();
check('abgelaufen: Anmeldung nötig (Status 201), keine Anfrage mit altem Schlüssel', $rk->ReadAttributeString('Buendel') === '' && $rk->status === 201);
$GLOBALS['robohubTreiberFabrik'] = $fabrikSt;
@unlink(sys_get_temp_dir() . '/robohub-stihl-fehlertexte.json');
