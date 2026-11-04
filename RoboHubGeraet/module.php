<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/RoboHubModell.php';
require_once __DIR__ . '/../libs/RoboHubFormular.php';
require_once __DIR__ . '/../libs/RoboHubKartenbild.php';

// ===========================================================================
// RoboHub Roboter — eine Instanz je Saug-/Wisch- oder Mähroboter.
//
// Herstellerneutral: fragt den Zustand zyklisch über die übergeordnete
// Konto-Instanz ab (die den Hersteller-Treiber kennt) und pflegt daraus die
// Variablen nach RoboHubModell. Eine Variable entsteht erst, wenn der Treiber
// für dieses Gerät tatsächlich einen Wert liefert — ein Mäher bekommt so nie
// eine „Staubbeutel"-Variable, ein Sauger nie „Messer".
//
// Bedienung über Aktionsvariablen (WebFront, Kachel, Skript per
// IPS_RequestAction, Ereignisse): „Befehl", „Raum reinigen" und alle
// Einstellungen, für die der Treiber eine Auswahlliste liefert.
// ===========================================================================

class RoboHubGeraet extends IPSModule
{
    use RoboHubFormular;

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        // Treiber, Modell und Kachel übersetzen über RoboHubText (englische Quelltexte, Deutsch in locale.json).
        RoboHubText::setzen(function (string $text): string {
            return $this->Translate($text);
        });
    }

    private const DATA_ZUM_KONTO = '{CFA82D8B-B9F1-4CAF-BFB7-E898E3690581}';

    // Während einer Reinigung schneller nachsehen als im Ruhezustand.
    private const TAKT_AKTIV_S = 30;

    // Raumliste ändert sich selten (Karte), deshalb nur alle 6 h neu laden.
    private const RAEUME_ALTER_S = 6 * 3600;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('GeraetID', '');
        $this->RegisterPropertyString('FormLayout', '[]');   // „Formular: Aufbau" (SUITE.md 3a)
        $this->RegisterPropertyString('Modell', '');
        $this->RegisterPropertyString('Klasse', RoboHubModell::KLASSE_SAUGER);
        $this->RegisterPropertyInteger('Intervall', 120);
        // Eigenständige Seite (IPSView/Browser): Bedienen nur, wenn ausdrücklich
        // erlaubt — die Seite ist ohne Symcon-Anmeldung erreichbar.
        $this->RegisterPropertyBoolean('WebBedienung', false);
        $this->RegisterAttributeString('WebSchluessel', '');

        $this->RegisterAttributeString('Raeume', '{}');
        // Kartenbild für Kachel/Seite: {"bild": data-URI, "marken": [...], "station": {...}|null, "format": b/h}.
        $this->RegisterAttributeString('Karte', '{}');
        $this->RegisterPropertyBoolean('KarteZeigen', true);
        $this->RegisterPropertyInteger('Farbschema', 0);   // 0 automatisch, 1 hell, 2 dunkel
        $this->RegisterPropertyInteger('Symbole', 0);      // 0 Symcon-Symbole (icons.js), 1 Emojis (Forum Thilo 26.10.2026)
        $this->RegisterPropertyBoolean('EinstellungenZeigen', true);
        $this->RegisterPropertyBoolean('Deaktiviert', false);
        // Live-Karte: während der Arbeit aktuelle Karte mit Weg und Roboter holen.
        $this->RegisterPropertyBoolean('LiveKarte', true);
        $this->RegisterAttributeBoolean('LiveAktiv', false);
        $this->RegisterAttributeString('Bild', '');
        $this->RegisterAttributeInteger('LetzteEchtzeit', 0);
        $this->RegisterAttributeString('EchtzeitVerlauf', '[]');
        $this->RegisterAttributeInteger('RaeumeStand', 0);
        $this->RegisterAttributeString('Optionen', '{}');
        $this->RegisterAttributeBoolean('EinstellungenFreigegeben', false);
        $this->RegisterAttributeString('Zeitplaene', '[]');
        $this->RegisterAttributeString('ZeitplanRoh', '');
        $this->RegisterAttributeString('RaumEinstellungen', '{}');
        $this->RegisterAttributeString('RaumEinstellungenQuellen', '{}');
        $this->RegisterAttributeString('Schreibbar', '[]');
        $this->RegisterAttributeInteger('LetzterAbruf', 0);
        $this->RegisterAttributeString('LetzterFehler', '');
        $this->RegisterAttributeString('LetzteRohdaten', '');
        $this->registerFormAttributes();

        $this->RegisterTimer('Abruf', 0, 'ROBO_Aktualisieren($_IPS[\'TARGET\']);');
        $this->RegisterTimer('LiveKarte', 0, 'ROBO_LiveKarteLaden($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Zuordnen', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Zuordnen\', 0);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $klasse = $this->klasse();
        $this->MaintainVariable('Erreichbar', RoboHubText::t('Reachable'), VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 1, true);
        // Nur Anzeige → Wertanzeige mit Optionen. Die Aufzählung verlangt eine Variablenaktion; ohne sie
        // lässt sich die Variable in der Konsole nicht mehr bearbeiten, z. B. Archiv einschalten (Forum Thilo 25.10.2026).
        $this->MaintainVariable('Zustand', RoboHubText::t('State'), VARIABLETYPE_INTEGER, $this->presAnzeige($this->zustandsOptionen($klasse)), 10, true);
        $this->MaintainVariable('Befehl', RoboHubText::t('Command'), VARIABLETYPE_INTEGER, $this->presEnum($this->befehlsOptionen($klasse)), 5, true);
        $this->EnableAction('Befehl');
        $this->SetVisualizationType(1);
        if ($this->ReadAttributeString('WebSchluessel') === '') {
            $this->WriteAttributeString('WebSchluessel', bin2hex(random_bytes(12)));
        }

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            return;
        }
        if ($this->ReadPropertyString('GeraetID') === '') {
            $this->SetTimerInterval('Abruf', 0);
            $this->SetStatus(104);
            return;
        }
        // Einzelnen Roboter abschalten, ohne das Konto (und damit alle anderen) mitzunehmen —
        // Muster OCPPHub-Ladepunkt „Diesen Ladepunkt deaktivieren" (Forum Thilo 25.10.2026).
        if ($this->ReadPropertyBoolean('Deaktiviert')) {
            foreach (['Abruf', 'LiveKarte', 'Zuordnen'] as $takt) {
                $this->SetTimerInterval($takt, 0);
            }
            $this->SetStatus(104);
            return;
        }
        // Ohne Konto erst kurz warten: Beim Anlegen über den Konfigurator verbindet Symcon den
        // Roboter selbst NACH ApplyChanges. Wer vorher schon verbunden ist, lässt Symcon 9.1
        // scheitern („hat bereits ein übergeordnetes Objekt") und den Konfigurator alles
        // zurückrollen (Forum Thilo, 20.10.2026). Falsch verbundene korrigiert sofort.
        if ((int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0) === 0) {
            $this->SetTimerInterval('Zuordnen', 15000);
        } else {
            $this->SetTimerInterval('Zuordnen', 0);
            $this->ordneKontoZu();
        }
        $this->registriereHook();
        $this->einstellungenFreigeben();
        $this->SetStatus(102);
        $this->SetTimerInterval('Abruf', $this->taktSekunden() * 1000);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && ($Data[0] ?? null) === KR_READY) {
            $this->ApplyChanges();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Befehl':
                $this->fuehreAus($this->befehlsName((int)$Value), []);
                // Befehl ist ein Taster, kein Zustand: bleibt nicht stehen.
                return;
            case 'Zuordnen':
                $this->SetTimerInterval('Zuordnen', 0);
                $this->ordneKontoZu();
                return;
            case 'Raum':
                $this->fuehreAus('raum', ['raeume' => [(int)$Value]]);
                return;
            case 'Zuruecksetzen':
                $this->kachelAktualisieren($this->VerbrauchsteilZuruecksetzen((string)$Value));
                return;
            case 'Zeitplan':
                // Aus der Kachel: „ID:1" bzw. „ID:0".
                [$tid, $an] = array_pad(explode(':', (string)$Value, 2), 2, '0');
                $this->kachelAktualisieren($this->ZeitplanSchalten((int)$tid, $an === '1'));
                return;
            case 'Raeume':
                // Aus der Kachel: „3,5" oder mit Startwerten „3,5|0|1|2" (Modus|Saugstufe|Durchgänge, −1 = wie eingestellt).
                $this->fuehreAus('raum', self::raumStart((string)$Value));
                return;
            default:
                if (in_array($Ident, $this->schreibbar(), true)) {
                    $wert = $this->einstellungPruefen($Ident, $Value);
                    if ($wert !== null && $this->fuehreAus('einstellung', ['feld' => $Ident, 'wert' => $wert])) {
                        $this->SetValue($Ident, $wert);
                    }
                    return;
                }
                $optionen = json_decode((string)$this->ReadAttributeString('Optionen'), true) ?: [];
                if (isset($optionen[$Ident])) {
                    if ($this->fuehreAus('setze', ['feld' => $Ident, 'wert' => (int)$Value])) {
                        $this->SetValue($Ident, (int)$Value);
                    }
                    return;
                }
        }
        throw new Exception(RoboHubText::t('Unknown action: %s', $Ident));
    }

    // -----------------------------------------------------------------
    // Öffentliche Aktionen (Skripte, Formular)
    // -----------------------------------------------------------------

    /**
     * Echtzeit-Meldung von der Konto-Instanz (MQTT): bereits übersetzter
     * Zustand mit den geänderten Werten. Nur für dieses Gerät übernehmen.
     */
    public function ReceiveData($JSONString)
    {
        $d = json_decode((string)$JSONString, true);
        if ($this->ReadPropertyBoolean('Deaktiviert') || !is_array($d) || (string)($d['Geraet'] ?? '') !== $this->ReadPropertyString('GeraetID') || !is_array($d['Daten'] ?? null)) {
            return '';
        }
        $this->uebernimm($d['Daten']);
        $this->WriteAttributeInteger('LetzteEchtzeit', time());
        // Verlauf der letzten 20 Meldungen (Rohwerte) — zur Fehlersuche in „📋 Rohdaten".
        // Reine Positionsmeldungen (Kartenrahmen, alle paar Sekunden) nicht.
        $delta = (array)($d['Daten']['echtzeit'] ?? []);
        if ($delta !== []) {
            $verlauf = json_decode((string)$this->ReadAttributeString('EchtzeitVerlauf'), true) ?: [];
            array_unshift($verlauf, ['zeit' => date('d.m.Y H:i:s'), 'werte' => $delta]);
            $this->WriteAttributeString('EchtzeitVerlauf', (string)json_encode(array_slice($verlauf, 0, 20)));
            $this->SendDebug('Echtzeit', json_encode($delta), 0);
        }
        if (is_array($d['Daten']['position'] ?? null)) {
            $this->positionUebernehmen($d['Daten']['position']);
        }
        if (is_string($d['Daten']['kartenobjekt'] ?? null) && $this->klasse() === RoboHubModell::KLASSE_SAUGER) {
            $this->kartenobjektNachladen($d['Daten']['kartenobjekt']);
        }
        $this->liveSteuern();
        $this->kachelAktualisieren();
        return '';
    }

    /** ROBO_Aktualisieren($id): string — Zustand sofort abrufen. */
    public function Aktualisieren(): string
    {
        if ($this->ReadPropertyBoolean('Deaktiviert')) {
            return RoboHubText::t('🔌 This robot is deactivated.');
        }
        if (!$this->kontoAktiv()) {
            $this->ordneKontoZu();
        }
        $antwort = $this->frageKonto(['Aktion' => 'zustand', 'Geraet' => $this->ReadPropertyString('GeraetID')]);
        if (!$antwort['ok']) {
            $this->SetValue('Erreichbar', false);
            $this->WriteAttributeString('LetzterFehler', (string)$antwort['fehler']);
            $this->SetStatus(empty($antwort['anmeldungNoetig']) ? 202 : 201);
            return '⛔ ' . $antwort['fehler'];
        }
        $this->WriteAttributeString('LetzterFehler', '');
        $this->SetStatus(102);
        $this->uebernimm((array)$antwort['daten']);
        $this->WriteAttributeInteger('LetzterAbruf', time());
        $this->liveSteuern();
        if (time() - $this->ReadAttributeInteger('RaeumeStand') > self::RAEUME_ALTER_S) {
            $this->ladeRaeume();
        }
        $this->kachelAktualisieren();
        $this->SetTimerInterval('Abruf', $this->taktSekunden() * 1000);
        return RoboHubText::t('✅ State fetched: %s%s.', RoboHubModell::zustandsName((int)$this->GetValue('Zustand'), $this->klasse()), ($this->hatVariable('Akku') ? RoboHubText::t(', battery %s %%', $this->GetValue('Akku')) : ''));
    }

    /** ROBO_Starten($id): string */
    public function Starten(): string
    {
        return $this->befehlMitKachel('start', []);
    }

    /** ROBO_Anhalten($id): string */
    public function Anhalten(): string
    {
        return $this->befehlMitKachel('pause', []);
    }

    /** ROBO_Stoppen($id): string */
    public function Stoppen(): string
    {
        return $this->befehlMitKachel('stopp', []);
    }

    /** ROBO_ZurStation($id): string */
    public function ZurStation(): string
    {
        return $this->befehlMitKachel('station', []);
    }

    /** ROBO_Orten($id): string */
    public function Orten(): string
    {
        return $this->befehlMitKachel('orten', []);
    }

    /**
     * ROBO_RaeumeReinigen($id, string $raumIDs): string — Raum-IDs als
     * kommagetrennte Liste („3,5"); IDs zeigt das Formular bzw. die Variable
     * „Raum reinigen".
     */
    public function RaeumeReinigen(string $raumIDs): string
    {
        $ids = self::raumIds($raumIDs);
        if (count($ids) === 0) {
            return RoboHubText::t('⛔ No room ID given (e.g. "3,5").');
        }
        return $this->befehlMitKachel('raum', ['raeume' => $ids]);
    }

    /**
     * ROBO_RaeumeReinigenMit($id, "3,5", $modus, $saugstufe, $durchgaenge): string — wie das
     * Startfenster der App: Modus 0 Saugen, 1 Wischen, 2 Saugen und Wischen, 3 Erst saugen,
     * dann wischen; Saugstufe 0 Leise … 3 Turbo; Durchgänge 1–3; −1 = wie eingestellt.
     */
    public function RaeumeReinigenMit(string $raumIDs, int $modus, int $saugstufe, int $durchgaenge): string
    {
        $ids = self::raumIds($raumIDs);
        if (count($ids) === 0) {
            return RoboHubText::t('⛔ No room ID given (e.g. "3,5").');
        }
        return $this->befehlMitKachel('raum', ['raeume' => $ids, 'modus' => $modus, 'saugstufe' => $saugstufe, 'durchgaenge' => $durchgaenge]);
    }

    /** „3,5|0|1|2" → Befehlsparameter (Räume, Modus, Saugstufe, Durchgänge). */
    /**
     * „3,5" → [3, 5]. ID 0 ist gültig (Husqvarna-Hauptbereich, erste STIHL-Zone) — früher
     * hat array_filter sie verworfen; Treiber, bei denen 0 keine Zone ist, prüfen selbst.
     */
    private static function raumIds(string $liste): array
    {
        $ids = [];
        foreach (explode(',', $liste) as $teil) {
            $teil = trim($teil);
            if ($teil !== '' && ctype_digit($teil)) {
                $ids[] = (int)$teil;
            }
        }
        return array_values(array_unique($ids));
    }

    private static function raumStart(string $wert): array
    {
        $teile = explode('|', $wert);
        $p = ['raeume' => self::raumIds($teile[0])];
        foreach (['modus' => 1, 'saugstufe' => 2, 'durchgaenge' => 3] as $schluessel => $i) {
            if (isset($teile[$i]) && is_numeric($teile[$i]) && (int)$teile[$i] >= 0) {
                $p[$schluessel] = (int)$teile[$i];
            }
        }
        return $p;
    }

    /**
     * ROBO_VerbrauchsteilZuruecksetzen($id, string $teil): string — setzt den
     * Verschleißzähler eines Teils auf 100 % zurück (nach Wechsel/Reinigung).
     * $teil = Ident der Prozent-Variable, z. B. „Filter", „Hauptbuerste", „Messer".
     */
    public function VerbrauchsteilZuruecksetzen(string $teil): string
    {
        $name = null;
        foreach (self::TEILE as [$pIdent, , $anzeige]) {
            if ($pIdent === $teil) {
                $name = RoboHubText::wert($anzeige);
            }
        }
        if ($name === null || $this->wert($teil) === null) {
            return RoboHubText::t('⛔ Unknown consumable or not present on this device: %s', $teil);
        }
        $vorher = (int)$this->wert($teil);
        $antwort = $this->frageKonto(['Aktion' => 'zuruecksetzen', 'Geraet' => $this->ReadPropertyString('GeraetID'), 'Teil' => $teil]);
        if (!$antwort['ok']) {
            $this->protokolliere(RoboHubText::t('Resetting %s failed: %s', $name, $antwort['fehler']), KL_WARNING);
            return '⛔ ' . $antwort['fehler'];
        }
        // Dauerhaft festhalten — so ist später nachvollziehbar, wann gewechselt wurde.
        $this->protokolliere(RoboHubText::t('%s reset (previously %s %%).', $name, $vorher));
        $this->Aktualisieren();
        $nachher = $this->wert($teil);
        return RoboHubText::t('✅ %s reset (previously %s %%%s).', $name, $vorher, ($nachher !== null ? RoboHubText::t(', now %s %%', (int)$nachher) : ''));
    }

    /** ROBO_ZeitplanSchalten($id, int $termin, bool $an): string — Zeitplan der Hersteller-App ein-/ausschalten. */
    public function ZeitplanSchalten(int $termin, bool $an): string
    {
        $bekannt = null;
        foreach (json_decode((string)$this->ReadAttributeString('Zeitplaene'), true) ?: [] as $t) {
            if ((int)($t['id'] ?? -1) === $termin) {
                $bekannt = $t;
            }
        }
        if ($bekannt === null) {
            return RoboHubText::t('⛔ Unknown schedule: %s', $termin);
        }
        if (($bekannt['art'] ?? '') !== 'maeher') {
            return $this->befehlText('zeitplan', ['id' => $termin, 'an' => $an]);
        }
        if (!isset($bekannt['tabelle'])) {
            return RoboHubText::t('⛔ This mowing plan cannot be switched (yet).');
        }
        $text = $this->befehlText('zeitplan', ['tabelle' => (int)$bekannt['tabelle'], 'an' => $an]);
        if (strpos($text, '⛔') !== 0) {
            // Der Mäher hat bestätigt; Pläne neu lesen (Nachbarplan kann sich mitgeschaltet haben).
            $this->protokolliere(RoboHubText::t('Mowing plan %s %s.', ((int)($bekannt['nummer'] ?? $termin) + 1), ($an ? RoboHubText::t('switched on') : RoboHubText::t('switched off'))));
            $this->ladeRaeume();
        }
        return $text;
    }

    /**
     * ROBO_RaumEinstellungSetzen($id, int $raum, string $feld, int $wert): string — vorerst gesperrt
     * (seit 0.12.0). Der Treiber-Weg (6.2 customeClean) bleibt für spätere Klärung erhalten.
     */
    public function RaumEinstellungSetzen(int $raum, string $feld, int $wert): string
    {
        // Gesperrt (Live-Befund 12.10.2026): die Hersteller-App zeigt diese gespeicherten Werte
        // nicht an und schreibt sie nicht; wofür der Roboter sie nutzt, ist noch offen.
        return RoboHubText::t('⛔ Changing the room settings stored in the robot is blocked for now — please choose mode/suction level when starting (ROBO_RaeumeReinigenMit).');
    }

    /** ROBO_Rohdaten($id): string — letzte Rohantwort des Herstellers (zur Fehlersuche). */
    public function Rohdaten(): string
    {
        $roh = $this->ReadAttributeString('LetzteRohdaten');
        if ($roh === '') {
            return RoboHubText::t('ℹ️ No raw data yet — click "Update now" first.');
        }
        $daten = json_decode($roh, true);
        $verlauf = json_decode((string)$this->ReadAttributeString('EchtzeitVerlauf'), true) ?: [];
        if (!is_array($daten) || (count($verlauf) === 0 && $this->ReadAttributeString('ZeitplanRoh') === '' && $this->ReadAttributeString('RaumEinstellungen') === '{}')) {
            return $roh;
        }
        $daten['echtzeitVerlauf'] = $verlauf;   // neueste zuerst, bleibt gültiges JSON
        if ($this->ReadAttributeString('ZeitplanRoh') !== '') {
            $daten['zeitplanRoh'] = $this->ReadAttributeString('ZeitplanRoh');
        }
        $quellen = json_decode((string)$this->ReadAttributeString('RaumEinstellungenQuellen'), true) ?: [];
        if ($quellen !== []) {
            $daten['raumEinstellungenRoh'] = $quellen;
        }
        return (string)json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    // -----------------------------------------------------------------
    // Intern
    // -----------------------------------------------------------------

    private const KONTO_GUID = '{DB269BEC-0CA6-4E6A-83B8-78FF8CFB2DB0}';

    /**
     * Verbindet den Roboter mit dem Konto, dessen Geräteliste seine
     * Geräte-ID enthält. Nötig, weil der Konfigurator alle Konten in einer
     * Liste zeigt und Symcon einen neuen Roboter bei mehreren Konten nach
     * eigenem Ermessen verbindet. Fragt keine Cloud an (nur die
     * zwischengespeicherten Listen). Rückgabe: Klartext oder '' (nichts zu tun).
     */
    private function ordneKontoZu(): string
    {
        $gid = $this->ReadPropertyString('GeraetID');
        if ($gid === '') {
            return '';
        }
        $aktuell = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        $konten = IPS_GetInstanceListByModuleID(self::KONTO_GUID);
        $treffer = [];
        foreach ($konten as $kid) {
            try {
                $info = json_decode((string)ROBOHUB_GeraeteListe($kid), true);
            } catch (Throwable $e) {
                continue;
            }
            foreach ((array)($info['geraete'] ?? []) as $g) {
                if ((string)($g['id'] ?? '') === $gid) {
                    $treffer[] = (int)$kid;
                }
            }
        }
        if (in_array($aktuell, $treffer, true)) {
            return '';
        }
        $ziel = 0;
        if (count($treffer) === 1) {
            $ziel = $treffer[0];
        } elseif (count($treffer) === 0 && $aktuell === 0 && count($konten) === 1) {
            // Nur ein Konto vorhanden, Liste evtl. noch nicht geladen: das ist es.
            $ziel = (int)$konten[0];
        }
        if ($ziel === 0 || $ziel === $aktuell) {
            return '';
        }
        if ($aktuell > 0) {
            IPS_DisconnectInstance($this->InstanceID);
        }
        if (!IPS_ConnectInstance($this->InstanceID, $ziel)) {
            $this->protokolliere(RoboHubText::t('Connecting to account #%s failed.', $ziel), KL_WARNING);
            return RoboHubText::t('⛔ Connecting to account #%s failed.', $ziel);
        }
        $text = RoboHubText::t('🔗 Connected to account "%s" (#%s) — this device is registered there.', IPS_GetName($ziel), $ziel);
        $this->protokolliere($text);
        return $text;
    }

    /**
     * Befehl aus einem Skript (ROBO_Starten & Co.): Rückmeldung auch in der Kachel zeigen,
     * genau wie beim Weg über die Variable „Befehl" (Forum Thilo 26.10.2026).
     */
    private function befehlMitKachel(string $befehl, array $parameter): string
    {
        $text = $this->befehlText($befehl, $parameter);
        $this->kachelAktualisieren($text);
        return $text;
    }

    private function befehlText(string $befehl, array $parameter): string
    {
        $antwort = $this->frageKonto(['Aktion' => 'befehl', 'Geraet' => $this->ReadPropertyString('GeraetID'), 'Befehl' => $befehl, 'Parameter' => $parameter]);
        if (!$antwort['ok']) {
            $this->protokolliere(RoboHubText::t('Command "%s" failed: %s', $befehl, $antwort['fehler']), KL_WARNING);
            return '⛔ ' . $antwort['fehler'];
        }
        // Gesendet ist nicht ausgeführt: Zustand gleich nachziehen und
        // danach zügiger nachsehen (Takt richtet sich nach dem Zustand).
        $this->SetTimerInterval('Abruf', 5000);
        return RoboHubText::t('📤 %s The state will update in a few seconds.', (string)$antwort['daten']);
    }

    /** Für Aktionsvariablen (WebFront): Fehlschlag protokollieren, Erfolg als bool. */
    private function fuehreAus(string $befehl, array $parameter): bool
    {
        $text = $this->befehlText($befehl, $parameter);
        $this->kachelAktualisieren($text);
        if (strpos($text, '⛔') === 0) {
            // Variable springt zurück bzw. bleibt — die Protokollzeile steht schon.
            return false;
        }
        return true;
    }

    /**
     * Konto verbunden und aktiv? HasActiveParent() meldete live kurz nach einem Modul-Update
     * mehrfach „nein", obwohl das Konto längst aktiv war (Befund 12.10.2026) — dann den Status
     * der übergeordneten Instanz selbst prüfen.
     */
    private function kontoAktiv(): bool
    {
        if ($this->HasActiveParent()) {
            return true;
        }
        $eltern = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        return $eltern > 0 && (int)(IPS_GetInstance($eltern)['InstanceStatus'] ?? 0) === IS_ACTIVE;
    }

    /** Sendet an die Konto-Instanz; liefert immer ['ok' => bool, ...]. */
    protected function frageKonto(array $anfrage): array
    {
        if ($this->ReadPropertyBoolean('Deaktiviert')) {
            return ['ok' => false, 'fehler' => RoboHubText::t('This robot is deactivated (form: "Deactivate this robot").')];
        }
        if (!$this->kontoAktiv()) {
            return ['ok' => false, 'fehler' => RoboHubText::t('No active RoboHub account instance connected — please choose a signed-in account under "Parent instance".')];
        }
        $anfrage['DataID'] = self::DATA_ZUM_KONTO;
        try {
            $roh = $this->SendDataToParent((string)json_encode($anfrage));
        } catch (Throwable $e) {
            return ['ok' => false, 'fehler' => RoboHubText::t('Account instance not reachable: %s', $e->getMessage())];
        }
        $antwort = json_decode((string)$roh, true);
        if (!is_array($antwort) || !isset($antwort['ok'])) {
            return ['ok' => false, 'fehler' => RoboHubText::t('Unexpected response from the account instance.')];
        }
        return $antwort;
    }

    /**
     * Neues Kartenobjekt gemeldet (App-Änderung) → Raum-Einstellungen daraus lesen.
     * Nicht während der Arbeit: dann kommen ständig neue Objekte (ioBroker drosselt genauso).
     */
    private function kartenobjektNachladen(string $name): void
    {
        $zustand = $this->hatVariable('Zustand') ? (int)$this->GetValue('Zustand') : RoboHubModell::ZUSTAND_UNBEKANNT;
        if (in_array($zustand, [RoboHubModell::ZUSTAND_REINIGT, RoboHubModell::ZUSTAND_PAUSIERT, RoboHubModell::ZUSTAND_ZURUECK], true)) {
            return;
        }
        $antwort = $this->frageKonto(['Aktion' => 'kartenobjekt', 'Geraet' => $this->ReadPropertyString('GeraetID'), 'Name' => $name]);
        $d = $antwort['ok'] ? (array)$antwort['daten'] : ['kartenobjektInfo' => RoboHubText::t('Real-time %s: %s', $name, ($antwort['fehler'] ?? '?'))];
        $q = json_decode((string)$this->ReadAttributeString('RaumEinstellungenQuellen'), true) ?: [];
        $q['kartenobjektInfo'] = date('d.m.Y H:i:s') . ' ' . (string)($d['kartenobjektInfo'] ?? '');
        $this->WriteAttributeString('RaumEinstellungenQuellen', (string)json_encode($q));
        if (isset($d['raumEinstellungen'])) {
            $this->raumEinstellungenMerken($d, 'kartenobjekt');
            $this->raumEinstellungenVariable();
        }
    }

    /** Einstellungen je Raum aus Karte/Live-Karte merken; Variable nach dem Laden der Raumnamen. */
    private function raumEinstellungenMerken(array $d, string $quelle = ''): void
    {
        if (!isset($d['raumEinstellungen']) || !is_array($d['raumEinstellungen'])) {
            return;
        }
        if ($quelle !== '') {
            // Beide Quellen getrennt für „📋 Rohdaten" (gespeicherte Karte vs. Live-Rahmen).
            $q = json_decode((string)$this->ReadAttributeString('RaumEinstellungenQuellen'), true) ?: [];
            $q[$quelle] = ['zeit' => date('d.m.Y H:i:s'), 'an' => $d['raumEinstellungenAn'] ?? null, 'cleanset' => $d['raumEinstellungenRoh'] ?? null];
            $this->WriteAttributeString('RaumEinstellungenQuellen', (string)json_encode($q));
            // Live-Rahmen tragen nur den Stand der letzten Kartenaufnahme (Live-Befund Barny:
            // App-Änderung fehlte dort) — das Kartenobjekt hat Vorrang.
            if ($quelle === 'liveKarte' && isset($q['kartenobjekt'])) {
                return;
            }
        }
        $this->WriteAttributeString('RaumEinstellungen', (string)json_encode([
            'raeume' => $d['raumEinstellungen'], 'an' => $d['raumEinstellungenAn'] ?? null, 'roh' => $d['raumEinstellungenRoh'] ?? null,
        ]));
    }

    private function raumEinstellungenVariable(): void
    {
        $zeilen = $this->raumEinstellungenZeilen();
        if ($zeilen === []) {
            return;
        }
        $re = json_decode((string)$this->ReadAttributeString('RaumEinstellungen'), true) ?: [];
        $kopf = ($re['an'] ?? null) === false ? RoboHubText::t("⏸️ Switched off in the app — the general settings apply.\n") : '';
        $this->MaintainVariable('RaumEinstellungen', RoboHubText::t('Room settings in the robot'), VARIABLETYPE_STRING, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 96, true);
        // MaintainVariable benennt vorhandene Variablen nicht um — den alten Standardnamen (0.10/0.11)
        // gezielt ersetzen, einen selbst vergebenen Namen nicht anfassen.
        $vid = (int)IPS_GetObjectIDByIdent('RaumEinstellungen', $this->InstanceID);
        if (IPS_GetName($vid) === 'Einstellungen je Raum') {
            IPS_SetName($vid, RoboHubText::t('Room settings in the robot'));
        }
        $this->SetValue('RaumEinstellungen', $kopf . implode("\n", $zeilen));
    }

    /** „Küche: Standard · 1× · Saugen und Wischen · Wasser Mittel" je Raum. */
    private function raumEinstellungenZeilen(): array
    {
        $re = json_decode((string)$this->ReadAttributeString('RaumEinstellungen'), true) ?: [];
        $namen = json_decode((string)$this->ReadAttributeString('Raeume'), true) ?: [];
        $zeilen = [];
        $liste = (array)($re['raeume'] ?? []);
        // In Reinigungsreihenfolge (wie die App), Räume ohne Reihenfolge ans Ende.
        uksort($liste, function ($a, $b) use ($liste): int {
            $ra = (int)($liste[$a]['reihenfolge'] ?? 0) ?: 999;
            $rb = (int)($liste[$b]['reihenfolge'] ?? 0) ?: 999;
            return $ra <=> $rb ?: ((int)$a <=> (int)$b);
        });
        foreach ($liste as $id => $e) {
            $teile = [];
            if (!empty($e['modus'])) {
                $teile[] = $e['modus'];
            }
            array_push($teile, (string)$e['saugstufe'], (int)$e['durchgaenge'] . '×');
            // Wasser nur, wenn auch gewischt wird (die App blendet es sonst aus).
            if (empty($e['modus']) || preg_match('/wisch|mop/iu', (string)$e['modus'])) {
                $teile[] = (preg_match('/^(Feuchte|Moisture)\b/u', (string)$e['wasser']) ? '' : RoboHubText::t('Water ')) . $e['wasser'];
            }
            $nr = (int)($e['reihenfolge'] ?? 0);
            $zeilen[(int)$id] = ($nr > 0 ? $nr . '. ' : '') . ($namen[(string)$id] ?? $namen[(int)$id] ?? RoboHubText::t('Room %s', $id)) . ': ' . implode(' · ', $teile);
        }
        return $zeilen;
    }

    private function zeitplanSchaltbar(array $t): bool
    {
        if (!empty($t['ungueltig']) || ($t['art'] ?? '') === 'text') {
            return false;
        }
        if (($t['art'] ?? '') === 'maeher') {
            // Einschalten braucht einen aktiven Termin; Ausschalten geht immer.
            return isset($t['tabelle']) && (!empty($t['an']) || !empty($t['eintraege']));
        }
        return true;
    }

    private function zeitplaeneUebernehmen(array $termine): void
    {
        $this->WriteAttributeString('Zeitplaene', (string)json_encode(array_values($termine)));
        $zeilen = array_map(function (array $t): string { return ($t['an'] ? '✅ ' : '⏸️ ') . $this->zeitplanText($t); }, $termine);
        $this->MaintainVariable('Zeitplaene', RoboHubText::t('Schedules'), VARIABLETYPE_STRING, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 95, true);
        $this->SetValue('Zeitplaene', $zeilen === [] ? 'keine' : implode("\n", $zeilen));
    }

    /** Mäh-Plan: „Plan Sommer · Mo, Mi 09:00–12:00 · alles; Sa 10:00–11:00 · Rasen". */
    private function maehplanText(array $t): string
    {
        $namen = json_decode((string)$this->ReadAttributeString('Raeume'), true) ?: [];
        $gruppen = [];
        foreach ((array)($t['eintraege'] ?? []) as $e) {
            $was = $e['art'] === 'zonen'
                ? implode(', ', array_map(function ($z) use ($namen): string { return (string)($namen[(string)$z] ?? $namen[(int)$z] ?? RoboHubText::t('Zone %s', $z)); }, (array)$e['zonen']))
                : [RoboHubText::t('everything'), RoboHubText::t('Edge'), RoboHubText::t('Patrol')][['alle' => 0, 'kante' => 1, 'patrouille' => 2][$e['art']] ?? -1] ?? $e['art'];
            $schluessel = $e['von'] . ($e['bis'] !== null ? '–' . $e['bis'] : '') . ' · ' . $was;
            $gruppen[$schluessel][] = RoboHubText::tagCode((string)$e['tag']);
        }
        $teile = [];
        foreach ($gruppen as $wann => $tage) {
            $tage = array_values(array_unique($tage));
            $tageText = count($tage) === 7 ? RoboHubText::t('daily') : ($tage === ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] ? RoboHubText::t('weekdays') : implode(', ', array_map([RoboHubText::class, 'tag'], $tage)));
            $teile[] = $tageText . ' ' . $wann;
        }
        // Ohne Namen wie in der App gezählt: erster Plan = „Plan 1".
        $kopf = trim((string)($t['name'] ?? '')) !== '' ? RoboHubText::t('Plan "%s"', $t['name']) : RoboHubText::t('Plan %s%s', ((int)($t['nummer'] ?? $t['id']) + 1), ((int)($t['platz'] ?? 0) > 0 ? RoboHubText::t(' (map %s)', ((int)$t['platz'] + 1)) : ''));
        return $kopf . ($teile === [] ? RoboHubText::t(' · no appointments') : ' · ' . implode('; ', $teile));
    }

    /** Ein Termin in Klartext, mit Raumnamen aus der Karte: „07:00 · Mo, Mi, Fr · Vorrat, Küche · Saugen". */
    private function zeitplanText(array $t): string
    {
        if (($t['art'] ?? '') === 'text') {
            return (string)($t['text'] ?? '');   // Treiber liefert den fertigen Text (Worx)
        }
        if (($t['art'] ?? '') === 'maeher') {
            return $this->maehplanText($t);
        }
        $tage = array_map(function ($x): string { return RoboHubText::tagCode((string)$x); }, (array)($t['tage'] ?? []));
        if (!empty($t['einmalig'])) {
            $wann = RoboHubText::t('once');
        } elseif (count($tage) === 7) {
            $wann = RoboHubText::t('daily');
        } elseif ($tage === ['Mon', 'Tue', 'Wed', 'Thu', 'Fri']) {
            $wann = RoboHubText::t('weekdays');
        } else {
            $wann = $tage === [] ? RoboHubText::t('never') : implode(', ', array_map([RoboHubText::class, 'tag'], $tage));
        }
        $teile = [(string)($t['zeit'] ?? '?'), $wann];
        $namen = json_decode((string)$this->ReadAttributeString('Raeume'), true) ?: [];
        switch ($t['art'] ?? '') {
            case 'raeume':
                $teile[] = implode(', ', array_map(function (array $r) use ($namen): string {
                    return (string)($namen[(string)$r['id']] ?? $namen[(int)$r['id']] ?? RoboHubText::t('Room %s', $r['id']));
                }, (array)($t['raeume'] ?? [])));
                $modi = array_values(array_unique(array_filter(array_column((array)($t['raeume'] ?? []), 'modus'))));
                if (count($modi) === 1) {
                    $teile[] = $modi[0];
                }
                break;
            case 'alle':
                $teile[] = RoboHubText::t('all rooms');
                if (!empty($t['modus'])) {
                    $teile[] = $t['modus'];
                }
                break;
            case 'kurzbefehl':
                $teile[] = RoboHubText::t('Shortcut %s', (int)($t['kurzbefehl'] ?? 0));
                break;
        }
        if (!empty($t['ungueltig'])) {
            $teile[] = RoboHubText::t('⚠️ invalid');
        }
        return implode(' · ', $teile);
    }

    /** Kartendaten für die Kachel (ohne Umrechnungsdaten). */
    private function kachelKarte(): ?array
    {
        if (!$this->ReadPropertyBoolean('KarteZeigen')) {
            return null;
        }
        $karte = json_decode((string)$this->ReadAttributeString('Karte'), true);
        if (!is_array($karte) || (string)($karte['bild'] ?? '') === '') {
            return null;
        }
        unset($karte['geometrie']);
        return $karte;
    }

    /** Idents, die der Treiber als frei änderbar gemeldet hat (Nicht stören, Lautstärke). */
    private function schreibbar(): array
    {
        return json_decode((string)$this->ReadAttributeString('Schreibbar'), true) ?: [];
    }

    /**
     * Eingabe aus WebFront/Kachel/Skript prüfen, bevor etwas zum Roboter geht.
     * Ungültig → Protokoll + Kachel-Rückmeldung, Rückgabe null (Variable bleibt).
     */
    private function einstellungPruefen(string $ident, $wert)
    {
        $fehler = null;
        if ($ident === 'Lautstaerke') {
            $wert = (int)$wert;
            if ($wert < 0 || $wert > 100) {
                $fehler = RoboHubText::t('Volume must be between 0 and 100 %.');
            }
        } elseif ($ident === 'NichtStoeren') {
            $wert = (bool)$wert;
        } else {
            $wert = trim((string)$wert);
            if (!RoboHubModell::istUhrzeit($wert)) {
                $fehler = RoboHubText::t('Please enter the time as HH:MM (e.g. 22:00), not "%s".', $wert);
            }
        }
        if ($fehler !== null) {
            $this->protokolliere($fehler, KL_WARNING);
            $this->kachelAktualisieren('⛔ ' . $fehler);
            return null;
        }
        return $wert;
    }

    /** Darstellung einer änderbaren Einstellung: Schalter, Schieberegler oder Eingabefeld. */
    private function presSchreibbar(int $typ, string $suffix): array
    {
        if ($typ === VARIABLETYPE_BOOLEAN) {
            return ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH];
        }
        if ($typ === VARIABLETYPE_INTEGER) {
            return ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => 0, 'MAX' => 100, 'STEP_SIZE' => 5, 'SUFFIX' => $suffix, 'DIGITS' => 0];
        }
        // Freie Werte ohne Bereich brauchen die Eingabe-Darstellung (HeishaMon-Befund:
        // die reine Wertedarstellung kann keine Eingabe).
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_INPUT, 'SUFFIX' => $suffix];
    }

    /** Übernimmt einen normalisierten Treiber-Zustand in die Variablen. */
    /** Roboter-Einstellungen (Saugstufe, Durchgänge, Nicht stören …) — die Variablen, die man umstellen kann. */
    private const EINSTELLUNGEN = ['Reinigungsmodus', 'Saugstufe', 'Wassermenge', 'Durchgaenge', 'Kantenreinigung', 'BehaelterVoll',
        'Schnitthoehe', 'Scheinwerfer', 'Maehzeiten', 'Regenverzoegerung', 'Zeitzuschlag',
        'NichtStoeren', 'NichtStoerenVon', 'NichtStoerenBis', 'Lautstaerke'];

    /**
     * Property EinstellungenZeigen betrifft seit 0.19.2 nur noch den Überblick der Kachel (Thilo,
     * 24.10.2026: Variablen in der Instanz sollen bleiben). 0.19.0/0.19.1 haben die Variablen bei
     * ausgeschalteter Einstellung versteckt — das wird hier einmalig zurückgenommen.
     */
    private function einstellungenFreigeben(): void
    {
        if ($this->ReadAttributeBoolean('EinstellungenFreigegeben')) {
            return;
        }
        if (!$this->ReadPropertyBoolean('EinstellungenZeigen')) {
            foreach (self::EINSTELLUNGEN as $ident) {
                $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
                if ($vid !== false && (bool)IPS_GetObject($vid)['ObjectIsHidden']) {
                    IPS_SetHidden($vid, false);
                }
            }
        }
        $this->WriteAttributeBoolean('EinstellungenFreigegeben', true);
    }

    private function uebernimm(array $daten): void
    {
        $werte    = (array)($daten['werte'] ?? []);
        $optionen = (array)($daten['optionen'] ?? []);
        // Zusammenführen statt ersetzen: Echtzeit-Meldungen des Mähers tragen
        // die Einstellungen (Konfigurationskanal) nicht mit.
        $schreibbar = array_values(array_unique(array_merge($this->schreibbar(), array_map('strval', (array)($daten['schreibbar'] ?? [])))));
        $this->WriteAttributeString('Schreibbar', (string)json_encode($schreibbar));

        $this->SetValue('Erreichbar', (bool)($daten['online'] ?? true));
        if (isset($daten['bild']) && (string)$daten['bild'] !== $this->ReadAttributeString('Bild')) {
            $this->WriteAttributeString('Bild', (string)$daten['bild']);
        }
        if (array_key_exists('Zustand', $werte) && $werte['Zustand'] !== null) {
            $this->SetValue('Zustand', (int)$werte['Zustand']);
        }

        foreach (RoboHubModell::FELDER as $ident => [$name, $typ, $suffix, $stellen, $pos]) {
            if ($ident === 'Zustand' || !array_key_exists($ident, $werte) || $werte[$ident] === null) {
                continue;
            }
            if (isset($optionen[$ident]) && is_array($optionen[$ident])) {
                // Einstellbar mit Auswahlliste → Integer + Aufzählung + Aktion.
                $this->MaintainVariable($ident, RoboHubText::wert($name), VARIABLETYPE_INTEGER, $this->presEnum($optionen[$ident]), $pos, true);
                $this->EnableAction($ident);
                $this->SetValue($ident, (int)$werte[$ident]);
                continue;
            }
            if (in_array($ident, $schreibbar, true)) {
                $this->MaintainVariable($ident, RoboHubText::wert($name), $typ, $this->presSchreibbar($typ, $suffix), $pos, true);
                $this->EnableAction($ident);
                $this->SetValue($ident, $this->typisiere($typ, $werte[$ident]));
                continue;
            }
            // Wertanzeige laut Symcon-Doku: DIGITS nur bei Ganz-/Kommazahlen (Symcon 9.1 warnt sonst
            // bei jedem Abruf „Parameter DIGITS is not defined for this presentation", Forum 14.10.2026).
            $pres = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
            if ($typ !== VARIABLETYPE_BOOLEAN) {
                $pres['SUFFIX'] = $suffix;
            }
            if ($typ === VARIABLETYPE_INTEGER || $typ === VARIABLETYPE_FLOAT) {
                $pres['DIGITS'] = $stellen;
            }
            $this->MaintainVariable($ident, RoboHubText::wert($name), $typ, $pres, $pos, true);
            $this->SetValue($ident, $this->typisiere($typ, $werte[$ident]));
        }
        $this->WriteAttributeString('Optionen', (string)json_encode($optionen));

        if (array_key_exists('zeitplaene', $daten) && is_array($daten['zeitplaene'])) {
            $this->zeitplaeneUebernehmen($daten['zeitplaene']);
        }

        if (isset($daten['roh'])) {
            $roh = (string)json_encode($daten['roh'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $this->WriteAttributeString('LetzteRohdaten', $roh);
            $this->SendDebug('Rohdaten', $roh, 0);
        }
    }

    // Takt der Live-Karte während der Arbeit (Sauger: Kartenrahmen klein, Mäher: ganze Gerätedaten).
    private const LIVE_SAUGER_S = 30;
    private const LIVE_MAEHER_S = 60;

    /**
     * ROBO_LiveKarteLaden($id): string — aktuelle Karte mit gefahrenem Weg und
     * Roboterposition holen (läuft während der Arbeit von selbst).
     */
    public function LiveKarteLaden(): string
    {
        $antwort = $this->frageKonto(['Aktion' => 'livekarte', 'Geraet' => $this->ReadPropertyString('GeraetID'),
            'Raeume' => json_decode((string)$this->ReadAttributeString('Raeume'), true) ?: []]);
        if (!$antwort['ok']) {
            $this->SendDebug('Live-Karte', (string)$antwort['fehler'], 0);
            return '⛔ ' . $antwort['fehler'];
        }
        $d = (array)$antwort['daten'];
        $this->raumEinstellungenMerken($d, 'liveKarte');
        $this->raumEinstellungenVariable();
        if ((string)($d['bild'] ?? '') === '') {
            return RoboHubText::t('ℹ️ No current map delivered.');
        }
        $weg = array_values((array)($d['weg'] ?? []));
        if ($weg === [] && !$this->ReadAttributeBoolean('LiveAktiv')) {
            // Nach Auftragsende löscht der Roboter den Weg im Kartenrahmen (Live-Befund Barny
            // 12.10.2026) — der fertige Weg soll bis zur nächsten Arbeit stehen bleiben.
            $weg = (array)((json_decode((string)$this->ReadAttributeString('Karte'), true) ?: [])['weg'] ?? []);
        }
        $this->WriteAttributeString('Karte', (string)json_encode([
            'bild' => (string)$d['bild'], 'marken' => array_values((array)($d['marken'] ?? [])),
            'station' => $d['station'] ?? null, 'format' => (float)($d['format'] ?? 1.0),
            'geometrie' => $d['geometrie'] ?? null, 'weg' => $weg,
            'roboter' => $d['roboter'] ?? null, 'live' => time(),
        ]));
        $this->kachelAktualisieren();
        $punkte = 0;
        foreach ((array)($d['weg'] ?? []) as $s) {
            $punkte += count((array)$s);
        }
        return RoboHubText::t('✅ Live map loaded: %s waypoints%s', $punkte, (isset($d['roboter']) ? RoboHubText::t(', robot on the map.') : '.'));
    }

    /** Zustand → Live-Takt an/aus. Am Ende der Arbeit einmal den fertigen Weg holen. */
    private function liveSteuern(): void
    {
        $zustand = $this->hatVariable('Zustand') ? (int)$this->GetValue('Zustand') : RoboHubModell::ZUSTAND_UNBEKANNT;
        $aktiv = $this->ReadPropertyBoolean('LiveKarte') && $this->ReadPropertyBoolean('KarteZeigen')
            && in_array($zustand, [RoboHubModell::ZUSTAND_REINIGT, RoboHubModell::ZUSTAND_PAUSIERT, RoboHubModell::ZUSTAND_ZURUECK], true);
        $war = $this->ReadAttributeBoolean('LiveAktiv');
        if ($aktiv === $war) {
            return;
        }
        $this->WriteAttributeBoolean('LiveAktiv', $aktiv);
        $takt = $this->klasse() === RoboHubModell::KLASSE_MAEHER ? self::LIVE_MAEHER_S : self::LIVE_SAUGER_S;
        $this->SetTimerInterval('LiveKarte', $aktiv ? $takt * 1000 : 0);
        // Beim Start sofort, am Ende ein letztes Mal (fertiger Weg bleibt bis zur nächsten Arbeit stehen).
        $this->LiveKarteLaden();
    }

    /** Echtzeit-Position [x mm, y mm, Winkel] auf die angezeigte Karte setzen. */
    private function positionUebernehmen(array $pos): void
    {
        $karte = json_decode((string)$this->ReadAttributeString('Karte'), true) ?: [];
        if (!is_array($karte['geometrie'] ?? null) || !isset($pos[0], $pos[1])) {
            return;
        }
        $karte['roboter'] = RoboHubKartenbild::prozentPunkt($karte['geometrie'], (float)$pos[0], (float)$pos[1]) + ['winkel' => (int)($pos[2] ?? 0)];
        $this->WriteAttributeString('Karte', (string)json_encode($karte));
    }

    /** ROBO_RaeumeLaden($id): string — Raum-/Zonenliste aus der Karte neu holen. */
    public function RaeumeLaden(): string
    {
        return $this->ladeRaeume();
    }

    private function ladeRaeume(): string
    {
        $this->WriteAttributeInteger('RaeumeStand', time());
        $antwort = $this->frageKonto(['Aktion' => 'raeume', 'Geraet' => $this->ReadPropertyString('GeraetID')]);
        if (!$antwort['ok']) {
            $this->SendDebug('Räume', (string)$antwort['fehler'], 0);
            return '⛔ ' . $antwort['fehler'];
        }
        $daten = (array)$antwort['daten'];
        if (isset($daten['zeitplanRoh'])) {
            $this->WriteAttributeString('ZeitplanRoh', (string)$daten['zeitplanRoh']);
        }
        $this->raumEinstellungenMerken($daten, (string)($daten['raumEinstellungenQuelle'] ?? 'gespeicherteKarte'));
        if (isset($daten['kartenobjektInfo'])) {
            $q = json_decode((string)$this->ReadAttributeString('RaumEinstellungenQuellen'), true) ?: [];
            $q['kartenobjektInfo'] = date('d.m.Y H:i:s') . ' ' . $daten['kartenobjektInfo'];
            $this->WriteAttributeString('RaumEinstellungenQuellen', (string)json_encode($q));
        }
        $raeume = [];
        foreach ((array)($daten['raeume'] ?? []) as $id => $name) {
            $raeume[(int)$id] = (string)$name;
        }
        if ((string)($daten['bild'] ?? '') !== '' && !$this->ReadAttributeBoolean('LiveAktiv')) {
            $this->WriteAttributeString('Karte', (string)json_encode([
                'bild' => (string)$daten['bild'], 'marken' => array_values((array)($daten['marken'] ?? [])),
                'station' => $daten['station'] ?? null, 'format' => (float)($daten['format'] ?? 1.0),
                'geometrie' => $daten['geometrie'] ?? null,
            ]));
        }
        if (count($raeume) === 0) {
            if (isset($daten['zeitplaene']) && is_array($daten['zeitplaene'])) {
                $this->zeitplaeneUebernehmen($daten['zeitplaene']);
            }
            return RoboHubText::t('ℹ️ No rooms/zones found — the robot has no saved map yet, or its map format is not known yet (debug "Karte").');
        }
        $alt = json_decode((string)$this->ReadAttributeString('Raeume'), true) ?: [];
        if ($alt != $raeume || !$this->hatVariable('Raum')) {
            $this->WriteAttributeString('Raeume', (string)json_encode($raeume));
            $this->MaintainVariable('Raum', $this->klasse() === RoboHubModell::KLASSE_MAEHER ? RoboHubText::t('Mow zone') : RoboHubText::t('Clean room'), VARIABLETYPE_INTEGER, $this->presEnum($raeume), 6, true);
            $this->EnableAction('Raum');
        }
        // Nach den Raum-/Zonennamen, damit die Pläne schon die neuen Namen tragen.
        if (isset($daten['zeitplaene']) && is_array($daten['zeitplaene'])) {
            $this->zeitplaeneUebernehmen($daten['zeitplaene']);
        }
        $this->raumEinstellungenVariable();
        $this->kachelAktualisieren();
        return RoboHubText::t('✅ %s rooms/zones: %s.', count($raeume), implode(', ', $raeume));
    }

    private function typisiere(int $typ, $wert)
    {
        switch ($typ) {
            case VARIABLETYPE_BOOLEAN: return (bool)$wert;
            case VARIABLETYPE_INTEGER: return (int)round((float)$wert);
            case VARIABLETYPE_FLOAT:   return (float)$wert;
            default:                   return (string)RoboHubText::wert((string)$wert);
        }
    }

    private function taktSekunden(): int
    {
        $basis = max(30, $this->ReadPropertyInteger('Intervall'));
        $zustand = $this->hatVariable('Zustand') ? (int)$this->GetValue('Zustand') : RoboHubModell::ZUSTAND_UNBEKANNT;
        $aktiv = in_array($zustand, [RoboHubModell::ZUSTAND_REINIGT, RoboHubModell::ZUSTAND_ZURUECK, RoboHubModell::ZUSTAND_STATIONSARBEIT, RoboHubModell::ZUSTAND_KARTIERUNG], true);
        // Kommen Echtzeit-Meldungen, braucht es die schnelle Abfrage nicht.
        $echtzeit = time() - $this->ReadAttributeInteger('LetzteEchtzeit') < 600;
        return ($aktiv && !$echtzeit) ? min($basis, self::TAKT_AKTIV_S) : $basis;
    }

    private function klasse(): string
    {
        return $this->ReadPropertyString('Klasse') === RoboHubModell::KLASSE_MAEHER ? RoboHubModell::KLASSE_MAEHER : RoboHubModell::KLASSE_SAUGER;
    }

    private function zustandsOptionen(string $klasse): array
    {
        $map = [];
        foreach (array_keys(RoboHubModell::ZUSTAENDE) as $wert) {
            if ($klasse === RoboHubModell::KLASSE_SAUGER && $wert === RoboHubModell::ZUSTAND_WETTERPAUSE) {
                continue;
            }
            $map[$wert] = RoboHubModell::zustandsName($wert, $klasse);
        }
        return $map;
    }

    private function befehlsOptionen(string $klasse): array
    {
        $map = [];
        foreach (RoboHubModell::BEFEHLE as $wert => [$name]) {
            $map[$wert] = $name;
        }
        return $map;
    }

    private function befehlsName(int $wert): string
    {
        $namen = [
            RoboHubModell::BEFEHL_START   => 'start',
            RoboHubModell::BEFEHL_PAUSE   => 'pause',
            RoboHubModell::BEFEHL_STOPP   => 'stopp',
            RoboHubModell::BEFEHL_STATION => 'station',
            RoboHubModell::BEFEHL_ORTEN   => 'orten',
        ];
        if (!isset($namen[$wert])) {
            throw new Exception(RoboHubText::t('Unknown command: %s', $wert));
        }
        return $namen[$wert];
    }

    /** Wertanzeige mit Beschriftung je Wert (Optionen-Schlüssel laut Symcon-Doku: ColorActive/ColorValue statt Color). */
    /**
     * Wertanzeige mit festem Text je Ganzzahl. Laut Symcon-Doku kennt die Wertanzeige OPTIONS nur bei
     * Boolean/String; Ganz-/Kommazahlen bekommen Intervalle mit „Konstante" (live geprüft 26.10.2026:
     * mit OPTIONS zeigte „Zustand" nur die Zahl, mit Intervallen den Text — Forum baraka).
     */
    private function presAnzeige(array $map): array
    {
        $intervalle = [];
        foreach ($map as $wert => $caption) {
            $intervalle[] = ['IntervalMinValue' => (int)$wert, 'IntervalMaxValue' => (int)$wert, 'ConstantActive' => true, 'ConstantValue' => (string)RoboHubText::wert((string)$caption),
                'ConversionFactor' => 1, 'PrefixActive' => false, 'SuffixActive' => false, 'DigitsActive' => false,
                'IconActive' => false, 'IconValue' => '', 'ColorActive' => false, 'ColorValue' => -1];
        }
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'INTERVALS_ACTIVE' => true, 'INTERVALS' => json_encode($intervalle)];
    }

    private function presEnum(array $map): array
    {
        $options = [];
        foreach ($map as $wert => $caption) {
            // Schlüssel laut Symcon-Doku (Darstellung Aufzählung): Value, Caption, IconActive, IconValue, Color.
            // Neuere Versionen brechen bei anderen ab („unknown sub-parameters", Forum 13.10.2026).
            $options[] = ['Value' => (int)$wert, 'Caption' => (string)RoboHubText::wert((string)$caption), 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        return ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($options)];
    }

    private function hatVariable(string $ident): bool
    {
        return @IPS_GetObjectIDByIdent($ident, $this->InstanceID) !== false;
    }

    private function protokolliere(string $text, int $stufe = KL_MESSAGE): void
    {
        $this->LogMessage($text, $stufe);
    }

    // -----------------------------------------------------------------
    // Kachel (Kachel-Visualisierung) und eigenständige Seite (IPSView)
    // -----------------------------------------------------------------

    private const WEBHOOK_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    // Verbrauchsteile für die Kachel: [Prozent-Ident, Stunden-Ident, Name].
    private const TEILE = [
        ['Hauptbuerste', 'HauptbuersteRest', 'Main brush'],
        ['Seitenbuerste', 'SeitenbuersteRest', 'Side brush'],
        ['Filter', 'FilterRest', 'Filter'],
        ['Mopp', 'MoppRest', 'Mop/roller'],
        ['Sensoren', 'SensorenRest', 'Sensors'],
        ['Abwasserfilter', 'AbwasserfilterRest', 'Waste water outlet filter'],
        ['Schmutzwasserbehaelter', 'SchmutzwasserbehaelterRest', 'Dirty water tank'],
        ['Messer', 'MesserRest', 'Cutting blades'],
        ['Reinigungsbuerste', 'ReinigungsbuersteRest', 'Cleaning brush'],
        ['Wartung', 'WartungRest', 'Robot maintenance'],
    ];

    private function hookPfad(): string
    {
        return '/hook/robohub' . $this->InstanceID;
    }

    public function GetVisualizationTile()
    {
        return file_get_contents(__DIR__ . '/module.html')
            . '<script>handleMessage(' . $this->kachelDaten(false, '') . ');</script>';
    }

    private function kachelAktualisieren(string $rueckmeldung = ''): void
    {
        $this->UpdateVisualizationValue($this->kachelDaten(false, '', $rueckmeldung));
    }

    /**
     * Eigenständige Seite für IPSView (WebView-Element) oder jeden Browser:
     *   /hook/robohub<ID>            Anzeige, aktualisiert sich alle 15 s
     *   /hook/robohub<ID>?key=…      dazu Bedienung, falls im Formular erlaubt
     *   ?json=1                      nur Daten;  ?cmd=Befehl|Raum|Raeume&wert=…&key=…  Befehl
     * Die Seite ist ohne Symcon-Anmeldung erreichbar, deshalb Befehle nur mit
     * dem geheimen Schlüssel UND eingeschalteter Freigabe.
     */
    public function ProcessHookData()
    {
        $key = (string)($_GET['key'] ?? '');
        $darf = $this->ReadPropertyBoolean('WebBedienung') && $key !== '' && hash_equals($this->ReadAttributeString('WebSchluessel'), $key);

        if (isset($_GET['cmd'])) {
            $this->kopf('application/json');
            if (!$darf) {
                echo json_encode(['ok' => false, 'text' => RoboHubText::t('🔒 Control via this page is not enabled.')]);
                return;
            }
            $cmd = (string)$_GET['cmd'];
            $wert = (string)($_GET['wert'] ?? '');
            if (!in_array($cmd, ['Befehl', 'Raum', 'Raeume', 'Zuruecksetzen', 'Zeitplan'], true)) {
                echo json_encode(['ok' => false, 'text' => RoboHubText::t('⛔ Unknown command.')]);
                return;
            }
            try {
                if ($cmd === 'Befehl') {
                    $text = $this->befehlText($this->befehlsName((int)$wert), []);
                } elseif ($cmd === 'Zuruecksetzen') {
                    $text = $this->VerbrauchsteilZuruecksetzen($wert);
                } elseif ($cmd === 'Zeitplan') {
                    [$tid, $an] = array_pad(explode(':', $wert, 2), 2, '0');
                    $text = $this->ZeitplanSchalten((int)$tid, $an === '1');
                } else {
                    $start = self::raumStart($wert);
                    $text = $start['raeume'] === [] ? RoboHubText::t('⛔ No room ID given.') : $this->befehlText('raum', $start);
                }
            } catch (Throwable $e) {
                $text = '⛔ ' . $e->getMessage();
            }
            $this->kachelAktualisieren($text);
            echo json_encode(['ok' => strpos($text, '⛔') !== 0, 'text' => $text], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (isset($_GET['json'])) {
            $this->kopf('application/json');
            echo $this->kachelDaten(true, $darf ? $key : '');
            return;
        }
        $this->kopf('text/html');
        $json = $this->kachelDaten(true, $darf ? $key : '');
        $abruf = json_encode($this->hookPfad() . '?json=1' . ($darf ? '&key=' . rawurlencode($key) : ''));
        echo file_get_contents(__DIR__ . '/module.html')
            . '<script>handleMessage(' . $json . ');setInterval(function(){fetch(' . $abruf . ')'
            . '.then(function(r){return r.text();}).then(function(t){handleMessage(t);}).catch(function(){});},15000);</script>';
    }

    private function kopf(string $typ): void
    {
        if (!headers_sent()) {
            header('Content-Type: ' . $typ . '; charset=utf-8');
        }
    }

    /** Texte der Kachel (module.html schlägt sie per T() nach; Deutsch aus locale.json). */
    private const KACHEL_TEXTE = ['language code: en', 'charging', 'Currently not reachable', 'Display only — control not enabled for this page', 'Map', 'Station', 'Live · %s', 'Mow zone', 'Clean room', 'Mode as set', 'Suction level as set', 'Mowing %s', 'Cleaning %s', 'Mow %s', 'Clean %s', 'Schedules', 'Schedule off', 'Schedule on', 'on', 'off', 'Overview', 'Consumables', 'Reset %s', 'Yes, reset', 'No', 'Reset after replacing/cleaning', '%s more OK', 'All %s OK', 'less', 'As of %s', '%s sent …', 'sent', 'rejected', 'No connection to Symcon', 'Start', 'Pause', 'Locate'];

    /** Daten für Kachel und eigenständige Seite (JSON). */
    private function kachelDaten(bool $eigen, string $key, string $rueckmeldung = ''): string
    {
        $klasse = $this->klasse();
        $zustand = $this->wert('Zustand');
        $zustand = $zustand === null ? RoboHubModell::ZUSTAND_UNBEKANNT : (int)$zustand;
        $zid = @IPS_GetObjectIDByIdent('Zustand', $this->InstanceID);
        $seit = ($zid !== false && $zustand !== RoboHubModell::ZUSTAND_UNBEKANNT) ? (int)IPS_GetVariable($zid)['VariableChanged'] : 0;
        $roh = (string)($this->wert('ZustandRoh') ?? '');
        $unter = [];
        if ($roh !== '' && strpos($roh, RoboHubModell::zustandsName($zustand, $klasse)) !== 0) {
            $unter[] = preg_replace('/\s*\(\d+\)$/', '', $roh);
        }
        if ($seit > 0) {
            $unter[] = RoboHubText::t('since %s', date(date('Ymd', $seit) === date('Ymd') ? 'H:i' : 'd.m. H:i', $seit));
        }

        $teile = [];
        foreach (self::TEILE as [$pIdent, $hIdent, $name]) {
            $name = RoboHubText::wert($name);
            $p = $this->wert($pIdent);
            if ($p === null) {
                continue;
            }
            $h = $this->wert($hIdent);
            $teile[] = ['id' => $pIdent, 'name' => $name, 'prozent' => (int)$p, 'stunden' => $h === null ? null : round((float)$h, 1)];
        }

        $hinweise = [];
        foreach (['Frischwasser' => '🚰', 'Schmutzwasser' => '🪣', 'Staubbeutel' => '🗑️', 'Reinigungsmittel' => '🧴'] as $ident => $symbol) {
            $v = $this->wert($ident);
            if ($v !== null && $v !== '' && $v !== RoboHubText::t('OK') && $v !== 'In Ordnung') {
                $hinweise[] = $symbol . ' ' . RoboHubText::wert(RoboHubModell::FELDER[$ident][0]) . ': ' . $v;
            }
        }
        $meldung = $this->wert('Meldung');
        if (is_string($meldung) && $meldung !== '') {
            $hinweise[] = 'ℹ️ ' . $meldung;
        }

        $info = [];
        $einst = $this->ReadPropertyBoolean('EinstellungenZeigen');
        foreach ($einst ? ['Reinigungsmodus', 'Saugstufe', 'Wassermenge', 'Durchgaenge', 'Kantenreinigung', 'BehaelterVoll', 'Schnitthoehe', 'Scheinwerfer', 'Maehzeiten', 'Regenverzoegerung', 'Zeitzuschlag'] : [] as $ident) {
            $t = $this->auswahlText($ident);
            if ($t !== null) {
                // Mit Namen davor — „Hoch" oder „An" allein sagt in der Kachel nichts (Forum Thilo 24.10.2026).
                $info[] = $ident === 'Reinigungsmodus' ? $t : RoboHubText::wert(RoboHubModell::FELDER[$ident][0]) . ': ' . $t;
            }
        }
        if ($einst && $this->wert('NichtStoeren') !== null) {
            $info[] = $this->wert('NichtStoeren')
                ? RoboHubText::t('🌙 Do not disturb %s–%s', $this->wert('NichtStoerenVon'), $this->wert('NichtStoerenBis'))
                : RoboHubText::t('🔔 Do not disturb off');
        }
        if ($einst && $this->wert('Lautstaerke') !== null) {
            $info[] = ((int)$this->wert('Lautstaerke') === 0 ? '🔇 ' : '🔊 ') . (int)$this->wert('Lautstaerke') . ' %';
        }
        if ($this->wert('Regensensor') !== null) {
            $info[] = $this->wert('Regensensor') ? RoboHubText::t('🌧️ Rain detected') : RoboHubText::t('☀️ no rain');
        }
        $flaeche = $this->wert('Flaeche');
        if ($flaeche !== null && in_array($zustand, [RoboHubModell::ZUSTAND_REINIGT, RoboHubModell::ZUSTAND_PAUSIERT], true)) {
            // Fläche erst zeigen, wenn der Roboter eine meldet (der 980 meldet sie wohl erst am Ende).
            $info[] = RoboHubText::t('🧹 so far %s%s min', (int)$flaeche > 0 ? $flaeche . ' m² · ' : '', (int)$this->wert('Dauer'));
        }

        $raeume = [];
        $einst = $this->raumEinstellungenZeilen();
        foreach (json_decode((string)$this->ReadAttributeString('Raeume'), true) ?: [] as $id => $name) {
            $raeume[] = ['id' => (int)$id, 'name' => (string)$name, 'info' => isset($einst[(int)$id]) ? (string)preg_replace('/^(\d+\. )?' . preg_quote((string)$name, '/') . ': /u', '', $einst[(int)$id]) : ''];
        }
        $fehler = (string)($this->wert('Fehler') ?? '');
        $abruf = $this->ReadAttributeInteger('LetzterAbruf');

        return (string)json_encode([
            'klasse'      => $klasse,
            'bild'        => $this->ReadAttributeString('Bild'),
            'zustand'     => $zustand,
            'zustandText' => RoboHubModell::zustandsName($zustand, $klasse),
            'unterzeile'  => implode(' · ', $unter),
            'akku'        => $this->wert('Akku') === null ? null : (int)$this->wert('Akku'),
            'laedt'       => (bool)$this->wert('Laedt'),
            'erreichbar'  => $this->wert('Erreichbar') !== false,
            'fehler'      => ($fehler === '' || $fehler === 'kein Fehler' || $fehler === RoboHubText::t('no error')) ? '' : $fehler,
            'hinweise'    => $hinweise,
            'info'        => $info,
            'raeume'      => $raeume,
            'karte'       => $this->kachelKarte(),
            // Auswahl für „Raum reinigen mit Wahl" aus den Treiber-Optionen (herstellerneutral).
            'startWahl'   => $this->startWahl(),
            'zeitplaene'  => array_map(function (array $t): array {
                // Mäh-Pläne nur aus der Tabellenform schaltbar (dort ist der Schaltweg belegt).
                return ['id' => (int)$t['id'], 'an' => (bool)$t['an'], 'text' => $this->zeitplanText($t), 'schaltbar' => $this->zeitplanSchaltbar($t)];
            }, json_decode((string)$this->ReadAttributeString('Zeitplaene'), true) ?: []),
            'teile'       => $teile,
            'stand'       => $abruf > 0 ? date('H:i:s', $abruf) : '',
            'rueck'       => $rueckmeldung,
            'eigen'       => $eigen,
            'farbschema'  => $this->ReadPropertyInteger('Farbschema'),
            'symbole'     => $this->ReadPropertyInteger('Symbole'),
            // Hervorgehobener Knopf: der, der gerade naheliegt (bei Fehlern keiner).
            'hauptknopf'  => $zustand === RoboHubModell::ZUSTAND_REINIGT ? 2 : ($zustand === RoboHubModell::ZUSTAND_FEHLER ? 0 : 1),
            'bedienung'   => $eigen && $key !== '',
            'key'         => $key,
            'hook'        => $this->hookPfad(),
            'texte'       => array_combine(self::KACHEL_TEXTE, array_map([RoboHubText::class, 't'], self::KACHEL_TEXTE)),
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Auswahllisten für den Start mit Wahl: [['wert', 'text'], …] je Modus/Saugstufe; leer = keine Auswahl. */
    private function startWahl(): array
    {
        $optionen = json_decode((string)$this->ReadAttributeString('Optionen'), true) ?: [];
        $liste = function (string $ident) use ($optionen): array {
            $aus = [];
            foreach ((array)($optionen[$ident] ?? []) as $wert => $text) {
                $aus[] = [(int)$wert, (string)RoboHubText::wert((string)$text)];
            }
            return $aus;
        };
        return ['modus' => $liste('Reinigungsmodus'), 'saug' => $liste('Saugstufe')];
    }

    /** Wert einer eigenen Variable oder null, wenn es sie (noch) nicht gibt. */
    private function wert(string $ident)
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        return $id === false ? null : GetValue($id);
    }

    /** Klartext einer Auswahl-Variable (aus ihrer Aufzählung). */
    private function auswahlText(string $ident): ?string
    {
        $wert = $this->wert($ident);
        $optionen = json_decode((string)$this->ReadAttributeString('Optionen'), true) ?: [];
        if ($wert === null || !isset($optionen[$ident])) {
            return null;
        }
        return (string)RoboHubText::wert($optionen[$ident][(int)$wert] ?? $optionen[$ident][(string)(int)$wert] ?? $wert);
    }

    /** WebHook beim WebHook-Control eintragen (Verbund-Muster, z. B. NRGDashboard). */
    private function registriereHook(): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_GUID);
        if (count($ids) === 0) {
            return;
        }
        $hooks = json_decode((string)IPS_GetProperty($ids[0], 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }
        foreach ($hooks as $i => $hook) {
            if (($hook['Hook'] ?? '') === $this->hookPfad()) {
                if ((int)$hook['TargetID'] === $this->InstanceID) {
                    return;
                }
                $hooks[$i]['TargetID'] = $this->InstanceID;
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
                return;
            }
        }
        $hooks[] = ['Hook' => $this->hookPfad(), 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }

    /** ROBO_NeuerWebSchluessel($id): string — macht alte Bedien-Adressen ungültig. */
    public function NeuerWebSchluessel(): string
    {
        $this->WriteAttributeString('WebSchluessel', bin2hex(random_bytes(12)));
        $this->UpdateFormField('WebAdresseBedienung', 'caption', $this->webAdresseZeile());
        return RoboHubText::t('🔑 New key created. The previous control address stops working immediately — enter the new address in IPSView.');
    }

    private function webAdresseZeile(): string
    {
        return RoboHubText::t('🔑 With control: %s?key=%s', $this->hookPfad(), $this->ReadAttributeString('WebSchluessel'));
    }

    private function kachelPanel(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => RoboHubText::t('📱  Tile and IPSView'),
            'items' => [
                ['type' => 'Label', 'caption' => RoboHubText::t('Tile visualization: this instance is a tile itself — just place it on the dashboard. Control always works there (visualization sign-in).')],
                ['type' => 'Select', 'name' => 'Farbschema', 'caption' => RoboHubText::t('Tile colours'), 'options' => [
                    ['caption' => RoboHubText::t('Automatic (detect background, otherwise device setting)'), 'value' => 0],
                    ['caption' => RoboHubText::t('Light'), 'value' => 1],
                    ['caption' => RoboHubText::t('Dark'), 'value' => 2],
                ]],
                ['type' => 'Label', 'caption' => RoboHubText::t('Symcon does not tell a tile whether the visualization is set to light or dark. If the text is hard to read, set "Light" or "Dark" here.')],
                ['type' => 'Select', 'name' => 'Symbole', 'caption' => RoboHubText::t('Icons in the tile'), 'options' => [
                    ['caption' => RoboHubText::t('Symcon icons (like the rest of the visualization)'), 'value' => 0],
                    ['caption' => RoboHubText::t('Emojis (colourful characters of the device)'), 'value' => 1],
                ]],
                ['type' => 'Label', 'caption' => RoboHubText::t('Symcon icons come from the Symcon server and look the same on every device. Emojis need an emoji font on the displaying device (missing e.g. on a Raspberry Pi). If the Symcon icons cannot be loaded, the tile shows emojis by itself.')],
                ['type' => 'CheckBox', 'name' => 'EinstellungenZeigen', 'caption' => RoboHubText::t('Show the robot\'s settings (suction level, passes, do not disturb …) in the tile overview — the instance\'s variables always stay')],
                ['type' => 'CheckBox', 'name' => 'KarteZeigen', 'caption' => RoboHubText::t('Show map image (rooms or mowing zones to tap)')],
                ['type' => 'CheckBox', 'name' => 'LiveKarte', 'caption' => RoboHubText::t('Live map while working (driven path and robot position; vacuums every 30 s, mowers every 60 s)')],
                ['type' => 'Label', 'caption' => RoboHubText::t('IPSView or browser: create a WebView element in IPSView and enter http://<Symcon-IP>:3777%s as the address. The page refreshes itself every 15 seconds.', $this->hookPfad())],
                ['type' => 'Label', 'caption' => RoboHubText::t('👁 Display only: %s', $this->hookPfad())],
                ['type' => 'CheckBox', 'name' => 'WebBedienung', 'caption' => RoboHubText::t('Allow control via the standalone page (only with key in the address)')],
                ['type' => 'Label', 'name' => 'WebAdresseBedienung', 'caption' => $this->webAdresseZeile()],
                ['type' => 'Button', 'caption' => RoboHubText::t('🔑 Create new key'), 'onClick' => 'echo ROBO_NeuerWebSchluessel($id);'],
                ['type' => 'Label', 'caption' => RoboHubText::t('⚠️ The standalone page can be reached without Symcon sign-in. Anyone who knows the address with key can start the robot — do not share the address. Alternatively bind the variables "Command" and "Clean room" directly as switches in IPSView; then the IPSView sign-in applies.')],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Formular
    // -----------------------------------------------------------------

    protected function robohubModulePrefix(): string
    {
        return 'ROBO';
    }

    protected function robohubPurposeLines(): array
    {
        return [
            RoboHubText::t('This instance represents exactly one robot (robot vacuum/mop or lawn mower). It shows state, battery, cleaning or mowing progress and the wear of the consumables, and accepts commands.'),
            RoboHubText::t('The benefit: the robot can be automated like any other device in Symcon — e.g. start as soon as everyone has left the house, or send it back to base when the doorbell rings.'),
        ];
    }

    protected function robohubDocLines(): array
    {
        return [
            RoboHubText::t('This instance is normally created by the RoboHub configurator; it fills in device ID, model and device class itself.'),
            RoboHubText::t('Control: variable "Command" (Start/resume, Pause, Stop, Return to base, Locate), "Clean room" or "Mow zone" as well as suction level, water level etc. — as soon as the vendor provides a selection list, they can be switched directly.'),
            RoboHubText::t('Scripts: ROBO_Starten($id), ROBO_Anhalten($id), ROBO_Stoppen($id), ROBO_ZurStation($id), ROBO_Orten($id), ROBO_RaeumeReinigen($id, "3,5"), ROBO_Aktualisieren($id). All return a plain text.'),
            RoboHubText::t('A command at first only means "sent": whether the robot carries it out is shown by the variable "State" a few seconds later.'),
            RoboHubText::t('Variables are only created when your model actually delivers the value. If something the vendor app shows is missing: click "📋 Show raw data" and send the excerpt as a GitHub issue.'),
        ];
    }

    private function geraetPanel(): array
    {
        $abruf = $this->ReadAttributeInteger('LetzterAbruf');
        $fehler = $this->ReadAttributeString('LetzterFehler');
        if ($this->ReadPropertyString('GeraetID') === '') {
            $zeile = RoboHubText::t('ℹ️ Not assigned to a device yet — easiest to create via the RoboHub configurator.');
        } elseif ($fehler !== '') {
            $zeile = RoboHubText::t('⛔ Last update failed: %s', $fehler);
        } elseif ($abruf > 0) {
            $zeile = RoboHubText::t('✅ Last updated %s.', date('d.m.Y H:i:s', $abruf));
        } else {
            $zeile = RoboHubText::t('ℹ️ Not updated yet.');
        }
        $klasse = $this->klasse() === RoboHubModell::KLASSE_MAEHER ? RoboHubText::t('robotic lawn mower') : 'Saug-/Wischroboter';
        $raeume = json_decode((string)$this->ReadAttributeString('Raeume'), true) ?: [];
        $raumZeile = count($raeume) === 0
            ? RoboHubText::t('ℹ️ No rooms/zones known yet (they appear after the first update if the robot has a map).')
            : RoboHubText::t('🗺️ Rooms/zones (ID: name): %s', implode(', ', array_map(function ($id, $name) {
                return $id . ': ' . $name;
            }, array_keys($raeume), $raeume)));

        return [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => RoboHubText::t('🤖  Device'),
            'items' => [
                ['type' => 'CheckBox', 'name' => 'Deaktiviert', 'caption' => RoboHubText::t('🔌 Deactivate this robot (no polling, no commands; account and other robots keep running)')],
                ['type' => 'Label', 'caption' => RoboHubText::t('🔗 %s%s%s (taken over from the configurator)', $klasse, ($this->ReadPropertyString('Modell') !== '' ? ', Modell ' . $this->ReadPropertyString('Modell') : ''), ($this->ReadPropertyString('GeraetID') !== '' ? RoboHubText::t(', device ID %s', $this->ReadPropertyString('GeraetID')) : ''))],
                $this->kontoZeile(),
                ['type' => 'Label', 'name' => 'AbrufZeile', 'caption' => $zeile],
                ['type' => 'NumberSpinner', 'name' => 'Intervall', 'caption' => RoboHubText::t('Poll every'), 'suffix' => ' s', 'minimum' => 30, 'maximum' => 3600],
                ['type' => 'Label', 'caption' => RoboHubText::t('While the robot is working, RoboHub automatically polls every %s s — unless real-time messages arrive (account instance). Shorter intervals load the vendor cloud, which imposes a lockout period after too many requests.', self::TAKT_AKTIV_S)],
                ['type' => 'Label', 'caption' => $this->ReadAttributeInteger('LetzteEchtzeit') > 0 ? RoboHubText::t('⚡ Last real-time message %s.', date('d.m.Y H:i:s', $this->ReadAttributeInteger('LetzteEchtzeit'))) : RoboHubText::t('ℹ️ No real-time message received yet.')],
                ['type' => 'Label', 'caption' => $raumZeile],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => RoboHubText::t('🔄 Update now'), 'onClick' => 'echo ROBO_Aktualisieren($id);'],
                    ['type' => 'Button', 'caption' => '📍 Orten', 'onClick' => 'echo ROBO_Orten($id);'],
                    ['type' => 'Button', 'caption' => RoboHubText::t('🗺️ Reload rooms'), 'onClick' => 'echo ROBO_RaeumeLaden($id);'],
                    ['type' => 'Button', 'caption' => RoboHubText::t('📋 Show raw data'), 'onClick' => 'echo ROBO_Rohdaten($id);'],
                ]],
            ],
        ];
    }

    private function kontoZeile(): array
    {
        $kid = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($kid > 0) {
            return ['type' => 'Label', 'caption' => RoboHubText::t('🔗 Account: %s (#%s) — assigned automatically via the device ID', IPS_GetName($kid), $kid), 'color' => 0x2E8B3D];
        }
        return ['type' => 'Label', 'caption' => RoboHubText::t('⛔ No account connected — the device ID was not found in any signed-in RoboHub account instance.'), 'color' => 0xFF0000];
    }

    private function raumPanel(): ?array
    {
        $re = json_decode((string)$this->ReadAttributeString('RaumEinstellungen'), true) ?: [];
        $liste = (array)($re['raeume'] ?? []);
        if ($liste === []) {
            return null;
        }
        $namen = json_decode((string)$this->ReadAttributeString('Raeume'), true) ?: [];
        uksort($liste, function ($a, $b) use ($liste): int {
            return ((int)($liste[$a]['reihenfolge'] ?? 0) ?: 999) <=> ((int)($liste[$b]['reihenfolge'] ?? 0) ?: 999) ?: ((int)$a <=> (int)$b);
        });
        $items = [['type' => 'Label', 'caption' => RoboHubText::t('Values per room stored in the robot (from the live map). The vendor app does not show them — its "custom" window only passes mode and suction power when starting. RoboHub can do the same: tile → choose rooms → mode/suction level/passes → start, or ROBO_RaeumeReinigenMit.')]];
        foreach ($liste as $id => $e) {
            $id = (int)$id;
            $nr = (int)($e['reihenfolge'] ?? 0);
            $items[] = ['type' => 'Label', 'caption' => ($this->raumEinstellungenZeilen()[$id] ?? (($nr > 0 ? $nr . '. ' : '') . ($namen[(string)$id] ?? RoboHubText::t('Room %s', $id))))];
        }
        return ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => RoboHubText::t('🏠  Room settings in the robot (%s)', count($liste)), 'items' => $items];
    }

    private function zeitplanPanel(): ?array
    {
        $termine = json_decode((string)$this->ReadAttributeString('Zeitplaene'), true) ?: [];
        if ($termine === []) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => $this->klasse() === RoboHubModell::KLASSE_MAEHER
            ? RoboHubText::t('Mowing plans from the vendor app. Creating and changing is only possible there; here they can be switched on and off while the mower is idle (base or ready). Switching one on may switch off another plan of the same map.')
            : RoboHubText::t('Schedules from the vendor app. Creating and changing is only possible there, here they can be switched on and off.')]];
        foreach ($termine as $t) {
            $items[] = ['type' => 'RowLayout', 'items' => [
                ['type' => 'Label', 'caption' => ($t['an'] ? '✅ ' : '⏸️ ') . $this->zeitplanText($t), 'width' => '520px'],
                ['type' => 'Button', 'caption' => $t['an'] ? RoboHubText::t('Switch off') : RoboHubText::t('Switch on'), 'visible' => $this->zeitplanSchaltbar($t),
                    'onClick' => 'echo ROBO_ZeitplanSchalten($id, ' . (int)$t['id'] . ', ' . ($t['an'] ? 'false' : 'true') . ');'],
            ]];
        }
        return ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => RoboHubText::t('🗓️  Schedules (%s)', count($termine)), 'items' => $items];
    }

    private function verbrauchPanel(): ?array
    {
        $items = [['type' => 'Label', 'caption' => RoboHubText::t('After replacing or cleaning a part, reset its counter here — as in the vendor app. Every reset is recorded in the Symcon log.')]];
        foreach (self::TEILE as [$pIdent, $hIdent, $name]) {
            $name = RoboHubText::wert($name);
            $p = $this->wert($pIdent);
            if ($p === null) {
                continue;
            }
            $items[] = ['type' => 'Button', 'caption' => RoboHubText::t('↺ Reset %s (now %s %%)', $name, (int)$p), 'width' => '420px',
                'confirm' => RoboHubText::t('Really reset %s? This cannot be undone — only after replacing or cleaning.', $name),
                'onClick' => 'echo ROBO_VerbrauchsteilZuruecksetzen($id, ' . var_export($pIdent, true) . ');'];
        }
        if (count($items) === 1) {
            return null;
        }
        return ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => RoboHubText::t('🔧  Reset consumables'), 'items' => $items];
    }

    private function technikPanel(): array
    {
        // GeraetID/Modell/Klasse bleiben Properties (der Konfigurator setzt
        // sie bei der Anlage), sind hier aber nur für Sonderfälle editierbar.
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => RoboHubText::t('🛠️  Assignment (change only if needed)'),
            'items' => [
                ['type' => 'ValidationTextBox', 'name' => 'GeraetID', 'caption' => RoboHubText::t('Device ID at the vendor')],
                ['type' => 'ValidationTextBox', 'name' => 'Modell', 'caption' => RoboHubText::t('Model ID')],
                ['type' => 'Select', 'name' => 'Klasse', 'caption' => RoboHubText::t('Device class'), 'options' => [
                    ['caption' => 'Saug-/Wischroboter', 'value' => RoboHubModell::KLASSE_SAUGER],
                    ['caption' => RoboHubText::t('robotic lawn mower'), 'value' => RoboHubModell::KLASSE_MAEHER],
                ]],
            ],
        ];
    }

    protected function robohubFachpanels(): array
    {
        return [
            'geraet' => $this->geraetPanel(), 'zeitplan' => $this->zeitplanPanel(), 'raum' => $this->raumPanel(),
            'verbrauch' => $this->verbrauchPanel(), 'kachel' => $this->kachelPanel(), 'technik' => $this->technikPanel(),
        ];
    }

    public function GetConfigurationForm()
    {
        $status = [
            ['code' => 102, 'icon' => 'active', 'caption' => RoboHubText::t('Connected')],
            ['code' => 104, 'icon' => 'inactive', 'caption' => RoboHubText::t('Inactive — deactivated or not yet assigned to a device')],
            ['code' => 201, 'icon' => 'error', 'caption' => RoboHubText::t('Account must be signed in again')],
            ['code' => 202, 'icon' => 'inactive', 'caption' => RoboHubText::t('Update failed — will be retried automatically')],
        ];
        return $this->robohubBuildForm($this->robohubFachpanels(), [], $status);
    }
}
