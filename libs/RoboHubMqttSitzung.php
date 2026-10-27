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

    /** @var resource|RoboHubTls */
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
    public static function oeffnen(string $host, int $port, string $wurzelPem, ?string $wsPfad, float $ende, ?string $clientPem = null): self
    {
        $ssl = [
            'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host,
            'cafile' => self::caDatei($wurzelPem), 'SNI_enabled' => true,
        ];
        // Gegenseitiges TLS (Eufy/AWS IoT): Zertifikat + Schlüssel nur für die Dauer des Handschlags auf Platte.
        $clientDatei = null;
        if ($clientPem !== null) {
            $clientDatei = tempnam(sys_get_temp_dir(), 'robohub-client-');
            chmod($clientDatei, 0600);
            file_put_contents($clientDatei, $clientPem);
            $ssl['local_cert'] = $clientDatei;
        }
        $kontext = stream_context_create(['ssl' => $ssl]);
        $fehlerNr = 0;
        $fehlerText = '';
        try {
            $sock = @stream_socket_client('tls://' . $host . ':' . $port, $fehlerNr, $fehlerText, 8, STREAM_CLIENT_CONNECT, $kontext);
        } finally {
            if ($clientDatei !== null) {
                @unlink($clientDatei);
            }
        }
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
     * Übernimmt einen bereits geprüften TLS-Strom oder eine eigene TLS-Verbindung
     * (iRobot: Gerätezertifikat per gemerktem Fingerabdruck geprüft, siehe
     * RoboHubIrobot::geraetVerbinden).
     */
    public static function ausStrom($sock): self
    {
        return new self($sock, false);
    }

    public function senden(string $mqtt): void
    {
        if ($this->sock instanceof RoboHubTls) {
            $this->sock->schreiben($mqtt);
            return;
        }
        fwrite($this->sock, $this->ws ? self::wsRahmen($mqtt, random_bytes(4)) : $mqtt);
    }

    /** Liest, bis ein MQTT-Paket des Typs kommt (und $passt es annimmt) oder $ende erreicht ist. */
    public function warte(int $typ, ?callable $passt, float $ende): ?array
    {
        $zu = false;
        while (true) {
            foreach (RoboHubMqtt::zerlegen($this->puffer) as $paket) {
                $this->bestaetigen($paket);
                if ($paket['typ'] === $typ && ($passt === null || $passt($paket))) {
                    return $paket;
                }
            }
            if ($zu) {
                return null;
            }
            // Auch wenn lesen() Ende meldet: was im selben Stück noch kam (Antwort + Schließen), erst auswerten.
            $zu = !$this->lesen($ende);
        }
    }

    /**
     * Eingehende PUBLISH mit QoS 1/2 bestätigen. Ohne Bestätigung schickt ein
     * Server mit kleinem Sendefenster nichts weiter (Roomba 980 lieferte so nur
     * Bruchstücke, Forum Thilo 21.10.2026).
     */
    private function bestaetigen(array $paket): void
    {
        if ($paket['typ'] === RoboHubMqtt::PUBLISH) {
            $pub = RoboHubMqtt::publishLesen($paket['flags'], $paket['body']);
            if ($pub['qos'] === 1) {
                $this->senden(RoboHubMqtt::puback($pub['id']));
            } elseif ($pub['qos'] === 2) {
                $this->senden(RoboHubMqtt::pubrec($pub['id']));
            }
        } elseif ($paket['typ'] === RoboHubMqtt::PUBREL && strlen($paket['body']) >= 2) {
            $this->senden(RoboHubMqtt::pubcomp(unpack('n', $paket['body'])[1]));
        }
    }

    public function schliessen(): void
    {
        if ($this->sock instanceof RoboHubTls) {
            try {
                $this->sock->schreiben(RoboHubMqtt::disconnect());
            } catch (Throwable $e) {
            }
            $this->sock->schliessen();
            return;
        }
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
        if ($this->sock instanceof RoboHubTls) {
            $stueck = $this->sock->lesen($rest);
            if ($stueck === null || $stueck === '') {
                return false;
            }
            $this->puffer .= $stueck;
            return true;
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

    /** Vollständige Server-Rahmen aus dem Puffer: [['op' => int, 'daten' => string, 'fin' => bool], …]. */
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
            $aus[] = ['op' => $b0 & 0x0F, 'daten' => $daten, 'fin' => ($b0 & 0x80) !== 0];
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
