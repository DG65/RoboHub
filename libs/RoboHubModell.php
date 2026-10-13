<?php

declare(strict_types=1);

// ===========================================================================
// Herstellerneutrales Datenmodell von RoboHub.
//
// Jeder Hersteller-Treiber (heute Dreame/MOVA, später z. B. Roborock,
// Ecovacs) übersetzt seine Rohwerte auf genau diese Werte. Die
// Roboter-Instanz kennt nur dieses Modell — Variablen, Bedienung und
// Formular sind dadurch für alle Hersteller gleich.
//
// Grundregel (SUITE.md Stolperstein 15): Was die Quelle nicht liefert, ist
// null — nie 0 oder ein „harmlos wirkender" Platzhalter.
// ===========================================================================

final class RoboHubModell
{
    // Geräteklassen. Der Treiber meldet sie je Gerät; die Roboter-Instanz
    // legt nur die Variablen an, die zur Klasse passen.
    public const KLASSE_SAUGER = 'sauger';   // Saug-/Wischroboter
    public const KLASSE_MAEHER = 'maeher';   // Rasenmähroboter

    // Normalisierter Betriebszustand (Variable „Zustand").
    // „Reinigt" heißt beim Mäher „Mäht" (Beschriftung je Klasse, siehe
    // zustandsName()), der Zahlenwert bleibt gleich — Skripte und Ereignisse
    // funktionieren dadurch klassenübergreifend.
    public const ZUSTAND_UNBEKANNT      = -1;
    public const ZUSTAND_BEREIT         = 0;  // steht, nicht in der Station
    public const ZUSTAND_REINIGT        = 1;
    public const ZUSTAND_PAUSIERT       = 2;
    public const ZUSTAND_ZURUECK        = 3;  // fährt zur Station
    public const ZUSTAND_LAEDT          = 4;
    public const ZUSTAND_GELADEN        = 5;  // in der Station, voll
    public const ZUSTAND_STATIONSARBEIT = 6;  // Mopp waschen, trocknen, Staub absaugen …
    public const ZUSTAND_FEHLER         = 7;
    public const ZUSTAND_SCHLAF         = 8;
    public const ZUSTAND_KARTIERUNG     = 9;
    public const ZUSTAND_AKTUALISIERUNG = 10; // Firmware-Update
    public const ZUSTAND_WETTERPAUSE    = 11; // Mäher: Regen/Frost-Pause

    public const ZUSTAENDE = [
        self::ZUSTAND_UNBEKANNT      => ['Unbekannt', 'question'],
        self::ZUSTAND_BEREIT         => ['Bereit', 'robot'],
        self::ZUSTAND_REINIGT        => ['Reinigt', 'broom'],
        self::ZUSTAND_PAUSIERT       => ['Pausiert', 'pause'],
        self::ZUSTAND_ZURUECK        => ['Fährt zur Station', 'house'],
        self::ZUSTAND_LAEDT          => ['Lädt', 'battery-bolt'],
        self::ZUSTAND_GELADEN        => ['In der Station', 'battery-full'],
        self::ZUSTAND_STATIONSARBEIT => ['Station arbeitet', 'droplet'],
        self::ZUSTAND_FEHLER         => ['Fehler', 'triangle-exclamation'],
        self::ZUSTAND_SCHLAF         => ['Ruht', 'moon'],
        self::ZUSTAND_KARTIERUNG     => ['Kartiert', 'map'],
        self::ZUSTAND_AKTUALISIERUNG => ['Aktualisiert Firmware', 'download'],
        self::ZUSTAND_WETTERPAUSE    => ['Wetterpause', 'cloud-rain'],
    ];

    /** Beschriftung eines Zustands passend zur Geräteklasse. */
    public static function zustandsName(int $zustand, string $klasse): string
    {
        if ($klasse === self::KLASSE_MAEHER && $zustand === self::ZUSTAND_REINIGT) {
            return 'Mäht';
        }
        return self::ZUSTAENDE[$zustand][0] ?? self::ZUSTAENDE[self::ZUSTAND_UNBEKANNT][0];
    }

    // Befehle (Variable „Befehl", Aktionsvariable).
    public const BEFEHL_START   = 1;
    public const BEFEHL_PAUSE   = 2;
    public const BEFEHL_STOPP   = 3;
    public const BEFEHL_STATION = 4;
    public const BEFEHL_ORTEN   = 5;

    public const BEFEHLE = [
        self::BEFEHL_START   => ['Start / Fortsetzen', 'play'],
        self::BEFEHL_PAUSE   => ['Pause', 'pause'],
        self::BEFEHL_STOPP   => ['Stopp', 'stop'],
        self::BEFEHL_STATION => ['Zur Station', 'house'],
        self::BEFEHL_ORTEN   => ['Orten', 'location-dot'],
    ];

    /**
     * Felder, die ein Treiber in zustand() liefern darf (alle optional —
     * angelegt wird eine Variable erst, wenn der Treiber einen Wert liefert,
     * so bekommt ein Mäher nie eine „Staubbeutel"-Variable).
     * ident => [Name, Variablentyp, Einheit/Suffix, Nachkommastellen, Position]
     */
    public const FELDER = [
        'Messer'             => ['Messer', 1, ' %', 0, 55],
        'Reinigungsbuerste'  => ['Reinigungsbürste', 1, ' %', 0, 56],
        'Wartung'            => ['Wartung des Roboters', 1, ' %', 0, 57],
        // Reststunden wie in den Hersteller-Apps. Sauger: vom Gerät geliefert
        // (ganze Stunden), Mäher: aus den Minutenzählern gerechnet (0,1 h).
        'HauptbuersteRest'      => ['Hauptbürste Reststunden', 2, ' h', 0, 80],
        'SeitenbuersteRest'     => ['Seitenbürste Reststunden', 2, ' h', 0, 81],
        'FilterRest'            => ['Filter Reststunden', 2, ' h', 0, 82],
        'MoppRest'              => ['Mopp/Walze Reststunden', 2, ' h', 0, 83],
        'SensorenRest'          => ['Sensoren Reststunden', 2, ' h', 0, 84],
        'AbwasserfilterRest'    => ['Abwasserauslassfilter Reststunden', 2, ' h', 0, 88],
        'SchmutzwasserbehaelterRest' => ['Schmutzwasserbehälter Reststunden', 2, ' h', 0, 89],
        'MesserRest'            => ['Messer Reststunden', 2, ' h', 1, 85],
        'ReinigungsbuersteRest' => ['Reinigungsbürste Reststunden', 2, ' h', 1, 86],
        'WartungRest'           => ['Wartung des Roboters in', 2, ' h', 1, 87],
        'Regensensor'        => ['Regen erkannt', 0, '', 0, 65],
        'Zustand'            => ['Zustand', 1, '', 0, 10],
        'ZustandRoh'         => ['Zustand (Hersteller)', 3, '', 0, 11],
        'Fehler'             => ['Fehler', 3, '', 0, 12],
        'Meldung'            => ['Letzte Meldung', 3, '', 0, 13],
        'Akku'               => ['Akku', 1, ' %', 0, 20],
        'Laedt'              => ['Lädt', 0, '', 0, 21],
        'Reinigungsmodus'    => ['Reinigungsmodus', 3, '', 0, 30],
        'Saugstufe'          => ['Saugstufe', 3, '', 0, 31],
        'Wassermenge'        => ['Wassermenge', 3, '', 0, 32],
        'Flaeche'            => ['Gereinigte Fläche', 1, ' m²', 0, 40],
        'Dauer'              => ['Reinigungsdauer', 1, ' min', 0, 41],
        'Hauptbuerste'       => ['Hauptbürste', 1, ' %', 0, 50],
        'Seitenbuerste'      => ['Seitenbürste', 1, ' %', 0, 51],
        'Filter'             => ['Filter', 1, ' %', 0, 52],
        'Mopp'               => ['Mopp/Walze', 1, ' %', 0, 53],
        'Sensoren'           => ['Sensoren', 1, ' %', 0, 54],
        'Abwasserfilter'     => ['Abwasserauslassfilter', 1, ' %', 0, 58],
        'Schmutzwasserbehaelter' => ['Schmutzwasserbehälter (Roboter)', 1, ' %', 0, 59],
        'Station'            => ['Station', 3, '', 0, 60],
        'Frischwasser'       => ['Frischwasser', 3, '', 0, 61],
        'Schmutzwasser'      => ['Schmutzwasser', 3, '', 0, 62],
        'Staubbeutel'        => ['Staubbeutel', 3, '', 0, 63],
        'Reinigungsmittel'   => ['Reinigungsmittel', 3, '', 0, 64],
        'GesamtFlaeche'      => ['Gereinigte Fläche gesamt', 1, ' m²', 0, 70],
        'GesamtDauer'        => ['Reinigungsdauer gesamt', 1, ' h', 0, 71],
        'GesamtAnzahl'       => ['Reinigungen gesamt', 1, '', 0, 72],
        // Einstellungen, die der Treiber unter 'schreibbar' als änderbar meldet.
        'NichtStoeren'       => ['Nicht stören', 0, '', 0, 90],
        'NichtStoerenVon'    => ['Nicht stören ab', 3, '', 0, 91],
        'NichtStoerenBis'    => ['Nicht stören bis', 3, '', 0, 92],
        'Lautstaerke'        => ['Lautstärke', 1, ' %', 0, 93],
    ];

    /** Zeitangabe „HH:MM" (00:00–23:59) für Nicht-stören-Zeiten. */
    public static function istUhrzeit(string $t): bool
    {
        return (bool)preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $t);
    }
}

/**
 * Schnittstelle jedes Hersteller-Treibers.
 *
 * Alle Methoden werfen bei Fehlschlag RoboHubFehler (mit Klartext für den
 * Nutzer). Ein Token-Bündel ist ein beliebiges Array, das der Treiber
 * selbst versteht — die Konto-Instanz speichert es nur (Attribut, nie
 * Property).
 */
interface RoboHubTreiber
{
    /** Erstanmeldung mit Passwort. Rückgabe: Token-Bündel (ohne Passwort!). */
    public function anmelden(string $konto, string $passwort, string $region): array;

    /** Erneuert das Bündel, falls nötig. Rückgabe: (ggf. neues) Bündel. */
    public function erneuern(array $buendel): array;

    /** Liste der Geräte: [['id' => .., 'name' => .., 'modell' => .., 'klasse' => sauger|maeher, 'online' => bool, 'roh' => [...]], ...] */
    public function geraete(array $buendel): array;

    /** Aktueller Zustand eines Geräts, normalisiert nach RoboHubModell::FELDER plus 'roh'. */
    public function zustand(array $buendel, array $geraet): array;

    /**
     * Räume bzw. Zonen und Kartenbild des Geräts:
     * ['raeume' => [ID => Name], 'bild' => data-URI ('' = keins),
     *  'marken' => [['name', 'x' %, 'y' %], …], 'station' => ['x' %, 'y' %] | null].
     * Leere Liste/leeres Bild, wenn (noch) keine Karte.
     */
    public function karte(array $buendel, array $geraet): array;

    /**
     * Aktuelle Karte während der Arbeit: wie karte(), dazu 'weg' (Stücke in %)
     * und 'roboter' (['x' %, 'y' %, 'winkel']) sowie 'geometrie' für spätere
     * Echtzeit-Positionen.
     */
    public function liveKarte(array $buendel, array $geraet): array;

    /** Normalisiert Rohwerte ohne Netz (für Echtzeit-Meldungen). */
    public function normalisiere(string $modell, array $p): array;

    /** [Host, Port] des Echtzeit-Servers. */
    public function mqttZiel(array $geraete, array $buendel): array;

    /** Topic, auf dem die Cloud Änderungen dieses Geräts meldet. */
    public function mqttTopic(array $geraet, array $buendel): string;

    /** [Benutzer, Passwort] für die Echtzeit-Anmeldung. */
    public function mqttZugang(array $buendel): array;

    /**
     * Echtzeit-Nachricht → ['Geräte-ID' => ['siid.piid' => Wert]] (nur relevante Werte).
     * Eine Roboterposition steht unter dem Schlüssel '_position' als [x mm, y mm, Winkel].
     */
    public function pushLesen(string $payload): array;

    /** Setzt den Verschleißzähler eines Teils zurück (Ident wie in FELDER, z. B. „Filter"). */
    public function zuruecksetzen(array $buendel, array $geraet, string $teil): string;

    /** Führt einen Befehl aus; Rückgabe: Klartext („gesendet …"). */
    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter): string;
}

final class RoboHubFehler extends Exception
{
    /** Sekunden, die der Treiber laut Gegenstelle warten soll (0 = unbekannt). */
    public $warteSekunden = 0;
    /** true = Zugangsschlüssel ungültig, neue Anmeldung nötig. */
    public $anmeldungNoetig = false;
    /**
     * Zwischenstand einer zweistufigen Anmeldung (z. B. Ecovacs verlangt eine
     * Bestätigung per E-Mail-Code). Leer = keine zweite Stufe.
     */
    public $vorstufe = [];
}
