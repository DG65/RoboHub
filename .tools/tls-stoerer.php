<?php
/**
 * Störer zwischen RoboHubTls und dem nachgestellten Roboter (Prüfstand):
 * leitet TCP weiter und verfälscht in Richtung Roboter → RoboHub ein Byte.
 *   sitzung = ein Byte der Sitzungskennung im ServerHello (Schlüssel bleiben
 *             gleich, nur die Finished-Prüfung kann es merken)
 *   daten   = ein Byte im IV des ersten Anwendungsdaten-Satzes (Auffüllung
 *             bleibt gültig, nur die MAC-Prüfung kann es merken)
 * Aufruf: php tls-stoerer.php <Zielport> <Art>; gibt den eigenen Port aus,
 * läuft höchstens 30 s.
 */

declare(strict_types=1);

[$ziel, $art] = [(int)$argv[1], $argv[2]];
$server = stream_socket_server('tcp://127.0.0.1:0', $nr, $text);
echo substr((string)strrchr(stream_socket_get_name($server, false), ':'), 1) . "\n";
@ob_flush();
flush();
$schluss = time() + 30;

while (time() < $schluss) {
    $c = @stream_socket_accept($server, 1);
    if ($c === false) {
        continue;
    }
    $r = stream_socket_client('tcp://127.0.0.1:' . $ziel);
    $vonRoboter = '';
    $gestoert = false;
    while (time() < $schluss) {
        $lesen = [$c, $r];
        $w = null;
        $e = null;
        if (@stream_select($lesen, $w, $e, 1) < 1) {
            continue;
        }
        if (in_array($c, $lesen, true)) {
            $d = fread($c, 65536);
            if ($d === false || $d === '') {
                break;
            }
            fwrite($r, $d);
        }
        if (in_array($r, $lesen, true)) {
            $d = fread($r, 65536);
            if ($d === false || $d === '') {
                break;
            }
            $vonRoboter .= $d;
            // ganze Sätze weiterreichen, dabei ggf. einen verfälschen
            while (strlen($vonRoboter) >= 5 && strlen($vonRoboter) >= 5 + unpack('n', substr($vonRoboter, 3, 2))[1]) {
                $laenge = unpack('n', substr($vonRoboter, 3, 2))[1];
                $satz = substr($vonRoboter, 0, 5 + $laenge);
                $vonRoboter = (string)substr($vonRoboter, 5 + $laenge);
                if (!$gestoert && $art === 'sitzung' && ord($satz[0]) === 22 && ord($satz[5]) === 2 && ord($satz[5 + 38]) > 0) {
                    $satz[5 + 39] = chr(ord($satz[5 + 39]) ^ 0x01);   // erstes Byte der Sitzungskennung
                    $gestoert = true;
                } elseif (!$gestoert && $art === 'daten' && ord($satz[0]) === 23) {
                    $satz[5] = chr(ord($satz[5]) ^ 0x01);             // erstes IV-Byte
                    $gestoert = true;
                }
                fwrite($c, $satz);
            }
        }
    }
    fclose($c);
    fclose($r);
}
