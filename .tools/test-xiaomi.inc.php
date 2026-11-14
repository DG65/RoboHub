<?php
/**
 * Xiaomi-Teil des Prüfstands (Roborock im Mi-Home-Konto, wird von test-robohub.php eingebunden).
 *
 * Referenzwerte unabhängig nachgerechnet: RC4/Signatur/Nonce mit einer eigenen Python-Umsetzung
 * nach Xiaomi-cloud-tokens-extractor (MIT), das miIO-Paket mit der openssl-Kommandozeile
 * (aes-128-cbc, md5) nach aholstenson/miio lib/packet.js (MIT).
 */

declare(strict_types=1);

/** Attrappe: ersetzt Cloud (httpVoll) und Roboter (UDP), der Rest ist echter Code. */
class FakeXiaomi extends RoboHubXiaomi
{
    public const SSECURITY = 'MDEyMzQ1Njc4OWFiY2RlZg==';   // base64('0123456789abcdef')
    public const TOKEN = '00112233445566778899aabbccddeeff';
    public static $anfragen = [];
    public static $lpBereit = false;
    public static $modus = 'ok';      // ok | captcha | 2fa | falsch
    public static $geraete = [];
    public static $rpc = [];          // [ip, methode, params]
    public static $antworten = [];    // methode => result | ['__fehler' => text]
    public static $still = false;     // Roboter antwortet nicht
    public static $apiServer = [];

    protected function httpVoll(string $methode, string $url, array $kopf, ?string $body, array $kekse, int $frist): array
    {
        self::$anfragen[] = ['methode' => $methode, 'url' => $url, 'body' => $body, 'kekse' => $kekse];
        $json = function (array $d): array { return [200, [], '&&&START&&&' . json_encode($d)]; };
        if (strpos($url, '/longPolling/loginUrl') !== false) {
            return $json(['qr' => 'https://qr.example/x.png', 'lp' => 'https://lp.example/abc', 'loginUrl' => 'https://login.example', 'timeout' => 300]);
        }
        if (strpos($url, 'https://qr.example/') === 0) {
            return [200, ['content-type' => ['image/png']], "\x89PNG-QR"];
        }
        if (strpos($url, 'https://lp.example/') === 0) {
            return self::$lpBereit
                ? $json(['userId' => 42, 'cUserId' => 'c42', 'ssecurity' => self::SSECURITY, 'passToken' => 'PT1', 'location' => 'https://sts.api.io.mi.com/sts?x=1'])
                : [0, [], 'Operation timed out'];
        }
        if (strpos($url, 'https://sts.api.io.mi.com/sts') === 0) {
            return [200, ['set-cookie' => ['serviceToken=ST1; Path=/; HttpOnly', 'userId=42; Path=/']], 'ok'];
        }
        if (strpos($url, '/pass/serviceLoginAuth2/end') !== false) {
            return [302, ['extension-pragma' => ['{"ssecurity":"' . self::SSECURITY . '","psecurity":"x"}'], 'location' => ['https://sts.api.io.mi.com/sts?z=2'],
                'set-cookie' => ['passToken=PT9; Path=/', 'userId=42; Path=/', 'cUserId=c42; Path=/']], ''];
        }
        if (strpos($url, '/pass/serviceLoginAuth2') !== false) {
            if (self::$modus === 'captcha') {
                return $json(['captchaUrl' => '/pass/getCode?icodeType=login', 'code' => 87001]);
            }
            if (self::$modus === '2fa') {
                return $json(['notificationUrl' => 'https://account.xiaomi.com/fe/service/identity/authStart?sid=xiaomiio&context=CTX1', 'code' => 0]);
            }
            if (self::$modus === 'falsch') {
                return $json(['code' => 70016, 'desc' => 'wrong']);
            }
            return $json(['code' => 0, 'userId' => 42, 'ssecurity' => self::SSECURITY, 'passToken' => 'PT1', 'location' => 'https://sts.api.io.mi.com/sts?p=1']);
        }
        if (strpos($url, '/pass/serviceLogin') !== false) {
            if (($kekse['passToken'] ?? '') !== '') {
                return $kekse['passToken'] === 'PTX' ? $json(['code' => 70016])
                    : $json(['code' => 0, 'userId' => 42, 'ssecurity' => self::SSECURITY, 'passToken' => 'PT2', 'location' => 'https://sts.api.io.mi.com/sts?r=1']);
            }
            return $json(['_sign' => 'SIGN1', 'code' => 70016]);
        }
        if (strpos($url, '/identity/authStart') !== false || strpos($url, '/identity/list') !== false) {
            return [200, ['set-cookie' => ['ick=ICK1; Path=/']], ''];
        }
        if (strpos($url, '/identity/auth/sendEmailTicket') !== false) {
            return $json(['code' => 0]);
        }
        if (strpos($url, '/identity/auth/verifyEmail') !== false) {
            parse_str((string)$body, $f);
            return ($f['ticket'] ?? '') === '123456' && ($f['ick'] ?? '') === 'ICK1'
                ? $json(['code' => 0, 'location' => 'https://account.xiaomi.com/identity/result/check?sid=xiaomiio&context=CTX1'])
                : $json(['code' => 70014, 'desc' => 'wrong code']);
        }
        if (strpos($url, '/identity/result/check') !== false) {
            return [302, ['location' => ['https://account.xiaomi.com/pass/serviceLoginAuth2/end?t=1']], ''];
        }
        if (preg_match('~^https://(?:(\w+)\.)?api\.io\.mi\.com/app/home/device_list$~', $url, $m)) {
            parse_str((string)$body, $f);
            $server = $m[1] === '' ? 'cn' : $m[1];
            self::$apiServer[] = $server;
            $signiert = self::signierterNonce((string)$f['ssecurity'], (string)$f['_nonce']);
            $daten = json_decode(self::rc4($signiert, (string)base64_decode((string)$f['data'])), true);
            $ok = is_array($daten) && ($daten['getVirtualModel'] ?? null) === true && ($kekse['serviceToken'] ?? '') !== '';
            $antwort = ['code' => $ok ? 0 : 3, 'message' => $ok ? 'ok' : 'auth err',
                'result' => ['list' => $server === 'de' ? self::$geraete : []]];
            return [200, [], base64_encode(self::rc4($signiert, (string)json_encode($antwort)))];
        }
        return [404, [], ''];
    }

    protected function udpAustausch(string $ip, string $paket, float $frist): ?string
    {
        if (self::$still) {
            return null;
        }
        $token = (string)hex2bin(self::TOKEN);
        if ($paket === self::halloPaket()) {
            return pack('nnNNN', 0x2131, 32, 0, 0x01020304, 5000) . str_repeat("\xff", 16);
        }
        $p = self::paketLesen($paket, $token);
        if ($p === null) {
            return null;   // falscher Schlüssel: echter Roboter schweigt
        }
        $j = json_decode($p['nutzlast'], true);
        self::$rpc[] = [$ip, $j['method'], $j['params']];
        $r = self::$antworten[$j['method']] ?? 0;
        $antwort = is_array($r) && isset($r['__fehler']) ? ['id' => $j['id'], 'error' => ['code' => -1, 'message' => $r['__fehler']]] : ['id' => $j['id'], 'result' => $r];
        return self::paketBauen($token, 0x01020304, 5001, json_encode($antwort) . "\0");
    }

    protected function udpLesen(string $ip, float $frist): ?string
    {
        return null;
    }
}

// ===========================================================================
abschnitt('Xiaomi: Verschlüsselung gegen Referenzwerte');
$nonceX = RoboHubXiaomi::nonce(1794600000000, 'ABCDEFGH');
$signX = RoboHubXiaomi::signierterNonce(FakeXiaomi::SSECURITY, $nonceX);
check('Nonce (8 Zufallsbytes + Minuten) = Referenz', $nonceX === 'QUJDREVGR0gByGPw', $nonceX);
check('signierter Nonce sha256(ssecurity+nonce) = Referenz', $signX === 'wH2fFqBlhjtu5zPfvrda8eZ5Lp3cdhuBgSObe1j+H2s=', $signX);
check('RC4 mit 1024 verworfenen Bytes = Referenz', bin2hex(RoboHubXiaomi::rc4($signX, 'hello world')) === 'ff06b5e53e5df06e67352c');
$fX = RoboHubXiaomi::verschluesselteFelder('POST', 'https://de.api.io.mi.com/app/home/device_list', $signX, $nonceX, ['data' => '{"a":1}'], FakeXiaomi::SSECURITY);
check('verschlüsselte Felder, rc4_hash__ und signature = Referenz', $fX['data'] === '7EG4q2tM+g==' && $fX['rc4_hash__'] === '/xWR6GQ7xU1bKjloi38RsYGgoa0fu74zomBBkQ=='
    && $fX['signature'] === 'BW0X4MBbbIcTBvk53Ewwa6STodk=' && $fX['_nonce'] === $nonceX && $fX['ssecurity'] === FakeXiaomi::SSECURITY, json_encode($fX));
$paketRef = '213100500000000001020304000003e8dc8d6f4eaabee34cc587976f20dd570ca5516ec6151955dc2bb2d43e7c84c183bbabebda6a820d0a55fcfe476c51a42cdb4e99776d93c2afe2d14dcacc7585b5';
$tokX = (string)hex2bin(FakeXiaomi::TOKEN);
check('miIO-Paket (AES-128-CBC, md5-Prüfsumme) = openssl-Referenz', bin2hex(RoboHubXiaomi::paketBauen($tokX, 0x01020304, 1000, '{"id":1,"method":"get_status","params":[]}')) === $paketRef);
$gl = RoboHubXiaomi::paketLesen((string)hex2bin($paketRef), $tokX);
check('Referenzpaket gelesen', $gl !== null && $gl['nutzlast'] === '{"id":1,"method":"get_status","params":[]}' && $gl['did'] === 0x01020304 && $gl['stempel'] === 1000);
$kaputtX = (string)hex2bin($paketRef);
$kaputtX[40] = chr(ord($kaputtX[40]) ^ 1);
check('Paket mit falscher Prüfsumme verworfen', RoboHubXiaomi::paketLesen($kaputtX, $tokX) === null);
check('falscher Token → nichts gelesen', RoboHubXiaomi::paketLesen((string)hex2bin($paketRef), str_repeat("\x01", 16)) === null);
check('Hello-Paket: 0x2131, Länge 32, Rest 0xff', bin2hex(RoboHubXiaomi::halloPaket()) === '21310020' . str_repeat('ff', 28));
check('Kekse aus Set-Cookie übernommen, leere gelöscht', RoboHubXiaomi::kekseMerken(['a' => '1', 'b' => '2'], ['set-cookie' => ['c=3; Path=/', 'b=; Max-Age=0']]) === ['a' => '1', 'c' => '3']);
check('nur Roborock-Altgeräte sprechen miIO-1.0', RoboHubXiaomi::miioV1('roborock.vacuum.s5') && RoboHubXiaomi::miioV1('rockrobo.vacuum.v1') && !RoboHubXiaomi::miioV1('xiaomi.vacuum.c102gl'));

// ===========================================================================
abschnitt('Xiaomi: Anmeldung per QR-Code');
$vorherFabrik = $GLOBALS['robohubTreiberFabrik'] ?? null;
$GLOBALS['robohubTreiberFabrik'] = function (string $marke) use ($vorherFabrik) {
    return $marke === 'xiaomi' ? new FakeXiaomi() : ($vorherFabrik ? $vorherFabrik($marke) : new FakeCloud($marke));
};
FakeXiaomi::$geraete = [
    ['did' => '123456789', 'name' => 'Sauger Flur', 'model' => 'roborock.vacuum.s5', 'token' => FakeXiaomi::TOKEN, 'localip' => '192.168.1.50', 'isOnline' => true],
    ['did' => '987654321', 'name' => 'Sauger Küche', 'model' => 'xiaomi.vacuum.c102gl', 'token' => 'ffeeddccbbaa99887766554433221100', 'localip' => '192.168.1.51', 'isOnline' => true],
    ['did' => '555', 'name' => 'Lampe', 'model' => 'yeelink.light.ceiling1', 'token' => 'aa', 'localip' => '192.168.1.52', 'isOnline' => true],
];
$xk = new RoboHubKonto(6000);
$xk->props['Hersteller'] = 'xiaomi';
$xk->Create();
$xk->ApplyChanges();
$f = json_encode(json_decode(konsole($xk->GetConfigurationForm()), true), JSON_UNESCAPED_UNICODE);
check('Formular: QR-Knopf, QR-Bild (versteckt), Hinweis auf lokale Steuerung, Serverwahl', strpos($f, 'ROBOHUB_QrAnzeigen') !== false && strpos($f, '"name":"QrBild"') !== false
    && strpos($f, 'UDP-Port 54321') !== false && strpos($f, 'automatisch (alle Server prüfen)') !== false && strpos($f, '"caption":"📧 Code anfordern","visible":false') !== false,
    json_encode([strpos($f, 'ROBOHUB_QrAnzeigen'), strpos($f, '"name":"QrBild"'), strpos($f, 'UDP-Port 54321'), strpos($f, 'automatisch (alle Server prüfen)'), strpos($f, 'ROBOHUB_CodeAnfordern(')]));
check('Konto ohne Echtzeit nennt das Virtual IO', json_decode($xk->GetCompatibleParents(), true)['moduleIDs'] === ['{6179ED6A-FC31-413C-BB8E-1204150CF376}']);
$t = $xk->QrAnzeigen();
$qrFeld = [];
foreach ($xk->fields as [$fn, $fp, $fv]) {
    if ($fn === 'QrBild') {
        $qrFeld[$fp] = $fv;
    }
}
check('QR-Code angezeigt, Timer läuft, Bild als data-URI im Formular', strpos($t, '📱') === 0 && ($xk->timers['QrWarten'] ?? 0) === 3000
    && strpos((string)($qrFeld['image'] ?? ''), 'data:image/png;base64,') === 0 && ($qrFeld['visible'] ?? false) === true, $t . ' ' . json_encode($qrFeld));
check('Puffer enthält kein Bild, aber die Long-Polling-Adresse', strpos($xk->GetBuffer('Qr'), 'lp.example') !== false && strpos($xk->GetBuffer('Qr'), 'base64') === false);
$xk->QrWarten();
check('noch nicht gescannt → weiter warten, nichts gespeichert', $xk->ReadAttributeString('Buendel') === '' && ($xk->timers['QrWarten'] ?? 0) === 3000);
FakeXiaomi::$lpBereit = true;
$xk->QrWarten();
$xb = json_decode($xk->ReadAttributeString('Buendel'), true) ?: [];
check('gescannt → angemeldet: ssecurity, serviceToken, passToken, kein Passwort', ($xb['ssecurity'] ?? '') === FakeXiaomi::SSECURITY && ($xb['serviceToken'] ?? '') === 'ST1'
    && ($xb['passToken'] ?? '') === 'PT1' && ($xb['userId'] ?? '') === '42' && ($xk->timers['QrWarten'] ?? 0) === 0 && $xk->GetBuffer('Qr') === '', json_encode($xb));
$xg = json_decode($xk->ReadAttributeString('Geraete'), true) ?: [];
check('Geräte: nur Sauger, Token + IP im Konto, MIoT-Gerät markiert', count($xg) === 2 && $xg[0]['modell'] === 'roborock.vacuum.s5' && $xg[0]['schluessel'] === FakeXiaomi::TOKEN
    && $xg[0]['ip'] === '192.168.1.50' && $xg[0]['protokoll'] === '1.0' && $xg[1]['protokoll'] === 'miot', json_encode($xg));
check('Region automatisch: Server nacheinander, Halt beim ersten mit Geräten', FakeXiaomi::$apiServer === ['de'], json_encode(FakeXiaomi::$apiServer));
check('GeraeteListe gibt keine Token heraus', strpos($xk->GeraeteListe(), FakeXiaomi::TOKEN) === false);
$xk->props['Region'] = 'us';
FakeXiaomi::$apiServer = [];
$xb['server'] = 'us';
$xk->WriteAttributeString('Buendel', json_encode($xb));
$xk->GeraeteSuchen();
check('feste Region: nur dieser Server', FakeXiaomi::$apiServer === ['us']);
$xb['server'] = 'auto';
$xk->WriteAttributeString('Buendel', json_encode($xb));
$xk->GeraeteSuchen();

// ===========================================================================
abschnitt('Xiaomi: lokale Steuerung per miIO');
FakeXiaomi::$antworten = [
    'get_status' => [['state' => 8, 'battery' => 100, 'fan_power' => 102, 'error_code' => 0, 'clean_time' => 600, 'clean_area' => 12500000]],
    'get_consumable' => [['main_brush_work_time' => 0, 'side_brush_work_time' => 0, 'filter_work_time' => 0, 'sensor_dirty_time' => 0]],
    'get_clean_summary' => [3600, 25000000, 2, []],
    'get_dnd_timer' => [['start_hour' => 22, 'start_minute' => 0, 'end_hour' => 8, 'end_minute' => 0, 'enabled' => 1]],
    'get_sound_volume' => [80],
    'get_room_mapping' => ['__fehler' => 'unknown_method'],
];
$xr = new RoboHubGeraet(6100);
$xr->props = ['GeraetID' => '123456789', 'Modell' => 'roborock.vacuum.s5', 'Klasse' => 'sauger', 'Intervall' => 120];
$xr->Create();
$xr->parent = $xk;
$xr->ApplyChanges();
FakeXiaomi::$rpc = [];
$t = $xr->Aktualisieren();
check('Abruf lokal an die IP aus der Cloud, alle fünf Blöcke', array_slice(array_column(FakeXiaomi::$rpc, 1), 0, 5) === ["get_status", "get_consumable", "get_clean_summary", "get_dnd_timer", "get_sound_volume"]
    && FakeXiaomi::$rpc[0][0] === '192.168.1.50', $t . ' ' . json_encode(FakeXiaomi::$rpc));
check('Zustand wie bei Roborock ausgewertet (lädt, Akku, Fläche, Lautstärke)', variable(6100, 'Laedt')['value'] === true && variable(6100, 'Akku')['value'] === 100
    && variable(6100, 'Flaeche')['value'] === 13 && variable(6100, 'Lautstaerke')['value'] === 80);
FakeXiaomi::$rpc = [];
$t = $xr->Starten();
check('Start = app_start lokal', array_column(FakeXiaomi::$rpc, 1) === ['app_start'], $t);
FakeXiaomi::$antworten['app_charge'] = ['__fehler' => 'invalid status'];
check('Ablehnung des Roboters wird gemeldet', strpos($xr->ZurStation(), 'invalid status') !== false);
FakeXiaomi::$still = true;
$t = $xr->Aktualisieren();
check('Roboter antwortet nicht → verständliche Meldung', strpos($t, 'antwortet nicht') !== false || strpos($t, 'does not respond') !== false, $t);
FakeXiaomi::$still = false;
$xm = new RoboHubGeraet(6200);
$xm->props = ['GeraetID' => '987654321', 'Modell' => 'xiaomi.vacuum.c102gl', 'Klasse' => 'sauger', 'Intervall' => 120];
$xm->Create();
$xm->parent = $xk;
$xm->ApplyChanges();
FakeXiaomi::$rpc = [];
$t = $xm->Aktualisieren();
check('MIoT-Gerät: ehrlich „noch nicht unterstützt“, kein Paket gesendet', strpos($t, 'MIoT') !== false && FakeXiaomi::$rpc === [], $t);
$xd = new FakeXiaomi();
check('Räume: Altgerät ohne Raumzuordnung → leere Liste statt Fehler', $xd->karte([], ['protokoll' => '1.0', 'schluessel' => FakeXiaomi::TOKEN, 'ip' => '192.168.1.50'])['raeume'] === []);
try {
    $xd->zustand([], ['protokoll' => '1.0', 'schluessel' => FakeXiaomi::TOKEN, 'ip' => '', 'modell' => 'roborock.vacuum.s5']);
    $ohneIp = '';
} catch (RoboHubFehler $e) {
    $ohneIp = $e->getMessage();
}
check('ohne lokale IP: klare Meldung statt UDP-Versuch', strpos($ohneIp, 'IP') !== false, $ohneIp);

// ===========================================================================
abschnitt('Xiaomi: Passwort als Rückfall, E-Mail-Code, Erneuern');
$xp = new RoboHubKonto(6300);
$xp->props['Hersteller'] = 'xiaomi';
$xp->Create();
$xp->ApplyChanges();
$xp->props['Konto'] = 'nutzer@example.org';
$xp->props['Passwort'] = 'Geheim123';
FakeXiaomi::$modus = 'captcha';
$t = $xp->Anmelden();
check('Captcha → Verweis auf den QR-Code, nichts gespeichert', strpos($t, 'QR') !== false && $xp->ReadAttributeString('Buendel') === '', $t);
FakeXiaomi::$modus = 'falsch';
check('falsches Passwort abgelehnt', strpos($xp->Anmelden(), 'abgelehnt') !== false);
FakeXiaomi::$modus = '2fa';
FakeXiaomi::$anfragen = [];
$t = $xp->Anmelden();
$auth2 = null;
foreach (FakeXiaomi::$anfragen as $a) {
    if (strpos($a['url'], 'serviceLoginAuth2?') !== false) {
        $auth2 = $a['url'];
    }
}
parse_str((string)parse_url((string)$auth2, PHP_URL_QUERY), $aq);
check('Passwort nur als md5 (Großbuchstaben) gesendet', ($aq['hash'] ?? '') === strtoupper(md5('Geheim123')) && strpos((string)$auth2, 'Geheim123') === false);
check('E-Mail-Code angefordert, Vorstufe gemerkt, Passwortfeld geleert', strpos($t, '📧') === 0 && $xp->props['Passwort'] === ''
    && strpos($xp->ReadAttributeString('AnmeldeVorstufe'), 'ICK1') !== false && strpos($xp->ReadAttributeString('AnmeldeVorstufe'), 'Geheim123') === false, $t);
$f = json_encode(json_decode(konsole($xp->GetConfigurationForm()), true), JSON_UNESCAPED_UNICODE);
check('Formular fragt jetzt nach dem Code aus der Xiaomi-E-Mail', strpos($f, 'Code aus der Xiaomi-E-Mail') !== false);
$xp->props['Passwort'] = '654321';
$t = $xp->Anmelden();
check('falscher Code → klare Meldung', strpos($t, 'nicht angenommen') !== false, $t);
$xp->props['Passwort'] = '123456';
$t = $xp->Anmelden();
$pb = json_decode($xp->ReadAttributeString('Buendel'), true) ?: [];
check('richtiger Code → ssecurity aus extension-pragma, serviceToken über STS, passToken aus Keks', ($pb['ssecurity'] ?? '') === FakeXiaomi::SSECURITY
    && ($pb['serviceToken'] ?? '') === 'ST1' && ($pb['passToken'] ?? '') === 'PT9' && $xp->ReadAttributeString('AnmeldeVorstufe') === '' && $xp->props['Passwort'] === '', $t);
check('Bündel enthält kein Passwort und keinen Code', strpos($xp->ReadAttributeString('Buendel'), 'Geheim123') === false && strpos($xp->ReadAttributeString('Buendel'), '123456') === false);
$alt = $pb;
$alt['zeit'] = time() - 7 * 3600;
$neu = (new FakeXiaomi())->erneuern($alt);
check('nach 6 h: über passToken erneuert (ohne Passwort)', ($neu['passToken'] ?? '') === 'PT2' && ($neu['serviceToken'] ?? '') === 'ST1' && $neu['zeit'] >= time() - 5);
$frisch = $pb;
$frisch['zeit'] = time();
check('frisches Bündel bleibt unverändert', (new FakeXiaomi())->erneuern($frisch) === $frisch);
$alt['passToken'] = 'PTX';
check('abgelehnter passToken → altes Bündel bleibt (kein Abmelden im Takt)', (new FakeXiaomi())->erneuern($alt) === $alt);
FakeXiaomi::$modus = 'ok';
$GLOBALS['robohubTreiberFabrik'] = $vorherFabrik;
