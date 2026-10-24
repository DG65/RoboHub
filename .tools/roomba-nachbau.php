<?php
/**
 * Nachgestellter iRobot-Roboter für den Prüfstand (echte Sockets, echtes TLS):
 *   - UDP: beantwortet „irobotmcs" mit Gerätedaten
 *   - TLS (selbst ausgestelltes Zertifikat): Passwort-Magie und lokales MQTT
 * Aufruf: php roomba-nachbau.php <Protokolldatei> [alt]
 *   alt = wie ein Roomba 980 mit Firmware 2.x: nur TLS 1.2 mit RSA-Schlüsseltausch
 *         und AES-CBC (prüft RoboHubs eigenen TLS-Weg gegen echtes OpenSSL); Zustand
 *         mit QoS 1 und erst nach jeder Bestätigung (PUBACK) das nächste Stück
 * Gibt „<TLS-Port> <UDP-Port> <SHA-256 des Zertifikats>" aus und bedient
 * Verbindungen, bis der Prüfstand den Prozess beendet (spätestens nach 60 s).
 */

declare(strict_types=1);

require __DIR__ . '/../libs/RoboHubModell.php';
require __DIR__ . '/../libs/RoboHubMqtt.php';

$protokoll = $argv[1];
$schluessel = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$zert = openssl_csr_sign(openssl_csr_new(['commonName' => 'Roomba'], $schluessel), null, $schluessel, 30);
openssl_x509_export($zert, $zertPem);
openssl_pkey_export($schluessel, $schluesselPem);
$datei = tempnam(sys_get_temp_dir(), 'roomba');
file_put_contents($datei, $zertPem . $schluesselPem);

$tls = stream_socket_server('tls://127.0.0.1:0', $nr, $text, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    stream_context_create(['ssl' => ['local_cert' => $datei, 'verify_peer' => false] + (($argv[2] ?? '') === 'alt'
        ? ['ciphers' => 'AES128-SHA256:AES256-SHA256:AES128-SHA:AES256-SHA:@SECLEVEL=0', 'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER] : [])]));
$udp = stream_socket_server('udp://127.0.0.1:0', $nr, $text, STREAM_SERVER_BIND);
$port = (int)substr((string)strrchr(stream_socket_get_name($tls, false), ':'), 1);
$udpPort = (int)substr((string)strrchr(stream_socket_get_name($udp, false), ':'), 1);
echo $port . ' ' . $udpPort . ' ' . openssl_x509_fingerprint($zert, 'sha256') . "\n";
@ob_flush();
flush();

$log = function (string $z) use ($protokoll): void { file_put_contents($protokoll, $z . "\n", FILE_APPEND); };
$schluss = time() + 60;

while (time() < $schluss) {
    $lesen = [$tls, $udp];
    $w = null;
    $e = null;
    if (@stream_select($lesen, $w, $e, 1) < 1) {
        continue;
    }
    if (in_array($udp, $lesen, true)) {
        $daten = stream_socket_recvfrom($udp, 64, 0, $absender);
        if ($daten === 'irobotmcs') {
            stream_socket_sendto($udp, json_encode(['hostname' => 'Roomba-3176C41234567890', 'robotname' => 'Krümel', 'sku' => 'i755840',
                'sw' => 'soho+3.20.7', 'ip' => '127.0.0.1', 'mac' => '50:14:79:00:00:01', 'cap' => ['pose' => 1]]), 0, $absender);
        }
    }
    if (!in_array($tls, $lesen, true)) {
        continue;
    }
    $c = @stream_socket_accept($tls, 5);
    if ($c === false) {
        continue;
    }
    stream_set_timeout($c, 5);
    $erstes = fread($c, 4096);
    if ($erstes === "\xf0\x05\xef\xcc\x3b\x29\x00") {
        $pw = ':1:1700000000:GeheimesPasswort';
        fwrite($c, "\xf0" . chr(strlen($pw) + 5) . "\xef\xcc\x3b\x29\x00" . $pw . "\0");
        $log('passwort');
        fclose($c);
        continue;
    }
    $puffer = (string)$erstes;
    $ende = microtime(true) + 5;
    $fertig = false;
    while (!$fertig && microtime(true) < $ende) {
        foreach (RoboHubMqtt::zerlegen($puffer) as $p) {
            if ($p['typ'] === RoboHubMqtt::CONNECT) {
                // Kopf 10 Bytes, dann Client-ID (Länge + Text) — sie muss die BLID sein (roombapy identifier=blid).
                $idLaenge = unpack('n', substr($p['body'], 10, 2))[1];
                $ok = substr($p['body'], 12, $idLaenge) === '3176C41234567890' && strpos($p['body'], ':1:1700000000:GeheimesPasswort') !== false;
                fwrite($c, "\x20\x02\x00" . ($ok ? "\x00" : "\x04"));
                $log($ok ? 'connect' : 'connect-abgelehnt');
            } elseif ($p['typ'] === RoboHubMqtt::SUBSCRIBE) {
                fwrite($c, "\x90\x03\x00\x01\x00");
                $t = '$aws/things/3176C41234567890/shadow/update';
                $stuecke = [
                    ['wifistat', '{"state":{"reported":{"signal":{"rssi":-50}}}}'],
                    [$t, json_encode(['state' => ['reported' => ['batPct' => 87, 'cleanMissionStatus' => ['cycle' => 'clean', 'phase' => 'run', 'error' => 0, 'mssnM' => 12, 'sqft' => 215]]]])],
                    [$t, json_encode(['state' => ['reported' => ['bin' => ['present' => true, 'full' => false], 'bbrun' => ['hr' => 123, 'min' => 4], 'bbmssn' => ['nMssn' => 321]]]])],
                ];
                if (is_file($protokoll . '.nurfunk')) {
                    // wie der 980 sporadisch: nur Funkmeldungen, kein Stand
                    $stuecke = [$stuecke[0], $stuecke[0]];
                }
                if (($argv[2] ?? '') === 'alt') {
                    $naechste = 1;
                    $offen = $stuecke;
                    [$thema, $inhalt] = array_shift($offen);
                    fwrite($c, RoboHubMqtt::publish($thema, $inhalt, 1, $naechste));
                } else {
                    foreach ($stuecke as [$thema, $inhalt]) {
                        fwrite($c, RoboHubMqtt::publish($thema, $inhalt));
                    }
                }
                $log('abo');
            } elseif ($p['typ'] === RoboHubMqtt::PUBACK) {
                $log('puback');
                if (!empty($offen) && unpack('n', $p['body'])[1] === $naechste) {
                    $naechste++;
                    [$thema, $inhalt] = array_shift($offen);
                    fwrite($c, RoboHubMqtt::publish($thema, $inhalt, 1, $naechste));
                }
            } elseif ($p['typ'] === RoboHubMqtt::PUBLISH) {
                $pub = RoboHubMqtt::publishLesen($p['flags'], $p['body']);
                $log('befehl ' . $pub['topic'] . ' ' . $pub['payload']);
            } elseif ($p['typ'] === RoboHubMqtt::DISCONNECT) {
                $fertig = true;
            }
        }
        if ($fertig) {
            break;
        }
        $stueck = fread($c, 4096);
        if ($stueck === false || ($stueck === '' && feof($c))) {
            break;
        }
        $puffer .= $stueck;
    }
    fclose($c);
}
@unlink($datei);
