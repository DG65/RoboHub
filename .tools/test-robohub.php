<?php
/**
 * test-robohub.php — Prüfstand für RoboHub OHNE laufendes Symcon und OHNE Netz.
 *
 * Simuliert IPSModule (Properties/Attribute/Variablen/Parent-Kind-Datenfluss)
 * und die Dreame-Cloud (Antworten wie in ioBroker.dreame/Tasshack belegt).
 *
 * Aufruf:   php .tools/test-robohub.php
 * Rückgabe: 0 = alles bestanden, 1 = mindestens ein Fehlschlag
 */

declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');

const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const IM_CHANGESTATUS = 10505;
const IS_ACTIVE = 102;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_STRING = 3;
const VARIABLE_PRESENTATION_VALUE_PRESENTATION = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
const VARIABLE_PRESENTATION_ENUMERATION = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';

$GLOBALS['OBJ'] = [];      // id => [ident, parent, type, value, name, pres, action]
$GLOBALS['NEXT'] = 60000;
$GLOBALS['LOG'] = [];
$GLOBALS['INST'] = [];     // InstanceID => Modulobjekt

function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_GetObjectIDByIdent(string $ident, int $parent) {
    foreach ($GLOBALS['OBJ'] as $id => $o) { if ($o['ident'] === $ident && $o['parent'] === $parent) { return $id; } }
    return false;
}
function IPS_SemaphoreEnter(string $n, int $ms): bool { return true; }
function IPS_SemaphoreLeave(string $n): bool { return true; }
function IPS_LogMessage(string $s, string $m): bool { $GLOBALS['LOG'][] = "$s: $m"; return true; }
function IPS_GetLibrary(string $guid): array { $l = json_decode(file_get_contents(dirname(__DIR__) . '/library.json'), true); return ['Version' => $l['version'] . '-beta.1', 'Build' => $l['build']]; }
function IPS_SetProperty(int $id, string $n, $v): bool { $GLOBALS['INST'][$id]->props[$n] = $v; return true; }
function IPS_ApplyChanges(int $id): bool { $GLOBALS['INST'][$id]->ApplyChanges(); return true; }
function IPS_GetInstanceListByModuleID(string $g): array
{
    $klasse = ['{DB269BEC-0CA6-4E6A-83B8-78FF8CFB2DB0}' => 'RoboHubKonto', '{631DA85B-5985-4C25-B222-E47FDD49E302}' => 'RoboHubGeraet', '{5AA59E01-AFBF-4669-8899-57F5E2C24C33}' => 'RoboHubKonfigurator'][$g] ?? '';
    $o = [];
    foreach ($GLOBALS['INST'] as $id => $m) { if ($klasse !== '' && get_class($m) === $klasse) { $o[] = $id; } }
    return $o;
}
function IPS_ConnectInstance(int $id, int $parent): bool { $GLOBALS['CONNECTS'][] = [$id, $parent]; $GLOBALS['INST'][$id]->parent = $GLOBALS['INST'][$parent]; return true; }
function IPS_DisconnectInstance(int $id): bool { $GLOBALS['INST'][$id]->parent = null; return true; }
function ROBOHUB_GeraeteListe(int $id): string { return $GLOBALS['INST'][$id]->GeraeteListe(); }
function ROBOHUB_GeraeteSuchen(int $id): string { return $GLOBALS['INST'][$id]->GeraeteSuchen(); }
$GLOBALS['CONNECTS'] = [];
$GLOBALS['SKRIPTE'] = [];
function IPS_RunScriptText(string $s): bool { $GLOBALS['SKRIPTE'][] = $s; return true; }
function IPS_GetConfiguration(int $id): string { return json_encode($GLOBALS['INST'][$id]->config ?? []); }
/** Nachgebildeter Client Socket: merkt sich gesendete Bytes. */
class FakeSocket
{
    public $InstanceID;
    public $config = ['Open' => false];
    public $gesendet = [];
    public $parent = null;
    public function __construct(int $id) { $this->InstanceID = $id; $GLOBALS['INST'][$id] = $this; }
    public function ForwardData(string $json): string { $d = json_decode($json, true); $this->gesendet[] = mb_convert_encoding($d['Buffer'], 'ISO-8859-1', 'UTF-8'); return ''; }
}
function mqttVomServer($konto, string $bytes): void
{
    $konto->ReceiveData(json_encode(['DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1')]));
}
function GetValue(int $id) { return $GLOBALS['OBJ'][$id]['value']; }
function IPS_GetVariable(int $id): array { return ['VariableChanged' => $GLOBALS['OBJ'][$id]['changed'] ?? time()]; }
function IPS_GetProperty(int $id, string $n) { return $GLOBALS['INST'][$id]->props[$n]; }
function IPS_GetName(int $id): string { return 'Instanz ' . $id; }
function IPS_GetInstance(int $id): array { $m = $GLOBALS['INST'][$id] ?? null; return ['ConnectionID' => ($m && $m->parent) ? $m->parent->InstanceID : 0]; }

class IPSModule
{
    public $InstanceID;
    public $props = [];
    public $attrs = [];
    public $status = 0;
    public $timers = [];
    public $debug = [];
    public $fields = [];
    public $parent = null;   // Konto-Objekt für Kind-Instanzen

    public function __construct(int $id) { $this->InstanceID = $id; $GLOBALS['INST'][$id] = $this; }
    public function Create() {}
    public function ApplyChanges() {}
    public function RegisterPropertyString(string $n, string $d): void { $this->props[$n] = $this->props[$n] ?? $d; }
    public function RegisterPropertyInteger(string $n, int $d): void { $this->props[$n] = $this->props[$n] ?? $d; }
    public function RegisterPropertyBoolean(string $n, bool $d): void { $this->props[$n] = $this->props[$n] ?? $d; }
    public function ReadPropertyString(string $n): string { return (string)$this->props[$n]; }
    public function ReadPropertyInteger(string $n): int { return (int)$this->props[$n]; }
    public function ReadPropertyBoolean(string $n): bool { return (bool)$this->props[$n]; }
    public function RegisterAttributeString(string $n, string $d): void { $this->attrs[$n] = $this->attrs[$n] ?? $d; }
    public function RegisterAttributeInteger(string $n, int $d): void { $this->attrs[$n] = $this->attrs[$n] ?? $d; }
    public function RegisterAttributeBoolean(string $n, bool $d): void { $this->attrs[$n] = $this->attrs[$n] ?? $d; }
    public function ReadAttributeString(string $n): string { return (string)$this->attrs[$n]; }
    public function ReadAttributeInteger(string $n): int { return (int)$this->attrs[$n]; }
    public function ReadAttributeBoolean(string $n): bool { return (bool)$this->attrs[$n]; }
    public function WriteAttributeString(string $n, string $v): void { $this->attrs[$n] = $v; }
    public function WriteAttributeInteger(string $n, int $v): void { $this->attrs[$n] = $v; }
    public function WriteAttributeBoolean(string $n, bool $v): void { $this->attrs[$n] = $v; }
    public function SetStatus(int $s): void { $this->status = $s; }
    public function RegisterTimer(string $n, int $ms, string $s): void { $this->timers[$n] = $ms; }
    public function SetTimerInterval(string $n, int $ms): void { $this->timers[$n] = $ms; }
    public function RegisterMessage(int $s, int $m): void {}
    public function SendDebug(string $a, string $b, int $c): void { $this->debug[] = "$a: $b"; }
    public function UpdateFormField(string $n, string $p, $v): void { $this->fields[] = [$n, $p, $v]; }
    public function HasActiveParent(): bool { return $this->parent !== null; }
    public $puffer = [];
    public function RequireParent(string $guid): void { if ($this->parent === null) { $this->parent = new FakeSocket($this->InstanceID + 90000); } }
    public function SetBuffer(string $n, string $v): void { $this->puffer[$n] = $v; }
    public function GetBuffer(string $n): string { return $this->puffer[$n] ?? ''; }
    public function SendDataToChildren(string $json): void { foreach ($GLOBALS['INST'] as $m) { if ($m instanceof IPSModule && $m->parent === $this) { $m->ReceiveData($json); } } }
    public function UnregisterMessage(int $s, int $m): void {}
    public $visu = [];
    public $visuTyp = 0;
    public function SetVisualizationType(int $t): void { $this->visuTyp = $t; }
    public function UpdateVisualizationValue(string $v): void { $this->visu[] = $v; }
    public function SendDataToParent(string $json): string { return $this->parent->ForwardData($json); }
    public function MaintainVariable(string $ident, string $name, int $type, $pres, int $pos, bool $keep): void
    {
        $id = IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id === false) {
            $id = $GLOBALS['NEXT']++;
            $def = [false, 0, 0.0, ''][$type];
            $GLOBALS['OBJ'][$id] = ['ident' => $ident, 'parent' => $this->InstanceID, 'type' => $type, 'value' => $def, 'name' => $name, 'pres' => $pres, 'action' => false];
        }
        $GLOBALS['OBJ'][$id]['pres'] = $pres;
        $GLOBALS['OBJ'][$id]['name'] = $name;
    }
    public function EnableAction(string $ident): void { $GLOBALS['OBJ'][IPS_GetObjectIDByIdent($ident, $this->InstanceID)]['action'] = true; }
    public function SetValue(string $ident, $v): void { $id = IPS_GetObjectIDByIdent($ident, $this->InstanceID); if ($GLOBALS['OBJ'][$id]['value'] !== $v) { $GLOBALS['OBJ'][$id]['changed'] = time(); } $GLOBALS['OBJ'][$id]['value'] = $v; }
    public function GetValue(string $ident) { return $GLOBALS['OBJ'][IPS_GetObjectIDByIdent($ident, $this->InstanceID)]['value']; }
}

require dirname(__DIR__) . '/RoboHubKonto/module.php';
require dirname(__DIR__) . '/RoboHubGeraet/module.php';
require dirname(__DIR__) . '/RoboHubKonfigurator/module.php';

// ---------------------------------------------------------------------------
// Attrappe der Dreame-Cloud: ersetzt nur http(), der Rest ist echter Code.
// ---------------------------------------------------------------------------
class FakeCloud extends RoboHubDreame
{
    public static $anfragen = [];
    public static $props = [];          // 'siid.piid' => Wert, was get_properties liefert
    public static $ausfall = [];        // siid => true: Block liefert 80001
    public static $liste = [];          // Geräteliste des Dreame-Kontos
    public static $listeMova = [];      // Geräteliste des MOVA-Kontos
    public static $refreshStatus = 200;
    public static $cms = null;
    public static $karte = null;        // Kartentext (mapstr[0].map)
    public static $tokenZaehler = 0;
    public static $ablehnen = [];       // 'siid.aiid' => true: Aktion mit result.code -1 ablehnen

    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        self::$anfragen[] = ['methode' => $methode, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if (strpos($url, '/dreame-auth/oauth/token') !== false) {
            parse_str((string)$body, $f);
            if ($f['grant_type'] === 'refresh_token' && self::$refreshStatus !== 200) {
                return [self::$refreshStatus, '{"error":"invalid_grant","error_description":"Invalid refresh token"}'];
            }
            if ($f['grant_type'] === 'password' && $f['password'] !== md5('geheim' . 'RAylYC%fmSKp7%Tq')) {
                return [401, '{"error":"invalid_grant","error_description":"Bad credentials"}'];
            }
            self::$tokenZaehler++;
            return [200, json_encode(['access_token' => 'AT' . self::$tokenZaehler, 'refresh_token' => 'RT' . self::$tokenZaehler, 'expires_in' => 7200, 'uid' => 'U1', 'region' => 'eu', 'country' => 'DE', 'tenant_id' => '000000'])];
        }
        if (strpos($url, 'device/listV2') !== false) {
            $records = strpos($url, 'mova-tech') !== false ? self::$listeMova : self::$liste;
            return [200, json_encode(['code' => 0, 'success' => true, 'data' => ['page' => ['records' => $records]]])];
        }
        if (strpos($url, 'device/sendCommand') !== false) {
            $b = json_decode((string)$body, true);
            $m = $b['data']['method'];
            if ($m === 'get_properties') {
                $siid = $b['data']['params'][0]['siid'];
                if (!empty(self::$ausfall[$siid])) {
                    return [200, json_encode(['code' => 80001, 'msg' => '设备可能不在线'])];
                }
                $res = [];
                foreach ($b['data']['params'] as $p) {
                    $k = $p['siid'] . '.' . $p['piid'];
                    $res[] = array_key_exists($k, self::$props)
                        ? ['did' => $p['did'], 'siid' => $p['siid'], 'piid' => $p['piid'], 'code' => 0, 'value' => self::$props[$k]]
                        : ['did' => $p['did'], 'siid' => $p['siid'], 'piid' => $p['piid'], 'code' => -1];
                }
                return [200, json_encode(['code' => 0, 'data' => ['result' => $res]])];
            }
            if ($m === 'set_properties') {
                return [200, json_encode(['code' => 0, 'data' => ['result' => [['code' => 0]]]])];
            }
            if ($m === 'action') {
                $in = $b['data']['params']['in'][0] ?? null;
                if (is_array($in) && ($in['t'] ?? '') === 'CMS') {
                    return [200, json_encode(['code' => 0, 'data' => ['result' => ['code' => 0, 'out' => [self::$cms]]]])];
                }
                $k = $b['data']['params']['siid'] . '.' . $b['data']['params']['aiid'];
                return [200, json_encode(['code' => 0, 'data' => ['result' => ['code' => !empty(self::$ablehnen[$k]) ? -1 : 0, 'out' => []]]])];
            }
        }
        if (strpos($url, 'iotfile/getDownloadUrl') !== false) {
            return [200, json_encode(['code' => 0, 'data' => 'https://download.example/karte.json'])];
        }
        if (strpos($url, 'download.example') !== false) {
            return [200, json_encode(['curr_id' => 1, 'mapstr' => [['id' => 0, 'map' => self::$karte]]])];
        }
        return [404, 'unbekannt'];
    }

    public static function letzte(string $teil): ?array
    {
        foreach (array_reverse(self::$anfragen) as $a) { if (strpos($a['url'], $teil) !== false) { return $a; } }
        return null;
    }
    public static function befehle(): array
    {
        $o = [];
        foreach (self::$anfragen as $a) { if (strpos($a['url'], 'sendCommand') !== false) { $o[] = json_decode($a['body'], true)['data']; } }
        return $o;
    }
}
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) { return new FakeCloud($marke); };

/** Baut PUBLISH-Pakete wie ein MQTT-Server (unabhängig vom Code unter Test). */
final class RoboHubMqttTest
{
    public static function publish(string $topic, string $payload, int $qos, int $id): string
    {
        $rest = pack('n', strlen($topic)) . $topic . ($qos > 0 ? pack('n', $id) : '') . $payload;
        $len = strlen($rest);
        $enc = '';
        do { $b = $len % 128; $len = intdiv($len, 128); if ($len > 0) { $b |= 0x80; } $enc .= chr($b); } while ($len > 0);
        return chr(0x30 | ($qos << 1)) . $enc . $rest;
    }
}

$fails = 0;
function check(string $was, bool $ok, string $detail = ''): void
{
    global $fails;
    echo ($ok ? '  ✅ ' : '  ❌ ') . $was . ($ok || $detail === '' ? '' : ' — ' . $detail) . "\n";
    if (!$ok) { $fails++; }
}
function abschnitt(string $t): void { echo "\n[$t]\n"; }
function variable(int $inst, string $ident) { $id = IPS_GetObjectIDByIdent($ident, $inst); return $id === false ? null : $GLOBALS['OBJ'][$id]; }

$saugerRecord = ['did' => '-112663880', 'model' => 'dreame.vacuum.r95475', 'customName' => 'Aqua10', 'online' => true, 'latestStatus' => 13, 'battery' => 100, 'bindDomain' => '10000.mt.eu.iot.dreame.tech:19973', 'ver' => '4.3.9_1234',
    'deviceInfo' => ['mainImage' => ['imageUrl' => 'https://oss.iot.dreame.tech/pub/pic/000000/ali_dreame/dreame.vacuum.r95475/bild.png', 'smallImageUrl' => '']]];
$maeherRecord = ['did' => '777', 'model' => 'mova.mower.g2529c', 'customName' => '', 'deviceInfo' => ['displayName' => 'MOVA 600'], 'online' => true, 'battery' => 80];

// ===========================================================================
abschnitt('Konto: Anmeldung');
$konto = new RoboHubKonto(1000);
$konto->Create();
$konto->ApplyChanges();
check('ohne Anmeldung Status 104', $konto->status === 104);
$t = $konto->Anmelden();
check('ohne Zugangsdaten klare Meldung', strpos($t, '⛔') === 0);

$konto->props['Konto'] = 'nutzer@example.org';
$konto->props['Passwort'] = 'falsch';
$t = $konto->Anmelden();
check('falsches Passwort wird abgelehnt', strpos($t, '⛔ Anmeldung fehlgeschlagen') === 0, $t);
check('falsches Passwort: kein Schlüssel gespeichert', $konto->ReadAttributeString('Buendel') === '');

FakeCloud::$liste = [$saugerRecord, $maeherRecord];
$konto->props['Passwort'] = 'geheim';
$t = $konto->Anmelden();
check('Anmeldung erfolgreich', strpos($t, '✅ Angemeldet') === 0, $t);
check('Passwort-Property danach leer', $konto->props['Passwort'] === '');
$b = json_decode($konto->ReadAttributeString('Buendel'), true);
check('Bündel enthält Zugangs- und Erneuerungsschlüssel', ($b['access_token'] ?? '') === 'AT1' && ($b['refresh_token'] ?? '') === 'RT1');
check('Bündel enthält KEIN Passwort', strpos($konto->ReadAttributeString('Buendel'), 'geheim') === false && strpos($konto->ReadAttributeString('Buendel'), md5('geheimRAylYC%fmSKp7%Tq')) === false);
check('Status nach Anmeldung 102', $konto->status === 102);
check('Erneuerungs-Timer läuft', ($konto->timers['Erneuern'] ?? 0) > 0);
$login = FakeCloud::letzte('oauth/token');
parse_str($login['body'], $lf);
check('Passwort-Hash = md5(pw+Salt)', $lf['password'] === md5('geheimRAylYC%fmSKp7%Tq'));
check('Login an eu.iot.dreame.tech:13267', strpos($login['url'], 'https://eu.iot.dreame.tech:13267/dreame-auth/oauth/token') === 0);
$h = implode("\n", $login['headers']);
check('Header Tenant-Id 000000 + Basic-Auth Dreame', strpos($h, 'Tenant-Id: 000000') !== false && strpos($h, 'Basic ZHJlYW1lX2FwcHYxOkFQXmR2QHpAU1FZVnhOODg=') !== false);
$rlcErwartet = bin2hex(openssl_encrypt('eu|en|DE', 'aes-128-ecb', 'EETjszu*XI5znHsI', OPENSSL_RAW_DATA));
check('Dreame-Rlc = AES-128-ECB hex', strpos($h, 'Dreame-Rlc: ' . $rlcErwartet) !== false);
check('Geräte gleich mitgeladen', count(json_decode($konto->ReadAttributeString('Geraete'), true)) === 2);
check('Anmeldemeldung nennt beide Geräteklassen', strpos($t, 'Saug-/Wischroboter') !== false && strpos($t, 'Mähroboter') !== false, $t);
check('Protokoll ohne Passwort', strpos(implode("\n", $GLOBALS['LOG']), 'geheim') === false);

abschnitt('Konto: Schlüssel-Erneuerung');
$vorher = count(FakeCloud::$anfragen);
$konto->Erneuern();
check('frischer Schlüssel wird NICHT erneuert', FakeCloud::letzte('oauth/token') === $login && count(FakeCloud::$anfragen) === $vorher);
$b['expires_at'] = time() + 60;
$konto->WriteAttributeString('Buendel', json_encode($b));
$konto->Erneuern();
$nb = json_decode($konto->ReadAttributeString('Buendel'), true);
$rf = FakeCloud::letzte('oauth/token');
parse_str($rf['body'], $rff);
check('kurz vor Ablauf: Erneuerung per refresh_token', $rff['grant_type'] === 'refresh_token' && $rff['refresh_token'] === 'RT1');
check('rotierender Erneuerungsschlüssel übernommen', $nb['refresh_token'] === 'RT2' && $nb['access_token'] === 'AT2');

abschnitt('Konfigurator');
$cfg = new RoboHubKonfigurator(3000);
$cfg->Create();
$cfg->ApplyChanges();
$form = json_decode($cfg->GetConfigurationForm(), true);
check('Konfigurator-Formular ist gültiges JSON', is_array($form));
$liste = null;
array_walk_recursive($form, function () {});
foreach ($form['elements'] as $e) { foreach (($e['items'] ?? []) as $i) { if (($i['type'] ?? '') === 'Configurator') { $liste = $i; } } }
check('Konfigurator-Liste mit 2 Geräten', $liste !== null && count($liste['values']) === 2);
$m = $liste['values'][1] ?? [];
check('Mäher als Rasenmähroboter erkannt', ($m['klasse'] ?? '') === 'Rasenmähroboter' && ($m['create']['configuration']['Klasse'] ?? '') === 'maeher');
check('Anzeigename-Ersatz aus deviceInfo.displayName', ($m['name'] ?? '') === 'MOVA 600');
check('negative did bleibt String', ($liste['values'][0]['create']['configuration']['GeraetID'] ?? null) === '-112663880');
check('kein erfundener parent-Schlüssel in create', !isset($liste['values'][0]['create']['parent']));

abschnitt('Sauger r95475: Zustand ohne 2.1/3.1 im Poll');
$geraet = new RoboHubGeraet(2000);
$geraet->props = ['GeraetID' => '-112663880', 'Modell' => 'dreame.vacuum.r95475', 'Klasse' => 'sauger', 'Intervall' => 120];
$geraet->Create();
$geraet->parent = $konto;
$geraet->ApplyChanges();
check('Status 102 mit Geräte-ID', $geraet->status === 102);
FakeCloud::$props = [
    '2.2' => 0, '3.2' => 3, '4.2' => 35, '4.3' => 42, '4.4' => 1, '4.5' => 2, '4.23' => (5 << 16) | 0, '4.25' => 0,
    '9.2' => 88, '10.2' => 77, '11.1' => 66, '16.1' => 55, '18.1' => 44,
    '9.1' => 169, '10.1' => 69, '11.2' => 19, '16.2' => 0, '18.2' => 250,
    '35.2' => 40, '35.1' => 120, '26.1' => 75, '26.2' => 300,
    '27.1' => 0, '27.2' => 0, '27.3' => 2, '27.4' => 2, '28.1' => 20, '12.2' => 6000, '12.3' => 17, '12.4' => 950,
    '6.8' => '',
];
FakeCloud::$ausfall = [35 => false];
$t = $geraet->Aktualisieren();
check('Aktualisieren meldet Erfolg', strpos($t, '✅') === 0, $t);
check('Zustand aus latestStatus 13 → „In der Station"', variable(2000, 'Zustand')['value'] === RoboHubModell::ZUSTAND_GELADEN);
check('Rohzustand im Klartext', variable(2000, 'ZustandRoh')['value'] === 'Geladen (13)');
check('Akku aus battery der Geräteliste', variable(2000, 'Akku')['value'] === 100);
check('Fehler 0 → „kein Fehler"', variable(2000, 'Fehler')['value'] === 'kein Fehler');
check('Verbrauchsteile angelegt (Hauptbürste 88 %)', variable(2000, 'Hauptbuerste')['value'] === 88);
check('Filter-% von piid 1', variable(2000, 'Filter')['value'] === 66);
check('Reststunden Sauger (Haupt/Seite/Filter/Sensoren/Mopp)', variable(2000, 'HauptbuersteRest')['value'] === 169.0 && variable(2000, 'SeitenbuersteRest')['value'] === 69.0 && variable(2000, 'FilterRest')['value'] === 19.0 && variable(2000, 'SensorenRest')['value'] === 0.0 && variable(2000, 'MoppRest')['value'] === 250.0);
check('Sensoren 0 h bleibt 0 (kein Ausblenden echter Nullwerte)', variable(2000, 'SensorenRest') !== null);
check('Abwasserauslassfilter: 35.2 = %, 35.1 = h', variable(2000, 'Abwasserfilter')['value'] === 40 && variable(2000, 'AbwasserfilterRest')['value'] === 120.0);
check('Schmutzwasserbehälter: 26.1 = %, 26.2 = h', variable(2000, 'Schmutzwasserbehaelter')['value'] === 75 && variable(2000, 'SchmutzwasserbehaelterRest')['value'] === 300.0);
check('neuer Block hängt am Ende (Blocksperre-Indizes stabil)', (function () { $r = new ReflectionClass('RoboHubDreame'); $b = $r->getConstant('BLOECKE_SAUGER'); return end($b) === [[26, 1], [26, 2], [35, 1]] && $b[2][5] === [35, 2]; })());
check('Saugstufe als schaltbare Auswahl', variable(2000, 'Saugstufe')['action'] === true && variable(2000, 'Saugstufe')['value'] === 1);
check('Wassermenge entfällt bei Feuchte-Modell (28.1)', variable(2000, 'Wassermenge') === null);
check('Reinigungsmodus mit Mopp-Anhebung: Leitung 0 → Saugen und Wischen (2)', variable(2000, 'Reinigungsmodus')['value'] === 2);
check('Staubbeutel „Prüfen"', variable(2000, 'Staubbeutel')['value'] === 'Prüfen');
check('Gesamtdauer in Stunden', variable(2000, 'GesamtDauer')['value'] === 100);
check('keine Mäher-Variable beim Sauger', variable(2000, 'Messer') === null && variable(2000, 'Regensensor') === null);
check('Rohdaten gespeichert (inkl. Liste)', strpos($geraet->Rohdaten(), '"latestStatus": 13') !== false);
check('Erreichbar = true', variable(2000, 'Erreichbar')['value'] === true);

abschnitt('Sauger: Abfrage liefert 2.1 selbst (Poll gewinnt)');
FakeCloud::$props['2.1'] = 1;
FakeCloud::$props['3.1'] = 57;
$geraet->Aktualisieren();
check('Poll-Wert 2.1=1 gewinnt → Reinigt', variable(2000, 'Zustand')['value'] === RoboHubModell::ZUSTAND_REINIGT);
check('Poll-Akku 57 gewinnt', variable(2000, 'Akku')['value'] === 57);
check('während Reinigung schnellerer Takt (30 s)', $geraet->timers['Abruf'] === 30000);
unset(FakeCloud::$props['2.1'], FakeCloud::$props['3.1']);

abschnitt('Sauger: ausfallender Block (80001) wird toleriert');
FakeCloud::$ausfall = [27 => true];
$t = $geraet->Aktualisieren();
check('trotz Ausfall eines Blocks Erfolg', strpos($t, '✅') === 0, $t);
check('fehlender Block im Rohauszug gezählt', strpos($geraet->Rohdaten(), '"fehlendeBloecke": 1') !== false);
FakeCloud::$ausfall = [];

abschnitt('Sauger: Befehle');
FakeCloud::$anfragen = [];
$geraet->RequestAction('Befehl', RoboHubModell::BEFEHL_STATION);
$cmd = FakeCloud::befehle()[0] ?? [];
check('Zur Station = action 3.1', ($cmd['method'] ?? '') === 'action' && $cmd['params']['siid'] === 3 && $cmd['params']['aiid'] === 1);
FakeCloud::$anfragen = [];
$t = $geraet->Stoppen();
$cmd = FakeCloud::befehle()[0];
check('Stopp = action 4.2 und Klartext „gesendet"', $cmd['params']['siid'] === 4 && $cmd['params']['aiid'] === 2 && strpos($t, 'gesendet') !== false, $t);
check('nach Befehl schnelle Nachfrage (5 s)', $geraet->timers['Abruf'] === 5000);

FakeCloud::$anfragen = [];
$t = $geraet->RaeumeReinigen('3,5');
$befehle = FakeCloud::befehle();
$raum = end($befehle);
$sel = json_decode($raum['params']['in'][1]['value'], true)['selects'] ?? [];
check('Raumreinigung = action 4.1 mit piid1=18', $raum['params']['siid'] === 4 && $raum['params']['aiid'] === 1 && $raum['params']['in'][0] === ['piid' => 1, 'value' => 18]);
check('selects: Reihenfolge, Saugstufe/Wasser übernommen, 5. Feld = 1', $sel === [[3, 1, 1, 2, 1], [5, 1, 1, 2, 1]], json_encode($sel));
check('ohne Raum-ID klare Meldung', strpos($geraet->RaeumeReinigen(''), '⛔') === 0);

FakeCloud::$anfragen = [];
$geraet->RequestAction('Reinigungsmodus', 0); // Nutzer will „Saugen"
$set = null;
foreach (FakeCloud::befehle() as $c) { if ($c['method'] === 'set_properties') { $set = $c['params'][0]; } }
check('Reinigungsmodus: Read-Modify-Write, Feuchte-Bits erhalten, Tausch 0→2', $set !== null && $set['siid'] === 4 && $set['piid'] === 23 && $set['value'] === ((5 << 16) | 2), json_encode($set));
check('Variable zeigt neue Auswahl', variable(2000, 'Reinigungsmodus')['value'] === 0);

abschnitt('Karte → Räume');
$segs = ['3' => ['type' => 4, 'index' => 0], '5' => ['type' => 0, 'name' => base64_encode('Hobbykeller')], '7' => ['type' => 2, 'index' => 1]];
$bild = str_repeat("\0", 4 * 3);
$kopf = pack('v', 1) . "\0\0" . chr(73) . str_repeat("\0", 14) . pack('v', 4) . pack('v', 3) . "\0\0\0\0";
$roh = $kopf . $bild . json_encode(['seg_inf' => $segs]);
check('Testkopf hat 27 Byte', strlen($kopf) === 27);
$klar = strtr(base64_encode(gzcompress($roh)), '+/', '-_');
check('unverschlüsselte Karte dekodiert', (RoboHubDreame::dekodiereKarte($klar, 'dreame.vacuum.r95475')['seg_inf'] ?? null) == $segs);
$suffix = 'abc123';
$key = substr(hash('sha256', $suffix), 0, 32);
$enc = strtr(base64_encode(openssl_encrypt(gzcompress($roh), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, str_repeat("\0", 16))), '+/', '-_') . ',' . $suffix;
check('verschlüsselte Karte (leerer IV) dekodiert', (RoboHubDreame::dekodiereKarte($enc, 'dreame.vacuum.r95475')['seg_inf'] ?? null) == $segs);
$enc2 = base64_encode(openssl_encrypt(gzcompress($roh), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, 'NRwnBj5FsNPgBNbT')) . ',' . $suffix;
check('r2253 nutzt seinen eigenen IV', (RoboHubDreame::dekodiereKarte($enc2, 'dreame.vacuum.r2253c')['seg_inf'] ?? null) == $segs);
check('Müll ergibt null statt Absturz', RoboHubDreame::dekodiereKarte('%%%kaputt', 'x') === null);
check('Raumnamen: Typ, eigener Name, Index', RoboHubDreame::raeumeAusSegmenten($segs) === [3 => 'Küche', 5 => 'Hobbykeller', 7 => 'Schlafzimmer 2']);

FakeCloud::$props['6.8'] = json_encode(['object_name' => 'ali_dreame/123/map']);
FakeCloud::$karte = $klar;
$t = $geraet->RaeumeLaden();
check('Räume über Karte geladen', strpos($t, '✅ 3 Räume') === 0, $t);
$raumVar = variable(2000, 'Raum');
check('Variable „Raum reinigen" schaltbar mit 3 Optionen', $raumVar !== null && $raumVar['action'] === true && count(json_decode($raumVar['pres']['OPTIONS'], true)) === 3);
FakeCloud::$anfragen = [];
$geraet->RequestAction('Raum', 5);
$befehle = FakeCloud::befehle();
$raum = end($befehle);
check('Raum-Variable startet genau diesen Raum', json_decode($raum['params']['in'][1]['value'], true)['selects'][0][0] === 5);

abschnitt('Konfigurator mit angelegter Instanz');
FakeCloud::$liste = [$saugerRecord, ['did' => '2066117758', 'model' => 'dreame.vacuum.r9547a', 'customName' => 'Barny II', 'online' => true]];
$barny = new RoboHubGeraet(2300);
$barny->props = ['GeraetID' => '2066117758', 'Modell' => 'dreame.vacuum.r9547a', 'Klasse' => 'sauger', 'Intervall' => 120];
$barny->Create();
$barny->parent = $konto;
$alt = new RoboHubGeraet(2301);
$alt->props = ['GeraetID' => '999', 'Modell' => 'dreame.vacuum.x', 'Klasse' => 'sauger', 'Intervall' => 120];
$alt->Create();
$alt->parent = $konto;
$cfg->Suchen();
$werte = null;
foreach (array_reverse($cfg->fields) as $f) { if ($f[0] === 'Geraete' && $f[1] === 'values') { $werte = json_decode($f[2], true); break; } }
$ids = array_map(function ($w) { return (string)$w['id']; }, (array)$werte);
check('angelegtes Gerät mit Zahlen-ID erscheint genau einmal (Live-Fund Barny II)', count(array_keys($ids, '2066117758', true)) === 1, json_encode($ids));
$barnyZeile = null;
foreach ((array)$werte as $w) { if ((string)$w['id'] === '2066117758') { $barnyZeile = $w; } }
check('… und ist seiner Instanz zugeordnet', ($barnyZeile['instanceID'] ?? 0) === 2300);
check('wirklich verwaiste Instanz bleibt sichtbar', in_array('999', $ids, true));
$altZeile = null;
foreach ((array)$werte as $w) { if ((string)$w['id'] === '999') { $altZeile = $w; } }
check('verwaiste Zeile ohne create (Symcon färbt sie rot)', $altZeile !== null && !isset($altZeile['create']) && $altZeile['online'] === 'in keinem Konto gefunden');
unset($GLOBALS['INST'][2300], $GLOBALS['INST'][2301]);

abschnitt('Kachel und eigenständige Seite (IPSView)');
function kachelJson(string $html): ?array
{
    return preg_match('/handleMessage\((\{.*?\})\);/s', $html, $m) ? json_decode($m[1], true) : null;
}
function hook(RoboHubGeraet $g, array $get): string
{
    $_GET = $get;
    ob_start();
    $g->ProcessHookData();
    return (string)ob_get_clean();
}
check('Instanz ist eine Kachel (SetVisualizationType 1)', $geraet->visuTyp === 1);
$k = kachelJson($geraet->GetVisualizationTile());
check('Kachel liefert Daten an handleMessage()', is_array($k));
check('Kachel: Zustand im Klartext', ($k['zustandText'] ?? '') !== '' && $k['eigen'] === false);
$haupt = null;
foreach ($k['teile'] ?? [] as $t) { if ($t['name'] === 'Hauptbürste') { $haupt = $t; } }
check('Kachel: Verbrauchsteil mit % und Stunden', $haupt == ['name' => 'Hauptbürste', 'prozent' => 88, 'stunden' => 169], json_encode($haupt));
check('Kachel: Räume aus der Karte', count($k['raeume'] ?? []) === 3);
check('Kachel: kein Schlüssel in den Daten', ($k['key'] ?? 'x') === '');
check('Kachel: Produktbild aus der Geräteliste', ($k['bild'] ?? '') === 'https://oss.iot.dreame.tech/pub/pic/000000/ali_dreame/dreame.vacuum.r95475/bild.png');
check('Bild: nur https, kein Einschleusen von HTML', RoboHubDreame::bildVon(['deviceInfo' => ['mainImage' => ['imageUrl' => 'http://x/a.png']]]) === ''
    && RoboHubDreame::bildVon(['deviceInfo' => ['mainImage' => ['imageUrl' => 'https://x/a.png" onerror="alert(1)']]]) === ''
    && RoboHubDreame::bildVon(['deviceInfo' => ['mainImage' => ['imageUrl' => ''], 'icon' => ['imageUrl' => 'https://x/i.png']]]) === 'https://x/i.png');
check('Mäher ohne Bild in der Liste → kein Bild (Symbol als Ersatz)', RoboHubDreame::bildVon([]) === '');
check('Bild an anderer Stelle im Datensatz wird gefunden', RoboHubDreame::bildVon(['deviceInfo' => ['extendScType' => ['x'], 'imageUrl' => 'https://cdn/x/robot.png']]) === 'https://cdn/x/robot.png');
check('Nicht-Bild-Adressen werden ignoriert', RoboHubDreame::bildVon(['keyDefine' => ['url' => 'https://cdn/x/define.json']]) === '');
check('Rohdaten listen Felder und Bildpfade', strpos($geraet->Rohdaten(), '"bilder": {') !== false && strpos($geraet->Rohdaten(), '"felder": [') !== false);
check('Kachel: Station meldet Staubbeutel „Prüfen" als Hinweis', in_array('🗑️ Staubbeutel: Prüfen', $k['hinweise'] ?? [], true), json_encode($k['hinweise'] ?? []));

$geraet->visu = [];
FakeCloud::$anfragen = [];
$geraet->RequestAction('Raeume', '3,5');
$befehle = FakeCloud::befehle();
$raum = end($befehle);
check('Kachel-Raumauswahl „3,5" startet beide Räume', json_decode($raum['params']['in'][1]['value'], true)['selects'] === [[3, 1, 1, 2, 1], [5, 1, 1, 2, 1]]);
$letzte = json_decode((string)end($geraet->visu), true);
check('Kachel bekommt Rückmeldung „gesendet"', strpos((string)($letzte['rueck'] ?? ''), 'gesendet') !== false);

$seite = hook($geraet, []);
$d = kachelJson($seite);
check('Seite: HTML mit Daten und 15-s-Aktualisierung', $d !== null && strpos($seite, 'setInterval') !== false && strpos($seite, '15000') !== false);
check('Seite ohne Schlüssel: nur Anzeige', $d['eigen'] === true && $d['bedienung'] === false && $d['key'] === '');
$schluessel = $geraet->ReadAttributeString('WebSchluessel');
check('Schlüssel erzeugt (24 Hex-Zeichen)', (bool)preg_match('/^[0-9a-f]{24}$/', $schluessel));

FakeCloud::$anfragen = [];
$j = json_decode(hook($geraet, ['cmd' => 'Befehl', 'wert' => '5', 'key' => $schluessel]), true);
check('Bedienung aus (Standard): Befehl trotz richtigem Schlüssel abgelehnt', $j['ok'] === false && count(FakeCloud::befehle()) === 0);
$d = kachelJson(hook($geraet, ['key' => $schluessel]));
check('Bedienung aus: Seite mit Schlüssel bleibt Anzeige', $d['bedienung'] === false && $d['key'] === '');

$geraet->props['WebBedienung'] = true;
$j = json_decode(hook($geraet, ['cmd' => 'Befehl', 'wert' => '5', 'key' => 'falsch']), true);
check('falscher Schlüssel → abgelehnt, nichts gesendet', $j['ok'] === false && count(FakeCloud::befehle()) === 0);
$j = json_decode(hook($geraet, ['cmd' => 'Befehl', 'wert' => '5']), true);
check('kein Schlüssel → abgelehnt, nichts gesendet', $j['ok'] === false && count(FakeCloud::befehle()) === 0);
$j = json_decode(hook($geraet, ['cmd' => 'Befehl', 'wert' => '5', 'key' => $schluessel]), true);
$c = FakeCloud::befehle()[0] ?? [];
check('richtiger Schlüssel + Freigabe → Orten gesendet', $j['ok'] === true && ($c['params']['siid'] ?? 0) === 7 && ($c['params']['aiid'] ?? 0) === 1, json_encode($j, JSON_UNESCAPED_UNICODE));
FakeCloud::$anfragen = [];
$j = json_decode(hook($geraet, ['cmd' => 'Loeschen', 'wert' => '1', 'key' => $schluessel]), true);
check('unbekannter Befehl über die Seite abgelehnt', $j['ok'] === false && count(FakeCloud::befehle()) === 0);
$j = json_decode(hook($geraet, ['cmd' => 'Befehl', 'wert' => '99', 'key' => $schluessel]), true);
check('ungültiger Befehlswert → Fehlermeldung statt Absturz', $j['ok'] === false && count(FakeCloud::befehle()) === 0);
$d = kachelJson(hook($geraet, ['key' => $schluessel]));
check('Seite mit Schlüssel + Freigabe: bedienbar', $d['bedienung'] === true && $d['key'] === $schluessel);
$t = $geraet->NeuerWebSchluessel();
check('neuer Schlüssel: alter wird ungültig', $geraet->ReadAttributeString('WebSchluessel') !== $schluessel && json_decode(hook($geraet, ['cmd' => 'Befehl', 'wert' => '5', 'key' => $schluessel]), true)['ok'] === false);
$form = $geraet->GetConfigurationForm();
$formKlar = json_encode(json_decode($form, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
check('Formular: Panel „Kachel und IPSView" mit Hook-Adresse', strpos($formKlar, 'Kachel und IPSView') !== false && strpos($formKlar, '/hook/robohub2000') !== false);
$jd = json_decode(hook($geraet, ['json' => '1', 'key' => $geraet->ReadAttributeString('WebSchluessel')]), true);
check('Datenabfrage mit Schlüssel + Freigabe enthält Schlüssel', $jd['key'] === $geraet->ReadAttributeString('WebSchluessel'));
$geraet->props['WebBedienung'] = false;
$jd = json_decode(hook($geraet, ['json' => '1', 'key' => $geraet->ReadAttributeString('WebSchluessel')]), true);
check('Datenabfrage ohne Freigabe gibt den Schlüssel NICHT heraus', $jd['key'] === '' && $jd['bedienung'] === false);
$geraet->props['WebBedienung'] = true;
$gespeichert = $geraet->ReadAttributeString('WebSchluessel');
$geraet->WriteAttributeString('WebSchluessel', '');   // z. B. nach Attribut-Verlust beim Modul-Neuladen
FakeCloud::$anfragen = [];
$j = json_decode(hook($geraet, ['cmd' => 'Befehl', 'wert' => '5', 'key' => '']), true);
check('leerer gespeicherter Schlüssel + leerer Schlüssel → trotzdem abgelehnt', $j['ok'] === false && count(FakeCloud::befehle()) === 0);
$geraet->WriteAttributeString('WebSchluessel', $gespeichert);
$geraet->props['WebBedienung'] = false;
$_GET = [];

abschnitt('MOVA-Mäher');
$konto2 = new RoboHubKonto(1100);
$konto2->props = ['Hersteller' => 'mova', 'Region' => 'eu', 'Konto' => 'n@example.org', 'Passwort' => 'geheim'];
$konto2->Create();
$konto2->ApplyChanges();
FakeCloud::$listeMova = [$maeherRecord];
$konto2->Anmelden();
$login = FakeCloud::letzte('oauth/token');
$h = implode("\n", $login['headers']);
check('MOVA-Login an eu.iot.mova-tech.com', strpos($login['url'], 'https://eu.iot.mova-tech.com:13267/') === 0);
check('MOVA-Tenant 000002 + eigener Basic', strpos($h, 'Tenant-Id: 000002') !== false && strpos($h, 'bW92YV9hcHA6VjdLb0NoTFc4dkhBQ3FHYg==') !== false);

$maeher = new RoboHubGeraet(2100);
$maeher->props = ['GeraetID' => '777', 'Modell' => 'mova.mower.g2529c', 'Klasse' => 'maeher', 'Intervall' => 120];
$maeher->Create();
$maeher->parent = $konto2;
$maeher->ApplyChanges();
FakeCloud::$props = ['2.1' => 8, '2.2' => 0, '3.1' => 64, '3.2' => 0, '4.2' => 12, '4.3' => 150];
// Zahlen so gewählt, dass sie die App-Werte von Dietmars LiDAX ergeben (34 % / 87 % / 72 %).
FakeCloud::$cms = json_encode(['d' => ['value' => [3978, 3978, 1026]]]);
FakeCloud::$anfragen = [];
$t = $maeher->Aktualisieren();
check('Mäher-Abruf erfolgreich', strpos($t, '✅') === 0, $t);
check('Befehlspfad dreame-iot-com-20000', FakeCloud::letzte('sendCommand') !== null && strpos(FakeCloud::letzte('sendCommand')['url'], '/dreame-iot-com-20000/') !== false);
check('2.1=8 → Wetterpause', variable(2100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_WETTERPAUSE);
check('Regen erkannt = true', variable(2100, 'Regensensor')['value'] === true);
check('LiDAX: Code 0 = „Roboter angehoben" (Modell-Abweichung)', variable(2100, 'Fehler')['value'] === 'Roboter angehoben (0)', (string)variable(2100, 'Fehler')['value']);
// Minuten so gewählt, dass exakt die App-Werte von Dietmars LiDAX herauskommen.
check('Klingen: 34 % (wie App)', variable(2100, 'Messer')['value'] === 34);
check('Reinigungsbürste: 87 % (wie App)', variable(2100, 'Reinigungsbuerste')['value'] === 87);
check('Roboter-Wartung: 72 % (wie App)', variable(2100, 'Wartung')['value'] === 72);
check('Klingen Reststunden 33,7 h (wie App)', variable(2100, 'MesserRest')['value'] === 33.7);
check('Reinigungsbürste Reststunden 433,7 h (wie App)', variable(2100, 'ReinigungsbuersteRest')['value'] === 433.7);
check('Wartung in 42,9 h (wie App)', variable(2100, 'WartungRest')['value'] === 42.9);
check('Reststunden als Kommazahl mit Einheit h', variable(2100, 'MesserRest')['type'] === VARIABLETYPE_FLOAT && variable(2100, 'MesserRest')['pres']['SUFFIX'] === ' h');
check('keine Sauger-Variablen beim Mäher', variable(2100, 'Staubbeutel') === null && variable(2100, 'Saugstufe') === null);
$opts = json_decode(variable(2100, 'Zustand')['pres']['OPTIONS'], true);
$mahtCaption = '';
foreach ($opts as $o) { if ($o['Value'] === RoboHubModell::ZUSTAND_REINIGT) { $mahtCaption = $o['Caption']; } }
check('Zustandsbeschriftung beim Mäher „Mäht"', $mahtCaption === 'Mäht');
FakeCloud::$props['2.1'] = 3;
FakeCloud::$props['2.2'] = 56;
$maeher->Aktualisieren();
FakeCloud::$props['2.1'] = 19;
$maeher->Aktualisieren();
check('LiDAX-Livebefund: 2.1 = 19 → Mäht', variable(2100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_REINIGT && variable(2100, 'ZustandRoh')['value'] === 'Mäht (19)');
FakeCloud::$props['2.1'] = 3;
$maeher->Aktualisieren();
check('Mäher 3 = Pausiert (App-Beschriftung)', variable(2100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_PAUSIERT && variable(2100, 'ZustandRoh')['value'] === 'Pausiert (3)');
check('Info-Code 56 → Meldung, kein Fehler', variable(2100, 'Fehler')['value'] === 'kein Fehler' && variable(2100, 'Meldung')['value'] === 'Schlechtwetter-Schutz aktiv (56)');
FakeCloud::$props['2.1'] = 4;
FakeCloud::$props['2.2'] = 7;
$maeher->Aktualisieren();
check('Mäher 4 = Fehlerzustand, Code 7 Messerteller', variable(2100, 'Zustand')['value'] === RoboHubModell::ZUSTAND_FEHLER && variable(2100, 'Fehler')['value'] === 'Messerteller blockiert (7)');
FakeCloud::$props['2.2'] = 55;
$maeher->Aktualisieren();
check('LiDAX: 55 ist Fehler (Akku zu schwach zum Start)', strpos((string)variable(2100, 'Fehler')['value'], 'Start nicht möglich') === 0);
FakeCloud::$anfragen = [];
$maeher->ZurStation();
$c = FakeCloud::befehle()[0];
check('Mäher zur Station = action 5.3 (nicht 3.1)', $c['params']['siid'] === 5 && $c['params']['aiid'] === 3);
FakeCloud::$anfragen = [];
$maeher->Anhalten();
$c = FakeCloud::befehle()[0];
check('Mäher Pause zuerst über 5.4 (EvotecIT)', $c['params']['siid'] === 5 && $c['params']['aiid'] === 4 && count(FakeCloud::befehle()) === 1);
FakeCloud::$anfragen = [];
FakeCloud::$ablehnen = ['5.1' => true];
$t = $maeher->Starten();
$cs = FakeCloud::befehle();
check('Start abgelehnt über 5.1 → Ausweichweg 2.1, Ergebnis „gesendet"', count($cs) === 2 && $cs[1]['params']['siid'] === 2 && $cs[1]['params']['aiid'] === 1 && strpos($t, 'gesendet') !== false, $t);
FakeCloud::$ablehnen = ['5.1' => true, '2.1' => true];
$t = $maeher->Starten();
check('beide Wege abgelehnt → klare Fehlermeldung', strpos($t, '⛔') === 0 && strpos($t, 'abgelehnt') !== false, $t);
FakeCloud::$ablehnen = [];
FakeCloud::$anfragen = [];
$geraet->Orten();
$c = FakeCloud::befehle()[0];
check('Sauger orten = 7.1, ohne Ausweichweg', $c['params']['siid'] === 7 && $c['params']['aiid'] === 1 && count(FakeCloud::befehle()) === 1);
check('Raumreinigung beim Mäher abgelehnt', strpos($maeher->RaeumeReinigen('1'), '⛔') === 0);

abschnitt('Beschleunigung: unbeantwortete Blöcke überspringen');
function getPropsSiids(): array
{
    $o = [];
    foreach (FakeCloud::befehle() as $c) { if ($c['method'] === 'get_properties') { $o[] = $c['params'][0]['siid']; } }
    return $o;
}
FakeCloud::$props = ['2.1' => 13, '2.2' => 0, '3.1' => 100, '3.2' => 2];
FakeCloud::$ausfall = [4 => true];       // LiDAX-Livebefund: Block 4.x antwortet nicht
FakeCloud::$anfragen = [];
$maeher->Aktualisieren();
check('1. Abruf: Block 4 wird versucht (und scheitert)', in_array(4, getPropsSiids(), true));
$sperre = json_decode($konto2->ReadAttributeString('BlockSperre'), true);
check('… danach für dieses Gerät gesperrt (1 h)', isset($sperre['777'][1]) && $sperre['777'][1] > time() + 3500, json_encode($sperre));
FakeCloud::$anfragen = [];
$maeher->Aktualisieren();
check('2. Abruf: Block 4 wird übersprungen', !in_array(4, getPropsSiids(), true) && in_array(2, getPropsSiids(), true), json_encode(getPropsSiids()));
check('Rohdaten zeigen übersprungene Blöcke', strpos($maeher->Rohdaten(), '"uebersprungeneBloecke": [') !== false);
$sperre['777'][1] = time() - 1;           // Stunde abgelaufen
$konto2->WriteAttributeString('BlockSperre', json_encode($sperre));
FakeCloud::$ausfall = [];                 // Block antwortet inzwischen
FakeCloud::$anfragen = [];
$maeher->Aktualisieren();
check('nach Ablauf erneut versucht', in_array(4, getPropsSiids(), true));
check('beantworteter Block wird entsperrt', !isset(json_decode($konto2->ReadAttributeString('BlockSperre'), true)['777']));
FakeCloud::$ausfall = [2 => true, 4 => true];   // Gerät antwortet gar nicht
$maeher->Aktualisieren();
check('alles unbeantwortet → nichts gesperrt (Gerät war nur kurz weg)', json_decode($konto2->ReadAttributeString('BlockSperre'), true) === []);
FakeCloud::$ausfall = [];

abschnitt('Ein Konfigurator für alle Konten + Selbstzuordnung');
FakeCloud::$liste = [$saugerRecord];
$t = $cfg->Suchen();
check('Suchen fragt beide Konten ab', substr_count($t, "\n") === 1 && strpos($t, '#1000') !== false && strpos($t, '#1100') !== false, $t);
$werte = null;
foreach (array_reverse($cfg->fields) as $f) { if ($f[0] === 'Geraete' && $f[1] === 'values') { $werte = json_decode($f[2], true); break; } }
$proKonto = [];
foreach ((array)$werte as $w) { $proKonto[(string)$w['id']] = $w['konto'] ?? ''; }
check('Sauger und Mäher in EINER Liste', isset($proKonto['-112663880'], $proKonto['777']), json_encode($proKonto));
check('Spalte Konto nennt Hersteller + E-Mail', strpos($proKonto['-112663880'], 'Dreame (nutzer@example.org)') === 0 && strpos($proKonto['777'], 'MOVA (n@example.org)') === 0, json_encode($proKonto, JSON_UNESCAPED_UNICODE));
check('kein create-parent (Symcon verbindet, Roboter korrigiert)', !isset($werte[0]['create']['parent']));
$kz = '';
foreach (array_reverse($cfg->fields) as $f) { if ($f[0] === 'KontenZeile') { $kz = $f[2]; break; } }
check('Konten-Detailzeile mit 🔗 je angemeldetem Konto', substr_count($kz, '🔗') === 2, $kz);

$neu = new RoboHubGeraet(2400);
$neu->props = ['GeraetID' => '777', 'Modell' => 'mova.mower.g2529c', 'Klasse' => 'maeher', 'Intervall' => 120];
$neu->Create();
$GLOBALS['CONNECTS'] = [];
$neu->ApplyChanges();
check('neuer Mäher ohne Verbindung → verbindet sich selbst mit dem MOVA-Konto', $neu->parent === $konto2 && $GLOBALS['CONNECTS'] === [[2400, 1100]]);
$falsch = new RoboHubGeraet(2401);
$falsch->props = ['GeraetID' => '777', 'Modell' => 'mova.mower.g2529c', 'Klasse' => 'maeher', 'Intervall' => 120];
$falsch->Create();
$falsch->parent = $konto;   // Symcon hat ihn ans Dreame-Konto gehängt
$falsch->ApplyChanges();
check('falsch verbundener Mäher → wechselt zum MOVA-Konto', $falsch->parent === $konto2);
check('Wechsel dauerhaft protokolliert', strpos(implode("\n", $GLOBALS['LOG']), 'verbunden — dort ist dieses Gerät registriert') !== false);
$GLOBALS['CONNECTS'] = [];
$falsch->ApplyChanges();
check('richtig verbunden → keine weitere Umverbindung', $GLOBALS['CONNECTS'] === []);
$unbekannt = new RoboHubGeraet(2402);
$unbekannt->props = ['GeraetID' => '424242', 'Modell' => '', 'Klasse' => 'sauger', 'Intervall' => 120];
$unbekannt->Create();
$unbekannt->ApplyChanges();
check('unbekannte Geräte-ID bei zwei Konten → keine geratene Verbindung', $unbekannt->parent === null);
$f = json_decode($neu->GetConfigurationForm(), true);
check('Roboter-Formular zeigt verbundenes Konto (grün)', strpos(json_encode($f, JSON_UNESCAPED_UNICODE), '🔗 Konto: Instanz 1100 (#1100)') !== false);
$f = json_decode($unbekannt->GetConfigurationForm(), true);
check('Roboter ohne Konto: rote ⛔-Zeile', strpos(json_encode($f, JSON_UNESCAPED_UNICODE), '⛔ Kein Konto verbunden') !== false);
unset($GLOBALS['INST'][2400], $GLOBALS['INST'][2401], $GLOBALS['INST'][2402]);

abschnitt('Echtzeit (MQTT)');
$socket = $konto->parent;
check('Konto bekommt einen Client Socket (RequireParent)', $socket instanceof FakeSocket);
$sockCfg = json_decode($konto->GetConfigurationForParent(), true);
check('Socket-Ziel aus bindDomain der Geräteliste', $sockCfg['Host'] === '10000.mt.eu.iot.dreame.tech' && $sockCfg['Port'] === 19973, json_encode($sockCfg));
check('TLS mit voller Prüfung (Zertifikat + Name)', $sockCfg['UseSSL'] === true && $sockCfg['VerifyPeer'] === true && $sockCfg['VerifyHost'] === true);
check('eigene Wurzel hinterlegt (Fingerabdruck)', openssl_x509_fingerprint(openssl_x509_read(base64_decode($sockCfg['CertificateAuthority'])), 'sha256') === '6db9ea84c7e4c9aec692cd540ff52381f5e37dae47df8ebeb948a4461aeae425');
check('angemeldet + Echtzeit an → Socket offen', $sockCfg['Open'] === true);
$GLOBALS['SKRIPTE'] = [];
$konto->ApplyChanges();
$skript = end($GLOBALS['SKRIPTE']) ?: '';
check('ApplyChanges konfiguriert den Socket (per Skript)', strpos($skript, 'IPS_SetConfiguration(' . $socket->InstanceID) !== false && strpos($skript, '10000.mt.eu.iot.dreame.tech') !== false, $skript);
$socket->config = $sockCfg;   // Symcon hätte sie übernommen
$GLOBALS['SKRIPTE'] = [];
$konto->ApplyChanges();
check('unveränderte Einstellungen → kein erneutes Konfigurieren (keine Neuverbindungs-Schleife)', $GLOBALS['SKRIPTE'] === []);

$socket->gesendet = [];
$konto->MessageSink(0, $socket->InstanceID, IM_CHANGESTATUS, [IS_ACTIVE]);
$b = json_decode($konto->ReadAttributeString('Buendel'), true);
$connect = $socket->gesendet[0] ?? '';
check('Socket verbunden → CONNECT gesendet', $connect !== '' && ord($connect[0]) === 0x10);
$puf = $connect;
$pk = RoboHubMqtt::zerlegen($puf)[0] ?? ['body' => ''];
check('CONNECT: Benutzer = uid, Passwort = Zugangsschlüssel', strpos($pk['body'], pack('n', strlen($b['uid'])) . $b['uid'] . pack('n', strlen($b['access_token'])) . $b['access_token']) !== false);
check('Keepalive-Timer läuft (50 s)', ($konto->timers['MqttPing'] ?? 0) === 50000);

$socket->gesendet = [];
mqttVomServer($konto, "\x20\x02\x00\x00");
$sub = $socket->gesendet[0] ?? '';
check('CONNACK 0 → SUBSCRIBE je Gerät', $sub !== '' && ord($sub[0]) === 0x82 && strpos($sub, '/status/-112663880/' . $b['uid'] . '/dreame.vacuum.r95475/eu/') !== false, bin2hex(substr($sub, 0, 8)));
check('Formular: 🔗 verbunden', strpos(json_encode(json_decode($konto->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE), '🔗 Echtzeit verbunden') !== false);

// Sauger 2000: vollständiger Stand aus früheren Abrufen liegt vor. Push 2.1 = 1 (saugt) + Akku, in zwei Stücken.
$topic = '/status/-112663880/' . $b['uid'] . '/dreame.vacuum.r95475/eu/';
$nachricht = json_encode(['id' => 92, 'did' => '-112663880', 'data' => ['id' => 92, 'method' => 'properties_changed', 'params' => [
    ['did' => '-112663880', 'siid' => 2, 'piid' => 1, 'value' => 1], ['did' => '-112663880', 'siid' => 3, 'piid' => 1, 'value' => 77]]]]);
$publish = RoboHubMqttTest::publish($topic, $nachricht, 0, 0);
mqttVomServer($konto, substr($publish, 0, 10));
check('halbe Nachricht → noch nichts übernommen', variable(2000, 'Akku')['value'] !== 77);
mqttVomServer($konto, substr($publish, 10));
check('vollständige Nachricht → Zustand „Reinigt" sofort am Roboter', variable(2000, 'Zustand')['value'] === RoboHubModell::ZUSTAND_REINIGT);
check('… und Akku 77 %', variable(2000, 'Akku')['value'] === 77);
check('Roboter merkt sich die Echtzeit-Meldung', $geraet->ReadAttributeInteger('LetzteEchtzeit') >= time() - 2);
$rTakt = new ReflectionMethod('RoboHubGeraet', 'taktSekunden');
check('mit Echtzeit kein 30-s-Schnelltakt, obwohl der Roboter reinigt', $rTakt->invoke($geraet) === 120);
check('Kachel wurde sofort aktualisiert', strpos((string)end($geraet->visu), '"zustand":1') !== false);

// Vollständiger Stand mit Stationsdaten (4.25/27.3), wie ihn ein regulärer Abruf liefert.
FakeCloud::$props = ['2.1' => 1, '2.2' => 0, '3.1' => 77, '4.4' => 1, '4.23' => (5 << 16) | 2, '4.25' => 0, '27.3' => 0];
FakeCloud::$ausfall = [];
$konto->WriteAttributeString('BlockSperre', '{}');
$geraet->Aktualisieren();
// Nur der gepackte Reinigungsmodus kommt: Tausch muss aus dem gespeicherten Stand (4.25/27.3) richtig bleiben.
mqttVomServer($konto, RoboHubMqttTest::publish($topic, json_encode(['did' => '-112663880', 'data' => ['method' => 'properties_changed', 'params' => [['siid' => 4, 'piid' => 23, 'value' => (5 << 16) | 0]]]]), 0, 0));
check('Einzelwert 4.23 → Tausch bleibt korrekt (Leitung 0 = Saugen und Wischen)', variable(2000, 'Reinigungsmodus')['value'] === 2);

$vorher = count($geraet->visu);
mqttVomServer($konto, RoboHubMqttTest::publish($topic, json_encode(['did' => '-112663880', 'data' => ['method' => 'properties_changed', 'params' => [['siid' => 6, 'piid' => 1, 'value' => 'KARTE']]]]), 0, 0));
check('Kartendaten (6.x) werden übergangen', count($geraet->visu) === $vorher);
mqttVomServer($konto, RoboHubMqttTest::publish($topic, '{"kaputt', 0, 0));
check('abgeschnittene Nachricht (Server kürzt bei 4096 Byte) → still übergangen', count($geraet->visu) === $vorher);

$socket->gesendet = [];
mqttVomServer($konto, RoboHubMqttTest::publish($topic, json_encode(['did' => '-112663880', 'data' => ['method' => 'properties_changed', 'params' => [['siid' => 3, 'piid' => 1, 'value' => 76]]]]), 1, 4711));
check('QoS 1 → PUBACK mit Paket-ID', ($socket->gesendet[0] ?? '') === "\x40\x02" . pack('n', 4711));

$schnapp = json_decode($konto->ReadAttributeString('Schnappschuss'), true);
$merke = $schnapp;
unset($schnapp['-112663880']);
$konto->WriteAttributeString('Schnappschuss', json_encode($schnapp));
$akku = variable(2000, 'Akku')['value'];
mqttVomServer($konto, RoboHubMqttTest::publish($topic, json_encode(['did' => '-112663880', 'data' => ['method' => 'properties_changed', 'params' => [['siid' => 3, 'piid' => 1, 'value' => 11]]]]), 0, 0));
check('ohne vollständigen Stand wird nichts übersetzt (wartet auf Abruf)', variable(2000, 'Akku')['value'] === $akku);
$konto->WriteAttributeString('Schnappschuss', json_encode($merke));

$GLOBALS['SKRIPTE'] = [];
$b2 = $b;
$b2['expires_at'] = time() + 10;
$konto->WriteAttributeString('Buendel', json_encode($b2));
$konto->Erneuern();
$skript = end($GLOBALS['SKRIPTE']) ?: '';
check('neuer Zugangsschlüssel → Socket wird neu verbunden', strpos($skript, '"Open", false') !== false && strpos($skript, 'IPS_SetConfiguration') !== false, $skript);

$GLOBALS['LOG'] = [];
mqttVomServer($konto, "\x20\x02\x00\x05");
check('CONNACK 5 → Fehler sichtbar und protokolliert', strpos(json_encode(json_decode($konto->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE), 'nicht berechtigt') !== false && strpos(implode("\n", $GLOBALS['LOG']), 'Echtzeit-Anmeldung abgelehnt') !== false);

mqttVomServer($konto, "\x20\x02\x00\x00");
$stand = json_decode($konto->ReadAttributeString('Mqtt'), true);
$stand['lebenszeichen'] = time() - 400;
$konto->WriteAttributeString('Mqtt', json_encode($stand));
$GLOBALS['SKRIPTE'] = [];
$konto->MqttPing();
check('keine Antwort seit 3 Intervallen → Neuverbindung', count($GLOBALS['SKRIPTE']) === 1);
$stand['lebenszeichen'] = time();
$stand['zustand'] = 'verbunden';
$konto->WriteAttributeString('Mqtt', json_encode($stand));
$socket->gesendet = [];
$konto->MqttPing();
check('sonst PINGREQ', ($socket->gesendet[0] ?? '') === "\xC0\x00");

$konto->props['Echtzeit'] = false;
check('Echtzeit aus → Socket geschlossen', json_decode($konto->GetConfigurationForParent(), true)['Open'] === false);
$konto->props['Echtzeit'] = true;

abschnitt('Fehlerpfade');
$b = json_decode($konto->ReadAttributeString('Buendel'), true);
$b['expires_at'] = time() + 10;
$konto->WriteAttributeString('Buendel', json_encode($b));
FakeCloud::$refreshStatus = 401;
FakeCloud::$liste = [$saugerRecord];
$t = $geraet->Aktualisieren();
check('abgelehnter Erneuerungsschlüssel → Roboter meldet Fehler', strpos($t, '⛔') === 0);
check('Roboter-Status 201 (Anmeldung nötig)', $geraet->status === 201);
check('Konto verwirft Schlüssel und zeigt 201', $konto->ReadAttributeString('Buendel') === '' && $konto->status === 201);
check('Erreichbar springt auf false', variable(2000, 'Erreichbar')['value'] === false);
check('Fehler dauerhaft protokolliert', strpos(implode("\n", $GLOBALS['LOG']), 'neu anmelden') !== false);
FakeCloud::$refreshStatus = 200;

$konto->WriteAttributeString('Buendel', json_encode(['refresh_token' => 'R', 'access_token' => 'A', 'expires_at' => time() + 7200, 'region' => 'eu']));
$konto->WriteAttributeInteger('WarteBis', time() + 600);
$antwort = json_decode($konto->ForwardData(json_encode(['Aktion' => 'zustand', 'Geraet' => '-112663880'])), true);
check('Sperrfrist: keine Cloud-Anfrage, Antwort mit warteBis', $antwort['ok'] === false && $antwort['warteBis'] > time());
$geraet->Aktualisieren();
check('Roboter in Sperrfrist: Status 202 (wartet)', $geraet->status === 202);
$konto->WriteAttributeInteger('WarteBis', 0);

$ohneKonto = new RoboHubGeraet(2200);
$ohneKonto->props = ['GeraetID' => 'x', 'Modell' => '', 'Klasse' => 'sauger', 'Intervall' => 120];
$ohneKonto->Create();
$ohneKonto->ApplyChanges();
check('ohne Konto-Instanz verständliche Meldung', strpos($ohneKonto->Aktualisieren(), 'Keine aktive RoboHub-Konto-Instanz') !== false);

abschnitt('Formulare (Konvention)');
$libVersion = json_decode(file_get_contents(dirname(__DIR__) . '/library.json'), true)['version'];
foreach ([$konto, $geraet, $cfg] as $mod) {
    global $libVersion;
    $name = get_class($mod);
    $json = $mod->GetConfigurationForm();
    $f = json_decode($json, true);
    check("$name: gültiges JSON", is_array($f));
    $caps = array_map(function ($e) { return $e['caption'] ?? ''; }, $f['elements']);
    $erwartet = ['👋  Wozu dieses Modul?', '🆕  Neu bis Version ' . $libVersion, '📖  Dokumentation & Hilfe'];
    check("$name: Kopf-Reihenfolge Zweck → Neu → Doku", array_slice($caps, 0, 3) === $erwartet, implode(' | ', $caps));
    check("$name: endet mit Rückmeldung → Über dieses Modul", array_slice($caps, -2) === ['💬  Rückmeldungen', '🧡  Über dieses Modul']);
    check("$name: kein doppeltes Fragezeichen", strpos($json, '? ?') === false && strpos($json, '??') === false);
    check("$name: kein Datum JJJJ-MM-TT", !preg_match('/\b20\d\d-\d\d-\d\d\b/', $json));
    check("$name: Link-Knöpfe nutzen echo + link:true", !preg_match('/"link":"http/', $json));
    $statusCodes = array_column($f['status'], 'code');
    check("$name: Status 102 beschrieben", in_array(102, $statusCodes, true));
}
$geraet->AckNews();
check('AckNews speichert installierte Version ohne Suffix', $geraet->ReadAttributeString('SeenNews') === json_decode(file_get_contents(dirname(__DIR__) . '/library.json'), true)['version']);
$f = json_decode($geraet->GetConfigurationForm(), true);
check('nach Bestätigen kein News-Panel mehr', !in_array('🆕  Neu bis Version ' . $libVersion, array_column($f['elements'], 'caption'), true));
$lib = json_decode(file_get_contents(dirname(__DIR__) . '/library.json'), true);
$news = (new ReflectionClass('RoboHubKonto'))->getStaticPropertyValue('ROBOHUB_NEWS_VERSIONS');
$keys = array_keys($news);
usort($keys, 'version_compare');
check('höchster News-Eintrag = library.json-Version', end($keys) === $lib['version']);
check('library.json nur Store-Felder', array_keys($lib) === ['id', 'author', 'name', 'url', 'compatibility', 'version', 'build', 'date']);

abschnitt('Vertragshygiene');
foreach (['RoboHubKonto', 'RoboHubGeraet', 'RoboHubKonfigurator'] as $klasse) {
    foreach ((new ReflectionClass($klasse))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->getDeclaringClass()->getName() === 'IPSModule') { continue; }
        foreach ($m->getParameters() as $p) {
            if (in_array($m->getName(), ['MessageSink', 'RequestAction', 'ForwardData', 'ReceiveData', '__construct'], true)) { continue; }
            check("$klasse::{$m->getName()}(\${$p->getName()}) skalar typisiert, ohne Standardwert", $p->hasType() && in_array((string)$p->getType(), ['string', 'int', 'float', 'bool'], true) && !$p->isDefaultValueAvailable());
        }
    }
    $mj = json_decode(file_get_contents(dirname(__DIR__) . '/' . $klasse . '/module.json'), true);
    check("$klasse: module.json-Name = Klassenname", $mj['name'] === $klasse);
}
$prefixe = array_map(function ($k) { return json_decode(file_get_contents(dirname(__DIR__) . "/$k/module.json"), true)['prefix']; }, ['RoboHubKonto', 'RoboHubGeraet', 'RoboHubKonfigurator']);
check('jeder Modultyp hat eigenen Präfix', count(array_unique($prefixe)) === 3);
$quelltext = '';
foreach (glob(dirname(__DIR__) . '/{libs,RoboHub*}/*.php', GLOB_BRACE) as $d) { $quelltext .= file_get_contents($d); }
check('keine ColorValue/ColorActive-Schlüssel (Mobile-App-Absturz)', strpos($quelltext, 'ColorValue') === false && strpos($quelltext, 'ColorActive') === false);
check('kein Sys_GetURLContentEx (kann kein POST)', !preg_match('/Sys_GetURLContentEx\s*\(/', $quelltext));
check('keine Mojibake-Umlaute', strpos($quelltext, 'Ã') === false);
check('Kontakt-E-Mail nur im Lizenz-Panel (einmal)', substr_count($quelltext, 'dietmar@gureth.eu') === 1);

echo "\n" . ($fails === 0 ? '✅ Alle Prüfungen bestanden.' : "❌ $fails Prüfung(en) fehlgeschlagen.") . "\n";
exit($fails === 0 ? 0 : 1);
