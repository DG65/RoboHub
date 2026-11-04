<?php

declare(strict_types=1);

require_once __DIR__ . '/RoboHubText.php';

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
        self::ZUSTAND_UNBEKANNT      => ['Unknown', 'question'],
        self::ZUSTAND_BEREIT         => ['Ready', 'robot'],
        self::ZUSTAND_REINIGT        => ['Cleaning', 'broom'],
        self::ZUSTAND_PAUSIERT       => ['Paused', 'pause'],
        self::ZUSTAND_ZURUECK        => ['Returning to base', 'house'],
        self::ZUSTAND_LAEDT          => ['Charging', 'battery-bolt'],
        self::ZUSTAND_GELADEN        => ['Docked', 'battery-full'],
        self::ZUSTAND_STATIONSARBEIT => ['Station busy', 'droplet'],
        self::ZUSTAND_FEHLER         => ['Error', 'triangle-exclamation'],
        self::ZUSTAND_SCHLAF         => ['Sleeping', 'moon'],
        self::ZUSTAND_KARTIERUNG     => ['Mapping', 'map'],
        self::ZUSTAND_AKTUALISIERUNG => ['Updating firmware', 'download'],
        self::ZUSTAND_WETTERPAUSE    => ['Weather pause', 'cloud-rain'],
    ];

    /** Beschriftung eines Zustands passend zur Geräteklasse. */
    public static function zustandsName(int $zustand, string $klasse): string
    {
        if ($klasse === self::KLASSE_MAEHER && $zustand === self::ZUSTAND_REINIGT) {
            return RoboHubText::t('Mowing');
        }
        return RoboHubText::wert(self::ZUSTAENDE[$zustand][0] ?? self::ZUSTAENDE[self::ZUSTAND_UNBEKANNT][0]);
    }

    // Befehle (Variable „Befehl", Aktionsvariable).
    public const BEFEHL_START   = 1;
    public const BEFEHL_PAUSE   = 2;
    public const BEFEHL_STOPP   = 3;
    public const BEFEHL_STATION = 4;
    public const BEFEHL_ORTEN   = 5;

    public const BEFEHLE = [
        self::BEFEHL_START   => ['Start / resume', 'play'],
        self::BEFEHL_PAUSE   => ['Pause', 'pause'],
        self::BEFEHL_STOPP   => ['Stop', 'stop'],
        self::BEFEHL_STATION => ['Return to base', 'house'],
        self::BEFEHL_ORTEN   => ['Locate', 'location-dot'],
    ];

    /**
     * Felder, die ein Treiber in zustand() liefern darf (alle optional —
     * angelegt wird eine Variable erst, wenn der Treiber einen Wert liefert,
     * so bekommt ein Mäher nie eine „Staubbeutel"-Variable).
     * ident => [Name, Variablentyp, Einheit/Suffix, Nachkommastellen, Position]
     */
    public const FELDER = [
        'Messer'             => ['Blades', 1, ' %', 0, 55],
        'Reinigungsbuerste'  => ['Cleaning brush', 1, ' %', 0, 56],
        'Wartung'            => ['Robot maintenance', 1, ' %', 0, 57],
        // Reststunden wie in den Hersteller-Apps. Sauger: vom Gerät geliefert
        // (ganze Stunden), Mäher: aus den Minutenzählern gerechnet (0,1 h).
        'HauptbuersteRest'      => ['Main brush hours left', 2, ' h', 0, 80],
        'SeitenbuersteRest'     => ['Side brush hours left', 2, ' h', 0, 81],
        'FilterRest'            => ['Filter hours left', 2, ' h', 0, 82],
        'MoppRest'              => ['Mop/roller hours left', 2, ' h', 0, 83],
        'SensorenRest'          => ['Sensors hours left', 2, ' h', 0, 84],
        'AbwasserfilterRest'    => ['Waste water outlet filter hours left', 2, ' h', 0, 88],
        'SchmutzwasserbehaelterRest' => ['Dirty water tank hours left', 2, ' h', 0, 89],
        'MesserRest'            => ['Blades hours left', 2, ' h', 1, 85],
        'ReinigungsbuersteRest' => ['Cleaning brush hours left', 2, ' h', 1, 86],
        'WartungRest'           => ['Robot maintenance in', 2, ' h', 1, 87],
        'Regensensor'        => ['Rain detected', 0, '', 0, 65],
        'Zustand'            => ['State', 1, '', 0, 10],
        'ZustandRoh'         => ['State (vendor)', 3, '', 0, 11],
        'Fehler'             => ['Error', 3, '', 0, 12],
        'Meldung'            => ['Last message', 3, '', 0, 13],
        'Akku'               => ['Battery', 1, ' %', 0, 20],
        'Laedt'              => ['Charging', 0, '', 0, 21],
        'AkkuTemperatur'     => ['Battery temperature', 2, ' °C', 1, 22],
        'AkkuSpannung'       => ['Battery voltage', 2, ' V', 2, 23],
        'Ladezyklen'         => ['Charge cycles', 1, '', 0, 24],
        'WlanSignal'         => ['WLAN-Signal', 1, ' dBm', 0, 25],
        'Reinigungsmodus'    => ['Cleaning mode', 3, '', 0, 30],
        'Saugstufe'          => ['Suction level', 3, '', 0, 31],
        'Wassermenge'        => ['Water level', 3, '', 0, 32],
        'Durchgaenge'        => ['Cleaning passes', 3, '', 0, 33],
        'Kantenreinigung'    => ['Edge cleaning', 3, '', 0, 34],
        'BehaelterVoll'      => ['When the bin is full', 3, '', 0, 35],
        'Schnitthoehe'       => ['Cutting height', 3, '', 0, 36],
        'Scheinwerfer'       => ['Headlights', 3, '', 0, 37],
        'Maehzeiten'         => ['Mowing times (schedule)', 3, '', 0, 38],
        'Regenverzoegerung'  => ['Rain delay', 3, '', 0, 39],
        'Zeitzuschlag'       => ['Zeitzuschlag', 3, '', 0, 46],
        'Flaeche'            => ['Cleaned area', 1, ' m²', 0, 40],
        'Dauer'              => ['Cleaning time', 1, ' min', 0, 41],
        'Fortschritt'        => ['Progress', 1, ' %', 0, 42],
        'Hauptbuerste'       => ['Main brush', 1, ' %', 0, 50],
        'Seitenbuerste'      => ['Side brush', 1, ' %', 0, 51],
        'Filter'             => ['Filter', 1, ' %', 0, 52],
        'Mopp'               => ['Mopp/Walze', 1, ' %', 0, 53],
        'Sensoren'           => ['Sensors', 1, ' %', 0, 54],
        'Abwasserfilter'     => ['Abwasserauslassfilter', 1, ' %', 0, 58],
        'Schmutzwasserbehaelter' => ['Dirty water tank (robot)', 1, ' %', 0, 59],
        'Station'            => ['Station', 3, '', 0, 60],
        'Frischwasser'       => ['Clean water', 3, '', 0, 61],
        'Schmutzwasser'      => ['Dirty water', 3, '', 0, 62],
        'Staubbeutel'        => ['Dust bag', 3, '', 0, 63],
        'Reinigungsmittel'   => ['Detergent', 3, '', 0, 64],
        'GesamtFlaeche'      => ['Cleaned area total', 1, ' m²', 0, 70],
        'GesamtDauer'        => ['Cleaning time total', 1, ' h', 0, 71],
        'GesamtAnzahl'       => ['Cleanings total', 1, '', 0, 72],
        'GesamtStrecke'      => ['Distance total', 2, ' km', 1, 73],
        'Messerlaufzeit'     => ['Blade run time total', 2, ' h', 1, 74],
        'Firmware'           => ['Firmware', 3, '', 0, 79],
        // Einstellungen, die der Treiber unter 'schreibbar' als änderbar meldet.
        'NichtStoeren'       => ['Do not disturb', 0, '', 0, 90],
        'NichtStoerenVon'    => ['Do not disturb from', 3, '', 0, 91],
        'NichtStoerenBis'    => ['Do not disturb until', 3, '', 0, 92],
        'Lautstaerke'        => ['Volume', 1, ' %', 0, 93],
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
