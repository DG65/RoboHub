<?php

declare(strict_types=1);

// ===========================================================================
// Übersetzung für alle RoboHub-Teile. Quelltexte stehen auf Englisch im Code,
// die deutsche Fassung in locale.json (Symcons übliches Muster; die Datei wird
// aus libs/locale.json für alle drei Module erzeugt: .tools/locale-bauen.php).
//
// Treiber, Modell und Kachel haben kein $this->Translate(); jedes Modul setzt
// deshalb im Konstruktor seinen eigenen Übersetzer. Ohne Übersetzer (Prüfstand,
// Hilfsskripte) bleibt der englische Text stehen.
// ===========================================================================

final class RoboHubText
{
    /** @var callable|null fn(string): string */
    private static $uebersetzer = null;

    public static function setzen(?callable $uebersetzer): void
    {
        self::$uebersetzer = $uebersetzer;
    }

    /** Text übersetzen; mit Argumenten wie sprintf (Platzhalter %s, ein Prozentzeichen als %%). */
    public static function t(string $text, ...$args): string
    {
        $uebersetzt = self::$uebersetzer !== null ? (string)(self::$uebersetzer)($text) : $text;
        if ($args === []) {
            return $uebersetzt;
        }
        $aus = @vsprintf($uebersetzt, array_map(function ($a) {
            return is_scalar($a) || $a === null ? (string)$a : (string)json_encode($a);
        }, $args));
        return $aus === false ? $uebersetzt : $aus;
    }

    // Wochentag-Kürzel: Treiber liefern teils deutsche (Mo), teils englische (Mon) Kürzel.
    private const TAGE = ['Mo' => 'Mon', 'Di' => 'Tue', 'Mi' => 'Wed', 'Do' => 'Thu', 'Fr' => 'Fri', 'Sa' => 'Sat', 'So' => 'Sun'];

    /** Einheitliches englisches Kürzel (Mon … Sun) für Vergleiche; Unbekanntes bleibt, wie es ist. */
    public static function tagCode(string $tag): string
    {
        return self::TAGE[$tag] ?? $tag;
    }

    /** Wochentag-Kürzel in der Sprache des Systems. */
    public static function tag(string $tag): string
    {
        return self::t(self::tagCode($tag));
    }

    /** Für Werte aus Tabellen (Konstanten), die erst beim Anzeigen übersetzt werden. */
    public static function wert($wert)
    {
        return is_string($wert) && $wert !== '' ? self::t($wert) : $wert;
    }

    /**
     * Raumname zur Anzeige: Treiber speichern Raumtypen sprachneutral („Kitchen 2", „Room 6") —
     * übersetzt wird erst hier. Beim Speichern übersetzt hing die Sprache davon ab, in welchem
     * Ablauf die Karte geholt wurde (Forum power1625 12.11.2026: Namen nach Minuten englisch).
     * Eigene Namen aus der App bleiben unverändert.
     */
    public static function raum(string $name): string
    {
        $uebersetzt = (string)self::t($name);
        if ($uebersetzt !== $name) {
            return $uebersetzt;
        }
        if (preg_match('/^Room (\d+)$/', $name, $m)) {
            return (string)self::t('Room %s', $m[1]);
        }
        if (preg_match('/^(.+) (\d+)$/u', $name, $m)) {
            $basis = (string)self::t($m[1]);
            if ($basis !== $m[1]) {
                return $basis . ' ' . $m[2];
            }
        }
        return $name;
    }
}
