<?php
/**
 * Echter WebSocket-Gegenspieler für RoboHubNarwalVerbindung (unverschlüsselt, wie ein Narwal auf Port 9002).
 * Aufruf: php narwal-nachbau.php — wählt einen freien Port, schreibt ihn auf stdout und beantwortet genau eine Verbindung:
 * Handschlag prüfen, Ping senden (Pong erwartet), eine zweigeteilte Binärnachricht, dann die
 * empfangene Anfrage gespiegelt als Antwort (Feld 5), dann Schließen. Läuft höchstens 20 s.
 */

declare(strict_types=1);

$server = stream_socket_server('tcp://127.0.0.1:0', $nr, $text);
if ($server === false) {
    fwrite(STDERR, "kein Server: $text\n");
    exit(1);
}
echo explode(':', (string)stream_socket_get_name($server, false))[1], "\n";
fflush(STDOUT);
$c = @stream_socket_accept($server, 20);
if ($c === false) {
    exit(1);
}
stream_set_timeout($c, 10);
$kopf = '';
while (strpos($kopf, "\r\n\r\n") === false && !feof($c)) {
    $kopf .= (string)fread($c, 4096);
}
if (!preg_match('/^Sec-WebSocket-Key:\s*(\S+)/mi', $kopf, $m) || strpos($kopf, "GET / HTTP/1.1\r\n") !== 0) {
    fwrite($c, "HTTP/1.1 400 Bad Request\r\n\r\n");
    exit(1);
}
$akzeptanz = base64_encode(sha1($m[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
fwrite($c, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " . $akzeptanz . "\r\n\r\n");

/** Server-Rahmen (unmaskiert). */
function rahmen(string $daten, int $op, bool $fin = true): string
{
    $n = strlen($daten);
    $kopf = chr(($fin ? 0x80 : 0) | $op);
    if ($n < 126) {
        $kopf .= chr($n);
    } else {
        $kopf .= chr(126) . pack('n', $n);
    }
    return $kopf . $daten;
}

/** Einen Client-Rahmen lesen (maskiert). */
function lies($c): ?array
{
    $k = fread($c, 2);
    if ($k === false || strlen($k) < 2) {
        return null;
    }
    $n = ord($k[1]) & 0x7F;
    if ($n === 126) {
        $n = unpack('n', fread($c, 2))[1];
    }
    $maske = fread($c, 4);
    $d = $n > 0 ? fread($c, $n) : '';
    for ($i = 0; $i < $n; $i++) {
        $d[$i] = $d[$i] ^ $maske[$i % 4];
    }
    return ['op' => ord($k[0]) & 0x0F, 'daten' => $d];
}

$anfrage = lies($c);                                   // Anfrage des Clients
$gross = str_repeat('K', 300);
fwrite($c, rahmen('ping!', 0x9));                     // Ping: Client muss Pong senden
fwrite($c, rahmen("\x01\x02\x2a\x00" . substr($gross, 0, 100), 0x2, false));   // erster Teil
fwrite($c, rahmen(substr($gross, 100), 0x0, true));    // Fortsetzung
$pong = lies($c);
// Antwort und Schließen in EINEM Schreibvorgang (kommt so auch vom echten Roboter): die Antwort darf nicht verloren gehen.
fwrite($c, rahmen("\x01\x02\x2a\x00" . ($anfrage['daten'] ?? '') . '|' . ($pong['op'] ?? 0) . ':' . ($pong['daten'] ?? ''), 0x2) . rahmen('', 0x8));
usleep(200000);
fclose($c);
