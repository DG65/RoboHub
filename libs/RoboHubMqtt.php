<?php

declare(strict_types=1);

// ===========================================================================
// Schlanker MQTT-3.1.1-Client (nur, was RoboHub braucht): CONNECT,
// SUBSCRIBE, PUBLISH (QoS 0), PINGREQ, DISCONNECT, PUBACK senden; CONNACK, SUBACK, PUBLISH,
// PINGRESP lesen. Die TCP/TLS-Verbindung hält Symcons Client Socket (bzw. beim
// Roborock-Treiber ein eigener kurzer TLS-Strom); diese
// Klasse baut nur die Pakete (Bytes) und zerlegt den empfangenen Datenstrom.
//
// Paketaufbau nach OASIS MQTT 3.1.1, Kap. 2 (fester Kopf + „Remaining
// Length" als Variable-Length-Integer) und Kap. 3 (Pakettypen).
// ===========================================================================

final class RoboHubMqtt
{
    public const CONNECT     = 1;
    public const CONNACK     = 2;
    public const PUBLISH     = 3;
    public const PUBACK      = 4;
    public const SUBSCRIBE   = 8;
    public const SUBACK      = 9;
    public const PINGREQ     = 12;
    public const PINGRESP    = 13;
    public const DISCONNECT  = 14;

    /** CONNECT mit Benutzer/Passwort, Clean Session (3.1). */
    public static function connect(string $clientId, string $benutzer, string $passwort, int $keepalive): string
    {
        $variabel = self::text('MQTT') . "\x04"                     // Protokollname + Level 4 (= 3.1.1)
            . chr(0x80 | 0x40 | 0x02)                               // Benutzer, Passwort, Clean Session
            . pack('n', $keepalive);
        $nutzlast = self::text($clientId) . self::text($benutzer) . self::text($passwort);
        return self::paket(self::CONNECT << 4, $variabel . $nutzlast);
    }

    /** SUBSCRIBE (3.8): fester Kopf 0x82, Paket-ID, je Topic Länge+Text+QoS. */
    public static function subscribe(int $paketId, array $topics, int $qos): string
    {
        $nutzlast = '';
        foreach ($topics as $t) {
            $nutzlast .= self::text((string)$t) . chr($qos);
        }
        return self::paket((self::SUBSCRIBE << 4) | 0x02, pack('n', $paketId) . $nutzlast);
    }

    /** PUBLISH mit QoS 0 (3.3): fester Kopf 0x30, Topic, Inhalt. */
    public static function publish(string $topic, string $inhalt): string
    {
        return self::paket(self::PUBLISH << 4, self::text($topic) . $inhalt);
    }

    public static function pingreq(): string
    {
        return chr(self::PINGREQ << 4) . "\x00";
    }

    public static function disconnect(): string
    {
        return chr(self::DISCONNECT << 4) . "\x00";
    }

    public static function puback(int $paketId): string
    {
        return chr(self::PUBACK << 4) . "\x02" . pack('n', $paketId);
    }

    /**
     * Zerlegt den Puffer in vollständige Pakete; unvollständige Reste bleiben
     * im Puffer (TCP liefert in beliebigen Stücken). Rückgabe: Liste von
     * ['typ' => int, 'flags' => int, 'body' => string].
     */
    public static function zerlegen(string &$puffer): array
    {
        $pakete = [];
        while (strlen($puffer) >= 2) {
            $laenge = 0;
            $faktor = 1;
            $pos = 1;
            do {
                if ($pos >= strlen($puffer)) {
                    return $pakete;                                 // Längenangabe noch unvollständig
                }
                $byte = ord($puffer[$pos++]);
                $laenge += ($byte & 0x7F) * $faktor;
                $faktor *= 128;
                if ($faktor > 128 * 128 * 128 * 128) {
                    $puffer = '';                                   // ungültig — Strom verwerfen
                    return $pakete;
                }
            } while ($byte & 0x80);
            if (strlen($puffer) < $pos + $laenge) {
                return $pakete;                                     // Paket noch nicht vollständig
            }
            $kopf = ord($puffer[0]);
            $pakete[] = ['typ' => $kopf >> 4, 'flags' => $kopf & 0x0F, 'body' => substr($puffer, $pos, $laenge)];
            $puffer = (string)substr($puffer, $pos + $laenge);
        }
        return $pakete;
    }

    /** PUBLISH-Inhalt (3.3): ['topic', 'payload', 'qos', 'id']. */
    public static function publishLesen(int $flags, string $body): array
    {
        $qos = ($flags >> 1) & 0x03;
        $tlen = strlen($body) >= 2 ? unpack('n', $body)[1] : 0;
        $topic = substr($body, 2, $tlen);
        $pos = 2 + $tlen;
        $id = 0;
        if ($qos > 0) {
            $id = unpack('n', substr($body, $pos, 2))[1];
            $pos += 2;
        }
        return ['topic' => $topic, 'payload' => (string)substr($body, $pos), 'qos' => $qos, 'id' => $id];
    }

    /** Rückgabecode aus CONNACK (3.2.2.3): 0 = angenommen. */
    public static function connackCode(string $body): int
    {
        return strlen($body) >= 2 ? ord($body[1]) : 255;
    }

    public static function connackText(int $code): string
    {
        return [
            0 => 'angenommen',
            1 => 'Protokollversion abgelehnt',
            2 => 'Client-ID abgelehnt',
            3 => 'Server nicht verfügbar',
            4 => 'Benutzer/Zugangsschlüssel ungültig',
            5 => 'nicht berechtigt',
        ][$code] ?? 'Code ' . $code;
    }

    private static function text(string $s): string
    {
        return pack('n', strlen($s)) . $s;
    }

    private static function paket(int $kopf, string $rest): string
    {
        $laenge = strlen($rest);
        $kodiert = '';
        do {
            $byte = $laenge % 128;
            $laenge = intdiv($laenge, 128);
            if ($laenge > 0) {
                $byte |= 0x80;
            }
            $kodiert .= chr($byte);
        } while ($laenge > 0);
        return chr($kopf) . $kodiert . $rest;
    }
}
