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

    private const DATA_ZUM_KONTO = '{CFA82D8B-B9F1-4CAF-BFB7-E898E3690581}';

    // Während einer Reinigung schneller nachsehen als im Ruhezustand.
    private const TAKT_AKTIV_S = 30;

    // Raumliste ändert sich selten (Karte), deshalb nur alle 6 h neu laden.
    private const RAEUME_ALTER_S = 6 * 3600;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('GeraetID', '');
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
        // Live-Karte: während der Arbeit aktuelle Karte mit Weg und Roboter holen.
        $this->RegisterPropertyBoolean('LiveKarte', true);
        $this->RegisterAttributeBoolean('LiveAktiv', false);
        $this->RegisterAttributeString('Bild', '');
        $this->RegisterAttributeInteger('LetzteEchtzeit', 0);
        $this->RegisterAttributeString('EchtzeitVerlauf', '[]');
        $this->RegisterAttributeInteger('RaeumeStand', 0);
        $this->RegisterAttributeString('Optionen', '{}');
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
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $klasse = $this->klasse();
        $this->MaintainVariable('Erreichbar', 'Erreichbar', VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 1, true);
        $this->MaintainVariable('Zustand', 'Zustand', VARIABLETYPE_INTEGER, $this->presEnum($this->zustandsOptionen($klasse)), 10, true);
        $this->MaintainVariable('Befehl', 'Befehl', VARIABLETYPE_INTEGER, $this->presEnum($this->befehlsOptionen($klasse)), 5, true);
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
        $this->ordneKontoZu();
        $this->registriereHook();
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
                // Aus der Kachel: mehrere Räume als „3,5".
                $this->fuehreAus('raum', ['raeume' => array_values(array_filter(array_map('intval', explode(',', (string)$Value))))]);
                return;
            default:
                if (in_array($Ident, $this->schreibbar(), true)) {
                    $wert = $this->einstellungPruefen($Ident, $Value);
                    if ($wert !== null && $this->fuehreAus('einstellung', ['feld' => $Ident, 'wert' => $wert])) {
                        $this->SetValue($Ident, $wert);
                    }
                    return;
                }
                $optionen = json_decode($this->ReadAttributeString('Optionen'), true) ?: [];
                if (isset($optionen[$Ident])) {
                    if ($this->fuehreAus('setze', ['feld' => $Ident, 'wert' => (int)$Value])) {
                        $this->SetValue($Ident, (int)$Value);
                    }
                    return;
                }
        }
        throw new Exception('Unbekannte Aktion: ' . $Ident);
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
        if (!is_array($d) || (string)($d['Geraet'] ?? '') !== $this->ReadPropertyString('GeraetID') || !is_array($d['Daten'] ?? null)) {
            return '';
        }
        $this->uebernimm($d['Daten']);
        $this->WriteAttributeInteger('LetzteEchtzeit', time());
        // Verlauf der letzten 20 Meldungen (Rohwerte) — zur Fehlersuche in „📋 Rohdaten".
        // Reine Positionsmeldungen (Kartenrahmen, alle paar Sekunden) nicht.
        $delta = (array)($d['Daten']['echtzeit'] ?? []);
        if ($delta !== []) {
            $verlauf = json_decode($this->ReadAttributeString('EchtzeitVerlauf'), true) ?: [];
            array_unshift($verlauf, ['zeit' => date('d.m.Y H:i:s'), 'werte' => $delta]);
            $this->WriteAttributeString('EchtzeitVerlauf', (string)json_encode(array_slice($verlauf, 0, 20)));
            $this->SendDebug('Echtzeit', json_encode($delta), 0);
        }
        if (is_array($d['Daten']['position'] ?? null)) {
            $this->positionUebernehmen($d['Daten']['position']);
        }
        $this->liveSteuern();
        $this->kachelAktualisieren();
        return '';
    }

    /** ROBO_Aktualisieren($id): string — Zustand sofort abrufen. */
    public function Aktualisieren(): string
    {
        if (!$this->HasActiveParent()) {
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
        return '✅ Zustand abgerufen: ' . RoboHubModell::zustandsName((int)$this->GetValue('Zustand'), $this->klasse())
            . ($this->hatVariable('Akku') ? ', Akku ' . $this->GetValue('Akku') . ' %' : '') . '.';
    }

    /** ROBO_Starten($id): string */
    public function Starten(): string
    {
        return $this->befehlText('start', []);
    }

    /** ROBO_Anhalten($id): string */
    public function Anhalten(): string
    {
        return $this->befehlText('pause', []);
    }

    /** ROBO_Stoppen($id): string */
    public function Stoppen(): string
    {
        return $this->befehlText('stopp', []);
    }

    /** ROBO_ZurStation($id): string */
    public function ZurStation(): string
    {
        return $this->befehlText('station', []);
    }

    /** ROBO_Orten($id): string */
    public function Orten(): string
    {
        return $this->befehlText('orten', []);
    }

    /**
     * ROBO_RaeumeReinigen($id, string $raumIDs): string — Raum-IDs als
     * kommagetrennte Liste („3,5"); IDs zeigt das Formular bzw. die Variable
     * „Raum reinigen".
     */
    public function RaeumeReinigen(string $raumIDs): string
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', $raumIDs))));
        if (count($ids) === 0) {
            return '⛔ Keine Raum-ID angegeben (z. B. "3,5").';
        }
        return $this->befehlText('raum', ['raeume' => $ids]);
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
                $name = $anzeige;
            }
        }
        if ($name === null || $this->wert($teil) === null) {
            return '⛔ Unbekanntes oder bei diesem Gerät nicht vorhandenes Verbrauchsteil: ' . $teil;
        }
        $vorher = (int)$this->wert($teil);
        $antwort = $this->frageKonto(['Aktion' => 'zuruecksetzen', 'Geraet' => $this->ReadPropertyString('GeraetID'), 'Teil' => $teil]);
        if (!$antwort['ok']) {
            $this->protokolliere($name . ' zurücksetzen fehlgeschlagen: ' . $antwort['fehler']);
            return '⛔ ' . $antwort['fehler'];
        }
        // Dauerhaft festhalten — so ist später nachvollziehbar, wann gewechselt wurde.
        $this->protokolliere($name . ' zurückgesetzt (vorher ' . $vorher . ' %).');
        $this->Aktualisieren();
        $nachher = $this->wert($teil);
        return '✅ ' . $name . ' zurückgesetzt (vorher ' . $vorher . ' %' . ($nachher !== null ? ', jetzt ' . (int)$nachher . ' %' : '') . ').';
    }

    /** ROBO_ZeitplanSchalten($id, int $termin, bool $an): string — Zeitplan der Hersteller-App ein-/ausschalten. */
    public function ZeitplanSchalten(int $termin, bool $an): string
    {
        $bekannt = null;
        foreach (json_decode($this->ReadAttributeString('Zeitplaene'), true) ?: [] as $t) {
            if ((int)($t['id'] ?? -1) === $termin) {
                $bekannt = $t;
            }
        }
        if ($bekannt === null) {
            return '⛔ Unbekannter Zeitplan: ' . $termin;
        }
        if (($bekannt['art'] ?? '') !== 'maeher') {
            return $this->befehlText('zeitplan', ['id' => $termin, 'an' => $an]);
        }
        if (!isset($bekannt['tabelle'])) {
            return '⛔ Dieser Mäh-Plan lässt sich (noch) nicht schalten.';
        }
        $text = $this->befehlText('zeitplan', ['tabelle' => (int)$bekannt['tabelle'], 'an' => $an]);
        if (strpos($text, '⛔') !== 0) {
            // Der Mäher hat bestätigt; Pläne neu lesen (Nachbarplan kann sich mitgeschaltet haben).
            $this->protokolliere('Mäh-Plan ' . ((int)($bekannt['nummer'] ?? $termin) + 1) . ' ' . ($an ? 'eingeschaltet' : 'ausgeschaltet') . '.');
            $this->ladeRaeume();
        }
        return $text;
    }

    /**
     * ROBO_RaumEinstellungSetzen($id, int $raum, string $feld, int $wert): string — Einstellung je Raum
     * ändern wie in der App. $feld: „Saugstufe" (0 Leise … 3 Turbo), „Durchgaenge" (1–3),
     * „Modus" (0 Saugen, 1 Wischen, 2 Saugen und Wischen, 3 Erst saugen, dann wischen).
     */
    public function RaumEinstellungSetzen(int $raum, string $feld, int $wert): string
    {
        $re = json_decode($this->ReadAttributeString('RaumEinstellungen'), true) ?: [];
        if (!isset($re['raeume'][(string)$raum]) && !isset($re['raeume'][$raum])) {
            return '⛔ Für Raum ' . $raum . ' sind keine Einstellungen bekannt (erst „Live-Karte laden").';
        }
        $text = $this->befehlText('raumeinstellung', ['raum' => $raum, 'feld' => $feld, 'wert' => $wert]);
        if (strpos($text, '⛔') !== 0) {
            $namen = json_decode($this->ReadAttributeString('Raeume'), true) ?: [];
            $this->protokolliere('Raum-Einstellung ' . ($namen[(string)$raum] ?? 'Raum ' . $raum) . ': ' . $feld . ' = ' . $wert . '.');
            // Was der Roboter übernommen hat, zeigt erst der neue Kartenrahmen — direkt
            // nach dem Schreiben liefert Barny ein paar Sekunden keinen (Live-Befund).
            if (strpos($this->LiveKarteLaden(), '⛔') === 0) {
                IPS_Sleep(4000);
                $this->LiveKarteLaden();
            }
        }
        return $text;
    }

    /** ROBO_Rohdaten($id): string — letzte Rohantwort des Herstellers (zur Fehlersuche). */
    public function Rohdaten(): string
    {
        $roh = $this->ReadAttributeString('LetzteRohdaten');
        if ($roh === '') {
            return 'ℹ️ Noch keine Rohdaten — erst einmal „Jetzt aktualisieren".';
        }
        $daten = json_decode($roh, true);
        $verlauf = json_decode($this->ReadAttributeString('EchtzeitVerlauf'), true) ?: [];
        if (!is_array($daten) || (count($verlauf) === 0 && $this->ReadAttributeString('ZeitplanRoh') === '' && $this->ReadAttributeString('RaumEinstellungen') === '{}')) {
            return $roh;
        }
        $daten['echtzeitVerlauf'] = $verlauf;   // neueste zuerst, bleibt gültiges JSON
        if ($this->ReadAttributeString('ZeitplanRoh') !== '') {
            $daten['zeitplanRoh'] = $this->ReadAttributeString('ZeitplanRoh');
        }
        $quellen = json_decode($this->ReadAttributeString('RaumEinstellungenQuellen'), true) ?: [];
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
            $this->protokolliere('Verbinden mit Konto #' . $ziel . ' fehlgeschlagen.');
            return '⛔ Verbinden mit Konto #' . $ziel . ' fehlgeschlagen.';
        }
        $text = '🔗 Mit Konto „' . IPS_GetName($ziel) . '" (#' . $ziel . ') verbunden — dort ist dieses Gerät registriert.';
        $this->protokolliere($text);
        return $text;
    }

    private function befehlText(string $befehl, array $parameter): string
    {
        $antwort = $this->frageKonto(['Aktion' => 'befehl', 'Geraet' => $this->ReadPropertyString('GeraetID'), 'Befehl' => $befehl, 'Parameter' => $parameter]);
        if (!$antwort['ok']) {
            $this->protokolliere('Befehl „' . $befehl . '" fehlgeschlagen: ' . $antwort['fehler']);
            return '⛔ ' . $antwort['fehler'];
        }
        // Gesendet ist nicht ausgeführt: Zustand gleich nachziehen und
        // danach zügiger nachsehen (Takt richtet sich nach dem Zustand).
        $this->SetTimerInterval('Abruf', 5000);
        return '📤 ' . (string)$antwort['daten'] . ' Der Zustand aktualisiert sich in wenigen Sekunden.';
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

    /** Sendet an die Konto-Instanz; liefert immer ['ok' => bool, ...]. */
    protected function frageKonto(array $anfrage): array
    {
        if (!$this->HasActiveParent()) {
            return ['ok' => false, 'fehler' => 'Keine aktive RoboHub-Konto-Instanz verbunden — bitte unter „Übergeordnete Instanz" ein angemeldetes Konto wählen.'];
        }
        $anfrage['DataID'] = self::DATA_ZUM_KONTO;
        try {
            $roh = $this->SendDataToParent((string)json_encode($anfrage));
        } catch (Throwable $e) {
            return ['ok' => false, 'fehler' => 'Konto-Instanz nicht erreichbar: ' . $e->getMessage()];
        }
        $antwort = json_decode((string)$roh, true);
        if (!is_array($antwort) || !isset($antwort['ok'])) {
            return ['ok' => false, 'fehler' => 'Unerwartete Antwort der Konto-Instanz.'];
        }
        return $antwort;
    }

    /** Einstellungen je Raum aus Karte/Live-Karte merken; Variable nach dem Laden der Raumnamen. */
    private function raumEinstellungenMerken(array $d, string $quelle = ''): void
    {
        if (!isset($d['raumEinstellungen']) || !is_array($d['raumEinstellungen'])) {
            return;
        }
        if ($quelle !== '') {
            // Beide Quellen getrennt für „📋 Rohdaten" (gespeicherte Karte vs. Live-Rahmen).
            $q = json_decode($this->ReadAttributeString('RaumEinstellungenQuellen'), true) ?: [];
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
        $re = json_decode($this->ReadAttributeString('RaumEinstellungen'), true) ?: [];
        $kopf = ($re['an'] ?? null) === false ? "⏸️ In der App ausgeschaltet — es gelten die allgemeinen Einstellungen.\n" : '';
        $this->MaintainVariable('RaumEinstellungen', 'Einstellungen je Raum', VARIABLETYPE_STRING, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 96, true);
        $this->SetValue('RaumEinstellungen', $kopf . implode("\n", $zeilen));
    }

    /** „Küche: Standard · 1× · Saugen und Wischen · Wasser Mittel" je Raum. */
    private function raumEinstellungenZeilen(): array
    {
        $re = json_decode($this->ReadAttributeString('RaumEinstellungen'), true) ?: [];
        $namen = json_decode($this->ReadAttributeString('Raeume'), true) ?: [];
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
            if (empty($e['modus']) || strpos((string)$e['modus'], 'Wischen') !== false || strpos((string)$e['modus'], 'wischen') !== false) {
                $teile[] = (strpos((string)$e['wasser'], 'Feuchte') === 0 ? '' : 'Wasser ') . $e['wasser'];
            }
            $nr = (int)($e['reihenfolge'] ?? 0);
            $zeilen[(int)$id] = ($nr > 0 ? $nr . '. ' : '') . ($namen[(string)$id] ?? $namen[(int)$id] ?? 'Raum ' . $id) . ': ' . implode(' · ', $teile);
        }
        return $zeilen;
    }

    private function zeitplanSchaltbar(array $t): bool
    {
        if (!empty($t['ungueltig'])) {
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
        $this->MaintainVariable('Zeitplaene', 'Zeitpläne', VARIABLETYPE_STRING, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 95, true);
        $this->SetValue('Zeitplaene', $zeilen === [] ? 'keine' : implode("\n", $zeilen));
    }

    /** Mäh-Plan: „Plan Sommer · Mo, Mi 09:00–12:00 · alles; Sa 10:00–11:00 · Rasen". */
    private function maehplanText(array $t): string
    {
        $namen = json_decode($this->ReadAttributeString('Raeume'), true) ?: [];
        $gruppen = [];
        foreach ((array)($t['eintraege'] ?? []) as $e) {
            $was = $e['art'] === 'zonen'
                ? implode(', ', array_map(function ($z) use ($namen): string { return (string)($namen[(string)$z] ?? $namen[(int)$z] ?? 'Zone ' . $z); }, (array)$e['zonen']))
                : ['alle' => 'alles', 'kante' => 'Kante', 'patrouille' => 'Patrouille'][$e['art']] ?? $e['art'];
            $schluessel = $e['von'] . ($e['bis'] !== null ? '–' . $e['bis'] : '') . ' · ' . $was;
            $gruppen[$schluessel][] = $e['tag'];
        }
        $teile = [];
        foreach ($gruppen as $wann => $tage) {
            $tage = array_values(array_unique($tage));
            $tageText = count($tage) === 7 ? 'täglich' : ($tage === ['Mo', 'Di', 'Mi', 'Do', 'Fr'] ? 'werktags' : implode(', ', $tage));
            $teile[] = $tageText . ' ' . $wann;
        }
        // Ohne Namen wie in der App gezählt: erster Plan = „Plan 1".
        $kopf = trim((string)($t['name'] ?? '')) !== '' ? 'Plan „' . $t['name'] . '"' : 'Plan ' . ((int)($t['nummer'] ?? $t['id']) + 1)
            . ((int)($t['platz'] ?? 0) > 0 ? ' (Karte ' . ((int)$t['platz'] + 1) . ')' : '');
        return $kopf . ($teile === [] ? ' · ohne Termine' : ' · ' . implode('; ', $teile));
    }

    /** Ein Termin in Klartext, mit Raumnamen aus der Karte: „07:00 · Mo, Mi, Fr · Vorrat, Küche · Saugen". */
    private function zeitplanText(array $t): string
    {
        if (($t['art'] ?? '') === 'maeher') {
            return $this->maehplanText($t);
        }
        $tage = (array)($t['tage'] ?? []);
        if (!empty($t['einmalig'])) {
            $wann = 'einmalig';
        } elseif (count($tage) === 7) {
            $wann = 'täglich';
        } elseif ($tage === ['Mo', 'Di', 'Mi', 'Do', 'Fr']) {
            $wann = 'werktags';
        } else {
            $wann = $tage === [] ? 'nie' : implode(', ', $tage);
        }
        $teile = [(string)($t['zeit'] ?? '?'), $wann];
        $namen = json_decode($this->ReadAttributeString('Raeume'), true) ?: [];
        switch ($t['art'] ?? '') {
            case 'raeume':
                $teile[] = implode(', ', array_map(function (array $r) use ($namen): string {
                    return (string)($namen[(string)$r['id']] ?? $namen[(int)$r['id']] ?? 'Raum ' . $r['id']);
                }, (array)($t['raeume'] ?? [])));
                $modi = array_values(array_unique(array_filter(array_column((array)($t['raeume'] ?? []), 'modus'))));
                if (count($modi) === 1) {
                    $teile[] = $modi[0];
                }
                break;
            case 'alle':
                $teile[] = 'alle Räume';
                if (!empty($t['modus'])) {
                    $teile[] = $t['modus'];
                }
                break;
            case 'kurzbefehl':
                $teile[] = 'Kurzbefehl ' . (int)($t['kurzbefehl'] ?? 0);
                break;
        }
        if (!empty($t['ungueltig'])) {
            $teile[] = '⚠️ ungültig';
        }
        return implode(' · ', $teile);
    }

    /** Kartendaten für die Kachel (ohne Umrechnungsdaten). */
    private function kachelKarte(): ?array
    {
        if (!$this->ReadPropertyBoolean('KarteZeigen')) {
            return null;
        }
        $karte = json_decode($this->ReadAttributeString('Karte'), true);
        if (!is_array($karte) || (string)($karte['bild'] ?? '') === '') {
            return null;
        }
        unset($karte['geometrie']);
        return $karte;
    }

    /** Idents, die der Treiber als frei änderbar gemeldet hat (Nicht stören, Lautstärke). */
    private function schreibbar(): array
    {
        return json_decode($this->ReadAttributeString('Schreibbar'), true) ?: [];
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
                $fehler = 'Lautstärke muss zwischen 0 und 100 % liegen.';
            }
        } elseif ($ident === 'NichtStoeren') {
            $wert = (bool)$wert;
        } else {
            $wert = trim((string)$wert);
            if (!RoboHubModell::istUhrzeit($wert)) {
                $fehler = 'Uhrzeit bitte als HH:MM angeben (z. B. 22:00), nicht „' . $wert . '".';
            }
        }
        if ($fehler !== null) {
            $this->protokolliere($fehler);
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
                $this->MaintainVariable($ident, $name, VARIABLETYPE_INTEGER, $this->presEnum($optionen[$ident]), $pos, true);
                $this->EnableAction($ident);
                $this->SetValue($ident, (int)$werte[$ident]);
                continue;
            }
            if (in_array($ident, $schreibbar, true)) {
                $this->MaintainVariable($ident, $name, $typ, $this->presSchreibbar($typ, $suffix), $pos, true);
                $this->EnableAction($ident);
                $this->SetValue($ident, $this->typisiere($typ, $werte[$ident]));
                continue;
            }
            $pres = $typ === VARIABLETYPE_BOOLEAN
                ? ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION]
                : ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => $suffix, 'DIGITS' => $stellen];
            $this->MaintainVariable($ident, $name, $typ, $pres, $pos, true);
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
            'Raeume' => json_decode($this->ReadAttributeString('Raeume'), true) ?: []]);
        if (!$antwort['ok']) {
            $this->SendDebug('Live-Karte', (string)$antwort['fehler'], 0);
            return '⛔ ' . $antwort['fehler'];
        }
        $d = (array)$antwort['daten'];
        $this->raumEinstellungenMerken($d, 'liveKarte');
        $this->raumEinstellungenVariable();
        if ((string)($d['bild'] ?? '') === '') {
            return 'ℹ️ Keine aktuelle Karte geliefert.';
        }
        $this->WriteAttributeString('Karte', (string)json_encode([
            'bild' => (string)$d['bild'], 'marken' => array_values((array)($d['marken'] ?? [])),
            'station' => $d['station'] ?? null, 'format' => (float)($d['format'] ?? 1.0),
            'geometrie' => $d['geometrie'] ?? null, 'weg' => array_values((array)($d['weg'] ?? [])),
            'roboter' => $d['roboter'] ?? null, 'live' => time(),
        ]));
        $this->kachelAktualisieren();
        $punkte = 0;
        foreach ((array)($d['weg'] ?? []) as $s) {
            $punkte += count((array)$s);
        }
        return '✅ Live-Karte geladen: ' . $punkte . ' Wegpunkte' . (isset($d['roboter']) ? ', Roboter auf der Karte.' : '.');
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
        $karte = json_decode($this->ReadAttributeString('Karte'), true) ?: [];
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
            return 'ℹ️ Keine Räume/Zonen gefunden — der Roboter hat noch keine gespeicherte Karte, oder sein Kartenformat ist noch nicht bekannt (Debug „Karte").';
        }
        $alt = json_decode($this->ReadAttributeString('Raeume'), true) ?: [];
        if ($alt != $raeume || !$this->hatVariable('Raum')) {
            $this->WriteAttributeString('Raeume', (string)json_encode($raeume));
            $this->MaintainVariable('Raum', $this->klasse() === RoboHubModell::KLASSE_MAEHER ? 'Zone mähen' : 'Raum reinigen', VARIABLETYPE_INTEGER, $this->presEnum($raeume), 6, true);
            $this->EnableAction('Raum');
        }
        // Nach den Raum-/Zonennamen, damit die Pläne schon die neuen Namen tragen.
        if (isset($daten['zeitplaene']) && is_array($daten['zeitplaene'])) {
            $this->zeitplaeneUebernehmen($daten['zeitplaene']);
        }
        $this->raumEinstellungenVariable();
        $this->kachelAktualisieren();
        return '✅ ' . count($raeume) . ' Räume/Zonen: ' . implode(', ', $raeume) . '.';
    }

    private function typisiere(int $typ, $wert)
    {
        switch ($typ) {
            case VARIABLETYPE_BOOLEAN: return (bool)$wert;
            case VARIABLETYPE_INTEGER: return (int)round((float)$wert);
            case VARIABLETYPE_FLOAT:   return (float)$wert;
            default:                   return (string)$wert;
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
            throw new Exception('Unbekannter Befehl: ' . $wert);
        }
        return $namen[$wert];
    }

    private function presEnum(array $map): array
    {
        $options = [];
        foreach ($map as $wert => $caption) {
            $options[] = ['Value' => (int)$wert, 'Caption' => (string)$caption, 'IconActive' => false, 'Icon' => '', 'Color' => -1];
        }
        return ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($options)];
    }

    private function hatVariable(string $ident): bool
    {
        return @IPS_GetObjectIDByIdent($ident, $this->InstanceID) !== false;
    }

    private function protokolliere(string $text): void
    {
        IPS_LogMessage('RoboHub Roboter #' . $this->InstanceID, $text);
    }

    // -----------------------------------------------------------------
    // Kachel (Kachel-Visualisierung) und eigenständige Seite (IPSView)
    // -----------------------------------------------------------------

    private const WEBHOOK_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    // Verbrauchsteile für die Kachel: [Prozent-Ident, Stunden-Ident, Name].
    private const TEILE = [
        ['Hauptbuerste', 'HauptbuersteRest', 'Hauptbürste'],
        ['Seitenbuerste', 'SeitenbuersteRest', 'Seitenbürste'],
        ['Filter', 'FilterRest', 'Filter'],
        ['Mopp', 'MoppRest', 'Mopp/Walze'],
        ['Sensoren', 'SensorenRest', 'Sensoren'],
        ['Abwasserfilter', 'AbwasserfilterRest', 'Abwasserauslassfilter'],
        ['Schmutzwasserbehaelter', 'SchmutzwasserbehaelterRest', 'Schmutzwasserbehälter'],
        ['Messer', 'MesserRest', 'Klingen'],
        ['Reinigungsbuerste', 'ReinigungsbuersteRest', 'Reinigungsbürste'],
        ['Wartung', 'WartungRest', 'Wartung des Roboters'],
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
                echo json_encode(['ok' => false, 'text' => '🔒 Bedienung über diese Seite ist nicht freigegeben.']);
                return;
            }
            $cmd = (string)$_GET['cmd'];
            $wert = (string)($_GET['wert'] ?? '');
            if (!in_array($cmd, ['Befehl', 'Raum', 'Raeume', 'Zuruecksetzen', 'Zeitplan'], true)) {
                echo json_encode(['ok' => false, 'text' => '⛔ Unbekannter Befehl.']);
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
                    $text = $this->RaeumeReinigen($wert);
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
            $unter[] = 'seit ' . date(date('Ymd', $seit) === date('Ymd') ? 'H:i' : 'd.m. H:i', $seit) . ' Uhr';
        }

        $teile = [];
        foreach (self::TEILE as [$pIdent, $hIdent, $name]) {
            $p = $this->wert($pIdent);
            if ($p === null) {
                continue;
            }
            $h = $this->wert($hIdent);
            $teile[] = ['id' => $pIdent, 'name' => $name, 'prozent' => (int)$p, 'stunden' => $h === null ? null : round((float)$h, 1)];
        }

        $hinweise = [];
        foreach (['Frischwasser' => '🚰 Frischwasser', 'Schmutzwasser' => '🪣 Schmutzwasser', 'Staubbeutel' => '🗑️ Staubbeutel', 'Reinigungsmittel' => '🧴 Reinigungsmittel'] as $ident => $name) {
            $v = $this->wert($ident);
            if ($v !== null && $v !== '' && $v !== 'In Ordnung') {
                $hinweise[] = $name . ': ' . $v;
            }
        }
        $meldung = $this->wert('Meldung');
        if (is_string($meldung) && $meldung !== '') {
            $hinweise[] = 'ℹ️ ' . $meldung;
        }

        $info = [];
        foreach (['Reinigungsmodus', 'Saugstufe', 'Wassermenge'] as $ident) {
            $t = $this->auswahlText($ident);
            if ($t !== null) {
                $info[] = $t;
            }
        }
        if ($this->wert('NichtStoeren') !== null) {
            $info[] = $this->wert('NichtStoeren')
                ? '🌙 Nicht stören ' . $this->wert('NichtStoerenVon') . '–' . $this->wert('NichtStoerenBis')
                : '🔔 Nicht stören aus';
        }
        if ($this->wert('Lautstaerke') !== null) {
            $info[] = ((int)$this->wert('Lautstaerke') === 0 ? '🔇 ' : '🔊 ') . (int)$this->wert('Lautstaerke') . ' %';
        }
        if ($this->wert('Regensensor') !== null) {
            $info[] = $this->wert('Regensensor') ? '🌧️ Regen erkannt' : '☀️ kein Regen';
        }
        $flaeche = $this->wert('Flaeche');
        if ($flaeche !== null && in_array($zustand, [RoboHubModell::ZUSTAND_REINIGT, RoboHubModell::ZUSTAND_PAUSIERT], true)) {
            $info[] = $flaeche . ' m² · ' . (int)$this->wert('Dauer') . ' min';
        }

        $raeume = [];
        $einst = $this->raumEinstellungenZeilen();
        foreach (json_decode($this->ReadAttributeString('Raeume'), true) ?: [] as $id => $name) {
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
            'fehler'      => ($fehler === '' || $fehler === 'kein Fehler') ? '' : $fehler,
            'hinweise'    => $hinweise,
            'info'        => $info,
            'raeume'      => $raeume,
            'karte'       => $this->kachelKarte(),
            'zeitplaene'  => array_map(function (array $t): array {
                // Mäh-Pläne nur aus der Tabellenform schaltbar (dort ist der Schaltweg belegt).
                return ['id' => (int)$t['id'], 'an' => (bool)$t['an'], 'text' => $this->zeitplanText($t), 'schaltbar' => $this->zeitplanSchaltbar($t)];
            }, json_decode($this->ReadAttributeString('Zeitplaene'), true) ?: []),
            'teile'       => $teile,
            'stand'       => $abruf > 0 ? date('H:i:s', $abruf) : '',
            'rueck'       => $rueckmeldung,
            'eigen'       => $eigen,
            'bedienung'   => $eigen && $key !== '',
            'key'         => $key,
            'hook'        => $this->hookPfad(),
        ], JSON_UNESCAPED_UNICODE);
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
        $optionen = json_decode($this->ReadAttributeString('Optionen'), true) ?: [];
        if ($wert === null || !isset($optionen[$ident])) {
            return null;
        }
        return (string)($optionen[$ident][(int)$wert] ?? $optionen[$ident][(string)(int)$wert] ?? $wert);
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
        return '🔑 Neuer Schlüssel erzeugt. Die bisherige Bedien-Adresse funktioniert ab sofort nicht mehr — in IPSView die neue Adresse eintragen.';
    }

    private function webAdresseZeile(): string
    {
        return '🔑 Mit Bedienung: ' . $this->hookPfad() . '?key=' . $this->ReadAttributeString('WebSchluessel');
    }

    private function kachelPanel(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '📱  Kachel und IPSView',
            'items' => [
                ['type' => 'Label', 'caption' => 'Kachel-Visualisierung: Diese Instanz ist selbst eine Kachel — einfach auf dem Dashboard platzieren. Bedienen geht dort immer (Anmeldung der Visualisierung).'],
                ['type' => 'CheckBox', 'name' => 'KarteZeigen', 'caption' => 'Kartenbild zeigen (Räume bzw. Mähzonen zum Antippen)'],
                ['type' => 'CheckBox', 'name' => 'LiveKarte', 'caption' => 'Live-Karte während der Arbeit (gefahrener Weg und Roboterposition; Sauger alle 30 s, Mäher alle 60 s)'],
                ['type' => 'Label', 'caption' => 'IPSView oder Browser: In IPSView ein WebView-Element anlegen und als Adresse http://<Symcon-IP>:3777' . $this->hookPfad() . ' eintragen. Die Seite aktualisiert sich alle 15 Sekunden selbst.'],
                ['type' => 'Label', 'caption' => '👁 Nur Anzeige: ' . $this->hookPfad()],
                ['type' => 'CheckBox', 'name' => 'WebBedienung', 'caption' => 'Bedienung über die eigenständige Seite erlauben (nur mit Schlüssel in der Adresse)'],
                ['type' => 'Label', 'name' => 'WebAdresseBedienung', 'caption' => $this->webAdresseZeile()],
                ['type' => 'Button', 'caption' => '🔑 Neuen Schlüssel erzeugen', 'onClick' => 'echo ROBO_NeuerWebSchluessel($id);'],
                ['type' => 'Label', 'caption' => '⚠️ Die eigenständige Seite ist ohne Symcon-Anmeldung erreichbar. Wer die Adresse mit Schlüssel kennt, kann den Roboter starten — Adresse nicht weitergeben. Alternativ in IPSView die Variablen „Befehl" und „Raum reinigen" direkt als Schalter binden; dann gilt die IPSView-Anmeldung.'],
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
            'Diese Instanz steht für genau einen Roboter (Saug-/Wischroboter oder Rasenmäher). Sie zeigt Zustand, Akku, Reinigungs- bzw. Mähfortschritt und den Verschleiß der Verbrauchsteile und nimmt Befehle an.',
            'Der Nutzen: Der Roboter lässt sich wie jedes andere Gerät in Symcon automatisieren — z. B. starten, sobald alle das Haus verlassen haben, oder zur Station schicken, wenn es klingelt.',
        ];
    }

    protected function robohubDocLines(): array
    {
        return [
            'Angelegt wird diese Instanz normalerweise vom RoboHub-Konfigurator; Geräte-ID, Modell und Geräteklasse trägt er selbst ein.',
            'Bedienen: Variable „Befehl" (Start/Fortsetzen, Pause, Stopp, Zur Station, Orten), „Raum reinigen" bzw. „Zone mähen" sowie Saugstufe, Wassermenge usw. — sobald der Hersteller eine Auswahlliste liefert, sind sie direkt schaltbar.',
            'Skripte: ROBO_Starten($id), ROBO_Anhalten($id), ROBO_Stoppen($id), ROBO_ZurStation($id), ROBO_Orten($id), ROBO_RaeumeReinigen($id, "3,5"), ROBO_Aktualisieren($id). Alle liefern einen Klartext zurück.',
            'Ein Befehl heißt zunächst nur „gesendet": Ob der Roboter ihn ausführt, zeigt die Variable „Zustand" wenige Sekunden später.',
            'Variablen entstehen erst, wenn dein Modell den Wert tatsächlich liefert. Fehlt etwas, das die Hersteller-App zeigt: „📋 Rohdaten anzeigen" klicken und den Auszug als GitHub-Issue schicken.',
        ];
    }

    private function geraetPanel(): array
    {
        $abruf = $this->ReadAttributeInteger('LetzterAbruf');
        $fehler = $this->ReadAttributeString('LetzterFehler');
        if ($this->ReadPropertyString('GeraetID') === '') {
            $zeile = 'ℹ️ Noch keinem Gerät zugeordnet — am einfachsten über den RoboHub-Konfigurator anlegen.';
        } elseif ($fehler !== '') {
            $zeile = '⛔ Letzter Abruf fehlgeschlagen: ' . $fehler;
        } elseif ($abruf > 0) {
            $zeile = '✅ Zuletzt abgerufen ' . date('d.m.Y H:i:s', $abruf) . ' Uhr.';
        } else {
            $zeile = 'ℹ️ Noch nicht abgerufen.';
        }
        $klasse = $this->klasse() === RoboHubModell::KLASSE_MAEHER ? 'Rasenmähroboter' : 'Saug-/Wischroboter';
        $raeume = json_decode($this->ReadAttributeString('Raeume'), true) ?: [];
        $raumZeile = count($raeume) === 0
            ? 'ℹ️ Noch keine Räume/Zonen bekannt (erscheinen nach dem ersten Abruf, sofern der Roboter eine Karte hat).'
            : '🗺️ Räume/Zonen (ID: Name): ' . implode(', ', array_map(function ($id, $name) {
                return $id . ': ' . $name;
            }, array_keys($raeume), $raeume));

        return [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => '🤖  Gerät',
            'items' => [
                ['type' => 'Label', 'caption' => '🔗 ' . $klasse . ($this->ReadPropertyString('Modell') !== '' ? ', Modell ' . $this->ReadPropertyString('Modell') : '') . ($this->ReadPropertyString('GeraetID') !== '' ? ', Geräte-ID ' . $this->ReadPropertyString('GeraetID') : '') . ' (vom Konfigurator übernommen)'],
                $this->kontoZeile(),
                ['type' => 'Label', 'name' => 'AbrufZeile', 'caption' => $zeile],
                ['type' => 'NumberSpinner', 'name' => 'Intervall', 'caption' => 'Abfrage alle', 'suffix' => ' s', 'minimum' => 30, 'maximum' => 3600],
                ['type' => 'Label', 'caption' => 'Während der Roboter arbeitet, fragt RoboHub automatisch alle ' . self::TAKT_AKTIV_S . ' s nach — außer Echtzeit-Meldungen kommen an (Konto-Instanz). Kürzere Abstände belasten die Hersteller-Cloud, die bei zu vielen Anfragen eine Sperrfrist verhängt.'],
                ['type' => 'Label', 'caption' => $this->ReadAttributeInteger('LetzteEchtzeit') > 0 ? '⚡ Letzte Echtzeit-Meldung ' . date('d.m.Y H:i:s', $this->ReadAttributeInteger('LetzteEchtzeit')) . ' Uhr.' : 'ℹ️ Noch keine Echtzeit-Meldung empfangen.'],
                ['type' => 'Label', 'caption' => $raumZeile],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '🔄 Jetzt aktualisieren', 'onClick' => 'echo ROBO_Aktualisieren($id);'],
                    ['type' => 'Button', 'caption' => '📍 Orten', 'onClick' => 'echo ROBO_Orten($id);'],
                    ['type' => 'Button', 'caption' => '🗺️ Räume neu laden', 'onClick' => 'echo ROBO_RaeumeLaden($id);'],
                    ['type' => 'Button', 'caption' => '📋 Rohdaten anzeigen', 'onClick' => 'echo ROBO_Rohdaten($id);'],
                ]],
            ],
        ];
    }

    private function kontoZeile(): array
    {
        $kid = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($kid > 0) {
            return ['type' => 'Label', 'caption' => '🔗 Konto: ' . IPS_GetName($kid) . ' (#' . $kid . ') — automatisch zugeordnet über die Geräte-ID', 'color' => 0x2E8B3D];
        }
        return ['type' => 'Label', 'caption' => '⛔ Kein Konto verbunden — die Geräte-ID wurde in keiner angemeldeten RoboHub-Konto-Instanz gefunden.', 'color' => 0xFF0000];
    }

    private function raumPanel(): ?array
    {
        $re = json_decode($this->ReadAttributeString('RaumEinstellungen'), true) ?: [];
        $liste = (array)($re['raeume'] ?? []);
        if ($liste === []) {
            return null;
        }
        $namen = json_decode($this->ReadAttributeString('Raeume'), true) ?: [];
        uksort($liste, function ($a, $b) use ($liste): int {
            return ((int)($liste[$a]['reihenfolge'] ?? 0) ?: 999) <=> ((int)($liste[$b]['reihenfolge'] ?? 0) ?: 999) ?: ((int)$a <=> (int)$b);
        });
        $opt = function (array $werte): array {
            $o = [];
            foreach ($werte as $v => $c) {
                $o[] = ['caption' => $c, 'value' => $v];
            }
            return $o;
        };
        $items = [['type' => 'Label', 'caption' => (($re['an'] ?? null) === false ? '⏸️ In der App sind die Raum-Einstellungen ausgeschaltet — Änderungen wirken erst, wenn sie dort an sind. ' : '')
            . 'Änderungen gehen direkt an den Roboter (nur wenn er nicht reinigt); der Stand kommt aus der Live-Karte.']];
        foreach ($liste as $id => $e) {
            $id = (int)$id;
            $nr = (int)($e['reihenfolge'] ?? 0);
            $zeile = [['type' => 'Label', 'caption' => ($nr > 0 ? $nr . '. ' : '') . ($namen[(string)$id] ?? 'Raum ' . $id), 'width' => '170px']];
            if (isset($e['modusWert'])) {
                $zeile[] = ['type' => 'Select', 'name' => 'RM_' . $id, 'caption' => 'Modus', 'value' => (int)$e['modusWert'], 'width' => '230px',
                    'options' => $opt([0 => 'Saugen', 1 => 'Wischen', 2 => 'Saugen und Wischen', 3 => 'Erst saugen, dann wischen']),
                    'onChange' => 'echo ROBO_RaumEinstellungSetzen($id, ' . $id . ', "Modus", $RM_' . $id . ');'];
            }
            $zeile[] = ['type' => 'Select', 'name' => 'RS_' . $id, 'caption' => 'Saugstufe', 'value' => (int)($e['saugstufeWert'] ?? 1), 'width' => '140px',
                'options' => $opt([0 => 'Leise', 1 => 'Standard', 2 => 'Stark', 3 => 'Turbo']),
                'onChange' => 'echo ROBO_RaumEinstellungSetzen($id, ' . $id . ', "Saugstufe", $RS_' . $id . ');'];
            $zeile[] = ['type' => 'Select', 'name' => 'RD_' . $id, 'caption' => 'Durchgänge', 'value' => (int)($e['durchgaenge'] ?? 1), 'width' => '110px',
                'options' => $opt([1 => '1×', 2 => '2×', 3 => '3×']),
                'onChange' => 'echo ROBO_RaumEinstellungSetzen($id, ' . $id . ', "Durchgaenge", $RD_' . $id . ');'];
            $items[] = ['type' => 'RowLayout', 'items' => $zeile];
        }
        return ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🏠  Einstellungen je Raum (' . count($liste) . ')', 'items' => $items];
    }

    private function zeitplanPanel(): ?array
    {
        $termine = json_decode($this->ReadAttributeString('Zeitplaene'), true) ?: [];
        if ($termine === []) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => $this->klasse() === RoboHubModell::KLASSE_MAEHER
            ? 'Mäh-Pläne aus der Hersteller-App. Anlegen und ändern geht nur dort; hier lassen sie sich ein- und ausschalten, solange der Mäher ruht (Station oder bereit). Einschalten kann einen anderen Plan derselben Karte ausschalten.'
            : 'Zeitpläne aus der Hersteller-App. Anlegen und ändern geht nur dort, hier lassen sie sich ein- und ausschalten.']];
        foreach ($termine as $t) {
            $items[] = ['type' => 'RowLayout', 'items' => [
                ['type' => 'Label', 'caption' => ($t['an'] ? '✅ ' : '⏸️ ') . $this->zeitplanText($t), 'width' => '520px'],
                ['type' => 'Button', 'caption' => $t['an'] ? 'Ausschalten' : 'Einschalten', 'visible' => $this->zeitplanSchaltbar($t),
                    'onClick' => 'echo ROBO_ZeitplanSchalten($id, ' . (int)$t['id'] . ', ' . ($t['an'] ? 'false' : 'true') . ');'],
            ]];
        }
        return ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🗓️  Zeitpläne (' . count($termine) . ')', 'items' => $items];
    }

    private function verbrauchPanel(): ?array
    {
        $items = [['type' => 'Label', 'caption' => 'Nach dem Wechsel oder der Reinigung eines Teils hier den Zähler zurücksetzen — wie in der Hersteller-App. Jede Rücksetzung steht danach im Symcon-Protokoll.']];
        foreach (self::TEILE as [$pIdent, $hIdent, $name]) {
            $p = $this->wert($pIdent);
            if ($p === null) {
                continue;
            }
            $items[] = ['type' => 'Button', 'caption' => '↺ ' . $name . ' zurücksetzen (jetzt ' . (int)$p . ' %)', 'width' => '420px',
                'confirm' => $name . ' wirklich zurücksetzen? Das geht nicht rückgängig — nur nach Wechsel oder Reinigung.',
                'onClick' => 'echo ROBO_VerbrauchsteilZuruecksetzen($id, ' . var_export($pIdent, true) . ');'];
        }
        if (count($items) === 1) {
            return null;
        }
        return ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🔧  Verbrauchsteile zurücksetzen', 'items' => $items];
    }

    private function technikPanel(): array
    {
        // GeraetID/Modell/Klasse bleiben Properties (der Konfigurator setzt
        // sie bei der Anlage), sind hier aber nur für Sonderfälle editierbar.
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🛠️  Zuordnung (nur bei Bedarf ändern)',
            'items' => [
                ['type' => 'ValidationTextBox', 'name' => 'GeraetID', 'caption' => 'Geräte-ID beim Hersteller'],
                ['type' => 'ValidationTextBox', 'name' => 'Modell', 'caption' => 'Modellkennung'],
                ['type' => 'Select', 'name' => 'Klasse', 'caption' => 'Geräteklasse', 'options' => [
                    ['caption' => 'Saug-/Wischroboter', 'value' => RoboHubModell::KLASSE_SAUGER],
                    ['caption' => 'Rasenmähroboter', 'value' => RoboHubModell::KLASSE_MAEHER],
                ]],
            ],
        ];
    }

    public function GetConfigurationForm()
    {
        $status = [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Verbunden'],
            ['code' => 104, 'icon' => 'inactive', 'caption' => 'Noch keinem Gerät zugeordnet'],
            ['code' => 201, 'icon' => 'error', 'caption' => 'Konto muss neu angemeldet werden'],
            ['code' => 202, 'icon' => 'inactive', 'caption' => 'Abruf fehlgeschlagen — wird automatisch erneut versucht'],
        ];
        return $this->robohubBuildForm([$this->geraetPanel(), $this->zeitplanPanel(), $this->raumPanel(), $this->verbrauchPanel(), $this->kachelPanel(), $this->technikPanel()], [], $status);
    }
}
