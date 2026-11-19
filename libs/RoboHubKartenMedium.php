<?php

declare(strict_types=1);

// ===========================================================================
// Karte als fertiges Bild (PNG) für ein Medienobjekt — für IPSView-Bildelemente
// und alle, die keine HTML-Kachel/WebView nutzen wollen (Forum alsk1 144636/105).
//
// Die Kachel legt Raumnamen, Station, Weg und Roboter als HTML über das Kartenbild.
// Hier wird dasselbe in ein Bild gezeichnet: Kartenbild hochskaliert (Bildpunkte
// bleiben scharf), darauf Weg, Station, Roboter mit Blickrichtung und Raumnamen.
// Außerhalb der Karte bleibt das Bild durchsichtig, es passt also auf jeden Hintergrund.
//
// Braucht die GD-Erweiterung von PHP. Fehlt sie, liefert bild() das unveränderte
// Kartenbild (Räume ohne Beschriftung), damit das Medienobjekt trotzdem etwas zeigt.
// ===========================================================================

final class RoboHubKartenMedium
{
    /** Zielgröße der längeren Seite in Bildpunkten (ganzzahlig hochskaliert, höchstens 8×). */
    private const ZIEL = 900;

    /**
     * $karte wie im Attribut „Karte" (bild als data-URI, marken/station/roboter/weg in Prozent),
     * Raumnamen bereits übersetzt. Rückgabe PNG-Bytes oder null (kein Kartenbild).
     */
    public static function bild(array $karte, bool $namen): ?string
    {
        $roh = self::kartenPng((string)($karte['bild'] ?? ''));
        if ($roh === null) {
            return null;
        }
        if (!function_exists('imagecreatefromstring')) {
            return $roh;
        }
        $quelle = @imagecreatefromstring($roh);
        if ($quelle === false) {
            return $roh;
        }
        $w0 = imagesx($quelle);
        $h0 = imagesy($quelle);
        $f = max(1, min(8, intdiv(self::ZIEL, max($w0, $h0, 1))));
        $w = $w0 * $f;
        $h = $h0 * $f;

        $bild = imagecreatetruecolor($w, $h);
        imagealphablending($bild, false);
        imagesavealpha($bild, true);
        imagefilledrectangle($bild, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($bild, 0, 0, 0, 127));
        // Palettenbild mit durchsichtigem Eintrag 0 → echte Durchsichtigkeit (Alpha) vor dem Kopieren.
        imagepalettetotruecolor($quelle);
        imagealphablending($quelle, false);
        imagesavealpha($quelle, true);
        imagecopyresized($bild, $quelle, 0, 0, 0, 0, $w, $h, $w0, $h0);
        imagealphablending($bild, true);

        $px = static fn ($p): int => (int)round((float)$p / 100 * $w);
        $py = static fn ($p): int => (int)round((float)$p / 100 * $h);
        // Größen nach der LÄNGEREN Seite: IPSView skaliert das Bild auf seine Breite — nach der kürzeren Seite
        // wären Station/Roboter bei flachen Karten kleiner als bei quadratischen (Forum alsk1 144636/125).
        $lang = max($w, $h);
        $dicke = max(2, intdiv($lang, 450));

        // Gefahrener Weg (wie in der Kachel #3a6fd8, leicht durchscheinend)
        imagesetthickness($bild, $dicke);
        $wegFarbe = imagecolorallocatealpha($bild, 0x3a, 0x6f, 0xd8, 32);
        foreach ((array)($karte['weg'] ?? []) as $stueck) {
            $vorher = null;
            foreach ((array)$stueck as $p) {
                if (!isset($p[0], $p[1])) {
                    continue;
                }
                $jetzt = [$px($p[0]), $py($p[1])];
                if ($vorher !== null) {
                    imageline($bild, $vorher[0], $vorher[1], $jetzt[0], $jetzt[1], $wegFarbe);
                }
                $vorher = $jetzt;
            }
        }
        imagesetthickness($bild, 1);

        $weiss = imagecolorallocate($bild, 255, 255, 255);
        $r = max(6, intdiv($lang, 90));

        // Station: grüner Punkt mit weißem Rand
        if (isset($karte['station']['x'], $karte['station']['y'])) {
            $sx = $px($karte['station']['x']);
            $sy = $py($karte['station']['y']);
            imagefilledellipse($bild, $sx, $sy, 2 * $r + 4, 2 * $r + 4, $weiss);
            imagefilledellipse($bild, $sx, $sy, 2 * $r, 2 * $r, imagecolorallocate($bild, 0x2e, 0x9b, 0x57));
        }

        // Roboter: blauer Punkt mit weißem Rand und Blickrichtung (Winkel wie in der Kachel:
        // 0° = rechts, gegen den Uhrzeigersinn; Bild-y zeigt nach unten).
        if (isset($karte['roboter']['x'], $karte['roboter']['y'])) {
            $rx = $px($karte['roboter']['x']);
            $ry = $py($karte['roboter']['y']);
            $winkel = deg2rad((float)($karte['roboter']['winkel'] ?? 0));
            imagefilledellipse($bild, $rx, $ry, 2 * $r + 4 + 2 * $dicke, 2 * $r + 4 + 2 * $dicke, imagecolorallocatealpha($bild, 0x1f, 0x5f, 0xd1, 80));
            imagefilledellipse($bild, $rx, $ry, 2 * $r + 4, 2 * $r + 4, $weiss);
            imagefilledellipse($bild, $rx, $ry, 2 * $r, 2 * $r, imagecolorallocate($bild, 0x1f, 0x5f, 0xd1));
            imagesetthickness($bild, $dicke);
            imageline($bild, $rx, $ry, (int)round($rx + cos($winkel) * $r * 0.9), (int)round($ry - sin($winkel) * $r * 0.9), $weiss);
            imagesetthickness($bild, 1);
        }

        // Raumnamen: weiße Schrift auf dunklem Schild, mittig auf dem Raum.
        if ($namen) {
            $schrift = $w >= 600 ? 4 : 3;
            $sw = imagefontwidth($schrift);
            $sh = imagefontheight($schrift);
            $schild = imagecolorallocatealpha($bild, 0x1c, 0x1c, 0x1e, 30);
            foreach ((array)($karte['marken'] ?? []) as $m) {
                $text = self::latin2((string)($m['name'] ?? ''));
                if ($text === '' || !isset($m['x'], $m['y'])) {
                    continue;
                }
                $tw = strlen($text) * $sw;
                $x = $px($m['x']) - intdiv($tw, 2);
                $y = $py($m['y']) - intdiv($sh, 2);
                imagefilledrectangle($bild, $x - 5, $y - 3, $x + $tw + 4, $y + $sh + 2, $schild);
                imagestring($bild, $schrift, $x, $y, $text, $weiss);
            }
        }

        ob_start();
        imagepng($bild, null, 6);
        $png = (string)ob_get_clean();
        return $png !== '' ? $png : $roh;
    }

    /** data:image/png;base64,… → PNG-Bytes, sonst null. */
    private static function kartenPng(string $uri): ?string
    {
        if (strncmp($uri, 'data:image/png;base64,', 22) !== 0) {
            return null;
        }
        $png = base64_decode(substr($uri, 22), true);
        return ($png !== false && strncmp($png, "\x89PNG", 4) === 0) ? $png : null;
    }

    /** Die eingebauten GD-Schriften kennen ISO-8859-2 (deckt ä ö ü ß); Unbekanntes wird „?". */
    private static function latin2(string $text): string
    {
        $aus = @mb_convert_encoding($text, 'ISO-8859-2', 'UTF-8');
        return is_string($aus) ? $aus : preg_replace('/[^\x20-\x7e]/', '?', $text);
    }
}
