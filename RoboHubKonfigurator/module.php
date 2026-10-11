<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/RoboHubModell.php';
require_once __DIR__ . '/../libs/RoboHubFormular.php';

// ===========================================================================
// RoboHub Konfigurator — listet alle Roboter eines Hersteller-Kontos und
// legt die Roboter-Instanzen mit einem Klick an (Geräte-ID, Modell und
// Geräteklasse werden dabei gesetzt, der Nutzer muss nichts abtippen).
// ===========================================================================

class RoboHubKonfigurator extends IPSModule
{
    use RoboHubFormular;

    private const DATA_ZUM_KONTO = '{CFA82D8B-B9F1-4CAF-BFB7-E898E3690581}';
    private const GERAET_GUID    = '{631DA85B-5985-4C25-B222-E47FDD49E302}';

    public function Create()
    {
        parent::Create();
        $this->registerFormAttributes();
        $this->RegisterAttributeInteger('LetzteSuche', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }

    /** ROBOCFG_Suchen($id): string — Geräteliste neu von der Cloud holen. */
    public function Suchen(): string
    {
        $antwort = $this->frageKonto(['Aktion' => 'geraete', 'Neu' => true]);
        if (!$antwort['ok']) {
            return '⛔ ' . $antwort['fehler'];
        }
        $this->WriteAttributeInteger('LetzteSuche', time());
        $geraete = (array)$antwort['daten'];
        $this->UpdateFormField('Geraete', 'values', json_encode($this->listenWerte($geraete)));
        $this->UpdateFormField('SuchZeile', 'caption', $this->suchZeile(count($geraete)));
        return $this->suchZeile(count($geraete));
    }

    protected function frageKonto(array $anfrage): array
    {
        if (!$this->HasActiveParent()) {
            return ['ok' => false, 'fehler' => 'Keine aktive RoboHub-Konto-Instanz verbunden — bitte zuerst ein Konto anlegen, anmelden und hier als übergeordnete Instanz wählen.'];
        }
        $anfrage['DataID'] = self::DATA_ZUM_KONTO;
        try {
            $antwort = json_decode((string)$this->SendDataToParent((string)json_encode($anfrage)), true);
        } catch (Throwable $e) {
            return ['ok' => false, 'fehler' => 'Konto-Instanz nicht erreichbar: ' . $e->getMessage()];
        }
        return is_array($antwort) && isset($antwort['ok']) ? $antwort : ['ok' => false, 'fehler' => 'Unerwartete Antwort der Konto-Instanz.'];
    }

    private function listenWerte(array $geraete): array
    {
        $vorhanden = [];
        foreach (IPS_GetInstanceListByModuleID(self::GERAET_GUID) as $iid) {
            $vorhanden[(string)IPS_GetProperty($iid, 'GeraetID')] = $iid;
        }
        $werte = [];
        foreach ($geraete as $g) {
            $id = (string)($g['id'] ?? '');
            $klasse = ($g['klasse'] ?? '') === RoboHubModell::KLASSE_MAEHER ? RoboHubModell::KLASSE_MAEHER : RoboHubModell::KLASSE_SAUGER;
            $zeile = [
                'id'         => $id,
                'name'       => (string)($g['name'] ?? $id),
                'modell'     => (string)($g['modell'] ?? ''),
                'klasse'     => $klasse === RoboHubModell::KLASSE_MAEHER ? 'Rasenmähroboter' : 'Saug-/Wischroboter',
                'online'     => !empty($g['online']) ? 'online' : 'offline',
                'instanceID' => $vorhanden[$id] ?? 0,
                'create'     => [
                    'moduleID'      => self::GERAET_GUID,
                    'name'          => (string)($g['name'] ?? $id),
                    'configuration' => ['GeraetID' => $id, 'Modell' => (string)($g['modell'] ?? ''), 'Klasse' => $klasse],
                ],
            ];
            // Kein eigener Parent-Schlüssel nötig: Symcon verbindet neue
            // Instanzen bevorzugt mit der übergeordneten Instanz des
            // Konfigurators (SDK-Doku Configurator, geprüft 11.10.2026).
            $werte[] = $zeile;
        }
        // Instanzen, deren Gerät nicht mehr im Konto ist, trotzdem zeigen (rot markiert vom Konfigurator).
        $bekannt = array_column($werte, 'id');
        foreach ($vorhanden as $id => $iid) {
            if (!in_array($id, $bekannt, true) && $id !== '') {
                $werte[] = ['id' => $id, 'name' => IPS_GetName($iid), 'modell' => (string)IPS_GetProperty($iid, 'Modell'), 'klasse' => '', 'online' => 'nicht mehr im Konto', 'instanceID' => $iid];
            }
        }
        return $werte;
    }

    private function suchZeile(int $anzahl): string
    {
        $ts = $this->ReadAttributeInteger('LetzteSuche');
        if ($ts === 0) {
            return 'ℹ️ Noch nicht gesucht.';
        }
        return ($anzahl > 0 ? '✅ ' : '⚠️ ') . $anzahl . ' Gerät(e) gefunden (zuletzt ' . date('H:i:s', $ts) . ' Uhr).';
    }

    protected function robohubModulePrefix(): string
    {
        return 'ROBOCFG';
    }

    protected function robohubPurposeLines(): array
    {
        return [
            'Der Konfigurator zeigt alle Saug-, Wisch- und Mähroboter deines Hersteller-Kontos und legt sie mit einem Klick als RoboHub-Roboter an — Geräte-ID und Modell musst du nicht abtippen.',
        ];
    }

    protected function robohubDocLines(): array
    {
        return [
            'Voraussetzung: eine angemeldete RoboHub-Konto-Instanz als übergeordnete Instanz dieses Konfigurators.',
            'Gerät in der Liste wählen und „Erstellen" klicken. Bereits angelegte Roboter erscheinen mit ihrer Instanz-ID; Instanzen, deren Gerät nicht mehr im Konto ist, werden zusätzlich angezeigt.',
            'Ein neu gekaufter Roboter erscheint nach „🔎 Jetzt neu suchen", sobald er in der Hersteller-App eingerichtet ist.',
        ];
    }

    public function GetConfigurationForm()
    {
        $antwort = $this->frageKonto(['Aktion' => 'geraete']);
        $geraete = $antwort['ok'] ? (array)$antwort['daten'] : [];
        if ($antwort['ok'] && $this->ReadAttributeInteger('LetzteSuche') === 0) {
            $this->WriteAttributeInteger('LetzteSuche', time());
        }
        $kopf = $antwort['ok'] ? $this->suchZeile(count($geraete)) : '⛔ ' . $antwort['fehler'];

        $panel = [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => '🤖  Roboter im Konto',
            'items' => [
                ['type' => 'Button', 'caption' => '🔎 Jetzt neu suchen', 'onClick' => 'echo ROBOCFG_Suchen($id);'],
                ['type' => 'Label', 'name' => 'SuchZeile', 'caption' => $kopf],
                [
                    'type' => 'Configurator', 'name' => 'Geraete', 'caption' => 'Geräte',
                    'rowCount' => 8, 'add' => false, 'delete' => true,
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'name', 'width' => 'auto'],
                        ['caption' => 'Art', 'name' => 'klasse', 'width' => '180px'],
                        ['caption' => 'Modell', 'name' => 'modell', 'width' => '220px'],
                        ['caption' => 'Erreichbar', 'name' => 'online', 'width' => '150px'],
                        ['caption' => 'Geräte-ID', 'name' => 'id', 'width' => '160px'],
                    ],
                    'values' => $this->listenWerte($geraete),
                ],
            ],
        ];
        return $this->robohubBuildForm([$panel], [], [['code' => 102, 'icon' => 'active', 'caption' => 'Bereit']]);
    }
}
