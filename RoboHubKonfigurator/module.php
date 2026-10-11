<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/RoboHubModell.php';
require_once __DIR__ . '/../libs/RoboHubFormular.php';

// ===========================================================================
// RoboHub Konfigurator — EINE Liste für die Roboter ALLER RoboHub-Konten
// (z. B. Dreamehome-Saugroboter und MOVAhome-Mäher zusammen).
//
// Der Konfigurator hat bewusst keine übergeordnete Instanz: Er liest die
// zwischengespeicherten Gerätelisten aller Konto-Instanzen
// (ROBOHUB_GeraeteListe) und legt Roboter mit Geräte-ID/Modell/Klasse an.
// Mit welchem Konto sich ein neuer Roboter verbindet, entscheidet der
// Roboter danach selbst (RoboHubGeraet::ordneKontoZu) — Symcon würde einen
// neuen Roboter bei mehreren Konten sonst nach eigenem Ermessen verbinden
// (SDK-Doku Configurator: „wenn möglich", Auswahlregel undokumentiert).
// ===========================================================================

class RoboHubKonfigurator extends IPSModule
{
    use RoboHubFormular;

    private const KONTO_GUID  = '{DB269BEC-0CA6-4E6A-83B8-78FF8CFB2DB0}';
    private const GERAET_GUID = '{631DA85B-5985-4C25-B222-E47FDD49E302}';

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

    /** ROBOCFG_Suchen($id): string — Gerätelisten aller Konten neu von der Cloud holen. */
    public function Suchen(): string
    {
        $zeilen = [];
        foreach (IPS_GetInstanceListByModuleID(self::KONTO_GUID) as $kid) {
            try {
                $text = (string)ROBOHUB_GeraeteSuchen($kid);
            } catch (Throwable $e) {
                $text = '⛔ ' . $e->getMessage();
            }
            $zeilen[] = $this->kontoName($kid) . ': ' . strtok($text, "\n");
        }
        $this->WriteAttributeInteger('LetzteSuche', time());
        $konten = $this->sammle();
        $this->UpdateFormField('Geraete', 'values', json_encode($this->listenWerte($konten)));
        $this->UpdateFormField('SuchZeile', 'caption', $this->suchZeile($konten));
        $this->UpdateFormField('KontenZeile', 'caption', $this->kontenZeile($konten));
        return count($zeilen) === 0 ? $this->suchZeile($konten) : implode("\n", $zeilen);
    }

    /** Liest die zwischengespeicherten Listen aller Konten (keine Cloud-Anfrage). */
    private function sammle(): array
    {
        $konten = [];
        foreach (IPS_GetInstanceListByModuleID(self::KONTO_GUID) as $kid) {
            try {
                $info = json_decode((string)ROBOHUB_GeraeteListe($kid), true);
            } catch (Throwable $e) {
                $info = null;
            }
            $konten[$kid] = is_array($info) ? $info : ['anzeige' => $this->kontoName($kid), 'angemeldet' => false, 'stand' => 0, 'geraete' => []];
        }
        return $konten;
    }

    private function kontoName(int $kid): string
    {
        return IPS_GetName($kid) . ' #' . $kid;
    }

    private function listenWerte(array $konten): array
    {
        $vorhanden = [];
        foreach (IPS_GetInstanceListByModuleID(self::GERAET_GUID) as $iid) {
            // (string): PHP macht numerische Schlüssel zu int (Live-Fund 0.1.2).
            $vorhanden[(string)IPS_GetProperty($iid, 'GeraetID')] = $iid;
        }
        $werte = [];
        $bekannt = [];
        foreach ($konten as $kid => $info) {
            foreach ((array)($info['geraete'] ?? []) as $g) {
                $id = (string)($g['id'] ?? '');
                if ($id === '' || isset($bekannt[$id])) {
                    continue;
                }
                $bekannt[$id] = true;
                $klasse = ($g['klasse'] ?? '') === RoboHubModell::KLASSE_MAEHER ? RoboHubModell::KLASSE_MAEHER : RoboHubModell::KLASSE_SAUGER;
                $werte[] = [
                    'id'         => $id,
                    'name'       => (string)($g['name'] ?? $id),
                    'modell'     => (string)($g['modell'] ?? ''),
                    'klasse'     => $klasse === RoboHubModell::KLASSE_MAEHER ? 'Rasenmähroboter' : 'Saug-/Wischroboter',
                    'konto'      => (string)($info['anzeige'] ?? ('#' . $kid)),
                    'online'     => !empty($g['online']) ? 'online' : 'offline',
                    'instanceID' => $vorhanden[$id] ?? 0,
                    'create'     => [
                        'moduleID'      => self::GERAET_GUID,
                        'name'          => (string)($g['name'] ?? $id),
                        'configuration' => ['GeraetID' => $id, 'Modell' => (string)($g['modell'] ?? ''), 'Klasse' => $klasse],
                    ],
                ];
            }
        }
        // Roboter-Instanzen, deren Gerät in keinem Konto (mehr) vorkommt.
        foreach ($vorhanden as $id => $iid) {
            $id = (string)$id;
            if ($id !== '' && !isset($bekannt[$id])) {
                $werte[] = ['id' => $id, 'name' => IPS_GetName($iid), 'modell' => (string)IPS_GetProperty($iid, 'Modell'), 'klasse' => '', 'konto' => '—', 'online' => 'in keinem Konto gefunden', 'instanceID' => $iid];
            }
        }
        return $werte;
    }

    private function suchZeile(array $konten): string
    {
        if (count($konten) === 0) {
            return 'ℹ️ Noch keine RoboHub-Konto-Instanz vorhanden — zuerst „RoboHub Konto" anlegen und anmelden.';
        }
        $anzahl = 0;
        foreach ($konten as $info) {
            $anzahl += count((array)($info['geraete'] ?? []));
        }
        $ts = $this->ReadAttributeInteger('LetzteSuche');
        return ($anzahl > 0 ? '✅ ' : '⚠️ ') . $anzahl . ' Gerät(e) in ' . count($konten) . ' Konto/Konten gefunden'
            . ($ts > 0 ? ' (zuletzt ' . date('H:i:s', $ts) . ' Uhr).' : '.');
    }

    private function kontenZeile(array $konten): string
    {
        $zeilen = [];
        foreach ($konten as $kid => $info) {
            $n = count((array)($info['geraete'] ?? []));
            $zeilen[] = (empty($info['angemeldet']) ? '⛔ ' : '🔗 ') . ($info['anzeige'] ?? '') . ' — Instanz #' . $kid . ': '
                . (empty($info['angemeldet']) ? 'nicht angemeldet' : $n . ' Gerät(e)');
        }
        return implode("\n", $zeilen);
    }

    protected function robohubModulePrefix(): string
    {
        return 'ROBOCFG';
    }

    protected function robohubPurposeLines(): array
    {
        return [
            'Der Konfigurator zeigt alle Saug-, Wisch- und Mähroboter aus allen deinen RoboHub-Konten in einer Liste und legt sie mit einem Klick an — Geräte-ID und Modell musst du nicht abtippen.',
        ];
    }

    protected function robohubDocLines(): array
    {
        return [
            'Voraussetzung: mindestens eine angemeldete RoboHub-Konto-Instanz. Der Konfigurator braucht selbst keine Verbindung — er sammelt die Roboter aller Konten.',
            'Gerät wählen und „Erstellen" klicken. Der neue Roboter verbindet sich selbst mit dem Konto, in dem er gefunden wurde.',
            'Der Papierkorb hinter einer Zeile LÖSCHT die Roboter-Instanz samt Variablen. Rote Zeilen sind Instanzen, deren Gerät in keinem Konto mehr vorkommt.',
            'Ein neu gekaufter Roboter erscheint nach „🔎 Jetzt neu suchen", sobald er in der Hersteller-App eingerichtet ist.',
        ];
    }

    public function GetConfigurationForm()
    {
        $konten = $this->sammle();
        // Konten, die noch nie eine Liste geladen haben (z. B. nach einem
        // Modul-Neuladen), einmal nachladen — sonst wirkt die Liste leer.
        $nachgeladen = false;
        foreach ($konten as $kid => $info) {
            if (!empty($info['angemeldet']) && (int)($info['stand'] ?? 0) === 0) {
                try {
                    ROBOHUB_GeraeteSuchen($kid);
                    $nachgeladen = true;
                } catch (Throwable $e) {
                    // Fehler zeigt die Konten-Zeile.
                }
            }
        }
        if ($nachgeladen) {
            $konten = $this->sammle();
            $this->WriteAttributeInteger('LetzteSuche', time());
        }

        $panel = [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => '🤖  Roboter aller Konten',
            'items' => [
                ['type' => 'Button', 'caption' => '🔎 Jetzt neu suchen', 'onClick' => 'echo ROBOCFG_Suchen($id);'],
                ['type' => 'Label', 'name' => 'SuchZeile', 'caption' => $this->suchZeile($konten)],
                [
                    'type' => 'Configurator', 'name' => 'Geraete', 'caption' => 'Geräte',
                    'rowCount' => 8, 'add' => false, 'delete' => true,
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'name', 'width' => 'auto'],
                        ['caption' => 'Art', 'name' => 'klasse', 'width' => '180px'],
                        ['caption' => 'Konto', 'name' => 'konto', 'width' => '260px'],
                        ['caption' => 'Modell', 'name' => 'modell', 'width' => '200px'],
                        ['caption' => 'Erreichbar', 'name' => 'online', 'width' => '170px'],
                    ],
                    'values' => $this->listenWerte($konten),
                ],
                ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => 'Konten im Detail', 'items' => [
                    ['type' => 'Label', 'name' => 'KontenZeile', 'caption' => count($konten) > 0 ? $this->kontenZeile($konten) : 'ℹ️ Keine Konten.'],
                ]],
            ],
        ];
        return $this->robohubBuildForm([$panel], [], [['code' => 102, 'icon' => 'active', 'caption' => 'Bereit']]);
    }
}
