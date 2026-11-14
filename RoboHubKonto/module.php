<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/RoboHubModell.php';
require_once __DIR__ . '/../libs/RoboHubFormular.php';
require_once __DIR__ . '/../libs/RoboHubDreame.php';
require_once __DIR__ . '/../libs/RoboHubMqttSitzung.php';
require_once __DIR__ . '/../libs/RoboHubTls.php';
require_once __DIR__ . '/../libs/RoboHubRoborock.php';
require_once __DIR__ . '/../libs/RoboHubXiaomi.php';
require_once __DIR__ . '/../libs/RoboHubWorx.php';
require_once __DIR__ . '/../libs/RoboHubEcovacs.php';
require_once __DIR__ . '/../libs/RoboHubIrobot.php';
require_once __DIR__ . '/../libs/RoboHubHusqvarna.php';
require_once __DIR__ . '/../libs/RoboHubGardena.php';
require_once __DIR__ . '/../libs/RoboHubNavimow.php';
require_once __DIR__ . '/../libs/RoboHubIndego.php';
require_once __DIR__ . '/../libs/RoboHubNarwal.php';
require_once __DIR__ . '/../libs/RoboHubStihl.php';
require_once __DIR__ . '/../libs/RoboHubAlko.php';
require_once __DIR__ . '/../libs/RoboHubVorwerk.php';
require_once __DIR__ . '/../libs/RoboHubMiele.php';
require_once __DIR__ . '/../libs/RoboHubKaercher.php';
require_once __DIR__ . '/../libs/RoboHubEufy.php';
require_once __DIR__ . '/../libs/RoboHubEufyMaeher.php';
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

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        // Treiber, Modell und Kachel übersetzen über RoboHubText (englische Quelltexte, Deutsch in locale.json).
        RoboHubText::setzen(function (string $text): string {
            return $this->Translate($text);
        });
    }

    // Hersteller: Wert im Select => [Anzeige im Select, Kurzname, Anmeldung per Code, Regionswahl].
    private const HERSTELLER = [
        'dreame'    => ['Dreame (app "Dreamehome")', 'Dreame', false, true],
        'mova'      => ['MOVA (app "MOVAhome")', 'MOVA', false, true],
        'roborock'  => ['Roborock (app "Roborock")', 'Roborock', true, false],
        'xiaomi'    => ['Roborock in the app "Mi Home" (Xiaomi account, controlled locally in your home network)', 'Xiaomi', false, true],
        'worx'      => ['Worx Landroid (app "Landroid")', 'Worx', false, false],
        'kress'     => ['Kress (app "Kress Mission")', 'Kress', false, false],
        'landxcape' => ['LandXcape (app "LandXcape")', 'LandXcape', false, false],
        'ferrex'    => ['Ferrex (app "Smart Mower")', 'Ferrex', false, false],
        'ecovacs'   => ['Ecovacs Deebot/GOAT (app "Ecovacs Home")', 'Ecovacs', false, true],
        'irobot'    => ['iRobot Roomba/Braava (locally in your home network, no cloud)', 'iRobot', false, false],
        'husqvarna' => ['Husqvarna Automower (official Automower Connect API)', 'Husqvarna', false, false],
        'gardena'   => ['GARDENA smart system robot mower (official API)', 'GARDENA', false, false],
        'navimow'   => ['Segway Navimow (app "Navimow")', 'Navimow', false, false],
        'indego'    => ['Bosch Indego (app "Bosch Smart Gardening")', 'Indego', false, false],
        'stihl'     => ['STIHL iMOW (app "iMOW", all generations)', 'STIHL', false, false],
        'alko'      => ['AL-KO Robolinho (app "inTOUCH", official API)', 'AL-KO', false, false],
        'vorwerk'   => ['Vorwerk Kobold VR200/VR300 (app "MyKobold")', 'Vorwerk', true, false],
        'miele'     => ['Miele Scout (official Miele API)', 'Miele', false, true],
        'kaercher'  => ['Kärcher RCV/RCF/RVC (app "Kärcher Home Robots")', 'Kärcher', false, true],
        'eufy'      => ['Eufy RoboVac/Omni, robot vacuums only (app "Eufy Clean" or "Eufy")', 'Eufy', false, false],
        'eufymaeher' => ['Eufy robot mower E15 (locally in your home network, device key fetched once via the Eufy account)', 'Eufy mower', false, false],
        'narwal'    => ['Narwal Flow/Freo (locally in your home network, no cloud)', 'Narwal', false, false],
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
        $this->RegisterPropertyString('FormLayout', '[]');   // „Formular: Aufbau" (SUITE.md 3a)
        $this->RegisterPropertyString('Region', 'eu');
        $this->RegisterPropertyString('Konto', '');
        // Nur Eingabe für die Erstanmeldung, wird nach Erfolg sofort geleert.
        $this->RegisterPropertyString('Passwort', '');
        // AL-KO: selbst beantragter API-Zugang. Das Secret wird nach der Anmeldung ins Bündel übernommen und hier geleert.
        $this->RegisterPropertyString('ClientId', '');
        $this->RegisterPropertyString('MaeherIP', '');
        $this->RegisterAttributeInteger('ElternEingerichtet', 0);   // Verbindungsinstanz, deren feste Einstellungen gesetzt sind
        $this->RegisterPropertyString('ClientSecret', '');

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
        // Xiaomi: nach „QR-Code anzeigen“ alle 3 s nachfragen, ob in der Mi-Home-App gescannt wurde.
        $this->RegisterTimer('QrWarten', 0, 'ROBOHUB_QrWarten($_IPS[\'TARGET\']);');
    }

    /**
     * Passende Verbindungsinstanz für die Konsole (ab Symcon 8.2, SUITE.md Stolperstein 28): je nach Hersteller Client
     * Socket, WebSocket Client oder Virtual IO. Ohne diese Angabe meldete die Konsole das Virtual IO eines Roborock-Kontos
     * als „inkompatible übergeordnete Instanz“ (Forum lugge 14.11.2026), weil RequireParent fest den Client Socket nennt.
     */
    public function GetCompatibleParents()
    {
        return (string)json_encode(['type' => 'require', 'moduleIDs' => [$this->elternModul()]]);
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
        // Steht die Verbindung schon beim Start im Fehlerstatus, kommt kein Statuswechsel mehr (Nr. 27).
        if ($this->ReadAttributeBoolean('EchtzeitJeVerbunden') && $this->elternImFehler() && $this->ReadAttributeInteger('WsFehler') === 0) {
            $this->neuVerbindenPlanen();
        }
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
                $this->neuVerbindenErfolgreich();
                $this->mqttAnmelden();
            } elseif ((int)($Data[0] ?? 0) >= 200 && !$this->ReadAttributeBoolean('EchtzeitJeVerbunden')
                && $this->ReadPropertyBoolean('Echtzeit') && $this->ReadAttributeString('Buendel') !== '') {
                $this->echtzeitSperren();
            } else {
                $this->SetTimerInterval('MqttPing', 0);
                $this->mqttStand(['zustand' => $this->ReadPropertyBoolean('Echtzeit') ? 'getrennt' : 'aus']);
                if ((int)($Data[0] ?? 0) >= IS_EBASE) {
                    $this->neuVerbindenPlanen();
                }
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
            return $this->melde(RoboHubText::t('⛔ First click "🔗 Open sign-in page", sign in there and enter the address the browser lands on into the "Code" field, click "Apply", then sign in.'));
        }
        if (!method_exists($treiber, 'anmeldeLink') && ($konto === '' || $pass === '')) {
            return $this->melde(RoboHubText::t('⛔ First enter the account (e-mail) and password (for Roborock: the code from the e-mail), click "Apply" and then sign in.'));
        }
        $vorstufe = json_decode((string)$this->ReadAttributeString('AnmeldeVorstufe'), true) ?: [];
        if (property_exists($treiber, 'zusatz')) {
            $treiber->zusatz = ['clientId' => (string)$this->ReadPropertyString('ClientId'), 'clientSecret' => (string)$this->ReadPropertyString('ClientSecret'), 'ip' => (string)$this->ReadPropertyString('MaeherIP')];
        }
        try {
            if ($vorstufe !== [] && method_exists($treiber, 'anmeldenMitCode')) {
                $buendel = $treiber->anmeldenMitCode($konto, $pass, $vorstufe);
            } else {
                $buendel = $treiber->anmelden($konto, $pass, (string)$this->ReadPropertyString('Region'));
            }
        } catch (Throwable $e) {
            $this->WriteAttributeString('LetzterFehler', $e->getMessage());
            $this->protokolliere(RoboHubText::t('Sign-in failed: %s', $e->getMessage()), KL_WARNING);
            if ($e instanceof RoboHubFehler && $e->vorstufe !== []) {
                // Zweite Stufe nötig: Zwischenstand merken, Passwortfeld für den Code freimachen.
                $this->WriteAttributeString('AnmeldeVorstufe', (string)json_encode($e->vorstufe));
                $text = '📧 ' . $e->getMessage();
                $this->UpdateFormField('Ergebnis', 'caption', $text);
                IPS_SetProperty($this->InstanceID, 'Passwort', '');
                IPS_ApplyChanges($this->InstanceID);
                return $text;
            }
            return $this->melde(RoboHubText::t('⛔ Sign-in failed: %s', $e->getMessage()));
        }
        return $this->anmeldungAbschliessen($buendel, $treiber);
    }

    /** Gemeinsames Ende jeder erfolgreichen Anmeldung (Passwort, Code oder QR-Code). */
    private function anmeldungAbschliessen(array $buendel, RoboHubTreiber $treiber): string
    {
        $this->WriteAttributeString('AnmeldeVorstufe', '');
        $this->WriteAttributeString('Buendel', (string)json_encode($buendel));
        $this->WriteAttributeString('LetzterFehler', '');
        $this->WriteAttributeInteger('WarteBis', 0);

        $hersteller = $this->ReadPropertyString('Hersteller');
        if ($hersteller === 'ecovacs') {
            $text = RoboHubText::t('✅ Signed in. Ecovacs has no refresh token: RoboHub keeps a hash of the password (not the password itself) to sign in again by itself every 7 days. Signing out deletes it.');
        } elseif (method_exists($treiber, 'anmeldeText')) {
            $text = $treiber->anmeldeText($buendel);
        } elseif ($hersteller === 'navimow' || $hersteller === 'indego') {
            $text = RoboHubText::t('✅ Signed in. The access token is stored, the code was discarded — RoboHub does not know your %s password.', ($hersteller === 'indego' ? 'Bosch' : 'Segway'));
        } elseif ($hersteller === 'husqvarna' || $hersteller === 'gardena') {
            $text = RoboHubText::t('✅ Signed in. Application key and secret stay stored in the account because Husqvarna/GARDENA only renews access with them (every 24 h). You can revoke them in the developer portal at any time; signing out deletes them here.');
        } else {
            $text = RoboHubText::t('✅ Signed in. The access token is stored, %s was discarded.', ($this->perCode() ? RoboHubText::t('the code') : RoboHubText::t('the password')));
        }
        try {
            $geraete = $this->ladeGeraete($buendel);
            $text .= "\n" . $this->geraeteZusammenfassung($geraete);
        } catch (Throwable $e) {
            $text .= RoboHubText::t("\n⚠️ The device list could not be loaded yet (%s). The configurator will try again.", $e->getMessage());
        }

        // Passwort sofort unwirksam machen (Sicherheitsfall aus SUITE.md
        // 9l: SetProperty+ApplyChanges ist hier nötig und steht am ENDE,
        // nach allen UpdateFormField-Aufrufen).
        $this->UpdateFormField('Ergebnis', 'caption', $text);
        $this->echtzeitKonfigurieren(true);
        if ($this->ReadPropertyString('Passwort') !== '' || $this->ReadPropertyString('ClientSecret') !== '') {
            IPS_SetProperty($this->InstanceID, 'Passwort', '');
            if ($this->ReadPropertyString('ClientSecret') !== '') {
                IPS_SetProperty($this->InstanceID, 'ClientSecret', '');   // steht jetzt im Bündel (Attribut)
            }
            IPS_ApplyChanges($this->InstanceID);
        }
        return $text;
    }

    /**
     * ROBOHUB_QrAnzeigen($id): string — Xiaomi: QR-Code für die Anmeldung holen und im Formular zeigen.
     * In der Mi-Home-App scannen (Profil → Scannen), dort bestätigen; RoboHub fragt danach selbst nach.
     */
    public function QrAnzeigen(): string
    {
        $treiber = $this->treiber();
        if (!method_exists($treiber, 'qrStarten')) {
            return $this->melde(RoboHubText::t('ℹ️ This vendor does not sign in with a QR code.'));
        }
        try {
            $qr = $treiber->qrStarten();
        } catch (Throwable $e) {
            return $this->melde('⛔ ' . $e->getMessage());
        }
        $this->SetBuffer('Qr', (string)json_encode(array_diff_key($qr, ['bild' => 1])));
        $this->SetTimerInterval('QrWarten', 3000);
        $this->UpdateFormField('QrBild', 'image', $qr['bild']);
        $this->UpdateFormField('QrBild', 'visible', true);
        return $this->melde(RoboHubText::t('📱 Scan the QR code with the Mi Home app (profile → scan icon) and confirm the sign-in there. RoboHub notices it by itself — valid until %s.', date('H:i', (int)$qr['ende'])));
    }

    /** Timer nach QrAnzeigen: nachfragen, ob gescannt wurde. */
    public function QrWarten(): void
    {
        $qr = json_decode((string)$this->GetBuffer('Qr'), true);
        if (!is_array($qr) || time() > (int)($qr['ende'] ?? 0)) {
            $this->SetTimerInterval('QrWarten', 0);
            $this->SetBuffer('Qr', '');
            if (is_array($qr)) {
                $this->UpdateFormField('QrBild', 'visible', false);
                $this->melde(RoboHubText::t('⌛ The QR code has expired — click "📱 Show QR code" again.'));
            }
            return;
        }
        $treiber = $this->treiber();
        try {
            $buendel = $treiber->qrPruefen($qr);
        } catch (Throwable $e) {
            $this->SetTimerInterval('QrWarten', 0);
            $this->SetBuffer('Qr', '');
            $this->WriteAttributeString('LetzterFehler', $e->getMessage());
            $this->protokolliere(RoboHubText::t('Sign-in failed: %s', $e->getMessage()), KL_WARNING);
            $this->melde(RoboHubText::t('⛔ Sign-in failed: %s', $e->getMessage()));
            return;
        }
        if ($buendel === null) {
            return;
        }
        $this->SetTimerInterval('QrWarten', 0);
        $this->SetBuffer('Qr', '');
        $buendel['server'] = (string)$this->ReadPropertyString('Region');
        $this->UpdateFormField('QrBild', 'visible', false);
        $this->protokolliere(RoboHubText::t('Signed in with the QR code.'));
        $this->anmeldungAbschliessen($buendel, $treiber);
    }

    /** Lokale Roboter (iRobot): unter der IP koppeln, vorhandene Roboter bleiben im Bündel. */
    private function koppeln($treiber, string $ip): string
    {
        try {
            $buendel = $treiber->koppeln($ip, json_decode((string)$this->ReadAttributeString('Buendel'), true) ?: []);
        } catch (Throwable $e) {
            $this->WriteAttributeString('LetzterFehler', $e->getMessage());
            $this->protokolliere(RoboHubText::t('Pairing failed: %s', $e->getMessage()), KL_WARNING);
            return $this->melde(RoboHubText::t('⛔ Pairing failed: %s', $e->getMessage()));
        }
        $this->WriteAttributeString('Buendel', (string)json_encode($buendel));
        $this->WriteAttributeString('LetzterFehler', '');
        $text = method_exists($treiber, 'koppelText') ? $treiber->koppelText()
            : RoboHubText::t('✅ Paired. The device password and the fingerprint of the robot\'s certificate are stored; a device with a different certificate will be rejected from now on.');
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
            return $this->melde(RoboHubText::t('⛔ First enter the account\'s e-mail and click "Apply".'));
        }
        $treiber = $this->treiber();
        if (!method_exists($treiber, 'codeAnfordern')) {
            return $this->melde(RoboHubText::t('ℹ️ This vendor signs in with the password — no code needed.'));
        }
        try {
            $text = $treiber->codeAnfordern($konto, json_decode((string)$this->ReadAttributeString('AnmeldeVorstufe'), true) ?: []);
        } catch (Throwable $e) {
            $this->protokolliere(RoboHubText::t('Requesting the code failed: %s', $e->getMessage()), KL_WARNING);
            return $this->melde('⛔ ' . $e->getMessage());
        }
        return $this->melde(RoboHubText::t('📧 %s Enter the code into the field "Password or code", click "Apply", then "🔑 Sign in now".', $text));
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
        return $this->melde(RoboHubText::t('⏏️ Signed out — the access token was deleted. The robot instances are kept and continue after a new sign-in.'));
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
            'anzeige'    => RoboHubText::wert(self::HERSTELLER[$hersteller][1] ?? $hersteller) . ($this->ReadPropertyString('Konto') !== '' ? ' (' . $this->ReadPropertyString('Konto') . ')' : ''),
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
        $this->protokolliere(RoboHubText::t('Real-time connection fails (under Symcon 9.1 the certificate of the vendor server is rejected) — connection closed for 24 h, polling continues.'), KL_WARNING);
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
            return (string)json_encode(['ok' => false, 'fehler' => RoboHubText::t('Invalid request.')]);
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
                // Echtzeit-Verbindung steht (robot_activity-Vertrag: validUntil); rein lokal, keine Cloud-Anfrage.
                'echtzeit'   => (json_decode((string)$this->ReadAttributeString('Mqtt'), true)['zustand'] ?? '') === 'verbunden',
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
        throw new RoboHubFehler(RoboHubText::t('Unknown action: %s', $aktion));
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
        // Store-Review 2026: Feste Einstellungen (TLS, Zertifikat) kommen über GetConfigurationForParent und werden nur beim
        // ersten Einrichten dieser Verbindungsinstanz gesetzt; danach schreibt RoboHub nur noch die veränderlichen Werte.
        $erstmals = $this->ReadAttributeInteger('ElternEingerichtet') !== $eltern;
        $veraenderlich = $ws ? ['Active', 'URL', 'Headers'] : ['Open', 'Host', 'Port'];
        $schreiben = [];
        foreach ($soll as $k => $v) {
            if (($erstmals || in_array($k, $veraenderlich, true)) && ($ist[$k] ?? null) != $v) {
                $schreiben[$k] = $v;
            }
        }
        $anders = $schreiben !== [];
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
            $schreiben[$offen] = true;
        }
        if ($erstmals) {
            $skript .= 'IPS_SetConfiguration(' . $eltern . ', ' . var_export((string)json_encode($schreiben), true) . ');';
            $this->WriteAttributeInteger('ElternEingerichtet', $eltern);
        } else {
            foreach ($schreiben as $k => $v) {
                $skript .= 'IPS_SetProperty(' . $eltern . ', ' . var_export((string)$k, true) . ', ' . var_export($v, true) . ');';
            }
        }
        $skript .= 'IPS_ApplyChanges(' . $eltern . ');';
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
        // SUITE.md Stolperstein 27: Ein Client Socket / WebSocket Client im Fehlerstatus verbindet sich
        // nicht zuverlässig selbst neu — dann schaltet RoboHub ihn selbst aus und wieder an.
        $fehler = $this->elternImFehler();
        if ($this->echtzeitArt() === 'websocket') {
            // Ziel (Adresse + Anmeldekopf) frisch holen: GARDENA vergibt je Verbindung eine neue Adresse,
            // Husqvarna braucht den aktuellen Zugangsschlüssel. Danach neu verbinden.
            $geaendert = $this->wsZielAktualisieren();
            $this->echtzeitKonfigurieren($geaendert || $fehler);
        } else {
            $this->echtzeitKonfigurieren($fehler);
        }
        if ($fehler) {
            // Rückfall, falls der Socket im Fehlerstatus bleibt und kein neuer Statuswechsel kommt;
            // ein neuer Fehlerstatus plant über MessageSink mit größerem Abstand nach.
            $this->SetTimerInterval('Verbindung', $this->neuVerbindenAbstand(max(1, $this->ReadAttributeInteger('WsFehler'))) * 1000);
        }
    }

    /** Eigene Verbindungsinstanz im Fehlerstatus (≥ IS_EBASE), obwohl Echtzeit gewünscht und möglich ist. */
    private function elternImFehler(): bool
    {
        $eltern = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        return $eltern > 0 && $this->echtzeitArt() !== ''
            && IPS_GetInstance($eltern)['ModuleInfo']['ModuleID'] === $this->elternModul()
            && IPS_GetInstance($eltern)['InstanceStatus'] >= IS_EBASE
            && $this->ReadPropertyBoolean('Echtzeit') && $this->ReadAttributeString('Buendel') !== ''
            && $this->ReadAttributeInteger('EchtzeitGesperrtBis') <= time();
    }

    /**
     * Wartezeit vor dem n-ten Neuversuch: 5, 10, 20 … s, höchstens 5 min (SUITE.md Nr. 27).
     * WebSocket (Husqvarna/GARDENA) höchstens 1 h: Jeder Versuch holt dort ein neues Ziel über die
     * Hersteller-Schnittstelle, deren Monatskontingent sonst ein Dauerausfall aufbrauchen würde.
     */
    private function neuVerbindenAbstand(int $n): int
    {
        return (int)min($this->echtzeitArt() === 'websocket' ? 3600 : 300, 5 * (2 ** min(max($n, 1) - 1, 10)));
    }

    /** Nach einem Verbindungsabbruch den nächsten Neuversuch planen; nur der erste wird protokolliert. */
    private function neuVerbindenPlanen(): void
    {
        if (!$this->ReadPropertyBoolean('Echtzeit') || $this->ReadAttributeString('Buendel') === '' || $this->ReadAttributeInteger('EchtzeitGesperrtBis') > time()) {
            return;
        }
        $n = $this->ReadAttributeInteger('WsFehler') + 1;
        $this->WriteAttributeInteger('WsFehler', $n);
        if ($n === 1) {
            $this->protokolliere(RoboHubText::t('Real-time connection lost — RoboHub reconnects automatically (further attempts are not logged).'), KL_WARNING);
        }
        $this->SetTimerInterval('Verbindung', $this->neuVerbindenAbstand($n) * 1000);
    }

    /** Verbindung steht: Zähler zurücksetzen, nach Neuversuchen einmal melden. */
    private function neuVerbindenErfolgreich(): void
    {
        $n = $this->ReadAttributeInteger('WsFehler');
        if ($n > 0) {
            $this->WriteAttributeInteger('WsFehler', 0);
            $this->protokolliere(RoboHubText::t('Real-time connection restored after %s attempt(s).', $n), KL_MESSAGE);
        }
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
            $this->neuVerbindenPlanen();
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
            $this->neuVerbindenErfolgreich();
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
            $this->neuVerbindenPlanen();
            return;
        }
        $this->mqttStand(['zustand' => $this->ReadPropertyBoolean('Echtzeit') ? 'getrennt' : 'aus']);
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
            if (IPS_GetInstance($eltern)['ModuleInfo']['ModuleID'] === $this->elternModul() || !$this->elternVonRoboHub($eltern)) {
                return;
            }
            IPS_DisconnectInstance($this->InstanceID);
            @IPS_DeleteInstance($eltern);
            $this->protokolliere(RoboHubText::t('Replaced the connection instance with %s.', ['websocket' => RoboHubText::t('a WebSocket Client'), 'mqtt' => RoboHubText::t('a Client Socket'), '' => RoboHubText::t('a Virtual IO')][$this->echtzeitArt()]));
        }
        $id = @IPS_CreateInstance($this->elternModul());
        if (!$id) {
            $this->protokolliere(RoboHubText::t('The connection instance below the account could not be created.'), KL_WARNING);
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

    /**
     * Stammt die Verbindungsinstanz von RoboHub? Ja, wenn RoboHub sie benannt hat („RoboHub …") oder wenn sie
     * Dreames private Wurzel trägt, die nur RoboHub dort einträgt — so legt die Konsole beim Neuanlegen den
     * Client Socket an, solange noch „Dreame" voreingestellt ist (Forum lugge 13.11.2026: Roborock-Konto hing
     * an diesem Socket, Dreames Server trennte alle 1–2 min, „Fehler beim Lesen: End of file").
     * Nie tauschen, wenn noch eine andere Instanz daran hängt.
     */
    private function elternVonRoboHub(int $eltern): bool
    {
        foreach (IPS_GetInstanceList() as $id) {
            if ($id !== $this->InstanceID && (int)(IPS_GetInstance($id)['ConnectionID'] ?? 0) === $eltern) {
                return false;
            }
        }
        if (strpos(IPS_GetName($eltern), 'RoboHub ') === 0) {
            return true;
        }
        $ca = (string)(json_decode((string)IPS_GetConfiguration($eltern), true)['CertificateAuthority'] ?? '');
        return $ca !== '' && $ca === base64_encode(RoboHubDreame::MQTT_WURZEL);
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
            $this->mqttStand(['zustand' => 'fehler', 'text' => RoboHubText::t('Sending failed: %s', $e->getMessage())]);
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
                    $this->mqttStand(['zustand' => 'fehler', 'text' => RoboHubText::t('Sending failed: %s', $e->getMessage())]);
                }
            }
            return;
        }
        $stand = json_decode((string)$this->ReadAttributeString('Mqtt'), true) ?: [];
        if (($stand['zustand'] ?? '') !== 'verbunden') {
            return;
        }
        if (time() - (int)($stand['lebenszeichen'] ?? 0) > 3 * self::MQTT_KEEPALIVE) {
            $this->protokolliere(RoboHubText::t('Real-time connection no longer responds — reconnecting.'), KL_WARNING);
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
                    $text = RoboHubText::t('Real-time sign-in rejected: %s', RoboHubMqtt::connackText($code));
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
        $alt = json_decode((string)$this->ReadAttributeString('Mqtt'), true) ?: [];
        $stand = array_replace($alt, $neu);
        $this->WriteAttributeString('Mqtt', (string)json_encode($stand));
        // Formularzeile (viele übersetzte Texte) nicht bei jeder Meldung — nur bei Zustandswechsel oder
        // höchstens alle 15 s (Forum power1625 144636/75: Echtzeit-Meldungen stauten sich ~4 min).
        $wechsel = ($stand['zustand'] ?? '') !== ($alt['zustand'] ?? '') || isset($neu['text']);
        $zuletzt = (int)$this->GetBuffer('EchtzeitZeileStand');
        if ($wechsel || time() - $zuletzt >= 15) {
            $this->SetBuffer('EchtzeitZeileStand', (string)time());
            $this->UpdateFormField('EchtzeitZeile', 'caption', $this->echtzeitZeile());
        }
    }

    private function echtzeitZeile(): string
    {
        $s = json_decode((string)$this->ReadAttributeString('Mqtt'), true) ?: [];
        if (!$this->ReadPropertyBoolean('Echtzeit')) {
            return RoboHubText::t('ℹ️ Real-time is switched off — the robots are only updated by polling.');
        }
        $treiber = $this->treiber();
        if (method_exists($treiber, 'echtzeitMoeglich') && !$treiber->echtzeitMoeglich()) {
            return RoboHubText::t('ℹ️ There are no real-time messages for %s yet — the robots are updated by polling. That\'s why only a "Virtual IO" hangs below the account as the link Symcon requires.', (RoboHubText::wert(self::HERSTELLER[$this->ReadPropertyString('Hersteller')][1] ?? null) ?? RoboHubText::t('this vendor')));
        }
        switch ($s['zustand'] ?? '') {
            case 'verbunden':
                return RoboHubText::t('🔗 Real-time connected to %s since %s, %s device(s) subscribed%s.', ($s['host'] ?? '?'), date('d.m.Y H:i', (int)($s['seit'] ?? time())), (int)($s['topics'] ?? 0), (!empty($s['letzte']) ? RoboHubText::t(', last message %s (%s in total)', date(date('Ymd', (int)$s['letzte']) === date('Ymd') ? 'H:i:s' : 'd.m.Y H:i:s', (int)$s['letzte']), (int)($s['anzahl'] ?? 0)) : RoboHubText::t(', no message yet')));
            case 'verbinde':
                return RoboHubText::t('⏳ Real-time connecting to %s …', ($s['host'] ?? '?'));
            case 'fehler':
                return RoboHubText::t('⛔ Real-time: %s — polling continues.', ($s['text'] ?? RoboHubText::t('error')));
            case 'getrennt':
                return RoboHubText::t('⚠️ Real-time disconnected — Symcon reconnects automatically; polling continues.');
            case 'fehlt':
                return RoboHubText::t('⚠️ No connection instance (Client Socket) — please click "Apply".');
            case 'zertifikat':
                return RoboHubText::t('⚠️ The real-time connection could not be established. Under Symcon 9.1 the connection instance rejects the certificate of the vendor server (it is valid from 31.12.1969, the new TLS check accepts nothing before 1970). RoboHub therefore closed it and polls the robots regularly. Next attempt at %s or via the button.', date('d.m.Y H:i', $this->ReadAttributeInteger('EchtzeitGesperrtBis')));
            default:
                return $this->ReadAttributeString('Buendel') === '' ? RoboHubText::t('ℹ️ Real-time starts after sign-in.') : RoboHubText::t('ℹ️ Real-time not connected yet.');
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
        } elseif ($hersteller === 'xiaomi') {
            $treiber = new RoboHubXiaomi();
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
        } elseif ($hersteller === 'alko') {
            $treiber = new RoboHubAlko();
        } elseif ($hersteller === 'vorwerk') {
            $treiber = new RoboHubVorwerk();
        } elseif ($hersteller === 'miele') {
            $treiber = new RoboHubMiele();
        } elseif ($hersteller === 'kaercher') {
            $treiber = new RoboHubKaercher();
        } elseif ($hersteller === 'eufy') {
            $treiber = new RoboHubEufy();
        } elseif ($hersteller === 'eufymaeher') {
            $treiber = new RoboHubEufyMaeher();
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
            throw new RoboHubFehler(RoboHubText::t('Not signed in — please sign in in the account instance.'));
        }
        $warte = $this->ReadAttributeInteger('WarteBis');
        if ($warte > time()) {
            throw new RoboHubFehler(RoboHubText::t('The vendor cloud is throttling right now — next attempt from %s.', date('d.m.Y H:i', $warte)));
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
        throw new RoboHubFehler(RoboHubText::t('Device %s is not (or no longer) in this account.', $id));
    }

    private function geraeteZusammenfassung(array $geraete): string
    {
        if (count($geraete) === 0) {
            return RoboHubText::t('⚠️ No robot was found in the account.');
        }
        $zeilen = [RoboHubText::t('✅ %s device(s) in the account:', count($geraete))];
        foreach ($geraete as $g) {
            $zeilen[] = '• ' . ($g['name'] ?? '?') . ' (' . ($g['modell'] ?? '?') . ', '
                . (($g['klasse'] ?? '') === RoboHubModell::KLASSE_MAEHER ? RoboHubText::t('robot mower') : RoboHubText::t('robot vacuum/mop')) . ', '
                . (!empty($g['online']) ? 'online' : 'offline') . ')';
        }
        $zeilen[] = RoboHubText::t('Create them with the RoboHub configurator.');
        return implode("\n", $zeilen);
    }

    private function geraeteZeile(): string
    {
        $stand = $this->ReadAttributeInteger('GeraeteStand');
        if ($stand === 0) {
            return RoboHubText::t('ℹ️ No device list loaded yet.');
        }
        $n = count(json_decode((string)$this->ReadAttributeString('Geraete'), true) ?: []);
        return RoboHubText::t('%s%s device(s) found (last %s).', ($n > 0 ? '✅ ' : '⚠️ '), $n, date('d.m.Y H:i:s', $stand));
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
            'This instance connects Symcon with your vendor account for robot vacuums, mops and lawn mowers (currently Dreame, MOVA, Roborock, Ecovacs, Vorwerk Kobold, Miele Scout, Kärcher, Eufy, Eufy mower E15, iRobot, Narwal as well as Husqvarna, GARDENA, Worx, Kress, LandXcape, Ferrex, Segway Navimow, Bosch Indego, STIHL iMOW and AL-KO Robolinho). You sign in once — after that RoboHub fetches state, battery and consumables of your robots and can start, stop or send them back to base.',
            'The benefit: robots become part of your automations, e.g. "vacuum when nobody is home" or "stop mowing when it rains" — without the vendor app. You then create the individual robots with the RoboHub configurator.',
        ];
    }

    protected function robohubDocLines(): array
    {
        return [
            'Sign in: choose vendor and region, enter e-mail and password of your vendor account, click "Apply", then "🔑 Sign in now". The password is discarded right after a successful sign-in; only the access token is kept.',
            'Ecovacs: choose the country, enter e-mail and password, sign in. If Ecovacs asks for a confirmation, click "📧 Request code", enter the code into the password field, click "Apply", sign in again. Because Ecovacs has no refresh token, RoboHub keeps a hash of the password for the automatic re-sign-in every 7 days.',
            'Roborock: without a password — click "📧 Request code", enter the code from the e-mail into the field, click "Apply", "🔑 Sign in now". State and commands run encrypted via the Roborock server; map image and real-time messages will follow later.',
            'Security: Symcon does not encrypt stored values. The access token is not shown in the form or the log, but it is stored in plain text in the Symcon database — as with any cloud module.',
            'Several accounts (e.g. a Dreamehome account for the robot vacuum and a MOVAhome account for the mower): create one account instance per account. The configurator still shows all robots in one list, and every robot connects itself to the account it belongs to.',
            'If the vendor cloud throttles (too many requests), RoboHub automatically waits the given time (status "Cloud throttling") and then continues by itself.',
            'If the access token finally expires, the instance shows "Sign-in required" — then simply enter the password again and sign in.',
        ];
    }

    private function kontoPanel(): array
    {
        $roborock = $this->perCode() || $this->ReadAttributeString('AnmeldeVorstufe') !== '';
        $xiaomi = $this->ReadPropertyString('Hersteller') === 'xiaomi';
        $ecovacs = $this->ReadPropertyString('Hersteller') === 'ecovacs';
        $lokal = in_array($this->ReadPropertyString('Hersteller'), ['irobot', 'narwal'], true);
        $narwal = $this->ReadPropertyString('Hersteller') === 'narwal';
        $husqvarna = in_array($this->ReadPropertyString('Hersteller'), ['husqvarna', 'gardena'], true);
        $navimow = $this->ReadPropertyString('Hersteller') === 'navimow';
        $alko = $this->ReadPropertyString('Hersteller') === 'alko';
        $miele = $this->ReadPropertyString('Hersteller') === 'miele';
        $mitClient = $alko || $miele;
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
            $zeile = $this->ReadPropertyString('Konto') !== '' ? RoboHubText::t('✅ Signed in as %s.', $this->ReadPropertyString('Konto')) : RoboHubText::t('✅ Signed in.');
        } elseif ($fehler !== '') {
            $zeile = RoboHubText::t('⛔ Not signed in — last error: %s', $fehler);
        } else {
            $zeile = RoboHubText::t('ℹ️ Not signed in yet.');
        }
        $warte = $this->ReadAttributeInteger('WarteBis');
        if ($warte > time()) {
            $zeile .= RoboHubText::t("\n⏳ The cloud is throttling — next attempt from %s.", date('d.m.Y H:i', $warte));
        }

        return [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => '🔑  Account',
            'items' => [
                ['type' => 'Select', 'name' => 'Hersteller', 'caption' => 'Vendor / app', 'options' => $auswahl],
                ['type' => 'Select', 'name' => 'Region', 'caption' => $ecovacs || $miele ? 'Country of the account (as in the app)' : 'Region of the account',
                    'options' => $miele ? array_map(function ($wert, $text) { return ['caption' => $text, 'value' => $wert]; }, array_keys(RoboHubMiele::LAENDER), RoboHubMiele::LAENDER)
                        : ($ecovacs ? RoboHubEcovacs::regionen() : ($this->ReadPropertyString('Hersteller') === 'kaercher' ? RoboHubKaercher::regionen() : ($xiaomi ? RoboHubXiaomi::regionen() : RoboHubDreame::regionen()))), 'visible' => $mitRegion],
                ['type' => 'ValidationTextBox', 'name' => 'Konto', 'visible' => !$perLink, 'caption' => $lokal ? 'IP address of the robot' : ($husqvarna ? 'Application key' : 'E-mail of the account')],
                ['type' => 'Label', 'visible' => $husqvarna, 'caption' => RoboHubText::t('Husqvarna uses the official API: sign in on developer.husqvarnagroup.cloud with your Husqvarna account, "Create application" (any redirect URL, e.g. http://localhost), connect the APIs "Authentication API" and "%s", then enter application key and application secret here. Because of the request limit RoboHub polls %s.', $api, ($api === 'GARDENA smart system API' ? RoboHubText::t('at most every 15 minutes') : RoboHubText::t('at most every 5 minutes')))],
                ['type' => 'Label', 'visible' => $narwal, 'caption' => 'Narwal runs locally, without cloud and without password: enter the robot\'s IP address (ideally fixed in your router), click "Apply", "🔑 Sign in now". The robot must be switched on. Works with Flow, Flow 2, Freo Z10 Ultra/Pro, Freo X10 Pro, Freo 20/20 Edge and JX; Freo X Ultra, X Plus, J1/J4 and T10 have no local interface. For another Narwal do the same with its IP.'],
                ['type' => 'Label', 'visible' => $lokal && !$narwal, 'caption' => 'iRobot runs locally, without cloud: put the robot on its base, enter the IP address, click "Apply". Press "🔑 Sign in now" once (RoboHub searches for the robot). Then hold the HOME button on the robot for about 2 seconds until it plays tones, and immediately press "🔑 Sign in now" again. For another robot do the same with its IP.'],
                ['type' => 'Label', 'visible' => $roborock && $this->ReadPropertyString('Hersteller') === 'vorwerk', 'caption' => 'Vorwerk signs in without a password: enter the e-mail of the MyKobold account, click "Apply", "📧 Request code", enter the code from the e-mail below, click "Apply", "🔑 Sign in now". Supported are Kobold VR200 and VR300; the VR7 uses a different cloud and does not work (yet). If a robot is added later, simply sign in again.'],
                ['type' => 'Label', 'visible' => $xiaomi, 'caption' => 'Mi Home: preferably sign in without a password — "📱 Show QR code", scan it with the Mi Home app (profile → scan icon) and confirm there. Alternatively enter e-mail and password below and click "🔑 Sign in now"; if Xiaomi then sends a code by e-mail, enter it into the password field and sign in again. Afterwards only the access keys are stored, never the password.'],
                ['type' => 'Label', 'visible' => $xiaomi, 'caption' => 'The Xiaomi cloud only provides the device list with each robot\'s key and local IP address. Commands go directly to the robot in your home network (UDP port 54321), so Symcon and robot must be in the same network — best give the robot a fixed IP in the router. Supported are the Roborock models in Mi Home (e.g. S5, S6, S50, Mi Robot Vacuum); Xiaomi\'s own vacuums with the MIoT protocol not yet.'],
                ['type' => 'Image', 'name' => 'QrBild', 'visible' => false, 'image' => '', 'center' => true],
                ['type' => 'Label', 'visible' => $roborock && !in_array($this->ReadPropertyString('Hersteller'), ['vorwerk', 'xiaomi'], true), 'caption' => 'Roborock signs in without a password: enter the e-mail, click "Apply", "📧 Request code", enter the code from the e-mail below, click "Apply", "🔑 Sign in now". Roborock finds the region itself.'],
                ['type' => 'Label', 'visible' => $navimow, 'caption' => 'Navimow signs in to RoboHub without a password: "🔗 Open sign-in page", sign in there with your Navimow account. The browser then lands on a page that does not load (http://localhost:1/callback?code=…) — that is intended. Copy this address from the address bar, enter it below, click "Apply", "🔑 Sign in now". The code is only valid for a few minutes.'],
                ['type' => 'Label', 'visible' => $navimow, 'caption' => 'To be clear: Segway does not issue separate access for smart home systems. Like ioBroker, RoboHub therefore uses the smart home ID "homeassistant" published by Segway. That\'s why the sign-in page says "Home Assistant" — it means this interface, not your Home Assistant.'],
                ['type' => 'Label', 'visible' => $alko, 'caption' => 'AL-KO uses the official API: apply once for access (client ID and client secret) at AL-KO under alko-garden.de/iot-api-zugang-anfordern. Then enter client ID and secret as well as e-mail and password of the inTOUCH app, click "Apply", "🔑 Sign in now". The password is discarded afterwards; client ID and secret stay stored in the account because AL-KO only renews access with them.'],
                ['type' => 'Label', 'visible' => $miele, 'caption' => 'Miele uses the official API: create access once on developer.miele.com (client ID and client secret). Then enter client ID and secret, e-mail and password of the Miele app and the country of the account, click "Apply", "🔑 Sign in now". The password is discarded afterwards; client ID and secret stay stored in the account because Miele only renews access with them. Remote control must be allowed on the robot.'],
                ['type' => 'ValidationTextBox', 'name' => 'MaeherIP', 'visible' => $this->ReadPropertyString('Hersteller') === 'eufymaeher', 'caption' => 'IP address of the mower'],
                ['type' => 'Label', 'visible' => $this->ReadPropertyString('Hersteller') === 'eufymaeher', 'caption' => 'Eufy mower E15: runs locally in your home network. Enter the mower\'s IP address (fixed in your router), plus e-mail and password of the Eufy account, click "Apply", "🔑 Sign in now". The account is only needed this one time to fetch the device key; the password is discarded afterwards. The mower allows only one local connection, so do not run a second integration in parallel. If it is paired again, the key changes: then sign in again. Map, zones and stopping in place are not available locally. E18 and C15 are not tested.'],
                ['type' => 'Label', 'visible' => $this->ReadPropertyString('Hersteller') === 'eufy', 'caption' => 'Eufy: enter e-mail and password of the app "Eufy Clean" or "Eufy", click "Apply", "🔑 Sign in now". The password is discarded afterwards. Supported are robots with Eufy\'s new cloud connection (including X8/X9/X10, L60, C10/C20, G50, Omni S1 Pro); older models with the pure Tuya protocol (e.g. RoboVac 11S, 30C, G30) not yet.'],
                ['type' => 'ValidationTextBox', 'name' => 'ClientId', 'visible' => $mitClient, 'caption' => RoboHubText::t('Client ID (from %s)', ($miele ? 'Miele' : 'AL-KO'))],
                ['type' => 'PasswordTextBox', 'name' => 'ClientSecret', 'visible' => $mitClient, 'caption' => RoboHubText::t('Client secret (from %s, moved into the account after sign-in)', ($miele ? 'Miele' : 'AL-KO'))],
                ['type' => 'Label', 'visible' => $indego, 'caption' => 'Indego signs in to RoboHub without a password. Best in Chrome, Edge or Firefox: first open the developer console (F12, on a Mac Option + Command + I), then "🔗 Open sign-in page" and sign in with the Bosch SingleKey ID. Afterwards nothing seems to happen — but the console shows a failed redirect to "com.bosch.indegoconnect://login?…code=…". Enter this address (or the whole console line) below, click "Apply", "🔑 Sign in now". The code is only valid for a few minutes. ioBroker does it the same way.'],
                ['type' => 'PasswordTextBox', 'name' => 'Passwort', 'visible' => !$lokal, 'caption' => $perLink ? 'Code or address after sign-in (discarded afterwards)' : ($roborock && !$xiaomi ? 'Code from the e-mail (discarded after sign-in)' : ($xiaomi && $this->ReadAttributeString('AnmeldeVorstufe') !== '' ? 'Code from the Xiaomi e-mail' : ($husqvarna ? 'Application secret (stays stored in the account)' : 'Password (only for sign-in, discarded afterwards)')))],
                ['type' => 'Label', 'name' => 'Ergebnis', 'caption' => $zeile],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Button', 'caption' => '📧 Request code', 'visible' => $roborock && !$xiaomi, 'onClick' => 'echo ROBOHUB_CodeAnfordern($id);'],
                    ['type' => 'Button', 'caption' => '📱 Show QR code', 'visible' => $xiaomi, 'onClick' => 'echo ROBOHUB_QrAnzeigen($id);'],
                    ['type' => 'Button', 'caption' => '🔗 Open sign-in page', 'visible' => $perLink, 'onClick' => 'echo ROBOHUB_Anmeldelink($id);', 'link' => true],
                    ['type' => 'Button', 'caption' => '🔑 Sign in now', 'onClick' => 'echo ROBOHUB_Anmelden($id);'],
                    ['type' => 'Button', 'caption' => '⏏️ Sign out', 'onClick' => 'echo ROBOHUB_Abmelden($id);'],
                ]],
                ['type' => 'PopupButton', 'caption' => 'Which region is my account in?', 'width' => '460px', 'visible' => $mitRegion && !$ecovacs && !$miele, 'popup' => [
                    'caption' => 'Which region is my account in?',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'The region follows the country you chose when registering in the app — for Germany, Austria and Switzerland that is "Europe".'],
                        ['type' => 'Label', 'caption' => 'If sign-in fails with "account not found" although e-mail and password are correct, the account is usually in a different region.'],
                    ],
                ]],
            ],
        ];
    }

    private function geraetePanel(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => '🤖  Devices in the account',
            'items' => [
                ['type' => 'Button', 'caption' => '🔎 Search devices now', 'onClick' => 'echo ROBOHUB_GeraeteSuchen($id);'],
                ['type' => 'Label', 'name' => 'GeraeteZeile', 'caption' => $this->geraeteZeile()],
                ['type' => 'Label', 'caption' => 'Robots are created with the "RoboHub configurator" — a single configurator shows the robots of all RoboHub accounts.'],
            ],
        ];
    }

    private function echtzeitPanel(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '⚡  Real-time messages',
            'items' => [
                ['type' => 'CheckBox', 'name' => 'Echtzeit', 'caption' => 'Receive changes immediately from the vendor cloud (instead of only polling every few minutes)'],
                ['type' => 'Label', 'name' => 'EchtzeitZeile', 'caption' => $this->echtzeitZeile()],
                ['type' => 'Button', 'caption' => '🔁 Retry real-time now', 'visible' => $this->ReadAttributeInteger('EchtzeitGesperrtBis') > time(), 'onClick' => 'ROBOHUB_EchtzeitNeuVersuchen($id);'],
                ['type' => 'Label', 'caption' => 'Technology: encrypted MQTT connection to the vendor\'s server via a separate Client Socket instance. The server certificate is checked against the vendor\'s certificate authority (certificate and server name) — foreign servers are rejected.'],
                ['type' => 'Label', 'caption' => 'Regular polling continues in addition (safety net). Map and position data are not processed.'],
            ],
        ];
    }

    protected function robohubFachpanels(): array
    {
        return ['konto' => $this->kontoPanel(), 'geraete' => $this->geraetePanel(), 'echtzeit' => $this->echtzeitPanel()];
    }

    public function GetConfigurationForm()
    {
        $status = [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Signed in'],
            ['code' => 104, 'icon' => 'inactive', 'caption' => 'Not signed in yet'],
            ['code' => 201, 'icon' => 'error', 'caption' => 'Sign-in required (access token expired or rejected)'],
            ['code' => 202, 'icon' => 'inactive', 'caption' => 'Cloud throttling — RoboHub waits for the lockout period'],
        ];
        return $this->robohubBuildForm($this->robohubFachpanels(), [], $status);
    }
}
