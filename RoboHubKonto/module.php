<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/RoboHubModell.php';
require_once __DIR__ . '/../libs/RoboHubFormular.php';
require_once __DIR__ . '/../libs/RoboHubDreame.php';
require_once __DIR__ . '/../libs/RoboHubMqttSitzung.php';
require_once __DIR__ . '/../libs/RoboHubTls.php';
require_once __DIR__ . '/../libs/RoboHubRoborock.php';
require_once __DIR__ . '/../libs/RoboHubWorx.php';
require_once __DIR__ . '/../libs/RoboHubEcovacs.php';
require_once __DIR__ . '/../libs/RoboHubIrobot.php';
require_once __DIR__ . '/../libs/RoboHubHusqvarna.php';
require_once __DIR__ . '/../libs/RoboHubGardena.php';
require_once __DIR__ . '/../libs/RoboHubNavimow.php';
require_once __DIR__ . '/../libs/RoboHubIndego.php';
require_once __DIR__ . '/../libs/RoboHubNarwal.php';
require_once __DIR__ . '/../libs/RoboHubStihl.php';
require_once __DIR__ . '/../libs/RoboHubMqtt.php';

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

    // Hersteller: Wert im Select => [Anzeige im Select, Kurzname, Anmeldung per Code, Regionswahl].
    private const HERSTELLER = [
        'dreame'    => ['Dreame (App „Dreamehome")', 'Dreame', false, true],
        'mova'      => ['MOVA (App „MOVAhome")', 'MOVA', false, true],
        'roborock'  => ['Roborock (App „Roborock")', 'Roborock', true, false],
        'worx'      => ['Worx Landroid (App „Landroid")', 'Worx', false, false],
        'kress'     => ['Kress (App „Kress Mission")', 'Kress', false, false],
        'landxcape' => ['LandXcape (App „LandXcape")', 'LandXcape', false, false],
        'ferrex'    => ['Ferrex (App „Smart Mower")', 'Ferrex', false, false],
        'ecovacs'   => ['Ecovacs Deebot/GOAT (App „Ecovacs Home")', 'Ecovacs', false, true],
        'irobot'    => ['iRobot Roomba/Braava (lokal im Heimnetz, ohne Cloud)', 'iRobot', false, false],
        'husqvarna' => ['Husqvarna Automower (offizielle Automower Connect API)', 'Husqvarna', false, false],
        'gardena'   => ['GARDENA smart system Mähroboter (offizielle API)', 'GARDENA', false, false],
        'navimow'   => ['Segway Navimow (App „Navimow")', 'Navimow', false, false],
        'indego'    => ['Bosch Indego (App „Bosch Smart Gardening")', 'Indego', false, false],
        'stihl'     => ['STIHL iMOW (App „iMOW", alle Generationen)', 'STIHL', false, false],
        'narwal'    => ['Narwal Flow/Freo (lokal im Heimnetz, ohne Cloud)', 'Narwal', false, false],
    ];

    private const DATA_ZUM_KONTO = '{CFA82D8B-B9F1-4CAF-BFB7-E898E3690581}';
    private const DATA_ZUM_KIND  = '{4949DAA0-C934-4184-91E2-2D3E90D4DC24}';

    // Echtzeit (MQTT) über Symcons Client Socket (TLS).
    private const CLIENT_SOCKET  = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    // Echtzeit über WebSocket (Husqvarna, GARDENA): Symcons WebSocket Client, gleiche Datenschnittstellen.
    private const WS_CLIENT      = '{D68FD31F-0E90-7019-F16C-1949BD3079EF}';
    // Ohne Echtzeit: Symcons Virtual IO (gleiche Datenschnittstelle, live geprüft 26.10.2026), immer aktiv —
    // ein geschlossener Client Socket ließ Konto und Roboter „übergeordnete Instanz inaktiv" melden (Forum baraka).
    private const VIRTUAL_IO     = '{6179ED6A-FC31-413C-BB8E-1204150CF376}';
    private const WS_LEBENSZEICHEN_S = 60;
    private const DATA_ZUM_IO    = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const MQTT_KEEPALIVE = 60;

    // Unbeantwortete Abfrageblöcke eine Stunde lang überspringen.
    private const BLOCK_SPERRE_S = 3600;

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
        // Zweistufige Anmeldung (Ecovacs-Gerätebestätigung): Zwischenstand bis zum Code.
        $this->RegisterAttributeString('AnmeldeVorstufe', '');
        $this->RegisterAttributeString('Geraete', '[]');
        $this->RegisterAttributeInteger('GeraeteStand', 0);
        $this->RegisterAttributeInteger('WarteBis', 0);
        $this->RegisterAttributeString('LetzterFehler', '');
        $this->RegisterAttributeString('BlockSperre', '{}');
        // Echtzeit: letzter vollständiger Rohstand je Gerät (für korrekte
        // Übersetzung einzelner Änderungen) und Verbindungsstand fürs Formular.
        $this->RegisterAttributeString('Schnappschuss', '{}');
        $this->RegisterAttributeString('Mqtt', '{}');
        $this->RegisterAttributeInteger('MqttEltern', 0);
        // Echtzeit unter Symcon 9.1 (Rust-TLS): Zertifikate mit Beginn vor 1970 werden abgelehnt.
        // Scheitert der Socket, ohne je verbunden gewesen zu sein, wird er 24 h geschlossen.
        $this->RegisterAttributeBoolean('EchtzeitJeVerbunden', false);
        $this->RegisterAttributeInteger('EchtzeitGesperrtBis', 0);
        $this->RegisterAttributeInteger('WsFehler', 0);
        $this->RegisterPropertyBoolean('Echtzeit', true);
        $this->RegisterTimer('MqttPing', 0, 'ROBOHUB_MqttPing($_IPS[\'TARGET\']);');
        $this->RequireParent(self::CLIENT_SOCKET);
        $this->registerFormAttributes();

        $this->RegisterTimer('Erneuern', 0, 'ROBOHUB_Erneuern($_IPS[\'TARGET\']);');
        // Client Socket erst verzögert selbst anlegen: beim Neuanlegen bietet die Konsole ihn selbst an
        // (Forum 13.10.2026: „hat bereits ein übergeordnetes Objekt", wenn RoboHub schneller war).
        $this->RegisterTimer('Verbindung', 0, 'ROBOHUB_VerbindungPruefen($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            return;
        }
        $this->aktualisiereStatus();
        // Bestandskonten, deren Echtzeit schon lief, nie wegen eines späteren Fehlers sperren.
        if ((json_decode((string)$this->ReadAttributeString('Mqtt'), true)['zustand'] ?? '') === 'verbunden') {
            $this->WriteAttributeBoolean('EchtzeitJeVerbunden', true);
        }
        $eltern = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($eltern === 0 || IPS_GetInstance($eltern)['ModuleInfo']['ModuleID'] !== $this->elternModul()
            || ($this->echtzeitArt() === 'websocket' && $this->GetBuffer('WsZiel') === '' && $this->ReadAttributeString('Buendel') !== '')) {
            $this->SetTimerInterval('Verbindung', 15000);
        }
        $this->elternBeobachten();
        $this->socketBenennen();
        $this->echtzeitKonfigurieren(false);
    }

    /** Wurde der Socket noch als Dreame-Konto angelegt (Voreinstellung), den Namen nachziehen. */
    private function socketBenennen(): void
    {
        $eltern = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        $name = IPS_GetName($this->InstanceID);
        if ($eltern > 0 && $this->echtzeitArt() === '' && IPS_GetName($eltern) === 'RoboHub Echtzeit (' . $name . ')') {
            IPS_SetName($eltern, self::ohneEchtzeitName($name));
        }
    }

    private static function ohneEchtzeitName(string $konto): string
    {
        return 'RoboHub Verbindung (' . $konto . ', ohne Echtzeit)';
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && ($Data[0] ?? null) === KR_READY) {
            $this->ApplyChanges();
            return;
        }
        // Client Socket verbunden/getrennt (Muster Tibber Grid Reward).
        if ($Message === IM_CHANGESTATUS && $SenderID === $this->ReadAttributeInteger('MqttEltern') && $this->echtzeitArt() === 'websocket') {
            $this->wsStatus((int)($Data[0] ?? 0));
            return;
        }
        if ($Message === IM_CHANGESTATUS && $SenderID === $this->ReadAttributeInteger('MqttEltern') && $this->echtzeitArt() === '') {
            return;   // Virtual IO ohne Echtzeit: nichts zu tun
        }
        if ($Message === IM_CHANGESTATUS && $SenderID === $this->ReadAttributeInteger('MqttEltern')) {
            if (($Data[0] ?? 0) === IS_ACTIVE) {
                $this->mqttAnmelden();
            } elseif ((int)($Data[0] ?? 0) >= 200 && !$this->ReadAttributeBoolean('EchtzeitJeVerbunden')
                && $this->ReadPropertyBoolean('Echtzeit') && $this->ReadAttributeString('Buendel') !== '') {
                $this->echtzeitSperren();
            } else {
                $this->SetTimerInterval('MqttPing', 0);
                $this->mqttStand(['zustand' => $this->ReadPropertyBoolean('Echtzeit') ? 'getrennt' : 'aus']);
            }
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
        $treiber = $this->treiber();
        if (method_exists($treiber, 'koppeln')) {
            return $this->koppeln($treiber, $konto);
        }
        if (method_exists($treiber, 'anmeldeLink') && $pass === '') {
            return $this->melde('⛔ Bitte zuerst „🔗 Anmeldeseite öffnen", dort anmelden und die Adresse, auf der der Browser landet, ins Feld „Code" eintragen, „Übernehmen", dann anmelden.');
        }
        if (!method_exists($treiber, 'anmeldeLink') && ($konto === '' || $pass === '')) {
            return $this->melde('⛔ Bitte zuerst Konto (E-Mail) und Passwort (bei Roborock: den Code aus der E-Mail) eintragen, „Übernehmen" klicken und dann anmelden.');
        }
        $vorstufe = json_decode((string)$this->ReadAttributeString('AnmeldeVorstufe'), true) ?: [];
        try {
            if ($vorstufe !== [] && method_exists($treiber, 'anmeldenMitCode')) {
                $buendel = $treiber->anmeldenMitCode($konto, $pass, $vorstufe);
            } else {
                $buendel = $treiber->anmelden($konto, $pass, (string)$this->ReadPropertyString('Region'));
            }
        } catch (Throwable $e) {
            $this->WriteAttributeString('LetzterFehler', $e->getMessage());
            $this->protokolliere('Anmeldung fehlgeschlagen: ' . $e->getMessage(), KL_WARNING);
            if ($e instanceof RoboHubFehler && $e->vorstufe !== []) {
                // Zweite Stufe nötig: Zwischenstand merken, Passwortfeld für den Code freimachen.
                $this->WriteAttributeString('AnmeldeVorstufe', (string)json_encode($e->vorstufe));
                $text = '📧 ' . $e->getMessage();
                $this->UpdateFormField('Ergebnis', 'caption', $text);
                IPS_SetProperty($this->InstanceID, 'Passwort', '');
                IPS_ApplyChanges($this->InstanceID);
                return $text;
            }
            return $this->melde('⛔ Anmeldung fehlgeschlagen: ' . $e->getMessage());
        }
        $this->WriteAttributeString('AnmeldeVorstufe', '');
        $this->WriteAttributeString('Buendel', (string)json_encode($buendel));
        $this->WriteAttributeString('LetzterFehler', '');
        $this->WriteAttributeInteger('WarteBis', 0);

        $hersteller = $this->ReadPropertyString('Hersteller');
        if ($hersteller === 'ecovacs') {
            $text = '✅ Angemeldet. Ecovacs kennt keinen Erneuerungsschlüssel: RoboHub behält einen Hash des Passworts (nicht das Passwort selbst), um sich alle 7 Tage selbst neu anzumelden. Abmelden löscht ihn.';
        } elseif (method_exists($treiber, 'anmeldeText')) {
            $text = $treiber->anmeldeText($buendel);
        } elseif ($hersteller === 'navimow' || $hersteller === 'indego') {
            $text = '✅ Angemeldet. Der Zugangsschlüssel ist gespeichert, der Code wurde verworfen — RoboHub kennt dein ' . ($hersteller === 'indego' ? 'Bosch' : 'Segway') . '-Passwort nicht.';
        } elseif ($hersteller === 'husqvarna' || $hersteller === 'gardena') {
            $text = '✅ Angemeldet. Application Key und Secret bleiben im Konto gespeichert, weil Husqvarna/GARDENA den Zugang nur damit erneuert (alle 24 h). Im Entwicklerportal kannst du sie jederzeit widerrufen; Abmelden löscht sie hier.';
        } else {
            $text = '✅ Angemeldet. Der Zugangsschlüssel ist gespeichert, ' . ($this->perCode() ? 'der Code' : 'das Passwort') . ' wurde verworfen.';
        }
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
        $this->echtzeitKonfigurieren(true);
        IPS_SetProperty($this->InstanceID, 'Passwort', '');
        IPS_ApplyChanges($this->InstanceID);
        return $text;
    }

    /** Lokale Roboter (iRobot): unter der IP koppeln, vorhandene Roboter bleiben im Bündel. */
    private function koppeln($treiber, string $ip): string
    {
        try {
            $buendel = $treiber->koppeln($ip, json_decode((string)$this->ReadAttributeString('Buendel'), true) ?: []);
        } catch (Throwable $e) {
            $this->WriteAttributeString('LetzterFehler', $e->getMessage());
            $this->protokolliere('Koppeln fehlgeschlagen: ' . $e->getMessage(), KL_WARNING);
            return $this->melde('⛔ Koppeln fehlgeschlagen: ' . $e->getMessage());
        }
        $this->WriteAttributeString('Buendel', (string)json_encode($buendel));
        $this->WriteAttributeString('LetzterFehler', '');
        $text = method_exists($treiber, 'koppelText') ? $treiber->koppelText()
            : '✅ Gekoppelt. Das Gerätepasswort und der Fingerabdruck des Roboter-Zertifikats sind gespeichert; ein Gerät mit anderem Zertifikat wird künftig abgelehnt.';
        try {
            $text .= "\n" . $this->geraeteZusammenfassung($this->ladeGeraete($buendel));
        } catch (Throwable $e) {
            $text .= "\n⚠️ " . $e->getMessage();
        }
        $this->aktualisiereStatus();
        return $this->melde($text);
    }

    /**
     * ROBOHUB_CodeAnfordern($id): string — nur Roborock: Anmeldecode per
     * E-Mail anfordern. Danach den Code ins Feld „Passwort bzw. Code"
     * eintragen und anmelden.
     */
    public function CodeAnfordern(): string
    {
        $konto = trim((string)$this->ReadPropertyString('Konto'));
        if ($konto === '') {
            return $this->melde('⛔ Bitte zuerst die E-Mail des Kontos eintragen und „Übernehmen" klicken.');
        }
        $treiber = $this->treiber();
        if (!method_exists($treiber, 'codeAnfordern')) {
            return $this->melde('ℹ️ Dieser Hersteller meldet sich mit dem Passwort an — kein Code nötig.');
        }
        try {
            $text = $treiber->codeAnfordern($konto, json_decode((string)$this->ReadAttributeString('AnmeldeVorstufe'), true) ?: []);
        } catch (Throwable $e) {
            $this->protokolliere('Code anfordern fehlgeschlagen: ' . $e->getMessage(), KL_WARNING);
            return $this->melde('⛔ ' . $e->getMessage());
        }
        return $this->melde('📧 ' . $text . ' Code ins Feld „Passwort bzw. Code" eintragen, „Übernehmen", dann „🔑 Jetzt anmelden".');
    }

    /**
     * ROBOHUB_Anmeldelink($id): string — Navimow/Indego: Adresse der Hersteller-Anmeldeseite
     * (der Formular-Knopf öffnet sie direkt).
     */
    public function Anmeldelink(): string
    {
        $treiber = $this->treiber();
        return method_exists($treiber, 'anmeldeLink') ? $treiber->anmeldeLink() : '';
    }

    /** ROBOHUB_Abmelden($id): string — verwirft den Zugangsschlüssel. */
    public function Abmelden(): string
    {
        $this->WriteAttributeString('Buendel', '');
        $this->WriteAttributeString('AnmeldeVorstufe', '');
        $this->WriteAttributeString('Geraete', '[]');
        $this->WriteAttributeInteger('GeraeteStand', 0);
        $this->aktualisiereStatus();
        $this->echtzeitKonfigurieren(false);
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

    /**
     * ROBOHUB_GeraeteListe($id): string — zuletzt geladene Geräteliste als
     * JSON, OHNE Cloud-Anfrage. Für den Konfigurator (sammelt alle Konten)
     * und die Roboter-Instanzen (finden darüber ihr Konto selbst).
     */
    public function GeraeteListe(): string
    {
        $hersteller = $this->ReadPropertyString('Hersteller');
        // Gerätschlüssel (Roborock) bleiben im Konto — Konfigurator und Roboter brauchen sie nicht.
        $geraete = array_map(function (array $g): array {
            unset($g['schluessel']);
            return $g;
        }, json_decode((string)$this->ReadAttributeString('Geraete'), true) ?: []);
        return (string)json_encode([
            'hersteller' => $hersteller,
            'anzeige'    => (self::HERSTELLER[$hersteller][1] ?? $hersteller) . ($this->ReadPropertyString('Konto') !== '' ? ' (' . $this->ReadPropertyString('Konto') . ')' : ''),
            'angemeldet' => $this->ReadAttributeString('Buendel') !== '',
            'stand'      => $this->ReadAttributeInteger('GeraeteStand'),
            'geraete'    => $geraete,
        ], JSON_UNESCAPED_UNICODE);
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
        $sperre = $this->ReadAttributeInteger('EchtzeitGesperrtBis');
        if ($sperre > 0 && $sperre <= time()) {
            $this->EchtzeitNeuVersuchen();
        }
    }

    /** ROBOHUB_EchtzeitNeuVersuchen($id): geschlossene Echtzeit-Verbindung erneut öffnen. */
    public function EchtzeitNeuVersuchen(): void
    {
        $this->WriteAttributeInteger('EchtzeitGesperrtBis', 0);
        $this->echtzeitKonfigurieren(true);
        $this->UpdateFormField('EchtzeitZeile', 'caption', $this->echtzeitZeile());
    }

    /**
     * Der Socket ist gescheitert, bevor je eine Echtzeit-Verbindung stand — unter Symcon 9.1
     * (Rust-TLS) lehnt er die Herstellerzertifikate ab (Beginn 31.12.1969, rustls-webpki
     * unterstützt nichts vor 1970). Statt Fehlerstatus: Socket schließen, Grund nennen,
     * nach 24 h erneut versuchen (falls Symcon nachbessert). Die Abfrage läuft weiter.
     */
    private function echtzeitSperren(): void
    {
        $this->WriteAttributeInteger('EchtzeitGesperrtBis', time() + 86400);
        $this->protokolliere('Echtzeit-Verbindung schlägt fehl (unter Symcon 9.1 wird das Zertifikat des Herstellerservers abgelehnt) — Verbindung für 24 h geschlossen, die Abfrage läuft weiter.', KL_WARNING);
        $this->echtzeitKonfigurieren(false);
        $this->mqttStand(['zustand' => 'zertifikat']);
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
            return json_decode((string)$this->ReadAttributeString('Geraete'), true) ?: [];
        }
        if ($aktion === 'info') {
            return [
                'hersteller' => $this->ReadPropertyString('Hersteller'),
                'angemeldet' => $this->ReadAttributeString('Buendel') !== '',
            ];
        }

        $geraet = $this->findeGeraet((string)($anfrage['Geraet'] ?? ''));
        // Letzter vollständiger Rohstand (z. B. für die Mopp-Anhebungs-Erkennung bei Raum-Einstellungen).
        $geraet['stand'] = (array)((json_decode((string)$this->ReadAttributeString('Schnappschuss'), true) ?: [])[(string)$geraet['id']]['p'] ?? []);
        $buendel = $this->gueltigesBuendel();
        try {
            if ($aktion === 'zustand') {
                return $this->zustandMitBlockSperre($buendel, $geraet);
            }
            if ($aktion === 'raeume') {
                return $this->treiber()->karte($buendel, $geraet);
            }
            if ($aktion === 'kartenobjekt') {
                $treiber = $this->treiber();
                return method_exists($treiber, 'raumEinstellungenAusObjekt')
                    ? $treiber->raumEinstellungenAusObjekt($buendel, $geraet, (string)($anfrage['Name'] ?? '')) : [];
            }
            if ($aktion === 'livekarte') {
                return $this->treiber()->liveKarte($buendel, $geraet + ['raeume' => (array)($anfrage['Raeume'] ?? [])]);
            }
            if ($aktion === 'zuruecksetzen') {
                return $this->treiber()->zuruecksetzen($buendel, $geraet, (string)($anfrage['Teil'] ?? ''));
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
    // Echtzeit (MQTT)
    // -----------------------------------------------------------------

    /** Einstellungen für den Client Socket (Symcon wendet sie beim Verbinden an). */
    public function GetConfigurationForParent()
    {
        if ($this->echtzeitArt() === '') {
            return '{}';   // Virtual IO braucht keine Einstellungen
        }
        if ($this->echtzeitArt() === 'websocket') {
            $ziel = json_decode((string)$this->GetBuffer('WsZiel'), true) ?: [];
            $url = (string)($ziel['url'] ?? '');
            return (string)json_encode([
                'Active'            => $this->ReadPropertyBoolean('Echtzeit') && $this->ReadAttributeString('Buendel') !== '' && $url !== '',
                'URL'               => $url,
                'Headers'           => (string)json_encode((array)($ziel['kopf'] ?? [])),
                'VerifyCertificate' => true,   // nie abschalten (Verbund-Regel, anders als manche Fremdmodule)
                'Type'              => 0,
            ]);
        }
        $an = $this->ReadPropertyBoolean('Echtzeit') && $this->ReadAttributeString('Buendel') !== ''
            && $this->ReadAttributeInteger('EchtzeitGesperrtBis') <= time();
        [$host, $port] = $this->mqttZiel();
        return (string)json_encode([
            'Host'                 => $host,
            'Port'                 => $port,
            'Open'                 => $an && $host !== '',
            'UseSSL'               => true,
            // Volle Prüfung gegen Dreames eigene Wurzel (siehe RoboHubDreame::MQTT_WURZEL).
            'VerifyPeer'           => true,
            'VerifyHost'           => true,
            'CertificateAuthority' => base64_encode(RoboHubDreame::MQTT_WURZEL),
        ]);
    }

    private function mqttZiel(): array
    {
        $buendel = json_decode((string)$this->ReadAttributeString('Buendel'), true) ?: [];
        $geraete = json_decode((string)$this->ReadAttributeString('Geraete'), true) ?: [];
        try {
            return $this->treiber()->mqttZiel($geraete, $buendel);
        } catch (Throwable $e) {
            return ['', 0];
        }
    }

    /** Client Socket nach Bedarf (um)konfigurieren; $neuVerbinden erzwingt Trennen + Verbinden. */
    private function echtzeitKonfigurieren(bool $neuVerbinden): void
    {
        $eltern = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($eltern <= 0) {
            $this->mqttStand(['zustand' => 'fehlt']);
            return;
        }
        $ws = $this->echtzeitArt() === 'websocket';
        if (IPS_GetInstance($eltern)['ModuleInfo']['ModuleID'] !== $this->elternModul()) {
            return;   // fremde/alte Verbindungsart — VerbindungPruefen tauscht eigene aus
        }
        if ($this->echtzeitArt() === '') {
            $this->SetTimerInterval('MqttPing', 0);
            $this->mqttStand(['zustand' => 'aus']);
            return;
        }
        $soll = json_decode($this->GetConfigurationForParent(), true);
        $offen = $ws ? 'Active' : 'Open';
        $ist = json_decode((string)IPS_GetConfiguration($eltern), true) ?: [];
        $anders = false;
        foreach ($soll as $k => $v) {
            if (($ist[$k] ?? null) != $v) {
                $anders = true;
            }
        }
        if (!$soll[$offen]) {
            $this->SetTimerInterval('MqttPing', 0);
            $this->mqttStand(['zustand' => $this->ReadAttributeInteger('EchtzeitGesperrtBis') > time() ? 'zertifikat' : 'aus']);
        }
        if (!$anders && !($neuVerbinden && $soll[$offen])) {
            return;
        }
        // Wie Tibber Grid Reward: per Skript, damit das ApplyChanges des
        // Sockets nicht im eigenen Aufruf hängt; IM_CHANGESTATUS meldet das Ergebnis.
        $skript = '';
        if ($neuVerbinden && $soll[$offen] && !$anders) {
            $skript .= 'IPS_SetProperty(' . $eltern . ', "' . $offen . '", false); IPS_ApplyChanges(' . $eltern . ');';
        }
        $skript .= 'IPS_SetConfiguration(' . $eltern . ', ' . var_export((string)json_encode($soll), true) . '); IPS_ApplyChanges(' . $eltern . ');';
        if ($soll[$offen]) {
            $this->mqttStand(['zustand' => 'verbinde', 'host' => $ws ? (string)parse_url((string)$soll['URL'], PHP_URL_HOST) : $soll['Host'] . ':' . $soll['Port']]);
        }
        IPS_RunScriptText($skript);
    }

    /**
     * Client Socket anlegen und verbinden, falls noch keiner da ist.
     * RequireParent() in Create()/ApplyChanges() hat bei Konten, die vor 0.4.0
     * angelegt wurden, live nichts angelegt (11.10.2026, auch nach Übernehmen),
     * obwohl Moduldefinition und Code stimmten. Deshalb dieselben Schritte wie
     * in Symcons offiziellen Stubs (SymconStubs ModuleStubs::RequireParent):
     * anlegen, benennen, verbinden — nur ausdrücklich.
     */
    /**
     * Timer: fehlt der Client Socket noch (Bestandskonten — dort legt Symcon ihn
     * über RequireParent nicht an), legt RoboHub ihn selbst an. Verzögert, damit
     * die Konsole beim Neuanlegen ihren eigenen zuerst einhängen kann.
     */
    public function VerbindungPruefen(): void
    {
        $this->SetTimerInterval('Verbindung', 0);
        $this->verbindungSicherstellen();
        $this->elternBeobachten();
        if ($this->echtzeitArt() === 'websocket') {
            // Ziel (Adresse + Anmeldekopf) frisch holen: GARDENA vergibt je Verbindung eine neue Adresse,
            // Husqvarna braucht den aktuellen Zugangsschlüssel. Danach neu verbinden.
            $this->echtzeitKonfigurieren($this->wsZielAktualisieren());
            return;
        }
        $this->echtzeitKonfigurieren(false);
    }

    /** 'mqtt' (Dreame/MOVA über Client Socket), 'websocket' (WebSocket Client) oder '' (keine Konto-Echtzeit). */
    private function echtzeitArt(): string
    {
        $t = $this->treiber();
        if (method_exists($t, 'echtzeitArt')) {
            return (string)$t->echtzeitArt();
        }
        return !method_exists($t, 'echtzeitMoeglich') || $t->echtzeitMoeglich() ? 'mqtt' : '';
    }

    private function elternModul(): string
    {
        $art = $this->echtzeitArt();
        return $art === 'websocket' ? self::WS_CLIENT : ($art === 'mqtt' ? self::CLIENT_SOCKET : self::VIRTUAL_IO);
    }

    /** WebSocket-Ziel vom Treiber holen und im Puffer ablegen; true, wenn es sich geändert hat. */
    private function wsZielAktualisieren(): bool
    {
        if (!$this->ReadPropertyBoolean('Echtzeit') || $this->ReadAttributeString('Buendel') === '') {
            return false;
        }
        try {
            $ziel = $this->treiber()->wsZiel($this->gueltigesBuendel());
        } catch (Throwable $e) {
            $this->mqttStand(['zustand' => 'fehler', 'text' => $e->getMessage()]);
            $this->wsNeuPlanen();
            return false;
        }
        $neu = (string)json_encode($ziel);
        $alt = $this->GetBuffer('WsZiel');
        $this->SetBuffer('WsZiel', $neu);
        return $neu !== $alt;
    }

    /** Statuswechsel des WebSocket Clients. */
    private function wsStatus(int $status): void
    {
        if ($status === IS_ACTIVE) {
            $this->WriteAttributeInteger('WsFehler', 0);
            $this->WriteAttributeBoolean('EchtzeitJeVerbunden', true);
            $this->mqttStand(['zustand' => 'verbunden', 'seit' => time(), 'text' => '', 'lebenszeichen' => time(),
                'topics' => count(json_decode((string)$this->ReadAttributeString('Geraete'), true) ?: [])]);
            $t = $this->treiber();
            $this->SetTimerInterval('MqttPing', method_exists($t, 'wsLebenszeichen') && $t->wsLebenszeichen() !== null ? self::WS_LEBENSZEICHEN_S * 1000 : 0);
            return;
        }
        $this->SetTimerInterval('MqttPing', 0);
        if ($status >= 200 && $this->ReadPropertyBoolean('Echtzeit') && $this->ReadAttributeString('Buendel') !== '') {
            $this->mqttStand(['zustand' => 'getrennt']);
            $this->wsNeuPlanen();
            return;
        }
        $this->mqttStand(['zustand' => $this->ReadPropertyBoolean('Echtzeit') ? 'getrennt' : 'aus']);
    }

    /** Neuer Verbindungsversuch mit wachsendem Abstand (30 s, 60 s … höchstens 1 h). */
    private function wsNeuPlanen(): void
    {
        $n = $this->ReadAttributeInteger('WsFehler') + 1;
        $this->WriteAttributeInteger('WsFehler', $n);
        $this->SetTimerInterval('Verbindung', (int)min(3600, 15 * (2 ** min($n, 8))) * 1000);
    }

    private function verbindungSicherstellen(): void
    {
        // Jedes Konto bekommt eine Verbindungsinstanz, auch ohne Echtzeit (seit 0.25.0 dann ein
        // Virtual IO statt eines geschlossenen Client Sockets): Das Modul verlangt sie, und Symcon 9.1
        // verwendet ein Konto ohne ihn in der Konfigurator-Kette nicht wieder, sondern
        // legt ein neues, unvollständiges an (Forum Thilo, 20.10.2026).
        $eltern = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($eltern !== 0) {
            // Falsche Verbindungsart (z. B. Husqvarna-Konto aus der Zeit vor der WebSocket-Echtzeit) —
            // nur austauschen, wenn RoboHub sie selbst angelegt hat (Name „RoboHub …"), Fremdes nie anfassen.
            if (IPS_GetInstance($eltern)['ModuleInfo']['ModuleID'] === $this->elternModul() || strpos(IPS_GetName($eltern), 'RoboHub ') !== 0) {
                return;
            }
            IPS_DisconnectInstance($this->InstanceID);
            @IPS_DeleteInstance($eltern);
            $this->protokolliere('Verbindungsinstanz gegen ' . ['websocket' => 'einen WebSocket Client', 'mqtt' => 'einen Client Socket', '' => 'einen Virtual IO'][$this->echtzeitArt()] . ' getauscht.');
        }
        $id = @IPS_CreateInstance($this->elternModul());
        if (!$id) {
            $this->protokolliere('Verbindungsinstanz unter dem Konto ließ sich nicht anlegen.', KL_WARNING);
            return;
        }
        // Inzwischen doch verbunden (z. B. von der Konsole)? Dann den eigenen wieder entfernen.
        if ((int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0) !== 0 || !@IPS_ConnectInstance($this->InstanceID, $id)) {
            @IPS_DeleteInstance($id);
            return;
        }
        IPS_SetName($id, $this->echtzeitArt() !== ''
            ? 'RoboHub Echtzeit (' . IPS_GetName($this->InstanceID) . ')'
            : self::ohneEchtzeitName(IPS_GetName($this->InstanceID)));
    }

    private function elternBeobachten(): void
    {
        $eltern = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        $vorher = $this->ReadAttributeInteger('MqttEltern');
        if ($vorher !== $eltern && $vorher > 0) {
            $this->UnregisterMessage($vorher, IM_CHANGESTATUS);
        }
        $this->WriteAttributeInteger('MqttEltern', $eltern);
        if ($eltern > 0) {
            $this->RegisterMessage($eltern, IM_CHANGESTATUS);
        }
    }

    /** Socket steht → MQTT-Anmeldung mit dem aktuellen Zugangsschlüssel. */
    private function mqttAnmelden(): void
    {
        if (!$this->ReadPropertyBoolean('Echtzeit')) {
            return;
        }
        try {
            $buendel = $this->gueltigesBuendel();
            [$benutzer, $passwort] = $this->treiber()->mqttZugang($buendel);
        } catch (Throwable $e) {
            $this->mqttStand(['zustand' => 'fehler', 'text' => $e->getMessage()]);
            return;
        }
        $this->SetBuffer('MqttEingang', '');
        $this->mqttSenden(RoboHubMqtt::connect('p_' . bin2hex(random_bytes(8)), $benutzer, $passwort, self::MQTT_KEEPALIVE));
        $this->SetTimerInterval('MqttPing', (self::MQTT_KEEPALIVE - 10) * 1000);
    }

    private function mqttSenden(string $bytes): void
    {
        try {
            $this->SendDataToParent((string)json_encode(['DataID' => self::DATA_ZUM_IO, 'Buffer' => mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1')]));
        } catch (Throwable $e) {
            $this->mqttStand(['zustand' => 'fehler', 'text' => 'Senden fehlgeschlagen: ' . $e->getMessage()]);
        }
    }

    /** Timer: Verbindung am Leben halten; ohne jede Antwort seit 3 Intervallen neu verbinden. */
    public function MqttPing(): void
    {
        if ($this->echtzeitArt() === 'websocket') {
            $t = $this->treiber();
            $text = method_exists($t, 'wsLebenszeichen') ? $t->wsLebenszeichen() : null;
            if ($text !== null) {
                try {
                    $this->SendDataToParent((string)json_encode(['DataID' => self::DATA_ZUM_IO, 'Buffer' => $text]));
                } catch (Throwable $e) {
                    $this->mqttStand(['zustand' => 'fehler', 'text' => 'Senden fehlgeschlagen: ' . $e->getMessage()]);
                }
            }
            return;
        }
        $stand = json_decode((string)$this->ReadAttributeString('Mqtt'), true) ?: [];
        if (($stand['zustand'] ?? '') !== 'verbunden') {
            return;
        }
        if (time() - (int)($stand['lebenszeichen'] ?? 0) > 3 * self::MQTT_KEEPALIVE) {
            $this->protokolliere('Echtzeit-Verbindung antwortet nicht mehr — verbinde neu.', KL_WARNING);
            $this->echtzeitKonfigurieren(true);
            return;
        }
        $this->mqttSenden(RoboHubMqtt::pingreq());
    }

    /** Daten vom Client Socket (Bytes als UTF-8, Verbund-Muster Fernwirk101). */
    public function ReceiveData($JSONString)
    {
        $paket = json_decode((string)$JSONString, true);
        if (!is_array($paket) || !isset($paket['Buffer'])) {
            return '';
        }
        if ($this->echtzeitArt() === 'websocket') {
            // WebSocket Client: Buffer ist der Text einer Nachricht.
            $this->mqttStand(['lebenszeichen' => time()]);
            $text = (string)$paket['Buffer'];
            if (trim($text) !== '') {
                $this->SendDebug('Echtzeit', substr($text, 0, 600), 0);
                $this->pushVerarbeiten($text);
            }
            return '';
        }
        $puffer = base64_decode($this->GetBuffer('MqttEingang')) . mb_convert_encoding((string)$paket['Buffer'], 'ISO-8859-1', 'UTF-8');
        $pakete = RoboHubMqtt::zerlegen($puffer);
        $this->SetBuffer('MqttEingang', base64_encode($puffer));
        foreach ($pakete as $p) {
            $this->mqttPaket($p);
        }
        return '';
    }

    private function mqttPaket(array $p): void
    {
        $this->mqttStand(['lebenszeichen' => time()]);
        switch ($p['typ']) {
            case RoboHubMqtt::CONNACK:
                $code = RoboHubMqtt::connackCode($p['body']);
                if ($code !== 0) {
                    $text = 'Echtzeit-Anmeldung abgelehnt: ' . RoboHubMqtt::connackText($code);
                    $this->mqttStand(['zustand' => 'fehler', 'text' => $text]);
                    $this->protokolliere($text, KL_WARNING);
                    return;
                }
                $topics = [];
                $buendel = json_decode((string)$this->ReadAttributeString('Buendel'), true) ?: [];
                foreach (json_decode((string)$this->ReadAttributeString('Geraete'), true) ?: [] as $g) {
                    $topics[] = $this->treiber()->mqttTopic($g, $buendel);
                }
                if (count($topics) > 0) {
                    $this->mqttSenden(RoboHubMqtt::subscribe(1, $topics, 0));
                }
                $this->WriteAttributeBoolean('EchtzeitJeVerbunden', true);
                $this->mqttStand(['zustand' => 'verbunden', 'seit' => time(), 'text' => '', 'topics' => count($topics)]);
                return;
            case RoboHubMqtt::PUBLISH:
                $pub = RoboHubMqtt::publishLesen($p['flags'], $p['body']);
                if ($pub['qos'] > 0) {
                    $this->mqttSenden(RoboHubMqtt::puback($pub['id']));
                }
                $this->SendDebug('Echtzeit', $pub['topic'] . ' ' . substr($pub['payload'], 0, 600), 0);
                $this->pushVerarbeiten($pub['payload']);
                return;
        }
    }

    /** Änderungen in den letzten vollständigen Stand mischen, übersetzen, an den Roboter geben. */
    private function pushVerarbeiten(string $payload): void
    {
        $t = $this->treiber();
        $aenderungen = method_exists($t, 'pushLesenMit')
            ? $t->pushLesenMit($payload, json_decode((string)$this->ReadAttributeString('Buendel'), true) ?: [])
            : $t->pushLesen($payload);
        if (count($aenderungen) === 0) {
            return;
        }
        $schnapp = json_decode((string)$this->ReadAttributeString('Schnappschuss'), true) ?: [];
        foreach ($aenderungen as $did => $delta) {
            // Roboterposition ist kein Gerätewert: nicht in den Schnappschuss.
            $position = $delta['_position'] ?? null;
            $objekt = $delta['_objekt'] ?? null;
            unset($delta['_position'], $delta['_objekt']);
            if (!isset($schnapp[$did])) {
                // Noch kein vollständiger Stand (z. B. direkt nach dem Start) —
                // ohne ihn ließen sich abgeleitete Werte nicht korrekt bilden;
                // der nächste reguläre Abruf holt alles.
                continue;
            }
            $schnapp[$did]['p'] = array_replace((array)$schnapp[$did]['p'], $delta);
            $daten = $this->treiber()->normalisiere((string)$schnapp[$did]['modell'], $schnapp[$did]['p']);
            $daten['online'] = true;
            $daten['echtzeit'] = $delta;   // geänderte Rohwerte mit Werten (für den Verlauf am Roboter)
            if ($position !== null) {
                $daten['position'] = $position;
            }
            if ($objekt !== null) {
                $daten['kartenobjekt'] = $objekt;
            }
            $this->SendDataToChildren((string)json_encode(['DataID' => self::DATA_ZUM_KIND, 'Geraet' => (string)$did, 'Daten' => $daten]));
            $this->mqttStand(['letzte' => time(), 'anzahl' => (int)((json_decode((string)$this->ReadAttributeString('Mqtt'), true)['anzahl'] ?? 0)) + 1]);
        }
        $this->WriteAttributeString('Schnappschuss', (string)json_encode($schnapp));
    }

    private function schnappschussMerken(string $did, string $modell, array $p): void
    {
        if (count($p) === 0) {
            return;
        }
        $schnapp = json_decode((string)$this->ReadAttributeString('Schnappschuss'), true) ?: [];
        $schnapp[$did] = ['modell' => $modell, 'p' => $p];
        $this->WriteAttributeString('Schnappschuss', (string)json_encode($schnapp));
    }

    private function mqttStand(array $neu): void
    {
        $stand = array_replace(json_decode((string)$this->ReadAttributeString('Mqtt'), true) ?: [], $neu);
        $this->WriteAttributeString('Mqtt', (string)json_encode($stand));
        $this->UpdateFormField('EchtzeitZeile', 'caption', $this->echtzeitZeile());
    }

    private function echtzeitZeile(): string
    {
        $s = json_decode((string)$this->ReadAttributeString('Mqtt'), true) ?: [];
        if (!$this->ReadPropertyBoolean('Echtzeit')) {
            return 'ℹ️ Echtzeit ist ausgeschaltet — die Roboter werden nur per Abfrage aktualisiert.';
        }
        $treiber = $this->treiber();
        if (method_exists($treiber, 'echtzeitMoeglich') && !$treiber->echtzeitMoeglich()) {
            return 'ℹ️ Für ' . (self::HERSTELLER[$this->ReadPropertyString('Hersteller')][1] ?? 'diesen Hersteller') . ' gibt es noch keine Echtzeit-Meldungen — die Roboter werden per Abfrage aktualisiert. Unter dem Konto hängt deshalb nur ein „Virtual IO" als Verbindungsglied, das Symcon verlangt.';
        }
        switch ($s['zustand'] ?? '') {
            case 'verbunden':
                return '🔗 Echtzeit verbunden mit ' . ($s['host'] ?? '?') . ' seit ' . date('d.m.Y H:i', (int)($s['seit'] ?? time())) . ' Uhr, '
                    . (int)($s['topics'] ?? 0) . ' Gerät(e) abonniert'
                    . (!empty($s['letzte']) ? ', letzte Meldung ' . date('H:i:s', (int)$s['letzte']) . ' Uhr (' . (int)($s['anzahl'] ?? 0) . ' insgesamt)' : ', noch keine Meldung') . '.';
            case 'verbinde':
                return '⏳ Echtzeit verbindet mit ' . ($s['host'] ?? '?') . ' …';
            case 'fehler':
                return '⛔ Echtzeit: ' . ($s['text'] ?? 'Fehler') . ' — die Abfrage läuft weiter.';
            case 'getrennt':
                return '⚠️ Echtzeit getrennt — Symcon verbindet automatisch neu; die Abfrage läuft weiter.';
            case 'fehlt':
                return '⚠️ Keine Verbindungsinstanz (Client Socket) — bitte „Übernehmen" klicken.';
            case 'zertifikat':
                return '⚠️ Die Echtzeit-Verbindung kam nicht zustande. Unter Symcon 9.1 lehnt die Verbindungsinstanz das Zertifikat des Herstellerservers ab (es gilt ab 31.12.1969, die neue TLS-Prüfung akzeptiert nichts vor 1970). RoboHub hat sie deshalb geschlossen und fragt die Roboter regelmäßig ab. Neuer Versuch am '
                    . date('d.m.Y H:i', $this->ReadAttributeInteger('EchtzeitGesperrtBis')) . ' Uhr oder per Knopf.';
            default:
                return $this->ReadAttributeString('Buendel') === '' ? 'ℹ️ Echtzeit startet nach der Anmeldung.' : 'ℹ️ Echtzeit noch nicht verbunden.';
        }
    }

    // -----------------------------------------------------------------
    // Intern
    // -----------------------------------------------------------------

    /** Meldet sich dieser Hersteller per E-Mail-Code statt Passwort an? */
    private function perCode(): bool
    {
        return (bool)(self::HERSTELLER[$this->ReadPropertyString('Hersteller')][2] ?? false);
    }

    /** Hersteller-Treiber; im Prüfstand über $GLOBALS austauschbar. */
    protected function treiber(): RoboHubTreiber
    {
        $treiber = $this->treiberNeu();
        if (property_exists($treiber, 'echtzeitVerbunden')) {
            // Mit laufender Echtzeit reicht ein seltener Vollabruf (Anfragelimit der Hersteller).
            $treiber->echtzeitVerbunden = (json_decode((string)$this->ReadAttributeString('Mqtt'), true)['zustand'] ?? '') === 'verbunden';
        }
        return $treiber;
    }

    private function treiberNeu(): RoboHubTreiber
    {
        if (isset($GLOBALS['robohubTreiberFabrik']) && is_callable($GLOBALS['robohubTreiberFabrik'])) {
            return ($GLOBALS['robohubTreiberFabrik'])($this->ReadPropertyString('Hersteller'));
        }
        $hersteller = (string)$this->ReadPropertyString('Hersteller');
        if ($hersteller === 'roborock') {
            $treiber = new RoboHubRoborock();
        } elseif (isset(RoboHubWorx::MARKEN[$hersteller])) {
            $treiber = new RoboHubWorx($hersteller);
        } elseif ($hersteller === 'ecovacs') {
            $treiber = new RoboHubEcovacs();
        } elseif ($hersteller === 'irobot') {
            $treiber = new RoboHubIrobot();
        } elseif ($hersteller === 'husqvarna') {
            $treiber = new RoboHubHusqvarna();
        } elseif ($hersteller === 'gardena') {
            $treiber = new RoboHubGardena();
        } elseif ($hersteller === 'navimow') {
            $treiber = new RoboHubNavimow();
        } elseif ($hersteller === 'indego') {
            $treiber = new RoboHubIndego();
        } elseif ($hersteller === 'narwal') {
            $treiber = new RoboHubNarwal();
        } elseif ($hersteller === 'stihl') {
            $treiber = new RoboHubStihl();
        } else {
            $treiber = new RoboHubDreame($hersteller);
        }
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
            $buendel = json_decode((string)$this->ReadAttributeString('Buendel'), true) ?: $buendel;
            $neu = $this->treiber()->erneuern($buendel);
            if ($neu !== $buendel) {
                $this->WriteAttributeString('Buendel', (string)json_encode($neu));
                if (($neu['access_token'] ?? '') !== ($buendel['access_token'] ?? '')) {
                    // Echtzeit-Anmeldung nutzt den Zugangsschlüssel → neu verbinden.
                    $this->echtzeitKonfigurieren(true);
                } elseif (($neu['token'] ?? '') !== ($buendel['token'] ?? '') && $this->echtzeitArt() === 'websocket') {
                    // WebSocket-Anmeldekopf trägt den Zugangsschlüssel → kurz danach mit neuem Kopf verbinden.
                    $this->SetTimerInterval('Verbindung', 2000);
                }
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

    /**
     * Zustand abfragen und dabei Abfrageblöcke überspringen, auf die dieses
     * Gerät zuletzt nicht geantwortet hat. Gesperrt wird ein Block nur, wenn
     * im selben Abruf mindestens ein anderer geantwortet hat (sonst war das
     * Gerät einfach kurz weg); nach BLOCK_SPERRE_S wird er erneut versucht.
     */
    private function zustandMitBlockSperre(array $buendel, array $geraet): array
    {
        $did = (string)$geraet['id'];
        $sperre = json_decode((string)$this->ReadAttributeString('BlockSperre'), true) ?: [];
        $jetzt = time();
        $geraet['ueberspringen'] = [];
        foreach ((array)($sperre[$did] ?? []) as $block => $bis) {
            if ((int)$bis > $jetzt) {
                $geraet['ueberspringen'][] = (int)$block;
            }
        }
        $daten = $this->treiber()->zustand($buendel, $geraet);
        $this->schnappschussMerken($did, (string)($geraet['modell'] ?? ''), (array)($daten['roh']['properties'] ?? []));

        $ok = (array)($daten['bloecke']['ok'] ?? []);
        $fehl = (array)($daten['bloecke']['fehl'] ?? []);
        if (count($ok) > 0) {
            foreach ($fehl as $block) {
                $sperre[$did][(int)$block] = $jetzt + self::BLOCK_SPERRE_S;
            }
            // Abgelaufene Sperren aufräumen (ein beantworteter Block war nur
            // dabei, weil seine Sperre abgelaufen war — er fällt hier mit raus).
            foreach ((array)($sperre[$did] ?? []) as $block => $bis) {
                if ((int)$bis <= $jetzt && !in_array((int)$block, $fehl, true)) {
                    unset($sperre[$did][$block]);
                }
            }
            if (empty($sperre[$did])) {
                unset($sperre[$did]);
            }
            $this->WriteAttributeString('BlockSperre', (string)json_encode($sperre));
        }
        return $daten;
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
        $this->protokolliere($e->getMessage(), KL_WARNING);
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
        foreach (json_decode((string)$this->ReadAttributeString('Geraete'), true) ?: [] as $g) {
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
        $n = count(json_decode((string)$this->ReadAttributeString('Geraete'), true) ?: []);
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

    private function protokolliere(string $text, int $stufe = KL_MESSAGE): void
    {
        $this->LogMessage($text, $stufe);
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
            'Diese Instanz verbindet Symcon mit deinem Hersteller-Konto für Saug-, Wisch- und Mähroboter (heute Dreame, MOVA, Roborock, Ecovacs, iRobot, Narwal sowie Husqvarna, GARDENA, Worx, Kress, LandXcape, Ferrex, Segway Navimow, Bosch Indego und STIHL iMOW). Du meldest dich einmal an — danach holt RoboHub Zustand, Akku und Verbrauchsteile deiner Roboter und kann sie starten, anhalten oder zur Station schicken.',
            'Der Nutzen: Roboter werden Teil deiner Automationen, z. B. „saugen, wenn niemand zu Hause ist" oder „Mähen bei Regen abbrechen" — ohne Hersteller-App. Die einzelnen Roboter legst du danach mit dem RoboHub-Konfigurator an.',
        ];
    }

    protected function robohubDocLines(): array
    {
        return [
            'Anmelden: Hersteller und Region wählen, E-Mail und Passwort deines Hersteller-Kontos eintragen, „Übernehmen", dann „🔑 Jetzt anmelden". Das Passwort wird nach erfolgreicher Anmeldung sofort verworfen; gespeichert bleibt nur der Zugangsschlüssel.',
            'Ecovacs: Land wählen, E-Mail und Passwort eintragen, anmelden. Verlangt Ecovacs eine Bestätigung, „📧 Code anfordern", den Code ins Passwortfeld eintragen, „Übernehmen", erneut anmelden. Weil Ecovacs keinen Erneuerungsschlüssel kennt, behält RoboHub einen Hash des Passworts für die automatische Neuanmeldung alle 7 Tage.',
            'Roborock: ohne Passwort — „📧 Code anfordern", den Code aus der E-Mail ins Feld eintragen, „Übernehmen", „🔑 Jetzt anmelden". Zustand und Befehle laufen verschlüsselt über den Roborock-Server; Kartenbild und Echtzeit-Meldungen folgen später.',
            'Sicherheit: Symcon verschlüsselt gespeicherte Werte nicht. Der Zugangsschlüssel steht nicht im Formular und nicht im Protokoll, liegt aber im Klartext in der Symcon-Datenbank — wie bei jedem Cloud-Modul.',
            'Mehrere Konten (z. B. ein Dreamehome-Konto für den Saugroboter und ein MOVAhome-Konto für den Mäher): je Konto eine eigene Konto-Instanz anlegen. Der Konfigurator zeigt trotzdem alle Roboter in einer Liste, und jeder Roboter verbindet sich selbst mit dem Konto, zu dem er gehört.',
            'Bremst die Hersteller-Cloud (zu viele Anfragen), wartet RoboHub die angegebene Zeit automatisch ab (Status „Cloud bremst") und macht danach von selbst weiter.',
            'Läuft der Zugangsschlüssel endgültig ab, zeigt die Instanz „Anmeldung erforderlich" — dann einfach erneut Passwort eintragen und anmelden.',
        ];
    }

    private function kontoPanel(): array
    {
        $roborock = $this->perCode() || $this->ReadAttributeString('AnmeldeVorstufe') !== '';
        $ecovacs = $this->ReadPropertyString('Hersteller') === 'ecovacs';
        $lokal = in_array($this->ReadPropertyString('Hersteller'), ['irobot', 'narwal'], true);
        $narwal = $this->ReadPropertyString('Hersteller') === 'narwal';
        $husqvarna = in_array($this->ReadPropertyString('Hersteller'), ['husqvarna', 'gardena'], true);
        $navimow = $this->ReadPropertyString('Hersteller') === 'navimow';
        $indego = $this->ReadPropertyString('Hersteller') === 'indego';
        $perLink = $navimow || $indego;
        $api = $this->ReadPropertyString('Hersteller') === 'gardena' ? 'GARDENA smart system API' : 'Automower Connect API';
        $mitRegion = (bool)(self::HERSTELLER[$this->ReadPropertyString('Hersteller')][3] ?? true);
        $auswahl = [];
        foreach (self::HERSTELLER as $wert => [$text]) {
            $auswahl[] = ['caption' => $text, 'value' => $wert];
        }
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
                ['type' => 'Select', 'name' => 'Hersteller', 'caption' => 'Hersteller / App', 'options' => $auswahl],
                ['type' => 'Select', 'name' => 'Region', 'caption' => $ecovacs ? 'Land des Kontos (wie in der App)' : 'Region des Kontos', 'options' => $ecovacs ? RoboHubEcovacs::regionen() : RoboHubDreame::regionen(), 'visible' => $mitRegion],
                ['type' => 'ValidationTextBox', 'name' => 'Konto', 'visible' => !$perLink, 'caption' => $lokal ? 'IP-Adresse des Roboters' : ($husqvarna ? 'Application Key' : 'E-Mail des Kontos')],
                ['type' => 'Label', 'visible' => $husqvarna, 'caption' => 'Husqvarna nutzt die offizielle Schnittstelle: Auf developer.husqvarnagroup.cloud mit dem Husqvarna-Konto anmelden, „Create application" (Redirect-URL beliebig, z. B. http://localhost), die APIs „Authentication API" und „' . $api . '" verbinden, dann Application Key und Application Secret hier eintragen. Wegen des Abfragelimits fragt RoboHub ' . ($api === 'GARDENA smart system API' ? 'höchstens alle 15 Minuten' : 'höchstens alle 5 Minuten') . ' ab.'],
                ['type' => 'Label', 'visible' => $narwal, 'caption' => 'Narwal läuft lokal, ohne Cloud und ohne Passwort: IP-Adresse des Roboters eintragen (am besten im Router fest vergeben), „Übernehmen", „🔑 Jetzt anmelden". Der Roboter muss eingeschaltet sein. Geht mit Flow, Flow 2, Freo Z10 Ultra/Pro, Freo X10 Pro, Freo 20/20 Edge und JX; Freo X Ultra, X Plus, J1/J4 und T10 haben keine lokale Schnittstelle. Für einen weiteren Narwal dasselbe mit dessen IP.'],
                ['type' => 'Label', 'visible' => $lokal && !$narwal, 'caption' => 'iRobot läuft lokal, ohne Cloud: Roboter auf die Station stellen, IP-Adresse eintragen, „Übernehmen". Einmal „🔑 Jetzt anmelden" drücken (RoboHub sucht den Roboter). Dann am Roboter die HOME-Taste etwa 2 Sekunden halten, bis Töne kommen, und sofort noch einmal „🔑 Jetzt anmelden" drücken. Für einen weiteren Roboter dasselbe mit dessen IP.'],
                ['type' => 'Label', 'visible' => $roborock, 'caption' => 'Roborock meldet sich ohne Passwort an: E-Mail eintragen, „Übernehmen", „📧 Code anfordern", den Code aus der E-Mail unten eintragen, „Übernehmen", „🔑 Jetzt anmelden". Die Region findet Roborock selbst.'],
                ['type' => 'Label', 'visible' => $navimow, 'caption' => 'Navimow meldet sich ohne Passwort bei RoboHub an: „🔗 Anmeldeseite öffnen", dort mit dem Navimow-Konto anmelden. Danach landet der Browser auf einer Seite, die nicht lädt (http://localhost:1/callback?code=…) — das ist gewollt. Diese Adresse aus der Adresszeile kopieren, unten eintragen, „Übernehmen", „🔑 Jetzt anmelden". Der Code gilt nur wenige Minuten.'],
                ['type' => 'Label', 'visible' => $navimow, 'caption' => 'Offen gesagt: Segway vergibt für Smart-Home-Systeme keine eigenen Zugänge. RoboHub nutzt deshalb, wie ioBroker, die von Segway veröffentlichte Smart-Home-Kennung „homeassistant". Auf der Anmeldeseite steht darum „Home Assistant" — gemeint ist diese Schnittstelle, nicht dein Home Assistant.'],
                ['type' => 'Label', 'visible' => $indego, 'caption' => 'Indego meldet sich ohne Passwort bei RoboHub an. Am besten in Chrome, Edge oder Firefox: zuerst die Entwicklerkonsole öffnen (F12, am Mac Wahl + Befehl + I), dann „🔗 Anmeldeseite öffnen" und mit der Bosch SingleKey ID anmelden. Danach passiert scheinbar nichts — die Konsole zeigt aber eine fehlgeschlagene Weiterleitung auf „com.bosch.indegoconnect://login?…code=…". Diese Adresse (oder die ganze Konsolenzeile) unten eintragen, „Übernehmen", „🔑 Jetzt anmelden". Der Code gilt nur wenige Minuten. So macht es auch ioBroker.'],
                ['type' => 'PasswordTextBox', 'name' => 'Passwort', 'visible' => !$lokal, 'caption' => $perLink ? 'Code bzw. Adresse nach der Anmeldung (wird danach verworfen)' : ($roborock ? 'Code aus der E-Mail (wird nach der Anmeldung verworfen)' : ($husqvarna ? 'Application Secret (bleibt im Konto gespeichert)' : 'Passwort (nur für die Anmeldung, wird danach verworfen)'))],
                ['type' => 'Label', 'name' => 'Ergebnis', 'caption' => $zeile],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '📧 Code anfordern', 'visible' => $roborock, 'onClick' => 'echo ROBOHUB_CodeAnfordern($id);'],
                    ['type' => 'Button', 'caption' => '🔗 Anmeldeseite öffnen', 'visible' => $perLink, 'onClick' => 'echo ROBOHUB_Anmeldelink($id);', 'link' => true],
                    ['type' => 'Button', 'caption' => '🔑 Jetzt anmelden', 'onClick' => 'echo ROBOHUB_Anmelden($id);'],
                    ['type' => 'Button', 'caption' => '⏏️ Abmelden', 'onClick' => 'echo ROBOHUB_Abmelden($id);'],
                ]],
                ['type' => 'PopupButton', 'caption' => 'Welche Region hat mein Konto?', 'width' => '460px', 'visible' => $mitRegion && !$ecovacs, 'popup' => [
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
                ['type' => 'Label', 'caption' => 'Angelegt werden die Roboter über den „RoboHub Konfigurator" — ein einziger Konfigurator zeigt die Roboter aller RoboHub-Konten.'],
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
        $echtzeit = [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '⚡  Echtzeit-Meldungen',
            'items' => [
                ['type' => 'CheckBox', 'name' => 'Echtzeit', 'caption' => 'Änderungen sofort von der Hersteller-Cloud empfangen (statt nur alle paar Minuten abzufragen)'],
                ['type' => 'Label', 'name' => 'EchtzeitZeile', 'caption' => $this->echtzeitZeile()],
                ['type' => 'Button', 'caption' => '🔁 Echtzeit jetzt erneut versuchen', 'visible' => $this->ReadAttributeInteger('EchtzeitGesperrtBis') > time(), 'onClick' => 'ROBOHUB_EchtzeitNeuVersuchen($id);'],
                ['type' => 'Label', 'caption' => 'Technik: verschlüsselte MQTT-Verbindung zum Server des Herstellers über eine eigene Client-Socket-Instanz. Das Server-Zertifikat wird gegen die Zertifizierungsstelle des Herstellers geprüft (Zertifikat und Servername) — fremde Server werden abgelehnt.'],
                ['type' => 'Label', 'caption' => 'Die regelmäßige Abfrage läuft zusätzlich weiter (Sicherheitsnetz). Karten- und Positionsdaten werden nicht verarbeitet.'],
            ],
        ];
        return $this->robohubBuildForm([$this->kontoPanel(), $this->geraetePanel(), $echtzeit], [], $status);
    }
}
