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
//
// „🪟 Formular: Aufbau" (SUITE.md 3a) erscheint, wenn ein Modul mehr als zwei
// benannte Fachpanels liefert (Konto, Roboter); dann braucht es die Property
// `FormLayout` ('[]'). Der Konfigurator hat nur ein Fachpanel und kein Panel.
// ===========================================================================

trait RoboHubFormular
{
    // „Was ist neu": Schlüssel = Version ohne Beta-/Build-Suffix.
    private static $ROBOHUB_NEWS_VERSIONS = [
        // Ältere Versionen: CHANGELOG.md / CHANGELOG-Archiv.md (gekürzt für die englische Fassung, 0.35.0).
        '0.37.6' => [
            '• Tile: light or dark now follows the colours that Symcon passes to the tile — in the app and in the browser, independent of the device setting.',
            '• Tile: room names in the map can be switched on and off directly below the map; each device remembers its own choice.',
        ],
        '0.37.5' => [
            '• Tile: if the colour scheme of the visualization cannot be detected, the tile draws its own background — text stays readable in light and dark designs.',
            '• Tile: room names in the map image can be switched off (form: "Tile and IPSView"); room names are easier to tap and the station symbol no longer covers them.',
            '• Dreame/MOVA: a "Remove mop" notice left over from 0.37.3 disappears.',
        ],
        '0.37.4' => [
            '• Dreame/MOVA: rooms deactivated in the app now disappear from the map completely, including their edges.',
            '• Dreame/MOVA: the notice "Remove mop" (code 68) is no longer shown at all, like in the app.',
        ],
        '0.37.3' => [
            '• Dreame/MOVA: room type 10 is now called "Closet" like in the app (it was shown as "Storage room"), and rooms deactivated in the app disappear from the room list and the map.',
            '• Dreame/MOVA: the notice "Remove mop" (code 68) is no longer shown as an error — like in the app it appears under "Last message".',
        ],
        '0.37.2' => [
            '• Room names stay in your language: map and room buttons no longer switch to English after a few minutes.',
            '• Dreame/MOVA: your own room names from the app take precedence (e.g. "Wardrobe" instead of the room type), and deleted or deactivated rooms no longer appear.',
        ],
        '0.37.1' => [
            '• ROBO_GetActivity: an unreachable robot (e.g. switched off or stored for winter) now reports "unknown" instead of its last state, so other modules no longer rely on a days-old state.',
        ],
        '0.37.0' => [
            '• New for other modules: ROBO_GetActivity tells whether a robot is moving or outside its station (contract robot_activity 1.0) — e.g. so that FrigateHub raises no false alarm when the mower drives through the picture. Reads only Symcon variables, never the vendor cloud.',
        ],
        '0.36.1' => [
            '• Real-time connection reconnects by itself: if the connection drops and stays in an error state, RoboHub switches it off and on again (5 s, 10 s, 20 s … up to 5 min; Husqvarna/GARDENA up to 1 h because of the API quota). Before, real-time stayed off until the next restart.',
        ],
        '0.36.0' => [
            '• Forms are now translated by Symcon itself (as Symcon recommends) — fixed labels no longer go through RoboHub\'s own translation, so forms open faster.',
        ],
        '0.35.2' => [
            '• Room settings: values stored before the switch (e.g. "Vacuum · Standard") now also appear in the current language, right after the update.',
        ],
        '0.35.1' => [
            '• English: remaining German texts translated (device type, "Locate", sign-in line, sign out, error texts); mode and suction level of room settings now follow the language when shown.',
        ],
        '0.35.0' => [
            '• 🇬🇧 RoboHub now speaks English: forms, variables, states, messages and the tile follow the language of your Symcon. German stays as before.',
        ],
        '0.34.1' => [
            '• Real-time connection: RoboHub now sets the fixed settings of the connection instance (encryption, certificate) only on first setup and afterwards only changes target, port and on/off. Settings changed by hand are kept (note from the Symcon store review).',
        ],
        '0.34.0' => [
            '• New: Eufy E15 robot mower directly in your home network, without cloud (vendor "Eufy robot mower E15"). State, battery, cutting height, volume; start, pause, return to base. The Eufy account is only needed once for the device key, the password is discarded. E18 and C15 are not tested.',
            '• The vendor "Eufy" is now explicitly called "robot vacuums only"; the sign-in error names the server and the right way for mowers.',
        ],
        '0.33.0' => [
            '• 🪟 New: choose the form layout yourself — in the account and in the robot you decide under "🪟 Form: layout" which sections are expanded, collapsed or hidden when the form opens, and in which order they appear. Hidden sections keep working.',
        ],
        '0.32.0' => [
            '• New: Eufy RoboVac/Omni with Eufy\'s cloud connection (e.g. X8/X9/X10, L60, C10/C20, G50, Omni S1 Pro). State, battery, errors, wear, suction level; start, pause, stop, return to base, locate. Older Tuya-only models not yet.',
        ],
        '0.31.0' => [
            '• New: Kärcher Home Robots (RCV, RCF, RVC, RVM, RVF; app "Kärcher Home Robots"). State, battery, errors, wear, suction level, water level, cleaning mode; start, pause, stop, return to base, locate. The password is discarded after sign-in.',
        ],
        '0.30.0' => [
            '• New: Miele Scout via the official Miele API (create access at Miele). State with Miele\'s own text, errors F1–F8, battery, program (Auto/Turbo/Silent); start, pause, stop. Miele does not offer "return to base".',
        ],
        '0.29.0' => [
            '• New: Vorwerk Kobold VR200/VR300 — sign-in by e-mail code, no password. State, errors, notices, battery; start/resume, pause, stop, return to base, locate, clean saved zones on the VR300. The VR7 uses a different cloud and does not work yet.',
        ],
        '0.28.0' => [
            '• New: AL-KO Robolinho via the official AL-KO API (apply for access at AL-KO). State with the reason for returning, rain/frost pause, errors, battery, Wi-Fi, run times, blades, weekly schedule; mow, stop, return to base.',
        ],
        '0.27.0' => [
            '• New: STIHL iMOW — new generation (iMOW 5/6/7, also EVO) and older RMI models in one account, RoboHub detects the generation itself. State, errors with STIHL\'s text, battery, statistics, zones; mow, pause, return to base.',
            '• Older iMOW: STIHL grants access for only 30 days — RoboHub does not store your password, reminds you three days before and then asks for a new sign-in.',
            '• Fixed: room/zone number 0 was discarded (also affected the main area on Husqvarna).',
        ],
        '0.26.0' => [
            '• Tile with Symcon icons instead of emojis — they look the same on every device, including a Raspberry Pi without an emoji font. If you prefer the colourful emojis: "Icons in the tile" in the robot form.',
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
        $items[] = ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => $this->robohubModulePrefix() . '_AckPurposeIntro($id);'];
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  What is this module for?',
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
            $items[] = ['type' => 'Label', 'caption' => RoboHubText::t('Version %s:', $version)];
            foreach ($lines as $line) {
                $items[] = ['type' => 'Label', 'caption' => $line];   // die Konsole übersetzt selbst (Store-Punkt 25)
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => $this->robohubModulePrefix() . '_AckNews($id);'];
        $keys = array_keys($pending);
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => RoboHubText::t('🆕  New up to version %s', end($keys)),
            'items' => $items,
        ];
    }

    protected function formDocPanel(): array
    {
        $lib = $this->robohubLibrary();
        $ver = isset($lib['Version'])
            ? RoboHubText::t('ℹ️ RoboHub version %s (build %s)', $lib['Version'], ($lib['Build'] ?? '?'))
            : RoboHubText::t('ℹ️ RoboHub');
        $items = [['type' => 'Label', 'caption' => $ver]];
        foreach ($this->robohubDocLines() as $line) {
            $items[] = ['type' => 'Label', 'caption' => $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Manual on GitHub', 'onClick' => "echo '" . self::$ROBOHUB_REPO_URL . "';", 'link' => true];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '📖  Documentation & help',
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
            'caption' => '💬  Feedback',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 RoboHub is new. Does your robot work? Is a value missing, or do you have a model that is not supported yet? Feel free to post in the RoboHub thread in the Symcon forum — with the model name and, if available, the content of "Raw data".'],
                ['type' => 'Button', 'caption' => 'Go to the forum thread', 'onClick' => "echo '" . self::$ROBOHUB_FORUM_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Report a bug on GitHub', 'onClick' => "echo '" . self::$ROBOHUB_ISSUES_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => $this->robohubModulePrefix() . '_AckForumHint($id);'],
            ],
        ];
    }

    /** Wortlaut verbundweit identisch (SUITE.md Formular-Optik Punkt 5, Variante A). */
    protected function formLicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  About this module',
            'items' => [
                ['type' => 'Label', 'caption' => 'Born out of real enthusiasm for my own setup — and quite a few evenings of typing. Still: hobby software or not, this is intellectual property and real work went into it.'],
                ['type' => 'Label', 'caption' => 'License: PolyForm Noncommercial 1.0.0 — free for private and non-commercial use; commercial use requires a separate license from the rights holder.'],
                ['type' => 'Button', 'caption' => 'View license text', 'onClick' => "echo '" . self::$ROBOHUB_LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'You like the module and would like to leave something anyway? I am happy about a small donation — completely voluntary, nothing required in return.'],
                ['type' => 'Button', 'caption' => '☕  Donate via PayPal', 'onClick' => "echo '" . self::$ROBOHUB_PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    /** Baut das komplette Formular aus Rahmen + Fachpanels. */
    /**
     * Fachpanels des Moduls, Schlüssel = stabiler Name (nicht die Caption). Module mit mehr als zwei
     * Fachpanels liefern sie hier, damit „Formular: Aufbau" und der Zurücksetzen-Knopf sie kennen
     * (SUITE.md 3a). Null-Einträge = Panel gibt es gerade nicht (z. B. keine Räume).
     */
    protected function robohubFachpanels(): array
    {
        return [];
    }

    protected function robohubBuildForm(array $fachpanels, array $actions, array $status): string
    {
        $fachpanels = array_filter($fachpanels);
        $aufbau = [];
        // Formular: Aufbau nur bei benannten Fachpanels und mehr als zwei davon (SUITE.md 3a).
        if (count($fachpanels) > 2 && count(array_filter(array_keys($fachpanels), 'is_string')) === count($fachpanels)) {
            $layout = $this->robohubFormLayout(array_keys($fachpanels));
            $mitte  = [];
            foreach ($layout as $key => $l) {
                $panel = $fachpanels[$key];
                if ($l['open'] === 'open') {
                    $panel['expanded'] = true;
                } elseif ($l['open'] === 'closed') {
                    $panel['expanded'] = false;
                } elseif ($l['open'] === 'hidden') {
                    // Nicht weglassen: die Felder bleiben im Formular, gespeicherte Werte bleiben beim „Übernehmen" erhalten
                    $panel['visible'] = false;
                }
                $mitte[] = $panel;
            }
            $fachpanels = $mitte;
            $aufbau = [$this->robohubLayoutPanel($layout, array_combine(array_keys($layout), $mitte))];
        }
        $elements = array_values(array_filter(array_merge(
            [$this->formPurposeIntro(), $this->formNewsBanner(), $this->formDocPanel()],
            array_values($fachpanels),
            $aufbau,
            [$this->formForumHint(), $this->formLicenseHint()]
        )));
        return (string)json_encode(['elements' => $elements, 'actions' => $actions, 'status' => $status]);
    }

    /**
     * Reihenfolge und Aufklapp-Zustand der Fachpanels aus der Einstellung „FormLayout".
     * Unbekannte oder fehlende Einträge fallen auf Standardreihenfolge und „Standard" zurück.
     *
     * @return array key => ['open' => 'std'|'open'|'closed'|'hidden', 'pos' => int, 'idx' => int], sortiert
     */
    protected function robohubFormLayout(array $keys): array
    {
        $saved = json_decode((string)$this->ReadPropertyString('FormLayout'), true);
        $by = [];
        foreach (is_array($saved) ? $saved : [] as $row) {
            if (is_array($row) && isset($row['Key'])) {
                $by[(string)$row['Key']] = $row;
            }
        }
        $out = [];
        foreach (array_values($keys) as $i => $k) {
            $r = $by[$k] ?? [];
            // Vorrang bei Widerspruch: ausgeblendet vor zugeklappt vor aufgeklappt, keines = Standard
            $open = !empty($r['Hidden']) ? 'hidden' : (!empty($r['Closed']) ? 'closed' : (!empty($r['Opened']) ? 'open' : 'std'));
            $out[$k] = [
                'open' => $open,
                'pos'  => isset($r['Pos']) ? max(1, min(99, (int)$r['Pos'])) : $i + 1,
                'idx'  => $i,
            ];
        }
        uasort($out, function ($a, $b) {
            return [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']];
        });
        return $out;
    }

    private static function robohubPanelName(array $panel, string $key): string
    {
        return trim((string)preg_replace('/\s*\(.*$/u', '', (string)($panel['caption'] ?? $key)));
    }

    /** Wählbarer Aufbau des Formulars: je Fachpanel Aufklapp-Zustand beim Öffnen und Position. */
    protected function robohubLayoutPanel(array $layout, array $panels): array
    {
        $rows   = [];
        $hidden = [];
        foreach ($layout as $key => $l) {
            // Listenzeilen und Sätze mit Werten übersetzt die Konsole nicht — hier selbst (Store-Punkt 25).
            $name = RoboHubText::t(self::robohubPanelName($panels[$key] ?? [], (string)$key));
            $rows[] = ['Key' => (string)$key, 'Panel' => $name, 'Opened' => $l['open'] === 'open', 'Closed' => $l['open'] === 'closed',
                'Hidden' => $l['open'] === 'hidden', 'Pos' => $l['pos']];
            if ($l['open'] === 'hidden') {
                $hidden[] = trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', $name));
            }
        }
        $hiddenLine = $hidden
            ? [['type' => 'Label', 'caption' => RoboHubText::t('🙈 Hidden: %s — to show again, clear the "Hidden" checkbox. Hidden sections keep working, only the display is missing.', implode(', ', $hidden))]]
            : [];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => RoboHubText::t('🪟  Form: layout%s', ($hidden ? RoboHubText::t(' (%s hidden)', count($hidden)) : '')),
            'items' => array_merge($hiddenLine, [
                ['type' => 'Label', 'caption' => 'Here you decide which sections are expanded when the form opens and in which order they appear. Symcon does not remember expanding and collapsing, so this setting applies every time the form opens. It takes effect after "Apply" the next time the form is opened.'],
                ['type' => 'Label', 'caption' => 'No checkbox means "default": as the module intends. The position is a number; with equal numbers the default order applies. "Hidden" removes a section from the display without it losing anything: its settings are kept and keep working. To show it again, clear the checkbox or click "Reset to default". If several checkboxes are set, "Hidden" wins over "Collapsed", which wins over "Expanded". The sections at the top (purpose, news, documentation) and at the bottom (feedback, about this module) stay where they are and cannot be hidden. Sections that currently have nothing to show (e.g. rooms without a map) only appear in the list once they exist.'],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => 'Sections', 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => 'Section', 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Key', 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => 'Expanded', 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Collapsed', 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => '🙈 Hidden', 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Position', 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => '↩️ Reset to default', 'onClick' => 'echo ' . $this->robohubModulePrefix() . '_ResetFormLayout($id);'],
            ]),
        ];
    }

    /** Setzt Aufklapp-Zustand und Reihenfolge der Fachpanels im offenen Formular auf den Standard (gilt nach „Übernehmen"). */
    public function ResetFormLayout(): string
    {
        $rows = [];
        $i = 0;
        foreach (array_filter($this->robohubFachpanels()) as $key => $panel) {
            $rows[] = ['Key' => (string)$key, 'Panel' => self::robohubPanelName($panel, (string)$key), 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => ++$i];
        }
        $this->UpdateFormField('FormLayout', 'values', (string)json_encode($rows, JSON_UNESCAPED_UNICODE));
        return RoboHubText::t('ℹ️ Reset to default. Click "Apply" below to save.');
    }
}
