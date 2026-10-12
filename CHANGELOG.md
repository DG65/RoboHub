# Changelog

## 0.11.5 (12.10.2026)

- Live 0.11.4: keine 6.3-Meldung nach App-Änderung. Barnys Gerätelog (Echtzeit 99.98, zlib-JSON
  `generalRule`) zeigt als einzigen Raum-Befehl RoboHubs eigenen `acmds … customeClean` — die App schickt ihre
  Raum-Einstellungen also NICHT an das Gerät. Küche im Gerät auf den Ursprungswert (Standard) zurückgestellt.
- Fehlersuche: Felder der Cloud-Gerätedaten (`iotuserdata/getDeviceData`) in „📋 Rohdaten".

## 0.11.4 (12.10.2026)

- Live 0.11.3: `get_properties 6.3` liefert bei Barny "" — der Roboter meldet das Kartenobjekt nur per Echtzeit
  nach einer App-Änderung (ioBroker `_cleansetNachladenPruefen`). Jetzt: Echtzeit-6.3 → `_objekt` →
  Konto-Aktion `kartenobjekt` → Objekt mit genau diesem Namen laden → Raum-Einstellungen (Quelle
  `kartenobjekt`, Vorrang vor Live-Rahmen). Nicht während Reinigung/Pause/Rückfahrt.
- Rohdaten: Aufbau der gespeicherten Kartendatei (Felder/Größen) für die Fehlersuche.

## 0.11.3 (12.10.2026)

- Live 0.11.2: kein Kartenobjekt gefunden. Jetzt steht in „📋 Rohdaten" unter `raumEinstellungenRoh.kartenobjektInfo`,
  was 6.3 liefert und woran das Lesen scheitert.

## 0.11.2 (12.10.2026)

- Live 0.11.1: auch die vollständige gespeicherte Karte trägt kein `cleanset`. Nach ioBroker mapController
  (`_loadCleansetFromObject`) liegt der App-Stand im **Kartenobjekt hinter 6.3** (Base64 + zlib, JSON mit
  `cleanset`); die App schreibt dorthin, das Gerät meldet danach einen neuen 6.3-Wert. RoboHub liest es beim
  Räume-Laden; ein Live-Rahmen überschreibt diesen Stand nicht mehr.
- Ändern (0.11.0) schreibt per 6.2 in den Gerätestand des Live-Rahmens — kam live NICHT in der App an.
  Weg der App noch offen; bis dahin nicht verwenden.

## 0.11.1 (12.10.2026)

- Live-Befund 0.11.0 (Barny, Küche): RoboHub-Änderung „Stark" kam im Live-Rahmen an, die App zeigte weiter
  „Standard"; App-Änderung „Leise" erschien NICHT im Live-Rahmen → App und Live-Rahmen nutzen getrennte Stände.
- Gespeicherte Karte jetzt aus `mapstr[].map` (vollständig, wie Tasshack) statt `thb` (Vorschau, ohne cleanset).
- „📋 Rohdaten" zeigen die Raum-Einstellungen beider Quellen getrennt (gespeicherte Karte / Live-Rahmen).
- Nach dem Schreiben: Live-Karte bei Fehlschlag nach 4 s ein zweites Mal lesen.

## 0.11.0 (12.10.2026)

- Neu: **Raum-Einstellungen ändern** (Modus, Saugstufe, Durchgänge) nach Tasshack `set_cleanset`:
  Zustand frisch lesen (nicht während Reinigung/Pause/Rückfahrt), `cleanset` frisch aus dem Live-Rahmen,
  einen Wert ändern, action 6.2 piid 4 = `{"customeClean": [[Raum, Saugstufe, Wasser, Durchgänge, Modus,
  Rest…], …]}` für alle Räume (Reihenfolge-Index 3 nicht mitgesendet). Modus App→Leitung mit Mopp-Tausch.
  Danach Live-Karte neu → Anzeige zeigt, was der Roboter übernommen hat. Protokolleintrag.
- Formular-Panel „🏠 Einstellungen je Raum" mit Auswahlfeldern je Raum (in Reinigungsreihenfolge),
  `ROBO_RaumEinstellungSetzen($id, $raum, "Saugstufe"|"Durchgaenge"|"Modus", $wert)`.
- Wasser/Feuchte und Reihenfolge bleiben vorerst nur Anzeige. Noch nicht live geprüft.

## 0.10.1 (12.10.2026)

- Live-Befund 0.10.0 (Barny): `cleanset` steht nur im Live-Rahmen, nicht in der gespeicherten Karte; alle Räume
  `[1,3,1,Reihenfolge,2,546,0,2,0]`, `customeClean` 1. App zeigt „Saugen, Standard" → Modus je Raum ist bei
  Mopp-Anhebung genauso getauscht wie 4.23 (Leitung 2 = Saugen). Reihenfolge = App (Dietmar bestätigt).
- Anzeige: Modus zuerst, Räume in Reinigungsreihenfolge mit Nummer, Wasser nur bei Modi mit Wischen.
  Konto gibt dem Treiber den letzten Rohstand mit (`$geraet['stand']`) für die Mopp-Anhebungs-Erkennung.

## 0.10.0 (12.10.2026)

- Neu: **Einstellungen je Raum** lesen — `cleanset` aus dem Kartentext (gespeicherte Karte und Live-Rahmen,
  Tasshack map.py): je Raum [Saugstufe, Wasser/Feuchte, Durchgänge, Reihenfolge, Modus, …]; Wasser 2–4 =
  Niedrig/Mittel/Hoch (Wert − 1), sonst Feuchte-Stufe; `customeClean` = in der App eingeschaltet.
  Variable „Einstellungen je Raum", Kachel zeigt die Einstellungen der gewählten Räume vor dem Start,
  Rohwerte in „📋 Rohdaten". Ändern (Tasshack `UPDATE_MAP_DATA` 6.2 mit `{"customeClean": [...]}`) folgt nach
  dem Live-Abgleich.

## 0.9.5 (12.10.2026)

- Neu: **Mäh-Pläne schalten** (Tabellenform) nach EvotecIT `plan_table_enabled`: Zustand 2.1 frisch lesen —
  nur ruhend (bereit/lädt/geladen); SCHDI frisch lesen; Einschalten nur mit mindestens einem aktiven Termin
  (SCHDC); `{"m":"s","t":"SCHDS","d":[Tabelle, 1/0]}`; danach SCHDI erneut — nur bei bestätigtem Schalter
  „eingeschaltet/ausgeschaltet", sonst Fehlermeldung. Danach alle Pläne neu gelesen (Einschalten kann den
  Nachbarplan derselben Karte ausschalten), Protokolleintrag.
- Plan-Kennung = Tabellennummer (eindeutig über Karten), Anzeige „Plan n" je Karte, ab Karte 2 mit Zusatz.
- Live bestätigt (LiDAX, 12.10.2026): ein und wieder aus, Endstand in MOVAhome bestätigt.

## 0.9.4 (12.10.2026)

- Mäh-Pläne live gelesen (0.9.3, LiDAX): `SCHDI 0` = `[[0,0,1,"",[[0,41976294]],7],[1,0,1,"",[],7]]`,
  `SCHDC 0/0` = `[0,1,0,720,[0..6],[]]` → ein Plan, aus, täglich 12:00 ganze Fläche; `SCHDI 1` nur leere Tabellen.
- Leere Platzhalter-Tabellen (ohne Name und Termine) ausgeblendet; 7 Tage = „täglich", Mo–Fr = „werktags";
  Pläne ohne Namen ab „Plan 1" gezählt.

## 0.9.3 (12.10.2026)

- Mäh-Pläne: LiDAX lehnt `SCHDIV2/3` mit `r 3` ab (Live-Befund 0.9.2). Jetzt zuerst die Tabellenform, die
  EvotecIT für MOVA bevorzugt (`schedule_tables.py`): `SCHDI [2·Platz, 2·Platz+1]` → Tabellen mit Name, an,
  Termin-Verweisen; je Termin `SCHDC [Tabelle, Termin]` → an, Art, Start-Minute, Tage, Zonen. Nur Startzeit;
  ausgeschaltete Termine werden (wie bei EvotecIT) weggelassen. Danach erst die Dokumentform.

## 0.9.2 (12.10.2026)

- Mäh-Pläne: LiDAX lieferte live keinen Plan (in 1,6 s, ohne Fehlermeldung), obwohl einer existiert. Jetzt
  stehen die Antworten je Kartenplatz/Generation in „📋 Rohdaten" (`zeitplanRoh`), Ablehnungen mit Rohantwort.

## 0.9.1 (12.10.2026)

- Neu: **Mäh-Pläne** lesen (Quelle EvotecIT/homeassistant-dreamelawnmower, MIT: schedule.py,
  schedule_read_plan.py, schedule_document.py). Über 2.50 je Kartenplatz −1/0/1: `SCHDIV2` (sonst 3) →
  Länge/Version (0xFFFF = leer), dann `SCHDDV2` in 100-Byte-Stücken → `{"d": [[ID, an, Name, Base64]]}`.
  Termin-Bytes: 7 Byte Kopf (Tag/Art, Start, Ende, tatsächliches Ende, Zonenzahl) + Zonen; gerahmte Form
  0xAA…0xED nur mit Start. Anzeige „Plan „Name" · Mo, Mi 09:00–12:00 · alles; Sa … · Zone".
  Nur Anzeige — Schalten (`SCHDSV`) erst nach Live-Bestätigung.
- Fix (Prüfstand gefunden): Zeitpläne werden nach den Raum-/Zonennamen übernommen (sonst alte Namen) und
  auch dann, wenn keine Karte vorliegt.
- Sauger-Zeitpläne live bestätigt (0.9.0): Barnys Termin „09:00 · Mo, Mi, Fr · alle Räume · Saugen" = App,
  Aus- und Wiedereinschalten am Gerät angekommen.

## 0.9.0 (12.10.2026)

- Neu: **Zeitpläne** (Sauger) aus 8.2 — Format nach Tasshack (`_schedule_changed`: Zustand 1/2 an, 3 ungültig,
  Feld 4 „0" einmalig) und ioBroker `lib/schedule.js` (Wochentagsmaske ab Sonntag, Arten Räume / Alle Räume /
  Kurzbefehl mit Bitlagen für Raum, Durchgänge, Saugstufe, Modus).
  - Variable „Zeitpläne" (Klartext mit Raumnamen), Kachel-Abschnitt mit an/aus-Knopf, Formular-Panel
    „🗓️ Zeitpläne" mit Ein-/Ausschalten, `ROBO_ZeitplanSchalten($id, $termin, $an)`, IPSView-Seite nur mit
    Freigabe + Schlüssel.
  - Schalten wie ioBroker: 8.2 frisch lesen, nur Feld 1 des Termins ändern, alles zurückschreiben
    (App-Änderungen bleiben). Ungültige Termine werden nicht geschaltet. Anlegen/Ändern bleibt in der App.
- Mäher: Zeitplan-Rohtext (`SCHEDULE` aus den Gerätedaten) in „📋 Rohdaten" — Format noch nicht entschlüsselt.
- Noch nicht live geprüft.

## 0.8.1 (12.10.2026)

- Fix Live-Karte: Barnys Live-Rahmen (r9547a) trägt kein `seg_inf` — Räume waren grau und ohne Namen.
  Jetzt Namen aus der gespeicherten Raumliste (Roboter gibt sie mit), Kodierungswahl auch über diese
  Liste, und jeder Raum im Bild bekommt eine Farbe, auch ohne Namen.
- Live bestätigt (0.8.0, Barny in der Station): Anforderung 6.1 beantwortet, Weg der letzten Reinigung
  (138 Punkte) und Roboter auf der Station.

## 0.8.0 (12.10.2026)

- Neu: **Live-Karte** — gefahrener Weg und Roboterposition auf Kachel und IPSView-Seite.
  - Sauger: Vollrahmen anfordern wie Tasshack `_request_i_map` (action 6.1, 6.2 =
    `{"req_type":1,"frame_type":"I","force_type":1}`); Antwort 6.3 = Zwischendatei („Name,Schlüssel",
    Abruf über `iotfile/getDownloadUrl`), 6.1 = Kartentext direkt, 6.13 = alte Form. Daraus aktuelles
    Kartenbild, Weg aus „tr" (M/W/S/l absolut, L relativ, M = neues Stück) und Roboter aus dem Kopf
    (Bytes 5/7/9, 32767 = keine Position, `nr` = ausblenden).
  - Mäher: Mähweg aus `M_PATH` der Gerätedaten (×10, null/[32767,−32768] trennt), Position aus der
    Echtzeit-Telemetrie 1.4 (0xCE, 20-Bit-Koordinaten in cm, ioBroker main.js).
  - Echtzeit: Teilrahmen 6.1 (Sauger) bzw. 1.4 (Mäher) versetzen den Roboter sofort (`_position`),
    ohne in Schnappschuss oder Echtzeit-Verlauf zu landen.
  - Eigener Takt `LiveKarte` nur während Reinigt/Pausiert/Zurück (Sauger 30 s, Mäher 60 s), am Ende ein
    letzter Abruf; der fertige Weg bleibt bis zur nächsten Arbeit stehen. Räume-Laden überschreibt eine
    laufende Live-Karte nicht. Schalter „Live-Karte während der Arbeit" (Standard an).
  - Weg auf höchstens 1500 Punkte ausgedünnt; Kachel zeichnet ihn als SVG über dem Bild.
- Noch nicht live geprüft — braucht eine laufende Reinigung bzw. ein Mähen.

## 0.7.1 (12.10.2026)

- Kartenbild (Sauger): weiße Trennlinie zwischen zwei aneinandergrenzenden Räumen (Dietmars Wunsch —
  gespeicherte Karten enthalten kaum Wandpunkte). Ein Bildpunkt breit im vergrößerten Bild, deshalb jetzt
  mindestens doppelt vergrößert. Keine Linie zu Wand, Boden ohne Raum oder Außenrand.

## 0.7.0 (12.10.2026)

- Neu: **Kartenbild** auf Kachel und IPSView-Seite (`libs/RoboHubKartenbild.php`, ohne GD).
  - Sauger: Bildpunkte der gespeicherten Karte → PNG mit Farbpalette (außen durchsichtig, Wände,
    Räume je eigene Farbe), auf die belegte Fläche zugeschnitten, ganzzahlig vergrößert, gespiegelt
    wie Tasshack (Zeile 0 = kleinstes y). Drei Bildpunkt-Kodierungen nach Tasshack map.py
    (standard / v3 / Rahmenkarte `fsm`); gewählt wird die, deren Raumnummern zu `seg_inf` passen.
    Station aus dem Kartenkopf (Bytes 11/13, Raster 17, Ursprung 23/25 bzw. `origin`).
  - Mäher: Zonen und Sperrflächen als SVG aus `mowingAreas`/`forbiddenAreas` (mm, y gespiegelt
    wie ioBroker).
  - Namen als Beschriftung über dem Bild (scharf in jeder Größe), antippen wählt Raum/Zone.
  - Breite Kachel: Karte als eigene mittlere Spalte; mittelbreit unter dem Status.
  - Schalter „Kartenbild zeigen" (Standard an). Bleibt eine Karte ohne Bildpunkte, bleibt das
    letzte Bild stehen.
- Treiber-Schnittstelle: `raeume()` → `karte()` (Räume + Bild + Beschriftung).
- Live bestätigt: Barny (7 Räume) und LiDAX (Zone Rasen) stimmen mit den Apps überein.

## 0.6.0 (11.10.2026)

- Neu: **Mähzonen** für Mähroboter. Zonen aus `POST /dreame-user-iot/iotuserdata/getDeviceData {did}`
  (Karte in Stücken `MAP.0…n`, Länge `MAP.info`, ioBroker `reassembleChunks`), erster Karten-Slot,
  `mowingAreas.value = [[id, {name}]]`. Start einzelner oder mehrerer Zonen über 2.50
  `{"m":"a","p":0,"o":102,"d":{"region":[IDs]}}` (ioBroker main.js `mowAction`, docs/mower-protocol.md).
  Variable „Zone mähen", Kachel-Knöpfe („▶️ Vorgarten mähen"), `ROBO_RaeumeReinigen($id, "1,3")`.
- Noch nicht live geprüft — Zonen-Start nur mit Dietmars Freigabe (Rasen frei).

## 0.5.0 (11.10.2026)

- Neu: **Nicht stören** (`NichtStoeren` Schalter, `NichtStoerenVon`/`NichtStoerenBis` als HH:MM-Eingabe)
  und **Lautstärke** (`Lautstaerke`, Schieberegler 0–100 %), für Sauger und Mäher.
  - Sauger (Tasshack types.py/device.py, ioBroker main.js): neuere Geräte führen Zeitfenster als
    JSON-Liste in 5.4 — maßgeblich ist wie bei Tasshack das erste Fenster nach Startzeit; Ändern =
    Read-Modify-Write dieses Fensters, weitere Fenster werden unverändert mitgeschickt; ohne Fenster
    wird eines angelegt (id 1, täglich). Ältere Geräte: 5.1/5.2/5.3. Lautstärke 7.1.
  - Mäher (ioBroker docs/mower-protocol.md): Einstellungen über 2.50 `{"m":"g","t":"CFG"}`,
    `DND = [an, Start-Minute, End-Minute]`, `VOL`; schreiben per `{"m":"s","t":"DND","d":{"value","time"}}`
    bzw. `{"m":"s","t":"VOL","d":{"value"}}`. Ist CFG nicht lesbar, wird nichts geschrieben.
- Neue Abfrageblöcke am Ende der Sauger-Liste (Blocksperre-Indizes bleiben stabil).
- Treiber meldet änderbare Felder unter `schreibbar`; die Instanz führt die Liste zusammen (Mäher-
  Echtzeit-Meldungen tragen die Einstellungen nicht mit).
- Live an beiden Geräten bestätigt (Lesen und Schreiben, gegen beide Apps).

## 0.4.4 (11.10.2026)

- Neu: Verbrauchsteile zurücksetzen (`ROBO_VerbrauchsteilZuruecksetzen($id, 'Filter')`), im
  Formular als Knopf je vorhandenem Teil mit Ja/Nein-Abfrage (`confirm`), auf der Kachel in
  zwei Schritten (↺ → „Ja, zurücksetzen"), über die IPSView-Seite nur mit Freigabe + Schlüssel.
  Danach sofort neuer Abruf; jede Rücksetzung (mit Vorher-Wert) dauerhaft im Symcon-Protokoll.
- Sauger: Reset-Aktionen nach Tasshack `ACTION_MAPPING` — Hauptbürste 9.1, Seitenbürste 10.1,
  Filter 11.1, Sensoren 16.1, Mopp/Walze 18.1, Schmutzwasserbehälter 26.1, Abwasserfilter 35.1.
- Mäher: Read-Modify-Write des CMS-Zählers über 2.50 (ioBroker `resetIndex`: Klingen 0,
  Reinigungsbürste 1, Wartung 2) — die anderen beiden Zähler bleiben erhalten; ist CMS nicht
  lesbar, wird nichts geschrieben.
- Live bestätigt: Sensoren am Barny (16.1) → 100 % / 30 h. Mäher-Weg noch nicht live.

## 0.4.3 (11.10.2026)

- Echtzeit live bestätigt: Beide Konten verbunden (TLS mit Hersteller-CA, `CertificateAuthority`
  als base64(PEM) funktioniert), Anmeldung angenommen, Meldungen kommen an (7 in 4 Minuten).
- „📋 Rohdaten" zeigen zusätzlich die letzten 20 Echtzeit-Meldungen mit ihren Rohwerten
  (`echtzeitVerlauf`) — zur Klärung, wie das Gerät App-Änderungen meldet.

## 0.4.2 (11.10.2026)

- Fix (zweiter Anlauf): Auch `RequireParent()` in `ApplyChanges()` legte bei Dietmars
  bestehenden Konten live keinen Client Socket an (Moduldefinition und Code nachweislich
  korrekt, auch nach „Übernehmen" nichts). Jetzt ausdrücklich dieselben Schritte wie in
  Symcons offiziellen Stubs (`IPS_CreateInstance`, `IPS_SetName`, `IPS_ConnectInstance`),
  Name „RoboHub Echtzeit (<Konto>)". Prüfstand bildet das Live-Verhalten nach
  (`RequireParent` ohne Wirkung).

## 0.4.1 (11.10.2026)

- Versuch: `RequireParent` zusätzlich in `ApplyChanges()` — hat live NICHT gewirkt, siehe 0.4.2.

## 0.4.0 (11.10.2026)

- **Echtzeit-Meldungen per MQTT** (Dietmars Auftrag): eigener schlanker MQTT-3.1.1-Client
  (`libs/RoboHubMqtt.php`) über Symcons Client Socket (`RequireParent`, Konfiguration per
  `GetConfigurationForParent`, Statuswechsel per `IM_CHANGESTATUS` — Muster Tibber Grid Reward).
  Anmeldung mit uid + Zugangsschlüssel, Abonnement `/status/<did>/<uid>/<model>/<region>/` je
  Gerät (ioBroker `connectMqtt`), Keepalive 60 s, Neuverbindung bei neuem Zugangsschlüssel und
  wenn 3 Intervalle nichts kommt, PUBACK bei QoS 1.
- **Zertifikatsprüfung statt Abschalten:** Die MQTT-Server nutzen eine private Wurzel-CA (nennt
  sich „GlobalSign Root CA", ist es nicht). RoboHub hinterlegt genau diese Wurzel
  (`CertificateAuthority`, SHA-256 6D:B9:EA:84:…:E4:25) und prüft Zertifikat UND Hostname —
  gegengeprüft: Dreame-/MOVA-Server ok, fremdes Zertifikat abgelehnt. ioBroker schaltet die
  Prüfung ab (`rejectUnauthorized: false`).
- Meldungen (`properties_changed`) werden in den letzten vollständigen Rohstand gemischt und wie
  bei der Abfrage übersetzt (abgeleitete Werte wie der Reinigungsmodus-Tausch bleiben korrekt),
  dann sofort an Roboter und Kachel gegeben. Karten (6.x) und binäre Mäher-Telemetrie (1.x)
  werden übergangen. Ohne vollständigen Stand wartet RoboHub auf den nächsten Abruf.
- Abfrage bleibt als Sicherheitsnetz; der 30-s-Schnelltakt entfällt, solange Meldungen kommen.

## 0.3.5 (11.10.2026)

- Produktbild: Ersatzweise wird jetzt die erste https-Bildadresse im ganzen Datensatz
  genommen, falls `deviceInfo.mainImage` fehlt; die Rohdaten zeigen Felder und gefundene
  Bildpfade. **Korrektur:** Die Vermutung, `mainImage` fehle bei Dietmars Konten, war falsch —
  live liefern Dreame (r9547a) und MOVA (g2529c) es dort. Die leere Kachel unter 0.3.4 kam
  daher, dass noch kein Abruf nach dem Update gelaufen war (Bild wird erst beim Abruf gesetzt).

## 0.3.4 (11.10.2026)

- Kachel: offizielles Produktbild statt Haus-Symbol (Dietmars Wunsch). Quelle:
  `deviceInfo.mainImage.imageUrl` der Geräteliste (Ersatz overlook/icon/popup), nur
  https; die Kachel lädt es direkt vom Hersteller-Server, nichts wird kopiert. Zustand als
  farbiger Ring + kleines Symbol; ohne Bild oder bei Ladefehler wie bisher das Symbol.

## 0.3.3 (11.10.2026)

- Kachel: Farbstufen der Verbrauchsteile an die Hersteller-Apps angelehnt (Dietmars
  Wunsch): rot ≤ 5 % (Dreamehome färbt nur 0 % rot), gelb ≤ 35 % (MOVAhome: Klingen 34 % =
  „Bald ersetzen", 72 % = „In gutem Zustand"). Die eingeklappte Liste zeigt alle gelben/roten.

## 0.3.2 (11.10.2026)

- Kachel-Feinschliff nach Dietmars Screenshot: Inhalt oben ausgerichtet statt senkrecht
  zentriert (alle Kacheln beginnen auf gleicher Höhe), Zustandssymbol etwa doppelt so groß,
  bis zu vier Verbrauchsteile immer ausgeklappt (Mäher), „Stand …" am unteren Kachelrand.

## 0.3.1 (11.10.2026)

- Kachel-Layout nach Dietmars Screenshot: Symcon legt Instanzname und Vergrößern-Symbol
  als eigene Ebene über die oberen ~44 px — jetzt freigehalten (eigenständige Seite ohne
  diesen Abstand). Ab 560 px Breite zweispaltig, Schrift skaliert mit der Kachelgröße
  (`clamp`/`vmin`), Inhalt senkrecht zentriert, Akku als Balken, Knöpfe 2×2.

## 0.3.0 (11.10.2026)

- **Kachel je Roboter** (Kachel-Visualisierung, `SetVisualizationType(1)`, `module.html`):
  Zustand mit Symbol und Unterzeile (Rohzustand, „seit …"), Akku/Laden, Erreichbarkeit,
  Fehler und Stationshinweise, Knöpfe Start/Pause/Station/Orten, Raumauswahl mit
  Mehrfachwahl und Bestätigungsknopf (neue Aktion `Raeume`), Modus/Saugstufe,
  Verbrauchsteile nach Dringlichkeit (≤ 10 % rot, ≤ 25 % gelb, Rest aufklappbar).
  Kein eigener Titel (Symcon zeigt den Instanznamen).
- **Eigenständige Seite für IPSView/Browser** über WebHook `/hook/robohub<ID>`
  (Verbund-Muster NRGDashboard/Prognose, Aktualisierung alle 15 s). Ohne Symcon-
  Anmeldung erreichbar, daher standardmäßig nur Anzeige; Befehle nur bei
  eingeschalteter Freigabe (`WebBedienung`, Standard aus) UND passendem Schlüssel
  (`hash_equals`, 24 Hex-Zeichen, `ROBO_NeuerWebSchluessel`), nur Befehl/Raum/Raeume.
  Ohne Freigabe gibt die Seite den Schlüssel nie heraus.

## 0.2.5 (11.10.2026)

- Sauger: Abwasserauslassfilter (35.2 %, 35.1 h; Tasshack) und Schmutzwasserbehälter
  des Roboters (26.1 %, 26.2 h; ioBroker) — wie in der Dreamehome-App. Eigener
  Abfrageblock am Ende der Liste (Blocksperre-Indizes bleiben stabil).

## 0.2.4 (11.10.2026)

- Reststunden je Verbrauchsteil (Dietmars Wunsch). Sauger: vom Gerät geliefert
  (9.1/10.1/11.2/16.2/18.2, Einheit h laut ioBroker), eigener Abfrageblock — fehlt er
  bei einem Modell, greift die Blocksperre aus 0.2.1. Mäher: aus den CMS-Minutenzählern
  gerechnet ((Max − Minuten) / 60, 0,1 h), ergibt exakt die App-Werte
  (33,7 / 433,7 / 42,9 h).

## 0.2.3 (11.10.2026)

- Mäher: Reinigungsbürste (max. 30000 min) und Roboter-Wartung (max. 3600 min) aus
  derselben CMS-Antwort wie die Klingen. Alle drei am MOVA LiDAX Ultra 1000 gegen die
  MOVAhome-App bestätigt (Klingen 34 %, Bürste 87 %, Wartung 72 %).

## 0.2.2 (11.10.2026)

- Mäher-Zustand 19 = „Mäht". In keiner Referenz enthalten; live am MOVA LiDAX
  Ultra 1000 (`mova.mower.g2529c`, FW 4.3.6_0529) nach „Start" beobachtet (Akku
  sinkend, Besitzer bestätigt). Start lief über Weg 5.1 (eine Anfrage, 0,6 s).

## 0.2.1 (11.10.2026)

- Schnellere Abfrage: Die Konto-Instanz merkt sich je Gerät Abfrageblöcke, die nicht
  beantwortet wurden (Attribut `BlockSperre`), und überspringt sie eine Stunde lang.
  Gesperrt wird nur, wenn im selben Abruf ein anderer Block geantwortet hat — ist der
  Roboter kurz weg, bleibt alles offen. Live-Anlass: Der MOVA LiDAX Ultra 1000
  beantwortet Block 4.x (Arbeitsmodus/Fläche/Dauer/Auftrag) nicht, jeder Abruf
  dauerte dadurch ~13 s. Die Rohdaten zeigen die übersprungenen Blöcke.

## 0.2.0 (11.10.2026)

- **Ein Konfigurator für alle Konten** (Dietmars Wunsch): Der Konfigurator hat keine
  übergeordnete Instanz mehr, sammelt die zwischengespeicherten Gerätelisten aller
  Konto-Instanzen (neu: `ROBOHUB_GeraeteListe`, ohne Cloud-Anfrage) und zeigt sie in
  einer Liste mit Spalte „Konto". „Jetzt neu suchen" fragt alle Konten ab.
- **Selbstzuordnung der Roboter:** Welche von mehreren passenden übergeordneten
  Instanzen Symcon beim Erstellen wählt, ist nicht dokumentiert. Deshalb verbindet sich
  jeder Roboter in `ApplyChanges()` selbst mit dem Konto, dessen Liste seine Geräte-ID
  enthält (`IPS_ConnectInstance`), und wechselt bei Bedarf. Bei unbekannter ID und
  mehreren Konten wird nicht geraten. Das Formular zeigt das Konto (🔗 grün / ⛔ rot).
- Doku: Der Papierkorb im Konfigurator löscht die Roboter-Instanz.

## 0.1.3 (11.10.2026)

- Fix Konfigurator: Bei mehreren Konto-Instanzen erschienen die Roboter des
  anderen Kontos rot als „nicht mehr im Konto" (Live-Fund: Saugroboter im
  MOVA-Konfigurator). Berücksichtigt werden jetzt nur Roboter, die mit der
  Konto-Instanz dieses Konfigurators verbunden sind.

## 0.1.2 (11.10.2026)

- Fix Konfigurator: Ein bereits angelegter Roboter erschien doppelt — einmal
  richtig, einmal rot als „nicht mehr im Konto". PHP wandelt numerische
  Geräte-IDs als Array-Schlüssel in Zahlen um, der anschließende strenge
  Vergleich schlug fehl. Live-Fund an Dietmars erstem echten Konto.

## 0.1.1 (11.10.2026)

- Mähroboter-Gerätecodes (2.2) vollständig nach dem Register von
  [EvotecIT/homeassistant-dreamelawnmower](https://github.com/EvotecIT/homeassistant-dreamelawnmower)
  (MIT): Fehler und Wartungshinweise in „Fehler", reine Hinweise (Auftrag fertig,
  Regen-/Frostschutz, Ruhezeit …) in der neuen Variable „Letzte Meldung".
  LiDAX Ultra 1000/2000/2000 AWD (`mova.mower.g2529c/f`, `g2584a`): Code 0 =
  „Roboter angehoben", 55 = „Start nicht möglich — Akku zu schwach".
- Mäher-Zustände dort, wo sich die Quellen widersprechen, nach den
  App-Beschriftungen: 3 = Pausiert, 4 = Pausiert wegen Fehler, 15/16 = Laden
  pausiert (Akkutemperatur), neu 75 = Am Wartungspunkt pausiert.
- Mäher-Befehle Start/Stopp/Pause zuerst über 5.1/5.2/5.4 (bei LiDAX Ultra 1000
  belegt), bei Ablehnung einmal über 2.1/2.2/2.4 (ioBroker). Aktionen werten jetzt
  den Ergebniscode des Geräts aus: Ablehnung = Fehlermeldung statt „gesendet".

## 0.1.0 (11.10.2026)

- Erstes Release.
- **RoboHub Konto**: Anmeldung am Dreamehome- bzw. MOVAhome-Konto. Das Passwort
  dient nur der Erstanmeldung und wird danach verworfen; weiter trägt der
  (rotierende) Erneuerungsschlüssel. Gemeinsame Sperrfrist, wenn die Cloud bremst
  (HTTP 429/5xx), „Anmeldung erforderlich", wenn der Schlüssel abgelehnt wird.
- **RoboHub Konfigurator**: listet alle Roboter des Kontos (Saug-/Wischroboter und
  Rasenmähroboter) und legt sie mit Geräte-ID, Modell und Geräteklasse an.
- **RoboHub Roboter**: Zustand (herstellerneutral, mit Rohzustand im Klartext),
  Fehler, Akku, Laden, Fläche/Dauer, Verbrauchsteile, Stationsstatus, Statistik;
  Befehle Start/Pause/Stopp/Zur Station/Orten; Saugstufe, Wassermenge und
  Reinigungsmodus als Auswahl; Raumreinigung über die Variable „Raum reinigen"
  (Raumnamen aus der gespeicherten Karte). Variablen entstehen nur, wenn das
  Modell den Wert liefert.
- Sauger, die Zustand und Akku nicht per Abfrage melden (belegt für
  `dreame.vacuum.r95475`, Aqua10 Ultra Roller Complete), bekommen beide Werte
  aus der Geräteliste.
- Mäher (MOVA/Dreame): Zustand inkl. Regenpause, Fehler, Akku, Messer-Verschleiß,
  Start/Pause/Stopp/Zur Station/Orten.
- Prüfstand `.tools/test-robohub.php` (ohne Symcon, ohne Netz), läuft in der CI.
