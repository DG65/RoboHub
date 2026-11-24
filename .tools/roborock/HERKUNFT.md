# Herkunft der Testdaten

Die JSON-Dateien in diesem Ordner sind unverändert übernommen aus
[Python-roborock/python-roborock](https://github.com/Python-roborock/python-roborock),
`tests/protocols/testdata/v1_protocol/` (Stand 12.10.2026).

Lizenz: Apache License 2.0 — Copyright der Autoren von python-roborock.
Sie dienen nur als Antwortbeispiele im Prüfstand (`.tools/test-roborock.inc.php`).

## q7-karte-sc05.bin.gz (0.55.0)

Aus python-roborock (Apache-2.0), Stand 93f0d98 vom 22.11.2026:
`tests/map/testdata/raw-mqtt-map301.bin.inflated.bin.gz` — entpackte SCMap einer Q7 (roborock.vacuum.sc05),
Seriennummer im Test `testsn012345`. Erwartet laut `tests/map/test_b01_map_parser.py`: 340 × 300 Punkte,
Räume 10–19 „room1“ … „room10“. Unverändert übernommen.
