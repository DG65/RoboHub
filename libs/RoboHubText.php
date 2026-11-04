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

    /** Für Werte aus Tabellen (Konstanten), die erst beim Anzeigen übersetzt werden. */
    public static function wert($wert)
    {
        return is_string($wert) && $wert !== '' ? self::t($wert) : $wert;
    }
}
