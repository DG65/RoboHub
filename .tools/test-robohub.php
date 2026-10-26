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
const VARIABLE_PRESENTATION_SWITCH = '{32F8C2C9-3A09-7E0C-443F-2DBBE6D86BC2}';
const VARIABLE_PRESENTATION_SLIDER = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';
const VARIABLE_PRESENTATION_VALUE_INPUT = '{6F477326-1683-A2FD-D2E7-477F366ECB62}';

$GLOBALS['OBJ'] = [];      // id => [ident, parent, type, value, name, pres, action]
$GLOBALS['NEXT'] = 60000;
$GLOBALS['LOG'] = [];
$GLOBALS['INST'] = [];     // InstanceID => Modulobjekt

function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_Sleep(int $ms): void {}
function IPS_SetHidden(int $id, bool $v): bool { $GLOBALS['OBJ'][$id]['hidden'] = $v; return true; }
function IPS_GetObject(int $id): array { return ['ObjectIsHidden' => (bool)($GLOBALS['OBJ'][$id]['hidden'] ?? false), 'ObjectIdent' => $GLOBALS['OBJ'][$id]['ident'] ?? '']; }
function IPS_GetObjectIDByIdent(string $ident, int $parent) {
    foreach ($GLOBALS['OBJ'] as $id => $o) { if ($o['ident'] === $ident && $o['parent'] === $parent) { return $id; } }
    return false;
}
function IPS_SemaphoreEnter(string $n, int $ms): bool { return true; }
function IPS_SemaphoreLeave(string $n): bool { return true; }
const KL_MESSAGE = 10201;
const KL_WARNING = 10204;
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
function IPS_DeleteInstance(int $id): bool { $GLOBALS['GELOESCHT'][] = $id; unset($GLOBALS['INST'][$id]); return true; }
function IPS_DisconnectInstance(int $id): bool { $GLOBALS['INST'][$id]->parent = null; return true; }
function ROBOHUB_GeraeteListe(int $id): string { return $GLOBALS['INST'][$id]->GeraeteListe(); }
function ROBOHUB_GeraeteSuchen(int $id): string { return $GLOBALS['INST'][$id]->GeraeteSuchen(); }
$GLOBALS['CONNECTS'] = [];
$GLOBALS['SKRIPTE'] = [];
function IPS_RunScriptText(string $s): bool { $GLOBALS['SKRIPTE'][] = $s; return true; }
$GLOBALS['NAMEN'] = [];
function IPS_CreateInstance(string $guid): int
{
    if ($guid === '{DB269BEC-0CA6-4E6A-83B8-78FF8CFB2DB0}') {
        $k = new RoboHubKonto(80000 + count($GLOBALS['INST']));
        $k->Create();
        $GLOBALS['ERZEUGT'][] = [$guid, $k->InstanceID];
        return $k->InstanceID;
    }
    $s = new FakeSocket(70000 + count($GLOBALS['INST'])); $s->modul = $guid; $GLOBALS['ERZEUGT'][] = [$guid, $s->InstanceID]; return $s->InstanceID;
}
function IPS_SetName(int $id, string $n): bool { $GLOBALS['NAMEN'][$id] = $n; if (isset($GLOBALS['OBJ'][$id])) { $GLOBALS['OBJ'][$id]['name'] = $n; } return true; }
function IPS_GetConfiguration(int $id): string { return json_encode($GLOBALS['INST'][$id]->config ?? []); }
/** Nachgebildeter Client Socket: merkt sich gesendete Bytes. */
class FakeSocket
{
    public $InstanceID;
    public $config = ['Open' => false];
    public $modul = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
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
function IPS_GetName(int $id): string { return $GLOBALS['OBJ'][$id]['name'] ?? $GLOBALS['NAMEN'][$id] ?? ('Instanz ' . $id); }
function IPS_GetInstance(int $id): array { $m = $GLOBALS['INST'][$id] ?? null; return ['ConnectionID' => ($m && $m->parent) ? $m->parent->InstanceID : 0, 'InstanceStatus' => ($m && isset($m->status)) ? $m->status : 102,
    'ModuleInfo' => ['ModuleID' => $m instanceof FakeSocket ? $m->modul : ($m instanceof RoboHubKonto ? '{DB269BEC-0CA6-4E6A-83B8-78FF8CFB2DB0}' : '')]]; }

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
    public function LogMessage(string $m, int $t): void { $GLOBALS['LOG'][] = $m; $GLOBALS['LOGSTUFE'][] = $t; }
    public function UpdateFormField(string $n, string $p, $v): void { $this->fields[] = [$n, $p, $v]; }
    public $elternMeldung = null;           // null = normal; false = HasActiveParent() meldet fälschlich „nein" (Live-Befund)
    public function HasActiveParent(): bool { return $this->elternMeldung ?? ($this->parent !== null); }
    public $puffer = [];
    // Wie live beobachtet: RequireParent() legt bei Bestandsinstanzen nichts an.
    public function RequireParent(string $guid): void {}
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
        $GLOBALS['OBJ'][$id]['pres'] = $pres;   // wie Symcon: Darstellung wird bei jedem Aufruf nachgezogen
        // Wie Symcon 9.1: Zahlen-Parameter der Wertanzeige nur bei Ganz-/Kommazahlen (Forum 14.10.2026).
        if (is_array($pres) && ($pres['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_VALUE_PRESENTATION && !in_array($type, [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true)) {
            foreach (['DIGITS', 'USAGE_TYPE', 'PERCENTAGE', 'MIN', 'MAX', 'THOUSANDS_SEPARATOR', 'DECIMAL_SEPARATOR', 'INTERVALS', 'INTERVALS_ACTIVE'] as $k) {
                if (array_key_exists($k, $pres)) {
                    $GLOBALS['OPTIONS_FEHLER'][] = $ident . ': ' . $k . ' bei Typ ' . $type;
                }
            }
        }
        // Wie neuere Symcon-Versionen: unbekannte Schlüssel in OPTIONS sind ein Fehler (Forum 13.10.2026).
        // Erlaubte OPTIONS-Schlüssel hängen von der Darstellung ab (Symcon-Doku, SUITE.md Stolperstein 9):
        // Aufzählung … Color, Wertanzeige … ColorActive/ColorValue (NICHT Color).
        $erlaubt = [
            VARIABLE_PRESENTATION_ENUMERATION        => ['Value', 'Caption', 'IconActive', 'IconValue', 'Color'],
            VARIABLE_PRESENTATION_VALUE_PRESENTATION => ['Value', 'Caption', 'IconActive', 'IconValue', 'ColorActive', 'ColorValue'],
        ];
        // Symcon-Doku Wertanzeige: OPTIONS nur bei Boolean/String — bei Zahlen ignoriert Symcon sie still
        // (live 26.10.2026: „Zustand" zeigte nur die Zahl). Zahlen mit Text → INTERVALS mit Konstante.
        if (is_array($pres) && ($pres['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_VALUE_PRESENTATION && isset($pres['OPTIONS']) && in_array($type, [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true)) {
            $GLOBALS['OPTIONS_FEHLER'][] = $ident . ': OPTIONS bei Zahl (wirkt nicht, INTERVALS nehmen)';
        }
        if (is_array($pres) && isset($pres['INTERVALS'])) {
            if (empty($pres['INTERVALS_ACTIVE'])) {
                $GLOBALS['OPTIONS_FEHLER'][] = $ident . ': INTERVALS ohne INTERVALS_ACTIVE';
            }
            $iErlaubt = ['IntervalMinValue', 'IntervalMaxValue', 'ConstantActive', 'ConstantValue', 'ConversionFactor', 'PrefixActive', 'PrefixValue', 'SuffixActive', 'SuffixValue',
                'DigitsActive', 'DigitsValue', 'IconActive', 'IconValue', 'ColorActive', 'ColorValue', 'ContentColorActive', 'ContentColorValue'];
            foreach ((array)json_decode((string)$pres['INTERVALS'], true) as $iv) {
                foreach (array_keys((array)$iv) as $k) {
                    if (!in_array($k, $iErlaubt, true)) {
                        $GLOBALS['OPTIONS_FEHLER'][] = $ident . ': Intervall-Schlüssel ' . $k;
                    }
                }
            }
        }
        if (is_array($pres) && isset($pres['OPTIONS'])) {
            $liste = $erlaubt[$pres['PRESENTATION'] ?? ''] ?? null;
            foreach ((array)json_decode((string)$pres['OPTIONS'], true) as $opt) {
                foreach (array_keys((array)$opt) as $k) {
                    if ($liste === null || !in_array($k, $liste, true)) {
                        $GLOBALS['OPTIONS_FEHLER'][] = $ident . ': ' . $k . ($liste === null ? ' (OPTIONS bei unbekannter Darstellung)' : '');
                    }
                }
            }
        }
        $GLOBALS['OBJ'][$id]['pres'] = $pres;
        // Wie Symcon live (Befund 12.10.2026): eine vorhandene Variable wird NICHT umbenannt.
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
    public static $cmsGeschrieben = null;   // was zuletzt per {"m":"s","t":"CMS"} geschrieben wurde
    public static $cfg = null;              // Mäher-Einstellungen (Antwort auf {"m":"g","t":"CFG"}), null = keine Antwort
    public static $cfgGeschrieben = [];     // alle {"m":"s","t":"DND"/"VOL"}-Aufträge
    public static $geraeteDaten = null;     // Antwort data von getDeviceData (Mäher-Karte in Stücken)
    public static $maehplan = '';           // Zeitplan-Dokument des Mähers (Kartenplatz 0, Generation 2)
    public static $kanal = [];              // alle Anfragen an 2.50 (t)
    public static $tabellen = null;         // MOVA-Tabellenform: Antwort auf SCHDI je Platz (Platz => d), null = r 3
    public static $tabTermine = [];         // 'Tabelle/Termin' => Antwort auf SCHDC
    public static $schds = [];              // gesendete SCHDS-Daten
    public static $schdsWirkt = true;       // false = Mäher bestätigt, übernimmt aber nicht
    public static $liveOut = [];            // Antwort out auf action 6.1 (Live-Kartenanforderung)
    public static $liveDatei = '';          // Inhalt der Zwischendatei „live/…"
    public static $objekt = '';             // Inhalt des Kartenobjekts „obj/…" (6.3)
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
                if (is_array($in) && ($in['t'] ?? '') === 'CMS' && ($in['m'] ?? '') === 's') {
                    self::$cmsGeschrieben = $in['d']['value'] ?? null;
                    return [200, json_encode(['code' => 0, 'data' => ['result' => ['code' => 0, 'out' => []]]])];
                }
                if (is_array($in) && in_array($in['t'] ?? '', ['DND', 'VOL'], true) && ($in['m'] ?? '') === 's') {
                    self::$cfgGeschrieben[] = $in;
                    return [200, json_encode(['code' => 0, 'data' => ['result' => ['code' => 0, 'out' => []]]])];
                }
                if (is_array($in) && isset($in['t'])) {
                    self::$kanal[] = $in['t'];
                }
                $r3 = json_encode(['code' => 0, 'data' => ['result' => ['code' => 0, 'out' => [json_encode(['m' => 'r', 'q' => 1, 'r' => 3])]]]]);
                if (is_array($in) && ($in['t'] ?? '') === 'SCHDI') {
                    $platz = intdiv((int)($in['d'][0] ?? 0), 2);
                    if (self::$tabellen === null) {
                        return [200, $r3];
                    }
                    return [200, json_encode(['code' => 0, 'data' => ['result' => ['code' => 0, 'out' => [json_encode(['m' => 'r', 'r' => 0, 'd' => self::$tabellen[$platz] ?? []])]]]])];
                }
                if (is_array($in) && ($in['t'] ?? '') === 'SCHDS' && ($in['m'] ?? '') === 's') {
                    self::$schds[] = $in['d'];
                    if (self::$schdsWirkt) {
                        [$tab, $an] = $in['d'];
                        foreach (self::$tabellen[intdiv($tab, 2)] as $i => $t) {
                            if ($t[0] === $tab) {
                                self::$tabellen[intdiv($tab, 2)][$i][1] = $an;
                            } elseif ($an === 1) {
                                self::$tabellen[intdiv($tab, 2)][$i][1] = 0;   // Nachbarplan geht aus (EvotecIT)
                            }
                        }
                    }
                    return [200, json_encode(['code' => 0, 'data' => ['result' => ['code' => 0, 'out' => [json_encode(['m' => 'r', 'r' => 0])]]]])];
                }
                if (is_array($in) && ($in['t'] ?? '') === 'SCHDC') {
                    $k = ($in['d'][0] ?? '') . '/' . ($in['d'][1] ?? '');
                    return [200, isset(self::$tabTermine[$k]) ? json_encode(['code' => 0, 'data' => ['result' => ['code' => 0, 'out' => [json_encode(['m' => 'r', 'r' => 0, 'd' => self::$tabTermine[$k]])]]]]) : $r3];
                }
                if (is_array($in) && ($in['t'] ?? '') === 'SCHDIV2') {
                    $leer = ($in['d']['i'] ?? null) !== 0 || self::$maehplan === '';
                    // Platz −1: Länge > 0, aber Version 0xFFFF = leer (EvotecIT EMPTY_SCHEDULE_VERSION).
                    $d = $leer ? ['l' => ($in['d']['i'] ?? null) === -1 ? 5 : 0, 'v' => 65535] : ['i' => 0, 'l' => strlen(self::$maehplan), 'v' => 7];
                    return [200, json_encode(['code' => 0, 'data' => ['result' => ['code' => 0, 'out' => [json_encode(['r' => 0, 'd' => $d])]]]])];
                }
                if (is_array($in) && ($in['t'] ?? '') === 'SCHDDV2') {
                    $teil = substr(self::$maehplan, (int)$in['d']['s'], (int)$in['d']['l']);
                    return [200, json_encode(['code' => 0, 'data' => ['result' => ['code' => 0, 'out' => [json_encode(['r' => 0, 'd' => ['s' => $in['d']['s'], 'l' => strlen($teil), 'd' => $teil, 'v' => 7]])]]]])];
                }
                if (is_array($in) && ($in['t'] ?? '') === 'CFG') {
                    return self::$cfg === null
                        ? [200, json_encode(['code' => 0, 'data' => ['result' => ['code' => -1, 'out' => []]]])]
                        : [200, json_encode(['code' => 0, 'data' => ['result' => ['code' => 0, 'out' => [json_encode(['d' => self::$cfg])]]]])];
                }
                if (is_array($in) && ($in['t'] ?? '') === 'CMS') {
                    return [200, json_encode(['code' => 0, 'data' => ['result' => ['code' => 0, 'out' => [self::$cms]]]])];
                }
                $k = $b['data']['params']['siid'] . '.' . $b['data']['params']['aiid'];
                if ($k === '6.1') {
                    return [200, json_encode(['code' => 0, 'data' => ['result' => ['code' => 0, 'out' => self::$liveOut]]])];
                }
                return [200, json_encode(['code' => 0, 'data' => ['result' => ['code' => !empty(self::$ablehnen[$k]) ? -1 : 0, 'out' => []]]])];
            }
        }
        if (strpos($url, 'iotuserdata/getDeviceData') !== false) {
            return [200, json_encode(['code' => 0, 'data' => self::$geraeteDaten])];
        }
        if (strpos($url, 'iotfile/getDownloadUrl') !== false) {
            $fn = (string)(json_decode((string)$body, true)['filename'] ?? '');
            $ziel = strpos($fn, 'live/') === 0 ? 'live' : (strpos($fn, 'obj/') === 0 ? 'obj' : 'karte.json');
            return [200, json_encode(['code' => 0, 'data' => 'https://download.example/' . $ziel])];
        }
        if (strpos($url, 'download.example/obj') !== false) {
            return [200, self::$objekt];
        }
        if (strpos($url, 'download.example/live') !== false) {
            return [200, self::$liveDatei];
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
check('Mäher als Rasenmähroboter erkannt', ($m['klasse'] ?? '') === 'Rasenmähroboter' && ($m['create'][0]['configuration']['Klasse'] ?? '') === 'maeher');
check('Anzeigename-Ersatz aus deviceInfo.displayName', ($m['name'] ?? '') === 'MOVA 600');
check('negative did bleibt String', ($liste['values'][0]['create'][0]['configuration']['GeraetID'] ?? null) === '-112663880');
$GLOBALS['ERZEUGT'] = [];
$t = $cfg->KontoAnlegen();
$neuKonto = $GLOBALS['ERZEUGT'][0][1] ?? 0;
check('Konfigurator legt auf Knopfdruck ein Konto an (Name „RoboHub Konto")', ($GLOBALS['ERZEUGT'][0][0] ?? '') === '{DB269BEC-0CA6-4E6A-83B8-78FF8CFB2DB0}'
    && ($GLOBALS['NAMEN'][$neuKonto] ?? '') === 'RoboHub Konto' && strpos($t, '✅') === 0, $t);
unset($GLOBALS['INST'][$neuKonto]);
check('kein erfundener parent-Schlüssel in create', !isset($liste['values'][0]['create']['parent']) && !isset($liste['values'][0]['create'][0]['parent']));
$kette = $liste['values'][0]['create'] ?? [];
check('create ist die Kette Roboter → Konto → Client Socket (Symcon 9.1 verlangt sie vollständig)', count($kette) === 3
    && $kette[2]['moduleID'] === '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}' && $kette[2]['configuration'] === []
    && strpos($cfg->GetConfigurationForm(), '"moduleID":"{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}","configuration":{}') !== false
    && $kette[0]['moduleID'] === '{631DA85B-5985-4C25-B222-E47FDD49E302}' && $kette[1]['moduleID'] === '{DB269BEC-0CA6-4E6A-83B8-78FF8CFB2DB0}'
    && $kette[1]['configuration'] === ['Hersteller' => 'dreame', 'Konto' => 'nutzer@example.org'] && $kette[0]['name'] === 'Aqua10', json_encode($kette));

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
check('neuer Block hängt am Ende (Blocksperre-Indizes stabil)', (function () { $r = new ReflectionClass('RoboHubDreame'); $b = $r->getConstant('BLOECKE_SAUGER'); return $b[7] === [[26, 1], [26, 2], [35, 1]] && $b[8] === [[5, 1], [5, 2], [5, 3], [5, 4]] && $b[9] === [[7, 1]] && end($b) === [[8, 2]] && $b[2][5] === [35, 2]; })());
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

abschnitt('Kartenbild');
/** PNG (Palette, 8 Bit) prüfen und Zeilen als Listen von Palettenindizes liefern; null bei Fehler. */
function pngLesen(string $uri): ?array
{
    if (strpos($uri, 'data:image/png;base64,') !== 0) { return null; }
    $png = base64_decode(substr($uri, 22));
    if (substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") { return null; }
    $pos = 8; $idat = ''; $b = 0; $h = 0; $plte = ''; $trns = null;
    while ($pos < strlen($png)) {
        $len = unpack('N', substr($png, $pos, 4))[1];
        $typ = substr($png, $pos + 4, 4);
        $d = substr($png, $pos + 8, $len);
        if (unpack('N', substr($png, $pos + 8 + $len, 4))[1] !== crc32($typ . $d)) { return null; }
        if ($typ === 'IHDR') { [, $b, $h] = unpack('N2', $d) + [0, 0, 0]; $b = unpack('N', substr($d, 0, 4))[1]; $h = unpack('N', substr($d, 4, 4))[1]; if (substr($d, 8, 2) !== "\x08\x03") { return null; } }
        if ($typ === 'PLTE') { $plte = $d; }
        if ($typ === 'tRNS') { $trns = $d; }
        if ($typ === 'IDAT') { $idat .= $d; }
        $pos += 12 + $len;
    }
    $roh = gzuncompress($idat);
    $zeilen = [];
    for ($y = 0; $y < $h; $y++) {
        $z = substr($roh, $y * ($b + 1), $b + 1);
        if ($z[0] !== "\0") { return null; }
        $zeilen[] = array_map('ord', str_split(substr($z, 1)));
    }
    return ['b' => $b, 'h' => $h, 'zeilen' => $zeilen, 'plte' => $plte, 'trns' => $trns];
}
// 6×4-Karte, Zeile 0 = kleinstes y. Räume 3 (links) und 5 (rechts), Wand (Bit 7) oben, außen 0.
// Zeile 3 (größtes y) = Wand → muss im Bild OBEN stehen (gespiegelt wie Tasshack).
$punkte = [
    [0, 3, 3, 5, 5, 0],
    [0, 3, 3, 5, 5, 0],
    [0, 3, 3, 5, 5, 0],
    [0, 0x80, 0x80, 0x80, 0x80, 0],
];
$pix = '';
foreach ($punkte as $z) { foreach ($z as $p) { $pix .= chr($p); } }
$rohKarte = ['json' => ['seg_inf' => ['3' => [], '5' => []]], 'breite' => 6, 'hoehe' => 4, 'raster' => 50, 'links' => 0, 'oben' => 0, 'station' => [125, 25], 'pixel' => $pix];
$kb = RoboHubKartenbild::sauger($rohKarte, [3 => 'Küche', 5 => 'Bad']);
$png = pngLesen($kb['bild']);
check('Sauger-Kartenbild ist gültiges PNG (Prüfsummen, Palette, Filter 0)', $png !== null, substr($kb['bild'], 0, 40));
$f = $png ? intdiv($png['b'], 4) : 0;
check('auf belegte Fläche zugeschnitten (4×4) und ganzzahlig vergrößert', $png && $png['b'] === $png['h'] && $png['b'] % 4 === 0 && $f >= 2, json_encode([$png['b'] ?? 0, $png['h'] ?? 0]));
$px = function (int $x, int $y) use ($png, $f) { return $png['zeilen'][$y * $f][$x * $f]; };
check('gespiegelt: Wand (größtes y) oben, Räume darunter', $png && $px(0, 0) === 1 && $px(3, 0) === 1 && $px(0, 3) !== 1);
check('Räume farbig getrennt, Raum 3 links / Raum 5 rechts', $png && $px(0, 2) === $px(1, 3) && $px(2, 2) === $px(3, 1) && $px(0, 2) !== $px(2, 2) && $px(0, 2) >= 3);
check('außen durchsichtig (tRNS Eintrag 0)', $png && $png['trns'] === "\x00");
// Trennlinie: Raum 3 (lokal x 0–1) grenzt an Raum 5 (x 2–3) → letzte Teilspalte von x=1 weiß (Index 3).
$zeile = $png['zeilen'][2 * $f] ?? [];
check('Trennlinie zwischen zwei Räumen (ein Bildpunkt, Palette 3)', $png && $zeile[2 * $f - 1] === 3 && $zeile[2 * $f - 2] === $px(0, 2) && $zeile[2 * $f] === $px(2, 2), json_encode(array_slice($zeile, 0, 4 * $f)));
check('keine Linie zwischen Raum und Wand oder am Außenrand', $png && $png['zeilen'][$f - 1][$f - 1] === 1 && end($zeile) === $px(3, 2) && $png['zeilen'][4 * $f - 1][0] === $px(0, 3));
check('weiß in der Palette', $png && substr($png['plte'], 9, 3) === "\xff\xff\xff");
$gestapelt = RoboHubKartenbild::sauger(['json' => ['seg_inf' => ['3' => [], '5' => []]], 'breite' => 2, 'hoehe' => 2, 'raster' => 50, 'links' => 0, 'oben' => 0, 'station' => [0, 0], 'pixel' => chr(3) . chr(3) . chr(5) . chr(5)], [3 => 'A', 5 => 'B']);
$pg = pngLesen($gestapelt['bild']);
$fg = $pg ? intdiv($pg['h'], 2) : 0;
$gross = RoboHubKartenbild::sauger(['json' => ['seg_inf' => ['3' => []]], 'breite' => 300, 'hoehe' => 2, 'raster' => 50, 'links' => 0, 'oben' => 0, 'station' => [0, 0], 'pixel' => str_repeat(chr(3), 600)], [3 => 'Halle']);
check('auch große Karten (300 Punkte) mindestens doppelt vergrößert — sonst wäre die Linie so breit wie ein Kartenpunkt', (pngLesen($gross['bild'])['b'] ?? 0) === 600);
check('waagrechte Trennlinie: Raum 5 (oben im Bild) über Raum 3', $pg && $pg['zeilen'][$fg - 1] === array_fill(0, $pg['b'], 3) && $pg['zeilen'][0][0] !== 3 && $pg['zeilen'][$fg][0] !== 3 && $pg['zeilen'][0][0] !== $pg['zeilen'][$fg][0]);
check('Kodierung „standard" gewählt', $kb['kodierung'] === 'standard');
$m = [];
foreach ($kb['marken'] as $mk) { $m[$mk['name']] = $mk; }
check('Beschriftung: Küche links, Bad rechts, beide unterhalb der Wand', isset($m['Küche'], $m['Bad']) && $m['Küche']['x'] === 25.0 && $m['Bad']['x'] === 75.0 && $m['Küche']['y'] === 62.5 && $m['Küche']['id'] === 3, json_encode($kb['marken'], JSON_UNESCAPED_UNICODE));
// Station x=125 mm → Punkt 2,5 → im Ausschnitt (ab x=1) 1,5+0,5 = 2 von 4 → 50 %; y=25 mm → Zeile 0,5 → gespiegelt 2,5 → 3/4 = 75 %.
check('Station aus dem Kartenkopf an der richtigen Stelle', $kb['station'] == ['x' => 50.0, 'y' => 75.0], json_encode($kb['station']));
check('Seitenverhältnis für die Kachel', $kb['format'] === 1.0);
$verschoben = RoboHubKartenbild::sauger(['links' => -100, 'oben' => 300, 'station' => [25, 325]] + $rohKarte, [3 => 'Küche', 5 => 'Bad']);
check('Station relativ zum Kartenursprung (links/oben ≠ 0)', $verschoben['station'] == ['x' => 50.0, 'y' => 75.0], json_encode($verschoben['station']));
$fremd = $pix;
$fremd[6] = chr(9);   // ein Punkt gehört zu Raum 9, der nicht in der Raumliste steht
$fremd[7] = chr(9); $fremd[12] = chr(9); $fremd[13] = chr(9);
$kf = RoboHubKartenbild::sauger(['pixel' => $fremd] + $rohKarte, [3 => 'Küche', 5 => 'Bad']);
check('Raum ohne Namen in der Raumliste → keine Beschriftung, kein Absturz', count($kf['marken']) === 2 && $kf['bild'] !== '');

// Variante v3: Raum = Bits 0–4, Wand = Bits 5–6. 63 = Raum 31 + Wand 1 → Wand; 31 = Boden ohne Raum.
$v3 = str_repeat(chr(3), 4) . str_repeat(chr(63), 4) . str_repeat(chr(31), 4) . str_repeat(chr(63), 4);
$kb3 = RoboHubKartenbild::sauger(['json' => ['seg_inf' => ['3' => []]], 'breite' => 4, 'hoehe' => 4, 'raster' => 50, 'links' => 0, 'oben' => 0, 'station' => [0, 0], 'pixel' => $v3], [3 => 'Flur']);
check('passende Kodierung „v3" gewählt (Raumnummern passen zu seg_inf)', $kb3['kodierung'] === 'v3');
check('ohne seg_inf entscheidet die Raumliste über die Kodierung (Live-Rahmen)', RoboHubKartenbild::sauger(['json' => [], 'breite' => 4, 'hoehe' => 4, 'raster' => 50, 'links' => 0, 'oben' => 0, 'station' => [0, 0], 'pixel' => $v3], [3 => 'Flur'])['kodierung'] === 'v3');
check('Station 0/0 = unbekannt → keine Markierung', $kb3['station'] === null);
$rahmen = RoboHubKartenbild::sauger(['json' => ['seg_inf' => ['3' => []], 'fsm' => 1], 'breite' => 2, 'hoehe' => 1, 'raster' => 50, 'links' => 0, 'oben' => 0, 'station' => [0, 0], 'pixel' => chr(3 << 2) . chr(63 << 2)], [3 => 'Flur']);
$pr = pngLesen($rahmen['bild']);
check('Rahmenkarte (fsm=1): Raum = Bits 2–7, 63 = Wand', $rahmen['kodierung'] === 'rahmen' && $pr && $pr['zeilen'][0][0] >= 3 && end($pr['zeilen'][0]) === 1);
check('leere Karte → kein Bild', RoboHubKartenbild::sauger(['json' => [], 'breite' => 3, 'hoehe' => 3, 'pixel' => str_repeat("\0", 9)], [])['bild'] === '');
check('zu kurze Bilddaten → kein Bild statt Absturz', RoboHubKartenbild::sauger(['json' => [], 'breite' => 30, 'hoehe' => 30, 'pixel' => 'abc'], [])['bild'] === '');

// Roher Kopf: Station/Raster/Ursprung richtig gelesen, „origin" im JSON gewinnt.
$kopf2 = pack('v', 1) . pack('v', 0) . chr(73) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 125) . pack('v', 65511) . pack('v', 0) . pack('v', 50) . pack('v', 6) . pack('v', 4) . pack('v', 65436) . pack('v', 200);
$r2 = RoboHubDreame::dekodiereKarteRoh(strtr(base64_encode(gzcompress($kopf2 . $pix . json_encode(['seg_inf' => ['3' => []], 'origin' => [-300, 400]]))), '+/', '-_'), 'x');
check('Kartenkopf: Station mit Vorzeichen, Raster, Größe; origin ersetzt links/oben', $r2 !== null && $r2['station'] === [125, -25] && $r2['raster'] === 50 && $r2['breite'] === 6 && $r2['links'] === -300 && $r2['oben'] === 400 && $r2['pixel'] === $pix, json_encode($r2 ? array_diff_key($r2, ['pixel' => 1]) : null));

// Mäher: Zonen-Vielecke in mm → SVG, y gespiegelt.
$slotM = ['mowingAreas' => ['value' => [[1, ['name' => 'Rasen', 'path' => [['x' => 0, 'y' => 0], ['x' => 10000, 'y' => 0], ['x' => 10000, 'y' => 5000], ['x' => 0, 'y' => 5000]]]]]],
    'forbiddenAreas' => ['value' => [[7, ['path' => [['x' => 1000, 'y' => 4000], ['x' => 2000, 'y' => 4000], ['x' => 2000, 'y' => 4500]]]]]]];
$km = RoboHubKartenbild::maeher($slotM, [1 => 'Rasen']);
$svg = base64_decode(substr($km['bild'], strlen('data:image/svg+xml;base64,')));
check('Mäher-Karte als SVG mit Zone (grün) und Sperrfläche (rot)', strpos($km['bild'], 'data:image/svg+xml;base64,') === 0 && substr_count($svg, '<polygon') === 2 && strpos($svg, '#7cc47f') !== false && strpos($svg, '#e57373') !== false);
check('SVG ist wohlgeformt', @simplexml_load_string($svg) !== false);
check('Zonenname mittig beschriftet', count($km['marken']) === 1 && $km['marken'][0]['name'] === 'Rasen' && $km['marken'][0]['x'] === 50.0 && $km['marken'][0]['y'] === 50.0, json_encode($km['marken']));
preg_match('/points="([^"]+)"[^>]*#e57373/', $svg, $sp);
$erste = explode(',', explode(' ', $sp[1] ?? '0,0')[0]);
check('y gespiegelt: Sperrfläche (y=4000 mm, weit oben im Garten) liegt im Bild oben', (int)$erste[1] < 1500, $sp[1] ?? '');
check('Seitenverhältnis ≈ 2:1 (gleicher Rand ringsum: 10600 × 5600 mm)', abs($km['format'] - 1.893) < 0.01, (string)$km['format']);
check('Mäher ohne Vielecke → kein Bild', RoboHubKartenbild::maeher(['mowingAreas' => ['value' => [[1, ['name' => 'x']]]]], [1 => 'x'])['bild'] === '');

// Durchgereicht bis zur Kachel.
$segsB = ['3' => ['type' => 4, 'index' => 0], '5' => ['type' => 0, 'name' => base64_encode('Bad')]];
$kopfB = pack('v', 1) . "\0\0" . chr(73) . str_repeat("\0", 6) . pack('v', 125) . pack('v', 25) . "\0\0" . pack('v', 50) . pack('v', 6) . pack('v', 4) . "\0\0\0\0";
FakeCloud::$karte = strtr(base64_encode(gzcompress($kopfB . $pix . json_encode(['seg_inf' => $segsB]))), '+/', '-_');
$geraet->RaeumeLaden();
$k = kachelJson($geraet->GetVisualizationTile());
check('Kachel bekommt das Kartenbild mit Namen', strpos((string)($k['karte']['bild'] ?? ''), 'data:image/png;base64,') === 0 && count($k['karte']['marken']) === 2, json_encode(array_keys((array)($k['karte'] ?? []))));
check('… und weiterhin die Räume', count($k['raeume']) === 2);
check('Bild der gespeicherten Karte trägt keinen Roboter (Position im Kopf ist veraltet)', !isset(RoboHubKartenbild::sauger(['roboter' => [125, 25, 0], 'json' => ['seg_inf' => ['3' => []], 'tr' => 'M0,0L50,0']] + $rohKarte, [3 => 'Küche'])['roboter']));
check('gespeicherte Karte: kein (veralteter) Roboter, kein Weg', !isset($k['karte']['roboter']) && !isset($k['karte']['weg']));
$geraet->props['KarteZeigen'] = false;
check('„Kartenbild zeigen" aus → keine Karte auf der Kachel', kachelJson($geraet->GetVisualizationTile())['karte'] === null);
$geraet->props['KarteZeigen'] = true;
FakeCloud::$karte = $klar;
$geraet->RaeumeLaden();
check('Karte ohne Bildpunkte → altes Bild bleibt (kein Flackern), Räume aktualisiert', strpos((string)(kachelJson($geraet->GetVisualizationTile())['karte']['bild'] ?? ''), 'data:image/png') === 0 && count(kachelJson($geraet->GetVisualizationTile())['raeume']) === 3);

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
check('Kachel: Verbrauchsteil mit % und Stunden', $haupt == ['id' => 'Hauptbuerste', 'name' => 'Hauptbürste', 'prozent' => 88, 'stunden' => 169], json_encode($haupt));
check('Kachel: Räume aus der Karte', count($k['raeume'] ?? []) === 3);
check('Kachel: kein Schlüssel in den Daten', ($k['key'] ?? 'x') === '');
$hk = ['farbschema' => $k['farbschema'] ?? null, 'hauptknopf' => $k['hauptknopf'] ?? null, 'zustand' => $k['zustand'] ?? null];
check('Kachel: Farbschema (Standard automatisch) und Hauptknopf passend zum Zustand', $hk['farbschema'] === 0
    && $hk['hauptknopf'] === ($hk['zustand'] === RoboHubModell::ZUSTAND_REINIGT ? 2 : ($hk['zustand'] === RoboHubModell::ZUSTAND_FEHLER ? 0 : 1)), json_encode($hk));
$html = (string)file_get_contents(dirname(__DIR__) . '/RoboHubGeraet/module.html');
check('Kachel-HTML: festes Hell/Dunkel per Klasse, Automatik schaltet nur ohne „hell" auf dunkel', strpos($html, ':root.dunkel{') !== false && strpos($html, ':root:not(.hell){') !== false
    && strpos($html, "b[0] === d.hauptknopf") !== false);
check('Kachel-HTML: Befehlsknöpfe gesperrt, solange ein Befehl läuft (Rückmeldung oder 40 s geben sie frei)', strpos($html, "(befehlLaeuft ? ' disabled' : '')") !== false
    && strpos($html, 'if (neu.rueck) { rueck = neu.rueck; befehlLaeuft = false; }') !== false && strpos($html, 'if (befehlLaeuft) { return; }') !== false);
// Symcon-Symbole statt Emojis (Forum Thilo 26.10.2026): HTML-SDK-Doku „Icons" → /icons.js, Stil fa-light.
// Liste gegen Symcons icons.js (Dietmars 9.0, 26.10.2026) abgeglichen — neue Namen dort erst nachsehen.
$iconsGeprueft = ['arrow-turn-left', 'arrows-rotate', 'battery-bolt', 'battery-full', 'bell', 'bolt', 'bottle-droplet', 'broom', 'bucket', 'calendar-days',
    'charging-station', 'circle-check', 'circle-info', 'circle-pause', 'circle-question', 'circle-xmark', 'cloud-rain', 'droplet', 'envelope', 'faucet',
    'hourglass', 'house', 'key', 'location-dot', 'lock', 'map', 'moon', 'paper-plane', 'pause', 'play', 'plug', 'rotate-left', 'satellite-dish', 'seedling',
    'sun', 'trash-can', 'triangle-exclamation', 'vacuum-robot', 'volume-high', 'volume-xmark', 'wifi-slash'];
preg_match_all("/sym\\('([a-z0-9-]+)', '([^']*)'\\)/", $html, $symAufrufe, PREG_SET_ORDER);
preg_match_all("/: \\['[^']+', '[a-z]+', '([a-z0-9-]+)'\\]/", $html, $zustandIcons);
preg_match_all("/'[^'a-z ]+': '([a-z0-9-]+)'/u", $html, $emojiIcons);
preg_match_all("/\\[\\d, '([a-z0-9-]+)', '[^']+', '[A-Za-zä]+'\\]/u", $html, $befehlIcons);
$iconNamen = array_unique(array_merge(array_column($symAufrufe, 1), $zustandIcons[1], $emojiIcons[1], $befehlIcons[1]));
check('Kachel-HTML: lädt Symcons icons.js, Symbole im Stil fa-light, Rückfall Emoji ohne icons.js oder auf Wunsch',
    strpos($html, '<script src="/icons.js"></script>') !== false && strpos($html, "'<i class=\"fa-light fa-' + icon") !== false
    && strpos($html, 'daten.symbole !== 1 && window.FontAwesome') !== false);
check('Kachel-HTML: jedes benutzte Symbol gibt es in Symcons Satz, jedes hat ein Ersatz-Emoji', count($iconNamen) >= 35
    && array_diff($iconNamen, $iconsGeprueft) === [] && count(array_filter(array_column($symAufrufe, 2), function ($e) { return $e === ''; })) === 0
    && count($zustandIcons[1]) === 13 && count($befehlIcons[1]) === 4, implode(',', array_diff($iconNamen, $iconsGeprueft)) . ' / ' . count($iconNamen));
check('Kachel: Einstellung „Symbole" kommt mit (Standard Symcon-Symbole)', ($k['symbole'] ?? null) === 0);
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

abschnitt('Verbrauchsteile zurücksetzen');
FakeCloud::$anfragen = [];
$GLOBALS['LOG'] = [];
$t = $geraet->VerbrauchsteilZuruecksetzen('Filter');
$c = FakeCloud::befehle()[0] ?? [];
check('Sauger: Filter zurücksetzen = action 11.1', ($c['method'] ?? '') === 'action' && $c['params']['siid'] === 11 && $c['params']['aiid'] === 1, json_encode($c));
check('… Rückmeldung mit Vorher-Wert', strpos($t, '✅') === 0 && strpos($t, 'vorher') !== false, $t);
check('… dauerhaft protokolliert (als Meldung)', strpos(implode("\n", $GLOBALS['LOG']), 'Filter zurückgesetzt') !== false && end($GLOBALS['LOGSTUFE']) === KL_MESSAGE);
check('… danach neu abgerufen (Kachel zeigt neuen Stand)', count(FakeCloud::befehle()) > 1 && FakeCloud::befehle()[1]['method'] === 'get_properties');
foreach (['Hauptbuerste' => [9, 1], 'Seitenbuerste' => [10, 1], 'Sensoren' => [16, 1]] as $teil => [$si, $ai]) {
    FakeCloud::$anfragen = [];
    $geraet->VerbrauchsteilZuruecksetzen($teil);
    $c = FakeCloud::befehle()[0] ?? [];
    check("Sauger: $teil = action $si.$ai", ($c['params']['siid'] ?? 0) === $si && ($c['params']['aiid'] ?? 0) === $ai);
}
FakeCloud::$anfragen = [];
check('unbekanntes Teil → abgelehnt, nichts gesendet', strpos($geraet->VerbrauchsteilZuruecksetzen('Zustand'), '⛔') === 0 && count(FakeCloud::befehle()) === 0);
$abwId = IPS_GetObjectIDByIdent('Abwasserfilter', 2000);
$abw = $GLOBALS['OBJ'][$abwId];
unset($GLOBALS['OBJ'][$abwId]);   // Modell ohne Abwasserfilter: Teil nicht vorhanden
FakeCloud::$anfragen = [];
check('Sauger-Teil ohne Variable an diesem Modell → nichts an den Roboter gesendet', strpos($geraet->VerbrauchsteilZuruecksetzen('Abwasserfilter'), '⛔') === 0 && count(FakeCloud::befehle()) === 0);
$GLOBALS['OBJ'][$abwId] = $abw;
check('Mäher-Teil beim Sauger (keine Variable) → abgelehnt', strpos($geraet->VerbrauchsteilZuruecksetzen('Messer'), '⛔') === 0 && count(FakeCloud::befehle()) === 0);
FakeCloud::$ablehnen = ['11.1' => true];
$t = $geraet->VerbrauchsteilZuruecksetzen('Filter');
check('Roboter lehnt ab → Fehlermeldung, kein „zurückgesetzt" im Protokoll', strpos($t, '⛔') === 0 && strpos(end($GLOBALS['LOG']) ?: '', 'fehlgeschlagen') !== false && end($GLOBALS['LOGSTUFE']) === KL_WARNING, $t);
FakeCloud::$ablehnen = [];
$geraet->visu = [];
FakeCloud::$anfragen = [];
$geraet->RequestAction('Zuruecksetzen', 'Filter');
$letzte = json_decode((string)end($geraet->visu), true);
check('Kachel: RequestAction „Zuruecksetzen" sendet und meldet zurück', (FakeCloud::befehle()[0]['params']['siid'] ?? 0) === 11 && strpos((string)($letzte['rueck'] ?? ''), 'zurückgesetzt') !== false);
FakeCloud::$anfragen = [];
$j = json_decode(hook($geraet, ['cmd' => 'Zuruecksetzen', 'wert' => 'Filter', 'key' => 'falsch']), true);
check('Seite: Zurücksetzen mit falschem Schlüssel abgelehnt', $j['ok'] === false && count(FakeCloud::befehle()) === 0);
$j = json_decode(hook($geraet, ['cmd' => 'Zuruecksetzen', 'wert' => 'Filter', 'key' => $gespeichert]), true);
check('Seite: Zurücksetzen mit Schlüssel + Freigabe', $j['ok'] === true && (FakeCloud::befehle()[0]['params']['siid'] ?? 0) === 11, json_encode($j, JSON_UNESCAPED_UNICODE));
$geraet->props['WebBedienung'] = false;
FakeCloud::$anfragen = [];
$j = json_decode(hook($geraet, ['cmd' => 'Zuruecksetzen', 'wert' => 'Filter', 'key' => $gespeichert]), true);
check('Seite: ohne Freigabe trotz Schlüssel abgelehnt', $j['ok'] === false && count(FakeCloud::befehle()) === 0);
$formKlar = json_encode(json_decode($geraet->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
check('Formular: Zurücksetzen-Knopf mit Sicherheitsabfrage', strpos($formKlar, 'Verbrauchsteile zurücksetzen') !== false && strpos($formKlar, '"confirm":"Filter wirklich zurücksetzen?') !== false && strpos($formKlar, "ROBO_VerbrauchsteilZuruecksetzen(\$id, 'Filter')") !== false);
check('Formular: kein Knopf für Teile ohne Variable (Messer beim Sauger)', strpos($formKlar, 'Klingen zurücksetzen') === false && strpos($formKlar, "'Messer'") === false);
$_GET = [];

abschnitt('Nicht stören und Lautstärke (Sauger)');
function gesetzt(): array
{
    $o = [];
    foreach (FakeCloud::befehle() as $b) { if ($b['method'] === 'set_properties') { foreach ($b['params'] as $q) { $o[$q['siid'] . '.' . $q['piid']] = $q['value']; } } }
    return $o;
}
$merkeProps = FakeCloud::$props;
FakeCloud::$props['5.4'] = '[{"id":1,"en":true,"st":"22:00","et":"08:00","wk":127,"ss":0},{"id":2,"en":false,"st":"12:00","et":"13:00","wk":31,"ss":0}]';
FakeCloud::$props['7.1'] = 60;
$geraet->Aktualisieren();
check('5.4: frühestes Fenster maßgeblich (12:00, aus)', variable(2000, 'NichtStoeren')['value'] === false && variable(2000, 'NichtStoerenVon')['value'] === '12:00' && variable(2000, 'NichtStoerenBis')['value'] === '13:00');
FakeCloud::$props['5.4'] = '[{"id":1,"en":true,"st":"22:00","et":"08:00","wk":127,"ss":0},{"id":2,"en":false,"st":"23:00","et":"23:30","wk":31,"ss":0}]';
$geraet->Aktualisieren();
check('Nicht stören aus 5.4: an, 22:00–08:00', variable(2000, 'NichtStoeren')['value'] === true && variable(2000, 'NichtStoerenVon')['value'] === '22:00' && variable(2000, 'NichtStoerenBis')['value'] === '08:00');
check('Lautstärke 60 % aus 7.1', variable(2000, 'Lautstaerke')['value'] === 60);
check('Darstellung: Schalter / Schieberegler 0–100 / Eingabefeld, alle schaltbar',
    variable(2000, 'NichtStoeren')['pres']['PRESENTATION'] === VARIABLE_PRESENTATION_SWITCH
    && variable(2000, 'Lautstaerke')['pres']['PRESENTATION'] === VARIABLE_PRESENTATION_SLIDER && variable(2000, 'Lautstaerke')['pres']['MAX'] === 100
    && variable(2000, 'NichtStoerenVon')['pres']['PRESENTATION'] === VARIABLE_PRESENTATION_VALUE_INPUT
    && variable(2000, 'NichtStoeren')['action'] && variable(2000, 'Lautstaerke')['action'] && variable(2000, 'NichtStoerenBis')['action']);
$k = kachelJson($geraet->GetVisualizationTile());
check('Kachel zeigt 🌙 Nicht stören 22:00–08:00 und 🔊 60 %', in_array('🌙 Nicht stören 22:00–08:00', $k['info'], true) && in_array('🔊 60 %', $k['info'], true), json_encode($k['info'], JSON_UNESCAPED_UNICODE));

FakeCloud::$anfragen = [];
$geraet->RequestAction('NichtStoerenVon', '21:30');
$neu = json_decode((string)(gesetzt()['5.4'] ?? 'null'), true);
check('Beginn 21:30 → 5.4 nur im ersten Fenster geändert', $neu[0] == ['id' => 1, 'en' => true, 'st' => '21:30', 'et' => '08:00', 'wk' => 127, 'ss' => 0], json_encode($neu));
check('… zweites Fenster unverändert mitgeschickt', ($neu[1] ?? null) == ['id' => 2, 'en' => false, 'st' => '23:00', 'et' => '23:30', 'wk' => 31, 'ss' => 0]);
check('… Variable übernimmt den neuen Wert', variable(2000, 'NichtStoerenVon')['value'] === '21:30');
FakeCloud::$anfragen = [];
$geraet->RequestAction('NichtStoeren', false);
$neu = json_decode((string)(gesetzt()['5.4'] ?? 'null'), true);
check('Ausschalten → en = false, Zeiten bleiben', ($neu[0]['en'] ?? null) === false && $neu[0]['st'] === '22:00');
FakeCloud::$anfragen = [];
$GLOBALS['LOG'] = [];
$geraet->RequestAction('NichtStoerenBis', '25:00');
check('ungültige Uhrzeit → nichts gesendet, protokolliert', count(FakeCloud::befehle()) === 0 && strpos(implode("\n", $GLOBALS['LOG']), 'HH:MM') !== false);
$geraet->RequestAction('NichtStoerenBis', '22:00');
check('Ende = Beginn → abgelehnt, nichts geschrieben', gesetzt() === [] && strpos(end($GLOBALS['LOG']) ?: '', 'nicht gleich') !== false, end($GLOBALS['LOG']) ?: '');
FakeCloud::$anfragen = [];
$geraet->RequestAction('Lautstaerke', 80);
check('Lautstärke 80 → set_properties 7.1 = 80', gesetzt() === ['7.1' => 80] && variable(2000, 'Lautstaerke')['value'] === 80);
FakeCloud::$anfragen = [];
$geraet->RequestAction('Lautstaerke', 150);
check('Lautstärke 150 → abgelehnt', count(FakeCloud::befehle()) === 0 && variable(2000, 'Lautstaerke')['value'] === 80);
check('Skript-Weg über Konto prüft ebenfalls (Lautstärke −5)', strpos(json_decode($konto->ForwardData(json_encode(['Aktion' => 'befehl', 'Geraet' => '-112663880', 'Befehl' => 'einstellung', 'Parameter' => ['feld' => 'Lautstaerke', 'wert' => -5]])), true)['fehler'] ?? '', '0 und 100') !== false);

FakeCloud::$props['5.4'] = '';
$geraet->Aktualisieren();
check('leeres 5.4 → aus, Ersatzzeiten 22:00–08:00', variable(2000, 'NichtStoeren')['value'] === false && variable(2000, 'NichtStoerenVon')['value'] === '22:00');
FakeCloud::$anfragen = [];
$geraet->RequestAction('NichtStoeren', true);
check('Einschalten ohne Fenster → Fenster id 1 täglich angelegt', json_decode((string)(gesetzt()['5.4'] ?? 'null'), true) == [['id' => 1, 'en' => true, 'st' => '22:00', 'et' => '08:00', 'wk' => 127, 'ss' => 0]], json_encode(gesetzt()));

unset(FakeCloud::$props['5.4']);
FakeCloud::$props += ['5.1' => false, '5.2' => '23:00', '5.3' => '07:00'];
$geraet->Aktualisieren();
check('älteres Modell: 5.1/5.2/5.3', variable(2000, 'NichtStoeren')['value'] === false && variable(2000, 'NichtStoerenVon')['value'] === '23:00' && variable(2000, 'NichtStoerenBis')['value'] === '07:00');
FakeCloud::$anfragen = [];
$geraet->RequestAction('NichtStoeren', true);
check('… Einschalten → 5.1 = true', gesetzt() === ['5.1' => true], json_encode(gesetzt()));
FakeCloud::$anfragen = [];
$geraet->RequestAction('NichtStoerenBis', '06:30');
check('… Ende → 5.3 = "06:30"', gesetzt() === ['5.3' => '06:30'], json_encode(gesetzt()));

unset(FakeCloud::$props['5.1'], FakeCloud::$props['5.2'], FakeCloud::$props['5.3']);
FakeCloud::$anfragen = [];
check('Gerät ohne Nicht stören → klarer Fehler, nichts geschrieben', strpos(json_decode($konto->ForwardData(json_encode(['Aktion' => 'befehl', 'Geraet' => '-112663880', 'Befehl' => 'einstellung', 'Parameter' => ['feld' => 'NichtStoeren', 'wert' => true]])), true)['fehler'] ?? '', 'nicht lesbar') !== false && gesetzt() === []);
FakeCloud::$props = $merkeProps;
$geraet->Aktualisieren();

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
$opts = json_decode(variable(2100, 'Zustand')['pres']['INTERVALS'], true);
$mahtCaption = '';
foreach ($opts as $o) { if ($o['IntervalMinValue'] === RoboHubModell::ZUSTAND_REINIGT && $o['IntervalMaxValue'] === RoboHubModell::ZUSTAND_REINIGT && $o['ConstantActive'] === true) { $mahtCaption = $o['ConstantValue']; } }
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

// Mäh-Zonen: Karte in Stücken wie bei ioBroker reassembleChunks (MAP.info = Gesamtlänge).
$slot = ['mowingAreas' => ['value' => [[3, ['name' => 'Garten hinten', 'area' => 120]], [1, ['name' => 'Vorgarten']], [5, ['name' => '']], [0, ['name' => 'Rand']]]], 'forbiddenAreas' => ['value' => []], 'mapIndex' => 0];
$karte = json_encode([json_encode($slot), ['mowingAreas' => ['value' => [[9, ['name' => 'Zweite Karte']]]]]]);
$teil = intdiv(strlen($karte), 2);
FakeCloud::$geraeteDaten = ['MAP.0' => substr($karte, 0, $teil), 'MAP.1' => substr($karte, $teil) . 'MÜLL', 'MAP.info' => (string)strlen($karte), 'SETTINGS.0' => '{}'];
$t = $maeher->RaeumeLaden();
check('Mäh-Zonen aus getDeviceData (Stücke + Längenkürzung, Slot als JSON-Text)', strpos($t, '✅ 3') === 0, $t);
check('Variable „Zone mähen" mit Zonennamen, ID-sortiert, leerer Name → „Zone 5", ID 0 übergangen',
    variable(2100, 'Raum')['name'] === 'Zone mähen' && json_decode($maeher->ReadAttributeString('Raeume'), true) == [1 => 'Vorgarten', 3 => 'Garten hinten', 5 => 'Zone 5'], $maeher->ReadAttributeString('Raeume'));
check('nur die erste Karte (Zone 9 der zweiten fehlt)', strpos($maeher->ReadAttributeString('Raeume'), 'Zweite') === false);
check('getDeviceData an der MOVA-Adresse mit der Geräte-ID', strpos(FakeCloud::letzte('getDeviceData')['url'], 'https://eu.iot.mova-tech.com:13267/dreame-user-iot/iotuserdata/getDeviceData') === 0 && json_decode(FakeCloud::letzte('getDeviceData')['body'], true) === ['did' => '777']);
FakeCloud::$anfragen = [];
$maeher->RequestAction('Raum', 3);
$c = FakeCloud::befehle()[0] ?? [];
check('Zone mähen → action 2.50 {"m":"a","p":0,"o":102,"d":{"region":[3]}}', ($c['params']['siid'] ?? 0) === 2 && $c['params']['aiid'] === 50 && $c['params']['in'] === [['m' => 'a', 'p' => 0, 'o' => 102, 'd' => ['region' => [3]]]], json_encode($c));
FakeCloud::$anfragen = [];
$t = $maeher->RaeumeReinigen('1,3,3');
$c = FakeCloud::befehle()[0] ?? [];
check('mehrere Zonen „1,3,3" → region [1,3], Rückmeldung', ($c['params']['in'][0]['d']['region'] ?? null) === [1, 3] && strpos($t, '2 Zonen') !== false, $t);
$k = kachelJson($maeher->GetVisualizationTile());
check('Kachel des Mähers zeigt die Zonen', count($k['raeume'] ?? []) === 3 && $k['raeume'][0]['id'] === 1 && $k['raeume'][0]['name'] === 'Vorgarten');
FakeCloud::$anfragen = [];
check('Zonen-Start ohne Zone → abgelehnt, nichts gesendet', strpos($maeher->RaeumeReinigen('0'), '⛔') === 0 && count(FakeCloud::befehle()) === 0);
check('Treiber selbst lehnt Zone 0 ab (Skript-Weg über das Konto)', strpos(json_decode($konto2->ForwardData(json_encode(['Aktion' => 'befehl', 'Geraet' => '777', 'Befehl' => 'raum', 'Parameter' => ['raeume' => [0]]])), true)['fehler'] ?? '', 'Keine Zone') !== false && count(FakeCloud::befehle()) === 0);
FakeCloud::$geraeteDaten = ['SETTINGS.0' => '{}'];
check('ohne Karte → Hinweis statt Fehler, alte Zonen bleiben', strpos($maeher->RaeumeLaden(), 'Keine Räume/Zonen') !== false && variable(2100, 'Raum') !== null);
FakeCloud::$cms = json_encode(['d' => ['value' => [3978, 3978, 1026]]]);
FakeCloud::$cmsGeschrieben = null;
$t = $maeher->VerbrauchsteilZuruecksetzen('Messer');
check('Mäher: Klingen zurücksetzen schreibt CMS [0, 3978, 1026] (andere Zähler bleiben)', FakeCloud::$cmsGeschrieben === [0, 3978, 1026], json_encode(FakeCloud::$cmsGeschrieben) . ' ' . $t);
FakeCloud::$cmsGeschrieben = null;
$maeher->VerbrauchsteilZuruecksetzen('Wartung');
check('Mäher: Wartung = Index 2', FakeCloud::$cmsGeschrieben === [3978, 3978, 0], json_encode(FakeCloud::$cmsGeschrieben));
FakeCloud::$cmsGeschrieben = null;
$maeher->VerbrauchsteilZuruecksetzen('Reinigungsbuerste');
check('Mäher: Reinigungsbürste = Index 1', FakeCloud::$cmsGeschrieben === [3978, 0, 1026], json_encode(FakeCloud::$cmsGeschrieben));
FakeCloud::$cms = json_encode(['d' => ['value' => 'kaputt']]);
FakeCloud::$cmsGeschrieben = null;
$t = $maeher->VerbrauchsteilZuruecksetzen('Messer');
check('Mäher: CMS nicht lesbar → nichts geschrieben', FakeCloud::$cmsGeschrieben === null && strpos($t, '⛔') === 0, $t);
FakeCloud::$cms = json_encode(['d' => ['value' => [3978, 3978, 1026]]]);
check('Mäher: Sauger-Teil (Filter) abgelehnt', strpos($maeher->VerbrauchsteilZuruecksetzen('Filter'), '⛔') === 0);

FakeCloud::$cfg = ['DND' => [0, 1200, 480], 'VOL' => 80, 'WRP' => [1, 8, 0]];
$maeher->Aktualisieren();
check('Mäher: Nicht stören aus CFG (aus, 20:00–08:00), Lautstärke 80', variable(2100, 'NichtStoeren')['value'] === false && variable(2100, 'NichtStoerenVon')['value'] === '20:00' && variable(2100, 'NichtStoerenBis')['value'] === '08:00' && variable(2100, 'Lautstaerke')['value'] === 80);
check('Mäher: Einstellungen schaltbar', variable(2100, 'NichtStoeren')['action'] && variable(2100, 'Lautstaerke')['action']);
FakeCloud::$cfgGeschrieben = [];
$maeher->RequestAction('NichtStoeren', true);
check('Mäher einschalten → {"m":"s","t":"DND","d":{"value":1,"time":[1200,480]}}', FakeCloud::$cfgGeschrieben === [['m' => 's', 't' => 'DND', 'd' => ['value' => 1, 'time' => [1200, 480]]]], json_encode(FakeCloud::$cfgGeschrieben));
FakeCloud::$cfgGeschrieben = [];
$maeher->RequestAction('NichtStoerenBis', '07:15');
check('Mäher Ende 07:15 → Minuten 435, Zustand (aus) erhalten', (FakeCloud::$cfgGeschrieben[0]['d'] ?? null) === ['value' => 0, 'time' => [1200, 435]], json_encode(FakeCloud::$cfgGeschrieben));
FakeCloud::$cfgGeschrieben = [];
$maeher->RequestAction('Lautstaerke', 40);
check('Mäher Lautstärke 40 → {"t":"VOL","d":{"value":40}}', FakeCloud::$cfgGeschrieben === [['m' => 's', 't' => 'VOL', 'd' => ['value' => 40]]], json_encode(FakeCloud::$cfgGeschrieben));
FakeCloud::$cfg = null;
FakeCloud::$cfgGeschrieben = [];
$maeher->RequestAction('NichtStoeren', true);
check('Mäher: CFG nicht lesbar → nichts geschrieben', FakeCloud::$cfgGeschrieben === []);
$maeher->Aktualisieren();
check('Mäher: CFG fällt aus → Variablen bleiben schaltbar (Liste wird zusammengeführt)', variable(2100, 'NichtStoeren')['action'] && in_array('NichtStoeren', json_decode($maeher->ReadAttributeString('Schreibbar'), true), true));
check('Mäher-Tagesminuten über Mitternacht korrekt (1439 → 23:59)', (function () { $m = new ReflectionMethod('RoboHubDreame', 'minutenZuUhrzeit'); return $m->invoke(null, 1439) === '23:59' && $m->invoke(null, 0) === '00:00' && $m->invoke(null, 1440) === '00:00'; })());

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
$kettenKonto = [];
foreach ((array)$werte as $w) { $kettenKonto[(string)$w['id']] = $w['create'][1]['configuration']['Hersteller'] ?? ''; }
check('Kette zeigt je Gerät auf sein eigenes Konto (Dreame/MOVA)', ($kettenKonto['-112663880'] ?? '') === 'dreame' && ($kettenKonto['777'] ?? '') === 'mova', json_encode($kettenKonto));
$kz = '';
foreach (array_reverse($cfg->fields) as $f) { if ($f[0] === 'KontenZeile') { $kz = $f[2]; break; } }
check('Konten-Detailzeile mit 🔗 je angemeldetem Konto', substr_count($kz, '🔗') === 2, $kz);

$neu = new RoboHubGeraet(2400);
$neu->props = ['GeraetID' => '777', 'Modell' => 'mova.mower.g2529c', 'Klasse' => 'maeher', 'Intervall' => 120];
$neu->Create();
$GLOBALS['CONNECTS'] = [];
$neu->ApplyChanges();
check('neuer Mäher ohne Verbindung: in ApplyChanges NICHT selbst verbunden (das macht der Konfigurator danach), Nachfass-Takt läuft',
    $neu->parent === null && $GLOBALS['CONNECTS'] === [] && ($neu->timers['Zuordnen'] ?? 0) === 15000);
$neu->RequestAction('Zuordnen', 0);
check('… ist er nach dem Nachfass-Takt noch frei → verbindet sich selbst mit dem MOVA-Konto, Takt aus', $neu->parent === $konto2 && $GLOBALS['CONNECTS'] === [[2400, 1100]] && ($neu->timers['Zuordnen'] ?? 0) === 0);
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
$unbekannt->RequestAction('Zuordnen', 0);
check('unbekannte Geräte-ID bei zwei Konten → keine geratene Verbindung', $unbekannt->parent === null);
$f = json_decode($neu->GetConfigurationForm(), true);
check('Roboter-Formular zeigt verbundenes Konto (grün)', strpos(json_encode($f, JSON_UNESCAPED_UNICODE), '🔗 Konto: Instanz 1100 (#1100)') !== false);
$f = json_decode($unbekannt->GetConfigurationForm(), true);
check('Roboter ohne Konto: rote ⛔-Zeile', strpos(json_encode($f, JSON_UNESCAPED_UNICODE), '⛔ Kein Konto verbunden') !== false);
unset($GLOBALS['INST'][2400], $GLOBALS['INST'][2401], $GLOBALS['INST'][2402]);

abschnitt('Echtzeit (MQTT)');
// Wie live beobachtet: Konto aus der Zeit vor 0.4.0 ohne Socket (RequireParent wirkt nicht).
$konto->parent = null;
check('Bestandskonto hat noch keinen Socket', $konto->parent === null);
$GLOBALS['ERZEUGT'] = [];
$konto->ApplyChanges();
check('ApplyChanges legt den Socket NICHT sofort an (Konsole darf zuerst), sondern stellt den Timer', $GLOBALS['ERZEUGT'] === [] && ($konto->timers['Verbindung'] ?? 0) > 0);
// Konsole war schneller: Timer findet einen Socket vor → kein zweiter.
$konsole = new FakeSocket(69990);
$konto->parent = $konsole;
$konto->VerbindungPruefen();
check('Socket der Konsole vorhanden → RoboHub legt keinen zweiten an', $GLOBALS['ERZEUGT'] === [] && $konto->parent === $konsole && $konto->timers['Verbindung'] === 0);
$konto->parent = null;
unset($GLOBALS['INST'][69990]);
$konto->VerbindungPruefen();
$socket = $konto->parent;
check('ApplyChanges legt den Client Socket selbst an und verbindet ihn', count($GLOBALS['ERZEUGT']) === 1 && $GLOBALS['ERZEUGT'][0][0] === '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}' && $socket instanceof FakeSocket);
check('… mit sprechendem Namen', strpos($GLOBALS['NAMEN'][$socket->InstanceID] ?? '', 'RoboHub Echtzeit (') === 0);
$konto->ApplyChanges();
check('zweites ApplyChanges legt keinen weiteren Socket an', count($GLOBALS['ERZEUGT']) === 1);
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

// Symcon 9.1 (Rust-TLS): Socket scheitert, bevor je verbunden → schließen statt Fehlerstatus.
$konto->MessageSink(0, $socket->InstanceID, IM_CHANGESTATUS, [200]);
check('Socket scheitert vor erster Verbindung → Echtzeit 24 h geschlossen', $konto->ReadAttributeInteger('EchtzeitGesperrtBis') > time() + 86000
    && json_decode($konto->GetConfigurationForParent(), true)['Open'] === false);
$fz = json_encode(json_decode($konto->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
check('Formular erklärt den Grund (Symcon 9.1, Zertifikat vor 1970) und bietet erneuten Versuch', strpos($fz, 'Symcon 9.1') !== false && strpos($fz, 'vor 1970') !== false
    && strpos($fz, '"caption":"🔁 Echtzeit jetzt erneut versuchen","visible":true') !== false);
$konto->WriteAttributeInteger('EchtzeitGesperrtBis', time() - 1);
$konto->Erneuern();
check('nach Ablauf der Sperre öffnet Erneuern die Echtzeit wieder', $konto->ReadAttributeInteger('EchtzeitGesperrtBis') === 0 && json_decode($konto->GetConfigurationForParent(), true)['Open'] === true);

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
$konto->MessageSink(0, $socket->InstanceID, IM_CHANGESTATUS, [200]);
check('war schon verbunden → späterer Fehler sperrt NICHT (normales Neuverbinden)', $konto->ReadAttributeInteger('EchtzeitGesperrtBis') === 0 && json_decode($konto->GetConfigurationForParent(), true)['Open'] === true);
$konto->MessageSink(0, $socket->InstanceID, IM_CHANGESTATUS, [IS_ACTIVE]);
mqttVomServer($konto, "\x20\x02\x00\x00");

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
$rv = json_decode($geraet->Rohdaten(), true);
check('Verlauf mit Rohwerten in den Rohdaten (gültiges JSON)', is_array($rv) && ($rv['echtzeitVerlauf'][0]['werte']['3.1'] ?? null) === 77, substr($geraet->Rohdaten(), -300));
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
mqttVomServer($konto, RoboHubMqttTest::publish($topic, json_encode(['did' => '-112663880', 'data' => ['method' => 'properties_changed', 'params' => [['siid' => 7, 'piid' => 1, 'value' => 35]]]]), 0, 0));
check('Lautstärke aus der App geändert → per Echtzeit sofort in Symcon', variable(2000, 'Lautstaerke')['value'] === 35);
$vorher = count($geraet->visu);
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

abschnitt('Live-Karte');
check('Weg aus „tr": M = neues Stück, L relativ, S/W absolut', RoboHubKartenbild::saugerWeg('M100,200L10,0L0,10S150,250M500,500L5,5M9,9') === [[[100, 200], [110, 200], [110, 210], [150, 250]], [[500, 500], [505, 505]]], json_encode(RoboHubKartenbild::saugerWeg('M100,200L10,0L0,10S150,250M500,500L5,5M9,9')));
check('Mähweg aus M_PATH: ×10, null/32767 trennt, Einzelpunkte entfallen', RoboHubKartenbild::maeherWeg([[1, 2], [3, 4], [32767, -32768], [5, 6], null, [7, 8], [9, 10]]) === [[[10.0, 20.0], [30.0, 40.0]], [[70.0, 80.0], [90.0, 100.0]]]);
$lang = [];
for ($i = 0; $i < 4000; $i++) { $lang[] = [$i, 0]; }
$lang[] = [4000, 9];   // Endpunkt mit eigenem y; Index 4000 liegt nicht im 3er-Raster
$geoTest = ['art' => 'mm', 'x1' => 0, 'y2' => 10, 'b' => 4000, 'h' => 20];
$r = new ReflectionMethod('RoboHubKartenbild', 'wegNachProzent');
$aus = $r->invoke(null, [$lang], $geoTest);
check('langer Weg ausgedünnt (≤ 1500 Punkte), Endpunkt bleibt', count($aus[0]) <= 1501 && count($aus[0]) > 1000 && end($aus[0]) === [100.0, 5.0], (string)count($aus[0]) . ' ' . json_encode(end($aus[0])));
// Mäher-Telemetrie: x = 123 cm, y = −45 cm, Winkel 64/255.
$xb = 123 & 0xFFFFF; $yb = (-45) & 0xFFFFF;
$tele = [0xCE, $xb & 0xFF, ($xb >> 8) & 0xFF, (($xb >> 16) & 0x0F) | (($yb & 0x0F) << 4), ($yb >> 4) & 0xFF, ($yb >> 12) & 0xFF, 64, 0, 0];
check('Mäher-Position aus 1.4 (20-Bit mit Vorzeichen, cm → mm)', RoboHubDreame::maeherPosition($tele) === [1230, -450, 90], json_encode(RoboHubDreame::maeherPosition($tele)));
check('falsche Kennung → keine Position', RoboHubDreame::maeherPosition([0x00, 1, 2, 3, 4, 5, 6]) === null && RoboHubDreame::maeherPosition([0xCE, 1]) === null);
// Sauger-Teilrahmen („P" = 80) mit Roboter bei x 1500, y −200, Winkel 45.
$pKopf = pack('v', 1) . pack('v', 7) . chr(80) . pack('v', 1500) . pack('v', 65336) . pack('v', 45) . str_repeat("\0", 16);
$pText = strtr(base64_encode(gzcompress($pKopf . '{}')), '+/', '-_');
check('Position aus Kartenrahmen-Kopf (auch Teilrahmen)', RoboHubDreame::kopfPosition($pText) === [1500, -200, 45], json_encode(RoboHubDreame::kopfPosition($pText)));
$ohne = strtr(base64_encode(gzcompress(pack('v', 1) . pack('v', 7) . chr(80) . pack('v', 32767) . pack('v', 32767) . pack('v', 32767) . str_repeat("\0", 16))), '+/', '-_');
check('32767 = keine Position', RoboHubDreame::kopfPosition($ohne) === null);
$fc = new FakeCloud('dreame');
$push = $fc->pushLesen(json_encode(['did' => '5', 'data' => ['method' => 'properties_changed', 'params' => [['siid' => 6, 'piid' => 1, 'value' => $pText], ['siid' => 3, 'piid' => 1, 'value' => 50], ['did' => '777', 'siid' => 1, 'piid' => 4, 'value' => $tele]]]]));
check('Echtzeit: Positionen als _position, Kartentext selbst nicht als Wert', $push === ['5' => ['_position' => [1500, -200, 45], '3.1' => 50], '777' => ['_position' => [1230, -450, 90]]], json_encode($push));

// Sauger: aktuelle Karte über action 6.1 → Zwischendatei (verschlüsselt mit Schlüssel aus dem Dateinamen).
// 6×4 wie oben, Roboter bei x 125 / y 25 (Mitte zwischen Raum 3 und 5), Winkel 90, Weg quer durch Raum 3 und 5.
$lKopf = pack('v', 1) . pack('v', 0) . chr(73) . pack('v', 125) . pack('v', 25) . pack('v', 90) . pack('v', 125) . pack('v', 25) . "\0\0" . pack('v', 50) . pack('v', 6) . pack('v', 4) . "\0\0\0\0";
$lJson = json_encode(['seg_inf' => $segsB, 'tr' => 'M75,25L100,0L100,0']);
$lSchl = 'XYZ123';
FakeCloud::$liveDatei = base64_encode(openssl_encrypt(gzcompress($lKopf . $pix . $lJson), 'aes-256-cbc', substr(hash('sha256', $lSchl), 0, 32), OPENSSL_RAW_DATA, str_repeat("\0", 16)));
FakeCloud::$liveOut = [['piid' => 5, 'value' => '1760000000000'], ['piid' => 3, 'value' => 'live/2026/abc,' . $lSchl]];
// Ausgangslage: Roboter in der Station (der Echtzeit-Test oben ließ ihn „reinigen").
FakeCloud::$props['2.1'] = 13;
$geraet->Aktualisieren();
check('Station → Live-Takt aus', ($geraet->timers['LiveKarte'] ?? -1) === 0 && $geraet->ReadAttributeBoolean('LiveAktiv') === false);
FakeCloud::$anfragen = [];
FakeCloud::$props['2.1'] = 1;
$geraet->Aktualisieren();
$anf = array_values(array_filter(FakeCloud::befehle(), function ($b) { return $b['method'] === 'action' && $b['params']['siid'] === 6; }));
check('Reinigung beginnt → Live-Karte sofort angefordert (action 6.1, frame_type I)', count($anf) === 1 && strpos($anf[0]['params']['in'][0]['value'], '"frame_type":"I"') !== false && $anf[0]['params']['in'][0]['piid'] === 2);
check('… Zwischendatei mit Dateiname ohne Schlüssel angefragt', json_decode(FakeCloud::letzte('getDownloadUrl')['body'], true)['filename'] === 'live/2026/abc');
check('… Live-Takt 30 s läuft', ($geraet->timers['LiveKarte'] ?? 0) === 30000);
$k = kachelJson($geraet->GetVisualizationTile());
check('Kachel: Weg (1 Stück, 3 Punkte) und Roboter auf der Karte', count($k['karte']['weg'] ?? []) === 1 && count($k['karte']['weg'][0]) === 3 && isset($k['karte']['roboter']['x']), json_encode($k['karte']['weg'] ?? null));
check('… Roboter an x 125 / y 25 mm → 50 % / 75 %, Winkel 90', ($k['karte']['roboter'] ?? null) == ['x' => 50.0, 'y' => 75.0, 'winkel' => 90], json_encode($k['karte']['roboter'] ?? null));
check('… Weg beginnt bei x 75 mm → 25 %', ($k['karte']['weg'][0][0] ?? null) == [25.0, 75.0], json_encode($k['karte']['weg'][0] ?? null));
check('… „live"-Zeitpunkt, keine Umrechnungsdaten in der Kachel', ($k['karte']['live'] ?? 0) >= time() - 5 && !isset($k['karte']['geometrie']));
FakeCloud::$anfragen = [];
FakeCloud::$karte = strtr(base64_encode(gzcompress($kopfB . $pix . json_encode(['seg_inf' => $segsB]))), '+/', '-_');
FakeCloud::$props['6.8'] = json_encode(['object_name' => 'ali_dreame/123/map']);
$t = $geraet->RaeumeLaden();
check('(gespeicherte Karte mit Bild wurde geladen)', strpos($t, '✅') === 0, $t);
FakeCloud::$karte = $klar;
check('Räume laden während der Live-Karte überschreibt sie nicht', isset(kachelJson($geraet->GetVisualizationTile())['karte']['weg']));
// Echtzeit-Rahmen 6.1 bewegt den Roboter (x 25 mm → linke Spalte, y 125 mm → zweite Zeile von oben).
$pKopf2 = pack('v', 1) . pack('v', 8) . chr(80) . pack('v', 25) . pack('v', 125) . pack('v', 180) . str_repeat("\0", 16);
mqttVomServer($konto, RoboHubMqttTest::publish($topic, json_encode(['did' => '-112663880', 'data' => ['method' => 'properties_changed', 'params' => [['siid' => 6, 'piid' => 1, 'value' => strtr(base64_encode(gzcompress($pKopf2 . '{}')), '+/', '-_')]]]]), 0, 0));
$k = kachelJson($geraet->GetVisualizationTile());
check('Echtzeit-Kartenrahmen versetzt den Roboter sofort', ($k['karte']['roboter'] ?? null) == ['x' => 0.0, 'y' => 25.0, 'winkel' => 180], json_encode($k['karte']['roboter'] ?? null));
check('… und landet nicht im Schnappschuss (kein Gerätewert)', strpos($konto->ReadAttributeString('Schnappschuss'), '_position') === false);
check('… und landet nicht im Echtzeit-Verlauf', strpos($geraet->ReadAttributeString('EchtzeitVerlauf'), '"werte":[]') === false);
// Ende der Reinigung → ein letztes Mal laden, Takt aus.
FakeCloud::$anfragen = [];
FakeCloud::$props['2.1'] = 13;
$geraet->Aktualisieren();
$anf = array_filter(FakeCloud::befehle(), function ($b) { return $b['method'] === 'action' && $b['params']['siid'] === 6; });
check('Reinigung fertig → fertiger Weg ein letztes Mal geholt, Takt aus', count($anf) === 1 && ($geraet->timers['LiveKarte'] ?? -1) === 0);
FakeCloud::$anfragen = [];
$geraet->Aktualisieren();
check('… danach keine weiteren Anforderungen', count(array_filter(FakeCloud::befehle(), function ($b) { return $b['method'] === 'action'; })) === 0);
check('fertiger Weg bleibt bis zur nächsten Arbeit stehen', isset(kachelJson($geraet->GetVisualizationTile())['karte']['weg']));
// Live-Befund Barny: nach Auftragsende kommt der Rahmen ohne Weg — der alte Weg bleibt.
$wegVorher = kachelJson($geraet->GetVisualizationTile())['karte']['weg'];
FakeCloud::$liveOut = [['piid' => 1, 'value' => strtr(base64_encode(gzcompress($lKopf . $pix . json_encode(['seg_inf' => $segsB]))), '+/', '-_')]];
$geraet->LiveKarteLaden();
check('Rahmen ohne Weg nach Auftragsende → fertiger Weg bleibt stehen', count($wegVorher) > 0 && kachelJson($geraet->GetVisualizationTile())['karte']['weg'] == $wegVorher);
$geraet->WriteAttributeBoolean('LiveAktiv', true);   // neue Arbeit beginnt: leerer Weg ist richtig
$geraet->LiveKarteLaden();
check('… während der Arbeit ersetzt ein leerer Weg den alten (neuer Auftrag)', (kachelJson($geraet->GetVisualizationTile())['karte']['weg'] ?? null) === []);
$geraet->WriteAttributeBoolean('LiveAktiv', false);
FakeCloud::$liveOut = [['piid' => 1, 'value' => strtr(base64_encode(gzcompress($lKopf . $pix . $lJson)), '+/', '-_')]];
check('Kartentext direkt in der Antwort (6.1) wird ebenfalls genommen', strpos($geraet->LiveKarteLaden(), '✅') === 0);
// Live-Befund Barny: Live-Rahmen ohne seg_inf → Namen aus der gespeicherten Raumliste, Räume trotzdem farbig.
FakeCloud::$liveOut = [['piid' => 1, 'value' => strtr(base64_encode(gzcompress($lKopf . $pix . json_encode(['tr' => 'M75,25L100,0']))), '+/', '-_')]];
$geraet->LiveKarteLaden();
$k = kachelJson($geraet->GetVisualizationTile());
$namenLive = array_map(function ($m) { return $m['name']; }, $k['karte']['marken'] ?? []);
sort($namenLive);
check('Live-Rahmen ohne seg_inf: Namen aus der gespeicherten Karte', $namenLive === ['Bad', 'Küche'], json_encode($namenLive, JSON_UNESCAPED_UNICODE));
check('… Räume farbig (Palette mit Raumfarben)', strlen(pngLesen($k['karte']['bild'])['plte'] ?? '') >= 3 * 6);
$ohneNamen = RoboHubKartenbild::sauger(['json' => []] + $rohKarte, []);
check('Räume ganz ohne Namen bekommen trotzdem eigene Farben', ($pz = pngLesen($ohneNamen['bild'])) && $pz['zeilen'][2 * intdiv($pz['b'], 4)][0] >= 4 && $ohneNamen['marken'] === []);
FakeCloud::$liveOut = [];
check('keine Karte geliefert → klare Meldung, alte bleibt', strpos($geraet->LiveKarteLaden(), '⛔') === 0 && isset(kachelJson($geraet->GetVisualizationTile())['karte']['weg']));
$geraet->props['LiveKarte'] = false;
FakeCloud::$props['2.1'] = 1;
FakeCloud::$anfragen = [];
$geraet->Aktualisieren();
check('„Live-Karte" aus → keine Anforderung trotz Reinigung', count(array_filter(FakeCloud::befehle(), function ($b) { return $b['method'] === 'action'; })) === 0);
$geraet->props['LiveKarte'] = true;
FakeCloud::$props['2.1'] = 13;
$geraet->Aktualisieren();

// Mäher: Mähweg aus M_PATH, Position per Echtzeit 1.4.
$pfadM = json_encode([[50, 50], [900, 50], [900, 450], null, [100, 100], [200, 100]]);
FakeCloud::$geraeteDaten = ['MAP.0' => json_encode([$slotM]), 'MAP.info' => (string)strlen(json_encode([$slotM])), 'M_PATH.0' => $pfadM, 'M_PATH.info' => (string)strlen($pfadM)];
FakeCloud::$props['2.1'] = 19;
FakeCloud::$cfg = null;
$maeher->Aktualisieren();
$k = kachelJson($maeher->GetVisualizationTile());
check('Mäher mäht → Live-Karte mit Mähweg (2 Stücke), Takt 60 s', count($k['karte']['weg'] ?? []) === 2 && ($maeher->timers['LiveKarte'] ?? 0) === 60000, json_encode($k['karte']['weg'] ?? null));
$maeher->ReceiveData(json_encode(['Geraet' => '777', 'Daten' => ['werte' => [], 'optionen' => [], 'echtzeit' => [], 'position' => [5000, 2500, 90]]]));
$k = kachelJson($maeher->GetVisualizationTile());
check('Echtzeit-Position des Mähers (Mitte des Rasens) auf der Karte', ($k['karte']['roboter'] ?? null) == ['x' => 50.0, 'y' => 50.0, 'winkel' => 90], json_encode($k['karte']['roboter'] ?? null));
FakeCloud::$props['2.1'] = 13;
$maeher->Aktualisieren();

abschnitt('Zeitpläne');
// Beispiele aus ioBroker lib/schedule.js (Live-Messpunkte dort): Raum-Termin, Alle Räume, Kurzbefehl.
$plan = '5-1-07:00-0101010-1-1-0-0-1126240517;2-0-22:30-1111111-1-1-20-297795617-0;3-3-06:00-0000000-0-1-0-512-0';
$tp = RoboHubDreame::zeitplaeneLesen($plan);
check('drei Termine, nach Uhrzeit sortiert', array_column($tp, 'zeit') === ['06:00', '07:00', '22:30'], json_encode(array_column($tp, 'zeit')));
$raumT = $tp[1];
check('Raum-Termin: an, Mo/Mi/Fr (Maske ab Sonntag)', $raumT['an'] === true && $raumT['tage'] === ['Mo', 'Mi', 'Fr'] && $raumT['einmalig'] === false, json_encode($raumT));
check('Raum-Termin: Wort 0x43211105 → Raum 5, 1 Durchgang, Standard, Saugen und Wischen', $raumT['art'] === 'raeume' && $raumT['raeume'] === [['id' => 5, 'durchgaenge' => 1, 'saugstufe' => 'Standard', 'modus' => 'Saugen und Wischen']], json_encode($raumT['raeume'] ?? null));
check('Alle Räume: aus, täglich, 0x11c00021 → Saugen und Wischen, Standard, 1 Durchgang', $tp[2]['art'] === 'alle' && $tp[2]['an'] === false && count($tp[2]['tage']) === 7 && $tp[2]['modus'] === 'Saugen und Wischen' && $tp[2]['saugstufe'] === 'Standard' && $tp[2]['durchgaenge'] === 1, json_encode($tp[2]));
check('Kurzbefehl 512/256 = 2, Zustand 3 = ungültig, Feld 4 „0" = einmalig', $tp[0]['art'] === 'kurzbefehl' && $tp[0]['kurzbefehl'] === 2 && $tp[0]['ungueltig'] === true && $tp[0]['an'] === false && $tp[0]['einmalig'] === true);
check('leer / Müll → keine Termine statt Absturz', RoboHubDreame::zeitplaeneLesen('') === [] && RoboHubDreame::zeitplaeneLesen('kaputt;1-2') === []);
check('Alle Räume: Modus 3 = Wischen (eigene Reihenfolge, nicht die der Raum-Termine)', RoboHubDreame::zeitplaeneLesen('4-1-09:00-1111111-1-1-20-297795633-0')[0]['modus'] === 'Wischen');
check('Zustand 2 zählt als an (Tasshack)', RoboHubDreame::zeitplaeneLesen('9-2-08:00-1111111-1-1-0-0-1126240517')[0]['an'] === true);

FakeCloud::$props['8.2'] = $plan;
FakeCloud::$props['2.1'] = 13;
$geraet->Aktualisieren();
$namenR = json_decode($geraet->ReadAttributeString('Raeume'), true);
$raum5 = $namenR['5'] ?? 'Raum 5';
$zv = (string)(variable(2000, 'Zeitplaene')['value'] ?? '');
check('Variable „Zeitpläne" in Klartext mit Raumnamen', strpos($zv, '✅ 07:00 · Mo, Mi, Fr · ' . $raum5 . ' · Saugen und Wischen') !== false && strpos($zv, '⏸️ 22:30 · täglich · alle Räume · Saugen und Wischen') !== false && strpos($zv, '06:00 · einmalig · Kurzbefehl 2 · ⚠️ ungültig') !== false, $zv);
$k = kachelJson($geraet->GetVisualizationTile());
check('Werktage Mo–Fr als „werktags"', strpos((function () use ($geraet) { $r = new ReflectionMethod('RoboHubGeraet', 'zeitplanText'); return $r->invoke($geraet, ['zeit' => '08:00', 'tage' => ['Mo', 'Di', 'Mi', 'Do', 'Fr'], 'art' => 'alle']); })(), '08:00 · werktags · alle Räume') === 0);
check('Kachel: drei Zeitpläne, ungültiger nicht schaltbar', count($k['zeitplaene'] ?? []) === 3 && $k['zeitplaene'][0]['schaltbar'] === false && $k['zeitplaene'][1]['an'] === true, json_encode($k['zeitplaene'] ?? null, JSON_UNESCAPED_UNICODE));
$formKlar = json_encode(json_decode($geraet->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
check('Formular: Panel „Zeitpläne (3)" mit Schaltknöpfen', strpos($formKlar, 'Zeitpläne (3)') !== false && strpos($formKlar, 'ROBO_ZeitplanSchalten($id, 2, true)') !== false && strpos($formKlar, 'ROBO_ZeitplanSchalten($id, 5, false)') !== false);

FakeCloud::$anfragen = [];
// Inzwischen in der App geändert (Termin 5 auf 07:30) — Schalten darf das nicht überschreiben.
FakeCloud::$props['8.2'] = str_replace('5-1-07:00-', '5-1-07:30-', $plan);
$t = $geraet->ZeitplanSchalten(2, true);
check('Einschalten: 8.2 frisch gelesen, nur Feld 1 von Termin 2 geändert (App-Änderung bleibt)', (gesetzt()['8.2'] ?? '') === '5-1-07:30-0101010-1-1-0-0-1126240517;2-1-22:30-1111111-1-1-20-297795617-0;3-3-06:00-0000000-0-1-0-512-0' && strpos($t, '📤') === 0, (gesetzt()['8.2'] ?? '') . ' ' . $t);
FakeCloud::$props['8.2'] = $plan;
FakeCloud::$anfragen = [];
$geraet->RequestAction('Zeitplan', '5:0');
check('Kachel „5:0" schaltet Termin 5 aus', strpos((string)(gesetzt()['8.2'] ?? ''), '5-0-07:00-') === 0);
FakeCloud::$anfragen = [];
$t = $geraet->ZeitplanSchalten(3, true);
check('ungültiger Termin → abgelehnt, nichts geschrieben', strpos($t, '⛔') === 0 && gesetzt() === [], $t);
FakeCloud::$anfragen = [];
$t = $geraet->ZeitplanSchalten(9, true);
check('unbekannter Termin → abgelehnt, nichts gesendet', strpos($t, '⛔') === 0 && count(FakeCloud::befehle()) === 0);
check('Treiber prüft selbst: Termin weg (inzwischen in der App gelöscht)', strpos(json_decode($konto->ForwardData(json_encode(['Aktion' => 'befehl', 'Geraet' => '-112663880', 'Befehl' => 'zeitplan', 'Parameter' => ['id' => 7, 'an' => true]])), true)['fehler'] ?? '', 'nicht (mehr)') !== false);
$geraet->props['WebBedienung'] = false;
FakeCloud::$anfragen = [];
$j = json_decode(hook($geraet, ['cmd' => 'Zeitplan', 'wert' => '2:1', 'key' => $geraet->ReadAttributeString('WebSchluessel')]), true);
check('Seite: Zeitplan ohne Freigabe abgelehnt', $j['ok'] === false && count(FakeCloud::befehle()) === 0);
$geraet->props['WebBedienung'] = true;
$j = json_decode(hook($geraet, ['cmd' => 'Zeitplan', 'wert' => '2:1', 'key' => $geraet->ReadAttributeString('WebSchluessel')]), true);
check('Seite: Zeitplan mit Freigabe + Schlüssel', $j['ok'] === true && isset(gesetzt()['8.2']), json_encode($j, JSON_UNESCAPED_UNICODE));
$geraet->props['WebBedienung'] = false;
$_GET = [];
FakeCloud::$props['8.2'] = '';
$geraet->Aktualisieren();
check('alle Termine in der App gelöscht → „keine", Kachel leer', variable(2000, 'Zeitplaene')['value'] === 'keine' && kachelJson($geraet->GetVisualizationTile())['zeitplaene'] === []);
unset(FakeCloud::$props['8.2']);

// Mäher: Plan-Dokument nach EvotecIT (Mo+Mi 09:00–12:00 alles, wiederkehrend; Sa 10:00–11:00 Zone 1).
$tb = [0x18, 0x1C, 0x02, 45, 0xFF, 0x0F, 0,   0x38, 0x1C, 0x02, 45, 0xFF, 0x0F, 0,   0x69, 0x58, 0x42, 41, 0xFF, 0x1F, 0, 1];
FakeCloud::$maehplan = json_encode(['d' => [[3, 0, 'Sommer', base64_encode(pack('C*', ...$tb))], [4, 1, '', ''], [9, 0, str_repeat('Lang', 50), '']]]);
FakeCloud::$kanal = [];
$maeher->RaeumeLaden();
$mp = json_decode($maeher->ReadAttributeString('Zeitplaene'), true);
check('Mäher-Pläne: Dokument in 100-Byte-Stücken gelesen (SCHDIV2 → SCHDDV2 …)', strlen(FakeCloud::$maehplan) > 200 && in_array('SCHDIV2', FakeCloud::$kanal, true) && count(array_keys(FakeCloud::$kanal, 'SCHDDV2')) === (int)ceil(strlen(FakeCloud::$maehplan) / 100), json_encode(FakeCloud::$kanal));
check('Mäher-Pläne: drei Pläne (Platz −1 mit Version 0xFFFF = leer), „Sommer" aus, Plan 4 an', count($mp) === 3 && $mp[0]['id'] === 3 && $mp[0]['an'] === false && $mp[0]['name'] === 'Sommer' && $mp[1]['an'] === true, json_encode($mp));
check('Termine: Mo 09:00–12:00 alles, Mi gleich, Sa 10:00–11:00 Zone 1', ($mp[0]['eintraege'] ?? null) == [
    ['tagNr' => 1, 'tag' => 'Mo', 'art' => 'alle', 'von' => '09:00', 'bis' => '12:00', 'zonen' => []],
    ['tagNr' => 3, 'tag' => 'Mi', 'art' => 'alle', 'von' => '09:00', 'bis' => '12:00', 'zonen' => []],
    ['tagNr' => 6, 'tag' => 'Sa', 'art' => 'zonen', 'von' => '10:00', 'bis' => '11:00', 'zonen' => [1]],
], json_encode($mp[0]['eintraege'] ?? null));
$zone1 = json_decode($maeher->ReadAttributeString('Raeume'), true)['1'] ?? 'Zone 1';
$mv = (string)(variable(2100, 'Zeitplaene')['value'] ?? '');
check('Variable: „⏸️ Plan „Sommer" · Mo, Mi 09:00–12:00 · alles; Sa 10:00–11:00 · <Zone 1>"', strpos($mv, '⏸️ Plan „Sommer" · Mo, Mi 09:00–12:00 · alles; Sa 10:00–11:00 · ' . $zone1) === 0 && strpos($mv, '✅ Plan 5 · ohne Termine') !== false, $mv);
$k = kachelJson($maeher->GetVisualizationTile());
check('Mäh-Pläne auf der Kachel, aber (noch) nicht schaltbar', count($k['zeitplaene']) === 3 && $k['zeitplaene'][0]['schaltbar'] === false);
check('Rohtext in „📋 Rohdaten"', strpos($maeher->Rohdaten(), 'Sommer') !== false);
check('Mäher-Plan schalten → abgelehnt (noch nicht unterstützt)', strpos($maeher->ZeitplanSchalten(3, true), '⛔') === 0);
$gerahmt = RoboHubDreame::maeherPlaeneLesen(json_encode(['d' => [[1, 1, 'R', base64_encode(pack('C*', 0xAA, 8, 0x21, 0x1C, 0x02, 0, 0, 0xED))]]]));
check('gerahmte Termine (0xAA … 0xED): Tag + Start, kein Ende', ($gerahmt[0]['eintraege'][0] ?? null) == ['tagNr' => 2, 'tag' => 'Di', 'art' => 'zonen', 'von' => '09:00', 'bis' => null, 'zonen' => [0]], json_encode($gerahmt[0]['eintraege'] ?? null));
check('kaputtes Dokument → keine Pläne statt Absturz', RoboHubDreame::maeherPlaeneLesen('{"d":[[1,1,"x","%%%"]]}')[0]['eintraege'] === [] && RoboHubDreame::maeherPlaeneLesen('Müll') === []);
// MOVA-Tabellenform (Live-Befund LiDAX: SCHDIV2/3 → r 3). Plan „Frühling" (Tabelle 0, aus) mit zwei
// Terminen: Mo+Do 08:30 ganze Fläche (an), Fr 18:00 (aus → weggelassen); Tabelle 1 „Abend" an, Zone 1 Sa 19:15.
FakeCloud::$maehplan = '';
FakeCloud::$tabellen = [0 => [[0, 0, 0, 'Frühling', [[1, 3], [2, 1]]], [1, 1, 0, 'Abend', [[5, 2]]]]];
FakeCloud::$tabTermine = ['0/1' => [1, 1, 8, 510, [4, 0, 1], []], '0/2' => [2, 0, 8, 1080, [5], []], '1/5' => [5, 1, 9, 1155, [6], [1]]];
FakeCloud::$kanal = [];
$maeher->RaeumeLaden();
$mt = json_decode($maeher->ReadAttributeString('Zeitplaene'), true);
check('Tabellenform: SCHDI für Platz 0 (Tabellen 0/1), SCHDC je Termin', in_array('SCHDI', FakeCloud::$kanal, true) && count(array_keys(FakeCloud::$kanal, 'SCHDC')) === 3, json_encode(FakeCloud::$kanal));
check('… zwei Pläne: „Frühling" aus mit Mo+Do 08:30, „Abend" an mit Sa 19:15 Zone 1', count($mt) === 2 && $mt[0]['name'] === 'Frühling' && $mt[0]['an'] === false && array_column($mt[0]['eintraege'], 'tag') === ['Mo', 'Do', 'So'] && $mt[0]['eintraege'][0]['von'] === '08:30' && $mt[1]['an'] === true && $mt[1]['eintraege'][0] == ['tagNr' => 6, 'tag' => 'Sa', 'art' => 'zonen', 'von' => '19:15', 'bis' => null, 'zonen' => [1]], json_encode($mt, JSON_UNESCAPED_UNICODE));
check('… ausgeschalteter Termin (Fr 18:00) weggelassen, keine Dokumentabfrage für Platz 0', strpos(json_encode($mt), '18:00') === false && !in_array('SCHDIV2', array_slice(FakeCloud::$kanal, 2), true));
$mv = (string)variable(2100, 'Zeitplaene')['value'];
check('Variable: „⏸️ Plan „Frühling" · Mo, Do, So 08:30 · alles" (Montag zuerst)', strpos($mv, '⏸️ Plan „Frühling" · Mo, Do, So 08:30 · alles') !== false && strpos($mv, '✅ Plan „Abend" · Sa 19:15 · ') !== false, $mv);
check('Termin-Auswertung: falsche Form / aus → nichts', RoboHubDreame::maeherTabellenTermin([1, 1, 0, 2000, [1], []]) === [] && RoboHubDreame::maeherTabellenTermin('x') === [] && RoboHubDreame::maeherTabellenTermin([1, 0, 0, 60, [1], []]) === []);
// Echte Antworten des LiDAX (12.10.2026 01:01): eine belegte Tabelle (aus, täglich 12:00 ganze Fläche),
// drei leere Platzhalter.
FakeCloud::$tabellen = [0 => [[0, 0, 1, '', [[0, 41976294]], 7], [1, 0, 1, '', [], 7]], 1 => [[2, 0, 1, '', [], 7], [3, 0, 1, '', [], 7]]];
FakeCloud::$tabTermine = ['0/0' => [0, 1, 0, 720, [0, 1, 2, 3, 4, 5, 6], []]];
$maeher->RaeumeLaden();
check('LiDAX-Livedaten: genau ein Plan „⏸️ Plan 1 · täglich 12:00 · alles", Platzhalter ausgeblendet', variable(2100, 'Zeitplaene')['value'] === '⏸️ Plan 1 · täglich 12:00 · alles', (string)variable(2100, 'Zeitplaene')['value']);
check('Tabellen-Plan ist schaltbar (Kachel)', (kachelJson($maeher->GetVisualizationTile())['zeitplaene'][0]['schaltbar'] ?? false) === true);
FakeCloud::$props['2.1'] = 13;
FakeCloud::$schds = [];
$GLOBALS['LOG'] = [];
$t = $maeher->ZeitplanSchalten(0, true);
check('Mäh-Plan einschalten: SCHDS [0, 1], bestätigt, neu gelesen', FakeCloud::$schds === [[0, 1]] && strpos($t, '📤') === 0 && variable(2100, 'Zeitplaene')['value'] === '✅ Plan 1 · täglich 12:00 · alles', $t . ' / ' . variable(2100, 'Zeitplaene')['value']);
check('… im Protokoll festgehalten', strpos(implode("\n", $GLOBALS['LOG']), 'Mäh-Plan 1 eingeschaltet') !== false);
FakeCloud::$schds = [];
$maeher->ZeitplanSchalten(0, false);
check('Mäh-Plan ausschalten: SCHDS [0, 0]', FakeCloud::$schds === [[0, 0]] && variable(2100, 'Zeitplaene')['value'] === '⏸️ Plan 1 · täglich 12:00 · alles');
FakeCloud::$props['2.1'] = 19;
FakeCloud::$schds = [];
$t = $maeher->ZeitplanSchalten(0, true);
check('Mäher mäht → Schalten abgelehnt, nichts gesendet', FakeCloud::$schds === [] && strpos($t, '⛔') === 0 && strpos($t, 'ruht') !== false, $t);
FakeCloud::$props['2.1'] = 13;
FakeCloud::$tabTermine['0/0'][1] = 0;     // einziger Termin in der App ausgeschaltet
$t = $maeher->ZeitplanSchalten(0, true);
check('Plan ohne aktiven Termin → Einschalten abgelehnt', FakeCloud::$schds === [] && strpos($t, 'ohne aktiven Termin') !== false, $t);
FakeCloud::$tabTermine['0/0'][1] = 1;
FakeCloud::$schdsWirkt = false;
$t = $maeher->ZeitplanSchalten(0, true);
check('Mäher übernimmt nicht → klare Meldung statt „eingeschaltet"', strpos($t, 'nicht bestätigt') !== false, $t);
FakeCloud::$schdsWirkt = true;
$t = (new ReflectionMethod('RoboHubGeraet', 'zeitplanSchaltbar'))->invoke($maeher, ['art' => 'maeher', 'tabelle' => 0, 'an' => false, 'eintraege' => []]);
// Zweite Karte: Tabelle 2 (= Plan 1 auf Karte 2) — gleiche Plan-Nummer wie Tabelle 0, eigene Kennung.
FakeCloud::$tabellen[1] = [[2, 0, 1, '', [[7, 1]], 7], [3, 0, 1, '', [], 7]];
FakeCloud::$tabTermine['2/7'] = [7, 1, 0, 600, [6], []];
$maeher->RaeumeLaden();
check('zwei Karten: „Plan 1" und „Plan 1 (Karte 2)" getrennt', strpos((string)variable(2100, 'Zeitplaene')['value'], '⏸️ Plan 1 (Karte 2) · Sa 10:00 · alles') !== false, (string)variable(2100, 'Zeitplaene')['value']);
FakeCloud::$schds = [];
$maeher->ZeitplanSchalten(2, true);
check('… Schalten trifft Tabelle 2, nicht Tabelle 0', FakeCloud::$schds === [[2, 1]], json_encode(FakeCloud::$schds));
FakeCloud::$tabellen[1] = [[2, 0, 1, '', [], 7], [3, 0, 1, '', [], 7]];
check('Plan ohne Termine, aus → nicht schaltbar (Ausschalten wäre unnötig, Einschalten unmöglich)', $t === false);
FakeCloud::$tabellen = null;
FakeCloud::$tabTermine = [];
$merkeDaten = FakeCloud::$geraeteDaten;
FakeCloud::$geraeteDaten = ['SETTINGS.0' => '{}'];
FakeCloud::$maehplan = json_encode(['d' => [[5, 1, 'Ohne Karte', '']]]);
$maeher->RaeumeLaden();
check('Pläne kommen auch ohne Karte an', strpos((string)variable(2100, 'Zeitplaene')['value'], 'Ohne Karte') !== false);
FakeCloud::$geraeteDaten = $merkeDaten;
FakeCloud::$maehplan = '';

abschnitt('Einstellungen je Raum');
$cs = ['3' => [1, 3, 2, 2, 2, 0], '5' => [2, 16, 1, 1, 0, 0], 'x' => [1], '7' => [0, 2]];
$re = RoboHubDreame::raumEinstellungenAus(['cleanset' => json_encode($cs), 'customeClean' => 1]);
check('cleanset (auch als Text): Raum 3 Standard, Wasser Mittel, 2×, Saugen und Wischen', ($re['raumEinstellungen'][3] ?? null) == ['saugstufeWert' => 1, 'saugstufe' => 'Standard', 'wasser' => 'Mittel', 'durchgaenge' => 2, 'reihenfolge' => 2, 'modusWert' => 2, 'modus' => 'Saugen und Wischen'], json_encode($re['raumEinstellungen'][3] ?? null, JSON_UNESCAPED_UNICODE));
check('… Raum 5: Feuchte-Stufe 16 (Wert > 4), Stark, Saugen', ($re['raumEinstellungen'][5]['wasser'] ?? '') === 'Feuchte 16' && $re['raumEinstellungen'][5]['saugstufe'] === 'Stark' && $re['raumEinstellungen'][5]['modus'] === 'Saugen');
check('… unvollständige Einträge übergangen, „customeClean" = an', count($re['raumEinstellungen']) === 2 && $re['raumEinstellungenAn'] === true);
$getauscht = RoboHubDreame::raumEinstellungenAus(['cleanset' => $cs], true)['raumEinstellungen'];
check('mit Mopp-Anhebung: Leitung 2 → Saugen, 0 → Saugen und Wischen, 1 bleibt Wischen', $getauscht[3]['modus'] === 'Saugen' && $getauscht[5]['modus'] === 'Saugen und Wischen' && RoboHubDreame::raumEinstellungenAus(['cleanset' => ['1' => [1, 3, 1, 1, 1]]], true)['raumEinstellungen'][1]['modus'] === 'Wischen');
check('ohne cleanset → nichts', RoboHubDreame::raumEinstellungenAus(['seg_inf' => []]) === []);

$segsC = ['3' => ['type' => 4, 'index' => 0], '5' => ['type' => 0, 'name' => base64_encode('Bad')]];
FakeCloud::$props['6.8'] = json_encode(['object_name' => 'ali_dreame/123/map']);
FakeCloud::$karte = strtr(base64_encode(gzcompress($kopfB . $pix . json_encode(['seg_inf' => $segsC, 'cleanset' => $cs, 'customeClean' => 0]))), '+/', '-_');
$geraet->RaeumeLaden();
$rv = (string)(variable(2000, 'RaumEinstellungen')['value'] ?? '');
// Testgerät hat Mopp-Anhebung (4.25 + 27.3) → Leitung 2 = „Saugen", 0 = „Saugen und Wischen" (Live-Befund Barny).
check('Variable in Reihenfolge, Modus getauscht, Wasser nur beim Wischen', strpos($rv, "1. Bad: Saugen und Wischen · Stark · 1× · Feuchte 16\n2. Küche: Saugen · Standard · 2×") !== false, $rv);
check('… in der App ausgeschaltet → Hinweis oben', strpos($rv, '⏸️ In der App ausgeschaltet') === 0);
$k = kachelJson($geraet->GetVisualizationTile());
$kueche = array_values(array_filter($k['raeume'], function ($r) { return $r['name'] === 'Küche'; }))[0] ?? [];
check('Kachel: Raumknopf trägt die Einstellungen (ohne Nummer und Name)', ($kueche['info'] ?? '') === 'Saugen · Standard · 2×', json_encode($kueche, JSON_UNESCAPED_UNICODE));
check('Rohdaten enthalten das cleanset', strpos($geraet->Rohdaten(), '"raumEinstellungenRoh"') !== false);
// Live-Rahmen mit cleanset aktualisiert die Anzeige (z. B. in der App geändert).
$cs2 = $cs;
$cs2['3'][0] = 3;
FakeCloud::$liveOut = [['piid' => 1, 'value' => strtr(base64_encode(gzcompress($lKopf . $pix . json_encode(['cleanset' => $cs2, 'customeClean' => 1]))), '+/', '-_')]];
$geraet->LiveKarteLaden();
$rv = (string)variable(2000, 'RaumEinstellungen')['value'];
check('Live-Karte bringt neue Einstellungen (Küche jetzt Turbo, Schalter an)', strpos($rv, '2. Küche: Saugen · Turbo') !== false && strpos($rv, '⏸️') === false, $rv);
// Ändern: frischer Live-Rahmen, ein Wert, alle Räume zurück (ohne Reihenfolge-Index 3).
function customeClean(): ?array
{
    foreach (FakeCloud::befehle() as $b) {
        if ($b['method'] === 'action' && $b['params']['siid'] === 6 && $b['params']['aiid'] === 2) {
            return json_decode($b['params']['in'][0]['value'], true)['customeClean'] ?? null;
        }
    }
    return null;
}
$csLive = ['3' => [1, 3, 2, 2, 2, 546, 0, 2, 0], '5' => [2, 16, 1, 1, 0, 0], '7' => [0, 2]];
FakeCloud::$liveOut = [['piid' => 1, 'value' => strtr(base64_encode(gzcompress($lKopf . $pix . json_encode(['cleanset' => $csLive, 'customeClean' => 1]))), '+/', '-_')]];
FakeCloud::$props['2.1'] = 13;
$geraet->LiveKarteLaden();
// Schreibweg im Treiber (aus der Instanz gesperrt, Live-Befund: App nutzt diese Werte nicht).
function raumSetzen($konto, int $raum, string $feld, int $wert): array
{
    return json_decode($konto->ForwardData(json_encode(['Aktion' => 'befehl', 'Geraet' => '-112663880', 'Befehl' => 'raumeinstellung', 'Parameter' => ['raum' => $raum, 'feld' => $feld, 'wert' => $wert]])), true);
}
FakeCloud::$anfragen = [];
$j = raumSetzen($konto, 3, 'Saugstufe', 2);
check('Treiber: Saugstufe → action 6.2 piid 4, alle gültigen Räume, ohne Reihenfolge', customeClean() === [[3, 2, 3, 2, 2, 546, 0, 2, 0], [5, 2, 16, 1, 0, 0]] && $j['ok'] === true, json_encode(customeClean()));
FakeCloud::$anfragen = [];
raumSetzen($konto, 5, 'Modus', 0);
check('Treiber: Modus „Saugen" bei Mopp-Anhebung → Leitung 2', (customeClean()[1][4] ?? null) === 2, json_encode(customeClean()));
FakeCloud::$anfragen = [];
raumSetzen($konto, 3, 'Durchgaenge', 3);
check('Treiber: Durchgänge → Index 3 der Sendeliste', (customeClean()[0][3] ?? null) === 3);
FakeCloud::$anfragen = [];
check('Treiber: Durchgänge 4 / unbekanntes Feld → abgelehnt', raumSetzen($konto, 3, 'Durchgaenge', 4)['ok'] === false && raumSetzen($konto, 3, 'Wasser', 1)['ok'] === false && customeClean() === null);
FakeCloud::$props['2.1'] = 1;
check('Treiber: Roboter reinigt → abgelehnt', strpos(raumSetzen($konto, 3, 'Saugstufe', 0)['fehler'] ?? '', 'nicht reinigt') !== false && customeClean() === null);
FakeCloud::$props['2.1'] = 13;
$t = $geraet->RaumEinstellungSetzen(3, 'Saugstufe', 2);
check('Instanz: Ändern der gespeicherten Raumwerte gesperrt, nichts gesendet', strpos($t, '⛔') === 0 && strpos($t, 'gesperrt') !== false && customeClean() === null, $t);
$geraet->Aktualisieren();
$formKlar = json_encode(json_decode($geraet->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE);
check('Formular: „Raum-Einstellungen im Roboter" nur Anzeige (keine Auswahlfelder)', strpos($formKlar, 'Raum-Einstellungen im Roboter (2)') !== false && strpos($formKlar, '"onChange":"echo ROBO_RaumEinstellungSetzen') === false && strpos($formKlar, 'RaeumeReinigenMit') !== false);
check('Variable heißt „Raum-Einstellungen im Roboter"', variable(2000, 'RaumEinstellungen')['name'] === 'Raum-Einstellungen im Roboter');
$rid = IPS_GetObjectIDByIdent('RaumEinstellungen', 2000);
$GLOBALS['OBJ'][$rid]['name'] = 'Einstellungen je Raum';      // Stand aus 0.10/0.11
$geraet->LiveKarteLaden();
check('alter Standardname wird ersetzt (MaintainVariable allein benennt nicht um)', variable(2000, 'RaumEinstellungen')['name'] === 'Raum-Einstellungen im Roboter');
$GLOBALS['OBJ'][$rid]['name'] = 'Mein Name';
$geraet->LiveKarteLaden();
check('… ein selbst vergebener Name bleibt', variable(2000, 'RaumEinstellungen')['name'] === 'Mein Name');
$GLOBALS['OBJ'][$rid]['name'] = 'Raum-Einstellungen im Roboter';

// Start wie das App-Fenster „benutzerdefiniert": Modus vorher (4.23), Saugstufe + Durchgänge im Startbefehl.
function startSelects(): ?array
{
    foreach (FakeCloud::befehle() as $b) {
        if ($b['method'] === 'action' && $b['params']['siid'] === 4 && $b['params']['aiid'] === 1) {
            return json_decode($b['params']['in'][1]['value'], true)['selects'] ?? null;
        }
    }
    return null;
}
FakeCloud::$props['4.23'] = (5 << 16) | 0;
FakeCloud::$anfragen = [];
$t = $geraet->RaeumeReinigenMit('3,5', 0, 3, 2);
check('Start mit Wahl: Saugstufe Turbo, 2 Durchgänge in den selects', startSelects() === [[3, 2, 3, 2, 1], [5, 2, 3, 2, 1]] && strpos($t, '📤') === 0, json_encode(startSelects()) . ' ' . $t);
check('… Modus „Saugen" vorher gesetzt (4.23, mit Mopp-Tausch → Bits 0–1 = 2)', ((gesetzt()['4.23'] ?? 0) & 3) === 2 && ((gesetzt()['4.23'] ?? 0) >> 16) === 5, (string)(gesetzt()['4.23'] ?? 'nichts'));
FakeCloud::$anfragen = [];
$geraet->RaeumeReinigenMit('3', -1, -1, -1);
check('Start „wie eingestellt": aktuelle Saugstufe, 1 Durchgang, Modus unverändert', startSelects() === [[3, 1, 1, 2, 1]] && !isset(gesetzt()['4.23']), json_encode(startSelects()));
FakeCloud::$anfragen = [];
$geraet->RequestAction('Raeume', '5|-1|2|3');
check('Kachel „5|-1|2|3": Stark, 3 Durchgänge, Modus unverändert', startSelects() === [[5, 3, 2, 2, 1]] && !isset(gesetzt()['4.23']), json_encode(startSelects()));
FakeCloud::$anfragen = [];
check('ungültige Startwerte → abgelehnt, nichts gestartet', strpos($geraet->RaeumeReinigenMit('3', 0, 9, 1), '⛔') === 0 && startSelects() === null);
$geraet->props['WebBedienung'] = true;
FakeCloud::$anfragen = [];
$j = json_decode(hook($geraet, ['cmd' => 'Raeume', 'wert' => '3|1|0|1', 'key' => $geraet->ReadAttributeString('WebSchluessel')]), true);
check('Seite: Start mit Wahl über den Schlüssel', $j['ok'] === true && startSelects() === [[3, 1, 0, 2, 1]], json_encode($j, JSON_UNESCAPED_UNICODE));
$geraet->props['WebBedienung'] = false;
$_GET = [];

// Kartenobjekt hinter 6.3 (ioBroker): Base64 + zlib, JSON mit cleanset = Stand der App.
check('Kartenobjekt lesen: Base64/zlib, JSON ab „{"', RoboHubDreame::kartenObjektLesen(strtr(base64_encode(gzcompress('xy{"cleanset":"{}","a":1}')), '+/', '-_')) === ['cleanset' => '{}', 'a' => 1]);
check('… verschlüsselt mit Schlüssel aus dem Namen', RoboHubDreame::kartenObjektLesen(base64_encode(openssl_encrypt(gzcompress('{"cleanset":"{}"}'), 'aes-256-cbc', substr(hash('sha256', 'K1'), 0, 32), OPENSSL_RAW_DATA, str_repeat("\0", 16))), 'K1') === ['cleanset' => '{}']);
check('… Müll → null', RoboHubDreame::kartenObjektLesen('%%%') === null);
$csApp = ['3' => [0, 3, 1, 2, 2, 0], '5' => [2, 16, 1, 1, 0, 0]];   // App: Küche „Leise"
FakeCloud::$props['6.3'] = 'obj/2066117758/0';
FakeCloud::$objekt = strtr(base64_encode(gzcompress(json_encode(['cleanset' => json_encode($csApp), 'customeClean' => 1]))), '+/', '-_');
FakeCloud::$karte = strtr(base64_encode(gzcompress($kopfB . $pix . json_encode(['seg_inf' => $segsC]))), '+/', '-_');
$geraet->RaeumeLaden();
check('Raum-Einstellungen aus dem Kartenobjekt (App-Stand: Küche Leise)', strpos((string)variable(2000, 'RaumEinstellungen')['value'], '2. Küche: Saugen · Leise') !== false, (string)variable(2000, 'RaumEinstellungen')['value']);
check('… Quelle in den Rohdaten', strpos($geraet->Rohdaten(), '"kartenobjekt"') !== false);
$geraet->LiveKarteLaden();
check('veralteter Live-Rahmen (Küche Stark) überschreibt den App-Stand nicht', strpos((string)variable(2000, 'RaumEinstellungen')['value'], '2. Küche: Saugen · Leise') !== false && strpos($geraet->Rohdaten(), '"liveKarte"') !== false);
// Echtzeit-Meldung 6.3 (App-Änderung) → Objekt nachladen (ioBroker _cleansetNachladenPruefen).
$csApp2 = ['3' => [3, 3, 1, 2, 2, 0], '5' => [2, 16, 1, 1, 0, 0]];   // App: Küche Turbo
FakeCloud::$objekt = strtr(base64_encode(gzcompress(json_encode(['cleanset' => json_encode($csApp2), 'customeClean' => 1]))), '+/', '-_');
FakeCloud::$anfragen = [];
mqttVomServer($konto, RoboHubMqttTest::publish($topic, json_encode(['did' => '-112663880', 'data' => ['method' => 'properties_changed', 'params' => [['siid' => 6, 'piid' => 3, 'value' => 'obj/2066117758/0']]]]), 0, 0));
check('Echtzeit 6.3 → Kartenobjekt nachgeladen, Küche jetzt Turbo', strpos((string)variable(2000, 'RaumEinstellungen')['value'], '2. Küche: Saugen · Turbo') !== false && FakeCloud::letzte('download.example/obj') !== null, (string)variable(2000, 'RaumEinstellungen')['value']);
check('… gemeldeter Name direkt benutzt (keine 6.3-Abfrage nötig)', json_decode(FakeCloud::letzte('getDownloadUrl')['body'], true)['filename'] === 'obj/2066117758/0');
FakeCloud::$props['2.1'] = 1;
$geraet->Aktualisieren();
FakeCloud::$anfragen = [];
mqttVomServer($konto, RoboHubMqttTest::publish($topic, json_encode(['did' => '-112663880', 'data' => ['method' => 'properties_changed', 'params' => [['siid' => 6, 'piid' => 3, 'value' => 'obj/2066117758/0']]]]), 0, 0));
check('… während der Reinigung nicht (ständig neue Objekte)', FakeCloud::letzte('download.example/obj') === null);
FakeCloud::$props['2.1'] = 13;
$geraet->Aktualisieren();
$check63 = (new FakeCloud('dreame'))->pushLesen(json_encode(['did' => '9', 'data' => ['method' => 'properties_changed', 'params' => [['siid' => 6, 'piid' => 3, 'value' => 'a/b/0']]]]));
check('pushLesen: 6.3 als _objekt, nicht als Gerätewert', $check63 === ['9' => ['_objekt' => 'a/b/0']], json_encode($check63));
check('Rohdaten: Aufbau der Kartendatei für die Fehlersuche', strpos($geraet->Rohdaten(), 'Kartendatei') !== false || strpos($geraet->Rohdaten(), 'Echtzeit') !== false);
unset(FakeCloud::$props['6.3']);
FakeCloud::$karte = $klar;
FakeCloud::$liveOut = [];

abschnitt('Konto-Erkennung nach Modul-Update');
$geraet->elternMeldung = false;   // Symcon meldet kurz nach dem Update „kein aktiver Parent"
FakeCloud::$anfragen = [];
check('HasActiveParent() fälschlich „nein", Konto aber aktiv → Abruf klappt trotzdem', strpos($geraet->Aktualisieren(), '✅') === 0);
$merkeStatus = $konto->status;
$konto->status = 104;
check('Konto wirklich nicht aktiv → klare Meldung', strpos($geraet->Aktualisieren(), 'Keine aktive RoboHub-Konto-Instanz') !== false);
$konto->status = $merkeStatus;
$geraet->elternMeldung = null;

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

require __DIR__ . '/test-roborock.inc.php';
require __DIR__ . '/test-worx.inc.php';
require __DIR__ . '/test-ecovacs.inc.php';
require __DIR__ . '/test-irobot.inc.php';
require __DIR__ . '/test-husqvarna.inc.php';
require __DIR__ . '/test-gardena.inc.php';
require __DIR__ . '/test-navimow.inc.php';
require __DIR__ . '/test-indego.inc.php';
require __DIR__ . '/test-narwal.inc.php';
require __DIR__ . '/test-stihl.inc.php';

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
// Korrigierte Verbundregel (SUITE.md Stolperstein 9, 18.10.2026): ColorValue/ColorActive nur in Wertanzeige-OPTIONS,
// in Aufzählungen ein Mobile-App-Absturz. Je Darstellung prüft die MaintainVariable-Nachbildung; hier: nur in presAnzeige().
check('ColorValue/ColorActive nur in der Wertanzeige (presAnzeige), nie in Aufzählungen', substr_count($quelltext, "'ColorValue'") === 1 && substr_count($quelltext, "'ColorActive'") === 1
    && preg_match("/function presAnzeige[^}]*'ColorActive' => false, 'ColorValue' => -1/s", $quelltext) === 1);
check('Zustand: Wertanzeige mit Intervallen (Konstante je Wert), keine OPTIONS — Symcon-Doku: OPTIONS nur Boolean/String', isset(variable(2100, 'Zustand')['pres']['INTERVALS'])
    && !isset(variable(2100, 'Zustand')['pres']['OPTIONS']) && variable(2100, 'Zustand')['pres']['INTERVALS_ACTIVE'] === true);
check('kein Sys_GetURLContentEx (kann kein POST)', !preg_match('/Sys_GetURLContentEx\s*\(/', $quelltext));
check('keine Mojibake-Umlaute', strpos($quelltext, 'Ã') === false);
check('Kontakt-E-Mail nur im Lizenz-Panel (einmal)', substr_count($quelltext, 'dietmar@gureth.eu') === 1);

// Store-Review (SUITE.md Checkliste 15): Modulklassen loggen nur über $this->LogMessage.
$quellen = '';
foreach (array_merge(glob(__DIR__ . '/../*/module.php'), glob(__DIR__ . '/../libs/*.php')) as $datei) {
    $quellen .= file_get_contents($datei);
}
$ohneAktion = [];
foreach ($GLOBALS['OBJ'] as $o) {
    if (is_array($o['pres'] ?? null) && ($o['pres']['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_ENUMERATION && empty($o['action'])) {
        $ohneAktion[] = $o['ident'];
    }
}
check('Aufzählung nur bei Variablen mit Aktion (Symcon: „nur für Variablen mit einer Variablenaktion", Forum Thilo 25.10.2026) — sonst Wertanzeige mit Optionen', $ohneAktion === [], implode(', ', array_unique($ohneAktion)));
$sonder = ['Befehl', 'Raum', 'Raeume', 'Zuruecksetzen', 'Zeitplan', 'Zuordnen'];
check('kein Modell-Feld heißt wie eine Sonder-Aktion des Roboters (sonst schluckt RequestAction die Einstellung)', array_intersect(array_keys(RoboHubModell::FELDER), $sonder) === []);
check('Darstellungen nur mit Parametern der Symcon-Doku (OPTIONS je Darstellung: Aufzählung mit Color, Wertanzeige mit ColorActive/ColorValue; DIGITS nur bei Zahlen)', empty($GLOBALS['OPTIONS_FEHLER']), implode(', ', array_unique($GLOBALS['OPTIONS_FEHLER'] ?? [])));
check('kein IPS_LogMessage in den Modulen', strpos($quellen, 'IPS_LogMessage') === false);
check('Fehler als Warnung, Erfolge als Meldung protokolliert', in_array(KL_WARNING, $GLOBALS['LOGSTUFE'] ?? [], true) && in_array(KL_MESSAGE, $GLOBALS['LOGSTUFE'] ?? [], true));

echo "\n" . ($fails === 0 ? '✅ Alle Prüfungen bestanden.' : "❌ $fails Prüfung(en) fehlgeschlagen.") . "\n";
exit($fails === 0 ? 0 : 1);
