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

// Kachel: jeder T('…')-Text in module.html steht in KACHEL_TEXTE (sonst bliebe er englisch) und hat eine deutsche Fassung;
// keine deutschen Texte mehr im Skript (Kommentare ausgenommen).
$enHtml = (string)file_get_contents(__DIR__ . '/../RoboHubGeraet/module.html');
$enKachel = (new ReflectionClassConstant('RoboHubGeraet', 'KACHEL_TEXTE'))->getValue();
preg_match_all("/T\\('((?:[^'\\\\]|\\\\.)*)'/u", $enHtml, $enT);
preg_match_all("/T\\(([^()]*\\?[^()]*)\\)/u", $enHtml, $enTernaer);
foreach ($enTernaer[1] as $enAusdruck) {
    preg_match_all("/'([^']*)'/", $enAusdruck, $enTeile);
    $enT[1] = array_merge($enT[1], $enTeile[1]);
}
preg_match_all("/\\[\\d, '[a-z0-9-]+', '[^']+', '([A-Za-z]+)'\\]/u", $enHtml, $enBefehle);
$enAlle = array_diff(array_unique(array_merge($enT[1], $enBefehle[1])), ['maeher']);   // Klassenvergleich im Ternär, kein Text
$enOhne = array_values(array_filter($enAlle, function ($s) use ($enKachel, $enDe) { return !in_array($s, $enKachel, true) || !isset($enDe[$s]); }));
check('Kachel: jeder T()-Text in KACHEL_TEXTE und locale.json', count($enAlle) >= 30 && $enOhne === [], implode(' | ', $enOhne) . ' / ' . count($enAlle));
$enSkript = preg_replace(['~^\s*//.*$~m', '~/\*.*?\*/~s', '~ // .*$~m'], '', $enHtml);
check('Kachel: keine deutschen Texte im Skript', !preg_match("/'[^']*([äöüÄÖÜß]|\\b(Uhr|gesendet|reinigen|mähen|weniger|Ordnung)\\b)[^']*'/u", $enSkript));

// Gespeicherte Treiberwerte (Raum-Einstellungen, Zeitpläne): bis 0.35.0 deutsch gespeichert, seit 0.35.1 englischer Schlüssel bzw.
// „Moisture 16" — beides muss in jeder Sprache richtig erscheinen (Dietmars Live-Befund 04.11.2026: „Saugen · Standard" auf Englisch).
$enGesp = new ReflectionMethod('RoboHubGeraet', 'gespeichert');
$enAlle = function () use ($enGesp): string {
    return implode('|', array_map(function ($v) use ($enGesp) { return $enGesp->invoke(null, $v); },
        ['Saugen', 'Vacuum and mop', 'Leise', 'Strong', 'Mittel', 'Feuchte 16', 'Moisture 16', 'Level 7', 'Mode 5', 'Standard']));
};
$GLOBALS['SPRACHE'] = 'en';
$enAnzeigeEn = $enAlle();
$GLOBALS['SPRACHE'] = 'de';
$enAnzeigeDe = $enAlle();
check('gespeicherte Werte auf Englisch (auch alte deutsche)', $enAnzeigeEn === 'Vacuum|Vacuum and mop|Quiet|Strong|Medium|Moisture 16|Moisture 16|Level 7|Mode 5|Standard', $enAnzeigeEn);
check('gespeicherte Werte auf Deutsch (auch neue englische)', $enAnzeigeDe === 'Saugen|Saugen und Wischen|Leise|Stark|Mittel|Feuchte 16|Feuchte 16|Stufe 7|Modus 5|Standard', $enAnzeigeDe);

// Store-Punkt 25 (Symcon-Review 06.11.2026): Die Konsole übersetzt caption/label/confirm selbst. Feste Formulartexte stehen deshalb
// englisch im Code (kein RoboHubText::t() ohne Werte) und brauchen einen locale.json-Eintrag, sonst bleiben sie auf Deutsch englisch.
$fkFest = [];
$fkOhne = [];
foreach (array_merge(glob(__DIR__ . '/../libs/*.php'), glob(__DIR__ . '/../RoboHub*/module.php')) as $fkDatei) {
    $fkT = token_get_all((string)file_get_contents($fkDatei));
    $fkN = count($fkT);
    $fkSig = function ($i) use ($fkT) { return !(is_array($fkT[$i]) && in_array($fkT[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)); };
    $fkFunktion = '';
    for ($i = 0; $i < $fkN - 2; $i++) {
        if (is_array($fkT[$i]) && $fkT[$i][0] === T_FUNCTION) {
            $j = $i + 1; while (!$fkSig($j)) $j++;
            $fkFunktion = is_array($fkT[$j]) ? $fkT[$j][1] : '';
        }
        $fkLit = function ($t) { return $t[1][0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($t[1], 1, -1)) : stripcslashes(substr($t[1], 1, -1)); };
        // Einleitungs- und Doku-Zeilen: jedes Literal im Rückgabe-Array
        if (in_array($fkFunktion, ['robohubPurposeLines', 'robohubDocLines'], true) && is_array($fkT[$i]) && $fkT[$i][0] === T_CONSTANT_ENCAPSED_STRING) {
            $s = $fkLit($fkT[$i]);
            if (!isset($enDe[$s])) { $fkOhne[] = basename($fkDatei) . ': ' . mb_substr($s, 0, 60); }
            continue;
        }
        if (!(is_array($fkT[$i]) && $fkT[$i][0] === T_CONSTANT_ENCAPSED_STRING && in_array(substr($fkT[$i][1], 1, -1), ['caption', 'label', 'confirm'], true))) continue;
        $j = $i + 1; while (!$fkSig($j)) $j++;
        if (!(is_array($fkT[$j]) && $fkT[$j][0] === T_DOUBLE_ARROW)) continue;
        // Wert bis zum Ende des Elements: feste t() melden, Literale (auch in Ternären) gegen locale.json prüfen
        $tiefe = 0;
        for ($k = $j + 1; $k < $fkN; $k++) {
            $x = is_array($fkT[$k]) ? $fkT[$k][1] : $fkT[$k];
            if ($x === '(' || $x === '[') { $tiefe++; continue; }
            if ($x === ')' || $x === ']') { if ($tiefe === 0) break; $tiefe--; continue; }
            if ($tiefe === 0 && $x === ',') break;
            if ($x === 'RoboHubText' && ($fkT[$k + 2][1] ?? '') === 't') {
                $a = $k + 4; while (!$fkSig($a)) $a++;
                $b = $a + 1; while (!$fkSig($b)) $b++;
                if (is_array($fkT[$a]) && $fkT[$a][0] === T_CONSTANT_ENCAPSED_STRING && $fkT[$b] === ')') {
                    $fkFest[] = basename($fkDatei) . ':' . $fkT[$a][2] . ' ' . mb_substr($fkLit($fkT[$a]), 0, 50);
                }
                // Literale innerhalb von t(…, Werte) übersetzt RoboHub selbst — nicht als Formular-Literal prüfen
                $d = 0;
                for ($k = $k + 3; $k < $fkN; $k++) { $y = is_array($fkT[$k]) ? $fkT[$k][1] : $fkT[$k]; if ($y === '(') $d++; elseif ($y === ')') { if ($d === 0) break; $d--; } }
                continue;
            }
            if ($tiefe === 0 && is_array($fkT[$k]) && $fkT[$k][0] === T_CONSTANT_ENCAPSED_STRING) {
                $s = $fkLit($fkT[$k]);
                if (preg_match('/[a-z]{3,}/', $s) && !isset($enDe[$s])) { $fkOhne[] = basename($fkDatei) . ':' . $fkT[$k][2] . ' ' . mb_substr($s, 0, 60); }
            }
        }
    }
}
check('Formular: kein RoboHubText::t() ohne Werte als caption/label/confirm (die Konsole übersetzt selbst)', $fkFest === [], implode(' | ', array_slice($fkFest, 0, 6)) . ' (' . count($fkFest) . ')');
check('Formular: jeder feste Text hat einen deutschen Eintrag in locale.json', $fkOhne === [], implode(' | ', array_slice($fkOhne, 0, 8)) . ' (' . count($fkOhne) . ')');
