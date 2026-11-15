<?php

declare(strict_types=1);

require_once __DIR__ . '/RoboHubKartenbild.php';

// ===========================================================================
// Roborock-Karte („rr"-Kartendatei) — für das Roborock-Konto (MQTT) und für
// Roborocks im Xiaomi-Konto (Mi Home). Beide liefern dieselbe Datei, nur der
// Weg dorthin ist verschieden.
//
// Quellen (beide Apache-2.0):
//  - Python-roborock/python-roborock: protocols/v1_protocol.py
//    create_map_response_decoder (Antwort 301, Teile 300), protocol.py decrypt_cbc
//  - PiotrMachowski/Python-package-vacuum-map-parser-roborock: map_data_parser.py
//    (Blöcke), image_parser.py (Bildpunkte)
// Mi-Home-Download: PiotrMachowski/Home-Assistant-custom-components-Xiaomi-Cloud-
// Map-Extractor (MIT): /home/getmapfileurl {"obj_name"} → url → gzip.
//
// Die Darstellung übernimmt RoboHubKartenbild::sauger(): Die Bildpunkte werden
// in dessen Kodierung „rahmen" übersetzt (Raum n → n << 2, 63 << 2 Wand,
// 62 << 2 Boden). Zeile 0 der Datei ist wie bei Dreame die unterste Zeile.
// ===========================================================================

final class RoboHubRoborockKarte
{
    /** 1 Bildpunkt = 50 mm (map_data_parser.py: x / 50 − left). */
    public const RASTER = 50;

    // Blocktypen (map_data_parser.py RoborockBlockType)
    private const BLOCK_STATION = 1;
    private const BLOCK_BILD = 2;
    private const BLOCK_WEG = 3;
    private const BLOCK_ROBOTER = 8;

    // Bildpunkte (image_parser.py)
    private const PUNKT_AUSSEN = 0x00;
    private const PUNKT_WAND = 0x01;
    private const PUNKT_INNEN = 0xFF;
    private const PUNKT_GESCANNT = 0x07;

    // Zielkodierung „rahmen" in RoboHubKartenbild
    private const ZIEL_WAND = 63 << 2;
    private const ZIEL_BODEN = 62 << 2;

    /** Höchstgröße der entpackten Datei (Schutz vor kaputten Daten). */
    private const MAX_BYTES = 8000000;

    /**
     * Kartenantwort aus dem Roborock-Konto (Protokoll 301): 24 Byte Kopf
     * (8 Endpunkt, 8 unbekannt, 2 Anfrage-ID LE, 6 unbekannt), dann AES-128-CBC
     * mit den 16 Rohbytes der Nonce als Schlüssel, IV 16 Nullbytes, PKCS7, dann gzip.
     * Rückgabe ['id' => Anfrage-ID, 'rr' => Kartendatei] oder null (fremde Antwort/unlesbar).
     */
    public static function kartenAntwort(string $nutzlast, string $endpunkt, string $nonceRoh): ?array
    {
        if (strlen($nutzlast) < 24 || strpos($nutzlast, $endpunkt) !== 0) {
            return null;
        }
        $id = unpack('v', substr($nutzlast, 16, 2))[1];
        $klar = openssl_decrypt(substr($nutzlast, 24), 'aes-128-cbc', $nonceRoh, OPENSSL_RAW_DATA, str_repeat("\0", 16));
        if ($klar === false) {
            return null;
        }
        $rr = self::entpacken($klar);
        return $rr === null ? null : ['id' => (int)$id, 'rr' => $rr];
    }

    /**
     * Große Karten kommen in Teilen: alle außer dem letzten mit Protokoll 300, der letzte mit 301,
     * gemeinsames random/timestamp, fortlaufendes seq (create_map_response_decoder _add_part).
     * $teile = [seq => [letzter (bool), Nutzlast]] einer Gruppe. Rückgabe: zusammengesetzte Nutzlast
     * oder null (noch unvollständig).
     */
    public static function teileZusammen(array $teile, string $endpunkt): ?string
    {
        if ($teile === []) {
            return null;
        }
        ksort($teile);
        $seqs = array_keys($teile);
        $erste = $seqs[0];
        $letzte = $seqs[count($seqs) - 1];
        if (!$teile[$letzte][0] || $seqs !== range($erste, $letzte) || strpos($teile[$erste][1], $endpunkt) !== 0) {
            return null;
        }
        $aus = '';
        foreach ($teile as [, $nutzlast]) {
            $aus .= $nutzlast;
        }
        return $aus;
    }

    /** gzip entpacken (Mi-Home-Datei und Kartenantwort), mit Größenschutz. */
    public static function entpacken(string $daten): ?string
    {
        if (strncmp($daten, "\x1f\x8b", 2) !== 0) {
            return strncmp($daten, 'rr', 2) === 0 ? $daten : null;
        }
        $aus = @gzdecode($daten, self::MAX_BYTES);
        return is_string($aus) ? $aus : null;
    }

    /**
     * „rr"-Datei → Rohkarte für RoboHubKartenbild::sauger():
     * ['breite', 'hoehe', 'pixel' (Kodierung „rahmen"), 'json' => ['fsm' => 1], 'raster', 'links', 'oben',
     *  'station' => [x, y] | null, 'roboter' => [x, y, Winkel] | null, 'weg' => [[[x, y], …]], 'raeume' => [IDs]]
     * oder null, wenn die Datei kein Bild trägt.
     */
    public static function lesen(string $rr): ?array
    {
        $n = strlen($rr);
        if ($n < 20 || strncmp($rr, 'rr', 2) !== 0) {
            return null;
        }
        $pos = unpack('v', substr($rr, 2, 2))[1];
        $roh = ['station' => null, 'roboter' => null, 'weg' => []];
        $bild = null;
        while ($pos + 8 <= $n) {
            $k = unpack('vtyp/vkopf/Vlaenge', substr($rr, $pos, 8));
            $kopf = (int)$k['kopf'];
            $laenge = (int)$k['laenge'];
            if ($kopf < 8 || $pos + $kopf + $laenge > $n) {
                break;
            }
            $kopfDaten = substr($rr, $pos, $kopf);
            $daten = substr($rr, $pos + $kopf, $laenge);
            switch ((int)$k['typ']) {
                case self::BLOCK_STATION:
                case self::BLOCK_ROBOTER:
                    $punkt = self::position($daten);
                    if ($punkt !== null) {
                        $roh[(int)$k['typ'] === self::BLOCK_STATION ? 'station' : 'roboter'] = $punkt;
                    }
                    break;
                case self::BLOCK_BILD:
                    // Kopflänge je Firmware verschieden → Felder vom Ende des Kopfs her (map_data_parser.py _parse_image).
                    if ($kopf >= 24) {
                        $bild = ['oben' => self::int32($kopfDaten, $kopf - 16), 'links' => self::int32($kopfDaten, $kopf - 12),
                            'hoehe' => self::int32($kopfDaten, $kopf - 8), 'breite' => self::int32($kopfDaten, $kopf - 4), 'pixel' => $daten];
                    }
                    break;
                case self::BLOCK_WEG:
                    // Punkte je 4 Byte (x, y als u16 in mm) ab Kopfende (map_data_parser.py _parse_path).
                    $punkte = [];
                    for ($i = 0; $i + 4 <= $laenge; $i += 4) {
                        $p = unpack('vx/vy', substr($daten, $i, 4));
                        $punkte[] = [(int)$p['x'], (int)$p['y']];
                    }
                    if (count($punkte) > 1) {
                        $roh['weg'] = [$punkte];
                    }
                    break;
            }
            $pos += $kopf + $laenge;
        }
        if ($bild === null || $bild['breite'] <= 0 || $bild['hoehe'] <= 0 || strlen($bild['pixel']) < $bild['breite'] * $bild['hoehe']) {
            return null;
        }
        [$pixel, $raeume] = self::uebersetzen(substr($bild['pixel'], 0, $bild['breite'] * $bild['hoehe']));
        return $roh + [
            'breite' => $bild['breite'], 'hoehe' => $bild['hoehe'], 'pixel' => $pixel,
            'json'   => ['fsm' => 1],
            'raster' => self::RASTER,
            'links'  => $bild['links'] * self::RASTER,
            'oben'   => $bild['oben'] * self::RASTER,
            'raeume' => $raeume,
        ];
    }

    /** Bildpunkte Roborock → Kodierung „rahmen"; Rückgabe [Pixel, Raumnummern im Bild]. */
    public static function uebersetzen(string $pixel): array
    {
        $tabelle = [];
        $raeume = [];
        foreach (array_keys(count_chars($pixel, 1)) as $p) {
            if ($p === self::PUNKT_AUSSEN) {
                $z = 0;
            } elseif ($p === self::PUNKT_WAND) {
                $z = self::ZIEL_WAND;
            } elseif ($p === self::PUNKT_INNEN || $p === self::PUNKT_GESCANNT) {
                $z = self::ZIEL_BODEN;
            } elseif (($p & 0x07) === 7) {
                $seg = $p >> 3;                         // Segment-ID = p >> 3 (image_parser.py _get_room_number)
                $z = $seg << 2;
                $raeume[] = $seg;
            } elseif (($p & 0x07) <= 1) {
                $z = self::ZIEL_WAND;                   // graue Wand / Wand V2
            } else {
                $z = self::ZIEL_BODEN;                  // unbekannt → als Boden zeigen
            }
            $tabelle[chr($p)] = chr($z);
        }
        sort($raeume);
        return [strtr($pixel, $tabelle), $raeume];
    }

    /** Station/Roboter: x, y (int32, mm), optional Winkel (map_data_parser.py _parse_object_position). */
    private static function position(string $daten): ?array
    {
        if (strlen($daten) < 8) {
            return null;
        }
        $winkel = 0;
        if (strlen($daten) >= 12) {
            $winkel = self::int32($daten, 8);
            if ($winkel > 0xFF) {
                $winkel = ($winkel & 0xFF) - 256;
            }
        }
        return [self::int32($daten, 0), self::int32($daten, 4), $winkel];
    }

    /** int32 little-endian mit Vorzeichen (unabhängig von der Byte-Reihenfolge des Rechners). */
    private static function int32(string $daten, int $pos): int
    {
        $v = unpack('V', substr($daten, $pos, 4))[1];
        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    /**
     * Rohkarte + Raumnamen → Kachel-Karte (wie Dreame). $live = Weg und Roboter mitzeichnen.
     * Räume ohne Namen bekommen „Room n" nicht — sie bleiben farbig ohne Beschriftung.
     */
    public static function bild(array $roh, array $raeume, bool $live): array
    {
        $bild = RoboHubKartenbild::sauger($roh, $raeume, $live);
        unset($bild['kodierung']);
        return $bild;
    }
}
