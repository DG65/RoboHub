<?php

declare(strict_types=1);

// ===========================================================================
// Kurze MQTT-Sitzung eines Treibers (Anfrage → Antwort), ohne Symcon-Socket:
// TLS-Strom aus PHP, wahlweise mit WebSocket-Hülle (RFC 6455, Unterprotokoll
// „mqtt"). Das Server-Zertifikat wird immer gegen die übergebene Wurzel und
// den Servernamen geprüft — eine Abschaltung gibt es bewusst nicht.
//
// Genutzt von Treibern, deren Geräte nur über MQTT antworten (Roborock) bzw.
// Befehle nur über MQTT annehmen (Worx/AWS IoT über WebSocket).
// ===========================================================================

final class RoboHubMqttSitzung
{
    private const WS_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @var resource */
    private $sock;
    private $ws;
    private $roh = '';      // empfangene Bytes (bei WebSocket: noch nicht zerlegte Rahmen)
    private $puffer = '';   // MQTT-Bytes

    private function __construct($sock, bool $ws)
    {
        $this->sock = $sock;
        $this->ws = $ws;
    }

    /**
     * Öffnet die Verbindung. $wsPfad = null: MQTT direkt über TLS; sonst
     * WebSocket-Anfrage auf diesen Pfad.
     */
    public static function oeffnen(string $host, int $port, string $wurzelPem, ?string $wsPfad, float $ende): self
    {
        $kontext = stream_context_create(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host,
            'cafile' => self::caDatei($wurzelPem), 'SNI_enabled' => true,
        ]]);
        $fehlerNr = 0;
        $fehlerText = '';
        $sock = @stream_socket_client('tls://' . $host . ':' . $port, $fehlerNr, $fehlerText, 8, STREAM_CLIENT_CONNECT, $kontext);
        if ($sock === false) {
            throw new RoboHubFehler('Server nicht erreichbar oder Zertifikat ungültig (' . $host . ': ' . $fehlerText . ').');
        }
        $sitzung = new self($sock, $wsPfad !== null);
        if ($wsPfad !== null) {
            $sitzung->wsHandschlag($host, $wsPfad, $ende);
        }
        return $sitzung;
    }

    /**
     * Übernimmt einen bereits geprüften TLS-Strom (iRobot: Gerätezertifikat per
     * gemerktem Fingerabdruck geprüft, siehe RoboHubIrobot::geraetVerbinden).
     */
    public static function ausStrom($sock): self
    {
        return new self($sock, false);
    }

    public function senden(string $mqtt): void
    {
        fwrite($this->sock, $this->ws ? self::wsRahmen($mqtt, random_bytes(4)) : $mqtt);
    }

    /** Liest, bis ein MQTT-Paket des Typs kommt (und $passt es annimmt) oder $ende erreicht ist. */
    public function warte(int $typ, ?callable $passt, float $ende): ?array
    {
        while (true) {
            foreach (RoboHubMqtt::zerlegen($this->puffer) as $paket) {
                if ($paket['typ'] === $typ && ($passt === null || $passt($paket))) {
                    return $paket;
                }
            }
            if (!$this->lesen($ende)) {
                return null;
            }
        }
    }

    public function schliessen(): void
    {
        @fwrite($this->sock, $this->ws ? self::wsRahmen(RoboHubMqtt::disconnect(), random_bytes(4)) : RoboHubMqtt::disconnect());
        fclose($this->sock);
    }

    /** Ein Stück vom Strom lesen; false bei Fristablauf oder Verbindungsende. */
    private function lesen(float $ende): bool
    {
        $rest = $ende - microtime(true);
        if ($rest <= 0) {
            return false;
        }
        $lesen = [$this->sock];
        $schreiben = null;
        $ausnahme = null;
        if (@stream_select($lesen, $schreiben, $ausnahme, (int)$rest, (int)(($rest - floor($rest)) * 1000000)) < 1) {
            return false;
        }
        $stueck = fread($this->sock, 65536);
        if ($stueck === false || ($stueck === '' && feof($this->sock))) {
            return false;
        }
        if (!$this->ws) {
            $this->puffer .= $stueck;
            return true;
        }
        $this->roh .= $stueck;
        foreach (self::wsLesen($this->roh) as $rahmen) {
            if ($rahmen['op'] === 0x9) {
                fwrite($this->sock, self::wsRahmen($rahmen['daten'], random_bytes(4), 0xA));   // Ping → Pong
            } elseif ($rahmen['op'] === 0x8) {
                return false;                                                              // Server schließt
            } elseif ($rahmen['op'] === 0x2 || $rahmen['op'] === 0x0) {
                $this->puffer .= $rahmen['daten'];
            }
        }
        return true;
    }

    private function wsHandschlag(string $host, string $pfad, float $ende): void
    {
        $schluessel = base64_encode(random_bytes(16));
        fwrite($this->sock, "GET " . $pfad . " HTTP/1.1\r\nHost: " . $host . "\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: " . $schluessel . "\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Protocol: mqtt\r\n\r\n");
        $kopf = '';
        while (strpos($kopf, "\r\n\r\n") === false) {
            $rest = $ende - microtime(true);
            $lesen = [$this->sock];
            $schreiben = null;
            $ausnahme = null;
            if ($rest <= 0 || @stream_select($lesen, $schreiben, $ausnahme, (int)$rest, (int)(($rest - floor($rest)) * 1000000)) < 1) {
                throw new RoboHubFehler('Server hat die WebSocket-Verbindung nicht angenommen (keine Antwort).');
            }
            $stueck = fread($this->sock, 4096);
            if ($stueck === false || $stueck === '') {
                throw new RoboHubFehler('Server hat die WebSocket-Verbindung geschlossen.');
            }
            $kopf .= $stueck;
        }
        [$antwort, $rest] = explode("\r\n\r\n", $kopf, 2);
        if (!preg_match('#^HTTP/1\.1 101#', $antwort)) {
            throw new RoboHubFehler('Server lehnt die WebSocket-Verbindung ab: ' . strtok($antwort, "\r\n"));
        }
        if (!preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $antwort, $m) || $m[1] !== self::wsAkzeptanz($schluessel)) {
            throw new RoboHubFehler('WebSocket-Antwort des Servers ist ungültig.');
        }
        $this->roh = $rest;
    }

    /** Erwartete Sec-WebSocket-Accept (RFC 6455, 4.2.2). */
    public static function wsAkzeptanz(string $schluessel): string
    {
        return base64_encode(sha1($schluessel . self::WS_GUID, true));
    }

    /** Client-Rahmen (immer maskiert, RFC 6455, 5.2/5.3). */
    public static function wsRahmen(string $daten, string $maske, int $op = 0x2): string
    {
        $n = strlen($daten);
        $kopf = chr(0x80 | $op);
        if ($n < 126) {
            $kopf .= chr(0x80 | $n);
        } elseif ($n < 65536) {
            $kopf .= chr(0x80 | 126) . pack('n', $n);
        } else {
            $kopf .= chr(0x80 | 127) . pack('J', $n);
        }
        $maskiert = '';
        for ($i = 0; $i < $n; $i++) {
            $maskiert .= $daten[$i] ^ $maske[$i % 4];
        }
        return $kopf . $maske . $maskiert;
    }

    /** Vollständige Server-Rahmen aus dem Puffer: [['op' => int, 'daten' => string], …]. */
    public static function wsLesen(string &$puffer): array
    {
        $aus = [];
        while (strlen($puffer) >= 2) {
            $b0 = ord($puffer[0]);
            $b1 = ord($puffer[1]);
            $laenge = $b1 & 0x7F;
            $pos = 2;
            if ($laenge === 126) {
                if (strlen($puffer) < 4) {
                    break;
                }
                $laenge = unpack('n', substr($puffer, 2, 2))[1];
                $pos = 4;
            } elseif ($laenge === 127) {
                if (strlen($puffer) < 10) {
                    break;
                }
                $laenge = unpack('J', substr($puffer, 2, 8))[1];
                $pos = 10;
            }
            $maske = '';
            if ($b1 & 0x80) {
                $maske = substr($puffer, $pos, 4);
                $pos += 4;
            }
            if (strlen($puffer) < $pos + $laenge) {
                break;
            }
            $daten = substr($puffer, $pos, $laenge);
            if ($maske !== '') {
                for ($i = 0; $i < $laenge; $i++) {
                    $daten[$i] = $daten[$i] ^ $maske[$i % 4];
                }
            }
            $aus[] = ['op' => $b0 & 0x0F, 'daten' => $daten];
            $puffer = (string)substr($puffer, $pos + $laenge);
        }
        return $aus;
    }

    /** Wurzelzertifikat als Datei (für die Stream-Prüfung). */
    public static function caDatei(string $pem): string
    {
        $datei = sys_get_temp_dir() . '/robohub-ca-' . substr(md5($pem), 0, 12) . '.pem';
        if (!is_file($datei) || file_get_contents($datei) !== $pem) {
            file_put_contents($datei, $pem);
        }
        return $datei;
    }
}
