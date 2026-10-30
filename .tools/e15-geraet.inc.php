<?php
/**
 * Nachgebauter Eufy E15 (Tuya 3.5), nur auf den Rahmen arbeitend: von test-eufymaeher.inc.php im Speicher
 * und von e15-nachbau.php über echtes TCP genutzt. Prüft alles, was ein echtes Gerät prüfen würde,
 * und schweigt bei falschem Schlüssel oder falscher Reihenfolge.
 */

declare(strict_types=1);

class E15Geraet
{
    public $schluessel;
    public $dps = ['1' => false, '2' => false, '8' => 87, '26' => 30, '109' => 70, '110' => 45, '126' => 90];
    public $meldung = [];            // DP 107/108 als Meldung auf UPDATEDPS
    public $meldungAlsData = false;
    public $geschrieben = [];        // DP-Listen aus CONTROL_NEW
    public $befehle = [];
    public $abbrueche = 0;
    private $sitzung = null;
    private $meine = '';
    private $seine = '';
    private $folge = 1;
    private $puffer = '';
    private $phase = 0;
    public $aus = [];

    public function __construct(string $schluessel)
    {
        $this->schluessel = $schluessel;
    }

    private function antwort(string $schluessel, int $befehl, string $klar): void
    {
        $this->aus[] = RoboHubTuyaSitzung::rahmen($schluessel, $this->folge++, $befehl, $klar, null, 0);
    }

    public function nimm(string $daten): void
    {
        $this->puffer .= $daten;
        while (true) {
            try {
                $m = RoboHubTuyaSitzung::entnehmen($this->puffer, $this->phase === 2 ? (string)$this->sitzung : $this->schluessel);
            } catch (RoboHubFehler $e) {
                $this->abbrueche++;   // falscher Schlüssel: Gerät schweigt
                $this->puffer = '';
                return;
            }
            if ($m === null) {
                return;
            }
            [$befehl, $klar] = $m;
            $this->befehle[] = $befehl;
            if ($this->phase === 0 && $befehl === 3 && strlen($klar) === 16) {
                $this->meine = $klar;
                $this->seine = random_bytes(16);
                $this->antwort($this->schluessel, 4, $this->seine . hash_hmac('sha256', $this->meine, $this->schluessel, true));
                $this->phase = 1;
            } elseif ($this->phase === 1 && $befehl === 5 && hash_equals(hash_hmac('sha256', $this->seine, $this->schluessel, true), $klar)) {
                $tag = '';
                $this->sitzung = (string)openssl_encrypt($this->meine ^ $this->seine, 'aes-128-gcm', $this->schluessel, OPENSSL_RAW_DATA, substr($this->meine, 0, 12), $tag, '', 16);
                $this->phase = 2;
            } elseif ($this->phase === 2 && $befehl === 0x10 && $klar === '{}') {
                $this->antwort((string)$this->sitzung, 0x10, (string)json_encode(['dps' => $this->dps]));
            } elseif ($this->phase === 2 && $befehl === 0x12 && $klar === '{"dpId":[107,108]}') {
                $this->antwort((string)$this->sitzung, 0x12, '');
                if ($this->meldung !== []) {
                    $inhalt = $this->meldungAlsData ? ['protocol' => 4, 't' => 1, 'data' => ['dps' => $this->meldung]] : ['dps' => $this->meldung];
                    $this->antwort((string)$this->sitzung, 8, "3.5\0\0\0\0\0\0\0\0\0\0\0\0" . json_encode($inhalt));
                }
            } elseif ($this->phase === 2 && $befehl === 0x0d && strncmp($klar, '3.5', 3) === 0) {
                $d = json_decode(substr($klar, 15), true);
                if (($d['protocol'] ?? 0) === 5 && is_int($d['t'] ?? null) && is_array($d['data']['dps'] ?? null)) {
                    $this->geschrieben[] = $d['data']['dps'];
                    $this->antwort((string)$this->sitzung, 0x0d, '');
                }
            }
        }
    }
}

