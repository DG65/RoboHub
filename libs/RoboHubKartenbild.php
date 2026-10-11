<?php

declare(strict_types=1);

// ===========================================================================
// Kartenbild für Kachel und IPSView-Seite — ohne GD, ohne fremde Bibliothek.
//
// Sauger: die gespeicherte Karte ist ein Raster aus Bildpunkten (ein Byte je
//   Punkt). Welche Bits Raum, Wand und Teppich tragen, hängt vom Gerät ab
//   (Tasshack map.py, DreameVacuumMapDecoder): drei Varianten, siehe
//   PUNKT_*. RoboHub nimmt die Variante, deren Raumnummern zu den Räumen der
//   Karte (seg_inf) passen, und schreibt ein PNG mit Farbpalette. Zeile 0 der
//   Karte ist das kleinste y — Tasshack zeichnet deshalb von unten nach oben
//   (map.py render: pixel_type[x, height - y - 1]); hier genauso.
// Mäher: Zonen sind Vielecke in Millimetern (mowingAreas/forbiddenAreas,
//   ioBroker main.js getMowerMap) → SVG, y ebenfalls gespiegelt
//   (ioBroker: toY = (y2 - y) * scale).
//
// Beschriftung (Raum-/Zonennamen, Station) liefert RoboHub nicht ins Bild,
// sondern als Prozent-Positionen — die Kachel legt sie als Text darüber, so
// bleibt die Schrift bei jeder Größe scharf.
// ===========================================================================

final class RoboHubKartenbild
{
    // Farben angelehnt an die Hersteller-Apps (helle Pastelltöne je Raum).
    private const RAUMFARBEN = [
        [143, 184, 232], [245, 198, 138], [154, 212, 163], [240, 163, 163],
        [194, 168, 230], [242, 224, 138], [143, 214, 208], [230, 168, 207],
    ];
    private const WAND = [91, 100, 112];
    private const BODEN = [207, 214, 222];
    // Trennlinie zwischen zwei Räumen: weiß wie die Raumfugen der Dreamehome-App.
    private const TRENNLINIE = [255, 255, 255];
    private const TRENN = 3;

    // Klassen im Zwischenraster: 0 außen, 1 Wand, 2 Boden ohne Raum, 100+n Raum n.
    private const AUSSEN = 0;
    private const WANDPUNKT = 1;
    private const BODENPUNKT = 2;

    // Zielgröße der längeren Bildseite in Punkten (ganzzahlig vergrößert, scharf).
    private const ZIEL_PX = 360;

    /**
     * $roh aus RoboHubDreame::dekodiereKarteRoh(), $raeume [ID => Name].
     * Rückgabe ['bild' => data-URI|'', 'marken' => [...], 'station' => [...]|null, 'kodierung' => string].
     */
    public static function sauger(array $roh, array $raeume, bool $live = false): array
    {
        $leer = ['bild' => '', 'marken' => [], 'station' => null, 'format' => 1.0, 'kodierung' => ''];
        $b = (int)($roh['breite'] ?? 0);
        $h = (int)($roh['hoehe'] ?? 0);
        $pixel = (string)($roh['pixel'] ?? '');
        if ($b <= 0 || $h <= 0 || strlen($pixel) < $b * $h || $b * $h > 4000000) {
            return $leer;
        }
        $segInf = array_map('intval', array_keys((array)($roh['json']['seg_inf'] ?? [])));
        $kodierung = self::waehleKodierung($pixel, $roh['json'] ?? [], $segInf);

        // Klassen je Punkt + Umriss der belegten Fläche.
        $klasse = [];
        $minX = $b;
        $minY = $h;
        $maxX = -1;
        $maxY = -1;
        $zaehler = count_chars($pixel, 1);
        $tabelle = [];
        foreach (array_keys($zaehler) as $wert) {
            $tabelle[$wert] = self::klassifiziere($kodierung, $wert);
        }
        for ($y = 0; $y < $h; $y++) {
            $zeile = $h - 1 - $y;                       // gespiegelt: oben im Bild = größtes y
            $basis = $zeile * $b;
            for ($x = 0; $x < $b; $x++) {
                $k = $tabelle[ord($pixel[$basis + $x])] ?? self::AUSSEN;
                if ($k === self::AUSSEN) {
                    continue;
                }
                $klasse[$y][$x] = $k;
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }
        if ($maxX < 0) {
            return $leer;
        }
        $w = $maxX - $minX + 1;
        $hh = $maxY - $minY + 1;

        // Palette: 0 transparent, 1 Wand, 2 Boden, 3 Trennlinie, ab 4 die Räume (feste Farbe je Raum-Reihenfolge).
        $ids = array_values(array_unique(array_merge(array_keys($raeume), $segInf)));
        sort($ids);
        $index = [];
        $palette = [[255, 255, 255], self::WAND, self::BODEN, self::TRENNLINIE];
        foreach ($ids as $i => $id) {
            if (count($palette) >= 256) {
                break;
            }
            $index[(int)$id] = count($palette);
            $palette[] = self::RAUMFARBEN[$i % count(self::RAUMFARBEN)];
        }

        // Mindestens doppelt vergrößern: die Trennlinie ist ein Bildpunkt des
        // vergrößerten Bildes, also halb so breit wie ein Kartenpunkt (5 cm).
        $f = max(2, min(4, intdiv(self::ZIEL_PX, max($w, $hh))));
        $andererRaum = function (int $k, int $nachbar): bool {
            return $k >= 100 && $nachbar >= 100 && $nachbar !== $k;
        };
        $summe = [];
        $zeilen = '';
        for ($y = $minY; $y <= $maxY; $y++) {
            $z = '';
            $unten = '';                                // letzte Teilzeile: Linie zum Raum darunter
            for ($x = $minX; $x <= $maxX; $x++) {
                $k = $klasse[$y][$x] ?? self::AUSSEN;
                if ($k >= 100) {
                    $seg = $k - 100;
                    $p = $index[$seg] ?? self::BODENPUNKT;
                    $s = &$summe[$seg];
                    $s[0] = ($s[0] ?? 0) + ($x - $minX);
                    $s[1] = ($s[1] ?? 0) + ($y - $minY);
                    $s[2] = ($s[2] ?? 0) + 1;
                    unset($s);
                } else {
                    $p = $k;
                }
                $rechts = $andererRaum($k, $klasse[$y][$x + 1] ?? self::AUSSEN);
                $teil = str_repeat(chr($p), $f - 1) . chr($rechts ? self::TRENN : $p);
                $z .= $teil;
                $unten .= $andererRaum($k, $klasse[$y + 1][$x] ?? self::AUSSEN) ? str_repeat(chr(self::TRENN), $f) : $teil;
            }
            $zeilen .= str_repeat("\0" . $z, $f - 1) . "\0" . $unten;   // Filter 0 je Zeile
        }

        $marken = [];
        foreach ($summe as $seg => [$sx, $sy, $n]) {
            if (!isset($raeume[$seg]) || $n < 4) {
                continue;
            }
            $marken[] = ['id' => (int)$seg, 'name' => (string)$raeume[$seg], 'x' => self::prozent($sx / $n + 0.5, $w), 'y' => self::prozent($sy / $n + 0.5, $hh)];
        }

        $station = null;
        $raster = (int)($roh['raster'] ?? 0);
        if ($raster > 0 && isset($roh['station'][0], $roh['station'][1])) {
            $px = ((int)$roh['station'][0] - (int)$roh['links']) / $raster;
            $py = $h - 1 - ((int)$roh['station'][1] - (int)$roh['oben']) / $raster;
            if ($px >= $minX - 1 && $px <= $maxX + 1 && $py >= $minY - 1 && $py <= $maxY + 1 && !($roh['station'][0] === 0 && $roh['station'][1] === 0)) {
                $station = ['x' => self::prozent($px - $minX + 0.5, $w), 'y' => self::prozent($py - $minY + 0.5, $hh)];
            }
        }

        // Umrechnung Karten-mm → Bild-%: für Weg und Roboter jetzt und für
        // spätere Echtzeit-Positionen (RoboHubKartenbild::prozentPunkt).
        $geometrie = $raster > 0
            ? ['art' => 'raster', 'links' => (int)$roh['links'], 'oben' => (int)$roh['oben'], 'raster' => $raster, 'hoehe' => $h, 'minX' => $minX, 'minY' => $minY, 'b' => $w, 'h' => $hh]
            : null;
        $ergebnis = [
            'bild'      => 'data:image/png;base64,' . base64_encode(self::png($w * $f, $hh * $f, $palette, $zeilen)),
            'marken'    => $marken,
            'station'   => $station,
            'format'    => round($w / $hh, 3),
            'geometrie' => $geometrie,
            'kodierung' => $kodierung,
        ];
        // Nur in einer Live-Karte sind Roboterposition und Weg aktuell; die
        // gespeicherte Karte trägt den Stand von irgendwann.
        if ($live && $geometrie !== null) {
            $ergebnis['weg'] = self::wegNachProzent(self::saugerWeg((string)($roh['json']['tr'] ?? '')), $geometrie);
            $r = $roh['roboter'] ?? null;
            if (is_array($r) && !self::ungueltig($r[0], $r[1]) && empty($roh['json']['nr'])) {
                $ergebnis['roboter'] = self::prozentPunkt($geometrie, (float)$r[0], (float)$r[1]) + ['winkel' => (int)($r[2] ?? 0)];
            }
        }
        return $ergebnis;
    }

    /** 32767 (bzw. −32768) steht im Kartenkopf für „keine Position". */
    private static function ungueltig($x, $y): bool
    {
        return !is_numeric($x) || !is_numeric($y) || abs((int)$x) >= 32767 || abs((int)$y) >= 32767;
    }

    /**
     * Weg aus dem Kartentext „tr" (Tasshack map.py): Befehle M/W/S/l mit
     * absoluten mm-Koordinaten, L mit relativen. M beginnt ein neues Stück
     * (Fahrt ohne Reinigen wird nicht gezeichnet), alle anderen verlängern es.
     * Rückgabe: Liste von Stücken [[x, y], …] in mm.
     */
    public static function saugerWeg(string $tr): array
    {
        if ($tr === '' || !preg_match_all('/([MWSLl])(-?\d+),(-?\d+)/', $tr, $treffer, PREG_SET_ORDER)) {
            return [];
        }
        $stuecke = [];
        $aktuell = [];
        $x = 0;
        $y = 0;
        foreach ($treffer as [, $op, $a, $b]) {
            if ($op === 'L') {
                $x += (int)$a;
                $y += (int)$b;
            } else {
                $x = (int)$a;
                $y = (int)$b;
            }
            if ($op === 'M') {
                if (count($aktuell) > 1) {
                    $stuecke[] = $aktuell;
                }
                $aktuell = [];
            }
            $aktuell[] = [$x, $y];
        }
        if (count($aktuell) > 1) {
            $stuecke[] = $aktuell;
        }
        return $stuecke;
    }

    /**
     * Mähweg aus M_PATH (ioBroker docs/mower-protocol.md): Liste von [x, y]
     * (zehntel der Kartenkoordinaten → ×10), null oder [32767, −32768] trennt Stücke.
     */
    public static function maeherWeg(array $pfad): array
    {
        $stuecke = [];
        $aktuell = [];
        foreach ($pfad as $p) {
            if (!is_array($p) || !isset($p[0], $p[1]) || ((int)$p[0] === 32767 && (int)$p[1] === -32768)) {
                if (count($aktuell) > 1) {
                    $stuecke[] = $aktuell;
                }
                $aktuell = [];
                continue;
            }
            $aktuell[] = [(float)$p[0] * 10, (float)$p[1] * 10];
        }
        if (count($aktuell) > 1) {
            $stuecke[] = $aktuell;
        }
        return $stuecke;
    }

    // Höchstzahl Wegpunkte für die Kachel — darüber wird gleichmäßig ausgedünnt.
    private const WEG_MAX = 1500;

    /** Stücke in mm → Stücke in % (eine Nachkommastelle), ausgedünnt auf WEG_MAX Punkte. */
    private static function wegNachProzent(array $stuecke, array $geometrie): array
    {
        $gesamt = 0;
        foreach ($stuecke as $s) {
            $gesamt += count($s);
        }
        $schritt = max(1, (int)ceil($gesamt / self::WEG_MAX));
        $aus = [];
        foreach ($stuecke as $s) {
            $neu = [];
            $n = count($s);
            foreach ($s as $i => [$x, $y]) {
                if ($i % $schritt !== 0 && $i !== $n - 1) {
                    continue;                           // Endpunkt jedes Stücks bleibt
                }
                $p = self::prozentPunkt($geometrie, (float)$x, (float)$y);
                $neu[] = [$p['x'], $p['y']];
            }
            if (count($neu) > 1) {
                $aus[] = $neu;
            }
        }
        return $aus;
    }

    /** Karten-mm → Bild-% nach der Geometrie eines Kartenbilds (Sauger-Raster oder Mäher-mm). */
    public static function prozentPunkt(array $g, float $x, float $y): array
    {
        if (($g['art'] ?? '') === 'raster') {
            $px = ($x - $g['links']) / $g['raster'] - $g['minX'];
            $py = $g['hoehe'] - 1 - ($y - $g['oben']) / $g['raster'] - $g['minY'];
            return ['x' => self::prozent($px + 0.5, (float)$g['b']), 'y' => self::prozent($py + 0.5, (float)$g['h'])];
        }
        return ['x' => self::prozent($x - $g['x1'], (float)$g['b']), 'y' => self::prozent($g['y2'] - $y, (float)$g['h'])];
    }

    /**
     * Bildpunkt-Varianten (Tasshack map.py, Rahmen „I"):
     *  standard — Raum = Bits 0–5, Bit 7 = Wand/Raumgrenze (häufigster Fall gespeicherter Karten)
     *  v3       — Raum = Bits 0–4 (31 = Boden ohne Raum), Bits 5–6 = Wand (3 = nicht zeichnen)
     *  rahmen   — Raum = Bits 2–7 (63 Wand, 62 Boden, 61 unbekannt), sonst Bits 0–5: 1/3 Boden, 2 Wand
     */
    private static function klassifiziere(string $kodierung, int $p): int
    {
        if ($p === 0) {
            return self::AUSSEN;
        }
        if ($kodierung === 'rahmen') {
            $seg = $p >> 2;
            if ($seg > 0 && $seg < 64) {
                return $seg === 63 ? self::WANDPUNKT : ($seg >= 61 ? self::BODENPUNKT : 100 + $seg);
            }
            $seg = $p & 0x3F;
            return $seg === 2 ? self::WANDPUNKT : (($seg === 1 || $seg === 3) ? self::BODENPUNKT : self::AUSSEN);
        }
        if ($kodierung === 'v3') {
            $seg = $p & 0x1F;
            $wand = ($p >> 5) & 0x03;
            if ($seg === 0 || $wand === 3) {
                return self::AUSSEN;
            }
            if ($wand > 0) {
                return self::WANDPUNKT;
            }
            return $seg === 31 ? self::BODENPUNKT : 100 + $seg;
        }
        $seg = $p & 0x3F;
        if ($p >> 7) {
            return self::WANDPUNKT;
        }
        return $seg > 0 ? 100 + $seg : self::AUSSEN;
    }

    /** Variante wählen: „fsm" = Rahmenkarte; sonst die, deren Räume am besten zu seg_inf passen. */
    private static function waehleKodierung(string $pixel, array $json, array $segInf): string
    {
        if ((int)($json['fsm'] ?? 0) === 1) {
            return 'rahmen';
        }
        if ($segInf === []) {
            return 'standard';
        }
        $zaehler = count_chars($pixel, 1);
        $beste = 'standard';
        $besterWert = PHP_INT_MIN;
        foreach (['standard', 'v3'] as $k) {
            $wert = 0;
            foreach ($zaehler as $p => $n) {
                $klasse = self::klassifiziere($k, $p);
                if ($klasse >= 100) {
                    $wert += in_array($klasse - 100, $segInf, true) ? $n : -$n;
                }
            }
            if ($wert > $besterWert) {
                $besterWert = $wert;
                $beste = $k;
            }
        }
        return $beste;
    }

    /**
     * Mäher: Karten-Slot (mowingAreas/forbiddenAreas mit path [{x,y}] in mm) → SVG.
     * Rückgabe ['bild' => data-URI|'', 'marken' => [...], 'station' => null].
     */
    public static function maeher(array $slot, array $zonen, array $weg = []): array
    {
        $leer = ['bild' => '', 'marken' => [], 'station' => null, 'format' => 1.0];
        $flaechen = [];
        foreach (['mowingAreas' => 'zone', 'forbiddenAreas' => 'verbot'] as $schluessel => $art) {
            foreach ((array)($slot[$schluessel]['value'] ?? []) as $eintrag) {
                $pfad = [];
                foreach ((array)($eintrag[1]['path'] ?? []) as $pt) {
                    if (is_array($pt) && isset($pt['x'], $pt['y']) && is_numeric($pt['x']) && is_numeric($pt['y'])) {
                        $pfad[] = [(float)$pt['x'], (float)$pt['y']];
                    }
                }
                if (count($pfad) >= 3) {
                    $flaechen[] = ['art' => $art, 'id' => (int)($eintrag[0] ?? 0), 'pfad' => $pfad];
                }
            }
        }
        if ($flaechen === []) {
            return $leer;
        }
        $xs = [];
        $ys = [];
        foreach ($flaechen as $fl) {
            foreach ($fl['pfad'] as [$x, $y]) {
                $xs[] = $x;
                $ys[] = $y;
            }
        }
        $x1 = min($xs);
        $x2 = max($xs);
        $y1 = min($ys);
        $y2 = max($ys);
        $rand = max($x2 - $x1, $y2 - $y1) * 0.03 + 1;
        $x1 -= $rand;
        $y1 -= $rand;
        $x2 += $rand;
        $y2 += $rand;
        $w = $x2 - $x1;
        $hh = $y2 - $y1;
        $linie = round(max($w, $hh) / 250, 1);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . round($w) . ' ' . round($hh) . '">';
        $marken = [];
        foreach ($flaechen as $fl) {
            $punkte = [];
            $sx = 0.0;
            $sy = 0.0;
            foreach ($fl['pfad'] as [$x, $y]) {
                $px = $x - $x1;
                $py = $y2 - $y;                          // gespiegelt wie ioBroker
                $punkte[] = round($px) . ',' . round($py);
                $sx += $px;
                $sy += $py;
            }
            $farbe = $fl['art'] === 'zone' ? 'fill="#7cc47f" fill-opacity=".55" stroke="#3f8f45"' : 'fill="#e57373" fill-opacity=".45" stroke="#c0392b"';
            $svg .= '<polygon points="' . implode(' ', $punkte) . '" ' . $farbe . ' stroke-width="' . $linie . '" stroke-linejoin="round"/>';
            if ($fl['art'] === 'zone' && isset($zonen[$fl['id']])) {
                $n = count($fl['pfad']);
                $marken[] = ['id' => $fl['id'], 'name' => (string)$zonen[$fl['id']], 'x' => self::prozent($sx / $n, $w), 'y' => self::prozent($sy / $n, $hh)];
            }
        }
        $svg .= '</svg>';
        $geometrie = ['art' => 'mm', 'x1' => $x1, 'y2' => $y2, 'b' => $w, 'h' => $hh];
        $ergebnis = ['bild' => 'data:image/svg+xml;base64,' . base64_encode($svg), 'marken' => $marken, 'station' => null, 'format' => round($w / $hh, 3), 'geometrie' => $geometrie];
        if ($weg !== []) {
            $ergebnis['weg'] = self::wegNachProzent($weg, $geometrie);
        }
        return $ergebnis;
    }

    private static function prozent(float $wert, float $gesamt): float
    {
        return $gesamt > 0 ? round(max(0.0, min(100.0, $wert / $gesamt * 100)), 1) : 50.0;
    }

    /** PNG mit Farbpalette (Typ 3, 8 Bit), Eintrag 0 durchsichtig. $zeilen = Rohzeilen mit Filterbyte. */
    private static function png(int $breite, int $hoehe, array $palette, string $zeilen): string
    {
        $plte = '';
        foreach ($palette as [$r, $g, $b]) {
            $plte .= chr($r) . chr($g) . chr($b);
        }
        return "\x89PNG\r\n\x1a\n"
            . self::block('IHDR', pack('NN', $breite, $hoehe) . "\x08\x03\x00\x00\x00")
            . self::block('PLTE', $plte)
            . self::block('tRNS', "\x00")
            . self::block('IDAT', (string)gzcompress($zeilen, 9))
            . self::block('IEND', '');
    }

    private static function block(string $typ, string $daten): string
    {
        return pack('N', strlen($daten)) . $typ . $daten . pack('N', crc32($typ . $daten));
    }
}
