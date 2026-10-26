<?php
/**
 * Navimow-Teil des Prüfstands (von test-robohub.php eingebunden).
 * Datenformat wie home-assistant-navimow tests/conftest.py (MIT).
 */

declare(strict_types=1);

class FakeNavimow extends RoboHubNavimow
{
    public static $anfragen = [];
    public static $status = [];
    public static $befehlStatus = 'SUCCESS';
    public static $befehlFehler = '';
    public static $abgelehnt = 0;   // so viele API-Anfragen mit code 4005 beantworten
    public static $gueltig = 'A1';
    public static $refresh = 'R1';
    public static $runde = 1;

    public static function zaehle(string $teil): int
    {
        return count(array_filter(self::$anfragen, function ($a) use ($teil) { return strpos($a['methode'] . ' ' . $a['url'] . ' ' . $a['body'], $teil) !== false; }));
    }

    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        self::$anfragen[] = ['methode' => $methode, 'url' => $url, 'headers' => $headers, 'body' => (string)$body];
        if ($url === 'https://navimow-fra.ninebot.com/openapi/oauth/getAccessToken') {
            parse_str((string)$body, $f);
            if (($f['client_id'] ?? '') !== 'homeassistant' || ($f['client_secret'] ?? '') !== '57056e15-722e-42be-bbaa-b0cbfb208a52') {
                return [400, '{"error":"invalid_client"}'];
            }
            $ok = ($f['grant_type'] === 'authorization_code' && ($f['code'] ?? '') === 'GUTERCODE' && ($f['redirect_uri'] ?? '') === 'http://localhost:1/callback')
                || ($f['grant_type'] === 'refresh_token' && ($f['refresh_token'] ?? '') === self::$refresh);
            if (!$ok) {
                return [400, '{"code":4003,"desc":"CODE_OAUTH_INFO_ILLEGAL","refresh_token":"GEHEIM-ECHO"}'];
            }
            self::$runde++;
            self::$gueltig = 'A' . self::$runde;
            self::$refresh = 'R' . self::$runde;
            return [200, json_encode(['access_token' => self::$gueltig, 'refresh_token' => self::$refresh, 'expires_in' => 3600])];
        }
        $kopf = implode("\n", $headers);
        if (strpos($kopf, 'Authorization: Bearer ' . self::$gueltig) === false || !preg_match('/^requestId: [0-9a-f]{32}$/m', $kopf)) {
            return [401, ''];
        }
        if (self::$abgelehnt > 0) {
            self::$abgelehnt--;
            return [200, '{"code":4005,"desc":"TOKEN_EXPIRED"}'];
        }
        $ok = function (array $payload) { return [200, json_encode(['code' => 1, 'data' => ['payload' => $payload]])]; };
        if ($methode === 'GET' && $url === 'https://navimow-fra.ninebot.com/openapi/smarthome/authList') {
            return $ok(['devices' => [['id' => 'dev1', 'name' => 'Mein Navimow', 'model' => 'i105E', 'firmware' => '0064'], ['name' => 'ohne ID']]]);
        }
        if ($methode === 'POST' && $url === 'https://navimow-fra.ninebot.com/openapi/smarthome/getVehicleStatus') {
            $ids = array_column((array)(json_decode((string)$body, true)['devices'] ?? []), 'id');
            return $ok(['devices' => $ids === ['dev1'] ? [['id' => 'dev1'] + self::$status] : []]);
        }
        if ($methode === 'POST' && $url === 'https://navimow-fra.ninebot.com/openapi/smarthome/sendCommands') {
            return $ok(['commands' => [['ids' => ['dev1'], 'status' => self::$befehlStatus] + (self::$befehlFehler !== '' ? ['errorCode' => self::$befehlFehler] : [])]]);
        }
        return [404, '{}'];
    }
}

// ===========================================================================
abschnitt('Navimow: Anmeldung, Zustand, Befehle');
$fabrikNm = $GLOBALS['robohubTreiberFabrik'];
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) use ($fabrikNm) {
    return $marke === 'navimow' ? new FakeNavimow() : $fabrikNm($marke);
};
check('Code aus der ganzen Weiterleitungsadresse oder pur', RoboHubNavimow::codeAus(' http://localhost:1/callback?code=ABC123&state=x ') === 'ABC123'
    && RoboHubNavimow::codeAus('ABC123') === 'ABC123' && RoboHubNavimow::codeAus('http://localhost:1/callback?state=x') === '');
$link = (new RoboHubNavimow())->anmeldeLink();
parse_str((string)parse_url($link, PHP_URL_QUERY), $lq);
check('Anmeldelink: Segway-Seite, Kennung „homeassistant", Weiterleitung localhost:1, zufälliger state',
    strpos($link, 'https://navimow-h5-fra.willand.com/smartHome/login?') === 0 && $lq['client_id'] === 'homeassistant' && $lq['channel'] === 'homeassistant'
    && $lq['response_type'] === 'code' && $lq['redirect_uri'] === 'http://localhost:1/callback' && strlen($lq['state']) === 16, $link);

$nk = new RoboHubKonto(9500);
$nk->props['Hersteller'] = 'navimow';
$nk->Create();
$nk->ApplyChanges();
$f = json_decode($nk->GetConfigurationForm(), true);
$fText = json_encode($f, JSON_UNESCAPED_UNICODE);
$knopf = null;
$feldKonto = null;
$suche = function ($knoten) use (&$suche, &$knopf, &$feldKonto) {
    if (!is_array($knoten)) {
        return;
    }
    if (($knoten['caption'] ?? '') === '🔗 Anmeldeseite öffnen') {
        $knopf = $knoten;
    }
    if (($knoten['name'] ?? '') === 'Konto') {
        $feldKonto = $knoten;
    }
    foreach ($knoten as $k) {
        $suche($k);
    }
};
$suche($f);
check('Formular: Link-Knopf zur Anmeldeseite, E-Mail-Feld ausgeblendet, Kennung „homeassistant" offen erklärt',
    ($knopf['link'] ?? false) === true && ($knopf['visible'] ?? false) === true && $knopf['onClick'] === 'echo ROBOHUB_Anmeldelink($id);'
    && ($feldKonto['visible'] ?? true) === false && strpos($fText, 'veröffentlichte Smart-Home-Kennung „homeassistant') !== false, json_encode([$knopf, $feldKonto], JSON_UNESCAPED_UNICODE));
check('ROBOHUB_Anmeldelink liefert die Segway-Seite', strpos($nk->Anmeldelink(), 'https://navimow-h5-fra.willand.com/smartHome/login?') === 0);
$t = $nk->Anmelden();
check('ohne Code: Hinweis auf die Anmeldeseite, keine Anfrage', strpos($t, '🔗 Anmeldeseite öffnen') !== false && FakeNavimow::$anfragen === [], $t);
$nk->props['Passwort'] = 'http://localhost:1/callback?code=FALSCH&state=1';
$t = $nk->Anmelden();
check('falscher Code: verständliche Ablehnung, ohne Echo der Antwort', strpos($t, 'lehnt den Code ab (CODE_OAUTH_INFO_ILLEGAL)') !== false && strpos($t, 'GEHEIM-ECHO') === false
    && $nk->ReadAttributeString('Buendel') === '', $t);
$nk->props['Passwort'] = 'http://localhost:1/callback?code=GUTERCODE&state=1';
$t = $nk->Anmelden();
$nb = json_decode($nk->ReadAttributeString('Buendel'), true);
$ng = json_decode($nk->ReadAttributeString('Geraete'), true);
check('angemeldet ohne E-Mail; Bündel ohne Code/Geheimnis; ein Mäher (Eintrag ohne ID ausgelassen)', strpos($t, '✅ Angemeldet') === 0
    && array_keys($nb) === ['access_token', 'refresh_token', 'expires_at'] && $nb['refresh_token'] === 'R2'
    && count($ng) === 1 && $ng[0]['name'] === 'Mein Navimow' && $ng[0]['modell'] === 'navimow.mower.i105e' && $ng[0]['klasse'] === 'maeher', $t);
check('Code-Feld nach der Anmeldung geleert', $nk->props['Passwort'] === '');

$nm = new RoboHubGeraet(9600);
$nm->props = ['GeraetID' => 'dev1', 'Modell' => 'navimow.mower.i105e', 'Klasse' => 'maeher', 'Intervall' => 60];
$nm->Create();
$nm->parent = $nk;
$nm->ApplyChanges();
FakeNavimow::$status = ['vehicleState' => 'isDocked', 'online' => true, 'capacityRemaining' => [['rawValue' => 80]]];
$nm->Aktualisieren();
check('in der Station, Akku 80 aus capacityRemaining, kein Fehler', variable(9600, 'Zustand')['value'] === RoboHubModell::ZUSTAND_GELADEN
    && variable(9600, 'Akku')['value'] === 80 && variable(9600, 'Fehler')['value'] === 'kein Fehler' && variable(9600, 'ZustandRoh')['value'] === 'In der Station (isDocked)');
FakeNavimow::$status = ['vehicleState' => 'isLifted', 'battery' => 55, 'capacityRemaining' => [['rawValue' => 80]]];
$nm->Aktualisieren();
check('angehoben = Fehler „Angehoben"; „battery" geht vor capacityRemaining', variable(9600, 'Zustand')['value'] === RoboHubModell::ZUSTAND_FEHLER
    && variable(9600, 'Fehler')['value'] === 'Angehoben' && variable(9600, 'Akku')['value'] === 55);
FakeNavimow::$status = ['vehicleState' => 'isIdel', 'capacityRemaining' => [['rawValue' => 70]]];
$nm->Aktualisieren();
check('Tippfehler-Zustand „isIdel" der Cloud = Bereit', variable(9600, 'Zustand')['value'] === RoboHubModell::ZUSTAND_BEREIT);

$befehleNm = function (): array {
    $aus = [];
    foreach (FakeNavimow::$anfragen as $a) {
        if (strpos($a['url'], 'sendCommands') !== false) {
            $c = json_decode($a['body'], true)['commands'][0];
            $aus[] = [$c['devices'], $c['execution']];
        }
    }
    return $aus;
};
FakeNavimow::$anfragen = [];
$nm->Starten();
$nm->Anhalten();
$nm->ZurStation();
$nm->Stoppen();
$d = [['id' => 'dev1']];
check('Start, Pause, Zur Station, Stopp wie ioBroker/HA', $befehleNm() === [
    [$d, ['command' => 'action.devices.commands.StartStop', 'params' => ['on' => true]]],
    [$d, ['command' => 'action.devices.commands.PauseUnpause', 'params' => ['on' => false]]],
    [$d, ['command' => 'action.devices.commands.Dock']],
    [$d, ['command' => 'action.devices.commands.StartStop', 'params' => ['on' => false]]],
], json_encode($befehleNm()));
FakeNavimow::$status = ['vehicleState' => 'isPaused', 'capacityRemaining' => [['rawValue' => 70]]];
$nm->Aktualisieren();
FakeNavimow::$anfragen = [];
$nm->Starten();
check('pausiert → Start = Fortsetzen (PauseUnpause an)', ($befehleNm()[0][1] ?? null) === ['command' => 'action.devices.commands.PauseUnpause', 'params' => ['on' => true]], json_encode($befehleNm()));
FakeNavimow::$befehlStatus = 'ERROR';
FakeNavimow::$befehlFehler = 'deviceOffline';
$t = $nm->ZurStation();
check('vom Mäher abgelehnter Befehl wird gemeldet, nicht als Erfolg', strpos($t, 'abgelehnt (ERROR, deviceOffline)') !== false, $t);
FakeNavimow::$befehlFehler = 'alreadyInState';
$t = $nm->ZurStation();
check('„alreadyInState" gilt als Erfolg (ioBroker)', strpos($t, 'abgelehnt') === false, $t);
FakeNavimow::$befehlStatus = 'SUCCESS';
FakeNavimow::$befehlFehler = '';
FakeNavimow::$anfragen = [];
$t = $nm->Orten();
check('Orten: ehrlicher Hinweis, keine Anfrage', strpos($t, 'bietet Segways Smart-Home-Schnittstelle nicht') !== false && $befehleNm() === [], $t);

// Erneuern: rotierender Schlüssel, vorzeitige Ablehnung ohne Abmelden.
$nb = json_decode($nk->ReadAttributeString('Buendel'), true);
$nb['expires_at'] = time() + 100;
$nk->WriteAttributeString('Buendel', json_encode($nb));
$nm->Aktualisieren();
$nb = json_decode($nk->ReadAttributeString('Buendel'), true);
check('kurz vor Ablauf erneuert; neuer Erneuerungsschlüssel gespeichert', $nb['access_token'] === 'A3' && $nb['refresh_token'] === 'R3' && $nb['expires_at'] > time() + 3000);
FakeNavimow::$abgelehnt = 1;
FakeNavimow::$anfragen = [];
$nm->Aktualisieren();
check('Ablehnung (4005) mitten in der Laufzeit: angemeldet bleiben, keine Erneuerung im selben Zug', $nk->ReadAttributeString('Buendel') !== '' && FakeNavimow::zaehle('getAccessToken') === 0);
$nm->Aktualisieren();
$nb = json_decode($nk->ReadAttributeString('Buendel'), true);
check('… nächster Abruf erneuert sofort und läuft wieder', $nb['refresh_token'] === 'R4' && FakeNavimow::zaehle('getAccessToken') === 1
    && variable(9600, 'Zustand')['value'] === RoboHubModell::ZUSTAND_PAUSIERT);
FakeNavimow::$refresh = 'ANDERS';
$nb['expires_at'] = 0;
$nk->WriteAttributeString('Buendel', json_encode($nb));
$nm->Aktualisieren();
check('abgelehnte Erneuerung → Anmeldung nötig (Status 201)', $nk->ReadAttributeString('Buendel') === '' && $nk->status === 201, (string)$nk->status);
check('Navimow: Echtzeit-Socket bleibt geschlossen', strpos(json_encode(json_decode($nk->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE), 'Für Navimow gibt es noch keine Echtzeit') !== false);
$GLOBALS['robohubTreiberFabrik'] = $fabrikNm;
