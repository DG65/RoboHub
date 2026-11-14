<?php

declare(strict_types=1);

// ===========================================================================
// RoboHub-Treiber für Roborock-Sauger im Xiaomi-Konto (App „Mi Home“),
// z. B. Roborock S5, S6, S50, Mi Robot Vacuum (rockrobo.vacuum.v1).
//
// Quellen (nur Protokollwissen, kein Code übernommen):
//   - PiotrMachowski/Xiaomi-cloud-tokens-extractor (MIT, Stand 08.11.2025):
//     QR-Anmeldung (longPolling/loginUrl), Passwort-Anmeldung
//     (serviceLogin/serviceLoginAuth2, md5-Hash), E-Mail-Code (identity/…),
//     RC4-verschlüsselte API (api.io.mi.com, signed nonce, rc4_hash__).
//   - Squachen/micloud (MIT): /home/device_list, Anfrage als Formular.
//   - al-one/hass-xiaomi-miot (Apache-2.0): Fehlercodes für abgelaufenen
//     Zugang (code 2/3, „auth err“, „invalid signature“), Erneuern über
//     passToken (serviceLogin ohne Passwort).
//   - aholstenson/miio (MIT, lib/packet.js): lokales miIO-Protokoll
//     (UDP 54321, Hello, AES-128-CBC mit md5(Token), md5-Prüfsumme).
//   python-miio ist GPL-3.0 — nicht verwendet.
//
// Aufbau: Die Xiaomi-Cloud liefert nur die Geräteliste mit Token und
// lokaler IP. Befehle und Abfragen gehen LOKAL per miIO an den Roboter
// (Symcon und Roboter im selben Netz). Die Befehle selbst sind dieselben
// wie in der Roborock-App („1.0“-Methoden get_status, app_start …), daher
// erbt der Treiber Auswertung und Befehle von RoboHubRoborock.
//
// Zugangsdaten: Gespeichert werden userId, ssecurity, serviceToken und
// passToken (Erneuerungsschlüssel) — nie das Passwort. Bevorzugt ist die
// Anmeldung per QR-Code (Mi-Home-App scannt), Passwort nur als Rückfall
// (Dietmars Entscheidung 14.11.2026).
// ===========================================================================

class RoboHubXiaomi extends RoboHubRoborock
{
    public const SERVER = ['de', 'cn', 'us', 'ru', 'sg', 'in', 'i2', 'tw'];
    private const KONTO = 'https://account.xiaomi.com';
    private const STS = 'https://sts.api.io.mi.com/sts';
    private const MIIO_PORT = 54321;
    /** Nach so vielen Sekunden holt erneuern() über den passToken einen frischen serviceToken. */
    private const ERNEUERN_NACH = 6 * 3600;

    /** @var array<string, resource> offene UDP-Verbindungen je IP (nur für die Dauer eines Aufrufs) */
    private $udp = [];
    /** @var array<string, array> Sitzung je IP: did, Stempel, Zeitpunkt */
    private $sitzung = [];
    private $naechsteId = 0;

    public function __construct()
    {
        $this->naechsteId = random_int(1000, 9000);
    }

    public function __destruct()
    {
        foreach ($this->udp as $s) {
            @fclose($s);
        }
    }

    /** Server-Auswahl im Konto (Region der Mi-Home-App). */
    public static function regionen(): array
    {
        return [
            ['caption' => 'automatic (check all servers)', 'value' => 'auto'],
            ['caption' => 'Europe (de)', 'value' => 'de'],
            ['caption' => 'China (cn)', 'value' => 'cn'],
            ['caption' => 'USA (us)', 'value' => 'us'],
            ['caption' => 'Russia (ru)', 'value' => 'ru'],
            ['caption' => 'Singapore (sg)', 'value' => 'sg'],
            ['caption' => 'India (in)', 'value' => 'in'],
            ['caption' => 'India 2 (i2)', 'value' => 'i2'],
            ['caption' => 'Taiwan (tw)', 'value' => 'tw'],
        ];
    }

    public function anmeldeText(array $buendel): string
    {
        return RoboHubText::t('✅ Signed in at Xiaomi. Stored are only the access keys, not the password. The robots are controlled locally in your home network — Symcon and robot must be in the same network.');
    }

    // =================================================================
    // Anmeldung per QR-Code (bevorzugt)
    // =================================================================

    /** Schritt 1: QR-Code holen. Rückgabe: ['bild' => data-URI, 'link', 'lp', 'ende', 'agent', 'geraetId']. */
    public function qrStarten(): array
    {
        $agent = self::agent();
        $geraetId = self::zufall(6, 'abcdefghijklmnopqrstuvwxyz');
        $url = self::KONTO . '/longPolling/loginUrl?' . http_build_query([
            '_qrsize' => '480', 'qs' => '%3Fsid%3Dxiaomiio%26_json%3Dtrue', 'callback' => self::STS, '_hasLogo' => 'false',
            'sid' => 'xiaomiio', 'serviceParam' => '', '_locale' => 'en_GB', '_dc' => (string)(int)(microtime(true) * 1000),
        ]);
        [$status, , $text] = $this->httpVoll('GET', $url, ['User-Agent: ' . $agent], null, [], 15);
        $j = self::json($text);
        if ($status !== 200 || !isset($j['qr'], $j['lp'])) {
            throw new RoboHubFehler(RoboHubText::t('Xiaomi did not provide a QR code (HTTP %s).', $status));
        }
        [$s2, $k2, $png] = $this->httpVoll('GET', (string)$j['qr'], ['User-Agent: ' . $agent], null, [], 15);
        if ($s2 !== 200 || $png === '') {
            throw new RoboHubFehler(RoboHubText::t('The QR code image could not be loaded (HTTP %s).', $s2));
        }
        $typ = (string)(self::kopf($k2, 'content-type')[0] ?? 'image/png');
        $this->dbg('Xiaomi', 'QR-Code geholt, gültig ' . (int)($j['timeout'] ?? 0) . ' s.');
        return [
            'bild' => 'data:' . (strpos($typ, 'image/') === 0 ? $typ : 'image/png') . ';base64,' . base64_encode($png),
            'link' => (string)($j['loginUrl'] ?? ''),
            'lp' => (string)$j['lp'],
            'ende' => time() + max(60, (int)($j['timeout'] ?? 300)),
            'agent' => $agent,
            'geraetId' => $geraetId,
        ];
    }

    /** Schritt 2 (Timer): kurz nachfragen, ob gescannt wurde. null = noch nicht. */
    public function qrPruefen(array $qr): ?array
    {
        [$status, , $text] = $this->httpVoll('GET', (string)$qr['lp'], ['User-Agent: ' . $qr['agent']], null, [], 4);
        if ($status !== 200) {
            return null;   // Long-Polling läuft noch (Abbruch durch kurze Frist) — beim nächsten Takt weiter
        }
        $j = self::json($text);
        if (!isset($j['ssecurity'], $j['location'])) {
            $this->dbg('Xiaomi', 'QR-Antwort ohne Schlüssel: ' . substr($text, 0, 200));
            return null;
        }
        $this->dbg('Xiaomi', 'QR-Code gescannt und bestätigt.');
        $token = $this->serviceToken((string)$j['location'], (string)$qr['agent']);
        return self::buendelAus($j, $token, (string)$qr['agent'], (string)$qr['geraetId']);
    }

    // =================================================================
    // Anmeldung per Passwort (Rückfall), ggf. mit E-Mail-Code
    // =================================================================

    public function anmelden(string $konto, string $passwort, string $region): array
    {
        $agent = self::agent();
        $geraetId = self::zufall(6, 'abcdefghijklmnopqrstuvwxyz');
        $kekse = ['userId' => $konto, 'sdkVersion' => 'accountsdk-18.8.15', 'deviceId' => $geraetId];
        [$status, $k1, $text] = $this->httpVoll('GET', self::KONTO . '/pass/serviceLogin?sid=xiaomiio&_json=true', ['User-Agent: ' . $agent], null, $kekse, 15);
        $kekse = self::kekseMerken($kekse, $k1);
        $j = self::json($text);
        if ($status !== 200 || (!isset($j['_sign']) && !isset($j['ssecurity']))) {
            throw new RoboHubFehler(RoboHubText::t('Xiaomi does not know this account (HTTP %s).', $status));
        }
        $felder = [
            'sid' => 'xiaomiio', 'hash' => strtoupper(md5($passwort)), 'callback' => self::STS,
            'qs' => '%3Fsid%3Dxiaomiio%26_json%3Dtrue', 'user' => $konto, '_sign' => (string)($j['_sign'] ?? ''), '_json' => 'true',
        ];
        [$status, $k2, $text] = $this->httpVoll('POST', self::KONTO . '/pass/serviceLoginAuth2?' . http_build_query($felder),
            ['User-Agent: ' . $agent, 'Content-Type: application/x-www-form-urlencoded'], '', $kekse, 15);
        $kekse = self::kekseMerken($kekse, $k2);
        $j = self::json($text);
        if (($j['captchaUrl'] ?? null) !== null && ($j['captchaUrl'] ?? '') !== '') {
            throw new RoboHubFehler(RoboHubText::t('Xiaomi asks for a captcha for this sign-in. Please use "📱 Show QR code" instead — no captcha needed there.'));
        }
        if (isset($j['ssecurity']) && strlen((string)$j['ssecurity']) > 4 && isset($j['location'])) {
            $token = $this->serviceToken((string)$j['location'], $agent);
            $b = self::buendelAus($j, $token, $agent, $geraetId);
            $b['server'] = $region;
            return $b;
        }
        if (isset($j['notificationUrl'])) {
            return $this->zweiterFaktorStarten((string)$j['notificationUrl'], $kekse, $agent, $geraetId, $region);
        }
        $this->dbg('Xiaomi', 'serviceLoginAuth2: ' . substr($text, 0, 300));
        throw new RoboHubFehler(RoboHubText::t('Xiaomi rejected e-mail or password.'));
    }

    /** Xiaomi will einen E-Mail-Code: anfordern und Zwischenstand als Vorstufe zurückgeben (wirft immer). */
    private function zweiterFaktorStarten(string $url, array $kekse, string $agent, string $geraetId, string $region): array
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $kontext = (string)($q['context'] ?? '');
        $kopf = ['User-Agent: ' . $agent, 'Content-Type: application/x-www-form-urlencoded'];
        [, $k, ] = $this->httpVoll('GET', $url, $kopf, null, $kekse, 15);
        $kekse = self::kekseMerken($kekse, $k);
        [, $k, ] = $this->httpVoll('GET', self::KONTO . '/identity/list?' . http_build_query(['sid' => 'xiaomiio', 'context' => $kontext, '_locale' => 'en_US']), $kopf, null, $kekse, 15);
        $kekse = self::kekseMerken($kekse, $k);
        [$status, $k, $text] = $this->httpVoll('POST', self::KONTO . '/identity/auth/sendEmailTicket?' . http_build_query([
            '_dc' => (string)(int)(microtime(true) * 1000), 'sid' => 'xiaomiio', 'context' => $kontext, 'mask' => '0', '_locale' => 'en_US',
        ]), $kopf, http_build_query(['retry' => '0', 'icode' => '', '_json' => 'true', 'ick' => (string)($kekse['ick'] ?? '')]), $kekse, 15);
        $kekse = self::kekseMerken($kekse, $k);
        $this->dbg('Xiaomi', 'E-Mail-Code angefordert (HTTP ' . $status . ').');
        $e = new RoboHubFehler(RoboHubText::t('Xiaomi has sent a code to your e-mail. Enter it into the field "Password", click "Apply" and then "🔑 Sign in now" again.'));
        $e->vorstufe = ['xiaomi' => 1, 'kekse' => $kekse, 'kontext' => $kontext, 'agent' => $agent, 'geraetId' => $geraetId, 'region' => $region];
        throw $e;
    }

    /** Zweite Stufe: Code aus der E-Mail bestätigen und den Zugang abholen. */
    public function anmeldenMitCode(string $konto, string $code, array $vorstufe): array
    {
        $code = trim($code);
        if (!preg_match('/^\d{4,8}$/', $code)) {
            throw new RoboHubFehler(RoboHubText::t('Please enter the code from the Xiaomi e-mail (digits only).'));
        }
        $kekse = (array)($vorstufe['kekse'] ?? []);
        $agent = (string)($vorstufe['agent'] ?? self::agent());
        $kontext = (string)($vorstufe['kontext'] ?? '');
        $kopf = ['User-Agent: ' . $agent, 'Content-Type: application/x-www-form-urlencoded'];
        [$status, $k, $text] = $this->httpVoll('POST', self::KONTO . '/identity/auth/verifyEmail?' . http_build_query([
            '_flag' => '8', '_json' => 'true', 'sid' => 'xiaomiio', 'context' => $kontext, 'mask' => '0', '_locale' => 'en_US',
        ]), $kopf, http_build_query(['_flag' => '8', 'ticket' => $code, 'trust' => 'false', '_json' => 'true', 'ick' => (string)($kekse['ick'] ?? '')]), $kekse, 15);
        $kekse = self::kekseMerken($kekse, $k);
        $j = self::json($text);
        if ($status !== 200 || (isset($j['code']) && (int)$j['code'] !== 0 && !isset($j['location']))) {
            throw new RoboHubFehler(RoboHubText::t('Xiaomi did not accept the code — please sign in again to receive a new one.'));
        }
        $weiter = (string)($j['location'] ?? (self::kopf($k, 'location')[0] ?? ''));
        if ($weiter === '') {
            $weiter = self::KONTO . '/identity/result/check?' . http_build_query(['sid' => 'xiaomiio', 'context' => $kontext, '_locale' => 'en_US']);
        }
        // Weiterleitungen einzeln gehen: Auth2/end trägt ssecurity im Kopf „extension-pragma“, danach setzt STS den serviceToken.
        $ssecurity = '';
        $token = '';
        for ($i = 0; $i < 8 && $weiter !== '' && $token === ''; $i++) {
            [$status, $k, $text] = $this->httpVoll('GET', $weiter, $kopf, null, $kekse, 15);
            $kekse = self::kekseMerken($kekse, $k);
            $pragma = json_decode((string)(self::kopf($k, 'extension-pragma')[0] ?? ''), true);
            if (is_array($pragma) && ($pragma['ssecurity'] ?? '') !== '') {
                $ssecurity = (string)$pragma['ssecurity'];
            }
            $token = (string)($kekse['serviceToken'] ?? '');
            $naechste = (string)(self::kopf($k, 'location')[0] ?? '');
            if ($naechste === '' && $status === 200 && strpos($text, self::STS) !== false && strpos($weiter, self::STS) !== 0) {
                // Manche Server liefern erst eine Hinweisseite mit der STS-Adresse im Text.
                $naechste = preg_match('~' . preg_quote(self::STS, '~') . '[^"\'\s<]*~', $text, $m) ? html_entity_decode($m[0]) : '';
            }
            $weiter = $naechste;
        }
        if ($ssecurity === '' || $token === '') {
            throw new RoboHubFehler(RoboHubText::t('Xiaomi confirmed the code, but did not hand out the access. Please use "📱 Show QR code" instead.'));
        }
        $b = self::buendelAus(['ssecurity' => $ssecurity, 'userId' => $kekse['userId'] ?? '', 'cUserId' => $kekse['cUserId'] ?? '',
            'passToken' => $kekse['passToken'] ?? ''], $token, $agent, (string)($vorstufe['geraetId'] ?? ''));
        $b['server'] = (string)($vorstufe['region'] ?? 'auto');
        return $b;
    }

    /** Bündel regelmäßig über den passToken auffrischen (ohne Passwort). Scheitert das, bleibt das alte. */
    public function erneuern(array $buendel): array
    {
        if (time() - (int)($buendel['zeit'] ?? 0) < self::ERNEUERN_NACH || ($buendel['passToken'] ?? '') === '') {
            return $buendel;
        }
        try {
            return $this->mitPassToken($buendel);
        } catch (RoboHubFehler $e) {
            $this->dbg('Xiaomi', 'Erneuern über passToken gescheitert: ' . $e->getMessage());
            return $buendel;
        }
    }

    private function mitPassToken(array $b): array
    {
        $kekse = ['userId' => (string)$b['userId'], 'passToken' => (string)$b['passToken'], 'deviceId' => (string)($b['geraetId'] ?? ''), 'sdkVersion' => 'accountsdk-18.8.15'];
        [$status, , $text] = $this->httpVoll('GET', self::KONTO . '/pass/serviceLogin?sid=xiaomiio&_json=true', ['User-Agent: ' . $b['agent']], null, $kekse, 15);
        $j = self::json($text);
        if ($status !== 200 || !isset($j['ssecurity'], $j['location'])) {
            $e = new RoboHubFehler(RoboHubText::t('Xiaomi no longer accepts the stored access — please sign in again.'));
            $e->anmeldungNoetig = true;
            throw $e;
        }
        $token = $this->serviceToken((string)$j['location'], (string)$b['agent']);
        $neu = self::buendelAus($j + ['passToken' => $b['passToken']], $token, (string)$b['agent'], (string)($b['geraetId'] ?? ''));
        $neu['server'] = (string)($b['server'] ?? 'auto');
        $this->dbg('Xiaomi', 'Zugang über passToken erneuert.');
        return $neu;
    }

    /** location aufrufen (ohne Weiterleitung folgen), serviceToken steht im Set-Cookie. */
    private function serviceToken(string $location, string $agent): string
    {
        $kekse = [];
        $url = $location;
        for ($i = 0; $i < 4 && $url !== ''; $i++) {
            [, $k, ] = $this->httpVoll('GET', $url, ['User-Agent: ' . $agent], null, $kekse, 15);
            $kekse = self::kekseMerken($kekse, $k);
            if (($kekse['serviceToken'] ?? '') !== '') {
                return (string)$kekse['serviceToken'];
            }
            $url = (string)(self::kopf($k, 'location')[0] ?? '');
        }
        throw new RoboHubFehler(RoboHubText::t('Xiaomi did not hand out a service token.'));
    }

    private static function buendelAus(array $j, string $token, string $agent, string $geraetId): array
    {
        return [
            'userId' => (string)($j['userId'] ?? ''), 'cUserId' => (string)($j['cUserId'] ?? ''),
            'ssecurity' => (string)$j['ssecurity'], 'serviceToken' => $token, 'passToken' => (string)($j['passToken'] ?? ''),
            'agent' => $agent, 'geraetId' => $geraetId, 'server' => 'auto', 'zeit' => time(),
        ];
    }

    // =================================================================
    // Geräte (Cloud, RC4-verschlüsselt)
    // =================================================================

    public function geraete(array $buendel): array
    {
        $server = (string)($buendel['server'] ?? 'auto');
        $liste = in_array($server, self::SERVER, true) ? [$server] : self::SERVER;
        $alle = [];
        foreach ($liste as $s) {
            $r = $this->api($buendel, $s, '/home/device_list', ['getVirtualModel' => true, 'getHuamiDevices' => 1, 'get_split_device' => false, 'support_smart_home' => true]);
            $geraete = (array)($r['result']['list'] ?? []);
            $this->dbg('Xiaomi', 'Server ' . $s . ': ' . count($geraete) . ' Geräte.');
            foreach ($geraete as $g) {
                $modell = (string)($g['model'] ?? '');
                if (strpos($modell, '.vacuum.') === false) {
                    continue;
                }
                $alle[] = [
                    'id' => (string)($g['did'] ?? ''),
                    'name' => (string)($g['name'] ?? $modell),
                    'modell' => $modell,
                    'klasse' => RoboHubModell::KLASSE_SAUGER,
                    'online' => (bool)($g['isOnline'] ?? false),
                    'protokoll' => self::miioV1($modell) ? '1.0' : 'miot',
                    'schluessel' => (string)($g['token'] ?? ''),
                    'ip' => (string)($g['localip'] ?? ''),
                    'server' => $s,
                    'roh' => array_diff_key($g, ['token' => 1, 'extra' => 1]),
                ];
            }
            if ($alle !== []) {
                break;   // Konto liegt auf diesem Server
            }
        }
        return $alle;
    }

    /** Roborock-Altgeräte sprechen die miIO-Befehle der Roborock-App; Xiaomi-eigene Sauger (MIoT) noch nicht. */
    public static function miioV1(string $modell): bool
    {
        return strpos($modell, 'roborock.vacuum.') === 0 || strpos($modell, 'rockrobo.vacuum.') === 0;
    }

    /** Verschlüsselte API-Anfrage an {server}.api.io.mi.com/app. */
    private function api(array $b, string $server, string $pfad, array $daten): array
    {
        $url = 'https://' . ($server === 'cn' ? '' : $server . '.') . 'api.io.mi.com/app' . $pfad;
        $nonce = self::nonce();
        $signiert = self::signierterNonce((string)$b['ssecurity'], $nonce);
        $felder = self::verschluesselteFelder('POST', $url, $signiert, $nonce, ['data' => (string)json_encode($daten)], (string)$b['ssecurity']);
        $kopf = [
            'User-Agent: ' . $b['agent'], 'Accept-Encoding: identity', 'Content-Type: application/x-www-form-urlencoded',
            'x-xiaomi-protocal-flag-cli: PROTOCAL-HTTP2', 'MIOT-ENCRYPT-ALGORITHM: ENCRYPT-RC4',
        ];
        $kekse = [
            'userId' => (string)$b['userId'], 'yetAnotherServiceToken' => (string)$b['serviceToken'], 'serviceToken' => (string)$b['serviceToken'],
            'locale' => 'en_GB', 'timezone' => 'GMT+01:00', 'is_daylight' => '0', 'dst_offset' => '0', 'channel' => 'MI_APP_STORE',
        ];
        [$status, , $text] = $this->httpVoll('POST', $url, $kopf, http_build_query($felder), $kekse, 15);
        if ($status === 0) {
            throw new RoboHubFehler(RoboHubText::t('Xiaomi cloud not reachable: %s', $text));
        }
        if ($status === 401 || $status === 403) {
            $e = new RoboHubFehler(RoboHubText::t('Xiaomi rejected the access — please sign in again.'));
            $e->anmeldungNoetig = true;
            throw $e;
        }
        $klar = self::rc4(self::signierterNonce((string)$b['ssecurity'], $felder['_nonce']), (string)base64_decode($text));
        $j = json_decode($klar, true);
        if (!is_array($j)) {
            throw new RoboHubFehler(RoboHubText::t('Unexpected response from the Xiaomi cloud (HTTP %s).', $status));
        }
        $code = (int)($j['code'] ?? 0);
        $meldung = (string)($j['message'] ?? '');
        if (in_array($code, [2, 3], true) || stripos($meldung, 'auth err') !== false || in_array($meldung, ['invalid signature', 'SERVICETOKEN_EXPIRED'], true)) {
            $e = new RoboHubFehler(RoboHubText::t('Xiaomi rejected the access — please sign in again.'));
            $e->anmeldungNoetig = true;
            throw $e;
        }
        return $j;
    }

    public static function nonce(?int $millis = null, ?string $zufall = null): string
    {
        $millis = $millis ?? (int)(microtime(true) * 1000);
        return base64_encode(($zufall ?? random_bytes(8)) . pack('N', intdiv($millis, 60000)));
    }

    public static function signierterNonce(string $ssecurity, string $nonce): string
    {
        return base64_encode(hash('sha256', base64_decode($ssecurity) . base64_decode($nonce), true));
    }

    /** rc4_hash__, Felder RC4-verschlüsseln, signature, ssecurity, _nonce (Reihenfolge wie im Original). */
    public static function verschluesselteFelder(string $methode, string $url, string $signiert, string $nonce, array $felder, string $ssecurity): array
    {
        $felder['rc4_hash__'] = self::encSignatur($methode, $url, $signiert, $felder);
        foreach ($felder as $k => $v) {
            $felder[$k] = base64_encode(self::rc4($signiert, (string)$v));
        }
        $felder['signature'] = self::encSignatur($methode, $url, $signiert, $felder);
        $felder['ssecurity'] = $ssecurity;
        $felder['_nonce'] = $nonce;
        return $felder;
    }

    public static function encSignatur(string $methode, string $url, string $signiert, array $felder): string
    {
        $teile = [strtoupper($methode), str_replace('/app/', '/', (string)substr($url, (int)strpos($url, 'com') + 3))];
        foreach ($felder as $k => $v) {
            $teile[] = $k . '=' . $v;
        }
        $teile[] = $signiert;
        return base64_encode(sha1(implode('&', $teile), true));
    }

    /** RC4 mit verworfenen ersten 1024 Bytes (wie die Mi-Home-App); eigene Umsetzung, OpenSSL 3 hat RC4 nur im Legacy-Provider. */
    public static function rc4(string $schluesselB64, string $daten): string
    {
        $schluessel = (string)base64_decode($schluesselB64);
        $s = range(0, 255);
        $j = 0;
        $n = strlen($schluessel);
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($schluessel[$i % $n])) & 255;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }
        $i = $j = 0;
        $aus = '';
        $gesamt = 1024 + strlen($daten);
        for ($x = 0; $x < $gesamt; $x++) {
            $i = ($i + 1) & 255;
            $j = ($j + $s[$i]) & 255;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            if ($x >= 1024) {
                $aus .= chr(ord($daten[$x - 1024]) ^ $s[($s[$i] + $s[$j]) & 255]);
            }
        }
        return $aus;
    }

    // =================================================================
    // Karte: Raumnummern ohne Namen (Mi Home kennt keine Raumnamen in der Cloud)
    // =================================================================

    public function karte(array $buendel, array $geraet): array
    {
        $this->pruefeProtokoll($geraet);
        $raeume = [];
        try {
            $zuordnung = $this->rpc($buendel, $geraet, 'get_room_mapping', []);
            foreach (self::raumZuordnung(is_array($zuordnung) ? $zuordnung : []) as $segment => $iot) {
                $raeume[$segment] = 'Room ' . $segment;
            }
        } catch (RoboHubFehler $e) {
            $this->dbg('Xiaomi', 'Keine Räume: ' . $e->getMessage());   // ältere Modelle (S5) kennen keine Räume
        }
        ksort($raeume);
        return ['raeume' => $raeume, 'bild' => '', 'marken' => [], 'station' => null];
    }

    // =================================================================
    // Lokales miIO (UDP 54321)
    // =================================================================

    protected function pruefeProtokoll(array $geraet): void
    {
        if ((string)($geraet['protokoll'] ?? '1.0') !== '1.0') {
            throw new RoboHubFehler(RoboHubText::t('This Xiaomi robot (%s) uses the MIoT protocol, which RoboHub does not support yet.', (string)($geraet['modell'] ?? '?')));
        }
        if (!preg_match('/^[0-9a-f]{32}$/i', (string)($geraet['schluessel'] ?? ''))) {
            throw new RoboHubFehler(RoboHubText::t('Device token missing — please click "Search devices now" in the account instance.'));
        }
        if (!filter_var((string)($geraet['ip'] ?? ''), FILTER_VALIDATE_IP)) {
            throw new RoboHubFehler(RoboHubText::t('Xiaomi does not know a local IP address for this robot — is it connected to Wi-Fi?'));
        }
    }

    protected function rpcMehrere(array $buendel, array $geraet, array $anfragen): array
    {
        $ip = (string)$geraet['ip'];
        $token = (string)hex2bin((string)$geraet['schluessel']);
        $ergebnisse = array_fill(0, count($anfragen), null);
        if (!$this->hallo($ip)) {
            $this->dbg('Xiaomi', 'Roboter unter ' . $ip . ' antwortet nicht (UDP ' . self::MIIO_PORT . ').');
            return $ergebnisse;
        }
        $beantwortet = 0;
        foreach (array_values($anfragen) as $i => [$methode, $params]) {
            $id = ++$this->naechsteId;
            $paket = self::paketBauen($token, (int)$this->sitzung[$ip]['did'], $this->stempel($ip),
                (string)json_encode(['id' => $id, 'method' => (string)$methode, 'params' => $params]));
            $frist = microtime(true) + 3.0;
            $antwort = $this->udpAustausch($ip, $paket, 3.0);
            while ($antwort !== null) {
                $daten = self::paketLesen($antwort, $token);
                if ($daten !== null && isset($daten['stempel']) && $daten['stempel'] > 0) {
                    $this->sitzung[$ip]['stempel'] = $daten['stempel'];
                    $this->sitzung[$ip]['zeit'] = microtime(true);
                }
                $j = $daten !== null ? json_decode(rtrim((string)$daten['nutzlast'], "\0"), true) : null;
                if (is_array($j) && (int)($j['id'] ?? -1) === $id) {
                    $ergebnisse[$i] = isset($j['error'])
                        ? ['fehler' => (string)($j['error']['message'] ?? json_encode($j['error']))]
                        : ['ergebnis' => $j['result'] ?? null];
                    $beantwortet++;
                    break;
                }
                $rest = $frist - microtime(true);
                $antwort = $rest > 0 ? $this->udpLesen($ip, $rest) : null;   // fremde/alte Antwort übergangen
            }
        }
        $this->dbg('Xiaomi', $beantwortet . ' von ' . count($anfragen) . ' Anfragen beantwortet.');
        return $ergebnisse;
    }

    /** Hello-Paket: liefert Geräte-ID und Zeitstempel (Token kommt aus der Cloud, nicht aus der Antwort). */
    private function hallo(string $ip): bool
    {
        if (isset($this->sitzung[$ip]) && microtime(true) - $this->sitzung[$ip]['zeit'] < 60) {
            return true;
        }
        $antwort = $this->udpAustausch($ip, self::halloPaket(), 3.0);
        if ($antwort === null || strlen($antwort) < 32 || substr($antwort, 0, 2) !== "\x21\x31") {
            return false;
        }
        $kopf = unpack('nmagie/nlaenge/Nunbekannt/Ndid/Nstempel', substr($antwort, 0, 16));
        $this->sitzung[$ip] = ['did' => (int)$kopf['did'], 'stempel' => (int)$kopf['stempel'], 'zeit' => microtime(true)];
        return true;
    }

    private function stempel(string $ip): int
    {
        return (int)$this->sitzung[$ip]['stempel'] + (int)floor(microtime(true) - $this->sitzung[$ip]['zeit']);
    }

    public static function halloPaket(): string
    {
        return "\x21\x31\x00\x20" . str_repeat("\xff", 28);
    }

    public static function paketBauen(string $token, int $did, int $stempel, string $nutzlast): string
    {
        $schluessel = md5($token, true);
        $iv = md5($schluessel . $token, true);
        $verschluesselt = (string)openssl_encrypt($nutzlast, 'aes-128-cbc', $schluessel, OPENSSL_RAW_DATA, $iv);
        $kopf = pack('nnNNN', 0x2131, 32 + strlen($verschluesselt), 0, $did, $stempel);
        return $kopf . md5($kopf . $token . $verschluesselt, true) . $verschluesselt;
    }

    /** Antwort prüfen (md5) und entschlüsseln. null = kaputt/fremd. */
    public static function paketLesen(string $paket, string $token): ?array
    {
        if (strlen($paket) < 32 || substr($paket, 0, 2) !== "\x21\x31") {
            return null;
        }
        $kopf = unpack('nmagie/nlaenge/Nunbekannt/Ndid/Nstempel', substr($paket, 0, 16));
        $verschluesselt = (string)substr($paket, 32, max(0, (int)$kopf['laenge'] - 32));
        if ($verschluesselt === '') {
            return ['did' => (int)$kopf['did'], 'stempel' => (int)$kopf['stempel'], 'nutzlast' => ''];
        }
        if (!hash_equals(md5(substr($paket, 0, 16) . $token . $verschluesselt, true), substr($paket, 16, 16))) {
            return null;
        }
        $schluessel = md5($token, true);
        $klar = openssl_decrypt($verschluesselt, 'aes-128-cbc', $schluessel, OPENSSL_RAW_DATA, md5($schluessel . $token, true));
        if ($klar === false) {
            return null;
        }
        return ['did' => (int)$kopf['did'], 'stempel' => (int)$kopf['stempel'], 'nutzlast' => $klar];
    }

    /** Ein Paket senden und auf eine Antwort warten; im Prüfstand ersetzbar. */
    protected function udpAustausch(string $ip, string $paket, float $frist): ?string
    {
        if (!isset($this->udp[$ip])) {
            $s = @stream_socket_client('udp://' . $ip . ':' . self::MIIO_PORT, $nr, $text, 3);
            if ($s === false) {
                $this->dbg('Xiaomi', 'UDP ' . $ip . ': ' . $text);
                return null;
            }
            $this->udp[$ip] = $s;
        }
        @fwrite($this->udp[$ip], $paket);
        return $this->udpLesen($ip, $frist);
    }

    protected function udpLesen(string $ip, float $frist): ?string
    {
        if (!isset($this->udp[$ip])) {
            return null;
        }
        $lesen = [$this->udp[$ip]];
        $schreiben = $fehler = null;
        $sek = (int)floor($frist);
        if (@stream_select($lesen, $schreiben, $fehler, $sek, (int)(($frist - $sek) * 1000000)) < 1) {
            return null;
        }
        $daten = @fread($this->udp[$ip], 65535);
        return is_string($daten) && $daten !== '' ? $daten : null;
    }

    // =================================================================
    // HTTP mit Köpfen und Keksen, ohne Weiterleitungen (Prüfstand ersetzt das)
    // =================================================================

    /** Rückgabe: [Status, Köpfe (klein => [Werte]), Text]. */
    protected function httpVoll(string $methode, string $url, array $kopf, ?string $body, array $kekse, int $frist): array
    {
        $ch = curl_init($url);
        $koepfe = [];
        if ($kekse !== []) {
            $teile = [];
            foreach ($kekse as $k => $v) {
                $teile[] = $k . '=' . $v;
            }
            $kopf[] = 'Cookie: ' . implode('; ', $teile);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $methode,
            CURLOPT_HTTPHEADER     => $kopf,
            CURLOPT_TIMEOUT        => $frist,
            CURLOPT_CONNECTTIMEOUT => min(8, $frist),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($c, string $zeile) use (&$koepfe): int {
                $p = strpos($zeile, ':');
                if ($p !== false) {
                    $koepfe[strtolower(trim(substr($zeile, 0, $p)))][] = trim(substr($zeile, $p + 1));
                }
                return strlen($zeile);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $text = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = curl_error($ch);
        $this->dbg('HTTP', $methode . ' ' . preg_replace('/[?].*$/', '?…', $url) . ' → ' . $status);
        if ($text === false) {
            return [0, [], $fehler];
        }
        return [$status, $koepfe, (string)$text];
    }

    private static function kopf(array $koepfe, string $name): array
    {
        return (array)($koepfe[strtolower($name)] ?? []);
    }

    /** Set-Cookie-Köpfe in die Keksdose übernehmen (abgelaufene/leere Werte löschen). */
    public static function kekseMerken(array $kekse, array $koepfe): array
    {
        foreach (self::kopf($koepfe, 'set-cookie') as $zeile) {
            $paar = explode(';', $zeile, 2)[0];
            $p = strpos($paar, '=');
            if ($p === false) {
                continue;
            }
            $name = trim(substr($paar, 0, $p));
            $wert = trim(substr($paar, $p + 1));
            if ($wert === '' || $wert === 'EXPIRED' || stripos($zeile, 'max-age=0') !== false) {
                unset($kekse[$name]);
            } else {
                $kekse[$name] = $wert;
            }
        }
        return $kekse;
    }

    private static function json(string $text): array
    {
        $j = json_decode(str_replace('&&&START&&&', '', $text), true);
        return is_array($j) ? $j : [];
    }

    private static function agent(): string
    {
        return self::zufall(18, 'abcdefghijklmnopqrstuvwxyz') . '-' . self::zufall(13, 'ABCDE') . ' APP/com.xiaomi.mihome APPV/10.5.201';
    }

    private static function zufall(int $n, string $zeichen): string
    {
        $aus = '';
        for ($i = 0; $i < $n; $i++) {
            $aus .= $zeichen[random_int(0, strlen($zeichen) - 1)];
        }
        return $aus;
    }
}
