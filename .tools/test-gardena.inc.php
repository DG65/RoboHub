<?php
/**
 * GARDENA-Teil des Prüfstands (von test-robohub.php eingebunden).
 * Datenformat wie py-smart-gardena tests/fixtures.py (MIT).
 */

declare(strict_types=1);

class FakeGardena extends RoboHubGardena
{
    public static $anfragen = [];
    public static $mower = [];
    public static $common = [];

    public static function zaehle(string $teil): int
    {
        return count(array_filter(self::$anfragen, function ($a) use ($teil) { return strpos($a['methode'] . ' ' . $a['url'], $teil) !== false; }));
    }

    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        self::$anfragen[] = ['methode' => $methode, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if ($url === 'https://api.authentication.husqvarnagroup.dev/v1/oauth2/token') {
            parse_str((string)$body, $f);
            return ($f['client_secret'] ?? '') === 'GEHEIM'
                ? [200, json_encode(['access_token' => 'GT1', 'expires_in' => 86399])] : [400, '{"error":"invalid_client"}'];
        }
        $kopf = implode("\n", $headers);
        if (strpos($kopf, 'Authorization: Bearer GT1') === false || strpos($kopf, 'Authorization-Provider: husqvarna') === false || strpos($kopf, 'X-Api-Key: ' . $GLOBALS['gaSchluessel']) === false) {
            return [401, '{}'];
        }
        if ($methode === 'GET' && $url === 'https://api.smart.gardena.dev/v2/locations') {
            return [200, json_encode(['data' => [['id' => 'L1', 'type' => 'LOCATION', 'attributes' => ['name' => 'Mein Garten']]]])];
        }
        if ($methode === 'GET' && $url === 'https://api.smart.gardena.dev/v2/locations/L1') {
            $wert = function (array $a) { return array_map(function ($v) { return ['value' => $v]; }, $a); };
            return [200, json_encode(['data' => ['id' => 'L1', 'type' => 'LOCATION', 'relationships' => ['devices' => ['data' => [['id' => 'G1', 'type' => 'DEVICE'], ['id' => 'V1', 'type' => 'DEVICE']]]]],
                'included' => [
                    ['id' => 'G1', 'type' => 'DEVICE'],
                    ['id' => 'G1', 'type' => 'COMMON', 'attributes' => $wert(self::$common)],
                    ['id' => 'G1', 'type' => 'MOWER', 'attributes' => $wert(self::$mower)],
                    ['id' => 'V1', 'type' => 'COMMON', 'attributes' => $wert(['name' => 'Ventil', 'modelType' => 'GARDENA smart Water Control'])],
                    ['id' => 'V1:1', 'type' => 'VALVE', 'attributes' => $wert(['activity' => 'CLOSED'])],
                ]])];
        }
        if ($methode === 'PUT' && preg_match('#^https://api\.smart\.gardena\.dev/v2/command/(.+)$#', $url, $m)) {
            return $m[1] === 'G1' ? [202, ''] : [404, '{"errors":[{"title":"not found"}]}'];
        }
        return [404, '{}'];
    }
}

// ===========================================================================
abschnitt('GARDENA: Orte, Mäher, Befehle');
$GLOBALS['gaSchluessel'] = bin2hex(random_bytes(16));
$fabrikGa = $GLOBALS['robohubTreiberFabrik'];
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) use ($fabrikGa) {
    return $marke === 'gardena' ? new FakeGardena() : $fabrikGa($marke);
};
$gaDatei = sys_get_temp_dir() . '/robohub-gardena-' . substr(md5($GLOBALS['gaSchluessel']), 0, 12) . '.json';
FakeGardena::$common = ['name' => 'Sileno City', 'modelType' => 'GARDENA smart Mower', 'serial' => '00012345', 'batteryLevel' => 77, 'rfLinkState' => 'ONLINE'];
FakeGardena::$mower = ['activity' => 'OK_CUTTING', 'state' => 'OK', 'operatingHours' => 40, 'lastErrorCode' => 'NO_MESSAGE'];
check('Geräte aus „included" gebündelt, nur Mäher mit MOWER-Dienst', count(array_filter(RoboHubGardena::geraeteAus([
    ['id' => 'A', 'type' => 'COMMON', 'attributes' => ['name' => ['value' => 'x']]], ['id' => 'A', 'type' => 'MOWER', 'attributes' => ['state' => ['value' => 'OK']]],
    ['id' => 'B', 'type' => 'COMMON', 'attributes' => []], ['id' => 'B:1', 'type' => 'VALVE', 'attributes' => []],
]), function ($g) { return $g['dienst'] !== null; })) === 1);
$teil = RoboHubGardena::geraeteAus([['id' => 'A', 'type' => 'COMMON', 'attributes' => ['name' => ['value' => 'Mäher A']]], ['id' => 'A:1', 'type' => 'MOWER', 'attributes' => []]]);
check('Dienst „A:1" gehört zu Gerät „A" (Dienst-ID bleibt vollständig)', count($teil) === 1 && $teil[0]['id'] === 'A' && $teil[0]['dienst'] === 'A:1' && $teil[0]['common']['name'] === 'Mäher A');
$gk = new RoboHubKonto(9000);
$gk->props['Hersteller'] = 'gardena';
$gk->Create();
$gk->ApplyChanges();
$f = json_encode(json_decode($gk->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
check('Formular: GARDENA smart system API in der Anleitung, 15-Minuten-Hinweis', strpos($f, 'GARDENA smart system API') !== false && strpos($f, 'höchstens alle 15 Minuten') !== false);
$gk->props['Konto'] = $GLOBALS['gaSchluessel'];
$gk->props['Passwort'] = 'FALSCH';
check('Ablehnung nennt die GARDENA-API', strpos($gk->Anmelden(), '„GARDENA smart system API"') !== false);
$gk->props['Passwort'] = 'GEHEIM';
$t = $gk->Anmelden();
$gg = json_decode($gk->ReadAttributeString('Geraete'), true);
check('angemeldet; ein Mäher (Ventil ausgelassen) mit Namen und Modell', strpos($t, '✅ Angemeldet') === 0 && count($gg) === 1 && $gg[0]['name'] === 'Sileno City' && $gg[0]['modell'] === 'gardena.mower.mower' && $gg[0]['klasse'] === 'maeher', $t);
check('Bündel als GARDENA gekennzeichnet', json_decode($gk->ReadAttributeString('Buendel'), true)['marke'] === 'gardena');

$gm = new RoboHubGeraet(9100);
$gm->props = ['GeraetID' => 'G1', 'Modell' => 'gardena.mower.mower', 'Klasse' => 'maeher', 'Intervall' => 120];
$gm->Create();
$gm->parent = $gk;
$gm->ApplyChanges();
$vor = FakeGardena::zaehle('GET https://api.smart.gardena.dev/v2/locations');
$gm->Aktualisieren();
$gm->Aktualisieren();
check('Abruf zwischengespeichert (Limit)', FakeGardena::zaehle('GET https://api.smart.gardena.dev/v2/locations') - $vor <= 2);
check('mäht, Akku 77, Betriebsstunden 40, kein Fehler', variable(9100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_REINIGT && variable(9100, 'Akku')['value'] === 77
    && variable(9100, 'GesamtDauer')['value'] === 40 && variable(9100, 'Fehler')['value'] === 'kein Fehler');
FakeGardena::$mower = ['activity' => 'PARKED_AUTOTIMER', 'state' => 'OK', 'lastErrorCode' => 'NO_MESSAGE'];
@unlink($gaDatei);
$gm->Aktualisieren();
check('SensorControl-Parken = Wetterpause', variable(9100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_WETTERPAUSE);
FakeGardena::$mower = ['activity' => 'NONE', 'state' => 'ERROR', 'lastErrorCode' => 'TRAPPED'];
@unlink($gaDatei);
$gm->Aktualisieren();
check('Fehler TRAPPED = Eingeklemmt', variable(9100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_FEHLER && variable(9100, 'Fehler')['value'] === 'Eingeklemmt (TRAPPED)');

$befehleGa = function (): array {
    $aus = [];
    foreach (FakeGardena::$anfragen as $a) {
        if ($a['methode'] === 'PUT') {
            $d = json_decode((string)$a['body'], true)['data'];
            $aus[] = [$d['type'], $d['attributes'], preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $d['id']) === 1, basename($a['url'])];
        }
    }
    return $aus;
};
FakeGardena::$anfragen = [];
$gm->Starten();
$gm->ZurStation();
$gm->Stoppen();
check('Start 3 h, Parken bis Zeitplan, Parken bis auf Weiteres — an den MOWER-Dienst, mit UUID', $befehleGa() === [
    ['MOWER_CONTROL', ['command' => 'START_SECONDS_TO_OVERRIDE', 'seconds' => 10800], true, 'G1'],
    ['MOWER_CONTROL', ['command' => 'PARK_UNTIL_NEXT_TASK'], true, 'G1'],
    ['MOWER_CONTROL', ['command' => 'PARK_UNTIL_FURTHER_NOTICE'], true, 'G1'],
], json_encode($befehleGa()));
FakeGardena::$anfragen = [];
$gm->Aktualisieren();
check('nach einem Befehl holt der nächste Abruf frisch', FakeGardena::zaehle('GET https://api.smart.gardena.dev/v2/locations') >= 1);
FakeGardena::$mower = ['activity' => 'PAUSED', 'state' => 'OK'];
@unlink($gaDatei);
$gm->Aktualisieren();
FakeGardena::$anfragen = [];
$gm->Starten();
check('pausiert → Start = START_DONT_OVERRIDE', ($befehleGa()[0][1] ?? null) === ['command' => 'START_DONT_OVERRIDE']);
$t = $gm->Anhalten();
check('Pause: ehrlicher Hinweis (gibt es bei GARDENA nicht)', strpos($t, 'kennt die GARDENA-Schnittstelle nicht') !== false, $t);
@unlink($gaDatei);
$GLOBALS['robohubTreiberFabrik'] = $fabrikGa;
