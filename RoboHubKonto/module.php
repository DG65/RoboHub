<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/RoboHubModell.php';
require_once __DIR__ . '/../libs/RoboHubFormular.php';
require_once __DIR__ . '/../libs/RoboHubDreame.php';
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

    private const DATA_ZUM_KONTO = '{CFA82D8B-B9F1-4CAF-BFB7-E898E3690581}';
    private const DATA_ZUM_KIND  = '{4949DAA0-C934-4184-91E2-2D3E90D4DC24}';

    // Echtzeit (MQTT) über Symcons Client Socket (TLS).
    private const CLIENT_SOCKET  = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
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
        $this->RegisterPropertyBoolean('Echtzeit', true);
        $this->RegisterTimer('MqttPing', 0, 'ROBOHUB_MqttPing($_IPS[\'TARGET\']);');
        $this->RequireParent(self::CLIENT_SOCKET);
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
        $this->elternBeobachten();
        $this->echtzeitKonfigurieren(false);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && ($Data[0] ?? null) === KR_READY) {
            $this->ApplyChanges();
            return;
        }
        // Client Socket verbunden/getrennt (Muster Tibber Grid Reward).
        if ($Message === IM_CHANGESTATUS && $SenderID === $this->ReadAttributeInteger('MqttEltern')) {
            if (($Data[0] ?? 0) === IS_ACTIVE) {
                $this->mqttAnmelden();
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
        $this->echtzeitKonfigurieren(true);
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
        return (string)json_encode([
            'hersteller' => $hersteller,
            'anzeige'    => ($hersteller === 'mova' ? 'MOVA' : 'Dreame') . ($this->ReadPropertyString('Konto') !== '' ? ' (' . $this->ReadPropertyString('Konto') . ')' : ''),
            'angemeldet' => $this->ReadAttributeString('Buendel') !== '',
            'stand'      => $this->ReadAttributeInteger('GeraeteStand'),
            'geraete'    => json_decode($this->ReadAttributeString('Geraete'), true) ?: [],
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
                return $this->zustandMitBlockSperre($buendel, $geraet);
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
    // Echtzeit (MQTT)
    // -----------------------------------------------------------------

    /** Einstellungen für den Client Socket (Symcon wendet sie beim Verbinden an). */
    public function GetConfigurationForParent()
    {
        $an = $this->ReadPropertyBoolean('Echtzeit') && $this->ReadAttributeString('Buendel') !== '';
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
        $buendel = json_decode($this->ReadAttributeString('Buendel'), true) ?: [];
        $geraete = json_decode($this->ReadAttributeString('Geraete'), true) ?: [];
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
        $soll = json_decode($this->GetConfigurationForParent(), true);
        $ist = json_decode((string)IPS_GetConfiguration($eltern), true) ?: [];
        $anders = false;
        foreach ($soll as $k => $v) {
            if (($ist[$k] ?? null) != $v) {
                $anders = true;
            }
        }
        if (!$soll['Open']) {
            $this->SetTimerInterval('MqttPing', 0);
            $this->mqttStand(['zustand' => 'aus']);
        }
        if (!$anders && !($neuVerbinden && $soll['Open'])) {
            return;
        }
        // Wie Tibber Grid Reward: per Skript, damit das ApplyChanges des
        // Sockets nicht im eigenen Aufruf hängt; IM_CHANGESTATUS meldet das Ergebnis.
        $skript = '';
        if ($neuVerbinden && $soll['Open'] && !$anders) {
            $skript .= 'IPS_SetProperty(' . $eltern . ', "Open", false); IPS_ApplyChanges(' . $eltern . ');';
        }
        $skript .= 'IPS_SetConfiguration(' . $eltern . ', ' . var_export((string)json_encode($soll), true) . '); IPS_ApplyChanges(' . $eltern . ');';
        if ($soll['Open']) {
            $this->mqttStand(['zustand' => 'verbinde', 'host' => $soll['Host'] . ':' . $soll['Port']]);
        }
        IPS_RunScriptText($skript);
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
        $stand = json_decode($this->ReadAttributeString('Mqtt'), true) ?: [];
        if (($stand['zustand'] ?? '') !== 'verbunden') {
            return;
        }
        if (time() - (int)($stand['lebenszeichen'] ?? 0) > 3 * self::MQTT_KEEPALIVE) {
            $this->protokolliere('Echtzeit-Verbindung antwortet nicht mehr — verbinde neu.');
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
                    $this->protokolliere($text);
                    return;
                }
                $topics = [];
                $buendel = json_decode($this->ReadAttributeString('Buendel'), true) ?: [];
                foreach (json_decode($this->ReadAttributeString('Geraete'), true) ?: [] as $g) {
                    $topics[] = $this->treiber()->mqttTopic($g, $buendel);
                }
                if (count($topics) > 0) {
                    $this->mqttSenden(RoboHubMqtt::subscribe(1, $topics, 0));
                }
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
        $aenderungen = $this->treiber()->pushLesen($payload);
        if (count($aenderungen) === 0) {
            return;
        }
        $schnapp = json_decode($this->ReadAttributeString('Schnappschuss'), true) ?: [];
        foreach ($aenderungen as $did => $delta) {
            if (!isset($schnapp[$did])) {
                // Noch kein vollständiger Stand (z. B. direkt nach dem Start) —
                // ohne ihn ließen sich abgeleitete Werte nicht korrekt bilden;
                // der nächste reguläre Abruf holt alles.
                continue;
            }
            $schnapp[$did]['p'] = array_replace((array)$schnapp[$did]['p'], $delta);
            $daten = $this->treiber()->normalisiere((string)$schnapp[$did]['modell'], $schnapp[$did]['p']);
            $daten['online'] = true;
            $daten['echtzeit'] = array_keys($delta);
            $this->SendDataToChildren((string)json_encode(['DataID' => self::DATA_ZUM_KIND, 'Geraet' => (string)$did, 'Daten' => $daten]));
            $this->mqttStand(['letzte' => time(), 'anzahl' => (int)((json_decode($this->ReadAttributeString('Mqtt'), true)['anzahl'] ?? 0)) + 1]);
        }
        $this->WriteAttributeString('Schnappschuss', (string)json_encode($schnapp));
    }

    private function schnappschussMerken(string $did, string $modell, array $p): void
    {
        if (count($p) === 0) {
            return;
        }
        $schnapp = json_decode($this->ReadAttributeString('Schnappschuss'), true) ?: [];
        $schnapp[$did] = ['modell' => $modell, 'p' => $p];
        $this->WriteAttributeString('Schnappschuss', (string)json_encode($schnapp));
    }

    private function mqttStand(array $neu): void
    {
        $stand = array_replace(json_decode($this->ReadAttributeString('Mqtt'), true) ?: [], $neu);
        $this->WriteAttributeString('Mqtt', (string)json_encode($stand));
        $this->UpdateFormField('EchtzeitZeile', 'caption', $this->echtzeitZeile());
    }

    private function echtzeitZeile(): string
    {
        $s = json_decode($this->ReadAttributeString('Mqtt'), true) ?: [];
        if (!$this->ReadPropertyBoolean('Echtzeit')) {
            return 'ℹ️ Echtzeit ist ausgeschaltet — die Roboter werden nur per Abfrage aktualisiert.';
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
            default:
                return $this->ReadAttributeString('Buendel') === '' ? 'ℹ️ Echtzeit startet nach der Anmeldung.' : 'ℹ️ Echtzeit noch nicht verbunden.';
        }
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
                if (($neu['access_token'] ?? '') !== ($buendel['access_token'] ?? '')) {
                    // Echtzeit-Anmeldung nutzt den Zugangsschlüssel → neu verbinden.
                    $this->echtzeitKonfigurieren(true);
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
        $sperre = json_decode($this->ReadAttributeString('BlockSperre'), true) ?: [];
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
            'Mehrere Konten (z. B. ein Dreamehome-Konto für den Saugroboter und ein MOVAhome-Konto für den Mäher): je Konto eine eigene Konto-Instanz anlegen. Der Konfigurator zeigt trotzdem alle Roboter in einer Liste, und jeder Roboter verbindet sich selbst mit dem Konto, zu dem er gehört.',
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
                ['type' => 'Label', 'caption' => 'Technik: verschlüsselte MQTT-Verbindung zum Server des Herstellers über eine eigene Client-Socket-Instanz. Das Server-Zertifikat wird gegen die Zertifizierungsstelle des Herstellers geprüft (Zertifikat und Servername) — fremde Server werden abgelehnt.'],
                ['type' => 'Label', 'caption' => 'Die regelmäßige Abfrage läuft zusätzlich weiter (Sicherheitsnetz). Karten- und Positionsdaten werden nicht verarbeitet.'],
            ],
        ];
        return $this->robohubBuildForm([$this->kontoPanel(), $this->geraetePanel(), $echtzeit], [], $status);
    }
}
