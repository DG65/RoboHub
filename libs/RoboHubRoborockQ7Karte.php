<?php

declare(strict_types=1);

/**
 * Roborock Q7 (B01): Karte und Räume (0.55.0).
 *
 * Quelle: Python-roborock/python-roborock (Apache-2.0, Stand 93f0d98 vom 22.11.2026):
 *   protocols/b01_q7_protocol.py (create_map_key, decode_map_payload, create_map_response_assembler),
 *   map/proto/b01_scmap.proto (Feldnummern), map/b01_map_parser.py (Raster 0/127/128, Räume per Flutfüllung
 *   ab der Namensposition, begrenzt durch die Raumumrisse; Weltkoordinaten in Metern, Raster von unten).
 * Ergebnis im Rohformat von RoboHubRoborockKarte::lesen() (Kodierung „rahmen“), damit RoboHubKartenbild zeichnet.
 */
class RoboHubRoborockQ7Karte
{
    private const BODEN = 127;
    private const WAND = 128;
    private const ZIEL_WAND = 63 << 2;
    private const ZIEL_BODEN = 62 << 2;

    /** Kartenschlüssel aus Seriennummer und Modell (create_map_key). */
    public static function schluessel(string $seriennummer, string $modell): string
    {
        $teile = explode('.', $modell);
        $suffix = (string)end($teile);
        $modellSchluessel = substr($suffix . str_repeat('0', 16), 0, 16);
        $verschluesselt = (string)openssl_encrypt($seriennummer . '+' . $suffix . '+' . $seriennummer, 'aes-128-ecb', $modellSchluessel, OPENSSL_RAW_DATA);
        return substr(md5(base64_encode($verschluesselt)), 8, 16);
    }

    /** Base64 → AES-ECB → Hex-Text → zlib (decode_map_payload); null bei Fehlern. */
    public static function entschluesseln(string $roh, string $schluessel): ?string
    {
        $blob = trim($roh);
        $blob .= str_repeat('=', (4 - strlen($blob) % 4) % 4);
        $verschluesselt = base64_decode($blob, true);
        if ($verschluesselt === false || $verschluesselt === '' || strlen($verschluesselt) % 16 !== 0) {
            return null;
        }
        $hex = openssl_decrypt($verschluesselt, 'aes-128-ecb', $schluessel, OPENSSL_RAW_DATA);
        if ($hex === false || !ctype_xdigit($hex) || strlen($hex) % 2 !== 0) {
            return null;
        }
        $gepackt = hex2bin($hex);
        $daten = $gepackt === false ? false : @gzuncompress($gepackt);
        return $daten === false ? null : $daten;
    }

    /**
     * Kartenteile sammeln (create_map_response_assembler): Teile mit gleichem Zufall+Zeit, Protokoll 300 = Teil,
     * 301 = letzter Teil, nummeriert über seq ab 1. Ein einzelnes 301 gilt als ganz, wenn es sich in 16er-Blöcke dekodieren lässt.
     * $offen wird fortgeschrieben; Rückgabe der vollständige Rohtext oder null.
     */
    public static function teilAufnehmen(array &$offen, array $nachricht): ?string
    {
        $protokoll = (int)$nachricht['protokoll'];
        if (($nachricht['version'] ?? '') !== 'B01' || ($protokoll !== 300 && $protokoll !== 301) || (string)$nachricht['nutzlast'] === '') {
            return null;
        }
        $schl = $nachricht['zufall'] . '-' . $nachricht['zeit'];
        $letzter = $protokoll === 301;
        if ($protokoll === 300 || ($letzter && isset($offen[$schl]))) {
            $offen[$schl][(int)$nachricht['seq']] = [$letzter, (string)$nachricht['nutzlast']];
            $teile = $offen[$schl];
            ksort($teile);
            $nummern = array_keys($teile);
            $ende = end($teile);
            if (!$ende[0] || $nummern !== range(1, (int)end($nummern))) {
                return null;
            }
            $ganz = implode('', array_column($teile, 1));
            if (self::blockLaenge($ganz) === null) {
                return null;
            }
            unset($offen[$schl]);
            return $ganz;
        }
        if (self::blockLaenge((string)$nachricht['nutzlast']) === null) {
            $offen[$schl][(int)$nachricht['seq']] = [true, (string)$nachricht['nutzlast']];
            return null;
        }
        return (string)$nachricht['nutzlast'];
    }

    private static function blockLaenge(string $b64): ?int
    {
        $blob = trim($b64);
        $d = base64_decode($blob . str_repeat('=', (4 - strlen($blob) % 4) % 4), true);
        return ($d === false || strlen($d) % 16 !== 0) ? null : strlen($d);
    }

    // -----------------------------------------------------------------
    // Protobuf (nur Lesen, Wire-Format: 0 Varint, 1 64 Bit, 2 Länge, 5 32 Bit)
    // -----------------------------------------------------------------

    /** Nachricht → [Feldnummer => [Werte …]]; Länge-Felder bleiben Bytes, 32 Bit als Rohbytes. */
    public static function protobuf(string $b): ?array
    {
        $aus = [];
        $pos = 0;
        $n = strlen($b);
        while ($pos < $n) {
            $schl = self::varint($b, $pos);
            if ($schl === null) {
                return null;
            }
            $feld = $schl >> 3;
            switch ($schl & 7) {
                case 0:
                    $v = self::varint($b, $pos);
                    if ($v === null) {
                        return null;
                    }
                    break;
                case 1:
                    if ($pos + 8 > $n) {
                        return null;
                    }
                    $v = substr($b, $pos, 8);
                    $pos += 8;
                    break;
                case 2:
                    $l = self::varint($b, $pos);
                    if ($l === null || $l < 0 || $pos + $l > $n) {
                        return null;
                    }
                    $v = substr($b, $pos, $l);
                    $pos += $l;
                    break;
                case 5:
                    if ($pos + 4 > $n) {
                        return null;
                    }
                    $v = substr($b, $pos, 4);
                    $pos += 4;
                    break;
                default:
                    return null;
            }
            $aus[$feld][] = $v;
        }
        return $aus;
    }

    private static function varint(string $b, int &$pos): ?int
    {
        $wert = 0;
        $schiebe = 0;
        $n = strlen($b);
        while ($pos < $n && $schiebe < 64) {
            $byte = ord($b[$pos++]);
            $wert |= ($byte & 0x7F) << $schiebe;
            if (($byte & 0x80) === 0) {
                return $wert;
            }
            $schiebe += 7;
        }
        return null;
    }

    private static function f(array $m, int $feld): ?float
    {
        $v = $m[$feld][0] ?? null;
        return is_string($v) && strlen($v) === 4 ? (float)unpack('g', $v)[1] : null;
    }

    private static function u(array $m, int $feld): ?int
    {
        $v = $m[$feld][0] ?? null;
        return is_int($v) ? $v : null;
    }

    private static function unter(array $m, int $feld): array
    {
        $aus = [];
        foreach ((array)($m[$feld] ?? []) as $v) {
            $p = is_string($v) ? self::protobuf($v) : null;
            if ($p !== null) {
                $aus[] = $p;
            }
        }
        return $aus;
    }

    // -----------------------------------------------------------------
    // SCMap → Rohformat
    // -----------------------------------------------------------------

    /** Entpackte SCMap → ['roh' => …, 'raeume' => [id => Name], 'typ' => mapType] oder null. */
    public static function lesen(string $scmap): ?array
    {
        $m = self::protobuf($scmap);
        if ($m === null) {
            return null;
        }
        $kopf = self::unter($m, 3)[0] ?? null;
        $daten = self::unter($m, 4)[0] ?? null;
        if ($kopf === null || $daten === null) {
            return null;
        }
        $b = (int)self::u($kopf, 2);
        $h = (int)self::u($kopf, 3);
        $raster = (string)($daten[1][0] ?? '');
        if ($b <= 0 || $h <= 0 || $b * $h > 4000000 || strlen($raster) < $b * $h) {
            return null;
        }
        $raster = substr($raster, 0, $b * $h);
        $minX = (float)self::f($kopf, 4);
        $minY = (float)self::f($kopf, 5);
        $maxX = (float)self::f($kopf, 6);
        $maxY = (float)self::f($kopf, 7);
        $aufl = self::f($kopf, 8) ?: 0.05;
        $imBereich = function (?float $x, ?float $y) use ($minX, $minY, $maxX, $maxY): bool {
            return $x !== null && $y !== null && $x >= $minX && $x <= $maxX && $y >= $minY && $y <= $maxY;
        };
        $mm = function (float $v): int {
            return (int)round($v * 1000);
        };

        // Raumnamen und Namenspositionen (roomDataInfo, Feld 12).
        $namen = [];
        $saat = [];
        foreach (self::unter($m, 12) as $r) {
            $id = self::u($r, 1);
            if ($id === null) {
                continue;
            }
            $name = isset($r[2][0]) && is_string($r[2][0]) && $r[2][0] !== '' ? $r[2][0] : 'Room ' . $id;
            $namen[$id] = $name;
            $pos = self::unter($r, 8)[0] ?? null;
            if ($pos !== null && self::f($pos, 1) !== null && self::f($pos, 2) !== null) {
                $saat[$id] = [(int)(((float)self::f($pos, 1) - $minX) / $aufl), (int)(((float)self::f($pos, 2) - $minY) / $aufl)];
            }
        }
        ksort($namen);

        // Raumumrisse (roomOutline, Feld 14) als Grenze, dann Flutfüllung ab der Namensposition (_assign_room_pixels).
        $umrisse = [];
        foreach (self::unter($m, 14) as $o) {
            $id = self::u($o, 1);
            $punkte = [];
            foreach (self::unter($o, 2) as $p) {
                $punkte[] = [(int)self::u($p, 1), (int)self::u($p, 2)];
            }
            if ($id !== null && $punkte !== []) {
                $umrisse[$id] = $punkte;
            }
        }
        $zuordnung = array_fill(0, $b * $h, 0);
        if ($umrisse !== []) {
            $grenze = [];
            foreach ($umrisse as $punkte) {
                foreach ($punkte as [$x, $y]) {
                    if ($x < $b && $y < $h) {
                        $grenze[$y * $b + $x] = true;
                    }
                }
            }
            $maxFuellung = intdiv(substr_count($raster, chr(self::BODEN)), 2);
            foreach ($umrisse as $id => $punkte) {
                if (!isset($saat[$id])) {
                    continue;
                }
                [$sx, $sy] = $saat[$id];
                $start = $sy * $b + $sx;
                if ($sx < 0 || $sx >= $b || $sy < 0 || $sy >= $h || ord($raster[$start]) !== self::BODEN) {
                    continue;
                }
                $gefuellt = [];
                $schlange = [$start];
                $gesehen = [$start => true];
                for ($k = 0; $k < count($schlange) && count($gefuellt) <= $maxFuellung; $k++) {
                    $i = $schlange[$k];
                    $gefuellt[] = $i;
                    foreach ([$i - 1, $i + 1, $i - $b, $i + $b] as $nb) {
                        if ($nb >= 0 && $nb < $b * $h && !isset($gesehen[$nb]) && ord($raster[$nb]) === self::BODEN
                            && $zuordnung[$nb] === 0 && !isset($grenze[$nb]) && abs($nb % $b - $i % $b) <= 1) {
                            $gesehen[$nb] = true;
                            $schlange[] = $nb;
                        }
                    }
                }
                if (count($gefuellt) > $maxFuellung) {
                    continue;
                }
                foreach ($gefuellt as $i) {
                    $zuordnung[$i] = $id;
                }
                foreach ($punkte as [$x, $y]) {
                    $i = $y * $b + $x;
                    if ($i < $b * $h && ord($raster[$i]) === self::BODEN && $zuordnung[$i] === 0) {
                        $zuordnung[$i] = $id;
                    }
                }
            }
        }

        // Kodierung „rahmen“ (wie RoboHubRoborockKarte::uebersetzen): Raum n → n << 2, Wand 63 << 2, Boden 62 << 2.
        $pixel = '';
        $imBild = [];
        for ($i = 0; $i < $b * $h; $i++) {
            $w = ord($raster[$i]);
            if ($w === self::WAND) {
                $pixel .= chr(self::ZIEL_WAND);
            } elseif ($w === self::BODEN) {
                $id = $zuordnung[$i];
                if ($id > 0 && $id <= 61) {
                    $pixel .= chr($id << 2);
                    $imBild[$id] = true;
                } else {
                    $pixel .= chr(self::ZIEL_BODEN);
                }
            } elseif ($w === 0) {
                $pixel .= "\0";
            } else {
                $pixel .= chr(self::ZIEL_BODEN);   // python-roborock: unbekannte Werte als Boden
            }
        }
        $raeumeImBild = array_keys($imBild);
        sort($raeumeImBild);

        $station = null;
        $ladestation = self::unter($m, 7)[0] ?? null;
        if ($ladestation !== null && $imBereich(self::f($ladestation, 1), self::f($ladestation, 2))) {
            $station = [$mm((float)self::f($ladestation, 1)), $mm((float)self::f($ladestation, 2)), (int)round(rad2deg((float)self::f($ladestation, 3)))];
        }
        $roboter = null;
        $pose = self::unter($m, 8)[0] ?? null;
        if ($pose !== null && $imBereich(self::f($pose, 3), self::f($pose, 4))) {
            $roboter = [$mm((float)self::f($pose, 3)), $mm((float)self::f($pose, 4)), (int)round(rad2deg((float)self::f($pose, 5)))];
        }
        $weg = [];
        foreach (self::unter($m, 6) as $verlauf) {
            foreach (self::unter($verlauf, 2) as $p) {
                if ($imBereich(self::f($p, 2), self::f($p, 3))) {
                    $weg[] = [$mm((float)self::f($p, 2)), $mm((float)self::f($p, 3))];
                }
            }
        }

        $rasterMm = max(1, (int)round($aufl * 1000));
        return [
            'typ'    => (int)self::u($m, 1),
            'raeume' => $namen,
            'roh'    => [
                'breite' => $b, 'hoehe' => $h, 'pixel' => $pixel,
                'json'   => ['fsm' => 1],
                'raster' => $rasterMm,
                'links'  => $mm($minX),
                'oben'   => $mm($minY),
                'raeume' => $raeumeImBild,
                'station' => $station,
                'roboter' => $roboter,
                'weg'    => count($weg) > 1 ? [$weg] : [],
                'teppich' => '',
                'entfernt' => 0,
            ],
        ];
    }
}
