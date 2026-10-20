<?php

declare(strict_types=1);

// ===========================================================================
// Schlanker TLS-1.2-Client (RFC 5246) für ältere iRobot-Roboter.
//
// Warum: Roomba 980 & Co. mit Firmware 2.x beherrschen die sichere
// Neuverhandlung nach RFC 5746 nicht. OpenSSL 3 (auch Symcons PHP) bricht den
// Handschlag mit solchen Servern ab („unsafe legacy renegotiation disabled"),
// und PHP bietet keinen Schalter dafür (roombapy setzt dazu
// OP_LEGACY_SERVER_CONNECT). Dieser Client handelt nie neu — eine Anfrage des
// Servers wird ignoriert —, damit ist der Angriff, gegen den OpenSSL schützt,
// hier ausgeschlossen.
//
// Umfang bewusst klein: nur RSA-Schlüsseltausch mit AES-CBC und HMAC
// (TLS_RSA_WITH_AES_128/256_CBC_SHA256/SHA). Kein Zertifikatsketten-Check —
// der Aufrufer prüft den SHA-256-Fingerabdruck von $zertifikat (Trust on first
// use, siehe RoboHubIrobot). Dietmars Entscheidung 20.10.2026.
// ===========================================================================

final class RoboHubTls
{
    // Kennung → [Verschlüsselung, Schlüssellänge, MAC-Hash, MAC-Länge]
    private const SUITEN = [
        0x003C => ['aes-128-cbc', 16, 'sha256', 32],
        0x003D => ['aes-256-cbc', 32, 'sha256', 32],
        0x002F => ['aes-128-cbc', 16, 'sha1', 20],
        0x0035 => ['aes-256-cbc', 32, 'sha1', 20],
    ];
    private const VERSION = "\x03\x03";
    private const T_CCS = 20;
    private const T_ALARM = 21;
    private const T_HANDSCHLAG = 22;
    private const T_DATEN = 23;

    /** @var string DER des Server-Zertifikats */
    public $zertifikat = '';
    /** @var int ausgehandelte Suite */
    public $suite = 0;

    /** @var resource */
    private $sock;
    private $roh = '';
    private $klar = '';
    private $verlauf = '';      // alle Handschlag-Nachrichten für Finished
    private $hsOffen = '';      // angefangene Handschlag-Nachricht
    private $zu = false;
    private $schreibSchluessel = null;   // [Verschlüsselung, Schlüssel, MAC-Hash, MAC-Schlüssel]
    private $leseSchluessel = null;
    private $schreibNr = 0;
    private $leseNr = 0;

    private function __construct($sock)
    {
        $this->sock = $sock;
    }

    /** Verbindet und führt den Handschlag aus; wirft RoboHubFehler. */
    public static function verbinden(string $host, int $port, float $frist): self
    {
        $sock = @stream_socket_client('tcp://' . $host . ':' . $port, $nr, $text, $frist);
        if ($sock === false) {
            throw new RoboHubFehler('TCP-Verbindung zu ' . $host . ':' . $port . ' fehlgeschlagen (' . $text . ').');
        }
        $tls = new self($sock);
        try {
            $tls->handschlag(microtime(true) + $frist);
        } catch (Throwable $e) {
            @fclose($sock);
            throw $e instanceof RoboHubFehler ? $e : new RoboHubFehler($e->getMessage());
        }
        return $tls;
    }

    public function schreiben(string $daten): void
    {
        foreach (str_split($daten, 16384) as $stueck) {
            $this->datensatzSenden(self::T_DATEN, $stueck);
        }
    }

    /**
     * Wartet höchstens $sekunden auf Anwendungsdaten. Rückgabe: Daten,
     * '' bei Fristablauf, null wenn die Verbindung zu ist.
     */
    public function lesen(float $sekunden): ?string
    {
        $ende = microtime(true) + max(0.0, $sekunden);
        while ($this->klar === '') {
            if ($this->zu) {
                return null;
            }
            $satz = $this->datensatzHolen($ende);
            if ($satz === null) {
                return $this->zu ? null : '';
            }
            [$typ, $inhalt] = $satz;
            if ($typ === self::T_DATEN) {
                $this->klar .= $inhalt;
            } elseif ($typ === self::T_ALARM) {
                $this->alarm($inhalt);
            }
            // Handschlag-Sätze nach dem Aufbau (Neuverhandlung) werden ignoriert.
        }
        $aus = $this->klar;
        $this->klar = '';
        return $aus;
    }

    public function schliessen(): void
    {
        if (!$this->zu) {
            try {
                $this->datensatzSenden(self::T_ALARM, "\x01\x00");   // close_notify
            } catch (Throwable $e) {
            }
        }
        $this->zu = true;
        @fclose($this->sock);
    }

    // =================================================================
    // Handschlag
    // =================================================================

    private function handschlag(float $ende): void
    {
        $clientZufall = random_bytes(32);
        $suiten = '';
        foreach (array_keys(self::SUITEN) as $s) {
            $suiten .= pack('n', $s);
        }
        $suiten .= "\x00\xFF";   // TLS_EMPTY_RENEGOTIATION_INFO_SCSV
        // signature_algorithms: rsa_pkcs1_sha256/384/512, rsa_pkcs1_sha1
        $sigAlgs = "\x04\x01\x05\x01\x06\x01\x02\x01";
        $erweiterungen = pack('nn', 13, strlen($sigAlgs) + 2) . pack('n', strlen($sigAlgs)) . $sigAlgs;
        $hallo = self::VERSION . $clientZufall . "\x00" . pack('n', strlen($suiten)) . $suiten . "\x01\x00"
            . pack('n', strlen($erweiterungen)) . $erweiterungen;
        $this->handschlagSenden(1, $hallo);

        $serverZufall = null;
        $zertGewuenscht = false;
        while (true) {
            [$typ, $inhalt] = $this->handschlagNachricht($ende);
            if ($typ === 2) {                                   // ServerHello
                if (substr($inhalt, 0, 2) !== self::VERSION) {
                    throw new RoboHubFehler('Gerät spricht kein TLS 1.2.');
                }
                $serverZufall = substr($inhalt, 2, 32);
                $sidLaenge = ord($inhalt[34]);
                $this->suite = unpack('n', substr($inhalt, 35 + $sidLaenge, 2))[1];
                if (!isset(self::SUITEN[$this->suite]) || ord($inhalt[37 + $sidLaenge]) !== 0) {
                    throw new RoboHubFehler(sprintf('Gerät wählt nicht unterstützte Verschlüsselung 0x%04X.', $this->suite));
                }
            } elseif ($typ === 11) {                            // Certificate
                $laenge = unpack('N', "\x00" . substr($inhalt, 3, 3))[1];
                $this->zertifikat = substr($inhalt, 6, $laenge);
            } elseif ($typ === 12) {                            // ServerKeyExchange — nur bei DHE/ECDHE
                throw new RoboHubFehler('Gerät verlangt einen Schlüsseltausch, den RoboHub hier nicht anbietet.');
            } elseif ($typ === 13) {                            // CertificateRequest
                $zertGewuenscht = true;
            } elseif ($typ === 14) {                            // ServerHelloDone
                break;
            }
        }
        if ($serverZufall === null || $this->zertifikat === '') {
            throw new RoboHubFehler('Unvollständiger TLS-Handschlag des Geräts.');
        }
        if ($zertGewuenscht) {
            $this->handschlagSenden(11, "\x00\x00\x00");          // leere Zertifikatsliste
        }

        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($this->zertifikat), 64, "\n") . "-----END CERTIFICATE-----\n";
        $schluessel = @openssl_pkey_get_public($pem);
        $vorab = self::VERSION . random_bytes(46);
        if ($schluessel === false || !openssl_public_encrypt($vorab, $verschluesselt, $schluessel, OPENSSL_PKCS1_PADDING)) {
            throw new RoboHubFehler('Öffentlicher Schlüssel des Geräts unbrauchbar (kein RSA?).');
        }
        $this->handschlagSenden(16, pack('n', strlen($verschluesselt)) . $verschluesselt);

        $haupt = self::prf($vorab, 'master secret', $clientZufall . $serverZufall, 48);
        [$algo, $schluesselLaenge, $macHash, $macLaenge] = self::SUITEN[$this->suite];
        $block = self::prf($haupt, 'key expansion', $serverZufall . $clientZufall, 2 * $macLaenge + 2 * $schluesselLaenge);
        $clientMac = substr($block, 0, $macLaenge);
        $serverMac = substr($block, $macLaenge, $macLaenge);
        $clientKey = substr($block, 2 * $macLaenge, $schluesselLaenge);
        $serverKey = substr($block, 2 * $macLaenge + $schluesselLaenge, $schluesselLaenge);

        $this->rohSenden(self::T_CCS, "\x01");
        $this->schreibSchluessel = [$algo, $clientKey, $macHash, $clientMac];
        $this->schreibNr = 0;
        $fertig = self::prf($haupt, 'client finished', hash('sha256', $this->verlauf, true), 12);
        $this->handschlagSenden(20, $fertig);

        // Server: ChangeCipherSpec, dann verschlüsseltes Finished
        $erwartet = self::prf($haupt, 'server finished', hash('sha256', $this->verlauf, true), 12);
        while (true) {
            $satz = $this->datensatzHolen($ende);
            if ($satz === null) {
                throw new RoboHubFehler('Gerät hat den TLS-Handschlag nicht abgeschlossen.');
            }
            [$typ, $inhalt] = $satz;
            if ($typ === self::T_ALARM) {
                $this->alarm($inhalt);
                continue;
            }
            if ($typ === self::T_CCS && $this->leseSchluessel === null) {
                $this->leseSchluessel = [$algo, $serverKey, $macHash, $serverMac];
                $this->leseNr = 0;
                continue;
            }
            if ($typ === self::T_HANDSCHLAG && $this->leseSchluessel !== null
                && ord($inhalt[0]) === 20 && hash_equals($erwartet, (string)substr($inhalt, 4, 12))) {
                return;
            }
            throw new RoboHubFehler('TLS-Abschluss des Geräts ungültig.');
        }
    }

    /** Liest die nächste Handschlag-Nachricht (auch mehrere je Satz oder über Sätze verteilt). */
    private function handschlagNachricht(float $ende): array
    {
        while (true) {
            if (strlen($this->hsOffen) >= 4) {
                $laenge = unpack('N', "\x00" . substr($this->hsOffen, 1, 3))[1];
                if (strlen($this->hsOffen) >= 4 + $laenge) {
                    $nachricht = substr($this->hsOffen, 0, 4 + $laenge);
                    $this->hsOffen = (string)substr($this->hsOffen, 4 + $laenge);
                    $this->verlauf .= $nachricht;
                    return [ord($nachricht[0]), (string)substr($nachricht, 4)];
                }
            }
            $satz = $this->datensatzHolen($ende);
            if ($satz === null) {
                $this->hsOffen = '';
                throw new RoboHubFehler('Gerät hat den TLS-Handschlag abgebrochen.');
            }
            [$typ, $inhalt] = $satz;
            if ($typ === self::T_ALARM) {
                $this->hsOffen = '';
                $this->alarm($inhalt);
                throw new RoboHubFehler('Gerät hat den TLS-Handschlag beendet.');
            }
            if ($typ !== self::T_HANDSCHLAG) {
                $this->hsOffen = '';
                throw new RoboHubFehler('Unerwarteter TLS-Satz im Handschlag.');
            }
            $this->hsOffen .= $inhalt;
        }
    }

    private function handschlagSenden(int $typ, string $inhalt): void
    {
        $nachricht = chr($typ) . substr(pack('N', strlen($inhalt)), 1) . $inhalt;
        $this->verlauf .= $nachricht;
        $this->datensatzSenden(self::T_HANDSCHLAG, $nachricht);
    }

    private function alarm(string $inhalt): void
    {
        $stufe = ord($inhalt[0] ?? "\x02");
        $grund = ord($inhalt[1] ?? "\x00");
        if ($grund === 0) {                 // close_notify
            $this->zu = true;
            return;
        }
        if ($stufe === 2) {
            $this->zu = true;
            throw new RoboHubFehler('Gerät meldet TLS-Fehler ' . $grund . '.');
        }
    }

    // =================================================================
    // Datensätze
    // =================================================================

    private function datensatzSenden(int $typ, string $inhalt): void
    {
        if ($this->schreibSchluessel !== null) {
            [$algo, $key, $macHash, $macKey] = $this->schreibSchluessel;
            $mac = hash_hmac($macHash, pack('J', $this->schreibNr++) . chr($typ) . self::VERSION . pack('n', strlen($inhalt)) . $inhalt, $macKey, true);
            $daten = $inhalt . $mac;
            $fuell = 15 - (strlen($daten) % 16);
            $daten .= str_repeat(chr($fuell), $fuell + 1);
            $iv = random_bytes(16);
            $inhalt = $iv . openssl_encrypt($daten, $algo, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
        }
        $this->rohSenden($typ, $inhalt);
    }

    private function rohSenden(int $typ, string $inhalt): void
    {
        $satz = chr($typ) . self::VERSION . pack('n', strlen($inhalt)) . $inhalt;
        while ($satz !== '') {
            $n = @fwrite($this->sock, $satz);
            if ($n === false || $n === 0) {
                $this->zu = true;
                throw new RoboHubFehler('Verbindung zum Gerät unterbrochen.');
            }
            $satz = (string)substr($satz, $n);
        }
    }

    /** Nächster vollständiger Satz [Typ, Klartext] oder null bei Frist/Ende. */
    private function datensatzHolen(float $ende): ?array
    {
        while (true) {
            if (strlen($this->roh) >= 5) {
                $laenge = unpack('n', substr($this->roh, 3, 2))[1];
                if ($laenge > 18432) {
                    $this->zu = true;
                    throw new RoboHubFehler('Ungültiger TLS-Satz vom Gerät.');
                }
                if (strlen($this->roh) >= 5 + $laenge) {
                    $typ = ord($this->roh[0]);
                    $inhalt = (string)substr($this->roh, 5, $laenge);
                    $this->roh = (string)substr($this->roh, 5 + $laenge);
                    if ($this->leseSchluessel !== null && $typ !== self::T_CCS) {
                        $inhalt = $this->entschluesseln($typ, $inhalt);
                    }
                    return [$typ, $inhalt];
                }
            }
            $rest = $ende - microtime(true);
            if ($rest <= 0) {
                return null;
            }
            $lesen = [$this->sock];
            $schreiben = null;
            $ausnahme = null;
            if (@stream_select($lesen, $schreiben, $ausnahme, (int)$rest, (int)(($rest - floor($rest)) * 1000000)) < 1) {
                return null;
            }
            $stueck = @fread($this->sock, 32768);
            if ($stueck === false || ($stueck === '' && feof($this->sock))) {
                $this->zu = true;
                return null;
            }
            $this->roh .= $stueck;
        }
    }

    private function entschluesseln(int $typ, string $satz): string
    {
        [$algo, $key, $macHash, $macKey] = $this->leseSchluessel;
        $macLaenge = strlen(hash($macHash, '', true));
        if (strlen($satz) < 32 || strlen($satz) % 16 !== 0) {
            $this->zu = true;
            throw new RoboHubFehler('Ungültiger verschlüsselter TLS-Satz vom Gerät.');
        }
        $klar = openssl_decrypt(substr($satz, 16), $algo, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($satz, 0, 16));
        $fuell = $klar === false ? 255 : ord($klar[strlen($klar) - 1]);
        $ok = $klar !== false && strlen($klar) >= $fuell + 1 + $macLaenge
            && substr($klar, -($fuell + 1)) === str_repeat(chr($fuell), $fuell + 1);
        $inhalt = $ok ? substr($klar, 0, strlen($klar) - $fuell - 1 - $macLaenge) : '';
        $mac = $ok ? substr($klar, strlen($klar) - $fuell - 1 - $macLaenge, $macLaenge) : '';
        $soll = hash_hmac($macHash, pack('J', $this->leseNr++) . chr($typ) . self::VERSION . pack('n', strlen($inhalt)) . $inhalt, $macKey, true);
        if (!$ok || !hash_equals($soll, $mac)) {
            $this->zu = true;
            throw new RoboHubFehler('TLS-Satz vom Gerät ist beschädigt (Prüfsumme).');
        }
        return $inhalt;
    }

    /** TLS-1.2-PRF mit SHA-256 (RFC 5246 Abschnitt 5). */
    public static function prf(string $geheim, string $etikett, string $saat, int $laenge): string
    {
        $saat = $etikett . $saat;
        $a = $saat;
        $aus = '';
        while (strlen($aus) < $laenge) {
            $a = hash_hmac('sha256', $a, $geheim, true);
            $aus .= hash_hmac('sha256', $a . $saat, $geheim, true);
        }
        return substr($aus, 0, $laenge);
    }
}
