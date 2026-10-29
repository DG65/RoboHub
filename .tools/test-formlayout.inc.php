<?php
/**
 * „🪟 Formular: Aufbau" (SUITE.md 3a) — Teil des Prüfstands (von test-robohub.php eingebunden).
 * Nutzt das Konto $konto und den Roboter $geraet aus dem Hauptteil.
 */

declare(strict_types=1);

abschnitt('Formular: Aufbau (SUITE.md 3a)');

$flCaptions = function (array $form): array {
    return array_map(function ($e) { return (string)($e['caption'] ?? ($e['type'] ?? '')); }, $form['elements']);
};
$flPanel = function (array $form, string $anfang): ?array {
    foreach ($form['elements'] as $e) {
        if (strpos((string)($e['caption'] ?? ''), $anfang) === 0) {
            return $e;
        }
    }
    return null;
};
$flIndex = function (array $caps, string $anfang): int {
    foreach ($caps as $i => $c) {
        if (strpos($c, $anfang) === 0) {
            return $i;
        }
    }
    return -1;
};

// Standard: Panel da, eingeklappt, nach den Fachpanels und vor dem Rückmeldungs-Hinweis
$konto->props['FormLayout'] = '[]';
$kf    = json_decode($konto->GetConfigurationForm(), true);
$kc    = $flCaptions($kf);
$iAuf  = $flIndex($kc, '🪟  Formular: Aufbau');
$iKonto = $flIndex($kc, '🔑  Konto');
$iEcht = $flIndex($kc, '⚡  Echtzeit-Meldungen');
$iGer  = $flIndex($kc, '🤖  Geräte im Konto');
$iLiz  = $flIndex($kc, '🧡');
check('Konto: Panel „Formular: Aufbau" vorhanden und eingeklappt', $iAuf >= 0 && $kf['elements'][$iAuf]['expanded'] === false, json_encode($kc, JSON_UNESCAPED_UNICODE));
check('Konto: Standardreihenfolge Konto → Geräte → Echtzeit → Aufbau → … → Über dieses Modul',
    $iKonto >= 0 && $iKonto < $iGer && $iGer < $iEcht && $iEcht < $iAuf && $iAuf < $iLiz, json_encode($kc, JSON_UNESCAPED_UNICODE));
$liste = null;
foreach ($kf['elements'][$iAuf]['items'] as $it) {
    if (($it['name'] ?? '') === 'FormLayout') {
        $liste = $it;
    }
}
check('Konto: Liste mit drei Bereichen, unsichtbarer Schlüssel mit save', $liste !== null && count($liste['values']) === 3
    && array_column($liste['values'], 'Key') === ['konto', 'geraete', 'echtzeit']
    && $liste['columns'][1]['name'] === 'Key' && $liste['columns'][1]['visible'] === false && $liste['columns'][1]['save'] === true);
check('Konto: Zurücksetzen-Knopf ruft ROBOHUB_ResetFormLayout', strpos(json_encode($kf['elements'][$iAuf]), 'ROBOHUB_ResetFormLayout($id)') !== false);

// Gewählt: Echtzeit nach oben und aufgeklappt, Konto ausgeblendet, Geräte zugeklappt
$konto->props['FormLayout'] = json_encode([
    ['Key' => 'konto', 'Panel' => 'x', 'Opened' => false, 'Closed' => false, 'Hidden' => true, 'Pos' => 2],
    ['Key' => 'geraete', 'Panel' => 'x', 'Opened' => false, 'Closed' => true, 'Hidden' => false, 'Pos' => 3],
    ['Key' => 'echtzeit', 'Panel' => 'x', 'Opened' => true, 'Closed' => false, 'Hidden' => false, 'Pos' => 1],
]);
$kf = json_decode($konto->GetConfigurationForm(), true);
$kc = $flCaptions($kf);
$iKonto = $flIndex($kc, '🔑  Konto');
$iEcht  = $flIndex($kc, '⚡  Echtzeit-Meldungen');
$iGer   = $flIndex($kc, '🤖  Geräte im Konto');
check('Gewählt: Reihenfolge Echtzeit → Konto → Geräte', $iEcht >= 0 && $iEcht < $iKonto && $iKonto < $iGer, json_encode($kc, JSON_UNESCAPED_UNICODE));
check('Gewählt: Echtzeit aufgeklappt, Geräte zugeklappt', $kf['elements'][$iEcht]['expanded'] === true && $kf['elements'][$iGer]['expanded'] === false);
check('Ausgeblendet = visible:false, Felder bleiben im Formular', ($kf['elements'][$iKonto]['visible'] ?? true) === false
    && strpos(json_encode($kf['elements'][$iKonto]), '"name":"Hersteller"') !== false);
$auf = $flPanel($kf, '🪟  Formular: Aufbau');
check('Caption zählt Ausgeblendete, Hinweiszeile nennt sie', $auf !== null && strpos($auf['caption'], '(1 ausgeblendet)') !== false
    && strpos($auf['items'][0]['caption'], '🙈 Ausgeblendet: Konto') === 0, json_encode($auf['items'][0] ?? null, JSON_UNESCAPED_UNICODE));

// Widerspruch: ausgeblendet vor zugeklappt vor aufgeklappt
$konto->props['FormLayout'] = json_encode([
    ['Key' => 'geraete', 'Opened' => true, 'Closed' => true, 'Hidden' => true, 'Pos' => 2],
    ['Key' => 'echtzeit', 'Opened' => true, 'Closed' => true, 'Hidden' => false, 'Pos' => 3],
]);
$kf = json_decode($konto->GetConfigurationForm(), true);
$kc = $flCaptions($kf);
check('Widerspruch: ausgeblendet gewinnt, dann zugeklappt', ($kf['elements'][$flIndex($kc, '🤖  Geräte im Konto')]['visible'] ?? true) === false
    && $kf['elements'][$flIndex($kc, '⚡  Echtzeit-Meldungen')]['expanded'] === false);

// Rückfall: unbekannter Schlüssel, kaputter Text, gleiche Position
$konto->props['FormLayout'] = json_encode([['Key' => 'gibtsnicht', 'Hidden' => true, 'Pos' => 1], ['Key' => 'echtzeit', 'Pos' => 1]]);
$kf = json_decode($konto->GetConfigurationForm(), true);
$kc = $flCaptions($kf);
check('Rückfall: unbekannter Schlüssel ignoriert, gleiche Position → Standardreihenfolge',
    $flIndex($kc, '🔑  Konto') < $flIndex($kc, '⚡  Echtzeit-Meldungen') && $flIndex($kc, '⚡  Echtzeit-Meldungen') < $flIndex($kc, '🤖  Geräte im Konto')
    && strpos((string)json_encode($kf), '"visible":false,"caption":"🔐') === false, json_encode($kc, JSON_UNESCAPED_UNICODE));
$konto->props['FormLayout'] = '{kaputt';
$kf = json_decode($konto->GetConfigurationForm(), true);
$kc = $flCaptions($kf);
check('Rückfall: kaputter Text → Standard', $flIndex($kc, '🔑  Konto') < $flIndex($kc, '🤖  Geräte im Konto') && $flIndex($kc, '🤖  Geräte im Konto') < $flIndex($kc, '⚡  Echtzeit-Meldungen'));

// Zurücksetzen: Liste im offenen Formular auf Standard, keine Selbstpersistenz
$konto->props['FormLayout'] = json_encode([['Key' => 'echtzeit', 'Hidden' => true, 'Pos' => 1]]);
$konto->fields = [];
$antwort = $konto->ResetFormLayout();
$werte = null;
foreach ($konto->fields as $f) {
    if ($f[0] === 'FormLayout' && $f[1] === 'values') {
        $werte = json_decode($f[2], true);
    }
}
check('Zurücksetzen: drei Zeilen Standard, Position 1–3', is_array($werte) && array_column($werte, 'Key') === ['konto', 'geraete', 'echtzeit']
    && array_column($werte, 'Pos') === [1, 2, 3] && !in_array(true, array_merge(array_column($werte, 'Opened'), array_column($werte, 'Closed'), array_column($werte, 'Hidden')), true), json_encode($werte));
check('Zurücksetzen: Klartext, Property unverändert', strpos($antwort, 'Übernehmen') !== false && strpos($konto->props['FormLayout'], 'echtzeit') !== false);
$konto->props['FormLayout'] = '[]';

// Roboter: nur vorhandene Fachpanels in der Liste, Aufbau vor dem Lizenz-Panel
$geraet->props['FormLayout'] = '[]';
$gf  = json_decode($geraet->GetConfigurationForm(), true);
$gauf = $flPanel($gf, '🪟  Formular: Aufbau');
$gl = null;
foreach ($gauf['items'] ?? [] as $it) {
    if (($it['name'] ?? '') === 'FormLayout') {
        $gl = $it;
    }
}
$gKeys = $gl ? array_column($gl['values'], 'Key') : [];
check('Roboter: Aufbau-Panel mit den vorhandenen Fachpanels', $gl !== null && count($gKeys) >= 3 && in_array('geraet', $gKeys, true)
    && in_array('kachel', $gKeys, true) && in_array('technik', $gKeys, true), json_encode($gKeys));
check('Roboter: Knopf ruft ROBO_ResetFormLayout', strpos(json_encode($gauf), 'ROBO_ResetFormLayout($id)') !== false);
$geraet->props['FormLayout'] = json_encode([['Key' => 'technik', 'Opened' => true, 'Pos' => 1]]);
$gf = json_decode($geraet->GetConfigurationForm(), true);
$gc = $flCaptions($gf);
$iTech = $flIndex($gc, '🛠️');
check('Roboter: Technik nach oben (vor allen anderen Fachpanels) und aufgeklappt', $iTech >= 0 && $gf['elements'][$iTech]['expanded'] === true
    && $iTech < $flIndex($gc, '📱'), json_encode($gc, JSON_UNESCAPED_UNICODE));
$geraet->props['FormLayout'] = '[]';

// Konfigurator: nur ein Fachpanel → kein Aufbau-Panel
$cfgFl = new RoboHubKonfigurator(2990);
$cfgFl->Create();
check('Konfigurator (ein Fachpanel): kein Aufbau-Panel', $flPanel(json_decode($cfgFl->GetConfigurationForm(), true), '🪟  Formular: Aufbau') === null);
