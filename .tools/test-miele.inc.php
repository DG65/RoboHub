<?php
/**
 * Miele-Scout-Teil des Prüfstands (von test-robohub.php eingebunden).
 * Nach ioBroker.mielecloudservice und astrandb/miele (beide MIT).
 */

declare(strict_types=1);

class FakeMiele extends RoboHubMiele
{
    public static $anfragen = [];
    public static $zustand = [];
    public static $erlaubt = [1, 2, 3];
    public static $gueltig = 'M1';
    public static $refresh = 'MR1';
    public static $runde = 1;

    public static function puts(): array
    {
        return array_values(array_map(function ($a) { return json_decode($a['body'], true); }, array_filter(self::$anfragen, function ($a) { return $a['methode'] === 'PUT'; })));
    }

    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        self::$anfragen[] = ['methode' => $methode, 'url' => $url, 'headers' => $headers, 'body' => (string)$body];
        if ($url === 'https://api.mcs3.miele.com/thirdparty/token/') {
            parse_str((string)$body, $f);
            if (($f['client_id'] ?? '') !== 'miele-id' || ($f['client_secret'] ?? '') !== 'MIELE-GEHEIM') {
                return [401, '{"error":"invalid_client","error_description":"Client credentials are not valid"}'];
            }
            $ok = ($f['grant_type'] === 'password' && ($f['username'] ?? '') === 'scout@example.com' && ($f['password'] ?? '') === 'Sauber!' && ($f['vg'] ?? '') === 'de-AT')
                || ($f['grant_type'] === 'refresh_token' && ($f['refresh_token'] ?? '') === self::$refresh);
            if (!$ok) {
                return [401, '{"error":"invalid_grant"}'];
            }
            self::$runde++;
            self::$gueltig = 'M' . self::$runde;
            self::$refresh = 'MR' . self::$runde;
            return [200, json_encode(['access_token' => self::$gueltig, 'refresh_token' => self::$refresh, 'token_type' => 'Bearer', 'expires_in' => 2592000])];
        }
        if (strpos(implode("\n", $headers), 'Authorization: Bearer ' . self::$gueltig) === false) {
            return [401, '{"message":"Unauthorized"}'];
        }
        if ($methode === 'GET' && $url === 'https://api.mcs3.miele.com/v1/devices?language=de') {
            return [200, json_encode([
                '000123456789' => ['ident' => ['type' => ['value_raw' => 23, 'value_localized' => 'Saugroboter'], 'deviceName' => 'Scouty',
                    'deviceIdentLabel' => ['fabNumber' => '000123456789', 'techType' => 'RX3'], 'xkmIdentLabel' => ['releaseVersion' => '31.17']], 'state' => self::$zustand],
                '000999' => ['ident' => ['type' => ['value_raw' => 1, 'value_localized' => 'Waschmaschine']], 'state' => []],
            ])];
        }
        if ($methode === 'GET' && $url === 'https://api.mcs3.miele.com/v1/devices/000123456789/actions') {
            return [200, json_encode(['processAction' => self::$erlaubt, 'programId' => [1, 2, 3, 4]])];
        }
        if ($methode === 'PUT' && $url === 'https://api.mcs3.miele.com/v1/devices/000123456789/actions') {
            return [204, ''];
        }
        return [404, '{}'];
    }
}

// ===========================================================================
abschnitt('Miele Scout');
$fabrikMi = $GLOBALS['robohubTreiberFabrik'];
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) use ($fabrikMi) {
    return $marke === 'miele' ? new FakeMiele() : $fabrikMi($marke);
};
$mk = new RoboHubKonto(10200);
$mk->props['Hersteller'] = 'miele';
$mk->Create();
$mk->ApplyChanges();
$miSichtbar = [];
$miSuche = function ($k) use (&$miSuche, &$miSichtbar) {
    if (!is_array($k) || ($k['visible'] ?? true) === false) {
        return;   // unsichtbare Elemente samt Inhalt überspringen
    }
    if (isset($k['caption']) && is_string($k['caption'])) {
        $miSichtbar[] = $k['caption'] . (isset($k['name']) ? ' [' . $k['name'] . ']' : '') . (isset($k['options'][0]['value']) ? ' {' . $k['options'][0]['value'] . '}' : '');
    }
    foreach ($k as $w) {
        $miSuche($w);
    }
};
$miSuche(json_decode($mk->GetConfigurationForm(), true));
$miText = implode("\n", $miSichtbar);
check('Formular: Miele-Anleitung, Client-Felder, Land-Auswahl (de-DE zuerst), keine AL-KO-Anleitung', strpos($miText, 'developer.miele.com') !== false
    && strpos($miText, 'Client-ID (von Miele) [ClientId]') !== false && strpos($miText, 'Land des Kontos (wie in der App) [Region] {de-DE}') !== false
    && strpos($miText, 'alko-garden') === false && strpos($miText, 'Welche Region hat mein Konto?') === false, $miText);
$mk->props['Konto'] = 'scout@example.com';
$mk->props['Passwort'] = 'Sauber!';
$mk->props['Region'] = 'de-AT';
$mk->props['ClientId'] = 'miele-id';
$mk->props['ClientSecret'] = 'FALSCH';
$t = $mk->Anmelden();
check('falsches Secret: verständlich abgelehnt', strpos($t, 'Miele lehnt die Anmeldung ab (invalid_client)') !== false, $t);
$mk->props['ClientSecret'] = 'MIELE-GEHEIM';
$mk->props['Passwort'] = 'Sauber!';
$t = $mk->Anmelden();
$mb = json_decode($mk->ReadAttributeString('Buendel'), true);
$mg = json_decode($mk->ReadAttributeString('Geraete'), true);
check('angemeldet mit Land de-AT; Bündel ohne Passwort; nur der Saugroboter (Waschmaschine ausgelassen)', strpos($t, '✅ Angemeldet. Client-ID und Client-Secret bleiben') === 0
    && $mb['land'] === 'de-AT' && strpos(json_encode($mb), 'Sauber') === false && count($mg) === 1 && $mg[0]['id'] === '000123456789'
    && $mg[0]['name'] === 'Scouty' && $mg[0]['modell'] === 'miele.vacuum.rx3' && $mk->props['ClientSecret'] === '', $t);
$mr = new RoboHubGeraet(10250);
$mr->props = ['GeraetID' => '000123456789', 'Modell' => 'miele.vacuum.rx3', 'Klasse' => 'sauger', 'Intervall' => 120];
$mr->Create();
$mr->parent = $mk;
$mr->ApplyChanges();
$zustandMi = function (array $z) use ($mr): int {
    FakeMiele::$zustand = $z;
    $mr->Aktualisieren();
    return variable(10250, 'Zustand')['value'];
};
check('reinigt (Phase 5889) mit Mieles eigenem Text, Akku, Programm Turbo', $zustandMi(['status' => ['value_raw' => 5], 'programPhase' => ['value_raw' => 5889, 'value_localized' => 'Saugen'],
    'ProgramID' => ['value_raw' => 3], 'batteryLevel' => 77]) === RoboHubModell::ZUSTAND_REINIGT && variable(10250, 'ZustandRoh')['value'] === 'Saugen (5/5889)'
    && variable(10250, 'Akku')['value'] === 77 && variable(10250, 'Saugstufe')['value'] === 3);
check('in der Station: unter 100 % lädt, voll geladen; Rückfahrt; Pause', $zustandMi(['status' => ['value_raw' => 10], 'programPhase' => ['value_raw' => 5904], 'batteryLevel' => 60]) === RoboHubModell::ZUSTAND_LAEDT
    && $zustandMi(['status' => ['value_raw' => 10], 'programPhase' => ['value_raw' => 5903], 'batteryLevel' => 100]) === RoboHubModell::ZUSTAND_GELADEN
    && $zustandMi(['status' => ['value_raw' => 5], 'programPhase' => ['value_raw' => 5890]]) === RoboHubModell::ZUSTAND_ZURUECK
    && $zustandMi(['status' => ['value_raw' => 6], 'programPhase' => ['value_raw' => 0]]) === RoboHubModell::ZUSTAND_PAUSIERT);
check('Fehler F5 aus der Tabelle (ohne Miele-Text), nicht verbunden = offline', $zustandMi(['status' => ['value_raw' => 8], 'programPhase' => ['value_raw' => 5897]]) === RoboHubModell::ZUSTAND_FEHLER
    && variable(10250, 'Fehler')['value'] === 'F5: Bürsten blockiert' && $zustandMi(['status' => ['value_raw' => 255]]) === RoboHubModell::ZUSTAND_UNBEKANNT && variable(10250, 'Erreichbar')['value'] === false);

$zustandMi(['status' => ['value_raw' => 10], 'programPhase' => ['value_raw' => 5903], 'batteryLevel' => 100, 'ProgramID' => ['value_raw' => 1]]);
FakeMiele::$anfragen = [];
$mr->Starten();
$mr->Anhalten();
$mr->Stoppen();
$mr->RequestAction('Saugstufe', 4);
check('Start/Pause/Stopp als processAction 1/3/2, Saugstufe Leise als programId 4', FakeMiele::puts() === [['processAction' => 1], ['processAction' => 3], ['processAction' => 2], ['programId' => 4]], json_encode(FakeMiele::puts()));
FakeMiele::$anfragen = [];
$t = $mr->ZurStation();
check('Zur Station: ehrlicher Hinweis, kein Befehl', strpos($t, '„Zur Station" bietet die Miele-Schnittstelle nicht') !== false && FakeMiele::puts() === [], $t);
FakeMiele::$erlaubt = [2];
FakeMiele::$anfragen = [];
$t = $mr->Starten();
check('Start im falschen Zustand: Absage mit den erlaubten Aktionen, nichts gesendet', strpos($t, '„Start" ist gerade nicht möglich (Miele erlaubt im aktuellen Zustand nur: Stopp)') !== false && FakeMiele::puts() === [], $t);
FakeMiele::$erlaubt = [1, 2, 3];

FakeMiele::$gueltig = 'WEG';
$mr->Aktualisieren();
check('401: angemeldet bleiben …', $mk->ReadAttributeString('Buendel') !== '');
FakeMiele::$gueltig = 'M' . FakeMiele::$runde;
$alt = json_decode($mk->ReadAttributeString('Buendel'), true);
$mr->Aktualisieren();
$neu = json_decode($mk->ReadAttributeString('Buendel'), true);
check('… nächster Abruf erneuert (Land und Secret bleiben)', $neu['refresh_token'] !== $alt['refresh_token'] && $neu['land'] === 'de-AT' && $neu['clientSecret'] === 'MIELE-GEHEIM');
$GLOBALS['robohubTreiberFabrik'] = $fabrikMi;
