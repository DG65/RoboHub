<?php
/**
 * Vorwerk-Kobold-Teil des Prüfstands (von test-robohub.php eingebunden).
 * Protokoll nach pybotvac (MIT) und Homey-App-Vorwerk-Kobold (MIT).
 */

declare(strict_types=1);

class FakeVorwerk extends RoboHubVorwerk
{
    public static $anfragen = [];
    public static $zustand = [];
    public static $codeGesendet = '';
    public static $refreshOk = true;
    public static $befehle = [];

    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        self::$anfragen[] = ['methode' => $methode, 'url' => $url, 'headers' => $headers, 'body' => (string)$body];
        $j = json_decode((string)$body, true);
        if ($url === 'https://mykobold.eu.auth0.com/passwordless/start') {
            if (($j['client_id'] ?? '') !== 'KY4YbVAvtgB7lp8vIbWQ7zLk3hssZlhR' || ($j['connection'] ?? '') !== 'email' || ($j['send'] ?? '') !== 'code') {
                return [400, '{}'];
            }
            self::$codeGesendet = (string)$j['email'];
            return [200, '{"_id":"x","email":"' . $j['email'] . '"}'];
        }
        if ($url === 'https://mykobold.eu.auth0.com/oauth/token') {
            if (($j['grant_type'] ?? '') === 'refresh_token') {
                return self::$refreshOk && ($j['refresh_token'] ?? '') === 'VR-R1' ? [200, json_encode(['id_token' => 'VID2', 'refresh_token' => 'VR-R2', 'expires_in' => 86400])] : [403, '{"error":"invalid_grant"}'];
            }
            $ok = ($j['grant_type'] ?? '') === 'http://auth0.com/oauth/grant-type/passwordless/otp' && ($j['otp'] ?? '') === '123456'
                && ($j['username'] ?? '') === self::$codeGesendet && strpos((string)($j['scope'] ?? ''), 'offline_access') !== false && ($j['realm'] ?? '') === 'email';
            return $ok ? [200, json_encode(['id_token' => 'VID1', 'access_token' => 'nicht-benutzen', 'refresh_token' => 'VR-R1', 'expires_in' => 36000])]
                : [403, '{"error":"invalid_grant","error_description":"Wrong email or verification code."}'];
        }
        if ($url === 'https://beehive.ksecosys.com/users/me/robots') {
            if (!in_array('Authorization: Auth0Bearer VID1', $headers, true) && !in_array('Authorization: Auth0Bearer VID2', $headers, true)) {
                return [401, '{}'];
            }
            return [200, json_encode([
                ['serial' => 'OPS12345-ABCDEF', 'secret_key' => 'GEHEIMNIS300', 'nucleo_url' => 'https://nucleo.ksecosys.com:4443', 'name' => 'Kobi', 'model' => 'VR300'],
                ['serial' => 'FREMD1', 'secret_key' => 'X', 'nucleo_url' => 'https://boese.example.com', 'name' => 'Falle', 'model' => 'VR300'],
            ])];
        }
        if (preg_match('#^https://nucleo\.ksecosys\.com:4443/vendors/vorwerk/robots/OPS12345-ABCDEF/messages$#', $url)) {
            $datum = '';
            $auth = '';
            foreach ($headers as $h) {
                if (stripos($h, 'Date: ') === 0) {
                    $datum = substr($h, 6);
                }
                if (stripos($h, 'Authorization: ') === 0) {
                    $auth = substr($h, 15);
                }
            }
            $soll = 'NEATOAPP ' . hash_hmac('sha256', 'ops12345-abcdef' . "\n" . $datum . "\n" . (string)$body, 'GEHEIMNIS300');
            if ($auth !== $soll || !preg_match('/^[A-Z][a-z]{2}, \d\d [A-Z][a-z]{2} \d{4} \d\d:\d\d:\d\d GMT$/', $datum)
                || !in_array('Accept: application/vnd.neato.nucleo.v1', $headers, true)) {
                return [401, '{"message":"Unauthorized"}'];
            }
            $cmd = (string)($j['cmd'] ?? '');
            if ($cmd === 'getRobotState') {
                return [200, json_encode(self::$zustand + ['version' => 1, 'reqId' => '1', 'result' => 'ok', 'error' => null, 'alert' => null])];
            }
            if ($cmd === 'getMapBoundaries') {
                return [200, json_encode(['result' => 'ok', 'data' => ['mapId' => 'MAP-1', 'boundaries' => [
                    ['id' => 'b-kueche', 'type' => 'polygon', 'name' => 'Küche'], ['id' => 'b-linie', 'type' => 'polyline', 'name' => 'Sperrlinie'],
                    ['id' => 'b-bad', 'type' => 'polygon', 'name' => 'Bad'],
                ]]])];
            }
            self::$befehle[] = $j;
            return [200, json_encode(['result' => $cmd === 'findMe' && !empty(self::$zustand['_kein_orten']) ? 'command_rejected' : 'ok'])];
        }
        return [404, '{}'];
    }
}

// ===========================================================================
abschnitt('Vorwerk Kobold VR200/VR300');
$fabrikVw = $GLOBALS['robohubTreiberFabrik'];
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) use ($fabrikVw) {
    return $marke === 'vorwerk' ? new FakeVorwerk() : $fabrikVw($marke);
};
$vk = new RoboHubKonto(10100);
$vk->props['Hersteller'] = 'vorwerk';
$vk->Create();
$vk->ApplyChanges();
$vSichtbar = [];
$vSuche = function ($k) use (&$vSuche, &$vSichtbar) {
    if (!is_array($k)) {
        return;
    }
    if (isset($k['caption']) && is_string($k['caption']) && ($k['visible'] ?? true) !== false) {
        $vSichtbar[] = $k['caption'];
    }
    foreach ($k as $w) {
        $vSuche($w);
    }
};
$vSuche(json_decode($vk->GetConfigurationForm(), true));
$vText = implode("\n", $vSichtbar);
check('Formular: Vorwerk-Anleitung mit Code-Knopf, VR7 offen ausgenommen, keine Roborock-Anleitung', strpos($vText, 'Vorwerk meldet sich ohne Passwort an') !== false
    && strpos($vText, '📧 Code anfordern') !== false && strpos($vText, 'der VR7 nutzt eine andere Cloud') !== false && strpos($vText, 'Roborock meldet sich') === false);
$vk->props['Konto'] = 'Kobold@Example.com';
$t = $vk->CodeAnfordern();
check('Code angefordert (Auth0 passwordless, E-Mail klein)', strpos($t, 'Anmeldecode an kobold@example.com') !== false && FakeVorwerk::$codeGesendet === 'kobold@example.com', $t);
$vk->props['Passwort'] = '654321';
$t = $vk->Anmelden();
check('falscher Code: verständliche Ablehnung', strpos($t, 'Vorwerk lehnt den Code ab (invalid_grant)') !== false, $t);
$vk->props['Passwort'] = '123 456';
$t = $vk->Anmelden();
$vb = json_decode($vk->ReadAttributeString('Buendel'), true);
$vg = json_decode($vk->ReadAttributeString('Geraete'), true);
check('angemeldet mit ID-Token (nicht access_token); Roboterschlüssel im Bündel, fremder Server ausgefiltert', strpos($t, '✅ Angemeldet. RoboHub kennt kein Vorwerk-Passwort') === 0
    && $vb['id_token'] === 'VID1' && array_keys($vb['roboter']) === ['OPS12345-ABCDEF'] && $vb['roboter']['OPS12345-ABCDEF']['secret'] === 'GEHEIMNIS300'
    && count($vg) === 1 && $vg[0]['modell'] === 'vorwerk.vacuum.vr300' && $vg[0]['name'] === 'Kobi' && strpos(json_encode($vg), 'GEHEIMNIS') === false, $t);
check('Code-Feld danach leer', $vk->props['Passwort'] === '');

$vr = new RoboHubGeraet(10150);
$vr->props = ['GeraetID' => 'OPS12345-ABCDEF', 'Modell' => 'vorwerk.vacuum.vr300', 'Klasse' => 'sauger', 'Intervall' => 120];
$vr->Create();
$vr->parent = $vk;
$vr->ApplyChanges();
$basis = ['availableServices' => ['houseCleaning' => 'basic-4'], 'availableCommands' => ['start' => true], 'meta' => ['modelName' => 'BotVacD7Connected', 'firmware' => '4.5.3-189']];
$zustandVw = function (array $z) use ($vr, $basis): int {
    FakeVorwerk::$zustand = $z + $basis;
    $vr->Aktualisieren();
    return variable(10150, 'Zustand')['value'];
};
check('signierte Abfrage: in der Station lädt, Akku 64, Firmware', $zustandVw(['state' => 1, 'action' => 0, 'details' => ['isCharging' => true, 'isDocked' => true, 'charge' => 64]]) === RoboHubModell::ZUSTAND_LAEDT
    && variable(10150, 'Akku')['value'] === 64 && variable(10150, 'Firmware')['value'] === '4.5.3-189' && variable(10150, 'Fehler')['value'] === 'kein Fehler');
check('in der Station geladen, frei stehend bereit', $zustandVw(['state' => 1, 'details' => ['isDocked' => true, 'charge' => 100]]) === RoboHubModell::ZUSTAND_GELADEN
    && $zustandVw(['state' => 1, 'details' => ['isDocked' => false, 'charge' => 80]]) === RoboHubModell::ZUSTAND_BEREIT);
check('reinigt (Aktion 1/11), erkundet (12), fährt zurück (4), pausiert (3 bzw. 2 mit Aktion 6)', $zustandVw(['state' => 2, 'action' => 11, 'details' => []]) === RoboHubModell::ZUSTAND_REINIGT
    && $zustandVw(['state' => 2, 'action' => 12, 'details' => []]) === RoboHubModell::ZUSTAND_KARTIERUNG && $zustandVw(['state' => 2, 'action' => 4, 'details' => []]) === RoboHubModell::ZUSTAND_ZURUECK
    && $zustandVw(['state' => 3, 'action' => 1, 'details' => []]) === RoboHubModell::ZUSTAND_PAUSIERT && $zustandVw(['state' => 2, 'action' => 6, 'details' => []]) === RoboHubModell::ZUSTAND_PAUSIERT);
check('Fehler mit deutschem Text, „ui_alert_invalid" ist kein Fehler', $zustandVw(['state' => 4, 'error' => 'ui_error_brush_stuck', 'details' => []]) === RoboHubModell::ZUSTAND_FEHLER
    && variable(10150, 'Fehler')['value'] === 'Bürste blockiert (ui_error_brush_stuck)'
    && $zustandVw(['state' => 1, 'error' => 'ui_alert_invalid', 'details' => ['isDocked' => true, 'charge' => 100]]) === RoboHubModell::ZUSTAND_GELADEN
    && RoboHubVorwerk::fehlerText('ui_error_navigation_noprogress') === 'Weg versperrt — bitte freiräumen' && RoboHubVorwerk::fehlerText('ui_error_battery_overtemp') === 'Akku-Fehler — Akku prüfen bzw. tauschen');
check('Hinweis (alert) als „Letzte Meldung"', $zustandVw(['state' => 1, 'alert' => 'ui_alert_dust_bin_full', 'details' => ['isDocked' => true, 'charge' => 90]]) === RoboHubModell::ZUSTAND_GELADEN
    && variable(10150, 'Meldung')['value'] === 'Staubbehälter voll');

$vr->RaeumeLaden();
check('Bereiche vom Roboter (nur Flächen, keine Sperrlinien), sortiert, nummeriert', json_decode($vr->ReadAttributeString('Raeume'), true) == [1 => 'Bad', 2 => 'Küche']);
$zustandVw(['state' => 1, 'details' => ['isDocked' => true, 'charge' => 100]]);
FakeVorwerk::$befehle = [];
$vr->Starten();
$vr->Anhalten();
$vr->Stoppen();
$vr->ZurStation();
$vr->Orten();
$vr->RaeumeReinigen('2');
check('Start (gespeicherte Karte, Turbo), Pause, Stopp, Station, Orten, Bereich Küche mit Karten-/Bereichs-ID', array_column(FakeVorwerk::$befehle, 'cmd') === ['startCleaning', 'pauseCleaning', 'stopCleaning', 'sendToBase', 'findMe', 'startCleaning']
    && FakeVorwerk::$befehle[0]['params'] === ['category' => 4, 'mode' => 2, 'modifier' => 1, 'navigationMode' => 1]
    && FakeVorwerk::$befehle[5]['params'] === ['category' => 4, 'mode' => 2, 'modifier' => 1, 'navigationMode' => 1, 'mapId' => 'MAP-1', 'boundaryId' => 'b-kueche'], json_encode(FakeVorwerk::$befehle));
check('VR200 (basic-1): Haus ohne Karte', RoboHubVorwerk::startParameter('basic-1', null) === ['category' => 2, 'mode' => 2, 'modifier' => 1]);
$zustandVw(['state' => 3, 'action' => 1, 'details' => []]);
FakeVorwerk::$befehle = [];
$vr->Starten();
check('pausiert → Start = resumeCleaning', array_column(FakeVorwerk::$befehle, 'cmd') === ['resumeCleaning']);
$zustandVw(['state' => 1, 'details' => ['isDocked' => true], '_kein_orten' => true]);
$t = $vr->Orten();
check('vom Roboter abgelehnter Befehl wird gemeldet', strpos($t, 'lehnt „findMe" ab (command_rejected)') !== false, $t);

// Kontoschlüssel abgelaufen, Erneuerung abgelehnt: Roboter bleiben steuerbar.
$vb = json_decode($vk->ReadAttributeString('Buendel'), true);
$vb['expires_at'] = time() - 10;
$vk->WriteAttributeString('Buendel', json_encode($vb));
FakeVorwerk::$refreshOk = false;
$zustandVw(['state' => 1, 'details' => ['isDocked' => true, 'charge' => 70]]);
$vb2 = json_decode($vk->ReadAttributeString('Buendel'), true);
check('Erneuerung abgelehnt: nicht abgemeldet, Roboter läuft weiter, Hinweis', !empty($vb2['konto_abgelaufen']) && isset($vb2['roboter']['OPS12345-ABCDEF'])
    && variable(10150, 'Akku')['value'] === 70 && strpos((string)variable(10150, 'Meldung')['value'], 'MyKobold-Kontoschlüssel abgelaufen') === 0,
    json_encode(['ka' => $vb2['konto_abgelaufen'] ?? null, 'akku' => variable(10150, 'Akku')['value'], 'meldung' => variable(10150, 'Meldung')['value'] ?? null, 'fehler' => $vk->ReadAttributeString('LetzterFehler')], JSON_UNESCAPED_UNICODE));
FakeVorwerk::$refreshOk = true;
$vb2['expires_at'] = time() - 10;
unset($vb2['konto_abgelaufen']);
$vk->WriteAttributeString('Buendel', json_encode($vb2));
$vr->Aktualisieren();
check('Erneuerung klappt: neuer ID- und Erneuerungsschlüssel', json_decode($vk->ReadAttributeString('Buendel'), true)['id_token'] === 'VID2'
    && json_decode($vk->ReadAttributeString('Buendel'), true)['refresh_token'] === 'VR-R2');
$vb3 = json_decode($vk->ReadAttributeString('Buendel'), true);
$vb3['roboter']['OPS12345-ABCDEF']['secret'] = 'FALSCH';
$vk->WriteAttributeString('Buendel', json_encode($vb3));
$vr->Aktualisieren();
check('Roboterschlüssel abgelehnt → Anmeldung nötig (Status 201)', $vk->ReadAttributeString('Buendel') === '' && $vk->status === 201);
$GLOBALS['robohubTreiberFabrik'] = $fabrikVw;
