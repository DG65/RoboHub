<?php
/**
 * Erzeugt RoboHubKonto/RoboHubGeraet/RoboHubKonfigurator/locale.json aus libs/locale.json
 * (Symcon liest locale.json nur im Modulordner; alle drei Module nutzen dieselben Texte).
 * Aufruf: php .tools/locale-bauen.php
 */
declare(strict_types=1);
$quelle = (string)file_get_contents(__DIR__ . '/../libs/locale.json');
json_decode($quelle, true, 512, JSON_THROW_ON_ERROR);
foreach (['RoboHubKonto', 'RoboHubGeraet', 'RoboHubKonfigurator'] as $modul) {
    file_put_contents(__DIR__ . '/../' . $modul . '/locale.json', $quelle);
}
echo "locale.json für 3 Module geschrieben\n";
