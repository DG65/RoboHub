<?php

declare(strict_types=1);

// ===========================================================================
// Gemeinsame Formular-Bausteine aller RoboHub-Module (Konto, Konfigurator,
// Roboter) nach der DG65-Formular-Konvention: „Wozu dieses Modul?" →
// „Neu bis Version" → „Dokumentation & Hilfe" → Fachpanels →
// Rückmeldungs-Hinweis → „Über dieses Modul".
//
// Bewusst als Trait mit Modul-Präfix im Namen: Klassen- und Trait-Namen sind
// in Symcon prozessweit global, ein generischer Name würde mit fremden
// Modulen kollidieren.
//
// Jedes Modul liefert über robohubModulePrefix() seinen eigenen Präfix, damit
// die Ack-Knöpfe die richtige globale Funktion aufrufen (ROBOHUB_/ROBO_/
// ROBOCFG_) — gleiche Methodennamen in verschiedenen Präfixen sind erlaubt.
// ===========================================================================

trait RoboHubFormular
{
    // „Was ist neu": Schlüssel = Version ohne Beta-/Build-Suffix.
    private static $ROBOHUB_NEWS_VERSIONS = [
        '0.1.0' => [
            '• Erstes Release: Anbindung von Dreame- und MOVA-Saug-/Wischrobotern über die Hersteller-Cloud (Dreamehome-/MOVAhome-Konto).',
            '• Konto-Instanz meldet sich einmal an und verwirft danach das Passwort — gespeichert bleibt nur der Zugangsschlüssel.',
            '• Konfigurator findet alle Roboter im Konto und legt sie mit einem Klick an.',
            '• Roboter-Instanz: Status, Akku, Reinigungsfortschritt, Verbrauchsteile, Station sowie Start/Pause/Stopp/Zur Station/Orten, Saugstufe, Wassermenge und Raumreinigung.',
        ],
        '0.1.1' => [
            '• Mähroboter: Gerätecodes vollständig (Fehler, Wartung, Hinweise getrennt) — reine Hinweise wie „Schlechtwetter-Schutz aktiv" erscheinen in der neuen Variable „Letzte Meldung" statt als Fehler. MOVA LiDAX Ultra 1000/2000 mit ihren abweichenden Codes 0 und 55.',
            '• Mähroboter: Zustände nach den Beschriftungen der Hersteller-App (3 = Pausiert, 4 = Pausiert wegen Fehler); Start/Stopp/Pause mit Ausweichweg, falls der Mäher einen Befehlsweg ablehnt.',
        ],
        '0.1.2' => [
            '• Konfigurator: bereits angelegte Roboter erscheinen nicht mehr zusätzlich als rote Zeile „nicht mehr im Konto".',
        ],
        '0.12.5' => [
            '• Einträge im Symcon-Protokoll stehen jetzt unter dem Namen der Instanz, Fehler (z. B. fehlgeschlagene Befehle oder Anmeldung) als Warnung.',
        ],
        '0.12.4' => [
            '• Rückmeldungen gehen jetzt in den RoboHub-Thread im Symcon-Forum (Knopf im Panel „Rückmeldungen").',
        ],
        '0.12.3' => [
            '• Kurz nach einem Modul-Update meldete Symcon manchmal „keine aktive Konto-Instanz", obwohl das Konto lief — RoboHub prüft das Konto jetzt selbst.',
        ],
        '0.12.2' => [
            '• Live-Karte: Der fertige Weg bleibt nach der Arbeit stehen, auch wenn der Roboter ihn in der Station löscht.',
        ],
        '0.12.1' => [
            '• Die Variable „Einstellungen je Raum" wird in „Raum-Einstellungen im Roboter" umbenannt (nur, wenn sie noch den alten Namen trägt).',
        ],
        '0.12.0' => [
            '• Raum reinigen wie das „benutzerdefiniert"-Fenster der App: auf der Kachel nach der Raumwahl Modus, Saugstufe und Durchgänge für diesen Start wählen (oder ROBO_RaeumeReinigenMit).',
            '• Die im Roboter gespeicherten Raumwerte heißen jetzt „Raum-Einstellungen im Roboter" und sind nur noch Anzeige — die App nutzt sie nicht.',
        ],
        '0.11.5' => [
            '• Fehlersuche: „📋 Rohdaten" zeigen die Gerätedaten der Cloud (Suche nach den Raum-Einstellungen der App).',
        ],
        '0.11.4' => [
            '• Einstellungen je Raum: Ändert man sie in der Hersteller-App, meldet der Roboter das per Echtzeit — RoboHub liest die neuen Werte dann sofort.',
        ],
        '0.11.3' => [
            '• Fehlersuche: „📋 Rohdaten" zeigen, was beim Lesen des Kartenobjekts (Raum-Einstellungen) passiert.',
        ],
        '0.11.2' => [
            '• Einstellungen je Raum zeigen jetzt den Stand der Hersteller-App (aus dem Kartenobjekt der Cloud); die Live-Karte überschreibt ihn nicht mehr.',
        ],
        '0.11.1' => [
            '• Gespeicherte Karte wird jetzt in voller Fassung gelesen (statt der Vorschau). Nach dem Ändern einer Raum-Einstellung wird die Karte bei Bedarf ein zweites Mal gelesen.',
        ],
        '0.11.0' => [
            '• Saugroboter: Einstellungen je Raum ändern — Modus, Saugstufe und Durchgänge im Formular („🏠 Einstellungen je Raum") oder per ROBO_RaumEinstellungSetzen. Nur wenn der Roboter nicht reinigt.',
        ],
        '0.10.1' => [
            '• Einstellungen je Raum: Modus wie in der App (bei Geräten mit Mopp-Anhebung getauscht), in Reinigungsreihenfolge, Wassermenge nur bei Modi mit Wischen.',
        ],
        '0.10.0' => [
            '• Saugroboter: Einstellungen je Raum aus der Hersteller-App (Saugstufe, Durchgänge, Modus, Wasser/Feuchte) — als Variable und auf der Kachel unter den gewählten Räumen. Ändern folgt.',
        ],
        '0.9.5' => [
            '• Mähroboter: Mäh-Pläne ein- und ausschalten (Formular, Kachel, Skript) — nur wenn der Mäher ruht; der Mäher bestätigt jede Änderung, danach werden alle Pläne neu gelesen.',
        ],
        '0.9.4' => [
            '• Mäh-Pläne: leere Platzhalter ausgeblendet, „täglich"/„werktags" zusammengefasst, Pläne ohne Namen ab „Plan 1" gezählt.',
        ],
        '0.9.3' => [
            '• Mähroboter: Mäh-Pläne von MOVA-Mähern (z. B. LiDAX) werden jetzt gefunden — sie liegen in Plantabellen statt in einem Plan-Dokument.',
        ],
        '0.9.2' => [
            '• Mähroboter: Findet RoboHub keinen Mäh-Plan, stehen die Antworten des Mähers in „📋 Rohdaten" (Fehlersuche).',
        ],
        '0.9.1' => [
            '• Mähroboter: Mäh-Pläne aus der Hersteller-App (Name, Wochentage, Uhrzeit, ganze Fläche oder Zonen) als Variable, auf der Kachel und im Formular — vorerst nur Anzeige.',
        ],
        '0.9.0' => [
            '• Zeitpläne der Saugroboter aus der Hersteller-App: Uhrzeit, Wochentage, Räume bzw. alle Räume, Modus — als Variable, auf der Kachel und im Formular, dort und auf der Kachel ein- und ausschaltbar.',
        ],
        '0.8.1' => [
            '• Live-Karte: Räume farbig und mit Namen, auch wenn der Roboter im Live-Bild keine Raumliste mitschickt (Namen aus der gespeicherten Karte).',
        ],
        '0.8.0' => [
            '• Live-Karte: Während der Arbeit zeigt die Kachel den gefahrenen Weg und den Roboter (Punkt mit Blickrichtung) — Saugroboter alle 30 s, Mähroboter alle 60 s, dazwischen bewegt die Echtzeit-Verbindung den Roboter.',
            '• Nach der Arbeit bleibt der fertige Weg stehen. Abschaltbar unter „Kachel und IPSView" → „Live-Karte während der Arbeit".',
        ],
        '0.7.1' => [
            '• Kartenbild: feine weiße Trennlinien zwischen den Räumen, wie in der Dreamehome-App.',
        ],
        '0.7.0' => [
            '• Kartenbild auf der Kachel und der IPSView-Seite: Räume farbig mit Namen und Station (Saugroboter), Mähzonen und Sperrflächen (Mähroboter). Namen antippen wählt Räume/Zonen aus.',
            '• Abschaltbar unter „Kachel und IPSView" → „Kartenbild zeigen".',
        ],
        '0.6.0' => [
            '• Mähroboter: Mähzonen aus der gespeicherten Karte — Variable „Zone mähen" und Zonen-Knöpfe auf der Kachel, auch mehrere Zonen auf einmal.',
        ],
        '0.5.0' => [
            '• Nicht stören: an/aus sowie Beginn und Ende (HH:MM) direkt schaltbar — bei Saug- und Mährobotern.',
            '• Lautstärke als Schieberegler (0–100 %). Änderungen aus der Hersteller-App kommen per Echtzeit an.',
            '• Die Kachel zeigt 🌙 Nicht stören und 🔊 Lautstärke.',
        ],
        '0.4.4' => [
            '• Verbrauchsteile zurücksetzen: Nach Wechsel oder Reinigung den Zähler auf 100 % stellen — im Formular („🔧 Verbrauchsteile zurücksetzen", mit Sicherheitsabfrage) und auf der Kachel (↺, dann „Ja, zurücksetzen"). Jede Rücksetzung steht im Symcon-Protokoll.',
        ],
        '0.4.3' => [
            '• „📋 Rohdaten" zeigen jetzt auch die letzten 20 Echtzeit-Meldungen mit ihren Werten — hilfreich, wenn ein Wert anders ankommt als erwartet.',
        ],
        '0.4.2' => [
            '• Echtzeit: Die Verbindungsinstanz („RoboHub Echtzeit (…)", Client Socket) wird jetzt zuverlässig angelegt — auch bei Konten von vor 0.4.0.',
        ],
        '0.4.1' => [
            '• Echtzeit: Auch Konten, die vor 0.4.0 angelegt wurden, bekommen jetzt ihre Verbindungsinstanz (Client Socket).',
        ],
        '0.4.0' => [
            '• ⚡ Echtzeit-Meldungen: Änderungen am Roboter (Zustand, Akku, Fehler, Station) kommen sofort per verschlüsselter Verbindung von der Hersteller-Cloud — Panel „⚡ Echtzeit-Meldungen" in der Konto-Instanz. Dafür legt Symcon unter jedem Konto automatisch eine Client-Socket-Instanz an.',
        ],
        '0.3.5' => [
            '• Produktbild: sucht die Bildadresse jetzt im ganzen Gerätedatensatz (lag bei echten Konten nicht an der erwarteten Stelle).',
        ],
        '0.3.4' => [
            '• Kachel zeigt das Produktbild deines Roboters (wie in der Hersteller-App) mit farbigem Zustandsring; ohne Bild bleibt das Symbol.',
        ],
        '0.3.3' => [
            '• Kachel: Farbstufen der Verbrauchsteile wie in den Hersteller-Apps — rot nur bei 0–5 %, gelb („bald ersetzen") bis 35 %.',
        ],
        '0.3.2' => [
            '• Kachel: Inhalt beginnt bei allen Robotern auf gleicher Höhe direkt unter dem Namen, größeres Zustandssymbol, bis zu vier Verbrauchsteile immer offen, „Stand" am unteren Rand.',
        ],
        '0.3.1' => [
            '• Kachel überarbeitet: überlappt nicht mehr mit dem Instanznamen, nutzt breite Kacheln zweispaltig (links Zustand und Knöpfe, rechts Räume und Verbrauchsteile) und wächst mit der Kachelgröße mit.',
        ],
        '0.3.0' => [
            '• Jeder Roboter ist jetzt eine eigene Kachel: Zustand, Akku, Knöpfe, Räume zum Antippen und die fälligen Verbrauchsteile auf einen Blick.',
            '• Für IPSView: dieselbe Ansicht als eigenständige Seite (WebView-Element). Standardmäßig nur Anzeige; Bedienung erst nach Freigabe im Panel „📱 Kachel und IPSView" und nur mit geheimem Schlüssel in der Adresse.',
        ],
        '0.2.5' => [
            '• Saugroboter: zusätzlich Abwasserauslassfilter und Schmutzwasserbehälter des Roboters (% und Reststunden), sofern das Modell sie meldet.',
        ],
        '0.2.4' => [
            '• Reststunden der Verbrauchsteile wie in den Hersteller-Apps: Sauger (Hauptbürste, Seitenbürste, Filter, Sensoren, Mopp/Walze) und Mäher (Messer, Reinigungsbürste, Wartung) — z. B. für eine Erinnerung vor dem Wechsel.',
        ],
        '0.2.3' => [
            '• Mähroboter: zusätzlich „Reinigungsbürste" und „Wartung des Roboters" in % — wie in der Hersteller-App.',
        ],
        '0.2.2' => [
            '• Mähroboter: Zustand 19 wird als „Mäht" erkannt (bisher „Unbekannt") — am MOVA LiDAX Ultra 1000 live beobachtet.',
        ],
        '0.2.1' => [
            '• Schnellere Abfrage: Werte, die ein Roboter nachweislich nicht liefert (z. B. beim MOVA LiDAX die Auftragsdaten), fragt RoboHub nur noch stündlich nach statt bei jedem Abruf — der Abruf dauert dadurch Sekunden statt einer Viertelminute.',
        ],
        '0.2.0' => [
            '• Ein Konfigurator für alle Konten: Saugroboter (Dreamehome) und Mäher (MOVAhome) stehen in einer Liste, mit Spalte „Konto". Der Konfigurator braucht keine Verbindung mehr — vorhandene zweite Konfiguratoren kannst du löschen.',
            '• Roboter verbinden sich selbst mit dem Konto, in dem ihre Geräte-ID registriert ist (auch falsch eingehängte werden umgehängt). Das Roboter-Formular zeigt das verbundene Konto.',
        ],
        '0.1.3' => [
            '• Konfigurator: Bei mehreren Konten (z. B. Dreamehome und MOVAhome) zeigt jeder Konfigurator nur noch die Roboter seines eigenen Kontos.',
        ],
    ];

    private static $ROBOHUB_REPO_URL    = 'https://github.com/DG65/RoboHub';
    private static $ROBOHUB_ISSUES_URL  = 'https://github.com/DG65/RoboHub/issues';
    private static $ROBOHUB_FORUM_URL   = 'https://community.symcon.de/t/beta-modul-robohub-dreame-saugroboter-und-mova-maehroboter-in-ip-symcon-mit-karte-live-weg-zeitplaenen-und-raumreinigung/144636';
    private static $ROBOHUB_LICENSE_URL = 'https://github.com/DG65/RoboHub/blob/main/LICENSE';
    private static $ROBOHUB_PAYPAL_URL  = 'https://paypal.me/DietmarGureth';
    private static $ROBOHUB_LIBRARY_GUID = '{F4D2E22D-E5F0-444D-A2FF-359C105E74FB}';

    abstract protected function robohubModulePrefix(): string;

    /** Zweck-Text (2–3 Sätze) je Modul. */
    abstract protected function robohubPurposeLines(): array;

    /** Inhalt des Doku-Panels je Modul (ohne Versionszeile). */
    abstract protected function robohubDocLines(): array;

    protected function registerFormAttributes(): void
    {
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    public function AckNews(): void
    {
        // Gespeichert wird die tatsächlich INSTALLIERTE Version, nicht der
        // zuletzt gezeigte Eintrag (SUITE.md Formular-Optik Punkt 1).
        $this->WriteAttributeString('SeenNews', $this->robohubInstalledVersion());
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    protected static function robohubBaseVersion(string $version): string
    {
        return (string)preg_replace('/-.*$/', '', $version);
    }

    protected function robohubLibrary(): array
    {
        $lib = function_exists('IPS_GetLibrary') ? @IPS_GetLibrary(self::$ROBOHUB_LIBRARY_GUID) : false;
        return is_array($lib) ? $lib : [];
    }

    protected function robohubInstalledVersion(): string
    {
        $lib = $this->robohubLibrary();
        if (!empty($lib['Version'])) {
            return self::robohubBaseVersion((string)$lib['Version']);
        }
        $keys = array_keys(self::$ROBOHUB_NEWS_VERSIONS);
        usort($keys, 'version_compare');
        return (string)end($keys);
    }

    protected function formPurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        $items = [];
        foreach ($this->robohubPurposeLines() as $line) {
            $items[] = ['type' => 'Label', 'caption' => $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => $this->robohubModulePrefix() . '_AckPurposeIntro($id);'];
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  Wozu dieses Modul?',
            'items' => $items,
        ];
    }

    protected function formNewsBanner(): ?array
    {
        $seen = $this->ReadAttributeString('SeenNews');
        if ($seen === '') {
            $seen = '0';
        }
        $pending = [];
        foreach (self::$ROBOHUB_NEWS_VERSIONS as $version => $lines) {
            if (version_compare((string)$version, $seen, '>')) {
                $pending[(string)$version] = $lines;
            }
        }
        if (count($pending) === 0) {
            return null;
        }
        uksort($pending, 'version_compare');
        $items = [];
        foreach ($pending as $version => $lines) {
            $items[] = ['type' => 'Label', 'caption' => 'Version ' . $version . ':'];
            foreach ($lines as $line) {
                $items[] = ['type' => 'Label', 'caption' => $line];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => $this->robohubModulePrefix() . '_AckNews($id);'];
        $keys = array_keys($pending);
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => '🆕  Neu bis Version ' . end($keys),
            'items' => $items,
        ];
    }

    protected function formDocPanel(): array
    {
        $lib = $this->robohubLibrary();
        $ver = isset($lib['Version'])
            ? 'ℹ️ RoboHub Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ RoboHub';
        $items = [['type' => 'Label', 'caption' => $ver]];
        foreach ($this->robohubDocLines() as $line) {
            $items[] = ['type' => 'Label', 'caption' => $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Anleitung auf GitHub', 'onClick' => "echo '" . self::$ROBOHUB_REPO_URL . "';", 'link' => true];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '📖  Dokumentation & Hilfe',
            'items' => $items,
        ];
    }

    protected function formForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        // Forum-Thread seit 0.12.4; GitHub bleibt als zweiter Weg für Fehlerberichte.
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Rückmeldungen',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 RoboHub ist neu. Läuft dein Roboter? Fehlt ein Wert, oder hast du ein Modell, das noch nicht dabei ist? Schreib es gern in den RoboHub-Thread im Symcon-Forum — mit Modellname und, falls vorhanden, dem Inhalt von „Rohdaten".'],
                ['type' => 'Button', 'caption' => 'Zum Forum-Thread', 'onClick' => "echo '" . self::$ROBOHUB_FORUM_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Fehler auf GitHub melden', 'onClick' => "echo '" . self::$ROBOHUB_ISSUES_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => $this->robohubModulePrefix() . '_AckForumHint($id);'],
            ],
        ];
    }

    /** Wortlaut verbundweit identisch (SUITE.md Formular-Optik Punkt 5, Variante A). */
    protected function formLicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::$ROBOHUB_LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::$ROBOHUB_PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    /** Baut das komplette Formular aus Rahmen + Fachpanels. */
    protected function robohubBuildForm(array $fachpanels, array $actions, array $status): string
    {
        $elements = array_values(array_filter(array_merge(
            [$this->formPurposeIntro(), $this->formNewsBanner(), $this->formDocPanel()],
            $fachpanels,
            [$this->formForumHint(), $this->formLicenseHint()]
        )));
        return (string)json_encode(['elements' => $elements, 'actions' => $actions, 'status' => $status]);
    }
}
