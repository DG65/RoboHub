<?php
/**
 * Echter TCP-Gegenspieler für RoboHubTuyaVerbindung: wählt einen freien Port, schreibt ihn auf stdout, beantwortet genau
 * eine Verbindung und schickt jede Antwort zerteilt. Läuft höchstens 30 s. Aufruf: php e15-nachbau.php <Schlüssel>
 */

declare(strict_types=1);

require __DIR__ . '/../libs/RoboHubModell.php';
require __DIR__ . '/../libs/RoboHubEufyMaeher.php';
require __DIR__ . '/e15-geraet.inc.php';

set_time_limit(30);
$server = stream_socket_server('tcp://127.0.0.1:0');
echo (int)substr((string)strrchr((string)stream_socket_get_name($server, false), ':'), 1), "\n";
$c = stream_socket_accept($server, 25);
if ($c === false) {
    exit(1);
}
$g = new E15Geraet($argv[1]);
$g->meldung = ['107' => base64_encode("\x08\x02\x18\x01"), '108' => base64_encode("\x08\x01\x10\x00")];
$ende = microtime(true) + 25;
while (microtime(true) < $ende) {
    $d = fread($c, 8192);
    if ($d === '' || $d === false) {
        break;
    }
    $g->nimm($d);
    foreach ($g->aus as $rahmen) {
        fwrite($c, substr($rahmen, 0, 7));
        fflush($c);
        usleep(30000);
        fwrite($c, substr($rahmen, 7));
        fflush($c);
    }
    $g->aus = [];
}
