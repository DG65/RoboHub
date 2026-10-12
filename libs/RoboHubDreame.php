<?php

declare(strict_types=1);

require_once __DIR__ . '/RoboHubModell.php';
require_once __DIR__ . '/RoboHubKartenbild.php';

// ===========================================================================
// RoboHub-Treiber für die Dreame- und MOVA-Cloud (Dreamehome-/MOVAhome-App).
//
// Quellen (MIT-lizenziert, am Quellcode gegengelesen am 11.10.2026, nicht aus
// Zusammenfassungen):
//   • github.com/TA2k/ioBroker.dreame 0.4.12 (main.js, lib/dreame.js,
//     lib/specs/*, docs/mower-protocol.md) — Login, Endpunkte, Mäher
//   • github.com/Tasshack/dreame-vacuum 2.0.1 (dreame/types.py, device.py,
//     protocol.py) — Property-Zuordnung, Zustands- und Fehlerwerte
//
// Grundsätze:
//   • Passwort nur für die Erstanmeldung; danach trägt der (rotierende)
//     Refresh-Token. Läuft er ab, meldet der Treiber „Anmeldung nötig".
//   • Abfrage per HTTP (kein MQTT in Version 0.1). Einige neuere Sauger —
//     belegt für dreame.vacuum.r95475 (Aqua10 Ultra Roller Complete) —
//     liefern Zustand und Akku NICHT per get_properties, sondern nur per
//     MQTT-Push. Beides steht aber in der Geräteliste (latestStatus/battery);
//     dieselbe Lösung nutzt ioBroker (_bridgeStatusSummary).
//   • Unbekannte/fehlende Werte bleiben null, nie 0.
// ===========================================================================

class RoboHubDreame implements RoboHubTreiber
{
    private const MARKEN = [
        'dreame' => [
            'domain'  => 'iot.dreame.tech',
            'tenant'  => '000000',
            'basic'   => 'ZHJlYW1lX2FwcHYxOkFQXmR2QHpAU1FZVnhOODg=',
            'rlc'     => 'EETjszu*XI5znHsI',
            'prefix'  => '10000',
        ],
        'mova' => [
            'domain'  => 'iot.mova-tech.com',
            'tenant'  => '000002',
            'basic'   => 'bW92YV9hcHA6VjdLb0NoTFc4dkhBQ3FHYg==',
            'rlc'     => 'gigxlmqwZ]7oWZUF',
            'prefix'  => '20000',
        ],
    ];

    // Salt für den Passwort-Hash, in beiden Referenzprojekten identisch.
    private const SALT = 'RAylYC%fmSKp7%Tq';
    private const PORT = 13267;

    // Erneuern, wenn der Zugangsschlüssel in weniger als 10 min abläuft.
    private const ERNEUERN_VORLAUF_S = 600;

    /** Optionaler Debug-Kanal: function(string $thema, string $text). */
    public $debug = null;

    private $marke;

    public function __construct(string $marke)
    {
        $this->marke = isset(self::MARKEN[$marke]) ? $marke : 'dreame';
    }

    /** Regionen fürs Formular. Nur „Europa" ist an echten Konten belegt. */
    public static function regionen(): array
    {
        return [
            ['caption' => 'Europa (auch DE/AT/CH)', 'value' => 'eu'],
            ['caption' => 'Nordamerika (ungeprüft)', 'value' => 'us'],
            ['caption' => 'Singapur/Asien (ungeprüft)', 'value' => 'sg'],
            ['caption' => 'Russland (ungeprüft)', 'value' => 'ru'],
            ['caption' => 'Südkorea (ungeprüft)', 'value' => 'kr'],
            ['caption' => 'China (ungeprüft)', 'value' => 'cn'],
        ];
    }

    // =================================================================
    // Anmeldung
    // =================================================================

    public function anmelden(string $konto, string $passwort, string $region): array
    {
        $region = $this->pruefeRegion($region);
        $body = http_build_query([
            'grant_type' => 'password',
            'scope'      => 'all',
            'platform'   => 'IOS',
            'type'       => 'account',
            'username'   => $konto,
            'password'   => md5($passwort . self::SALT),
            'country'    => 'DE',
            'lang'       => 'de',
        ]);
        $antwort = $this->tokenAnfrage($region, $body, true);
        return $this->buendelAus($antwort, $region);
    }

    public function erneuern(array $buendel): array
    {
        if (empty($buendel['refresh_token'])) {
            $f = new RoboHubFehler('Kein Erneuerungsschlüssel vorhanden — bitte neu anmelden.');
            $f->anmeldungNoetig = true;
            throw $f;
        }
        if ((int)($buendel['expires_at'] ?? 0) - time() > self::ERNEUERN_VORLAUF_S) {
            return $buendel;
        }
        $region = (string)($buendel['region'] ?? 'eu');
        $body = http_build_query([
            'grant_type'    => 'refresh_token',
            'scope'         => 'all',
            'platform'      => 'IOS',
            'type'          => 'account',
            'refresh_token' => (string)$buendel['refresh_token'],
        ]);
        $antwort = $this->tokenAnfrage($region, $body, false);
        return $this->buendelAus($antwort, $region);
    }

    private function tokenAnfrage(string $region, string $body, bool $erstanmeldung): array
    {
        $headers = $this->basisHeader($region, 'DE');
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Dreame-Auth: bearer';
        [$status, $text] = $this->http('POST', $this->basisUrl($region) . '/dreame-auth/oauth/token', $headers, $body);
        $json = json_decode($text, true);

        if ($status >= 400 && $status < 500 && $status !== 429) {
            $grund = is_array($json) ? (string)($json['error_description'] ?? $json['msg'] ?? $json['error'] ?? '') : '';
            $f = new RoboHubFehler($erstanmeldung
                ? 'Anmeldung abgelehnt (HTTP ' . $status . ($grund !== '' ? ': ' . $grund : '') . '). E-Mail, Passwort, Hersteller und Region prüfen.'
                : 'Der Zugangsschlüssel wurde abgelehnt (HTTP ' . $status . ') — bitte in der Konto-Instanz neu anmelden.');
            $f->anmeldungNoetig = !$erstanmeldung;
            throw $f;
        }
        if ($status === 429 || $status >= 500 || $status === 0) {
            $f = new RoboHubFehler($status === 0
                ? 'Hersteller-Cloud nicht erreichbar (' . $text . ').'
                : 'Hersteller-Cloud antwortet mit HTTP ' . $status . ' — später erneut versuchen.');
            $f->warteSekunden = $status === 0 ? 120 : 300;
            throw $f;
        }
        if (!is_array($json) || empty($json['access_token'])) {
            throw new RoboHubFehler('Unerwartete Antwort bei der Anmeldung (kein Zugangsschlüssel).');
        }
        return $json;
    }

    private function buendelAus(array $antwort, string $region): array
    {
        return [
            'marke'         => $this->marke,
            'region'        => preg_match('/^[a-z]{2}$/', (string)($antwort['region'] ?? '')) ? (string)$antwort['region'] : $region,
            'country'       => (string)($antwort['country'] ?? 'DE'),
            'access_token'  => (string)$antwort['access_token'],
            // Der Refresh-Token rotiert — immer den neuen übernehmen.
            'refresh_token' => (string)($antwort['refresh_token'] ?? ''),
            'expires_at'    => time() + max(300, (int)($antwort['expires_in'] ?? 3600)),
            'uid'           => (string)($antwort['uid'] ?? ''),
            'tenant_id'     => (string)($antwort['tenant_id'] ?? self::MARKEN[$this->marke]['tenant']),
        ];
    }

    // =================================================================
    // Geräte
    // =================================================================

    public function geraete(array $buendel): array
    {
        $out = [];
        foreach ($this->geraeteRoh($buendel) as $r) {
            $did = (string)($r['did'] ?? '');
            if ($did === '') {
                continue;
            }
            $modell = (string)($r['model'] ?? '');
            $name = (string)($r['customName'] ?? '');
            if ($name === '') {
                $name = (string)($r['deviceInfo']['displayName'] ?? $modell);
            }
            $out[] = [
                'id'     => $did,
                'name'   => $name,
                'modell' => $modell,
                'klasse' => self::klasseVon($modell),
                'online' => !empty($r['online']),
                'roh'    => ['bindDomain' => $r['bindDomain'] ?? null, 'ver' => $r['ver'] ?? null, 'masterUid' => $r['masterUid'] ?? null],
            ];
        }
        return $out;
    }

    /**
     * Offizielles Produktbild aus der Geräteliste (deviceInfo.mainImage,
     * ersatzweise overlook/icon — Feldnamen aus ioBroker main.js, Beispiel-
     * datensatz). Nur https-Adressen; das Bild wird nicht kopiert, sondern
     * von der Kachel direkt beim Hersteller geladen (wie in der App).
     */
    public static function bildVon(array $record): string
    {
        foreach (['mainImage', 'overlook', 'icon', 'popup'] as $feld) {
            $url = (string)($record['deviceInfo'][$feld]['imageUrl'] ?? '');
            if (self::istBildUrl($url)) {
                return $url;
            }
        }
        // Sicherheitsnetz für Modelle, die das Bild an anderer Stelle führen.
        // (Live 11.10.2026: Dreame r9547a und MOVA g2529c liefern mainImage.)
        $treffer = self::bildPfade($record);
        return $treffer ? (string)reset($treffer) : '';
    }

    private static function istBildUrl(string $url): bool
    {
        return (bool)preg_match('#^https://[^\s"\'<>]+$#', $url);
    }

    /** Alle https-Bildadressen im Datensatz: [Pfad => URL] (für Rohdaten/Fehlersuche). */
    public static function bildPfade(array $daten, string $pfad = ''): array
    {
        $out = [];
        foreach ($daten as $k => $v) {
            $p = $pfad === '' ? (string)$k : $pfad . '.' . $k;
            if (is_array($v)) {
                $out += self::bildPfade($v, $p);
            } elseif (is_string($v) && self::istBildUrl($v) && preg_match('#\.(png|jpe?g|webp|gif)(\?|$)#i', $v)) {
                $out[$p] = $v;
            }
        }
        return $out;
    }

    /** Sauger oder Mäher — ioBroker erkennt Mäher ebenfalls am Modellnamen. */
    public static function klasseVon(string $modell): string
    {
        return stripos($modell, 'mower') !== false ? RoboHubModell::KLASSE_MAEHER : RoboHubModell::KLASSE_SAUGER;
    }

    private function geraeteRoh(array $buendel): array
    {
        $antwort = $this->api($buendel, '/dreame-user-iot/iotuserbind/device/listV2', [
            'sharedStatus' => 1, 'current' => 1, 'size' => 100, 'lang' => 'de', 'timestamp' => (int)round(microtime(true) * 1000),
        ]);
        $records = $antwort['data']['page']['records'] ?? null;
        if (!is_array($records)) {
            throw new RoboHubFehler('Unerwartete Antwort der Geräteliste.');
        }
        return $records;
    }

    // =================================================================
    // Zustand
    // =================================================================

    // Abfrageblöcke je Klasse. Klein gehalten, weil ein Block mit einer
    // nicht unterstützten SIID als Ganzes scheitern kann (ioBroker-Befund).
    private const BLOECKE_SAUGER = [
        [[2, 1], [2, 2], [3, 1], [3, 2]],
        [[4, 1], [4, 2], [4, 3], [4, 4], [4, 5], [4, 6], [4, 7], [4, 23], [4, 25], [4, 63]],
        [[9, 2], [10, 2], [11, 1], [16, 1], [18, 1], [35, 2]],
        [[27, 1], [27, 2], [27, 3], [27, 4]],
        [[28, 1]],
        [[12, 2], [12, 3], [12, 4]],
        // Reststunden (ioBroker lib/specs/consumables.js, Einheit h). Eigener
        // Block AM ENDE: die Blocksperre (0.2.1) merkt sich Block-Indizes,
        // neue Blöcke dürfen die bestehenden Indizes nicht verschieben.
        [[9, 1], [10, 1], [11, 2], [16, 2], [18, 2]],
        // Schmutzwasserbehälter am Roboter (ioBroker consumables.js: 26.1 %,
        // 26.2 h) und Stunden des Abwasserauslassfilters (Tasshack: 35.1 h,
        // 35.2 % steht schon in Block 2). Ebenfalls am Ende (Blocksperre).
        [[26, 1], [26, 2], [35, 1]],
        // Nicht stören (5.1 an/aus, 5.2/5.3 Zeiten; neuere Geräte stattdessen
        // 5.4 = JSON-Liste von Zeitfenstern) und Lautstärke 7.1 — Tasshack
        // types.py + ioBroker main.js. Getrennte Blöcke, weil nicht jedes
        // Modell alle kennt. Am Ende (Blocksperre, siehe oben).
        [[5, 1], [5, 2], [5, 3], [5, 4]],
        [[7, 1]],
        // Zeitpläne 8.2 (Tasshack SCHEDULE, ioBroker lib/schedule.js). Am Ende (Blocksperre).
        [[8, 2]],
    ];
    private const BLOECKE_MAEHER = [
        [[2, 1], [2, 2], [3, 1], [3, 2]],
        [[4, 1], [4, 2], [4, 3], [4, 7]],
    ];

    public function zustand(array $buendel, array $geraet): array
    {
        $did = (string)$geraet['id'];
        $modell = (string)($geraet['modell'] ?? '');
        $klasse = self::klasseVon($modell);

        // 1) Zusammenfassung aus der Geräteliste (online, latestStatus, battery).
        $eintrag = null;
        foreach ($this->geraeteRoh($buendel) as $r) {
            if ((string)($r['did'] ?? '') === $did) {
                $eintrag = $r;
                break;
            }
        }
        if ($eintrag === null) {
            throw new RoboHubFehler('Gerät ' . $did . ' ist nicht mehr im Konto.');
        }
        $online = !empty($eintrag['online']);

        // 2) Properties blockweise. Ein Offline-Gerät nicht weiter befragen.
        // Blöcke, auf die dieses Gerät nachweislich nicht antwortet, gibt das
        // Konto in 'ueberspringen' mit (Indizes) — jede unbeantwortete Abfrage
        // kostet sonst einige Sekunden Wartezeit (Live-Befund LiDAX, Block 4.x).
        $ueberspringen = array_map('intval', (array)($geraet['ueberspringen'] ?? []));
        $p = [];
        $fehl = [];
        $ok = [];
        if ($online) {
            foreach ($klasse === RoboHubModell::KLASSE_MAEHER ? self::BLOECKE_MAEHER : self::BLOECKE_SAUGER as $i => $block) {
                if (in_array($i, $ueberspringen, true)) {
                    continue;
                }
                try {
                    $p += $this->leseProperties($buendel, $did, $block);
                    $ok[] = $i;
                } catch (RoboHubFehler $e) {
                    if ($e->anmeldungNoetig || $e->warteSekunden > 0) {
                        throw $e;
                    }
                    $fehl[] = $i;
                }
            }
        }
        $fehlBloecke = count($fehl);
        // Zusammenfassung als Grundwert, echte Abfrage gewinnt (Reihenfolge wie ioBroker).
        if (!isset($p['2.1']) && isset($eintrag['latestStatus']) && $klasse === RoboHubModell::KLASSE_SAUGER) {
            $p['2.1'] = (int)$eintrag['latestStatus'];
        }
        if (!isset($p['3.1']) && isset($eintrag['battery'])) {
            $p['3.1'] = (int)$eintrag['battery'];
        }

        $daten = $this->normalisiere($modell, $p);
        if ($klasse === RoboHubModell::KLASSE_MAEHER && $online) {
            $daten['werte'] += $this->maeherVerschleiss($buendel, $did);
            $einstellungen = $this->maeherEinstellungen($buendel, $did);
            $daten['werte'] += $einstellungen;
            $daten['schreibbar'] = array_keys($einstellungen);
        }
        $daten['online'] = $online;
        $daten['bild'] = self::bildVon($eintrag);
        $daten['bloecke'] = ['ok' => $ok, 'fehl' => $fehl];
        $daten['roh'] = [
            'modell'        => $modell,
            'firmware'      => $eintrag['ver'] ?? null,
            'liste'         => ['online' => $eintrag['online'] ?? null, 'latestStatus' => $eintrag['latestStatus'] ?? null, 'battery' => $eintrag['battery'] ?? null,
                                'felder' => array_keys($eintrag), 'deviceInfo' => array_keys((array)($eintrag['deviceInfo'] ?? [])), 'bilder' => self::bildPfade($eintrag)],
            'properties'    => $p,
            'fehlendeBloecke' => $fehlBloecke,
            'uebersprungeneBloecke' => $ueberspringen,
        ];
        return $daten;
    }

    /**
     * Rohwerte ['siid.piid' => Wert] → normalisierter Zustand (werte/optionen).
     * Ohne Netz — wird auch für Echtzeit-Meldungen (MQTT) genutzt, dort mit
     * dem letzten vollständigen Stand plus den geänderten Werten, damit
     * abgeleitete Werte (z. B. Reinigungsmodus-Tausch) korrekt bleiben.
     */
    public function normalisiere(string $modell, array $p): array
    {
        return self::klasseVon($modell) === RoboHubModell::KLASSE_MAEHER
            ? $this->normalisiereMaeher($p, $modell)
            : $this->normalisiereSauger($p);
    }

    // =================================================================
    // Echtzeit (MQTT) — Werte aus ioBroker main.js connectMqtt()
    // =================================================================

    private const MQTT_ERSATZ = ['dreame' => ['app.mt.%s.iot.dreame.tech', 19973], 'mova' => ['app.mt.%s.iot.mova-tech.com', 19974]];

    /**
     * Private Wurzel-CA der Dreame/MOVA-MQTT-Server (nennt sich „GlobalSign
     * Root CA", ist aber eine eigene Stelle — mit öffentlichen CAs schlägt die
     * Prüfung fehl). Am 11.10.2026 von 10000.mt.eu.iot.dreame.tech:19973 und
     * app.mt.eu.iot.mova-tech.com:19974 gelesen, beide identisch, gültig bis
     * 2969, SHA-256 6D:B9:EA:84:C7:E4:C9:AE:C6:92:CD:54:0F:F5:23:81:F5:E3:7D:
     * AE:47:DF:8E:BE:B9:48:A4:46:1A:EA:E4:25. Damit prüft RoboHub Zertifikat
     * UND Hostnamen, statt die Prüfung (wie ioBroker) abzuschalten.
     */
    public const MQTT_WURZEL = <<<PEM
-----BEGIN CERTIFICATE-----
MIIDDzCCAfegAwIBAgIJAPvMK3fFXUEgMA0GCSqGSIb3DQEBCwUAMB0xGzAZBgNV
BAMMEkdsb2JhbFNpZ24gUm9vdCBDQTAgFw02OTEyMzExNjAwMDBaGA8yOTY5MDUw
MjE2MDAwMFowHTEbMBkGA1UEAwwSR2xvYmFsU2lnbiBSb290IENBMIIBIjANBgkq
hkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtdVI+1rZpIpB8YlmnzWFnvJyGzlBbxEh
RwgSanox6OypwX27htEFFJzMxz5YGv9nABm3b3SUB+0zcAkJYw/Q9lZoT1w0m9Cr
5KJMxofpD8JjsZWFE5hvJ5NIyRS4tOgimGsIfvHGMzihZ9sLnq7kBtX7u5hIv/A5
eSvifaroS2NombTFMQrV6ZNIvpFALbMUbp02+mexm7P59c7OzJVupQAWJSFOepIu
UwhyYLe1oR/HTiKcIuzDSp49QxhSNYuLpSUOYo/YU4GTMoKEhXD7u+AyeOzuHrnV
H7+/U7TlXFtMkyt+ZwQw+s8+/jzi4v9jG5XL82uVbr2FN0EC0PEhrwIDAQABo1Aw
TjAdBgNVHQ4EFgQUQXiLQz2aun8vl2Hy1RCja8nZ4qMwHwYDVR0jBBgwFoAUQXiL
Qz2aun8vl2Hy1RCja8nZ4qMwDAYDVR0TBAUwAwEB/zANBgkqhkiG9w0BAQsFAAOC
AQEAaAuVj1RNRRXfV7wk2VtLr6aKPoi7mlG9OSijHxAbmwV1KMyGR6gUpqToBpe/
xQN+AjJK1Unex0hkN7+RZhpe8ajWqtniZ+oq9Xd+be0JW2KqZmA18r5arJj4DiCt
Asa557/RNxuiIMc/uwvPtVq2C9uPX562esS02e2bRODH+l+aKcFzeCKotccJGej7
NrfwypYpmoqrwko/4pe9cMBc3O1CLl2ZYy+YreHMFitmL6xGnC4a6HuTcZgK/ntj
zewB8u+e2dMQwnBclahtLtb8oMmCHCWlgMTVU1MrjlWldvULalpMnIE5gsHKNXeC
z0PJitlGIfun4TuQQhVCf5B2aA==
-----END CERTIFICATE-----
PEM;

    /** [Host, Port] des MQTT-Servers: bindDomain des ersten Geräts, sonst Ersatz je Marke/Region. */
    public function mqttZiel(array $geraete, array $buendel): array
    {
        foreach ($geraete as $g) {
            $bind = (string)($g['roh']['bindDomain'] ?? '');
            if (preg_match('/^([a-z0-9.-]+):(\d{2,5})$/i', $bind, $m)) {
                return [$m[1], (int)$m[2]];
            }
        }
        [$muster, $port] = self::MQTT_ERSATZ[$this->marke];
        return [sprintf($muster, $this->pruefeRegion((string)($buendel['region'] ?? 'eu'))), $port];
    }

    /** Topic je Gerät: /status/<did>/<uid>/<model>/<region>/ (bei geteilten Geräten die uid des Besitzers). */
    public function mqttTopic(array $geraet, array $buendel): string
    {
        $uid = (string)($geraet['roh']['masterUid'] ?? '');
        if ($uid === '') {
            $uid = (string)($buendel['uid'] ?? '');
        }
        return '/status/' . $geraet['id'] . '/' . $uid . '/' . ($geraet['modell'] ?? '') . '/' . ($buendel['region'] ?? 'eu') . '/';
    }

    /** [Benutzer, Passwort] für CONNECT: uid + Zugangsschlüssel. */
    public function mqttZugang(array $buendel): array
    {
        return [(string)($buendel['uid'] ?? ''), (string)($buendel['access_token'] ?? '')];
    }

    /**
     * Zerlegt eine MQTT-Nachricht (properties_changed) in ['did' => ['siid.piid' => Wert]].
     * Karten (6.x) und binäre Mäher-Telemetrie (1.x) werden übergangen.
     */
    public function pushLesen(string $payload): array
    {
        $j = json_decode($payload, true);
        if (!is_array($j) || ($j['data']['method'] ?? '') !== 'properties_changed') {
            return [];
        }
        $out = [];
        $did = (string)($j['did'] ?? $j['data']['did'] ?? '');
        foreach ((array)($j['data']['params'] ?? []) as $e) {
            if (!is_array($e) || !array_key_exists('value', $e)) {
                continue;
            }
            $siid = (int)($e['siid'] ?? 0);
            $piid = (int)($e['piid'] ?? 0);
            $ziel = (string)($e['did'] ?? $did);
            // Positionen: Sauger-Kartenrahmen 6.1 (Kopf), Mäher-Telemetrie 1.4.
            // Alles andere aus 1.x/6.x (Karte, Rohtelemetrie) bleibt draußen.
            $position = null;
            if ($siid === 6 && $piid === 1 && is_string($e['value'])) {
                $position = self::kopfPosition($e['value']);
            } elseif ($siid === 1 && $piid === 4 && is_array($e['value'])) {
                $position = self::maeherPosition($e['value']);
            }
            if ($position !== null) {
                $out[$ziel]['_position'] = $position;
            }
            if (in_array($siid, [1, 6], true) || $siid <= 0) {
                continue;
            }
            $out[$ziel][$siid . '.' . $piid] = $e['value'];
        }
        return $out;
    }

    /**
     * Mäher-Telemetrie 1.4 (Bytefolge, beginnt mit 0xCE; ioBroker main.js):
     * x = 20 Bit aus Byte 1–3, y = 20 Bit aus Byte 3–5, beide mit Vorzeichen,
     * Einheit 1 cm (×10 = mm wie die Karte), Byte 6 = Winkel (0–255 → 0–360°).
     */
    public static function maeherPosition(array $b): ?array
    {
        if (count($b) < 7 || (int)$b[0] !== 0xCE) {
            return null;
        }
        $b = array_map(function ($v) { return ((int)$v) & 0xFF; }, $b);
        $s32 = function (int $v): int {
            $v &= 0xFFFFFFFF;
            return $v >= 0x80000000 ? $v - 0x100000000 : $v;
        };
        $x = $s32(($b[3] << 28) | ($b[2] << 20) | ($b[1] << 12)) >> 12;
        $y = $s32(($b[5] << 24) | ($b[4] << 16) | ($b[3] << 8)) >> 12;
        return [$x * 10, $y * 10, (int)round($b[6] / 255 * 360)];
    }

    /** get_properties für einen Block; Rückgabe ['siid.piid' => Wert] (nur gelieferte Werte). */
    private function leseProperties(array $buendel, string $did, array $block): array
    {
        $params = [];
        foreach ($block as [$siid, $piid]) {
            $params[] = ['did' => $did, 'siid' => $siid, 'piid' => $piid];
        }
        $ergebnis = $this->sendCommand($buendel, $did, 'get_properties', $params);
        $out = [];
        foreach ((array)$ergebnis as $e) {
            if (!is_array($e) || (int)($e['code'] ?? 0) !== 0 || !array_key_exists('value', $e)) {
                continue;
            }
            $out[(int)$e['siid'] . '.' . (int)$e['piid']] = $e['value'];
        }
        return $out;
    }

    // Zustand 2.1, neue Belegung (Tasshack DreameVacuumState, Fähigkeit NEW_STATE).
    private const SAUGER_ZUSTAND = [
        1 => ['Saugt', RoboHubModell::ZUSTAND_REINIGT],
        2 => ['Leerlauf', RoboHubModell::ZUSTAND_BEREIT],
        3 => ['Pausiert', RoboHubModell::ZUSTAND_PAUSIERT],
        4 => ['Fehler', RoboHubModell::ZUSTAND_FEHLER],
        5 => ['Fährt zur Station', RoboHubModell::ZUSTAND_ZURUECK],
        6 => ['Lädt', RoboHubModell::ZUSTAND_LAEDT],
        7 => ['Wischt', RoboHubModell::ZUSTAND_REINIGT],
        8 => ['Trocknet Mopp', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        9 => ['Wäscht Mopp', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        10 => ['Fährt zum Mopp-Waschen', RoboHubModell::ZUSTAND_ZURUECK],
        11 => ['Kartiert', RoboHubModell::ZUSTAND_KARTIERUNG],
        12 => ['Saugt und wischt', RoboHubModell::ZUSTAND_REINIGT],
        13 => ['Geladen', RoboHubModell::ZUSTAND_GELADEN],
        14 => ['Firmware-Update', RoboHubModell::ZUSTAND_AKTUALISIERUNG],
        15 => ['Rufreinigung', RoboHubModell::ZUSTAND_REINIGT],
        16 => ['Selbstreparatur', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        17 => ['Fährt zum Mopp-Anbringen', RoboHubModell::ZUSTAND_ZURUECK],
        18 => ['Fährt zum Mopp-Abnehmen', RoboHubModell::ZUSTAND_ZURUECK],
        19 => ['Wassertest', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        20 => ['Füllt Wasser nach', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        21 => ['Waschen pausiert', RoboHubModell::ZUSTAND_PAUSIERT],
        22 => ['Saugt Staub ab', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        23 => ['Fernsteuerung', RoboHubModell::ZUSTAND_REINIGT],
        24 => ['Intelligentes Laden', RoboHubModell::ZUSTAND_LAEDT],
        25 => ['Zweite Reinigung', RoboHubModell::ZUSTAND_REINIGT],
        27 => ['Punktreinigung', RoboHubModell::ZUSTAND_REINIGT],
        28 => ['Fährt zum Absaugen', RoboHubModell::ZUSTAND_ZURUECK],
        29 => ['Wartet auf Auftrag', RoboHubModell::ZUSTAND_BEREIT],
        30 => ['Stationsreinigung', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        31 => ['Entleert', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        32 => ['Entleert', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        34 => ['Leert Tank', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        35 => ['Trocknet Staubbeutel', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        36 => ['Trocknet Staubbeutel', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        37 => ['Extrareinigung', RoboHubModell::ZUSTAND_REINIGT],
        38 => ['Extrareinigung', RoboHubModell::ZUSTAND_REINIGT],
        97 => ['Kurzbefehl läuft', RoboHubModell::ZUSTAND_REINIGT],
        101 => ['Erst-Tiefenreinigung', RoboHubModell::ZUSTAND_REINIGT],
        102 => ['Erst-Tiefenreinigung', RoboHubModell::ZUSTAND_REINIGT],
        103 => ['Desinfektion', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        104 => ['Desinfektion', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        105 => ['Moppwechsel', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        106 => ['Moppwechsel', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        116 => ['Bringt Mopp an', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        117 => ['Nimmt Mopp ab', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        121 => ['Fährt in die Station', RoboHubModell::ZUSTAND_STATIONSARBEIT],
        122 => ['Fährt aus der Station', RoboHubModell::ZUSTAND_STATIONSARBEIT],
    ];

    // Fehler 2.2 (Auszug, Tasshack DreameVacuumErrorCode). Unbekannte Codes
    // erscheinen als „Fehlercode N" — nie still verschluckt.
    private const SAUGER_FEHLER = [
        1 => 'Roboter umgekippt/angehoben', 2 => 'Absturzsensor ausgelöst', 3 => 'Stoßfänger klemmt',
        4 => 'Rad blockiert', 5 => 'Rad angehoben', 6 => 'Rad blockiert', 7 => 'Laser-Abstandssensor blockiert',
        8 => 'Staubbehälter fehlt', 9 => 'Filter fehlt', 10 => 'Filter verstopft', 11 => 'Staubbehälter voll',
        12 => 'Hauptbürste blockiert', 13 => 'Seitenbürste blockiert', 14 => 'Lüfter-Fehler',
        15 => 'Wassertank fehlt', 20 => 'Akku schwach', 21 => 'Ladefehler', 22 => 'Akku-Fehler',
        24 => 'Wischmopp fehlt', 47 => 'Roboter festgefahren', 101 => 'Schmutzwassertank fehlt',
        102 => 'Schmutzwassertank voll', 103 => 'Frischwasser leer', 104 => 'Frischwassertank fehlt',
        105 => 'Wasserwanne fehlt', 106 => 'Staubbeutel voll', 107 => 'Wasserwanne verschmutzt',
        117 => 'Staubbeutel fehlt', 210 => 'Wischwalze blockiert', 218 => 'Wischwalze prüfen',
        222 => 'Auflockerungswalze blockiert', 225 => 'Wischwalze prüfen', 1000 => 'Rückkehr zur Station fehlgeschlagen',
    ];

    private function normalisiereSauger(array $p): array
    {
        $w = [];
        $optionen = [];
        if (isset($p['2.1'])) {
            $roh = (int)$p['2.1'];
            [$text, $zustand] = self::SAUGER_ZUSTAND[$roh] ?? ['Zustand ' . $roh, RoboHubModell::ZUSTAND_UNBEKANNT];
            $w['Zustand'] = $zustand;
            $w['ZustandRoh'] = $text . ' (' . $roh . ')';
        }
        if (isset($p['2.2'])) {
            $code = (int)$p['2.2'];
            $w['Fehler'] = $code === 0 ? 'kein Fehler' : (self::SAUGER_FEHLER[$code] ?? 'Fehlercode ' . $code) . ' (' . $code . ')';
            if ($code !== 0 && ($w['Zustand'] ?? null) === null) {
                $w['Zustand'] = RoboHubModell::ZUSTAND_FEHLER;
            }
        }
        $w['Akku'] = isset($p['3.1']) ? (int)$p['3.1'] : null;
        $w['Laedt'] = isset($p['3.2']) ? in_array((int)$p['3.2'], [1], true) : null;
        $w['Dauer'] = isset($p['4.2']) ? (int)$p['4.2'] : null;
        $w['Flaeche'] = isset($p['4.3']) ? (int)$p['4.3'] : null;

        if (isset($p['4.4'])) {
            $w['Saugstufe'] = (int)$p['4.4'];
            $optionen['Saugstufe'] = [0 => 'Leise', 1 => 'Standard', 2 => 'Stark', 3 => 'Turbo'];
        }
        // Wassermenge: neuere Modelle (Feuchte-Stufen 28.1) melden 4.5 nicht
        // oder ohne Wirkung — dann bleibt die Variable weg statt falsch zu sein.
        if (isset($p['4.5']) && !isset($p['28.1'])) {
            $w['Wassermenge'] = (int)$p['4.5'];
            $optionen['Wassermenge'] = [1 => 'Niedrig', 2 => 'Mittel', 3 => 'Hoch'];
        }
        if (isset($p['4.23'])) {
            $w['Reinigungsmodus'] = $this->reinigungsmodusLesen((int)$p['4.23'], $p);
            $optionen['Reinigungsmodus'] = [0 => 'Saugen', 1 => 'Wischen', 2 => 'Saugen und Wischen', 3 => 'Erst saugen, dann wischen'];
        }

        $w['Hauptbuerste']  = isset($p['9.2']) ? (int)$p['9.2'] : null;
        $w['Seitenbuerste'] = isset($p['10.2']) ? (int)$p['10.2'] : null;
        $w['Filter']        = isset($p['11.1']) ? (int)$p['11.1'] : null;   // Filter: % an piid 1 (Belegung so belegt)
        $w['Sensoren']      = isset($p['16.1']) ? (int)$p['16.1'] : null;
        $w['Mopp']          = isset($p['18.1']) ? (int)$p['18.1'] : null;
        $w['HauptbuersteRest']  = isset($p['9.1']) ? (float)$p['9.1'] : null;
        $w['SeitenbuersteRest'] = isset($p['10.1']) ? (float)$p['10.1'] : null;
        $w['FilterRest']        = isset($p['11.2']) ? (float)$p['11.2'] : null;   // Filter: Stunden an piid 2
        $w['SensorenRest']      = isset($p['16.2']) ? (float)$p['16.2'] : null;
        $w['MoppRest']          = isset($p['18.2']) ? (float)$p['18.2'] : null;
        // Reihenfolge %/h bei 35 nur aus Tasshack; an Dietmars Gerät beide 0,
        // also live nicht unterscheidbar (siehe CLAUDE.md).
        $w['Abwasserfilter']             = isset($p['35.2']) ? (int)$p['35.2'] : null;
        $w['AbwasserfilterRest']         = isset($p['35.1']) ? (float)$p['35.1'] : null;
        $w['Schmutzwasserbehaelter']     = isset($p['26.1']) ? (int)$p['26.1'] : null;
        $w['SchmutzwasserbehaelterRest'] = isset($p['26.2']) ? (float)$p['26.2'] : null;

        if (isset($p['4.25'])) {
            $w['Station'] = [0 => 'Bereit', 1 => 'Wäscht', 2 => 'Trocknet', 3 => 'Roboter kehrt zurück', 4 => 'Pausiert', 5 => 'Füllt Wasser', 6 => 'Füllt Wasser', 7 => 'Kehrt zum Trocknen zurück'][(int)$p['4.25']] ?? 'Zustand ' . (int)$p['4.25'];
        }
        if (isset($p['27.1'])) {
            $w['Frischwasser'] = [0 => 'In Ordnung', 1 => 'Tank fehlt', 2 => 'Wenig Wasser', 3 => 'Prüfen'][(int)$p['27.1']] ?? 'Zustand ' . (int)$p['27.1'];
        }
        if (isset($p['27.2'])) {
            $w['Schmutzwasser'] = (int)$p['27.2'] === 0 ? 'In Ordnung' : 'Fehlt oder voll';
        }
        if (isset($p['27.3'])) {
            $w['Staubbeutel'] = [0 => 'In Ordnung', 1 => 'Fehlt', 2 => 'Prüfen'][(int)$p['27.3']] ?? 'Zustand ' . (int)$p['27.3'];
        }
        if (isset($p['27.4'])) {
            $w['Reinigungsmittel'] = [0 => 'In Ordnung', 1 => 'Abgeschaltet', 2 => 'Wenig'][(int)$p['27.4']] ?? 'Zustand ' . (int)$p['27.4'];
        }
        $w['GesamtDauer']  = isset($p['12.2']) ? (int)round((int)$p['12.2'] / 60) : null;
        $w['GesamtAnzahl'] = isset($p['12.3']) ? (int)$p['12.3'] : null;
        $w['GesamtFlaeche'] = isset($p['12.4']) ? (int)$p['12.4'] : null;

        $schreibbar = [];
        $dnd = self::nichtStoerenLesen($p);
        if ($dnd !== null) {
            [$w['NichtStoeren'], $w['NichtStoerenVon'], $w['NichtStoerenBis']] = $dnd;
            array_push($schreibbar, 'NichtStoeren', 'NichtStoerenVon', 'NichtStoerenBis');
        }
        if (isset($p['7.1']) && is_numeric($p['7.1'])) {
            $w['Lautstaerke'] = (int)$p['7.1'];
            $schreibbar[] = 'Lautstaerke';
        }

        $daten = ['werte' => $w, 'optionen' => $optionen, 'schreibbar' => $schreibbar];
        if (array_key_exists('8.2', $p) && is_string($p['8.2'])) {
            $daten['zeitplaene'] = self::zeitplaeneLesen($p['8.2']);
        }
        return $daten;
    }

    /**
     * Reinigungsmodus 4.23 ist gepackt (Bits 0–1 Modus). Geräte mit
     * anhebbarem Mopp tauschen 0 und 2 auf der Leitung (ioBroker
     * lib/specs/cleaning.js, Tasshack device.py). ioBroker erkennt sie an
     * „Waschstation + Absaugstation"; hier: Waschstation-Zustand (4.25) UND
     * Staubbeutel-Zustand (27.3) werden geliefert.
     */
    private function hatMoppAnhebung(array $p): bool
    {
        return isset($p['4.25']) && isset($p['27.3']);
    }

    private function reinigungsmodusLesen(int $roh, array $p): int
    {
        return self::tauscheModus($roh & 3, $this->hatMoppAnhebung($p));
    }

    private static function tauscheModus(int $modus, bool $anhebung): int
    {
        if (!$anhebung) {
            return $modus;
        }
        return $modus === 2 ? 0 : ($modus === 0 ? 2 : $modus);
    }

    // Mäher 2.1. Wo sich die Quellen widersprechen, gelten die Beschriftungen
    // der Hersteller-App (EvotecIT app_protocol.py MOWER_STATE_LABELS, en+zh,
    // mit LiDAX-Rückmeldungen; deckt sich mit dem ioBroker-README):
    // 3 = Pause, 4 = Pause wegen Fehler, 15/16 = Laden pausiert (Temperatur).
    // ioBrokers Code nennt dort 3 = Mähen, 4 = Pause, 15/16 = Ortung/Anfahrt.
    // Werte nur aus ioBroker (7–12) ohne Widerspruch übernommen.
    private const MAEHER_ZUSTAND = [
        1 => ['Mäht', RoboHubModell::ZUSTAND_REINIGT],
        2 => ['Bereit', RoboHubModell::ZUSTAND_BEREIT],
        3 => ['Pausiert', RoboHubModell::ZUSTAND_PAUSIERT],
        4 => ['Pausiert wegen Fehler', RoboHubModell::ZUSTAND_FEHLER],
        5 => ['Fährt zur Station', RoboHubModell::ZUSTAND_ZURUECK],
        6 => ['Lädt', RoboHubModell::ZUSTAND_LAEDT],
        7 => ['Fehler', RoboHubModell::ZUSTAND_FEHLER],
        8 => ['Regenpause', RoboHubModell::ZUSTAND_WETTERPAUSE],
        9 => ['Startet', RoboHubModell::ZUSTAND_BEREIT],
        10 => ['Verlässt die Station', RoboHubModell::ZUSTAND_REINIGT],
        11 => ['Kartiert', RoboHubModell::ZUSTAND_KARTIERUNG],
        12 => ['Mäht Kanten', RoboHubModell::ZUSTAND_REINIGT],
        13 => ['Geladen', RoboHubModell::ZUSTAND_GELADEN],
        14 => ['Firmware-Update', RoboHubModell::ZUSTAND_AKTUALISIERUNG],
        15 => ['Laden pausiert — Akku zu heiß', RoboHubModell::ZUSTAND_LAEDT],
        16 => ['Laden pausiert — Akku zu kalt', RoboHubModell::ZUSTAND_LAEDT],
        // 19 kennt keine Referenz (ioBroker, EvotecIT, mova-lidax-ha, Homey-App).
        // Live beobachtet am MOVA LiDAX Ultra 1000 (g2529c, FW 4.3.6_0529) direkt
        // nach „Start", Akku sinkend, Besitzer bestätigt: mäht (11.10.2026).
        19 => ['Mäht', RoboHubModell::ZUSTAND_REINIGT],
        75 => ['Am Wartungspunkt pausiert', RoboHubModell::ZUSTAND_PAUSIERT],
    ];

    // Mäher-Gerätecode 2.2: gemeinsames Register aller Dreame/MOVA-Mäher
    // (EvotecIT/homeassistant-dreamelawnmower, device_code_semantics.py, MIT,
    // gelesen 11.10.2026). 'f' = Fehler/Warnung/Wartung, 'i' = reine Info.
    private const MAEHER_CODES = [
        0 => ['i', ''],
        1 => ['f', 'Roboter gekippt'], 2 => ['f', 'Roboter festgefahren'], 3 => ['f', 'Weg zur Station zu schmal'],
        4 => ['f', 'Linkes Antriebsrad gestört'], 5 => ['f', 'Rechtes Antriebsrad gestört'], 6 => ['f', 'Schnitthöhen-Motor gestört'],
        7 => ['f', 'Messerteller blockiert'], 8 => ['f', 'Messerteller-Seitenmotor gestört'], 9 => ['f', 'Stoßfänger gestört'],
        10 => ['f', 'Ladefehler'], 11 => ['f', 'Akku zu heiß'], 12 => ['f', 'Lidar blockiert'],
        13 => ['f', 'Lidar zu heiß (ohne Karte)'], 14 => ['f', 'Lidar zu heiß (mit Karte)'], 15 => ['f', 'Lidar zu heiß'],
        16 => ['f', 'Lidar verschmutzt'], 17 => ['f', 'Lidar gestört'], 18 => ['f', 'Ortungssignal schwach'],
        19 => ['f', 'Position verloren'], 20 => ['f', 'Sensor gestört'], 21 => ['f', 'Mäher in Sperrzone'],
        22 => ['f', 'Mäher außerhalb der Karte'], 23 => ['f', 'Not-Aus gedrückt'], 24 => ['f', 'Akku kritisch schwach'],
        25 => ['f', 'Kartendatei beschädigt'], 26 => ['f', 'Mäher zu weit von der Karte'], 27 => ['f', 'Person erkannt'],
        37 => ['f', 'Weg blockiert'], 73 => ['f', 'Abdeckung offen'],
        28 => ['f', 'Messer abgenutzt'], 29 => ['f', 'Stationsbürste abgenutzt'], 30 => ['f', 'Wartung fällig'],
        31 => ['f', 'Rückkehr zur Station fehlgeschlagen'], 32 => ['f', 'Andocken fehlgeschlagen'],
        33 => ['f', 'Ortung fehlgeschlagen (mit Karte)'], 34 => ['f', 'Ortung fehlgeschlagen (ohne Karte)'],
        35 => ['f', 'Ortungsfehler'], 36 => ['f', 'Auftrag konnte nicht starten'], 38 => ['f', 'Lidar verschmutzt'],
        39 => ['f', 'Kamera verschmutzt'], 40 => ['f', 'Kamera gestört'], 41 => ['f', 'Kamera verdeckt'],
        42 => ['f', 'Laden pausiert — Akku zu heiß'], 43 => ['f', 'Laden pausiert — Akku zu kalt'],
        44 => ['f', 'Automatische Randerkennung gestoppt'], 45 => ['f', 'Automatische Randerkennung (Seite) gestoppt'],
        46 => ['i', 'Randerkennung abgeschlossen'], 47 => ['i', 'Neue Karte erstellt'], 48 => ['i', 'Mähauftrag abgeschlossen'],
        49 => ['i', 'Einige Zonen nicht erreichbar'], 50 => ['i', 'Mähauftrag gestartet'], 51 => ['i', 'Patrouille gestartet'],
        52 => ['i', 'Zielfahrt gestartet'], 53 => ['i', 'Geplantes Mähen gestartet'], 54 => ['i', 'Akku schwach — kehrt zurück'],
        55 => ['i', 'Geplantes Mähen ausgesetzt — Akku schwach'], 56 => ['i', 'Schlechtwetter-Schutz aktiv'],
        57 => ['i', 'Geplantes Mähen wegen Regen unterbrochen'], 58 => ['i', 'Geplantes Mähen wegen Regen ausgesetzt'],
        59 => ['i', 'Frostschutz — kehrt zurück'], 60 => ['i', 'Geplantes Mähen wegen Frost ausgesetzt'],
        61 => ['i', 'Ruhezeit — kehrt zurück'], 62 => ['i', 'Geplantes Mähen wegen Ruhezeit ausgesetzt'],
        63 => ['i', 'Geplantes Mähen übersprungen — Mäher beschäftigt'], 64 => ['i', 'Geplantes Mähen wegen Fernsteuerung ausgesetzt'],
        65 => ['i', 'Geplantes Mähen wegen Not-Aus ausgesetzt'], 66 => ['i', 'Geplantes Mähen ausgesetzt — Abdeckung offen'],
        67 => ['i', 'Geplantes Mähen wegen Störung ausgesetzt'], 68 => ['i', 'Geplantes Mähen: Zeit abgelaufen'],
        69 => ['i', 'Station nicht mit dem Mähbereich verbunden'], 70 => ['i', 'Setzt unterbrochenen Auftrag fort'],
        71 => ['i', 'Leerlauf zu lang — kehrt zurück'], 72 => ['i', 'Pause zu lang — kehrt zurück'],
    ];

    // LiDAX Ultra 1000/2000 und 2000 AWD belegen 0 und 55 anders (dort mit
    // echten Geräten erfasst; ältere MOVA-Modelle bewusst NICHT einbezogen).
    private const MAEHER_CODES_LIDAX = [
        0 => ['f', 'Roboter angehoben'],
        55 => ['f', 'Start nicht möglich — Akku zu schwach'],
    ];
    private const LIDAX_MODELLE = ['mova.mower.g2529c', 'mova.mower.g2529f', 'mova.mower.g2584a'];

    private function normalisiereMaeher(array $p, string $modell): array
    {
        $w = [];
        if (isset($p['2.1'])) {
            $roh = (int)$p['2.1'];
            [$text, $zustand] = self::MAEHER_ZUSTAND[$roh] ?? ['Zustand ' . $roh, RoboHubModell::ZUSTAND_UNBEKANNT];
            $w['Zustand'] = $zustand;
            $w['ZustandRoh'] = $text . ' (' . $roh . ')';
            $w['Regensensor'] = $roh === 8;
        }
        // 2.2 ist beim Mäher ein Gerätecode: Fehler/Wartung → „Fehler",
        // reine Infos (Auftrag fertig, Regenschutz …) → „Letzte Meldung".
        if (isset($p['2.2'])) {
            $code = (int)$p['2.2'];
            $codes = self::MAEHER_CODES;
            if (in_array(strtolower($modell), self::LIDAX_MODELLE, true)) {
                $codes = self::MAEHER_CODES_LIDAX + $codes;
            }
            [$art, $text] = $codes[$code] ?? ['f', 'Gerätecode ' . $code];
            if ($art === 'f') {
                $w['Fehler'] = $text . ' (' . $code . ')';
            } else {
                $w['Fehler'] = 'kein Fehler';
                if ($text !== '') {
                    $w['Meldung'] = $text . ' (' . $code . ')';
                }
            }
        }
        $w['Akku'] = isset($p['3.1']) ? (int)$p['3.1'] : null;
        $w['Laedt'] = isset($p['3.2']) ? (int)$p['3.2'] === 1 : null;
        $w['Dauer'] = isset($p['4.2']) ? (int)$p['4.2'] : null;
        $w['Flaeche'] = isset($p['4.3']) ? (int)$p['4.3'] : null;
        return ['werte' => $w, 'optionen' => []];
    }

    /** Messer-Verschleiß über den Mäher-Konfigurationskanal (action 2.50, CMS). */
    private function maeherVerschleiss(array $buendel, string $did): array
    {
        try {
            $out = $this->sendCommand($buendel, $did, 'action', ['did' => $did, 'siid' => 2, 'aiid' => 50, 'in' => [['m' => 'g', 't' => 'CMS']]]);
        } catch (RoboHubFehler $e) {
            if ($e->anmeldungNoetig || $e->warteSekunden > 0) {
                throw $e;
            }
            return [];
        }
        $erstes = $out['out'][0] ?? null;
        if (is_string($erstes)) {
            $erstes = json_decode($erstes, true);
        }
        $werte = $erstes['d']['value'] ?? null;
        if (!is_array($werte) || !isset($werte[0])) {
            return [];
        }
        // Gesund in % = (1 − Minuten / Max) × 100; Max je Teil laut
        // docs/mower-protocol.md: Klingen 6000, Reinigungsbürste 30000,
        // Roboter-Wartung 3600 min. Alle drei am LiDAX Ultra 1000 gegen die
        // MOVAhome-App bestätigt (34 % / 87 % / 72 %, 11.10.2026).
        $prozent = function (int $index, int $max) use ($werte): ?int {
            return isset($werte[$index]) && is_numeric($werte[$index])
                ? max(0, min(100, (int)round((1 - (float)$werte[$index] / $max) * 100)))
                : null;
        };
        // Reststunden = (Max − verbrauchte Minuten) / 60, wie die App (z. B. 33,7 h).
        $rest = function (int $index, int $max) use ($werte): ?float {
            return isset($werte[$index]) && is_numeric($werte[$index])
                ? max(0.0, round(($max - (float)$werte[$index]) / 60, 1))
                : null;
        };
        return array_filter([
            'Messer'                => $prozent(0, 6000),
            'Reinigungsbuerste'     => $prozent(1, 30000),
            'Wartung'               => $prozent(2, 3600),
            'MesserRest'            => $rest(0, 6000),
            'ReinigungsbuersteRest' => $rest(1, 30000),
            'WartungRest'           => $rest(2, 3600),
        ], function ($v) { return $v !== null; });
    }

    // -----------------------------------------------------------------
    // Zeitpläne (Sauger 8.2)
    // -----------------------------------------------------------------

    // Raum-Termine: Modus/Saugstufe je Raum (ioBroker decodeRoomsWord), „Alle Räume": eigene Modus-Reihenfolge.
    private const TERMIN_MODUS_RAUM = [0 => 'Saugen', 1 => 'Wischen', 2 => 'Saugen und Wischen'];
    private const TERMIN_MODUS_ALLE = [1 => 'Saugen', 2 => 'Saugen und Wischen', 3 => 'Wischen', 4 => 'Erst saugen, dann wischen'];
    private const TERMIN_SAUGSTUFE = [0 => 'Leise', 1 => 'Standard', 2 => 'Stark', 3 => 'Turbo'];

    /**
     * 8.2 → Liste der Termine. Format je Termin (durch „;" getrennt, Felder durch „-"):
     * 0 ID, 1 Zustand (0 aus, 1/2 an, 3 ungültig — Tasshack), 2 Uhrzeit, 3 Wochentage
     * (7 Zeichen, Index 0 = Sonntag — ioBroker), 4 „0" = einmalig (Tasshack), 5 Karte,
     * 6–8 je nach Art (ioBroker detectScheduleType): Räume (Feld 8 = Wörter je Raum,
     * Bits 0–7 Raum, 8–11 Durchgänge, 16–19 Saugstufe, 20–23 Modus), Alle Räume
     * (Feld 7: Bits 4–7 Modus, 24–27 Saugstufe, 28–31 Durchgänge), Kurzbefehl (Feld 7 / 256).
     */
    public static function zeitplaeneLesen(string $text): array
    {
        $termine = [];
        foreach (explode(';', $text) as $teil) {
            $f = explode('-', trim($teil));
            if (count($f) < 9 || !ctype_digit($f[0]) || !preg_match('/^\d{1,2}:\d{2}$/', $f[2])) {
                continue;
            }
            $maske = str_pad($f[3], 7, '0', STR_PAD_LEFT);
            $tage = [];
            foreach ([1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 0 => 'So'] as $i => $tag) {
                if (($maske[$i] ?? '0') === '1') {
                    $tage[] = $tag;
                }
            }
            $t = [
                'id'        => (int)$f[0],
                'an'        => in_array($f[1], ['1', '2'], true),
                'ungueltig' => $f[1] === '3',
                'zeit'      => $f[2],
                'tage'      => $tage,
                'einmalig'  => $f[4] === '0',
                'art'       => 'unbekannt',
            ];
            if ($f[7] === '0' && $f[8] !== '0') {
                $t['art'] = 'raeume';
                $t['raeume'] = [];
                foreach (explode(',', $f[8]) as $wort) {
                    $w = (int)$wort;
                    $t['raeume'][] = ['id' => $w & 0xFF, 'durchgaenge' => ($w >> 8) & 0xF,
                        'saugstufe' => self::TERMIN_SAUGSTUFE[($w >> 16) & 0xF] ?? null, 'modus' => self::TERMIN_MODUS_RAUM[($w >> 20) & 0xF] ?? null];
                }
            } elseif ($f[7] !== '0' && $f[8] === '0' && $f[6] === '0') {
                $t['art'] = 'kurzbefehl';
                $t['kurzbefehl'] = ((int)$f[7]) >> 8;
            } elseif ($f[7] !== '0' && $f[8] === '0') {
                $w = (int)$f[7];
                $t['art'] = 'alle';
                $t['modus'] = self::TERMIN_MODUS_ALLE[($w >> 4) & 0xF] ?? null;
                $t['saugstufe'] = self::TERMIN_SAUGSTUFE[($w >> 24) & 0xF] ?? null;
                $t['durchgaenge'] = ($w >> 28) & 0xF;
            }
            $termine[] = $t;
        }
        usort($termine, function (array $a, array $b): int {
            return strcmp(str_pad($a['zeit'], 5, '0', STR_PAD_LEFT), str_pad($b['zeit'], 5, '0', STR_PAD_LEFT)) ?: ($a['id'] <=> $b['id']);
        });
        return $termine;
    }

    // Mäher-Zeitpläne (EvotecIT schedule.py / schedule_read_plan.py, MIT): über 2.50 je
    // Kartenplatz (−1 = Standard, 0, 1) erst SCHDIV<n> {"i": Platz} → {"l": Länge, "v": Version}
    // (0xFFFF = leer), dann SCHDDV<n> {"s": Versatz, "l": ≤ 100, "v": Version} → {"d": Text}.
    // Dokumentgeneration 2, sonst 3. Text = {"d": [[Plan-ID, an, Name, Base64-Termine], …]}.
    private const MAEHER_TAGE = [0 => 'So', 1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa'];
    private const MAEHER_PLANART = [0 => 'alle', 1 => 'zonen', 2 => 'kante', 3 => 'patrouille'];

    /** Rückgabe [Termine, Rohtext]; Fehler einzelner Plätze werden übergangen. */
    private function maeherZeitplaene(array $buendel, string $did): array
    {
        $termine = [];
        $roh = [];
        $protokoll = [];      // je Platz/Generation die Antwort — für „📋 Rohdaten", falls nichts gefunden wird
        foreach ([-1, 0, 1] as $platz) {
            // MOVA (LiDAX, Live-Befund 12.10.2026: SCHDIV2/3 → r 3) nutzt Tabellen:
            // SCHDI je Kartenplatz, dann SCHDC je Termin (EvotecIT read_tables).
            if ($platz >= 0) {
                try {
                    $termine = array_merge($termine, $this->maeherPlanTabellen($buendel, $did, $platz, $roh));
                    $protokoll[] = 'Platz ' . $platz . ' Tabellen: gelesen';
                    continue;
                } catch (RoboHubFehler $e) {
                    if ($e->anmeldungNoetig || $e->warteSekunden > 0) {
                        throw $e;
                    }
                    $protokoll[] = 'Platz ' . $platz . ' Tabellen: ' . $e->getMessage();
                }
            }
            foreach ([2, 3] as $gen) {
                try {
                    $info = $this->maeherKanal($buendel, $did, ['m' => 'g', 't' => 'SCHDIV' . $gen, 'd' => ['i' => $platz]]);
                } catch (RoboHubFehler $e) {
                    if ($e->anmeldungNoetig || $e->warteSekunden > 0) {
                        throw $e;
                    }
                    $protokoll[] = 'Platz ' . $platz . ' V' . $gen . ': ' . $e->getMessage();
                    continue;
                }
                $protokoll[] = 'Platz ' . $platz . ' V' . $gen . ': ' . substr((string)json_encode($info), 0, 200);
                if (!is_array($info) || !isset($info['l'], $info['v'])) {
                    continue;
                }
                $laenge = (int)$info['l'];
                $version = (int)$info['v'];
                if ($laenge <= 0 || $version === 0xFFFF) {
                    break;                                  // Platz leer
                }
                $text = '';
                for ($versatz = 0, $runde = 0; strlen($text) < $laenge && $runde < 60; $runde++) {
                    $stueck = $this->maeherKanal($buendel, $did, ['m' => 'g', 't' => 'SCHDDV' . $gen, 'd' => ['s' => strlen($text), 'l' => min(100, $laenge - strlen($text)), 'v' => $version]]);
                    $teil = is_array($stueck) ? (string)($stueck['d'] ?? '') : '';
                    if ($teil === '') {
                        break;
                    }
                    $text .= $teil;
                }
                if (strlen($text) !== $laenge) {
                    $this->dbg('Zeitpläne', 'Platz ' . $platz . ' unvollständig (' . strlen($text) . '/' . $laenge . ').');
                    break;
                }
                $roh[] = $text;
                foreach (self::maeherPlaeneLesen($text) as $t) {
                    $t['platz'] = $platz;
                    $termine[] = $t;
                }
                break;
            }
        }
        return [$termine, $roh !== [] ? implode("\n", $roh) : implode("\n", $protokoll)];
    }

    /**
     * Tabellenform (EvotecIT schedule_tables.py): SCHDI [2·Platz, 2·Platz+1] →
     * [[Tabellen-ID, an, ?, Name, [[Termin-ID, Version], …]], …]; je Termin
     * SCHDC [Tabellen-ID, Termin-ID] → [Termin-ID, an, Art, Start-Minute, [Tage 0=So], [Zonen]].
     * Nur Startzeit, kein Ende. Ausgeschaltete Termine lässt EvotecIT weg — hier auch.
     */
    private function maeherPlanTabellen(array $buendel, string $did, int $platz, array &$roh): array
    {
        $tabellen = $this->maeherKanal($buendel, $did, ['m' => 'g', 't' => 'SCHDI', 'd' => [2 * $platz, 2 * $platz + 1]]);
        if (!is_array($tabellen)) {
            throw new RoboHubFehler('SCHDI ohne Liste.');
        }
        $roh[] = 'SCHDI ' . $platz . ': ' . json_encode($tabellen, JSON_UNESCAPED_UNICODE);
        $termine = [];
        foreach ($tabellen as $t) {
            if (!is_array($t) || count($t) < 5 || !is_array($t[4])) {
                continue;
            }
            // Leere Platzhalter (kein Name, keine Termine) stehen in jeder Tabelle — LiDAX
            // meldet 4 Tabellen, belegt ist eine (Live-Befund 12.10.2026). Nicht anzeigen.
            if ($t[4] === [] && trim((string)$t[3]) === '') {
                continue;
            }
            $eintraege = [];
            foreach ($t[4] as $ref) {
                if (!is_array($ref) || !isset($ref[0])) {
                    continue;
                }
                try {
                    $termin = $this->maeherKanal($buendel, $did, ['m' => 'g', 't' => 'SCHDC', 'd' => [(int)$t[0], (int)$ref[0]]]);
                } catch (RoboHubFehler $e) {
                    if ($e->anmeldungNoetig || $e->warteSekunden > 0) {
                        throw $e;
                    }
                    continue;
                }
                $roh[] = 'SCHDC ' . (int)$t[0] . '/' . (int)$ref[0] . ': ' . json_encode($termin);
                $eintraege = array_merge($eintraege, self::maeherTabellenTermin($termin));
            }
            usort($eintraege, function (array $a, array $b): int {
                return (($a['tagNr'] + 6) % 7) <=> (($b['tagNr'] + 6) % 7) ?: strcmp($a['von'], $b['von']);
            });
            $tage = [];
            foreach ($eintraege as $e) {
                $tage[$e['tag']] = true;
            }
            $termine[] = [
                // id = Tabellennummer (eindeutig über alle Karten), nummer = Plan auf dieser Karte.
                'id' => (int)$t[0], 'nummer' => (int)$t[0] - 2 * $platz, 'an' => (int)$t[1] === 1, 'ungueltig' => false,
                'name' => (string)$t[3], 'art' => 'maeher', 'tage' => array_keys($tage), 'einmalig' => false,
                'zeit' => $eintraege[0]['von'] ?? '', 'eintraege' => $eintraege, 'platz' => $platz, 'tabelle' => (int)$t[0],
            ];
        }
        return $termine;
    }

    /**
     * Mäh-Plan (Tabelle) ein-/ausschalten wie EvotecIT plan_table_enabled:
     * nur bei ruhendem Mäher (Station/bereit — EvotecIT schedule_write_block_reason),
     * Einschalten nur mit mindestens einem aktiven Termin, Befehl
     * {"m":"s","t":"SCHDS","d":[Tabelle, 1/0]}, danach die Schalter beider Tabellen des
     * Kartenplatzes neu lesen — Einschalten kann den Nachbarplan ausschalten.
     */
    private function maehPlanSchalten(array $buendel, string $did, string $modell, int $tabelle, bool $an): string
    {
        if ($tabelle < 0) {
            throw new RoboHubFehler('Unbekannter Mäh-Plan.');
        }
        $p = $this->leseProperties($buendel, $did, [[2, 1]]);
        $zustand = $this->normalisiereMaeher($p, $modell)['werte']['Zustand'] ?? RoboHubModell::ZUSTAND_UNBEKANNT;
        if (!in_array($zustand, [RoboHubModell::ZUSTAND_BEREIT, RoboHubModell::ZUSTAND_LAEDT, RoboHubModell::ZUSTAND_GELADEN], true)) {
            throw new RoboHubFehler('Mäh-Pläne lassen sich nur schalten, wenn der Mäher ruht (in der Station oder bereit) — bitte die laufende Aufgabe erst beenden.');
        }
        $platz = intdiv($tabelle, 2);
        $vorher = $this->maeherKanal($buendel, $did, ['m' => 'g', 't' => 'SCHDI', 'd' => [2 * $platz, 2 * $platz + 1]]);
        $ziel = null;
        foreach ((array)$vorher as $t) {
            if (is_array($t) && (int)($t[0] ?? -1) === $tabelle) {
                $ziel = $t;
            }
        }
        if ($ziel === null) {
            throw new RoboHubFehler('Diesen Mäh-Plan meldet der Mäher nicht (mehr).');
        }
        if ($an) {
            $aktiv = false;
            foreach ((array)($ziel[4] ?? []) as $ref) {
                $termin = $this->maeherKanal($buendel, $did, ['m' => 'g', 't' => 'SCHDC', 'd' => [$tabelle, (int)($ref[0] ?? -1)]]);
                if (self::maeherTabellenTermin($termin) !== []) {
                    $aktiv = true;
                    break;
                }
            }
            if (!$aktiv) {
                throw new RoboHubFehler('Ein Mäh-Plan ohne aktiven Termin lässt sich nicht einschalten.');
            }
        }
        $this->maeherKanal($buendel, $did, ['m' => 's', 't' => 'SCHDS', 'd' => [$tabelle, $an ? 1 : 0]]);
        $nachher = $this->maeherKanal($buendel, $did, ['m' => 'g', 't' => 'SCHDI', 'd' => [2 * $platz, 2 * $platz + 1]]);
        foreach ((array)$nachher as $t) {
            if (is_array($t) && (int)($t[0] ?? -1) === $tabelle && (int)($t[1] ?? -1) === ($an ? 1 : 0)) {
                return 'Mäh-Plan ' . ($an ? 'eingeschaltet' : 'ausgeschaltet') . '.';
            }
        }
        throw new RoboHubFehler('Der Mäher hat das Schalten nicht bestätigt — bitte in der App prüfen.');
    }

    /** SCHDC-Antwort → Einträge je Wochentag (leer, wenn der Termin aus ist). */
    public static function maeherTabellenTermin($v): array
    {
        if (!is_array($v) || count($v) < 6 || (int)$v[1] !== 1 || !is_array($v[4]) || !is_array($v[5])) {
            return [];
        }
        $art = ((int)$v[2]) % 8;
        $start = (int)$v[3];
        if ($start < 0 || $start >= 1440) {
            return [];
        }
        $aus = [];
        foreach ($v[4] as $tag) {
            $tag = (int)$tag;
            if (!isset(self::MAEHER_TAGE[$tag])) {
                continue;
            }
            $aus[] = ['tagNr' => $tag, 'tag' => self::MAEHER_TAGE[$tag], 'art' => self::MAEHER_PLANART[$art] ?? 'unbekannt',
                'von' => sprintf('%02d:%02d', intdiv($start, 60), $start % 60), 'bis' => null,
                'zonen' => $art === 1 ? array_map('intval', $v[5]) : []];
        }
        return $aus;
    }

    /** Antwort-„d" einer Konfigurationskanal-Abfrage (2.50), r ≠ 0 = Fehler. */
    private function maeherKanal(array $buendel, string $did, array $anfrage)
    {
        $out = $this->aktion($buendel, $did, 2, 50, [$anfrage]);
        $erstes = $out['out'][0] ?? null;
        if (is_string($erstes)) {
            $erstes = json_decode($erstes, true);
        }
        if (!is_array($erstes) || (isset($erstes['r']) && (int)$erstes['r'] !== 0)) {
            throw new RoboHubFehler('Mäher lehnt ' . $anfrage['t'] . ' ab (' . substr((string)json_encode($out['out'] ?? $out), 0, 160) . ').');
        }
        return $erstes['d'] ?? null;
    }

    /** Zeitplan-Dokument → Termine (je Plan einer, mit Einträgen je Wochentag). */
    public static function maeherPlaeneLesen(string $text): array
    {
        $j = json_decode($text, true);
        $termine = [];
        foreach ((array)($j['d'] ?? []) as $plan) {
            if (!is_array($plan) || !isset($plan[0])) {
                continue;
            }
            $eintraege = [];
            $b64 = (string)($plan[3] ?? '');
            $daten = $b64 === '' ? '' : base64_decode($b64, true);
            if (is_string($daten) && $daten !== '') {
                $eintraege = self::maeherTermineLesen(array_values(unpack('C*', $daten)));
            }
            $tage = [];
            foreach ([1, 2, 3, 4, 5, 6, 0] as $i) {
                foreach ($eintraege as $e) {
                    if ($e['tagNr'] === $i) {
                        $tage[] = self::MAEHER_TAGE[$i];
                        break;
                    }
                }
            }
            $termine[] = [
                'id' => (int)$plan[0], 'an' => (int)($plan[1] ?? 0) === 1, 'ungueltig' => false,
                'name' => (string)($plan[2] ?? ''), 'art' => 'maeher', 'tage' => $tage, 'einmalig' => false,
                'zeit' => $eintraege[0]['von'] ?? '', 'eintraege' => $eintraege,
            ];
        }
        return $termine;
    }

    /**
     * Termin-Bytes (EvotecIT _decode_schedule_task_data): je Termin 7 Byte Kopf —
     * Byte 0 hohe 4 Bit Wochentag (0 = So), niedrige Art (≥ 8 = wiederkehrend, Rest
     * mod 8: 0 alles, 1 Zonen, 2 Kante, 3 Patrouille); Start 12 Bit (Byte 1 + untere 4 von 2),
     * Ende 12 Bit (obere 4 von 2 + Byte 3), tatsächliches Ende 12 Bit (Byte 4/5),
     * Anzahl Zonen-Bytes 12 Bit (obere 4 von 5 + Byte 6), dann die Zonen.
     * Gerahmte Form (beginnt mit 0xAA, endet je Termin mit 0xED): nur Start.
     */
    private static function maeherTermineLesen(array $b): array
    {
        $aus = [];
        $minuten = function (int $m): string { return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); };
        $i = 0;
        $n = count($b);
        if ($n > 0 && $b[0] === 0xAA) {
            while ($i + 7 <= $n && $b[$i] === 0xAA) {
                $laenge = $b[$i + 1];
                if ($laenge < 7 || $i + $laenge > $n) {
                    break;
                }
                $tag = $b[$i + 2] >> 4;
                $art = ($b[$i + 2] & 0x0F) % 8;
                $start = (($b[$i + 4] & 0x0F) << 8) | $b[$i + 3];
                $aus[] = ['tagNr' => $tag, 'tag' => self::MAEHER_TAGE[$tag] ?? '?', 'art' => self::MAEHER_PLANART[$art] ?? 'unbekannt',
                    'von' => $minuten($start), 'bis' => null, 'zonen' => $art === 1 ? array_slice($b, $i + 6, $laenge - 7) : []];
                $i += $laenge;
            }
            return $aus;
        }
        while ($i + 7 <= $n) {
            $tag = $b[$i] >> 4;
            $art = ($b[$i] & 0x0F) % 8;
            $start = (($b[$i + 2] & 0x0F) << 8) | $b[$i + 1];
            $ende = ($b[$i + 3] << 4) | ($b[$i + 2] >> 4);
            $anzahl = ($b[$i + 6] << 4) | ($b[$i + 5] >> 4);
            if ($i + 7 + $anzahl > $n || $start >= 1440 || $ende >= 1440 || !isset(self::MAEHER_TAGE[$tag])) {
                break;
            }
            $aus[] = ['tagNr' => $tag, 'tag' => self::MAEHER_TAGE[$tag], 'art' => self::MAEHER_PLANART[$art] ?? 'unbekannt',
                'von' => $minuten($start), 'bis' => $minuten($ende), 'zonen' => $art === 1 ? array_slice($b, $i + 7, $anzahl) : []];
            $i += 7 + $anzahl;
        }
        return $aus;
    }

    /** Termin ein-/ausschalten: 8.2 frisch lesen, nur Feld 1 dieses Termins ändern (ioBroker-Toggle). */
    private function zeitplanSchalten(array $buendel, string $did, int $id, bool $an): string
    {
        $p = $this->leseProperties($buendel, $did, [[8, 2]]);
        if (!isset($p['8.2']) || !is_string($p['8.2'])) {
            throw new RoboHubFehler('Zeitpläne des Roboters nicht lesbar — nichts geändert.');
        }
        $teile = explode(';', $p['8.2']);
        $gefunden = false;
        foreach ($teile as $i => $teil) {
            $f = explode('-', $teil);
            if (count($f) < 9 || $f[0] !== (string)$id) {
                continue;
            }
            if ($f[1] === '3') {
                throw new RoboHubFehler('Dieser Zeitplan ist ungültig (z. B. Karte geändert) — bitte in der Hersteller-App prüfen.');
            }
            $f[1] = $an ? '1' : '0';
            $teile[$i] = implode('-', $f);
            $gefunden = true;
        }
        if (!$gefunden) {
            throw new RoboHubFehler('Zeitplan ' . $id . ' gibt es am Roboter nicht (mehr).');
        }
        $this->setzeProperty($buendel, $did, 8, 2, implode(';', $teile));
        return 'Zeitplan ' . ($an ? 'eingeschaltet' : 'ausgeschaltet') . '.';
    }

    // -----------------------------------------------------------------
    // Nicht stören / Lautstärke
    // -----------------------------------------------------------------

    // Ersatzzeiten, wenn das Gerät noch kein Zeitfenster kennt (Tasshack set_dnd_tasks).
    private const DND_VON = '22:00';
    private const DND_BIS = '08:00';

    /**
     * Sauger: [an, von, bis] oder null. Neuere Geräte (Tasshack-Fähigkeit
     * „dnd_task") führen Zeitfenster als JSON-Liste in 5.4
     * ([{"id","en","st","et","wk","ss"}]); maßgeblich ist wie bei Tasshack
     * das erste Fenster nach Startzeit. Ältere Geräte: 5.1/5.2/5.3.
     */
    private static function nichtStoerenLesen(array $p): ?array
    {
        if (array_key_exists('5.4', $p) && is_string($p['5.4'])) {
            $fenster = self::dndFenster($p['5.4']);
            if ($fenster === []) {
                return [false, self::DND_VON, self::DND_BIS];
            }
            $f = $fenster[0];
            return [!empty($f['en']), (string)($f['st'] ?? self::DND_VON), (string)($f['et'] ?? self::DND_BIS)];
        }
        if (!isset($p['5.1'])) {
            return null;
        }
        return [(bool)$p['5.1'], is_string($p['5.2'] ?? null) ? $p['5.2'] : self::DND_VON, is_string($p['5.3'] ?? null) ? $p['5.3'] : self::DND_BIS];
    }

    /** 5.4 → Liste der Zeitfenster, sortiert wie Tasshack (Startzeit, bei Gleichstand höhere id zuerst). */
    private static function dndFenster(string $json): array
    {
        $liste = json_decode($json, true);
        if (!is_array($liste)) {
            return [];
        }
        $liste = array_values(array_filter($liste, 'is_array'));
        usort($liste, function (array $a, array $b): int {
            $sa = (int)str_replace(':', '', (string)($a['st'] ?? '0'));
            $sb = (int)str_replace(':', '', (string)($b['st'] ?? '0'));
            return $sa <=> $sb ?: ((int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
        });
        return $liste;
    }

    private static function minutenZuUhrzeit($min): string
    {
        $m = ((int)$min % 1440 + 1440) % 1440;
        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }

    private static function uhrzeitZuMinuten(string $t): int
    {
        [$h, $m] = array_map('intval', explode(':', $t));
        return $h * 60 + $m;
    }

    /**
     * Mäher-Einstellungen über den Konfigurationskanal (2.50, {"m":"g","t":"CFG"},
     * ioBroker docs/mower-protocol.md): DND = [an, Start-Minute, End-Minute],
     * VOL = Lautstärke 0–100.
     */
    private function maeherKonfiguration(array $buendel, string $did): array
    {
        $out = $this->aktion($buendel, $did, 2, 50, [['m' => 'g', 't' => 'CFG']]);
        $erstes = $out['out'][0] ?? null;
        if (is_string($erstes)) {
            $erstes = json_decode($erstes, true);
        }
        return is_array($erstes['d'] ?? null) ? $erstes['d'] : [];
    }

    private function maeherEinstellungen(array $buendel, string $did): array
    {
        try {
            $cfg = $this->maeherKonfiguration($buendel, $did);
        } catch (RoboHubFehler $e) {
            if ($e->anmeldungNoetig || $e->warteSekunden > 0) {
                throw $e;
            }
            return [];
        }
        $w = [];
        if (is_array($cfg['DND'] ?? null) && count($cfg['DND']) >= 3) {
            $w['NichtStoeren'] = !empty($cfg['DND'][0]);
            $w['NichtStoerenVon'] = self::minutenZuUhrzeit($cfg['DND'][1]);
            $w['NichtStoerenBis'] = self::minutenZuUhrzeit($cfg['DND'][2]);
        }
        if (isset($cfg['VOL']) && is_numeric($cfg['VOL'])) {
            $w['Lautstaerke'] = (int)$cfg['VOL'];
        }
        return $w;
    }

    /** Neue Einstellung prüfen: Uhrzeit „HH:MM", Lautstärke 0–100, Nicht stören bool. */
    private static function pruefeEinstellung(string $feld, $wert)
    {
        if ($feld === 'Lautstaerke') {
            if (!is_numeric($wert) || (int)$wert < 0 || (int)$wert > 100) {
                throw new RoboHubFehler('Lautstärke muss zwischen 0 und 100 % liegen.');
            }
            return (int)$wert;
        }
        if ($feld === 'NichtStoeren') {
            return (bool)$wert;
        }
        if ($feld === 'NichtStoerenVon' || $feld === 'NichtStoerenBis') {
            $t = trim((string)$wert);
            if (!RoboHubModell::istUhrzeit($t)) {
                throw new RoboHubFehler('Uhrzeit bitte als HH:MM angeben (z. B. 22:00), nicht „' . $t . '".');
            }
            return $t;
        }
        throw new RoboHubFehler('Einstellung „' . $feld . '" ist nicht schreibbar.');
    }

    /** Setzt NichtStoeren/NichtStoerenVon/NichtStoerenBis/Lautstaerke. */
    private function einstellung(array $buendel, string $did, bool $maeher, string $feld, $wert): string
    {
        $wert = self::pruefeEinstellung($feld, $wert);
        if ($feld === 'Lautstaerke') {
            if ($maeher) {
                $this->aktion($buendel, $did, 2, 50, [['m' => 's', 't' => 'VOL', 'd' => ['value' => $wert]]]);
            } else {
                $this->setzeProperty($buendel, $did, 7, 1, $wert);
            }
            return 'Lautstärke gesendet.';
        }

        // Nicht stören: aktuellen Stand lesen, nur das eine Feld ändern, alles zurückschreiben.
        if ($maeher) {
            $cfg = $this->maeherKonfiguration($buendel, $did);
            if (!is_array($cfg['DND'] ?? null) || count($cfg['DND']) < 3) {
                throw new RoboHubFehler('Nicht-stören-Einstellung des Mähers nicht lesbar — nichts geändert.');
            }
            $an = !empty($cfg['DND'][0]);
            $von = self::minutenZuUhrzeit($cfg['DND'][1]);
            $bis = self::minutenZuUhrzeit($cfg['DND'][2]);
        } else {
            $p = $this->leseProperties($buendel, $did, [[5, 1], [5, 2], [5, 3], [5, 4]]);
            $stand = self::nichtStoerenLesen($p);
            if ($stand === null) {
                throw new RoboHubFehler('Nicht-stören-Einstellung des Roboters nicht lesbar — nichts geändert.');
            }
            [$an, $von, $bis] = $stand;
        }
        if ($feld === 'NichtStoeren') {
            $an = $wert;
        } elseif ($feld === 'NichtStoerenVon') {
            $von = $wert;
        } else {
            $bis = $wert;
        }
        if ($von === $bis) {
            throw new RoboHubFehler('Beginn und Ende von „Nicht stören" dürfen nicht gleich sein.');
        }

        if ($maeher) {
            $this->aktion($buendel, $did, 2, 50, [['m' => 's', 't' => 'DND', 'd' => ['value' => $an ? 1 : 0, 'time' => [self::uhrzeitZuMinuten($von), self::uhrzeitZuMinuten($bis)]]]]);
        } elseif (array_key_exists('5.4', $p) && is_string($p['5.4'])) {
            // Wie Tasshack set_dnd_tasks: erstes Fenster ändern (oder anlegen),
            // weitere Fenster unverändert mitschicken.
            $fenster = self::dndFenster($p['5.4']);
            if ($fenster === []) {
                $fenster[] = ['id' => 1, 'en' => $an, 'st' => $von, 'et' => $bis, 'wk' => 127, 'ss' => 0];
            } else {
                $fenster[0]['en'] = $an;
                $fenster[0]['st'] = $von;
                $fenster[0]['et'] = $bis;
            }
            $this->setzeProperty($buendel, $did, 5, 4, (string)json_encode($fenster, JSON_UNESCAPED_SLASHES));
        } elseif ($feld === 'NichtStoeren') {
            $this->setzeProperty($buendel, $did, 5, 1, $an);
        } else {
            $this->setzeProperty($buendel, $did, 5, $feld === 'NichtStoerenVon' ? 2 : 3, $wert);
        }
        return '„Nicht stören" gesendet.';
    }

    // =================================================================
    // Befehle
    // =================================================================

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string
    {
        $did = (string)$geraet['id'];
        $maeher = self::klasseVon((string)($geraet['modell'] ?? '')) === RoboHubModell::KLASSE_MAEHER;

        // Mäher: die Quellen widersprechen sich. EvotecIT (const.py, mit
        // LiDAX-Ultra-1000-Rückmeldung „commands") nutzt Start 5.1, Stopp
        // 5.2, Station 5.3, Pause 5.4; ioBrokers Code nutzt 2.1/2.2/2.4
        // (beschriftet sie aber selbst mit „5-1/5-2/5-4"). Zur Station ist in
        // beiden 5.3 (3.1 wirkt beim Mäher nicht, ioBroker Issue #49).
        // Deshalb: 5.x zuerst, bei Ablehnung einmal der ioBroker-Weg.
        $aktionen = $maeher
            ? ['start' => [[5, 1], [2, 1]], 'stopp' => [[5, 2], [2, 2]], 'pause' => [[5, 4], [2, 4]], 'station' => [[5, 3]]]
            : ['start' => [[2, 1]], 'pause' => [[2, 2]], 'station' => [[3, 1]], 'stopp' => [[4, 2]], 'orten' => [[7, 1]]];

        if (isset($aktionen[$befehl])) {
            $letzterFehler = null;
            foreach ($aktionen[$befehl] as [$siid, $aiid]) {
                try {
                    $this->aktion($buendel, $did, $siid, $aiid, []);
                    $this->dbg('Befehl', self::befehlName($befehl) . ' über ' . $siid . '.' . $aiid . ' angenommen.');
                    return 'Befehl „' . self::befehlName($befehl) . '" gesendet.';
                } catch (RoboHubFehler $e) {
                    if ($e->anmeldungNoetig || $e->warteSekunden > 0) {
                        throw $e;
                    }
                    $this->dbg('Befehl', self::befehlName($befehl) . ' über ' . $siid . '.' . $aiid . ' abgelehnt: ' . $e->getMessage());
                    $letzterFehler = $e;
                }
            }
            throw $letzterFehler;
        }
        if ($befehl === 'orten' && $maeher) {
            // Mäher „Finden" über den Konfigurationskanal (o 9).
            $this->aktion($buendel, $did, 2, 50, [['m' => 'a', 'p' => 0, 'o' => 9]]);
            return 'Befehl „Orten" gesendet.';
        }
        if ($befehl === 'raum' && !$maeher) {
            return $this->raumReinigung($buendel, $did, array_map('intval', (array)($parameter['raeume'] ?? [])));
        }
        if ($befehl === 'raum' && $maeher) {
            // Zonenmähen: Konfigurationskanal, Operation 102 mit {"region":[IDs]}
            // (ioBroker main.js mowAction o 102, docs/mower-protocol.md „zoneMower").
            $zonen = array_values(array_unique(array_filter(array_map('intval', (array)($parameter['raeume'] ?? [])), function (int $z): bool { return $z > 0; })));
            if (count($zonen) === 0) {
                throw new RoboHubFehler('Keine Zone angegeben.');
            }
            $this->aktion($buendel, $did, 2, 50, [['m' => 'a', 'p' => 0, 'o' => 102, 'd' => ['region' => $zonen]]]);
            return 'Mähen ' . (count($zonen) === 1 ? 'der Zone' : 'von ' . count($zonen) . ' Zonen') . ' gesendet.';
        }
        if ($befehl === 'zeitplan' && $maeher) {
            return $this->maehPlanSchalten($buendel, $did, (string)($geraet['modell'] ?? ''), (int)($parameter['tabelle'] ?? -1), !empty($parameter['an']));
        }
        if ($befehl === 'zeitplan' && !$maeher) {
            return $this->zeitplanSchalten($buendel, $did, (int)($parameter['id'] ?? 0), !empty($parameter['an']));
        }
        if ($befehl === 'einstellung') {
            return $this->einstellung($buendel, $did, $maeher, (string)($parameter['feld'] ?? ''), $parameter['wert'] ?? null);
        }
        if ($befehl === 'setze' && !$maeher) {
            return $this->setze($buendel, $did, (string)($parameter['feld'] ?? ''), (int)($parameter['wert'] ?? 0));
        }
        throw new RoboHubFehler('Dieser Befehl wird für ' . ($maeher ? 'Mähroboter' : 'Saugroboter') . ' noch nicht unterstützt: ' . $befehl);
    }

    // Verbrauchsteile zurücksetzen. Sauger: eigene Aktion je Teil (Tasshack
    // types.py ACTION_MAPPING, für 9/10/11/16 identisch mit ioBroker main.js).
    private const RUECKSETZEN_SAUGER = [
        'Hauptbuerste' => [9, 1], 'Seitenbuerste' => [10, 1], 'Filter' => [11, 1], 'Sensoren' => [16, 1],
        'Mopp' => [18, 1], 'Schmutzwasserbehaelter' => [26, 1], 'Abwasserfilter' => [35, 1],
    ];
    // Mäher: Index im CMS-Minutenzähler (ioBroker main.js resetIndex, docs/mower-protocol.md).
    private const RUECKSETZEN_MAEHER = ['Messer' => 0, 'Reinigungsbuerste' => 1, 'Wartung' => 2];

    public function zuruecksetzen(array $buendel, array $geraet, string $teil): string
    {
        $did = (string)$geraet['id'];
        if (self::klasseVon((string)($geraet['modell'] ?? '')) === RoboHubModell::KLASSE_MAEHER) {
            if (!isset(self::RUECKSETZEN_MAEHER[$teil])) {
                throw new RoboHubFehler('Dieses Teil lässt sich beim Mäher nicht zurücksetzen: ' . $teil);
            }
            // Read-Modify-Write wie ioBroker: alle drei Zähler lesen, nur den
            // gewünschten auf 0 setzen, alle zurückschreiben — sonst gingen die
            // anderen beiden verloren.
            $out = $this->aktion($buendel, $did, 2, 50, [['m' => 'g', 't' => 'CMS']]);
            $erstes = $out['out'][0] ?? null;
            if (is_string($erstes)) {
                $erstes = json_decode($erstes, true);
            }
            $cms = $erstes['d']['value'] ?? null;
            if (!is_array($cms) || count($cms) < 3) {
                throw new RoboHubFehler('Verschleißzähler des Mähers nicht lesbar — nichts zurückgesetzt.');
            }
            $cms = array_values($cms);
            $cms[self::RUECKSETZEN_MAEHER[$teil]] = 0;
            $this->aktion($buendel, $did, 2, 50, [['m' => 's', 't' => 'CMS', 'd' => ['value' => $cms]]]);
            return 'Zurücksetzen gesendet.';
        }
        if (!isset(self::RUECKSETZEN_SAUGER[$teil])) {
            throw new RoboHubFehler('Dieses Teil lässt sich nicht zurücksetzen: ' . $teil);
        }
        [$siid, $aiid] = self::RUECKSETZEN_SAUGER[$teil];
        $this->aktion($buendel, $did, $siid, $aiid, []);
        return 'Zurücksetzen gesendet.';
    }

    private static function befehlName(string $befehl): string
    {
        return ['start' => 'Start', 'pause' => 'Pause', 'stopp' => 'Stopp', 'station' => 'Zur Station', 'orten' => 'Orten'][$befehl] ?? $befehl;
    }

    private function raumReinigung(array $buendel, string $did, array $raeume): string
    {
        if (count($raeume) === 0) {
            throw new RoboHubFehler('Kein Raum angegeben.');
        }
        // Aktuelle Saugstufe/Wassermenge übernehmen (wie ioBroker).
        $p = $this->leseProperties($buendel, $did, [[4, 4], [4, 5]]);
        $saug = isset($p['4.4']) ? (int)$p['4.4'] : 1;
        $wasser = isset($p['4.5']) ? (int)$p['4.5'] : 2;
        $selects = [];
        foreach ($raeume as $seg) {
            // Fünftes Feld fest 1 — sonst bricht Gen-5 nach dem ersten Raum ab (beide Referenzen).
            $selects[] = [$seg, 1, $saug, $wasser, 1];
        }
        $this->aktion($buendel, $did, 4, 1, [['piid' => 1, 'value' => 18], ['piid' => 10, 'value' => json_encode(['selects' => $selects])]]);
        return 'Raumreinigung für ' . count($raeume) . ' Raum/Räume gesendet.';
    }

    private function setze(array $buendel, string $did, string $feld, int $wert): string
    {
        if ($feld === 'Saugstufe') {
            $this->setzeProperty($buendel, $did, 4, 4, $wert);
            return 'Saugstufe gesendet.';
        }
        if ($feld === 'Wassermenge') {
            $this->setzeProperty($buendel, $did, 4, 5, $wert);
            return 'Wassermenge gesendet.';
        }
        if ($feld === 'Reinigungsmodus') {
            // Read-Modify-Write: nur Bits 0–1 ändern, Feuchte/Selbstreinigungsfläche erhalten.
            $p = $this->leseProperties($buendel, $did, [[4, 23], [4, 25], [27, 3]]);
            if (!isset($p['4.23'])) {
                throw new RoboHubFehler('Reinigungsmodus konnte nicht gelesen werden — nichts geändert.');
            }
            $roh = (int)$p['4.23'];
            $neu = ($roh & ~3) | (self::tauscheModus($wert & 3, $this->hatMoppAnhebung($p)) & 3);
            $this->setzeProperty($buendel, $did, 4, 23, $neu);
            return 'Reinigungsmodus gesendet.';
        }
        throw new RoboHubFehler('Einstellung „' . $feld . '" ist nicht schreibbar.');
    }

    /** action siid.aiid; wirft, wenn das Gerät die Aktion ablehnt (result.code ≠ 0). */
    private function aktion(array $buendel, string $did, int $siid, int $aiid, array $in): array
    {
        $erg = $this->sendCommand($buendel, $did, 'action', ['did' => $did, 'siid' => $siid, 'aiid' => $aiid, 'in' => $in]);
        $code = is_array($erg) ? (int)($erg['code'] ?? 0) : 0;
        if ($code !== 0) {
            throw new RoboHubFehler('Der Roboter hat den Befehl abgelehnt (Code ' . $code . ').');
        }
        return is_array($erg) ? $erg : [];
    }

    private function setzeProperty(array $buendel, string $did, int $siid, int $piid, $wert): void
    {
        $erg = $this->sendCommand($buendel, $did, 'set_properties', [['did' => $did, 'siid' => $siid, 'piid' => $piid, 'value' => $wert]]);
        $code = (int)($erg[0]['code'] ?? 0);
        if ($code !== 0) {
            throw new RoboHubFehler('Der Roboter hat die Einstellung abgelehnt (Code ' . $code . ').');
        }
    }

    // =================================================================
    // Räume (Sauger) — aus der gespeicherten Karte, ohne Bild auszuwerten
    // =================================================================

    // Vordefinierte Raumtypen (ioBroker lib/cleanset.js).
    private const RAUMTYPEN = [
        1 => 'Wohnzimmer', 2 => 'Schlafzimmer', 3 => 'Arbeitszimmer', 4 => 'Küche', 5 => 'Esszimmer',
        6 => 'Bad', 7 => 'Balkon', 8 => 'Flur', 9 => 'Hauswirtschaftsraum', 10 => 'Abstellraum',
        11 => 'Besprechungsraum', 12 => 'Büro', 13 => 'Fitnessraum', 14 => 'Freizeitraum', 15 => 'Zweites Schlafzimmer',
    ];

    /** Raum-ID => Name; leeres Array, wenn keine Karte vorhanden/lesbar. */
    public function karte(array $buendel, array $geraet): array
    {
        $leer = ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null, 'format' => 1.0];
        if (self::klasseVon((string)($geraet['modell'] ?? '')) === RoboHubModell::KLASSE_MAEHER) {
            $ergebnis = $this->maehKarte($buendel, (string)$geraet['id']) + $leer;
            // Zeitpläne nur mit der (seltenen) Kartenabfrage — das sind mehrere Anfragen.
            [$ergebnis['zeitplaene'], $ergebnis['zeitplanRoh']] = $this->maeherZeitplaene($buendel, (string)$geraet['id']);
            return $ergebnis;
        }
        $did = (string)$geraet['id'];
        $p = $this->leseProperties($buendel, $did, [[6, 8]]);
        $objekt = json_decode((string)($p['6.8'] ?? ''), true);
        $datei = is_array($objekt) ? (string)($objekt['object_name'] ?? '') : '';
        if ($datei === '') {
            $this->dbg('Karte', 'Keine gespeicherte Karte (6.8 leer).');
            return $leer;
        }
        $url = $this->api($buendel, '/dreame-user-iot/iotfile/getDownloadUrl', [
            'did' => $did, 'model' => (string)($geraet['modell'] ?? ''), 'filename' => $datei, 'region' => (string)($buendel['region'] ?? 'eu'),
        ])['data'] ?? '';
        if (!is_string($url) || $url === '') {
            return $leer;
        }
        [$status, $text] = $this->http('GET', $url, ['Accept: */*'], null);
        $inhalt = json_decode($text, true);
        if ($status !== 200 || !is_array($inhalt)) {
            $this->dbg('Karte', 'Kartendatei nicht ladbar (HTTP ' . $status . ').');
            return $leer;
        }
        $karten = (array)($inhalt['mapstr'] ?? []);
        $erste = $karten[0] ?? null;
        foreach ($karten as $k) {
            if (is_array($k) && ((int)($k['first'] ?? -1) === 0 || (int)($k['id'] ?? -1) === 0)) {
                $erste = $k;
            }
        }
        $kartenText = is_array($erste) ? (string)($erste['thb'] ?? $erste['map'] ?? '') : '';
        $roh = self::dekodiereKarteRoh($kartenText, (string)($geraet['modell'] ?? ''));
        if ($roh === null) {
            $this->dbg('Karte', 'Karte nicht dekodierbar (verschlüsselt mit unbekanntem Schlüssel oder anderes Format).');
            return $leer;
        }
        $raeume = self::raeumeAusSegmenten((array)($roh['json']['seg_inf'] ?? []));
        $bild = RoboHubKartenbild::sauger($roh, $raeume);
        $bild += self::raumEinstellungenAus($roh['json'], $this->hatMoppAnhebung((array)($geraet['stand'] ?? [])));
        if ($bild['bild'] === '') {
            $this->dbg('Karte', 'Räume gelesen, aber kein Kartenbild erzeugbar (' . $roh['breite'] . '×' . $roh['hoehe'] . ').');
        } else {
            $this->dbg('Karte', 'Kartenbild ' . $roh['breite'] . '×' . $roh['hoehe'] . ', Kodierung „' . $bild['kodierung'] . '", ' . strlen($bild['bild']) . ' Zeichen.');
        }
        unset($bild['kodierung']);
        return ['raeume' => $raeume] + $bild;
    }

    /**
     * Dekodiert einen Kartenstring bis zum JSON-Anhang (ioBroker
     * lib/dreame.js decodeMultiMapData): URL-sicheres Base64, optional
     * AES-256-CBC (Schlüssel = erste 32 Hex-Zeichen von sha256(Teil nach dem
     * Komma), IV modellabhängig, sonst leer), zlib, 27-Byte-Kopf, Bild,
     * dann JSON. Das Bild selbst wird nicht ausgewertet.
     */
    public static function dekodiereKarte(string $kartenText, string $modell): ?array
    {
        $roh = self::dekodiereKarteRoh($kartenText, $modell);
        return $roh === null ? null : $roh['json'];
    }

    /**
     * Karte vollständig: Kopf (Tasshack map.py, 27 Byte, Little Endian:
     * 0 mapId, 2 frameId, 4 frameType, 5/7/9 Roboter x/y/Winkel, 11/13/15
     * Station x/y/Winkel, 17 Rastergröße mm, 19 Breite, 21 Höhe, 23 links,
     * 25 oben), Bildpunkte (Breite × Höhe Byte, Zeile 0 = kleinstes y) und
     * JSON-Anhang. „origin" im JSON ersetzt links/oben (wie Tasshack).
     */
    public static function dekodiereKarteRoh(string $kartenText, string $modell): ?array
    {
        $roh = self::karteEntpacken($kartenText, $modell);
        if ($roh === null) {
            return null;
        }
        $k = unpack('vmapId/vframeId/cframe/vrx/vry/vrw/vsx/vsy/vsw/vraster/vbreite/vhoehe/vlinks/voben', $roh);
        if ((int)$k['frame'] !== 73) {
            return null;
        }
        return self::karteZerlegen($roh, $k);
    }

    /**
     * Nur die Roboterposition aus einem beliebigen Kartenrahmen (auch „P",
     * die Teilrahmen der Echtzeit-Meldung 6.1): [x mm, y mm, Winkel] oder null.
     */
    public static function kopfPosition(string $kartenText): ?array
    {
        $roh = self::karteEntpacken($kartenText, '');
        if ($roh === null) {
            return null;
        }
        $k = unpack('x5/vrx/vry/vrw', $roh);
        $vz = function (int $v): int { return $v >= 0x8000 ? $v - 0x10000 : $v; };
        $x = $vz((int)$k['rx']);
        $y = $vz((int)$k['ry']);
        if (abs($x) >= 32767 || abs($y) >= 32767 || $vz((int)$k['rw']) === 32767) {
            return null;
        }
        return [$x, $y, $vz((int)$k['rw'])];
    }

    /** Kartentext („Base64[,Schlüssel]") → entschlüsselte, entpackte Bytes (mind. 27 Byte Kopf) oder null. */
    private static function karteEntpacken(string $kartenText, string $modell): ?string
    {
        if ($kartenText === '') {
            return null;
        }
        $schluessel = '';
        if (strpos($kartenText, ',') !== false) {
            [$kartenText, $suffix] = explode(',', $kartenText, 2);
            $schluessel = substr(hash('sha256', $suffix), 0, 32);
        }
        $bin = base64_decode(strtr($kartenText, '-_', '+/'), true);
        if ($bin === false || $bin === '') {
            return null;
        }
        if ($schluessel !== '') {
            // Bekannte IVs (ioBroker MODEL_IV_TABLE); sonst leerer IV = 16 Nullbytes.
            $iv = stripos($modell, 'dreame.vacuum.r2253') === 0 ? 'NRwnBj5FsNPgBNbT' : str_repeat("\0", 16);
            $bin = openssl_decrypt($bin, 'aes-256-cbc', $schluessel, OPENSSL_RAW_DATA, $iv);
            if ($bin === false) {
                return null;
            }
        }
        $roh = @zlib_decode($bin);
        if ($roh === false || strlen($roh) < 27) {
            return null;
        }
        return $roh;
    }

    private static function karteZerlegen(string $roh, array $k): ?array
    {
        $vz = function (int $v): int { return $v >= 0x8000 ? $v - 0x10000 : $v; };
        $breite = (int)$k['breite'];
        $hoehe = (int)$k['hoehe'];
        $anhang = substr($roh, 27 + $breite * $hoehe);
        $json = json_decode((string)$anhang, true);
        if (!is_array($json)) {
            return null;
        }
        $links = $vz((int)$k['links']);
        $oben = $vz((int)$k['oben']);
        if (isset($json['origin'][0], $json['origin'][1]) && is_numeric($json['origin'][0]) && is_numeric($json['origin'][1])) {
            $links = (int)$json['origin'][0];
            $oben = (int)$json['origin'][1];
        }
        return [
            'json'    => $json,
            'breite'  => $breite,
            'hoehe'   => $hoehe,
            'raster'  => $vz((int)$k['raster']),
            'links'   => $links,
            'oben'    => $oben,
            'station' => [$vz((int)$k['sx']), $vz((int)$k['sy'])],
            'roboter' => [$vz((int)$k['rx']), $vz((int)$k['ry']), $vz((int)$k['rw'])],
            'pixel'   => (string)substr($roh, 27, $breite * $hoehe),
        ];
    }

    // Einstellungen je Raum („cleanset" im Kartentext, Tasshack map.py): je Raum-ID eine
    // Liste [Saugstufe, Wasser/Feuchte, Durchgänge, Reihenfolge, Modus, Wisch-Einstellungen, …].
    // Wasser: Tasshack liest 2–4 als Wassermenge (Wert − 1 = Niedrig/Mittel/Hoch), größere
    // Werte als Feuchte-Stufe (Geräte mit 28.1). Modus wie 4.23 — mit Mopp-Anhebung 0↔2
    // getauscht (Live-Befund Barny 12.10.2026: Leitung 2 = App „Saugen").
    private const RAUM_SAUGSTUFE = [0 => 'Leise', 1 => 'Standard', 2 => 'Stark', 3 => 'Turbo'];
    private const RAUM_WASSER = [1 => 'Niedrig', 2 => 'Mittel', 3 => 'Hoch'];
    private const RAUM_MODUS = [0 => 'Saugen', 1 => 'Wischen', 2 => 'Saugen und Wischen', 3 => 'Erst saugen, dann wischen'];

    /**
     * Rückgabe ['raumEinstellungen' => [ID => [...]], 'raumEinstellungenAn' => bool|null,
     * 'raumEinstellungenRoh' => cleanset] — leer, wenn der Kartentext kein cleanset trägt.
     */
    public static function raumEinstellungenAus(array $json, bool $moppAnhebung = false): array
    {
        $cs = $json['cleanset'] ?? null;
        if (is_string($cs)) {
            $cs = json_decode($cs, true);
        }
        if (!is_array($cs) || $cs === []) {
            return [];
        }
        $aus = [];
        foreach ($cs as $id => $w) {
            if (!is_numeric($id) || !is_array($w) || count($w) < 3) {
                continue;
            }
            $wasser = (int)$w[1];
            $e = [
                'saugstufe'   => self::RAUM_SAUGSTUFE[(int)$w[0]] ?? 'Stufe ' . (int)$w[0],
                'wasser'      => ($wasser >= 2 && $wasser <= 4) ? self::RAUM_WASSER[$wasser - 1] : 'Feuchte ' . $wasser,
                'durchgaenge' => (int)$w[2],
                'reihenfolge' => isset($w[3]) ? (int)$w[3] : null,
            ];
            if (isset($w[4])) {
                $m = self::tauscheModus((int)$w[4], $moppAnhebung);
                $e['modus'] = self::RAUM_MODUS[$m] ?? 'Modus ' . (int)$w[4];
            }
            $aus[(int)$id] = $e;
        }
        ksort($aus);
        $an = isset($json['customeClean']) ? (int)$json['customeClean'] === 1 : null;
        return ['raumEinstellungen' => $aus, 'raumEinstellungenAn' => $an, 'raumEinstellungenRoh' => $cs];
    }

    public static function raeumeAusSegmenten(array $segmente): array
    {
        $raeume = [];
        foreach ($segmente as $id => $seg) {
            if (!is_array($seg) || !is_numeric($id)) {
                continue;
            }
            $typ = (int)($seg['type'] ?? 0);
            $name = '';
            if ($typ === 0 && !empty($seg['name'])) {
                $name = (string)base64_decode((string)$seg['name'], true);
            } elseif (isset(self::RAUMTYPEN[$typ])) {
                $name = self::RAUMTYPEN[$typ] . ((int)($seg['index'] ?? 0) > 0 ? ' ' . ((int)$seg['index'] + 1) : '');
            }
            $raeume[(int)$id] = $name !== '' ? $name : 'Raum ' . $id;
        }
        ksort($raeume);
        return $raeume;
    }

    // =================================================================
    // HTTP
    // =================================================================

    private function basisUrl(string $region): string
    {
        return 'https://' . $region . '.' . self::MARKEN[$this->marke]['domain'] . ':' . self::PORT;
    }

    private function basisHeader(string $region, string $land): array
    {
        $m = self::MARKEN[$this->marke];
        $rlc = bin2hex((string)openssl_encrypt($region . '|en|' . $land, 'aes-128-ecb', $m['rlc'], OPENSSL_RAW_DATA));
        return [
            'User-Agent: Dart/3.2 (dart:io)',
            'Dreame-Meta: cv=i_829',
            'Dreame-Rlc: ' . $rlc,
            'Tenant-Id: ' . $m['tenant'],
            'Authorization: Basic ' . $m['basic'],
        ];
    }

    // =================================================================
    // Live-Karte
    // =================================================================

    public function liveKarte(array $buendel, array $geraet): array
    {
        $did = (string)$geraet['id'];
        if (self::klasseVon((string)($geraet['modell'] ?? '')) === RoboHubModell::KLASSE_MAEHER) {
            return $this->maehKarte($buendel, $did, true) + ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null, 'format' => 1.0];
        }
        $modell = (string)($geraet['modell'] ?? '');
        // Aktuellen Vollrahmen („I") anfordern wie Tasshack _request_i_map:
        // action 6.1 mit 6.2 = {"req_type":1,"frame_type":"I","force_type":1}.
        // Antwort: 6.3 = Dateiname in der Cloud (ggf. „Name,Schlüssel"),
        // 6.1 = Kartentext direkt, 6.13 = alte Form „0,Text" bzw. „1,Name[,Schlüssel]".
        $erg = $this->aktion($buendel, $did, 6, 1, [['piid' => 2, 'value' => '{"req_type":1,"frame_type":"I","force_type":1}']]);
        $datei = '';
        $text = '';
        foreach ((array)($erg['out'] ?? []) as $p) {
            $wert = (string)($p['value'] ?? '');
            if ($wert === '') {
                continue;
            }
            $piid = (int)($p['piid'] ?? 0);
            if ($piid === 3) {
                $datei = $wert;
            } elseif ($piid === 1) {
                $text = $wert;
            } elseif ($piid === 13 && $datei === '' && $text === '') {
                $teile = explode(',', $wert, 2);
                if ($teile[0] === '0') {
                    $text = (string)($teile[1] ?? '');
                } else {
                    $datei = (string)($teile[1] ?? '');
                }
            }
        }
        if ($text === '' && $datei !== '') {
            [$name, $schluessel] = array_pad(explode(',', $datei, 2), 2, '');
            $url = $this->api($buendel, '/dreame-user-iot/iotfile/getDownloadUrl', [
                'did' => $did, 'model' => $modell, 'filename' => $name, 'region' => (string)($buendel['region'] ?? 'eu'),
            ])['data'] ?? '';
            if (is_string($url) && $url !== '') {
                [$status, $inhalt] = $this->http('GET', $url, ['Accept: */*'], null);
                if ($status === 200 && $inhalt !== '') {
                    $text = trim($inhalt) . ($schluessel !== '' && strpos($inhalt, ',') === false ? ',' . $schluessel : '');
                }
            }
        }
        $roh = self::dekodiereKarteRoh($text, $modell);
        if ($roh === null) {
            $this->dbg('Live-Karte', 'Kein aktueller Kartenrahmen (Antwort: ' . json_encode($erg['out'] ?? []) . ').');
            throw new RoboHubFehler('Der Roboter hat keine aktuelle Karte geliefert.');
        }
        $raeume = self::raeumeAusSegmenten((array)($roh['json']['seg_inf'] ?? []));
        if ($raeume === []) {
            // Live-Rahmen ohne seg_inf: Namen aus der gespeicherten Karte (vom Roboter mitgegeben).
            foreach ((array)($geraet['raeume'] ?? []) as $id => $name) {
                $raeume[(int)$id] = (string)$name;
            }
        }
        $bild = RoboHubKartenbild::sauger($roh, $raeume, true);
        $bild += self::raumEinstellungenAus($roh['json'], $this->hatMoppAnhebung((array)($geraet['stand'] ?? [])));
        $this->dbg('Live-Karte', $roh['breite'] . '×' . $roh['hoehe'] . ', Kodierung „' . $bild['kodierung'] . '", Weg ' . count($bild['weg'] ?? []) . ' Stücke, Roboter ' . json_encode($bild['roboter'] ?? null));
        unset($bild['kodierung']);
        return ['raeume' => $raeume] + $bild;
    }

    // =================================================================
    // Mäh-Zonen (ioBroker main.js getMowerMap, docs/mower-protocol.md)
    // =================================================================

    /**
     * Zonen aus den Gerätedaten der Cloud: getDeviceData liefert die Karte
     * in Stücken (MAP.0, MAP.1 …, Gesamtlänge in MAP.info). Zusammengesetzt
     * ist das eine JSON-Liste von Karten-Slots (Eintrag als Objekt oder als
     * JSON-Text) mit mowingAreas.value = [[id, {name, …}], …].
     * Mehrere Karten: der erste Slot zählt (ioBroker kennt keinen Zeiger auf
     * die aktive Karte; der Zonen-Start nimmt nur IDs). Rückgabe [id => Name].
     */
    private function maehKarte(array $buendel, string $did, bool $mitWeg = false): array
    {
        $daten = $this->api($buendel, '/dreame-user-iot/iotuserdata/getDeviceData', ['did' => $did])['data'] ?? null;
        if (!is_array($daten)) {
            $this->dbg('Karte', 'Keine Gerätedaten (getDeviceData leer).');
            return ['raeume' => []];
        }
        $text = self::stueckeZusammensetzen($daten, 'MAP');
        $slots = json_decode($text, true);
        if (!is_array($slots) || $slots === []) {
            $this->dbg('Karte', 'Keine Mäh-Karte in den Gerätedaten (Schlüssel: ' . implode(', ', array_keys($daten)) . ').');
            return ['raeume' => []];
        }
        if (count($slots) > 1) {
            $this->dbg('Karte', count($slots) . ' Karten gespeichert — Zonen aus der ersten.');
        }
        $slot = reset($slots);
        if (is_string($slot)) {
            $slot = json_decode($slot, true);
        }
        $zonen = [];
        foreach ((array)($slot['mowingAreas']['value'] ?? []) as $eintrag) {
            if (!is_array($eintrag) || !isset($eintrag[0]) || !is_numeric($eintrag[0]) || (int)$eintrag[0] <= 0) {
                continue;
            }
            $id = (int)$eintrag[0];
            $name = trim((string)($eintrag[1]['name'] ?? ''));
            $zonen[$id] = $name !== '' ? $name : 'Zone ' . $id;
        }
        ksort($zonen);
        $weg = [];
        if ($mitWeg) {
            $pfad = json_decode(self::stueckeZusammensetzen($daten, 'M_PATH'), true);
            $weg = is_array($pfad) ? RoboHubKartenbild::maeherWeg($pfad) : [];
            $this->dbg('Live-Karte', 'Mähweg: ' . count($weg) . ' Stücke.');
        }
        return ['raeume' => $zonen] + RoboHubKartenbild::maeher(is_array($slot) ? $slot : [], $zonen, $weg);
    }

    /** Setzt PREFIX.0, PREFIX.1 … zusammen, gekürzt auf PREFIX.info Zeichen (ioBroker reassembleChunks). */
    private static function stueckeZusammensetzen(array $daten, string $prefix): string
    {
        $gesamt = (int)($daten[$prefix . '.info'] ?? 0);
        if ($gesamt <= 0) {
            return '';
        }
        $text = '';
        for ($i = 0; isset($daten[$prefix . '.' . $i]); $i++) {
            $text .= (string)$daten[$prefix . '.' . $i];
            if (strlen($text) >= $gesamt) {
                break;
            }
        }
        return substr($text, 0, $gesamt);
    }

    /** POST mit JSON an die Cloud-API, prüft code == 0. */
    private function api(array $buendel, string $pfad, array $daten): array
    {
        $region = (string)($buendel['region'] ?? 'eu');
        $headers = $this->basisHeader($region, (string)($buendel['country'] ?? 'DE'));
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Dreame-Auth: bearer ' . (string)($buendel['access_token'] ?? '');
        [$status, $text] = $this->http('POST', $this->basisUrl($region) . $pfad, $headers, (string)json_encode($daten));

        if ($status === 401) {
            $f = new RoboHubFehler('Zugangsschlüssel abgelaufen oder ungültig — bitte neu anmelden.');
            $f->anmeldungNoetig = true;
            throw $f;
        }
        if ($status === 429 || $status >= 500 || $status === 0) {
            $f = new RoboHubFehler($status === 0 ? 'Hersteller-Cloud nicht erreichbar (' . $text . ').' : 'Hersteller-Cloud antwortet mit HTTP ' . $status . '.');
            $f->warteSekunden = $status === 429 ? 600 : 120;
            throw $f;
        }
        $json = json_decode($text, true);
        if (!is_array($json)) {
            throw new RoboHubFehler('Unverständliche Antwort der Hersteller-Cloud (HTTP ' . $status . ').');
        }
        if ((int)($json['code'] ?? -1) !== 0) {
            $code = (int)($json['code'] ?? -1);
            throw new RoboHubFehler(in_array($code, [-8, 80001], true)
                ? 'Roboter antwortet nicht (offline oder im Ruhezustand).'
                : 'Hersteller-Cloud meldet Fehler ' . $code . ((string)($json['msg'] ?? '') !== '' ? ': ' . $json['msg'] : '') . '.');
        }
        return $json;
    }

    /** Befehl an ein Gerät; Rückgabe data.result. */
    private function sendCommand(array $buendel, string $did, string $methode, array $params)
    {
        $id = random_int(1000, 9999);
        $prefix = self::MARKEN[$this->marke]['prefix'];
        $antwort = $this->api($buendel, '/dreame-iot-com-' . $prefix . '/device/sendCommand', [
            'did'  => $did,
            'id'   => $id,
            'data' => ['did' => $did, 'id' => $id, 'method' => $methode, 'params' => $params],
        ]);
        $this->dbg('sendCommand ' . $methode, (string)json_encode(['params' => $params, 'antwort' => $antwort['data'] ?? null], JSON_UNESCAPED_UNICODE));
        return $antwort['data']['result'] ?? [];
    }

    private function pruefeRegion(string $region): string
    {
        return preg_match('/^[a-z]{2}$/', $region) ? $region : 'eu';
    }

    private function dbg(string $thema, string $text): void
    {
        if (is_callable($this->debug)) {
            ($this->debug)($thema, $text);
        }
    }

    /**
     * Ein HTTP-Aufruf; Rückgabe [Statuscode, Text] — Status 0 bei
     * Verbindungsfehler (Text = Fehlermeldung). Eigene Methode, damit der
     * Prüfstand sie ersetzen kann. curl statt Sys_GetURLContentEx, weil
     * diese kein POST kann (SUITE.md Stolperstein 11).
     */
    protected function http(string $methode, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $methode,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_ENCODING       => '',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $text = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = curl_error($ch);
        curl_close($ch);
        if ($text === false) {
            return [0, $fehler];
        }
        return [$status, (string)$text];
    }
}
