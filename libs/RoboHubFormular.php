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
        // Noch kein Forum-Thread: Rückmeldungen gehen bis dahin an GitHub
        // (Präzedenzfall OCPPHub, siehe Memory feedback-suite-md-vollstaendig-lesen).
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Rückmeldungen',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 RoboHub ist neu. Läuft dein Roboter? Fehlt ein Wert, oder hast du ein Modell, das noch nicht dabei ist? Schreib es gern als GitHub-Issue — mit Modellname und, falls vorhanden, dem Debug-Auszug „Rohdaten".'],
                ['type' => 'Button', 'caption' => 'Rückmeldung auf GitHub', 'onClick' => "echo '" . self::$ROBOHUB_ISSUES_URL . "';", 'link' => true],
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
