<?php
/**
 * Englische Fassung (von test-robohub.php am Ende eingebunden): Quelltexte sind englisch, Deutsch kommt aus
 * libs/locale.json. Geprüft wird die Übersetzungstabelle und dass Formulare auf Englisch keine deutschen Reste zeigen.
 */

declare(strict_types=1);

abschnitt('Englisch und Übersetzung');

$enLocale = json_decode((string)file_get_contents(__DIR__ . '/../libs/locale.json'), true);
$enDe = $enLocale['translations']['de'] ?? [];
check('locale.json: gültig, deutsche Tabelle vorhanden', is_array($enDe) && count($enDe) > 1000);
$enGleich = true;
foreach (['RoboHubKonto', 'RoboHubGeraet', 'RoboHubKonfigurator'] as $enModul) {
    $enGleich = $enGleich && file_get_contents(__DIR__ . '/../' . $enModul . '/locale.json') === file_get_contents(__DIR__ . '/../libs/locale.json');
}
check('locale.json der drei Module = libs/locale.json (.tools/locale-bauen.php)', $enGleich);

// Jeder Übersetzungsaufruf mit festem Text hat eine deutsche Fassung (außer Texte, die in beiden Sprachen gleich sind).
$enFehlt = [];
$enPlatz = [];
foreach (array_merge(glob(__DIR__ . '/../libs/*.php'), glob(__DIR__ . '/../RoboHub*/module.php')) as $enDatei) {
    $enT = token_get_all((string)file_get_contents($enDatei));
    $enN = count($enT);
    for ($i = 0; $i < $enN - 4; $i++) {
        if (is_array($enT[$i]) && $enT[$i][1] === 'RoboHubText' && $enT[$i + 1][1] === '::' && $enT[$i + 2][1] === 't' && $enT[$i + 3] === '('
            && is_array($enT[$i + 4]) && $enT[$i + 4][0] === T_CONSTANT_ENCAPSED_STRING) {
            $enText = $enT[$i + 4][1][0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($enT[$i + 4][1], 1, -1)) : stripcslashes(substr($enT[$i + 4][1], 1, -1));
            if (!isset($enDe[$enText]) && preg_match('/[a-z]{3,}/', $enText) && !preg_match('/^(ℹ️ RoboHub|Version %s:|Start \(3 h\)|Zone %s|Code %s|Plan %s%s|OK|Pause|Start|Turbo|Standard|Max|Filter|Station|Firmware|Position)$/u', $enText)) {
                $enFehlt[] = basename($enDatei) . ': ' . $enText;
            }
            $enArgs = 0;
            $d = 0;
            for ($j = $i + 5; $j < $enN; $j++) {
                $x = is_array($enT[$j]) ? $enT[$j][1] : $enT[$j];
                if ($x === '(' || $x === '[') {
                    $d++;
                } elseif ($x === ')' || $x === ']') {
                    if ($d === 0) {
                        break;
                    }
                    $d--;
                } elseif ($x === ',' && $d === 0) {
                    $enArgs++;
                }
            }
            $enPh = preg_match_all('/%[sd]/', str_replace('%%', '', $enText));
            if ($enArgs !== $enPh) {
                $enPlatz[] = basename($enDatei) . ': ' . $enText . " ($enPh Platzhalter, $enArgs Werte)";
            }
            if (isset($enDe[$enText]) && preg_match_all('/%[sd]/', str_replace('%%', '', $enDe[$enText])) !== $enPh) {
                $enPlatz[] = 'Deutsch: ' . $enText . ' → ' . $enDe[$enText];
            }
        }
    }
}
check('jeder RoboHubText::t()-Text hat eine deutsche Fassung', $enFehlt === [], implode(' | ', array_slice($enFehlt, 0, 8)) . ' (' . count($enFehlt) . ')');
check('Platzhalter passen: Anzahl Werte = %s im englischen und im deutschen Text', $enPlatz === [], implode(' | ', array_slice($enPlatz, 0, 6)) . ' (' . count($enPlatz) . ')');

// Formulare auf Englisch: keine deutschen Reste (Umlaute, typische Wörter); Markennamen ausgenommen.
$enTexte = function ($x) use (&$enTexte): array {
    $aus = [];
    if (is_array($x)) {
        foreach ($x as $k => $v) {
            if (in_array($k, ['caption', 'label', 'Caption', 'confirm'], true) && is_string($v)) {
                $aus[] = $v;
            } elseif (is_array($v)) {
                $aus = array_merge($aus, $enTexte($v));
            }
        }
    }
    return $aus;
};
$enDeutsch = function (string $s): bool {
    $s = str_replace(['Kärcher', 'Kärcher Home Robots', 'alko-garden.de/iot-api-zugang-anfordern', 'RoboHub Konto', 'Rohdaten', 'Karte'], '', $s);
    return (bool)preg_match('/[äöüÄÖÜß]|\b(und|der|die|das|nicht|bitte|Konto|Gerät|Roboter|anmelden|Jetzt|übernehmen|Übernehmen|Zugang|Passwort)\b/u', $s);
};
$GLOBALS['SPRACHE'] = 'en';
$enReste = [];
$enKonto = new RoboHubKonto(19900);
$enKonto->Create();
$enHersteller = (new ReflectionClassConstant('RoboHubKonto', 'HERSTELLER'))->getValue();
foreach (array_keys($enHersteller) as $enH) {
    $enKonto->props['Hersteller'] = $enH;
    foreach ($enTexte(json_decode($enKonto->GetConfigurationForm(), true)) as $s) {
        if ($enDeutsch($s)) {
            $enReste['Konto ' . $enH . ': ' . mb_substr($s, 0, 80)] = true;
        }
    }
}
$enRoboter = [];
foreach (['sauger' => 'dreame.vacuum.r9547a', 'maeher' => 'mova.mower.g2529c'] as $enKl => $enMod) {
    $enG = new RoboHubGeraet(19910 + count($enRoboter));
    $enG->props = ['GeraetID' => 'EN-' . $enKl, 'Modell' => $enMod, 'Klasse' => $enKl, 'Intervall' => 120];
    $enG->Create();
    $enG->ApplyChanges();
    $enRoboter[] = $enG;
}
foreach ($enRoboter as $enG) {
    foreach ($enTexte(json_decode($enG->GetConfigurationForm(), true)) as $s) {
        if ($enDeutsch($s)) {
            $enReste['Roboter ' . $enG->InstanceID . ': ' . mb_substr($s, 0, 80)] = true;
        }
    }
}
$enCfg = new RoboHubKonfigurator(19901);
$enCfg->Create();
foreach ($enTexte(json_decode($enCfg->GetConfigurationForm(), true)) as $s) {
    if ($enDeutsch($s)) {
        $enReste['Konfigurator: ' . mb_substr($s, 0, 80)] = true;
    }
}
check('Formulare auf Englisch ohne deutsche Reste (Konto für jeden Hersteller, Roboter, Konfigurator)', $enReste === [], implode(' | ', array_slice(array_keys($enReste), 0, 10)) . ' (' . count($enReste) . ')');
check('englisch: Zustandsnamen und Befehle', RoboHubModell::zustandsName(RoboHubModell::ZUSTAND_LAEDT, RoboHubModell::KLASSE_SAUGER) === 'Charging'
    && RoboHubModell::zustandsName(RoboHubModell::ZUSTAND_REINIGT, RoboHubModell::KLASSE_MAEHER) === 'Mowing');
$GLOBALS['SPRACHE'] = 'de';
check('deutsch: dieselben Namen aus locale.json', RoboHubModell::zustandsName(RoboHubModell::ZUSTAND_LAEDT, RoboHubModell::KLASSE_SAUGER) === 'Lädt'
    && RoboHubModell::zustandsName(RoboHubModell::ZUSTAND_REINIGT, RoboHubModell::KLASSE_MAEHER) === 'Mäht');
