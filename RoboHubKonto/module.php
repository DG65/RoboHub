<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/RoboHubModell.php';
require_once __DIR__ . '/../libs/RoboHubFormular.php';
require_once __DIR__ . '/../libs/RoboHubDreame.php';

// ===========================================================================
// RoboHub Konto — eine Instanz je Hersteller-Konto (z. B. Dreamehome- oder
// MOVAhome-Konto).
//
// Aufgaben:
//   • einmalige Anmeldung mit Passwort; danach wird das Passwort verworfen,
//     gespeichert bleibt nur der Zugangsschlüssel (Attribut, nie Property —
//     Credential-Regel des Verbunds),
//   • Zugangsschlüssel rechtzeitig erneuern,
//   • Anfragen der Roboter-Instanzen und des Konfigurators an den
//     Hersteller-Treiber weiterreichen (ForwardData), mit gemeinsamer
//     Sperrfrist, falls die Cloud bremst.
//
// Die Roboter-Instanzen sprechen nie selbst mit der Cloud — so gibt es genau
// EINEN Schlüssel je Konto, und eine Erneuerung kann nicht doppelt laufen.
// ===========================================================================

class RoboHubKonto extends IPSModule
{
    use RoboHubFormular;

    private const DATA_ZUM_KONTO = '{CFA82D8B-B9F1-4CAF-BFB7-E898E3690581}';
    private const DATA_ZUM_KIND  = '{4949DAA0-C934-4184-91E2-2D3E90D4DC24}';

    // Prüftakt für die Schlüssel-Erneuerung.
    private const ERNEUERN_MS = 15 * 60 * 1000;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Hersteller', 'dreame');
        $this->RegisterPropertyString('Region', 'eu');
        $this->RegisterPropertyString('Konto', '');
        // Nur Eingabe für die Erstanmeldung, wird nach Erfolg sofort geleert.
        $this->RegisterPropertyString('Passwort', '');

        $this->RegisterAttributeString('Buendel', '');
        $this->RegisterAttributeString('Geraete', '[]');
        $this->RegisterAttributeInteger('GeraeteStand', 0);
        $this->RegisterAttributeInteger('WarteBis', 0);
        $this->RegisterAttributeString('LetzterFehler', '');
        $this->registerFormAttributes();

        $this->RegisterTimer('Erneuern', 0, 'ROBOHUB_Erneuern($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            return;
        }
        $this->aktualisiereStatus();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && ($Data[0] ?? null) === KR_READY) {
            $this->ApplyChanges();
        }
    }

    // -----------------------------------------------------------------
    // Öffentliche Aktionen
    // -----------------------------------------------------------------

    /**
     * ROBOHUB_Anmelden($id): string — Erstanmeldung mit dem eingetragenen
     * Passwort. Nach Erfolg wird das Passwort verworfen.
     */
    public function Anmelden(): string
    {
        $konto = trim((string)$this->ReadPropertyString('Konto'));
        $pass  = (string)$this->ReadPropertyString('Passwort');
        if ($konto === '' || $pass === '') {
            return $this->melde('⛔ Bitte zuerst Konto (E-Mail) und Passwort eintragen, „Übernehmen" klicken und dann anmelden.');
        }
        try {
            $buendel = $this->treiber()->anmelden($konto, $pass, (string)$this->ReadPropertyString('Region'));
        } catch (Throwable $e) {
            $this->WriteAttributeString('LetzterFehler', $e->getMessage());
            $this->protokolliere('Anmeldung fehlgeschlagen: ' . $e->getMessage());
            return $this->melde('⛔ Anmeldung fehlgeschlagen: ' . $e->getMessage());
        }
        $this->WriteAttributeString('Buendel', (string)json_encode($buendel));
        $this->WriteAttributeString('LetzterFehler', '');
        $this->WriteAttributeInteger('WarteBis', 0);

        $text = '✅ Angemeldet. Der Zugangsschlüssel ist gespeichert, das Passwort wurde verworfen.';
        try {
            $geraete = $this->ladeGeraete($buendel);
            $text .= "\n" . $this->geraeteZusammenfassung($geraete);
        } catch (Throwable $e) {
            $text .= "\n⚠️ Die Geräteliste ließ sich noch nicht laden (" . $e->getMessage() . '). Der Konfigurator versucht es erneut.';
        }

        // Passwort sofort unwirksam machen (Sicherheitsfall aus SUITE.md
        // 9l: SetProperty+ApplyChanges ist hier nötig und steht am ENDE,
        // nach allen UpdateFormField-Aufrufen).
        $this->UpdateFormField('Ergebnis', 'caption', $text);
        IPS_SetProperty($this->InstanceID, 'Passwort', '');
        IPS_ApplyChanges($this->InstanceID);
        return $text;
    }

    /** ROBOHUB_Abmelden($id): string — verwirft den Zugangsschlüssel. */
    public function Abmelden(): string
    {
        $this->WriteAttributeString('Buendel', '');
        $this->WriteAttributeString('Geraete', '[]');
        $this->WriteAttributeInteger('GeraeteStand', 0);
        $this->aktualisiereStatus();
        return $this->melde('⏏️ Abgemeldet — der Zugangsschlüssel wurde gelöscht. Die Roboter-Instanzen bleiben erhalten und laufen nach einer neuen Anmeldung weiter.');
    }

    /** ROBOHUB_GeraeteSuchen($id): string — Geräteliste neu von der Cloud holen. */
    public function GeraeteSuchen(): string
    {
        try {
            $geraete = $this->ladeGeraete($this->gueltigesBuendel());
        } catch (Throwable $e) {
            return $this->melde('⛔ ' . $e->getMessage());
        }
        return $this->melde($this->geraeteZusammenfassung($geraete));
    }

    /** Timer: Zugangsschlüssel bei Bedarf erneuern. */
    public function Erneuern(): void
    {
        if ($this->ReadAttributeString('Buendel') === '') {
            return;
        }
        try {
            $this->gueltigesBuendel();
        } catch (Throwable $e) {
            // Fehler wurde in gueltigesBuendel() bereits protokolliert/Status gesetzt.
        }
    }

    // -----------------------------------------------------------------
    // Vermittlung zu den Kind-Instanzen
    // -----------------------------------------------------------------

    /**
     * Anfrage einer Roboter-Instanz oder des Konfigurators.
     * Eingang:  {"DataID": DATA_ZUM_KONTO, "Aktion": "geraete"|"zustand"|"befehl", "Geraet": "<id>", "Befehl": "...", "Parameter": {...}}
     * Antwort:  {"ok": true, "daten": ...} bzw. {"ok": false, "fehler": "...", "warteBis": <ts>}
     */
    public function ForwardData($JSONString)
    {
        $anfrage = json_decode((string)$JSONString, true);
        if (!is_array($anfrage)) {
            return (string)json_encode(['ok' => false, 'fehler' => 'Ungültige Anfrage.']);
        }
        try {
            $daten = $this->bearbeite($anfrage);
            return (string)json_encode(['ok' => true, 'daten' => $daten]);
        } catch (Throwable $e) {
            return (string)json_encode([
                'ok'       => false,
                'fehler'   => $e->getMessage(),
                'warteBis' => $this->ReadAttributeInteger('WarteBis'),
                'anmeldungNoetig' => ($e instanceof RoboHubFehler) && $e->anmeldungNoetig,
            ]);
        }
    }

    private function bearbeite(array $anfrage)
    {
        $aktion = (string)($anfrage['Aktion'] ?? '');
        if ($aktion === 'geraete') {
            // Konfigurator: zwischengespeicherte Liste reicht, außer sie ist leer/alt.
            $stand = $this->ReadAttributeInteger('GeraeteStand');
            if (!empty($anfrage['Neu']) || $stand === 0 || time() - $stand > 3600) {
                return $this->ladeGeraete($this->gueltigesBuendel());
            }
            return json_decode($this->ReadAttributeString('Geraete'), true) ?: [];
        }
        if ($aktion === 'info') {
            return [
                'hersteller' => $this->ReadPropertyString('Hersteller'),
                'angemeldet' => $this->ReadAttributeString('Buendel') !== '',
            ];
        }

        $geraet = $this->findeGeraet((string)($anfrage['Geraet'] ?? ''));
        $buendel = $this->gueltigesBuendel();
        try {
            if ($aktion === 'zustand') {
                return $this->treiber()->zustand($buendel, $geraet);
            }
            if ($aktion === 'raeume') {
                return $this->treiber()->raeume($buendel, $geraet);
            }
            if ($aktion === 'befehl') {
                return $this->treiber()->befehl($buendel, $geraet, (string)($anfrage['Befehl'] ?? ''), (array)($anfrage['Parameter'] ?? []));
            }
        } catch (RoboHubFehler $e) {
            $this->verarbeiteFehler($e);
            throw $e;
        }
        throw new RoboHubFehler('Unbekannte Aktion: ' . $aktion);
    }

    // -----------------------------------------------------------------
    // Intern
    // -----------------------------------------------------------------

    /** Hersteller-Treiber; im Prüfstand über $GLOBALS austauschbar. */
    protected function treiber(): RoboHubTreiber
    {
        if (isset($GLOBALS['robohubTreiberFabrik']) && is_callable($GLOBALS['robohubTreiberFabrik'])) {
            return ($GLOBALS['robohubTreiberFabrik'])($this->ReadPropertyString('Hersteller'));
        }
        $treiber = new RoboHubDreame((string)$this->ReadPropertyString('Hersteller'));
        $treiber->debug = function (string $thema, string $text): void {
            $this->SendDebug($thema, $text, 0);
        };
        return $treiber;
    }

    /** Liefert ein gültiges (ggf. frisch erneuertes) Bündel oder wirft. */
    private function gueltigesBuendel(): array
    {
        $roh = $this->ReadAttributeString('Buendel');
        if ($roh === '') {
            throw new RoboHubFehler('Nicht angemeldet — bitte in der Konto-Instanz anmelden.');
        }
        $warte = $this->ReadAttributeInteger('WarteBis');
        if ($warte > time()) {
            throw new RoboHubFehler('Die Hersteller-Cloud bremst gerade — nächster Versuch ab ' . date('d.m.Y H:i', $warte) . ' Uhr.');
        }
        $buendel = json_decode($roh, true) ?: [];

        // Nur ein Prozess darf gleichzeitig erneuern (mehrere Roboter
        // fragen im selben Takt an).
        $sem = 'RoboHubKonto_' . $this->InstanceID;
        $gesperrt = IPS_SemaphoreEnter($sem, 10000);
        try {
            // Nach dem Warten neu lesen — ein anderer Prozess kann schon erneuert haben.
            $buendel = json_decode($this->ReadAttributeString('Buendel'), true) ?: $buendel;
            $neu = $this->treiber()->erneuern($buendel);
            if ($neu !== $buendel) {
                $this->WriteAttributeString('Buendel', (string)json_encode($neu));
            }
            if ($this->ReadAttributeString('LetzterFehler') !== '') {
                $this->WriteAttributeString('LetzterFehler', '');
                $this->aktualisiereStatus();
            }
            return $neu;
        } catch (RoboHubFehler $e) {
            $this->verarbeiteFehler($e);
            throw $e;
        } finally {
            if ($gesperrt) {
                IPS_SemaphoreLeave($sem);
            }
        }
    }

    private function verarbeiteFehler(RoboHubFehler $e): void
    {
        $this->WriteAttributeString('LetzterFehler', $e->getMessage());
        if ($e->warteSekunden > 0) {
            $this->WriteAttributeInteger('WarteBis', time() + $e->warteSekunden);
        }
        if ($e->anmeldungNoetig) {
            $this->WriteAttributeString('Buendel', '');
        }
        $this->protokolliere($e->getMessage());
        $this->aktualisiereStatus();
    }

    private function ladeGeraete(array $buendel): array
    {
        try {
            $geraete = $this->treiber()->geraete($buendel);
        } catch (RoboHubFehler $e) {
            $this->verarbeiteFehler($e);
            throw $e;
        }
        $this->WriteAttributeString('Geraete', (string)json_encode($geraete));
        $this->WriteAttributeInteger('GeraeteStand', time());
        $this->UpdateFormField('GeraeteZeile', 'caption', $this->geraeteZeile());
        return $geraete;
    }

    private function findeGeraet(string $id): array
    {
        foreach (json_decode($this->ReadAttributeString('Geraete'), true) ?: [] as $g) {
            if ((string)($g['id'] ?? '') === $id) {
                return $g;
            }
        }
        // Unbekannt (z. B. nach Modul-Neuinstallation ohne Attribute): einmal neu laden.
        foreach ($this->ladeGeraete($this->gueltigesBuendel()) as $g) {
            if ((string)($g['id'] ?? '') === $id) {
                return $g;
            }
        }
        throw new RoboHubFehler('Gerät ' . $id . ' ist in diesem Konto nicht (mehr) vorhanden.');
    }

    private function geraeteZusammenfassung(array $geraete): string
    {
        if (count($geraete) === 0) {
            return '⚠️ Im Konto wurde kein Roboter gefunden.';
        }
        $zeilen = ['✅ ' . count($geraete) . ' Gerät(e) im Konto:'];
        foreach ($geraete as $g) {
            $zeilen[] = '• ' . ($g['name'] ?? '?') . ' (' . ($g['modell'] ?? '?') . ', '
                . (($g['klasse'] ?? '') === RoboHubModell::KLASSE_MAEHER ? 'Mähroboter' : 'Saug-/Wischroboter') . ', '
                . (!empty($g['online']) ? 'online' : 'offline') . ')';
        }
        $zeilen[] = 'Anlegen über den RoboHub-Konfigurator.';
        return implode("\n", $zeilen);
    }

    private function geraeteZeile(): string
    {
        $stand = $this->ReadAttributeInteger('GeraeteStand');
        if ($stand === 0) {
            return 'ℹ️ Noch keine Geräteliste geladen.';
        }
        $n = count(json_decode($this->ReadAttributeString('Geraete'), true) ?: []);
        return ($n > 0 ? '✅ ' : '⚠️ ') . $n . ' Gerät(e) gefunden (zuletzt ' . date('d.m.Y H:i:s', $stand) . ' Uhr).';
    }

    private function aktualisiereStatus(): void
    {
        $angemeldet = $this->ReadAttributeString('Buendel') !== '';
        $this->SetTimerInterval('Erneuern', $angemeldet ? self::ERNEUERN_MS : 0);
        if (!$angemeldet) {
            $this->SetStatus($this->ReadAttributeString('LetzterFehler') !== '' ? 201 : 104);
            return;
        }
        if ($this->ReadAttributeInteger('WarteBis') > time()) {
            $this->SetStatus(202);
            return;
        }
        $this->SetStatus(102);
    }

    private function melde(string $text): string
    {
        $this->UpdateFormField('Ergebnis', 'caption', $text);
        return $text;
    }

    private function protokolliere(string $text): void
    {
        IPS_LogMessage('RoboHub Konto #' . $this->InstanceID, $text);
    }

    // -----------------------------------------------------------------
    // Formular
    // -----------------------------------------------------------------

    protected function robohubModulePrefix(): string
    {
        return 'ROBOHUB';
    }

    protected function robohubPurposeLines(): array
    {
        return [
            'Diese Instanz verbindet Symcon mit deinem Hersteller-Konto für Saug-, Wisch- und Mähroboter (heute Dreame und MOVA). Du meldest dich einmal an — danach holt RoboHub Zustand, Akku und Verbrauchsteile deiner Roboter und kann sie starten, anhalten oder zur Station schicken.',
            'Der Nutzen: Roboter werden Teil deiner Automationen, z. B. „saugen, wenn niemand zu Hause ist" oder „Mähen bei Regen abbrechen" — ohne Hersteller-App. Die einzelnen Roboter legst du danach mit dem RoboHub-Konfigurator an.',
        ];
    }

    protected function robohubDocLines(): array
    {
        return [
            'Anmelden: Hersteller und Region wählen, E-Mail und Passwort deines Hersteller-Kontos eintragen, „Übernehmen", dann „🔑 Jetzt anmelden". Das Passwort wird nach erfolgreicher Anmeldung sofort verworfen; gespeichert bleibt nur der Zugangsschlüssel.',
            'Sicherheit: Symcon verschlüsselt gespeicherte Werte nicht. Der Zugangsschlüssel steht nicht im Formular und nicht im Protokoll, liegt aber im Klartext in der Symcon-Datenbank — wie bei jedem Cloud-Modul.',
            'Mehrere Konten (z. B. ein Dreamehome-Konto für den Saugroboter und ein MOVAhome-Konto für den Mäher): je Konto eine eigene Konto-Instanz anlegen.',
            'Bremst die Hersteller-Cloud (zu viele Anfragen), wartet RoboHub die angegebene Zeit automatisch ab (Status „Cloud bremst") und macht danach von selbst weiter.',
            'Läuft der Zugangsschlüssel endgültig ab, zeigt die Instanz „Anmeldung erforderlich" — dann einfach erneut Passwort eintragen und anmelden.',
        ];
    }

    private function kontoPanel(): array
    {
        $angemeldet = $this->ReadAttributeString('Buendel') !== '';
        $fehler = $this->ReadAttributeString('LetzterFehler');
        if ($angemeldet) {
            $zeile = '✅ Angemeldet' . ($this->ReadPropertyString('Konto') !== '' ? ' als ' . $this->ReadPropertyString('Konto') : '') . '.';
        } elseif ($fehler !== '') {
            $zeile = '⛔ Nicht angemeldet — letzter Fehler: ' . $fehler;
        } else {
            $zeile = 'ℹ️ Noch nicht angemeldet.';
        }
        $warte = $this->ReadAttributeInteger('WarteBis');
        if ($warte > time()) {
            $zeile .= "\n⏳ Die Cloud bremst — nächster Versuch ab " . date('d.m.Y H:i', $warte) . ' Uhr.';
        }

        return [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => '🔑  Konto',
            'items' => [
                ['type' => 'Select', 'name' => 'Hersteller', 'caption' => 'Hersteller / App', 'options' => [
                    ['caption' => 'Dreame (App „Dreamehome")', 'value' => 'dreame'],
                    ['caption' => 'MOVA (App „MOVAhome")', 'value' => 'mova'],
                ]],
                ['type' => 'Select', 'name' => 'Region', 'caption' => 'Region des Kontos', 'options' => RoboHubDreame::regionen()],
                ['type' => 'ValidationTextBox', 'name' => 'Konto', 'caption' => 'E-Mail des Kontos'],
                ['type' => 'PasswordTextBox', 'name' => 'Passwort', 'caption' => 'Passwort (nur für die Anmeldung, wird danach verworfen)'],
                ['type' => 'Label', 'name' => 'Ergebnis', 'caption' => $zeile],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '🔑 Jetzt anmelden', 'onClick' => 'echo ROBOHUB_Anmelden($id);'],
                    ['type' => 'Button', 'caption' => '⏏️ Abmelden', 'onClick' => 'echo ROBOHUB_Abmelden($id);'],
                ]],
                ['type' => 'PopupButton', 'caption' => 'Welche Region hat mein Konto?', 'width' => '460px', 'popup' => [
                    'caption' => 'Welche Region hat mein Konto?',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Die Region richtet sich nach dem Land, das du bei der Registrierung in der App gewählt hast — für Deutschland, Österreich und die Schweiz ist das „Europa".'],
                        ['type' => 'Label', 'caption' => 'Schlägt die Anmeldung mit „Konto nicht gefunden" fehl, obwohl E-Mail und Passwort stimmen, liegt das Konto meist in einer anderen Region.'],
                    ],
                ]],
            ],
        ];
    }

    private function geraetePanel(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => '🤖  Geräte im Konto',
            'items' => [
                ['type' => 'Button', 'caption' => '🔎 Geräte jetzt suchen', 'onClick' => 'echo ROBOHUB_GeraeteSuchen($id);'],
                ['type' => 'Label', 'name' => 'GeraeteZeile', 'caption' => $this->geraeteZeile()],
                ['type' => 'Label', 'caption' => 'Angelegt werden die Roboter über den „RoboHub Konfigurator" (Instanz hinzufügen → RoboHub Konfigurator, mit dieser Konto-Instanz verbinden).'],
            ],
        ];
    }

    public function GetConfigurationForm()
    {
        $status = [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Angemeldet'],
            ['code' => 104, 'icon' => 'inactive', 'caption' => 'Noch nicht angemeldet'],
            ['code' => 201, 'icon' => 'error', 'caption' => 'Anmeldung erforderlich (Zugangsschlüssel abgelaufen oder abgelehnt)'],
            ['code' => 202, 'icon' => 'inactive', 'caption' => 'Cloud bremst — RoboHub wartet die Sperrfrist ab'],
        ];
        return $this->robohubBuildForm([$this->kontoPanel(), $this->geraetePanel()], [], $status);
    }
}
