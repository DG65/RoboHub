# Changelog

Ältere Versionen: [CHANGELOG-Archiv.md](https://github.com/DG65/RoboHub/blob/main/CHANGELOG-Archiv.md)

## 0.54.1 (24.11.2026)

- **Ecovacs: alt angelegter Winbot meldet sich klar; gleiche Fehler nicht bei jedem Abruf** (Forum brdiver677 144636/189).
  Björn hatte den W2 Omni vor 0.52.3 als Saugroboter angelegt; jeder Abruf (alle 2 min) schrieb „Gerätedaten unvollständig“
  ins Meldungsfenster (Winbot-Einträge haben keine resource). Jetzt: Treiber erkennt Winbot (nichtSteuerbar, Klasse Fenster
  oder Firma winbot*) und meldet „nur Bluetooth — bitte diese Roboter-Instanz löschen“. Allgemein: `verarbeiteFehler` im Konto
  protokolliert dieselbe Meldung höchstens alle 6 h (Puffer `FehlerGemeldet`), sonst nur Debug.

## 0.54.0 (24.11.2026)

- **Roborock Q7 (Protokoll „B01“), Stufe 1** (Dietmars Auftrag nach der Bedarfsrecherche 23.11.: A01 sind keine Roboter, B01 =
  Q7 und Q10). Quelle python-roborock (Apache-2.0, Stand 93f0d98 vom 22.11.2026): protocol.py (B01-Rahmen: AES-128-CBC mit
  dem Geräteschlüssel, IV = md5hex(%08x(random) + B01_HASH)[9:25]), protocols/b01_q7_protocol.py (Anfrage
  `{"dps":{"10000":{method,msgId,params}}}`, wie dort doppelt gepolstert), devices/rpc/b01_q7_channel.py (Antwort als JSON-Text
  in dps 10001, Zuordnung über msgId), devices/traits/b01/q7 und data/b01_q7 (Befehle, Felder, Codes, Teile-Lebensdauer).
  Neu `libs/RoboHubRoborockQ7.php`; Weiche in RoboHubRoborock (`istQ7`: pv B01 und Modellkennung „sc“). Zustand per
  `prop.get`, Saugstufe/Wassermenge/Modus/Lautstärke per `prop.set`, Start/Pause/Stopp `service.set_room_clean` (ctrl 1/2/0),
  Station `service.start_recharge`, Orten `service.find_device`. Verbrauchsteile aus verbrauchten Minuten (300/200/150/180/30 h).
  Läuft über die Dauerverbindung des Kontos wie die übrigen Roborocks. Nicht gebaut: Räume/Karte (SCMap-Protobuf), Zonen,
  Echtzeit-Pushes, Verbrauchsteile zurücksetzen, Dauer/Fläche (Einheiten unbelegt). Q10 (Modell „ss“, eigenes Datenpunkt-
  Protokoll) meldet weiter ehrlich „nur Q7“. Prüfstand: Rahmen unabhängig nachgerechnet, Anfrageform, Auswertung, Befehle,
  Einstellungen, Ablehnung, Fehlerzustand. **Noch nie an einem echten Q7** — kein Tester.

## 0.53.1 (24.11.2026)

- **Roborock: erfolgreicher Neuaufbau der stummen Verbindung nicht mehr als Warnung** (Forum lugge 144636/187). Lugges
  Archiv „Erreichbar“ nach 0.52.2: Roborock verstummt etwa alle 3 h 07 min (23.11. 21:37, 24.11. 00:44, 03:51, 06:59),
  RoboHub baut nach drei Abrufen (~6 min bei 120 s) neu auf, danach kommen wieder Daten — vorher Ausfälle über Stunden.
  Er wünscht, dass das Meldungsfenster nur noch meldet, wenn wirklich nichts mehr kommt. Jetzt: Neuaufbau nur im Debug;
  eine Warnung („liefert auch nach dem Neuaufbau keine Antworten“) erst, wenn seit dem vorigen Neuaufbau keine einzige
  Antwort kam. Prüfstand: erster Neuaufbau still, geheilter Neuaufbau setzt zurück, ungeheilter warnt.

## 0.53.0 (23.11.2026)

- **Dreame/MOVA: Reinigungsroute** (Wunsch Tester mik per PN, L20 Ultra: „Route auf schnell ändern“). Quelle Tasshack
  dreame-vacuum (MIT): Schalter-Einstellungen 4.50 (AUTO_SWITCH_SETTINGS) sind eine JSON-Liste `[{"k","v"}]`, die Route
  ist `CleanRoute` mit 1 Standard, 2 Intensiv, 3 Tief, 4 Schnell (0 = nicht gesetzt); geschrieben wird
  `set_properties 4.50 = '{"k":"CleanRoute","v":n}'` (set_auto_switch_property). Neuer Abfrageblock [[4, 50]] am Ende
  (Blocksperre). Variable „Reinigungsroute“ nur, wenn der Roboter CleanRoute > 0 meldet. Beim Saugen und bei
  Saugen+Wischen bietet die App (Geräte ohne cleaning_route_v2) nur Standard und Schnell — RoboHub bietet dann auch nur
  diese an, ein anderer aktueller Wert bleibt sichtbar. Tasshack stellt bei solchen Geräten nach einem Moduswechsel die
  Route selbst auf Standard zurück; das macht RoboHub nicht (cleaning_route_v2 kennt RoboHub nicht). Prüfstand: Lesen,
  Optionen je Modus, ohne CleanRoute keine Variable, Schreibformat. **Live noch nicht bestätigt.**

## 0.52.3 (23.11.2026)

- **Konto: Knopf „Echtzeit jetzt neu verbinden“ immer sichtbar** (Forum alsk1 144636/184). Mein Notbehelf aus #181/#182
  („im Client Socket Geöffnet aus/an“) geht nicht: Symcon sperrt im Socket die Felder, die RoboHub über
  GetConfigurationForParent vorgibt. Der vorhandene Knopf (bisher nur bei 24-h-Zertifikatssperre sichtbar) erscheint jetzt
  immer, solange die Echtzeit eingeschaltet ist und der Hersteller sie hat. Er trennt und verbindet neu, setzt den
  Ausfallzähler aus 0.52.2 zurück und holt bei WebSocket-Konten (GARDENA, Husqvarna) vorher ein frisches Ziel. Eine
  Drosselpause des Herstellers hebt er bewusst nicht auf (jeder Versuch verlängert sie, Neuanmelden hebt sie auf).
- **Ecovacs: WINBOT-Fensterputzroboter erkannt, aber nicht anlegbar** (Forum brdiver677 144636/183, W2 Omni `ind025`
  erschien als „Saug-/Wischroboter“). Recherche 23.11.: Ecovacs' Gerätetabelle (productIotMap in ecovacs-deebot.js) führt
  alle 34 Winbot-Klassen mit smartType „BT“, echte Einträge (home-assistant/core#165821, W2 Omni `81gpyd`) haben
  company „winbot-bt“ ohne resource und ohne MQTT-Dienst — die App steuert sie per Bluetooth. Kein Open-Source-Projekt
  steuert Winbots (deebot_client überspringt sie, ioBroker #763 offen). Jetzt: Klasse „Fensterputzroboter“, im
  Konfigurator ohne Erstellen, Grund „nur Bluetooth — über die Cloud nicht steuerbar“ (Winbot mit resource: „noch nicht
  unterstützt“). Konfigurator-Meldung nach der Suche ohne abschließenden Doppelpunkt.

## 0.52.2 (23.11.2026)

- **Roborock: stumme Dauerverbindung selbst neu aufbauen** (Forum lugge 144636/179, Saros 20; alsk1 #177 mit drei Robotern
  zeigt dasselbe Bild). Lugges Debug: Konto schickt Anfragen (PUBLISH rr/m/i), der Server beantwortet jeden Ping (PINGRESP),
  liefert aber über 10 min **keine einzige** Nachricht aus — weder Antworten noch Pushes („0 von 5 Anfragen beantwortet“,
  letzte Echtzeit-Meldung 21.11. 23:36, „Erreichbar“ seit 22.11. 23:01 false). Der Ping-Wächter greift dabei nicht, weil
  die Verbindung lebt. python-roborock kennt genau das (HealthManager, v3.8.3 „restarting unhealthy mqtt connections“,
  seit v3.16.1 kontoweit; v6.3.0 auch für A01): nach 3 Zeitüberschreitungen in Folge Sitzung neu aufbauen, höchstens alle
  30 min. RoboHub jetzt genauso: Anfrage über die Dauerverbindung ohne jede Nachricht auf ihrem Thema = Ausfall, jede
  Antwort setzt zurück; beim dritten Ausfall Socket aus/an (neue Anmeldung, neues Abo), Protokolleintrag. Zählung je Konto.
  Die Ursache auf Roborocks Seite ist unbekannt. Prüfstand: stummer Server → Neuaufbau beim dritten Ausfall, nicht vorher,
  nicht erneut innerhalb 30 min, Antwort setzt den Zähler zurück. **Live noch nicht bestätigt.**

## 0.52.1 (22.11.2026)

- **Dreame: gewählte Saugstufe beim Raumstart vorher als 4.4 setzen** (Tester mik per PN, L20 Ultra `dreame.vacuum.r2253w`:
  Debug „Raumreinigung selects [[1,1,0,2,1]] (4.26 = 0 → fest 1)“, gewählt Saugen/Leise/1×, gesaugt mit „Standard“).
  Die Vermutung vom 20.11. („4.26 = 1 → Roboter übergeht die selects“) ist damit widerlegt. Tasshack `clean_segment` schickt
  dieselben selects ohne vorheriges 4.4 — der L20 übergeht sie trotzdem. Jetzt: weicht 4.4 von der gewählten Stufe ab, erst
  `set_properties 4.4`, dann der Start (wie der Modus über 4.23). Barny (r9547a) übernahm die selects und stellte 4.4 danach
  selbst auf denselben Wert → gleicher Endstand. Durchgänge haben keine globale Eigenschaft und bleiben nur in den selects.
  Prüfstand: Reihenfolge 4.4 vor Start, „wie eingestellt“ fasst 4.4 nicht an (Gegenprobe gefangen). **Am L20 noch nicht bestätigt.**

## 0.52.0 (21.11.2026)

Wünsche tobitobsen (Forum 144636/171, erster echter Valetudo-Test: Roborock S5 + 2× Dreame L10S Ultra, Valetudo 206.10.0 —
Verbrauchsteile anzeigen/zurücksetzen, Karte, Start/Pause/Station/Orten live bestätigt).

- **Valetudo: Stationsaufgaben.** Neue herstellerneutrale Befehle 6 Absaugen, 7 Mopp waschen, 8 Mopp trocknen, 9 Stationsaufgabe
  beenden (`RoboHubModell::BEFEHL_*`, Namen `absaugen`/`moppWaschen`/`moppTrocknen`/`stationStopp`). Valetudo:
  `AutoEmptyDockManualTriggerCapability` {action: trigger}, `MopDockCleanManualTriggerCapability`/`MopDockDryManualTriggerCapability`
  {action: start|stop} (capabilityRouters). „Beenden" stoppt, was `DockStatusStateAttribute` meldet (cleaning → Waschen,
  drying → Trocknen), sonst Hinweis ohne Anfrage. Valetudo meldet jetzt seinen Befehlsumfang (`RoboHubBefehlsumfang`) aus
  `/api/v2/robot/capabilities`. Kachel: Abschnitt „Station" mit Knöpfen nur für gemeldete Befehle; Skript `ROBO_Absaugen`,
  `ROBO_MoppWaschen`, `ROBO_MoppTrocknen`, `ROBO_StationsaufgabeBeenden`.
- **Treiber ohne eigenen Befehlsumfang bekommen nur die fünf Grundbefehle** (`BEFEHLE_GRUND`, vorher alle Befehlsnamen —
  sonst hätten Dreame, Roborock & Co. plötzlich Stationsknöpfe ohne Funktion). Vor dem ersten Abruf ebenso.
- **Valetudo: Stations-Einstellungen** als Auswahlvariablen mit Aktion (und in der Kachel unter „Einstellungen"):
  Absaug-Häufigkeit (`AutoEmptyDockAutoEmptyIntervalControlCapability`, interval), Absaug-Dauer (…DurationControl, duration),
  Mopp automatisch trocknen (`MopDockMopAutoDryingControlCapability`, SimpleToggle enable/disable), Trockendauer
  (`MopDockMopDryingTimeControlCapability`, 2h…5h/cold/quiet), Waschtemperatur (`MopDockMopWashTemperatureControlCapability`).
  Auswahl nur aus `/properties` (supported…). Zwei bis drei zusätzliche lokale Anfragen je Abruf und Fähigkeit.
- **Fehlermeldungen an Visualisierungen (alle Hersteller).** Formularpanel „🔔 Fehlermeldungen" (neuer Fachpanel-Schlüssel
  `meldung`): Liste `Meldeziele` (Kachel-Visualisierung, WebFront, SMTP), Ton `MeldeTon`, Testknopf `ROBO_MeldungTesten`.
  Muster aus FrigateHub (SUITE.md Stolperstein 22: `VISU_PostNotificationEx` mit TargetID = Roboter, Rückfall 0;
  `WFC_PushNotification`; Byte-Kürzung 32/256). Ein Fehler meldet sich einmal (Attribut `GemeldeterFehler`), erneut erst nach
  Behebung oder bei anderem Text. Fehlererkennung wie die rote Kachelzeile (`istFehler`). **Nie live zugestellt.**
- README: zwei Narwal-Zeilen standen seit 0.49.0 versehentlich unter Valetudo.
- Prüfstand: 21 neue Prüfungen (Station, Einstellungen, Meldungen), 3 Gegenproben gefangen (Grundbefehle, Doppelmeldung,
  Stopp-Zuordnung).

## 0.51.2 (21.11.2026)

- **Neuanmelden hebt auch die 24-h-Sperre „Echtzeit nie verbunden“ auf** (Forum alsk1 144636/168: nach Neuanmelden mit 0.51.1
  Roboter wieder aktiv, Echtzeit-Instanz blieb inaktiv). `echtzeitSperren()` greift bei jedem Socket-Fehler vor der ersten
  Verbindung (gedacht für Symcon 9.1/Rust-TLS bei Dreame), also auch, wenn Roborock ein gesperrtes Konto schon auf
  Verbindungsebene abweist. `anmeldungAbschliessen()` setzt jetzt `EchtzeitGesperrtBis` zurück; liegt es wirklich am
  Zertifikat, sperrt der nächste Fehlversuch wieder 24 h. Vermutete Ursache, an alsk1s Konto noch nicht belegt.
  Prüfstand: Rücksetzen beim Neuanmelden (Gegenprobe gefangen).
- Live (alsk1, 21.11.2026): Neuanmelden mit neuem E-Mail-Code hob die über 28 h lange Roborock-Sperre auf (python-roborock-Beobachtung bestätigt).

## 0.51.1 (21.11.2026)

- **Roborock: Neuanmelden beendet die Verbindungspause** (Forum alsk1 144636/159: seit über 28 h ausgesperrt). python-roborock
  (`mqtt/session.py`, `MqttSessionUnauthorized`) beobachtet, dass neue Zugangsdaten eine Abweisung „nicht berechtigt“ sowohl bei
  Drosselung als auch bei ungültigen Zugangsdaten beheben; HA meldet dafür neu an. Bisher setzte eine neue Anmeldung nur `WarteBis`
  zurück, die Echtzeit blieb bis zum Ende von `EchtzeitPauseBis` (bis 6 h) zu. Jetzt setzt `anmeldungAbschliessen()` Pause und
  Pausenstufe zurück. Ab der dritten Abweisung in Folge empfiehlt der Hinweis ein Neuanmelden mit neuem E-Mail-Code (empfohlen,
  nicht erzwungen — das Bündel bleibt). Prüfstand: Hinweistext, Rücksetzen beim Neuanmelden.
- **Roborock: Wasser-Codes 205/206/208/209/210 mit Namen** (Forum lugge 144636/160: Saros 20 meldet während der Routine
  „Flur Test“ `water_box_mode` 210, Kachel zeigte „Stufe 210“). Namen aus python-roborock `WaterModes` (210 = SMART_WATER), nur
  als Anzeige des aktuellen Werts, nicht als Wahl angeboten.
- **Live bestätigt (lugge, Saros 20, 21.11.2026):** Programme aus 0.51.0 mit richtigen Namen, Start „Flur Test“ ausgeführt;
  Wiederverbinden nach „End of file“ des Client Sockets (11:55:27) selbst geklappt, Echtzeit-Meldungen ab 11:58 wieder da.

## 0.51.0 (21.11.2026)

- **Roborock: Programme aus der App starten** (Forum lugge 144636/155: „Programme aus der App aufrufen“, z. B. erst saugen, dann
  wischen). Die Roborock-App nennt sie „Routinen“; sie liegen in der Cloud. Quelle python-roborock `web_api/scenes.py`: Liste per
  Hawk-GET `/user/scene/device/{duid}`, Start per Hawk-POST `/user/scene/{id}/execute` (kein MQTT). RoboHub holt die Liste beim
  Laden der Räume (Attribut `Programme`), legt die Variable „Programm starten“ an, sobald es Programme gibt, und zeigt sie in der
  Kachel als Abschnitt „Programme“. Skript: `ROBO_ProgrammStarten($id, $programmID)`, nur IDs aus der gemerkten Liste (auch über
  die IPSView-Seite, dort zusätzlich mit Freigabe + Schlüssel). Mi-Home-Konten haben keine Routinen. Schlägt die Liste fehl, bleiben
  Räume und Karte unberührt. Prüfstand: Hawk-Signatur, Namen, ID-Prüfung, Variable bleibt bei leerer Liste; 3 Gegenproben (2 gefangen, ID-Filter doppelt in Treiber und Gerät = gleichwertig).
  **Nie an einem echten Gerät** — Tester lugge.

## 0.50.2 (20.11.2026)

- **Wachsende Pause bei abgewiesener Daueranmeldung** (Forum alsk1 144636/150: Konto nach dem Umstieg in „Cloud bremst"): statt fest
  15 min jetzt 15 · 2^n min (Attribut `EchtzeitPauseStufe`), höchstens 6 h wie python-roborock; zurück auf 0 bei angenommener Anmeldung.
  Prüfstand: Verdopplung, Obergrenze, Rücksetzen.

## 0.50.1 (20.11.2026)

- **Roborock-Konto am Virtual IO der Konsole** (Forum alsk1 144636/146): Mit 0.50.0 verlangt Roborock einen Client Socket. Hatte die
  Konsole beim Anlegen selbst einen „Virtual IO" erzeugt (nicht „RoboHub …" benannt), tauschte `verbindungSicherstellen()` ihn nicht
  aus → „inkompatible übergeordnete Instanz". `elternVonRoboHub()` nimmt jetzt jeden Virtual IO, an dem nur dieses Konto hängt
  (trägt weder Einstellungen noch Daten). Prüfstand: Tausch + Gegenfall „anderer Nutzer am selben Virtual IO".

## 0.50.0 (20.11.2026)

- **Roborock: dauerhafte MQTT-Verbindung je Konto** (Forum alsk1 144636/125+140: trotz 0.45.0 stundenlang „Cloud bremst";
  Ursache je Abfrage eine eigene MQTT-Sitzung — aktiv 30 s Zustand + 30 s Live-Karte je Roboter, bei 3 Robotern ~720/h;
  python-roborock/HA halten EINE Verbindung). Roborock meldet jetzt `echtzeitMoeglich()`: das Konto verbindet seinen
  Client Socket mit `mqtt-eu-N.roborock.com` (Wurzel `RoboHubRoborock::MQTT_WURZEL` statt Dreames, `mqttWurzel()`), Zugang
  md5(u:k)/md5(s:k) wie bisher, abonniert `rr/m/o/…/<duid>` je Gerät.
  - Anfragen: Weiche `austausch()` im Treiber → `transport` des Kontos (`ueberDauerverbindung`): PUBLISH `rr/m/i/…`, Antworten aus
    dem Postfach (Ring aus 256 Puffern, `ReceiveData` legt unter Semaphore ab, die Anfrage liest ab ihrem Startzähler). Ohne
    stehende Verbindung wie bisher die kurze Einzelsitzung.
  - Push: `pushLesenThema()` (dps 120–127 nach python-roborock `RoborockDataProtocol` → error_code/state/battery/fan_power/
    water_box_mode/Verschleiß) in den Schnappschuss — Änderungen sofort im Roboter.
  - Daueranmeldung abgewiesen (CONNACK 4/5): `EchtzeitPauseBis` + `WarteBis` 15 min, Socket zu, keine Einzelsitzungen; danach
    selbst neu. Socket mit fremder Wurzel (z. B. Dreames) wird beim ersten Mal umkonfiguriert (`ElternWurzel`).
  - Mi Home (Xiaomi) bleibt ohne Konto-Verbindung (`echtzeitMoeglich` false, Befehle lokal per miIO).
  - Prüfstand: 10 neue Prüfungen mit nachgebautem Roborock-Server hinter dem Client Socket; Gegenprobe (ohne Übergabeweg) gefangen.
    **Live offen**: ob Symcon `ReceiveData` parallel zur wartenden Anfrage ausführt (davon hängt der Antwortweg ab) — erster Test bei alsk1.

## 0.49.1 (20.11.2026)

- **Dreame 27.1 = 3** ist laut Tasshack `CHECKING` (Station prüft), keine Warnung — bis 0.49.0 als „Prüfen" und gelber Hinweis
  (Tester mik, L20 Ultra, dauerhaft). Jetzt „In Ordnung". 27.3 = 2 (Staubbeutel „Prüfen") bleibt, dort ist es `CHECK`.
- Debug „Befehl" beim Raumstart zeigt den Wert von 4.26 (1 = benutzerdefinierte Reinigung in der App an — dann nimmt der
  Roboter die Raumeinstellungen der App statt Saugstufe/Durchgänge aus dem Startbefehl).

## 0.49.0 (20.11.2026)

- **Neu: Valetudo-Treiber** (`libs/RoboHubValetudo.php`, Dietmars Auftrag 19.11.2026; Forum: Valetudo-Nutzer bisher nur mit
  MQTT-Skripten). Lokal über die REST-Schnittstelle des Roboters (`/api/v2/…`), Quelle Hypfer/Valetudo (Apache-2.0).
  Koppeln wie Narwal (IP im Konto), Geräte-ID = Valetudo-`systemId`. Fähigkeiten aus `/robot/capabilities`.
  Zustand aus `state/attributes` (Status/Akku/Station/Presets), Verbrauchsteile mit Prozent aus `properties.maxValue`,
  Statistik, Presets als Auswahl (feste Zahlen je Stufe/Modus), Räume (`MapSegmentationCapability`, Reihenfolge, 1–3
  Durchgänge, Modus/Saugstufe vorher), Orten, Zurücksetzen. Karte aus der ValetudoMap (RLE-Ebenen → Kodierung „rahmen",
  y gespiegelt, Winkel 0° Nord/Uhrzeigersinn → 90° − Winkel).
- **Zugangsschutz** (HTTP-Basic-Auth, optional): Benutzer + Passwort im Bündel — Dietmars Ausnahme 19.11.2026; Passwort nach
  dem Koppeln aus der Eigenschaft genommen, nie in Formular/Protokoll/Debug. 401 → Hinweis, neu koppeln.
  - Prüfstand: 31 Prüfungen (`test-valetudo.inc.php`), Gegenproben Winkel und Basic-Auth gefangen. **An keinem echten Gerät.**

## 0.48.0 (19.11.2026)

- **Roboterposition als Variablen** (Forum alsk1 144636/134: Roboter-Symbol über dem eigenen, maßstabsgetreuen Grundriss in
  IPSView per Fernsteuerungs-Variablen). Eigenschaft `PositionVariablen` (Standard aus) → `positionVariablen()` bei jeder
  Kartenänderung (`kartenBildPlanen`): „Position X/Y“ (Float, m ab der oberen linken Ecke des Kartenbilds = Ecke des
  Medienbilds, × 80 = dessen Bildpunkt) und „Blickrichtung“ (0–359°, 0° = rechts, gegen den Uhrzeigersinn). Nur Rasterkarten
  mit Roboter (Live-Karte, Echtzeit-Position). Ausschalten entfernt die Variablen.
  - Prüfstand: Umrechnung, Option an/aus.

## 0.47.0 (19.11.2026)

- **Karte als Bild im festen Maßstab** (Forum alsk1 144636/132: Stationspunkte zweier Geschosse weiter verschieden groß; IPSView
  kann zwei Bilder nicht maßstabsgleich ziehen). Mit Geometrie (Raster) zeichnet `RoboHubKartenMedium` jetzt 80 px je Meter
  (`PX_JE_MM` 0,08, längste Seite höchstens 2400 px), Station/Roboter mit Radius 175 mm, Weg 30 mm. Bildgröße folgt der echten
  Fläche — in IPSView mit Originalgröße passen Geschosse zusammen. Ohne Geometrie wie bisher (ganzzahlig auf ~900 px).
  - Prüfstand: fester Maßstab + zwei Geschosse mit gleich großer Station; Gegenprobe (alte Regel) gefangen.

## 0.46.0 (19.11.2026)

- **Kachel/eigene Seite: keine `<select>` mehr** (Forum hbraun 144636/127: in IPSViews WebView passierte bei Mähzeiten/
  Regenverzögerung gar nichts, Knöpfe gingen). Native Aufklapplisten öffnen sich in eingebetteten Browsern nicht immer →
  `wahlKnopf()` (Knopf mit aktuellem Wert ▾) + `wahlListe()` (Werte als Knöpfe darunter), `waehle()`; gilt für Einstellungen
  und den Raumstart (Modus/Saugstufe/Durchgänge). Offene Liste überlebt das 15-s-Nachladen. Im Browser geprüft (Kachel:
  requestAction, eigene Seite: WebHook `Einstellung`). Prüfstand: Kachel enthält kein `<select>`.

## 0.45.0 (19.11.2026)

- **Roborock: MQTT-Anmeldung abgewiesen (CONNACK 4/5) = Drosselung** (Forum alsk1 144636/125: „Zugangsschlüssel ungültig“ nach
  einer Reinigung mit Live-Karte, Konto abgemeldet). Bisher `anmeldungNoetig` → Bündel gelöscht, neue Anmeldung per E-Mail-Code
  nötig. Jetzt `RoboHubRoborock::connackFehler()`: 15 min Pause (`WarteBis`), keine Abmeldung — wie python-roborock
  (roborock_session.py „unauthorized/rate-limit error“ → nur Wartezeit). Ungültiger Zugang fällt weiter über HTTP 401 auf.
- **Dreame: 5. Feld der selects** (Forum power1625 144636/126: 3 Räume, 2 Durchgänge → zweiter Durchgang nur im letzten Raum):
  wie Tasshack `clean_segment` — fest 1 nur bei Geräten mit „customized cleaning“ (4.26 beantwortet), sonst Reihenfolge 1…n.
  4.26 wird in derselben Abfrage wie 4.4/4.5 mitgelesen; Debug „Befehl“ zeigt die gesendete Liste. Ob das den Befund behebt,
  ist offen (Modell L10s Pro Ultra Heat, 4.26 unbekannt).
- **Karte als Bild:** Station-/Roboterradius und Wegdicke nach der längeren Bildseite (vorher kürzere) — gleich groß bei
  flachen und quadratischen Karten (Forum alsk1 144636/125).

## 0.44.0 (19.11.2026)

- **Kachel: Einstellungen auswählbar** (Forum hbraun 144636/121, Worx): Einstellungen mit Auswahlliste und Aktion (`KACHEL_EINSTELLUNGEN`,
  z. B. Mähzeiten, Regenverzögerung, Zeitzuschlag, Saugstufe, Durchgänge) kommen als `einstellungen` in die Kachel-Daten und
  erscheinen im Abschnitt „Einstellungen“ als Auswahlfeld (Kachel: `requestAction`, eigene Seite: WebHook-Befehl `Einstellung`
  „Ident:Wert“ — nur mit Freigabe + Schlüssel und nur für Felder, die die Kachel gerade als Auswahl zeigt). Ohne Bedienung als Text.
  Nach dem Setzen wird die Kachel gleich mit dem neuen Wert aktualisiert. Text-Einstellungen bleiben im Überblick.
- **Kachel: „Details“** (eingeklappt): Akkutemperatur/-spannung, Ladezyklen, WLAN, Gesamtwerte, Messerlaufzeit, Firmware; beim
  Mäher „Gemähte Fläche/Mähdauer/Mähvorgänge gesamt“ (nur in der Kachel, Variablennamen bleiben).
  - Prüfstand: 5 neue Prüfungen am Worx; Gegenprobe Positivliste der eigenen Seite gefangen.

## 0.43.0 (18.11.2026)

- **Roborock: ausgeblendete Bereiche** (Forum alsk1 144636/115): Block 17 der „rr“-Karte (Unterkarte, ein Byte je Bildpunkt,
  vacuum-map-parser-roborock `RoborockSubmapType`) wird gelesen. Bits 8/16 (REMOVED_BORDER/REMOVED_AREA) = in der App
  ausgeblendet → als „außen“ gezeichnet; das Bild schrumpft auf den sichtbaren Teil.
- **Teppiche** (Bit 1): neue Eigenschaft `KarteTeppich` (Standard aus) zeichnet sie als Karomuster (eigene Paletten-
  farbe, Raumfarben bleiben). Die Option geht mit den Anfragen `raeume`/`livekarte` ans Konto (`$geraet['teppich']`);
  Ändern lädt die Karte beim nächsten Abruf neu. `RoboHubKartenbild::sauger()` nimmt die Maske `$roh['teppich']`
  herstellerneutral.
- **Roborock: Stationsteile** (Forum alsk1 144636/116): `strainer_work_times` (150) → „Waschwanne (Sieb der Station)“,
  `cleaning_brush_work_times` (300) → „Reinigungsbürste“, nur Prozent (Zähler, keine Sekunden); zurücksetzbar
  (python-roborock `ConsumableAttribute`).
- **Variable „Verbrauchsteil zurücksetzen“** (Eigenschaft `TeilVariable`, Standard aus): Aufzählung der vorhandenen Teile,
  Wert = Position in `TEILE` (neue Teile nur hinten anfügen). Ausschalten löscht die Variable.
  - Prüfstand: 14 neue Prüfungen.

## 0.42.0 (18.11.2026)

- **Neu: Karte als Bild (Medienobjekt)** (Forum alsk1 144636/105: IPSView ohne WebView). Eigenschaft `KarteMedium` (Standard aus)
  legt unter dem Roboter das Medienobjekt „Karte“ an (Ident `KarteBild`, gecacht, PNG über `IPS_SetMediaContent`).
  `libs/RoboHubKartenMedium.php` zeichnet mit GD auf das hochskalierte Kartenbild (ganzzahlig, bis 900 px): Weg, Station,
  Roboter mit Blickrichtung (wie Kachel: 0° rechts, gegen den Uhrzeigersinn), Raumnamen (GD-Schrift, ISO-8859-2, folgt
  `KarteNamen`); außerhalb durchsichtig. Ohne GD das unveränderte Kartenbild. Neu gezeichnet gesammelt nach 3 s (Timer
  `KarteBild`) nach jeder Kartenänderung (Räume laden, Live-Karte, Echtzeit-Position). Ausschalten löscht das eigene Objekt.
  - Prüfstand: 9 neue Prüfungen, 3/4 Gegenproben gefangen (4. gleichwertig: GD überträgt die Durchsichtigkeit auch ohne
    `imagepalettetotruecolor`).

## 0.41.1 (16.11.2026)

- **Kachel: Raumstart in der großen Karte**: Wer in der vergrößerten Karte Räume antippt, bekommt darunter gleich Modus,
  Saugstufe, Durchgänge und den Startknopf sowie die Rückmeldung — vorher musste man erst schließen (Stolperstein wie
  Forum power1625 144636/68). Die Karte wird dafür etwas niedriger.

## 0.41.0 (15.11.2026)

- **Kachel: Karte vergrößern** (Forum kronos 144636/101, Dietmars Auftrag „für alle Karten"): Antippen der Karte zeigt sie über
  die ganze Kachel, so hoch wie die Kachel es zulässt — mit Raumnamen (weiter antippbar zur Raumauswahl), Station, Weg und Roboter.
  Zurück mit erneutem Antippen, „✕ Schließen" oder Esc. Gilt für jede Karte (Dreame/MOVA, Roborock, Mi Home, Ecovacs, Mäher …),
  da sie alle dieselbe Kachel nutzen. Neue Kacheltexte „Enlarge map", „Shrink map", „Close".
  - Roborock-Karte jetzt an echten Geräten bestätigt: Saros 20 (lugge 144636/100), S5 (kronos 144636/101).

## 0.40.0 (15.11.2026)

- **Neu: Roborock-Reinigungsmodus** (Forum lugge 144636/98, Dietmars Auftrag): Saugen / Wischen / Saugen und Wischen wie in der App,
  als Variable „Reinigungsmodus“ (wie bei Dreame: 0 Saugen, 1 Wischen, 2 Saugen und Wischen; Anzeige 4 Benutzerdefiniert, 5 SmartPlan)
  und in der Kachel beim Raumstart. Nach python-roborock (Apache-2.0) `v1_clean_modes.py`: Erkennung wie `get_current_cleaning_mode`,
  Setzen per `set_clean_motor_mode [{fan_power, water_box_mode, mop_mode}]`. Eingestellte Saugstufe, Wassermenge und Route bleiben,
  wo sie passen; Wasser aus → 202, lehnt das Gerät ab (Wasser-Regler), einmal 235.
  - „Wischen“ nur für Modelle mit reinem Wischen: `PRODUCTS_WITHOUT_PURE_CLEAN_MOP` (Kurzmodelle) bekommen es nicht, unbekannte
    neuere (Saros 20 a307) schon. Dort heißt Saugstufe 105 „Aus“ statt „Sanft“.
  - Nur bei Geräten mit Wischmodul (`water_box_mode` im Status). „Saugen, dann Wischen“ (Raumtabelle `seq_type`) noch nicht.
  - Prüfstand: 10 neue Prüfungen, 4/4 Gegenproben gefangen. Nicht an einem echten Gerät — Tester lugge (Saros 20).

## 0.39.0 (15.11.2026)

- **Neu: Roborock-Karte** (Forum kronos 144636/93, Dietmars Auftrag „für beide Wege"). `libs/RoboHubRoborockKarte.php` liest die
  „rr"-Kartendatei (vacuum-map-parser-roborock, Apache-2.0: Blöcke Station/Bild/Weg/Roboter, Bildkopf vom Ende her, Raum = p >> 3
  bei p & 7 = 7, 50 mm je Punkt, Zeile 0 unten) und übersetzt die Bildpunkte in die Kodierung „rahmen" von `RoboHubKartenbild`
  (Raumfarben, Trennlinien, Namen wie bei Dreame). `RoboHubKartenbild::sauger()` nimmt dafür einen fertig gelesenen Weg (`$roh['weg']`).
  - Roborock-Konto: `get_map_v1` über MQTT, Antwort Protokoll 301 (große Karten als Teile 300…301, auch in falscher Reihenfolge),
    24-Byte-Kopf mit Endpunkt und Anfrage-ID (LE), Körper AES-128-CBC mit den Rohbytes der Nonce, IV 0, dann gzip
    (python-roborock `create_map_response_decoder`, Apache-2.0). `rahmenLesen` liefert dafür auch `zufall`.
  - Mi Home: Kartenname lokal per miIO `get_map_v1` (bei „retry" bis 3 Versuche), dann `/home/getmapfileurl {"obj_name"}` beim
    Server des Geräts → `result.url` → gzip (Xiaomi-Cloud-Map-Extractor, MIT).
  - `karte()` liefert das Bild mit Räumen und Station, `liveKarte()` zusätzlich Weg und Roboter (Live-Takt während der Reinigung).
  - Ohne Karte bleibt alles wie bisher (Räume ohne Bild, Debug „Karte").
  - Prüfstand: 15 neue Prüfungen (eigene rr-Datei, 301 in 3 Teilen, Mi-Home-Download), 5/5 Gegenproben gefangen.
  **Noch an keinem echten Gerät** — Tester kronos (S5), baraka (Qrevo), lugge (Saros 20).

## 0.38.3 (15.11.2026)

- **Schlafender Roboter ohne Warnflut** (Forum lugge 144636/92, Saros 20: nachts „Der Roborock antwortet nicht (offline oder im
  Ruhezustand?)" als Warnung, danach wieder normal). Neu `RoboHubFehler::offline()` / `$geraetOffline` für „der Roboter selbst
  antwortet nicht" (Roborock/Mi Home ohne jede Antwort, Dreame/MOVA -8/80001, Ecovacs ohne Antwort/4200, Vorwerk 404). Beim Abruf:
  Konto schreibt nur Debug (kein Kontofehler), Roboter „Erreichbar" aus, Instanz bleibt 102, Kachel zeigt „nicht erreichbar".
  Eine Warnung erst nach 24 h am Stück (`OfflineSeit`/`OfflineGemeldet`), „antwortet wieder" als Meldung; auch per Echtzeit
  zurückgesetzt. Befehle an einen schlafenden Roboter melden weiter als Warnung (Nutzer hat geklickt).
  Prüfstand: 3 neue Prüfungen, 2/2 Gegenproben gefangen.
- **Roboter: WebHook beim Löschen austragen** (Verbundhinweis EMS/MeterHub, Forum dallard): `Destroy()` entfernt `/hook/robohub<ID>`
  aus dem WebHook-Control, aber nur wenn die Instanz wirklich gelöscht ist (`!IPS_InstanceExists`) — beim Entladen des Moduls
  bleibt alles. Hilfsfunktion `hookAustragen()`, bewusst nicht `UnregisterHook` (Kernfunktion ab Symcon 8.2). 3 neue Prüfungen.

## 0.38.2 (14.11.2026)

- **Befehle je Gerät statt pauschal alle fünf** (Forum baraka 144636/89: Worx zeigt „Pause“ und „Stopp“, beide pausieren — die App
  kennt nur Pause). Neue optionale Schnittstelle `RoboHubBefehlsumfang::befehlsumfang()`; das Konto schickt bei jedem Abruf `befehle`
  mit (ohne Schnittstelle alle fünf), das Gerät merkt sie im Attribut `Befehle` und zieht die Befehl-Variable und die Kachelknöpfe nach.
  Eingeschränkt: Worx/Kress/Landxcape (Start, Pause, Station), AL-KO, Bosch Indego, STIHL iMOW (ältere Generation nur Start/Station),
  Eufy E15 (je ohne Stopp/Orten), Husqvarna, Segway Navimow (ohne Orten), GARDENA (ohne Pause/Orten), Miele (ohne Station/Orten).
  Per Skript bleibt alles wie bisher (unbekannte Befehle → klare Absage, Worx-Stopp = Pause).
- **Roborock/Mi Home: „Lädt“ nur noch im Zustand 8** (Forum baraka 144636/89, Qrevo: „Reinigt (5)“ und „Lädt: An“ zugleich).
  `charge_status = 1` kommt auch beim Putzen; HA und python-roborock werten nur den Zustand aus.
- Prüfstand: 4 neue Prüfungen.

## 0.38.1 (14.11.2026)

- **Dreame/MOVA: Code 114 als Hinweis statt Fehler** (Forum power1625 144636/86, L10s Pro Ultra Heat): Kachel zeigte rot „Fehlercode 114 (114)“,
  die App nur den Hinweis „Die Reinigungsaufgabe ist abgeschlossen, bitte reinigen Sie das Waschbrett des Wischmopps.“ Tasshack führt 114
  als `CLEAN_MOP_PAD` in `WARNING_ERROR_CODE`. Neu `SAUGER_HINWEISE`: Code → „Letzte Meldung“ (gelber Hinweis in der Kachel), kein Fehler,
  kein Fehlerzustand; verschwindet, sobald der Roboter ihn nicht mehr meldet. 68/70 bleiben stumm (App zeigt sie nicht).
- **Konto: letzte Echtzeit-Meldung mit Datum**, wenn sie nicht von heute ist (live auf Dietmars MOVA-Konto: „15:53:42“ um 14:48 vom Vortag).
- Prüfstand: 5 neue Prüfungen, 2/2 Gegenproben gefangen.

## 0.38.0 (14.11.2026)

- **Neu: Roborock im Xiaomi-Konto (App „Mi Home“)** (Forum kronos, 144636/77 und /82; Dietmars Auftrag: QR-Code zuerst, Passwort als
  Rückfall). Neuer Hersteller `xiaomi`, Treiber `RoboHubXiaomi` erbt Auswertung und Befehle von Roborock.
  - Anmeldung per QR-Code (Mi-Home-App scannt, Konto fragt alle 3 s nach) oder per Passwort, bei Bedarf mit E-Mail-Code;
    bei Captcha Verweis auf den QR-Code. Gespeichert werden nur userId/ssecurity/serviceToken/passToken, Erneuern alle 6 h
    über den passToken.
  - Cloud (RC4-verschlüsselt, Server wählbar oder automatisch) nur für Geräteliste mit Token und lokaler IP; Befehle und Abfragen
    lokal per miIO (UDP 54321). Nur Roborock-Altgeräte (`roborock.vacuum.*`, `rockrobo.vacuum.*`); MIoT-Sauger „noch nicht unterstützt“.
  - Quellen: Xiaomi-cloud-tokens-extractor, micloud, aholstenson/miio (alle MIT), hass-xiaomi-miot (Apache-2.0). Live ungeprüft.
- Konto: gemeinsames Ende jeder Anmeldung (`anmeldungAbschliessen`), Passwortfeld wird nur geleert, wenn etwas drinsteht.
- Prüfstand: 40 neue Prüfungen (Referenzwerte aus Python bzw. openssl), 6/6 Gegenproben gefangen.

## 0.37.9 (14.11.2026)

- **Konto: `GetCompatibleParents()`** (SUITE.md Stolperstein 28, Forum lugge 144636/81): Nach dem Tausch auf das Virtual IO (0.37.8) meldete die
  Konsole am Roborock-Konto „inkompatible übergeordnete Instanz – Schnittstelle ändern“, weil `RequireParent()` fest den Client Socket nennt.
  Das Konto nennt der Konsole jetzt je Hersteller die passende Verbindungsart (Client Socket, WebSocket Client oder Virtual IO).
- **Roborock: Wasserfluss-Regler** (python-roborock `clean_modes.py`, „water slide“): Saros 20 meldet `water_box_mode` = 220 + Reglerstellung 1–30;
  lugges App zeigte bei 235 „Mittel“, Regler 15. Anzeige mit den App-Bereichen (≤3 Sehr niedrig, ≤7 Niedrig, ≤12 Sanft, ≤17 Mittel, ≤22 Erhöht,
  ≤27 Hoch, ≤30 Extrem), Auswahl Aus + je Bereich ein Wert (221/225/…/250), Schreiben wie bisher über `set_water_box_custom_mode`.
  Die deutschen Bereichsnamen außer „Mittel“ sind nicht gegen die App geprüft.
- Prüfstand: 6 neue Prüfungen, 3 Gegenproben (2 gefangen, ID-Filter doppelt in Treiber und Gerät = gleichwertig).

## 0.37.8 (13.11.2026)

- **Konto: von der Konsole angelegten Dreame-Socket austauschen** (Forum lugge, 144636/78, Roborock Saros 20): Client Socket „Client Socket
  (RoboHub Konto)“ ging alle 1,5–2 min auf Störung („Fehler beim Lesen: End of file“). Er zeigte auf `app.mt.eu.iot.dreame.tech:19973` mit Dreames
  Wurzel — die Konsole legt ihn beim Neuanlegen an, solange „Dreame“ voreingestellt ist. Roborock hat keine Konto-Echtzeit, also meldete sich niemand
  per MQTT an, und Dreames Server trennte. Der Tausch gegen das Virtual IO griff nicht, weil er nur Instanzen mit dem Namen „RoboHub …“ anfasste.
  Jetzt gilt auch eine Verbindungsinstanz mit Dreames privater Wurzel (trägt nur RoboHub ein) als eigene; nie, wenn noch eine andere Instanz daran hängt.
- **Roborock: Stationsbits (dss) richtig gelesen**: Nur der Wert 1 meldet „leer/voll oder fehlt“, alle anderen gelten als in Ordnung
  (python-roborock `RoborockDssCodes._missing_` → okay). Saros 20 meldet dss = 2216 (alle drei Felder = 2) bei voller Station.
- Prüfstand: 5 neue Prüfungen, 4/4 Gegenproben gefangen.

## 0.37.7 (13.11.2026)

- **Echtzeit-Stau behoben** (Forum power1625, 144636/75, Symcon auf Raspberry Pi): Die Kachel hing 4–5 min hinter dem Roboter. Im Debug-Auszug
  kamen die MQTT-Meldungen regelmäßig, aber verspätet an — der Cloud-Abruf meldete um 11:27:47 schon „Mopp trocknen“ (2.1 = 8), die Echtzeit zählte
  noch bis 11:30:59 die Reinigungszeit hoch und meldete erst 11:31:48 „Zurück zur Station“. Ursache: zu viel Arbeit je Meldung, die Warteschlange
  wuchs. Je Meldung bisher 133 `Translate()`-Aufrufe (Symcon liest dabei jedes Mal die locale.json, SUITE.md Stolperstein 24), ~40× MaintainVariable,
  Formularzeile des Kontos zweimal, ganze Kachel samt Texten. Jetzt:
  - `RoboHubText::t` mit Merkliste (je Text einmal je Aufruf): 133 → 46 Symcon-Anfragen je Meldung (Prüfstand).
  - Roboter überspringt unveränderte Werte (Merker im Puffer + Wertvergleich mit der Variable): nur noch die geänderten Variablen.
  - Kachel bei Echtzeit höchstens alle 2 s neu (Timer `Kachel` zieht den neuesten Stand nach), Aktualisierungen ohne Texte (die Kachel behält sie vom Aufbau).
  - Konto: Formularzeile „Echtzeit“ nur bei Zustandswechsel oder alle 15 s.
  Live auf einem Raspberry Pi noch nicht gemessen — Bestätigung durch Stefan offen.
- **Kachel: Raumnamen über Fahrweg und Roboterpunkt** (`.ort{z-index:2}`, Forum 144636/74).
- Prüfstand: 4 neue Prüfungen, 3 Gegenproben (2 gefangen, ID-Filter doppelt in Treiber und Gerät = gleichwertig).

## 0.37.6 (13.11.2026)

- **Kachel: Farbschema aus Symcons CSS-Variablen** (Forum power1625, 144636/70): Rahmen-Messung lag im Web falsch (Flutter-Web), in der App
  galt die Gerätefarbe. Symcons `html-sdk.js` setzt aber `--card-color`/`--content-color` u. a. auf `:root` (nicht in der HTML-SDK-Doku,
  gelesen auf Dietmars 9.x unter `/usr/share/symcon/tile/html-sdk.js`). Reihenfolge jetzt: Kartenfarbe → Textfarbe → Rahmen → Gerät mit
  eigenem Hintergrund; 8-stellig mit führendem `ff` = AARRGGBB. Feste Hell/Dunkel-Wahl gegen das erkannte Schema → eigener Hintergrund.
  Im Browser mit nachgestellten Variablen geprüft (Web hell/Gerät dunkel, App dunkel ARGB, fest Hell in dunkler App, nur Textfarbe).
  SUITE.md Stolperstein 30.
- **Kachel: Raumnamen-Schalter unter der Karte**, je Gerät im Browser-Speicher gemerkt (Schlüssel je Instanz); Formular-Eigenschaft
  `KarteNamen` ist die Vorgabe. Symbol `map` (geprüfte Liste) statt ungeprüftem `eye`.
- Prüfstand: 2 neue/1 angepasste Prüfung.

## 0.37.5 (13.11.2026)

- **Kachel: eigener Hintergrund, wenn das Farbschema nicht erkennbar ist** (Forum power1625, 144636/68): Symcon nennt der Kachel das
  Schema der Visualisierung nicht (HTML-SDK-Doku); ist der umgebende Rahmen nicht lesbar (Symcon-App, manche Browser), galt die
  Geräteeinstellung — bei Stefan helles Design + dunkles Gerät = weiße Schrift auf Weiß, in der App umgekehrt. Jetzt zeichnet die Kachel
  in diesem Fall `--seite` als Hintergrund (Klasse `eigenbg`), Text bleibt immer lesbar. Festes Hell/Dunkel im Formular unverändert.
- **Kachel: Raumnamen im Kartenbild abschaltbar** (neue Eigenschaft `KarteNamen`, Standard an) — in der kleinen Karte sehr dominant.
- **Kachel: Raumnamen besser antippbar**: Stationssymbol `pointer-events:none` (lag bei Stefan über „Küche"), unsichtbar vergrößerte
  Tippfläche (`::before`), `touch-action:manipulation`. Im Browser nachgeprüft: Klick aufs Stationssymbol und knapp unter den Namen wählt den Raum.
- **Dreame-Sauger: Meldung aus 0.37.3 wird geleert**, auch wenn der Roboter 68 nicht mehr meldet („Meldung bleibt"). Leere Texte legen
  keine neue Variable an — Sauger ohne Meldungs-Variable bekommen keine.
- Prüfstand: 6 neue Prüfungen, 2/2 Gegenproben gefangen.

## 0.37.4 (12.11.2026)

- **Dreame: Ränder deaktivierter Räume ausgeblendet** (Forum power1625, 144636/66): Rand-/Wandpunkte tragen bei Dreame die Raumnummer
  mit (Standard `0x80 | id`, v3 Wand 1/2 mit id; Tasshack `100 + id`). 0.37.3 blendete nur die Flächenpunkte aus, die Ränder blieben
  als Fragmente stehen. Neu `RoboHubKartenbild::raumVonPunkt()`.
- **Code 68/70 gar nicht mehr angezeigt**: Stefan sieht „Mopp abnehmen" dauerhaft, auch in der Station; die App zeigt ihn nie. Kein Fehler,
  keine Meldung mehr (die von 0.37.3 geschriebene wird geleert), nur Debug.
- Prüfstand: 2 neue/1 angepasste Prüfung, 2/2 Gegenproben gefangen (Standard- und v3-Kodierung je eindeutig).

## 0.37.3 (12.11.2026)

- **Dreame: Raumtyp 10 = „Closet"/„Garderobe"** (Forum power1625, 144636/64): Die 0.37.2-Annahme „eigener Name schlägt Typ" war nicht
  die Ursache — Stefans Raum 4 hat keinen eigenen Namen, sondern Typ 10. Tasshack nennt ihn „Closet", die App „Garderobe"; RoboHub hatte
  „Storage room" („Abstellraum").
- **Dreame: in der App deaktivierte Räume ausgeblendet** — Kartenfeld `delsr` (Tasshack `hidden_segments`). Deaktivierte Räume behalten
  ihre Fläche in der Karte, deshalb griff der 0.37.2-Filter „ohne Bildpunkte" nicht. Jetzt fallen sie aus Raumliste, Raumknöpfen und
  Kartenbild (Punkte nicht gezeichnet, keine Marke). Live-Rahmen ohne `delsr`: Stand der gespeicherten Karte (Raumliste) gilt.
- **Dreame-Sauger: Code 68 „Mopp abnehmen" / 70 „Mopp abgenommen" sind Hinweise** (Tasshack `WARNING_ERROR_CODE`, App zeigt keinen
  Fehler): „kein Fehler", Text in „Letzte Meldung" wie beim Mäher. Bewusst eng gehalten.
- Prüfstand: 6 neue Prüfungen, 4/4 Gegenproben gefangen.

## 0.37.2 (12.11.2026)

- **Raumnamen nach Minuten englisch** (Forum power1625, 144636/61, Symcon 9.1 auf Raspberry Pi): Dreame, Ecovacs, Narwal und Roborock
  übersetzten Raumtypen („Kitchen" → „Küche", „Room 6") schon beim Speichern im Konto — je nach Ablauf (Formular-Knopf vs. Live-Karten-
  Takt) landete Deutsch oder Englisch im Attribut. Jetzt sprachneutral gespeichert, übersetzt bei der Anzeige (`RoboHubText::raum`,
  auch mit Index „Bedroom 2" und „Room n"): Kachel-Marken, Raumknöpfe, Variable „Raum reinigen", Formular. Alte deutsche Namen bleiben lesbar.
- **Dreame: eigener Raumname schlägt den Typ** (wie Tasshack `Segment.set_name`) — „Garderobe" erschien als Typname „Abstellraum".
- **Dreame: gelöschte/deaktivierte Räume ausgeblendet** — seg_inf-Einträge ohne Bildpunkte fallen weg wie bei Tasshack (`is_unmmaped`),
  ohne Bildinfo bleibt alles. Neu `RoboHubKartenbild::raeumeImBild()`.
- Prüfstand: 4 neue/angepasste Prüfungen, 2/2 Gegenproben gefangen.

## 0.37.1 (10.11.2026)

- **robot_activity: nicht erreichbarer Roboter = unbekannt** (Live-Befund 10.11.2026): Der LiDAX ist seit 07.11. offline (Abdeckung offen,
  vermutlich eingewintert), meldete aber „Bereit, außerhalb der Station" mit gültigem `validUntil` — Abruf und Konto-Echtzeit laufen weiter,
  der Zustand war drei Tage alt. FrigateHub hätte so Alarme für einen ausgeschalteten Mäher unterdrückt. Jetzt: Variable „Erreichbar"
  = aus → state -1, moving/outsideStation false, validUntil 0. Prüfung + Gegenprobe.

## 0.37.0 (10.11.2026)

- **Neuer Vertrag `ROBO_GetActivity($id)`, `robot_activity` 1.0** (abgestimmt mit FrigateHub 10.11.2026, Dietmars Auftrag): rein
  lesend, ohne Cloud-Anfrage — class, name, state (Verbund-Enum RoboHubModell), stateVariableID, moving (1/3/9), outsideStation
  (0/1/2/3/7/9/11), since, updatedAt (max. Variablen-Aktualisierung/Abruf/Echtzeit), validUntil (Echtzeit verbunden: jetzt + 300 s,
  sonst updatedAt + 3 × Abfrageabstand, deaktiviert: 0), realtime, pollIntervalS. Discovery über die Modul-GUID von RoboHubGeraet.
  Konto-Aktion `info` liefert dafür zusätzlich `echtzeit` (lokal).
- Prüfstand: 6 neue Prüfungen, 3 Gegenproben (2 gefangen, ID-Filter doppelt in Treiber und Gerät = gleichwertig).

## 0.36.1 (10.11.2026)

- **SUITE.md Stolperstein 27 — Verbindung im Fehlerstatus selbst neu aufbauen** (Hinweis EMS/EEBUSGateway 10.11.2026): Client Socket
  (Dreame/MOVA) und WebSocket Client (Husqvarna/GARDENA) verbinden sich nach einem Abbruch nicht zuverlässig selbst neu. Bisher blieb
  die MQTT-Echtzeit nach einem Abbruch bis zum nächsten Übernehmen/Neustart aus; beim WebSocket gab es nur einen Versuch, und der
  verband bei unverändertem Ziel gar nicht neu. Jetzt: Takt `Verbindung` schaltet die eigene Verbindungsinstanz im Status ≥ IS_EBASE
  aus und wieder an, Abstand 5/10/20 … s bis 5 min (WebSocket bis 1 h wegen des Monatskontingents der Hersteller-Schnittstelle),
  Meldung nur beim ersten Versuch und einmal bei Wiederherstellung. 24-h-Zertifikatssperre (Symcon 9.1) unverändert.
- Konfigurator: Spaltenüberschrift „Art" → englisches Literal „Type" (Konsole übersetzt); Prüfstand prüft jetzt auch Formulartexte
  mit weniger als drei Kleinbuchstaben am Stück.
- Prüfstand: 10 neue Prüfungen (Nr. 27), 5/5 Gegenproben gefangen.

## 0.36.0 (09.11.2026)

- **Store-Punkt 25** (Symcon-Review 06.11.2026, SUITE.md Store-Checkliste): Formulare werden nicht mehr selbst übersetzt — die Konsole
  übersetzt `caption`/`label`/`confirm` (auch Select-Optionen und Listenspalten) selbst über die locale.json. 148 feste
  `RoboHubText::t('…')` in Formularen, Einleitungs- und Doku-Zeilen sind jetzt englische Literale; Hersteller-, Regionen- und
  Miele-Länderauswahl ebenso. Selbst übersetzt wird nur noch, was die Konsole nicht sieht: Texte mit eingesetzten Werten
  (`t('… %s', …)`), Listenzeilen-Werte (Formular: Aufbau), Variablennamen, Meldungen, Kachel.
- Prüfstand: `konsole()` bildet die Übersetzung der Konsole nach (alle Formularabrufe laufen durch sie); neue Prüfungen „kein festes
  t() im Formular" und „jeder feste Formulartext hat einen deutschen Eintrag" — beide Gegenproben gefangen. Dabei gefunden und
  behoben: die Namen ausgeblendeter Bereiche in „Formular: Aufbau" wären englisch geblieben.

## 0.35.2 (04.11.2026)

- Raum-Einstellungen (Dreame): bis 0.35.0 gespeicherte deutsche Werte („Saugen · Standard") erschienen unter Englisch weiter deutsch
  (Dietmars Live-Befund). `gespeichert()` im Roboter führt alte deutsche Werte auf den englischen Schlüssel zurück und übersetzt dann;
  „Feuchte/Stufe/Modus n" speichert der Treiber jetzt sprachneutral als „Moisture/Level/Mode n". ApplyChanges baut die Variable neu auf,
  damit der Text gleich nach dem Update in der aktuellen Sprache steht. Prüfstand: beide Richtungen, Gegenprobe gefangen.

## 0.35.1 (04.11.2026)

- Englisch: restliche deutsche Texte übersetzt, gefunden über einen Abgleich mit allen Wörtern, die nur in den deutschen Übersetzungen
  vorkommen: Geräteart „Saug-/Wischroboter" (Roboter, Konto, Konfigurator), „, Modell", „📍 Orten", „keine" (Zeitpläne), „✅ Angemeldet als",
  „⏏️ Abmelden", „Ausgeblendet" (Formular: Aufbau), „Instanz #" (Konfigurator), Fehlertexte bei Roborock/Ecovacs/STIHL, Worx „HBI error",
  Husqvarna „GPS navigation problem".
- Raum-Einstellungen und Dreame-Zeitpläne: der Treiber speichert Modus/Saugstufe/Wasser als englischen Schlüssel, übersetzt wird erst
  bei der Anzeige (vorher blieb nach einem Sprachwechsel der alte Text stehen, bis der Roboter wieder einen Live-Rahmen schickte).
- Befund Live (Locale `en`, Browser deutsch): Konsole und Visualisierung übersetzen Texte, die genau einem locale.json-Eintrag
  entsprechen, selbst noch einmal in die Sprache des Browsers. Gemischte Anzeige gibt es deshalb nur, wenn Symcon und Browser
  verschiedene Sprachen haben. Instanznamen bleiben deutsch (der Code vergleicht sie).

## 0.35.0 (04.11.2026)

- **Englische Fassung.** Quelltexte im Code sind jetzt englisch (Symcon-Konvention), Deutsch kommt aus `locale.json`. Betrifft alle
  Formulare (Konto, Roboter, Konfigurator), Variablennamen, Auswahl- und Zustandstexte, Meldungen, Fehlertexte der Treiber und die Kachel.
  - `libs/RoboHubText.php`: `t()` übersetzt Sätze mit `%s`-Platzhaltern, `wert()` übersetzt Tabellenwerte erst bei der Anzeige,
    `tag()` Wochentage. Die drei Module setzen den Übersetzer im Konstruktor (`$this->Translate`).
  - Eine Haupttabelle `libs/locale.json`, `.tools/locale-bauen.php` kopiert sie in die drei Modulordner.
  - Kachel: `KACHEL_TEXTE` im Roboter, die Kachel schlägt per `T()` nach; Uhrzeit- und Zahlenformat je Sprache.
  - Kennungen bleiben deutsch und unverändert: Idents, Feld- und Teile-Schlüssel, Präfixe, Instanznamen der Verbindungsinstanzen.
  - Bestehende Variablen behalten ihren Namen (`MaintainVariable` benennt nicht um); neue entstehen in der Sprache von Symcon.
  - Logik, die an deutschen Texten hing (Wischen-Erkennung im Raumplan, Feuchte-Stufe), arbeitet jetzt sprachunabhängig.
- Neuigkeiten im Formular auf Versionen ab 0.26.0 gekürzt (älteres im CHANGELOG).
- Prüfstand: `.tools/test-englisch.inc.php` — Übersetzung für jeden `t()`-Text, Platzhalter-Anzahl, Formulare aller Hersteller ohne deutsche
  Reste, Kachel-Texte vollständig. Debug-Ausgaben bleiben deutsch.

## 0.34.1 (04.11.2026)

- **Store-Review (Stable 0.34.0):** „Es wäre schön, wenn du bei echtzeitKonfigurieren nur die variablen Parameter überschreiben würdest.
  Die anderen Parameter wurden ja bereits durch GetConfigurationForParent prinzipiell gesetzt." → `echtzeitKonfigurieren` setzt die
  vollständige Konfiguration nur beim ersten Einrichten einer Verbindungsinstanz (Attribut `ElternEingerichtet`), danach nur Host/Port/Open
  bzw. URL/Headers/Active per `IPS_SetProperty`. Prüfstand: drei Prüfungen, zwei Gegenproben gefangen.

## 0.34.0 (30.10.2026)

- **Neuer Treiber Eufy-Mähroboter E15 (T2880)** (`libs/RoboHubEufyMaeher.php`), lokal über Tuya 3.5 auf TCP 6668, nach
  Thomvanoorschot/ha-eufy-e15 und jasonacox/tinytuya (beide MIT). Anlass: Forum sirkentucky, der E15 läuft nicht über Eufys Saugroboter-Cloud.
  - Anmeldung einmalig: Eufy-Konto → Tuya-Sitzung der App → `localKey`. Der passende Schlüssel wird über die Aushandlung mit dem
    Mäher unter der eingetragenen IP gefunden. Passwort, Eufy-Zugang und Tuya-Sitzung werden verworfen. Tuya-Server nur `*.tuya{eu,us,cn,in}.com`.
  - Eine kurze Sitzung je Abruf (der Mäher erlaubt nur eine). DP 107/108 kommen nur als Meldung: RoboHub bittet darum und merkt sich den
    letzten Stand. Start DP 1 = true, Fortsetzen DP 2 = false, Pause DP 2 = true (nur beim Mähen), Zur Station DP 1 = false, keine Befehle beim
    Speichern der Karte. Schnitthöhe DP 110, Lautstärke DP 26. Stopp und Orten ehrlich abgelehnt, weil lokal nicht möglich.
  - Keine Antwort auf die Aushandlung (falscher Schlüssel oder andere Sitzung offen) führt nicht zum Abmelden; ausgeschalteter Mäher gilt als nicht erreichbar.
- Eufy-Treiber: Hersteller „nur Saugroboter", Fehlermeldung nennt den MQTT-Server und den Weg für Mäher.
- Prüfstand: Prüfwerte für Signatur und Tuya-Passwort unabhängig mit dem openssl-Werkzeug, nachgebauter E15 im Speicher und über echtes TCP. **Nicht
  gegen ein echtes Gerät und nicht gegen tinytuya geprüft.**

## 0.33.0 (29.10.2026)

- **„🪟 Formular: Aufbau"** (verbindliche Verbund-Konvention SUITE.md 3a) im Konto (drei Fachpanels) und im Roboter (bis zu sechs):
  je Bereich „Aufgeklappt", „Zugeklappt", „🙈 Ausgeblendet" (Vorrang in dieser umgekehrten Reihenfolge) und Position 1–99.
  Property `FormLayout` (Liste `{Key, Panel, Opened, Closed, Hidden, Pos}`, Schlüssel stabil und unsichtbar), Ausgeblendet =
  `visible:false` (Felder bleiben, Werte wirken weiter), unbekannte Schlüssel fallen auf Standard zurück, Knopf
  „↩️ Auf Standard zurücksetzen" (`ROBOHUB_/ROBO_ResetFormLayout`, nur im offenen Formular). Zentral im Trait `RoboHubFormular`;
  der Konfigurator (ein Fachpanel) bekommt kein Panel. Prüfstand: 17 Prüfungen, Gegenproben (Vorrang, Weglassen, Sortierung) gefangen.

## 0.32.0 (27.10.2026)

- **Neuer Treiber Eufy RoboVac/Omni** (`libs/RoboHubEufy.php`) für Geräte mit Eufys MQTT-Anbindung (u. a. X8/X9/X10, L60, C10/C20,
  G50, Omni S1 Pro), nach jeppesens/eufy-clean (Fork von martijnpoppen/eufy-clean, „Eufy-Clean License") — **nur Protokollwissen**
  (Endpunkte, DPS-Nummern, Protobuf-Feldnummern aus den .proto-Dateien der App), kein Code übernommen.
  - Anmeldung home-api v2 (App „Eufy"), sonst v1 („Eufy Clean"); user_center_info → user_center_token, gtoken = md5(id);
    get_user_mqtt_info → AWS-IoT-Endpunkt (nur `*.iot.*.amazonaws.com`), thing_name, Client-Zertifikat + Schlüssel. Passwort
    verworfen; ein Erneuerungsweg ist nicht belegt → bei 401 neue Anmeldung.
  - Zustand aus der AIOT-Geräteliste (letzte DPS-Werte): Protobuf-Geräte (153 WorkStatus mit Station/Waschen/Trocknen/Staub,
    163 Akku, 158 Saugstufe, 177 Fehler, 167 Statistik, 168 Verschleiß in Stunden) und Zahlen-Geräte wie G50 (15, 104, 102,
    106/177, 109 s, 110 m², 150 Verschleiß in Minuten). Reine Tuya-Altgeräte (11S, 30C, G30 …) bewusst nicht.
  - Befehle per MQTT mit gegenseitigem TLS (Amazon Root CA 1): ModeCtrlRequest auf 152 (Start mit auto_clean, Fortsetzen,
    Pause, Stopp, Heim), Orten 160, Saugstufe 158; G50 nach Mitschnitt (5=1/3, 122=1/2, 103, 102).
- `RoboHubMqttSitzung::oeffnen()` kann ein Client-Zertifikat (Datei 0600, nur während des Handschlags auf Platte; geprüft gegen einen
  lokalen TLS-Server mit Pflicht-Client-Zertifikat).
- Live 27.10.2026 ohne Konto: beide Anmeldewege antworten (5002), user_center_info/MQTT-Info → 401.
- Prüfstand: 19 Eufy-Prüfungen, 17/17 Mutationen gefangen. **Live ungetestet.**

## 0.31.0 (27.10.2026)

- **Neuer Treiber Kärcher Home Robots** (`libs/RoboHubKaercher.php`, RCV 2/3/5, RCF 3, RVC 3, RVM 4, RVF 7) über die Cloud von
  3iRobotix, nach lafriks/python-karcher (MIT, Anmeldung, Signatur, Verschlüsselung, MQTT-Themen) und vosadci/karcher-rcv5-ha
  (MIT, Zustandsregeln, work_mode-Gruppen, Fehlercodes, Befehle, Verschleiß, Produkt-IDs).
  - Anmeldung mit E-Mail + Passwort (AES-128-ECB wie die App), Passwort danach verworfen. Erneuern ohne Passwort über
    `user-center/auth/login/token` — von keiner Quelle genutzt, Format live ermittelt (Server nennt fehlende Felder,
    `versionCode`/`phoneSys` als Text, erfundener Schlüssel → 401). Scheitert die Erneuerung, ist eine neue Anmeldung nötig.
  - TLS gegen das selbst signierte 3iRobotix-Zertifikat (gepinnt als Wurzel, Namensprüfung an), REST per curl, MQTT als kurze
    Sitzung je Abruf/Befehl. Nur Server unter `.3irobotix.net` aus der (verschlüsselten) Serverliste werden angenommen.
  - Zustand aus `prop.get` (work_mode-Gruppen, Station über status 4/charge_state, Fehler nur außerhalb der Station, 21xx als
    „Letzte Meldung"), Akku, Fläche, Dauer, Verschleiß (Haupt-/Seitenbürste, Filter, Mopp) mit Reststunden, Firmware.
  - Befehle: Start/Pause/Stopp (`set_room_clean` 1/2/0, Bereichsreinigung über `set_zone_clean`), Stopp auf dem Heimweg =
    `stop_recharge`, Zur Station, Orten; Saugstufe/Wassermenge/Reinigungsmodus per `prop.set` (RVF 7 zählt Saugstufen ab 1).
  - Live 27.10.2026 ohne Konto: Serverliste, Signatur und Verschlüsselung angenommen (623 für unbekannte E-Mail), MQTT-TLS mit
    gepinntem Zertifikat ok, falscher Zugang → CONNACK 5.
- Prüfstand: 16 Kärcher-Prüfungen, 16/16 Mutationen gefangen. **Live ungetestet.**

## 0.30.0 (27.10.2026)

- **Neuer Treiber Miele Scout** (`libs/RoboHubMiele.php`) über die offizielle Miele-3rd-Party-API, nach Grizzelbee/ioBroker.mielecloudservice
  (MIT, Passwort-Anmeldung, Endpunkte, Aktionen), astrandb/miele (MIT, Phasen und Programme des Saugroboters) und nordicopen/pymiele (MIT).
  - Zugang: Client-ID + Secret (developer.miele.com) + Password-Grant mit `vg` (Land, neue Länderauswahl im Konto-Feld „Region").
    **Dietmars Entscheidung 27.10.2026 (wie AL-KO):** Client-ID + Secret im Bündel, Passwort verworfen.
  - `v1/devices?language=de`, nur Typ 23. Zustand aus programPhase 5889–5910 (Mieles eigener Text bevorzugt, Tabelle als Rückfall),
    status 255 = nicht verbunden, 8 = Störung. Programm (ProgramID) als Auswahl „Saugstufe" (Auto/Turbo/Leise, schreibbar).
  - Befehle `PUT actions` processAction 1/3/2 (Start/Pause/Stopp), vorher `GET actions` — im falschen Zustand ehrliche Absage mit den
    erlaubten Aktionen. „Zur Station"/„Orten" bietet Miele nicht.
  - Live 27.10.2026: alter (thirdparty/token) und neuer (Keycloak) Anmeldeserver antworten mit `invalid_client` auf erfundene Daten.
- Prüfstand: 11 Miele-Prüfungen, 10/10 Mutationen gefangen. Formular-Sucher in den Tests überspringt unsichtbare Elemente samt Inhalt.
  **Live ungetestet.**

## 0.29.0 (27.10.2026)

- **Neuer Treiber Vorwerk Kobold VR200/VR300** (`libs/RoboHubVorwerk.php`) nach stianaske/pybotvac (MIT), der Homey-App
  SornyKonst/Homey-App-Vorwerk-Kobold (MIT, live an einem VR300) und bndtblds/homeassistant-vorwerk (Apache-2.0, Fehlercodes).
  - Anmeldung per E-Mail-Code (Auth0 passwordless, mykobold.eu.auth0.com) über den vorhandenen Code-Weg des Kontos (wie Roborock).
    ID-Token (nicht access_token) für beehive `users/me/robots` → je Roboter `secret_key` + `nucleo_url` (nur Vorwerks eigener
    Server `nucleo.ksecosys.com` wird angenommen).
  - Zustand/Befehle direkt an nucleo, je Nachricht HMAC-SHA256 („serial(klein)\nDatum\nKörper", Kopf `NEATOAPP`). Zustände
    nach robot-state.js (Pause auch bei Aktion 6/15, `ui_alert_invalid` kein Fehler), deutsche Fehlertexte nach Gruppen,
    Hinweise als „Letzte Meldung". startCleaning je houseCleaning-Dienst (VR200 basic-1 ohne Karte, VR300 gespeicherte Karte).
    VR300-Bereiche über `getMapBoundaries` (nur Flächen, keine Sperrlinien), Nummer = Position.
  - Kontoschlüssel nur für die Geräteliste: lehnt Vorwerk die Erneuerung ab, laufen die Roboter weiter (Hinweis statt Abmelden).
  - Kobold VR7 bewusst nicht: andere Cloud („orbital"), einzige offene Quelle fragt keinen Zustand ab und setzt eine feste
    Grundriss-ID.
  - Live 27.10.2026 ohne Konto: beehive verlangt Schlüssel (401), nucleo kennt das Format („robot_serial not found", 404).
- Prüfstand: 18 Vorwerk-Prüfungen (Nachbau prüft jede HMAC-Signatur), 12/12 Mutationen gefangen. **Live ungetestet.**

## 0.28.0 (27.10.2026)

- **Neuer Treiber AL-KO Robolinho** (`libs/RoboHubAlko.php`) über die offizielle AL-KO Gardentech IoT API, nach trx222/alko-cli
  (MIT, docs/API.md + FINDINGS.md), jonkristian/alko und chave/homebridge-alko-mower (beide MIT).
  - Zugang: Client-ID + Secret beantragt der Nutzer bei AL-KO; OAuth2-Password-Grant mit `offline_access` → Erneuerungsschlüssel.
    **Dietmars Entscheidung 27.10.2026 (wie Husqvarna):** Client-ID + Secret im Bündel, Passwort verworfen. Neue Konto-Felder
    `ClientId`/`ClientSecret` (nur bei AL-KO sichtbar); das Secret wird nach der Anmeldung ins Bündel übernommen und geleert.
  - AWS-IoT-Shadow: `GET things/{id}/state` → reported. operationState WORKING/HOMING/CHARGING/IDLE_BASE_STATION/IDLE, Grund der
    Heimfahrt aus `situationFlags.homingTrigger*`, Regen-/Frostpause, PIN-Sperre (LOCKED_PIN/…_LOCKED), Fehlercode 999 = kein
    Fehler, Laufzeiten in Minuten, Wochenplan (aktive Fenster) als Anzeige. Befehle als PATCH `state/desired` mit `rtc`.
  - Live 27.10.2026: Anmeldeserver lehnt erfundene Daten mit `400 {"error":"invalid_client"}` ab, API ohne Schlüssel 401.
- Konto: Treiber können Zusatzfelder bekommen (`zusatz`).
- Prüfstand: 18 AL-KO-Prüfungen, 13/13 Mutationen gefangen (eine Lücke beim ausgeschalteten Mähfenster geschlossen).
  **Live ungetestet** (kein Gerät).

## 0.27.0 (26.10.2026)

- **Neuer Treiber STIHL iMOW** (`libs/RoboHubStihl.php`), beide Cloud-Generationen in einem Hersteller:
  - Neue Generation (iMOW 5/6/7, EVO; myimow) nach simontims/ha-imow-v2 (MIT, 16.10.2026), Endpunkte durch ioBroker.imow
    bestätigt: STIHL-ID (Azure B2C) mit eigenem PKCE-Paar, Cookies und CSRF selbst geführt (Schritte authorize → SelfAsserted →
    confirmed → token), danach Erneuerungsschlüssel — Passwort verworfen. `mowertwin/api/v1/mowers`: overallState-Gruppen wie
    die App (Regen → Wetterpause, „busy" nach isDocked), battery.level ×100, errorsActive mit STIHLs Text aus der öffentlichen
    Datei `myimow.stihl.com/app-i18n-messages/de-DE.json` (einmal am Tag), Statistik, Zonen. Befehle start-mowing
    (Vorgabedauer des Mähers, optional Zone), resume aus der Pause, pause, end-job-and-return-to-dock.
  - Ältere RMI-Serie nach ChrisHaPunkt/stihl-imow-webapi (MIT, 15.07.2026): Formular-Anmeldung (csrf-token/requestId),
    Weiterleitungen selbst verfolgt, Schlüssel aus dem Fragment, 30 Tage. **Dietmars Entscheidung 26.10.2026:** Passwort nicht
    speichern, Hinweis drei Tage vorher („Letzte Meldung"), danach Anmeldung nötig. Zustands-/Fehlertabelle (15 + 147 Einträge,
    deutsche Texte) aus messages.py. Befehle startMowing (2 h) und toDocking.
  - Erkennung: erst neue Generation; lehnt STIHL ab oder ist kein Mäher im Konto → RMI mit denselben Daten.
  - Live (ohne Konto): beide Anmeldungen laufen bis zur Passwortprüfung und erkennen „falsch" richtig.
- Konto: optionaler Treiber-Haken `anmeldeText()` für die Erfolgsmeldung.
- **Fehler behoben:** `ROBO_RaeumeReinigen("0")`, Kachel und Variable „Zone mähen" verwarfen die ID 0 (`array_filter`). Betraf
  den Husqvarna-Hauptbereich (workAreaId 0) seit 0.17.0 und die erste STIHL-Zone. Jetzt `raumIds()`.
- Prüfstand: 21 STIHL-Prüfungen (Nachbau mit PKCE-Prüfung, Cookies, Weiterleitungen), 14/14 Mutationen gefangen.
  **Live ungetestet** (kein Gerät).

## 0.26.0 (26.10.2026)

- Kachel mit **Symcon-Symbolen** statt Emojis (Forum Thilo, Beitrag 50: Emoji-Schrift auf dem Pi nachinstalliert, Symcon-Symbole
  trotzdem lieber). Weg laut HTML-SDK-Doku „Icons": `<script src="/icons.js">`, Font Awesome mit Symcons Satz, Stil `fa-light`.
  Live geprüft: Dietmars 9.0 liefert /icons.js ohne Anmeldung (1,2 MB gz), alle 41 benutzten Namen sind enthalten.
- Neue Eigenschaft `Symbole` (0 Symcon-Symbole = Standard, 1 Emojis). Ohne icons.js (fremde Seite) fällt die Kachel von selbst
  auf Emojis zurück (`window.FontAwesome` fehlt). Ein bekanntes Emoji am Anfang von Modul-Texten (Rückmeldung, Hinweise,
  Überblick) wird zum Symbol — PHP-Texte bleiben unverändert.
- Geprüft im Browser mit Symcons echter icons.js: Symbole in Zustand, Knöpfen, Hinweisen, Zeitplänen, Überblick, Verbrauchsteilen;
  Emoji-Einstellung und Rückfall ohne icons.js. Prüfstand: Icon-Namen gegen die geprüfte Liste, Ersatz-Emoji Pflicht, 4/4 Proben.
- **Offen:** Darstellung in der echten Kachel-Visualisierung und in IPSView (WebHook-Seite) noch nicht gesehen.

## 0.25.0 (26.10.2026)

Anlass: erster echter Worx im Forum (baraka/Adam, Worx m500, Beitrag 51): Anmeldung, Aktualisierung, Mähzeiten,
Regenverzögerung, Zeitzuschlag und Wochenplan laufen. Drei Befunde:
- **„Zustand" zeigte nur die Zahl** (bei Adam „7", live auch auf Dietmars 9.0: „5"). Symcon-Doku Wertanzeige: OPTIONS gibt es nur
  für Boolean/String; Ganz-/Kommazahlen brauchen INTERVALS mit „Konstante". `presAnzeige()` baut jetzt Intervalle
  (IntervalMin/MaxValue, ConstantActive/Value, …); live an einer Wegwerf-Variable geprüft: 7 → „Fehler". Seit 0.19.4 falsch.
  Prüfstand: OPTIONS in einer Zahlen-Wertanzeige ist jetzt ein Fehler, Intervall-Schlüssel werden geprüft.
- **Graues „!" und „übergeordnete Instanz ist inaktiv"** an Konto und Roboter, weil Konten ohne Echtzeit seit 0.18.8 einen
  geschlossenen Client Socket als Elternteil haben. Jetzt Symcons Virtual IO (`{6179ED6A-…}`, implementiert dieselbe
  Datenschnittstelle `{79827379-…}`, live gelesen) — immer aktiv. Eigene alte Sockets („RoboHub …") werden automatisch getauscht,
  fremde Instanzen nie. Konfigurator-Kette nimmt das tatsächliche Elternmodul (unverändert).
- **Mehr Werte wie das frühere MQTTworx:** Akkutemperatur (bt.t), Akkuspannung (bt.v), Ladezyklen (bt.nr), WLAN-Signal (rsi),
  Strecke gesamt (st.d m → km), Messerlaufzeit (st.b min → h), Firmware (fw). Neue Modellfelder, generisch nutzbar.
  „Gesperrt" nicht übernommen (in ioBroker.worx nicht belegt).
- Prüfstand: 7/7 Mutationen gefangen (OPTIONS statt Intervalle, Intervalle inaktiv, Client Socket statt Virtual IO, fremde
  Instanz getauscht, Strecke/Messerzeit ohne Umrechnung, WLAN fehlt). **Live ungetestet** — Virtual IO und Zustandsanzeige
  nach dem Update an Dietmars Anlage prüfen.

## 0.24.0 (26.10.2026)

- **Neuer Treiber Narwal** (`libs/RoboHubNarwal.php`, nach sjmotew/NarwalIntegration v1.0.11, MIT) — lokal,
  WebSocket ws://IP:9002, keine Anmeldung. Koppeln per IP wie iRobot (`koppelText()` jetzt je Treiber).
  - Rahmen `01 len+2 22|2a len Thema Protobuf`; Protobuf ohne Schema (eigener Leser/Schreiber); Antworten ohne Kennung
    in Sendereihenfolge zugeordnet, Bestätigungen mit 5-s-Frist, Heartbeat schuldet keine Antwort.
  - Erkennen: get_device_info mit allen bekannten Produktschlüsseln, nur die Antwort mit Geräte-ID zählt.
  - Abfrage schonend (NarwalIntegration #82/#90): nur Abo + Grundstatus, kein Wecken; ohne Rundmeldung „Ruht" mit Akku
    (Arbeitszustand der Antwort ist dann veraltet). Zustand nach models.py (2 mit 3.4 = 3, Rückfahrt 3.7 UND 3.10).
  - Befehle mit Weckfolge; Start = alle Räume über clean/start_clean (App-Vorgabe Saugen+Wischen), Pause → task/resume.
  - `RoboHubNarwalVerbindung`: WebSocket ohne TLS, zerteilte Nachrichten, Ping/Pong; `wsLesen` liefert jetzt `fin`.
- **Fehler behoben (alle Hersteller mit MQTT/WebSocket-Sitzung):** Kamen die letzte Antwort und das Schließen der Gegenstelle im
  selben Netzwerkpaket, ging die Antwort verloren (`RoboHubMqttSitzung::warte` gab sofort auf). Betrifft Worx-Befehle, Roborock,
  iRobot. Gefunden über den echten WebSocket-Gegenspieler für Narwal (sporadisch rot); jetzt deterministisch geprüft.
- Konto: „Wozu dieses Modul?" nennt jetzt auch Narwal, Navimow und Indego.
- Prüfstand: 37 Narwal-Prüfungen inkl. echtem WebSocket-Gegenspieler (`.tools/narwal-nachbau.php`), 13/13 Mutationen
  gefangen. iRobot-Teil räumt Merkdateien abgebrochener Läufe weg (Ursache des gelegentlich roten Koppel-Tests;
  parallele Prüfstand-Läufe stören sich über Temp-Dateien — nie gleichzeitig laufen lassen). **Live ungetestet.**

## 0.23.0 (26.10.2026)

- **Neuer Treiber Bosch Indego** (`libs/RoboHubIndego.php`, nach pyIndego MIT + sander1988/Indego Apache-2.0,
  Anmeldeanleitung nach ioBroker.boschindego MIT).
  - Anmeldung Bosch SingleKey ID (Azure B2C, Client-ID der Bosch-App), Rücksprung `com.bosch.indegoconnect://login`
    wie HA, ohne PKCE — live geprüft: Bosch nimmt die Adresse an, eine fremde lehnt es ab (AADB2C90006). Code aus der
    Konsolenzeile, der Adresse oder pur. Link-Anmeldung im Konto jetzt für Navimow und Indego gemeinsam.
  - `alms`, `alms/{sn}` (Modell aus bareToolnumber), `alms/{sn}/state` (Zustand nach pyIndego-Tabelle deutsch, Fehler,
    mowed, runtime), `operatingData` nur beim Mähen/Laden oder ohne bekannten Akku (wie HA — weckt sonst den Mäher);
    Indego 1000/1100/1200/10C/13C melden Volt → Prozent umgerechnet. Befehle PUT state mow/pause/returnToDock.
  - Eigener User-Agent (Boschs Firewall weist manche ab, pyIndego-Issue 119). 401 → beim nächsten Abruf erneuern.
- Modell: neues Feld `Fortschritt` (%, Position 42).
- Prüfstand: 17 Indego-Prüfungen, 11/11 Mutationen gefangen (eine Lücke beim gemerkten Akku durch zweiten Ruheabruf
  geschlossen). **Live ungetestet** (kein Gerät).

## 0.22.0 (26.10.2026)

- **Neuer Treiber Segway Navimow** (`libs/RoboHubNavimow.php`, nach niddu85/home-assistant-navimow und
  ioBroker.navimow, beide MIT). Dietmars Entscheidung: wie ioBroker die von Segway veröffentlichte
  Smart-Home-Kennung `homeassistant` nutzen und das offen sagen (Formular + README).
  - Anmeldung: „🔗 Anmeldeseite öffnen" (`ROBOHUB_Anmeldelink`, Link-Knopf), Weiterleitung
    `http://localhost:1/callback`, eingefügte Adresse oder Code → `oauth/getAccessToken`. Kein Passwort bei RoboHub,
    kein E-Mail-Feld. Erneuerungsschlüssel rotiert.
  - `smarthome/authList`, `getVehicleStatus` (vehicleState, capacityRemaining bzw. battery), `sendCommands`
    (StartStop/PauseUnpause/Dock; aus der Pause → PauseUnpause an). Ergebnis je Gerät geprüft, `alreadyInState` = ok.
  - Vorzeitig abgelehnter Schlüssel (401/4005/TOKEN_EXPIRED) meldet nicht ab, sondern erneuert beim nächsten Abruf.
- Prüfstand: 21 Navimow-Prüfungen, 10/10 Mutationen gefangen (Fortsetzen, alreadyInState, Abmelden bei 4005, Rotation,
  Akku-Vorrang, Merkdatei, Code aus Adresse, Antwort-Echo, requestId, Dock ohne params). **Live ungetestet** (kein Gerät).
- Nicht gebaut: Echtzeit (MQTT über WebSocket mit Bearer-Kopf), Position/Fortschritt/Zone, Orten, Einstellungen.

## 0.21.1 (26.10.2026)

- Forum (Thilo): beide Roomba reinigen zeitgesteuert fehlerfrei. `ROBO_Starten()` & Co. lieferten die Rückmeldung nur als
  Rückgabewert, die Kachel blieb stumm (über `RequestAction` auf „Befehl" kam sie). Jetzt `befehlMitKachel()` für alle
  öffentlichen Befehle (Start, Pause, Stopp, Station, Orten, Räume). Fachlich war der Weg schon gleich.
- Kachel auf Raspberry Pi ohne Bildzeichen: dort fehlt die Emoji-Schrift (Pi OS) — Hinweis im Forum
  (`fonts-noto-color-emoji`), kein Modulfehler.

## 0.21.0 (25.10.2026)

- Worx/Kress/LandXcape/Ferrex (Vorlage ioBroker.worx, MIT; Funktionsumfang wie das eingestellte Symcon-Modul MQTTworx):
  - `Regenverzoegerung` (cfg.rd, 0–720 min in 30er-Schritten) → `{"rd":n}`.
  - `Maehzeiten` an/aus: Draht-Mäher sc.m 1 = Zeitplan, 2 = Party-Modus (`{"sc":{"m":1|2,"distm":0}}`, ioBroker
    sendPartyModus); Vision sc.enabled 1/0.
  - `Zeitzuschlag` (sc.p −100…100 in 10er-Schritten) → ganzes sc zurück, ohne `ots` (ioBroker mowTimeEx, „hotfix").
  - Wochenplan als Anzeige (`zeitplaene`, neue Art „text"): sc.d/sc.dd je Tag [Start, Minuten, Kantenschnitt],
    Sonntag zuerst (ioBroker this.week); Vision sc.slots {d, s (min ab Mitternacht), t, cfg.cut.b}. Nur Tage mit Mähzeit.
  - Werte werden vor dem Senden geprüft; ungültige gehen nie an den Mäher.
- Ident `Zeitplan` war im Roboter schon die Kachel-Aktion „Termin schalten" — neue Variable heißt deshalb `Maehzeiten`.
  Prüfstand-Regel: kein Modell-Feld darf wie eine Sonder-Aktion heißen. 4/4 Mutationen gefangen (ots bleibt, Party-Modus
  falsch, Wochentage verschoben, Wertprüfung weg).
- Nicht gebaut: Mähzonen (mz/mzv), Wochenplan ändern, Kantenschnitt-Befehl, Echtzeit. **Live ungetestet** (kein Gerät).

## 0.20.0 (25.10.2026)

- Anlass: Code-Vergleich mit den vorhandenen Symcon-Mähermodulen (demel42 Automower, Symcon GARDENA) — beide haben
  Echtzeit über WebSocket, RoboHub fragte nur alle 5 bzw. 15 min ab. Dietmar: „Mache so wie Du denkst".
- **Konto: zweite Echtzeit-Art „websocket"** neben MQTT. Treiber melden sie über `echtzeitArt()`; das Konto legt dann
  Symcons WebSocket Client (`{D68FD31F-…}`, gleiche Datenschnittstellen wie der Client Socket, Eigenschaften live gelesen:
  Active/URL/Headers/VerifyCertificate/Type) statt des Client Sockets an. Ziel (Adresse + Kopfzeilen) über `wsZiel()`,
  im Puffer `WsZiel`, `VerifyCertificate` immer an. Text-Meldungen → `pushLesenMit($text, $buendel)` → Schnappschuss →
  Kinder. Lebenszeichen über `wsLebenszeichen()` alle 60 s (null = keins). Getrennt → neuer Versuch nach 30 s,
  verdoppelnd bis 1 h (`WsFehler`), dabei frisches Ziel. Token-Wechsel (Husqvarna `token`) → neu verbinden.
  Bestandskonten: eigene Verbindung („RoboHub …") falscher Art wird gegen die richtige getauscht, Fremdes nie.
  Konfigurator-Kette nimmt das tatsächliche Verbindungsmodul des Kontos.
- **Husqvarna** (aioautomower auth.py/session.py/const.py, MIT): `wss://ws.openapi.husqvarna.dev/v1` mit
  `Authorization: Bearer`, Ereignisse `*-event-v2` (mower/battery/planner allgemein zusammengeführt,
  cuttingHeight → settings.cuttingHeight, headlights → settings.headlight.mode, position → positions; message/calendar
  übergangen), Lebenszeichen = leere Textnachricht. Ereignisse werden in den Zwischenspeicher eingearbeitet (Alter bleibt,
  sonst spränge der nächste Abruf auf den alten Stand zurück); mit Echtzeit gilt er 1 h. Neu: `Schnitthoehe` (1–9) und
  `Scheinwerfer` (nur bei `capabilities.headlights`), schreiben per POST `mowers/{id}/settings`.
- **GARDENA** (py-smart-gardena smart_system.py, MIT): Adresse je Verbindung per POST `/v2/websocket` {locationId}
  (erster Ort mit Mäher), kein Kopf, kein Lebenszeichen; Meldungen MOWER/COMMON werden in den Zwischenspeicher gemischt.
- Prüfstand: Echtzeit-Strecke beider Treiber, Tausch alter Verbindungen, Konfigurator-Kette; 6/6 Mutationen gefangen
  (Zertifikat aus, Zwischenspeicher nicht nachgezogen, keine 1-h-Gültigkeit, Fremdes gelöscht, Ziel nicht frisch,
  Dateialter nicht erhalten).
- **Live offen** — Dietmar hat weder Husqvarna noch GARDENA.

## 0.19.4 (25.10.2026)

- Forum (Thilo): „Zustand" ließ sich zum Archivieren nicht speichern — Symcon: „Diese Darstellung ist nur für Variablen
  mit einer Variablenaktion verfügbar". `Zustand` war eine Aufzählung (ENUMERATION) ohne Aktion. Jetzt Wertanzeige mit
  Optionen (`presAnzeige()`: Value, Caption, IconActive, IconValue, ColorActive, ColorValue). Alle anderen Aufzählungen
  (Befehl, Raum, Einstellungen) haben eine Aktion.
- Prüfstand: Nachbildung zieht die Darstellung bei jedem `MaintainVariable` nach; neue Prüfung „Aufzählung nur mit
  Aktion" über alle angelegten Variablen. Die alte Pauschalregel „nie ColorValue/ColorActive" (vor der Korrektur von
  SUITE.md Stolperstein 9 am 18.10.2026) ersetzt durch „nur in der Wertanzeige".

## 0.19.3 (25.10.2026)

- Forum (Thilo, Umzug in seine echte Installation ohne Probleme): kein Weg, einen einzelnen Roboter abzuschalten.
  Neu: Property `Deaktiviert` („🔌 Diesen Roboter deaktivieren", Muster OCPPHub-Ladepunkt) — Takte Abruf/LiveKarte/
  Zuordnen aus, Status 104 „Inaktiv", `frageKonto()` lehnt ab (Abruf, Befehle, Karte, Skripte), Echtzeit-Meldungen
  werden ignoriert, Variablen bleiben unverändert.

## 0.19.2 (24.10.2026)

- Forum (Thilo, 0.19.1): Umstellen aller vier iRobot-Einstellungen funktioniert, die App übernimmt sie — **Schreiben
  live bestätigt.** Gleichzeitig offene iRobot-App → RoboHub-Fehler (Roboter erlaubt eine Verbindung); die Meldung nach
  dem zweiten abgewiesenen Versuch nennt jetzt diese Ursache.
- Wunsch: der Schalter soll nur die Kachel betreffen, nicht die Variablen. `EinstellungenZeigen` steuert jetzt nur den
  Überblick der Kachel; `IPS_SetHidden` entfällt. Einmalige Rücknahme (Attribut `EinstellungenFreigegeben`): bei
  ausgeschaltetem Schalter werden versteckte Einstellungs-Variablen wieder sichtbar, danach bleibt eigenes Verstecken
  unangetastet.
- Kachel-Überblick: Einstellungen mit Namen („Saugstufe: Hoch"), außer Reinigungsmodus.

## 0.19.1 (24.10.2026)

- Forum (Thilo, 0.19.0): alle vier Einstellungen am Schlafzimmer-Roomba = iRobot-App (Bildschirmfotos). **Live
  bestätigt (Lesen).** Beschriftungen auf die App umgestellt: Saugleistung Niedrig / **Mittel (= carpetBoost)** / Hoch,
  Durchgänge Einfach-/Zweifach-Reinigung / Reinigung nach Raumgröße, Behälter „Weiter reinigen, wenn voll" /
  „Nicht reinigen, wenn voll". Werte und Idents unverändert, nur die Aufzählungstexte.

## 0.19.0 (24.10.2026)

- Forum (Thilo, zwei Roomba 980): Einstellungen wie in der iRobot-App gewünscht (nicht in der Visu); Positionsanzeige
  für ihn unwichtig. Dietmars Auftrag: beides bauen, abschaltbar.
- iRobot-Einstellungen als Auswahl-Variablen (Weg `optionen` → `setze`), gelesen aus den Schaltern im Stand, geschrieben
  als `delta` {"state": …} — Saugleistung (`carpetBoost`/`vacHigh`: Eco / Automatisch / Höchste Leistung, Zuordnung wie
  Home Assistant), Durchgänge (`noAutoPasses`/`twoPass`: nach Raumgröße / einmal / zweimal) je als **Paar** (roombapy
  `set_preferences`: halbe Gruppen ignoriert der Roboter stillschweigend), Kanten-Reinigung (`openOnly` invertiert),
  Verhalten bei vollem Behälter (`binPause`). Rückmeldung „übernommen", wenn der Roboter die Werte zurückmeldet (≤ 8 s).
  Neue Felder `Durchgaenge`, `Kantenreinigung`, `BehaelterVoll` (Modell, Position 33–35). Vergleich: openHAB hat alle
  vier als Kanäle, Home Assistant nur die Saugleistung.
- Position (900er, `pose` in mm/° ab Station): beim Abruf während der Fahrt wenigstens 5 s zuhören, Positionen je
  Reinigung (Startzeitpunkt) in einer Temp-Datei sammeln (≥ 5 cm Abstand, höchstens 5000 Punkte). `liveKarte()` baut
  daraus ohne eigene Verbindung `RoboHubKartenbild::positionsKarte()` (1-m-Raster, Station, Weg, Roboter mit Winkel).
  Abschaltbar über die vorhandenen „Kartenbild zeigen"/„Live-Karte".
- Gerät: Property `EinstellungenZeigen` (Standard an) für alle Hersteller — aus: Einstellungs-Variablen versteckt
  (`IPS_SetHidden`), nicht im Kachel-Überblick; Aktion bleibt.
- Prüfstand: nachgestellter Roboter liefert Einstellungen (wie Thilos Rohdaten) und Positionen, nimmt `delta` an;
  Mutationen (Paar halbiert, Kanten vertauscht, Verstecken wirkungslos, Positionen nicht gesammelt) gefangen.

## 0.18.14 (24.10.2026)

- Rohdaten Thilo (980 „Schlafzimmer", ohne App, nach einer Reinigung in der Station): `cleanMissionStatus` ist
  danach vollständig zurückgesetzt (`mssnM`, `sqft`, `mssnStrtTm` = 0) — die Fläche kommt am Ende also nicht nach.
  Dafür trägt `cleanMissionStatus.nMssn` (2619) immer die Zahl aller Reinigungen → „Reinigungen gesamt" jetzt daraus,
  wenn `bbmssn` fehlt. Weitere gelieferte Felder für später: `pose` (x/y/theta), `vacHigh`/`carpetBoost` (Saugstufe),
  `noAutoPasses`/`twoPass` (Durchgänge), `binPause`, `ecoCharge`, `openOnly`, `schedHold`.

## 0.18.13 (24.10.2026)

- Forum (Thilo): **0.18.12 live bestätigt** — Knöpfe während eines Befehls gesperrt, „Station" beim Reinigen fährt
  ohne Fehler heim. Fläche/Dauer blieben beim Reinigen 0 (`sqft`/`mssnM`). Dauer jetzt ersatzweise aus
  `cleanMissionStatus.mssnStrtTm` (roombapy types.py, Unix-Zeit), nur bei laufendem Zyklus und plausiblem Start
  (0–6 h), nie kleiner als `mssnM`. Kachel zeigt die Fläche erst ab > 0. Ob der 980 `sqft` am Ende meldet: Rohdaten
  einer langen Reinigung angefragt.

## 0.18.12 (24.10.2026)

- Forum (Thilo, 0.18.11): Farben automatisch richtig (Rahmen-Hintergrund lesbar), Start/Pause/Station/Orten aus
  Kachel und `ROBO_Starten` funktionieren — **iRobot-Befehle live bestätigt**. Offen waren:
  - „Zur Station" mitten in der Reinigung: keine Änderung in 10 s. Der 980 nimmt `dock` beim Reinigen nicht an;
    Home Assistants Roomba-Integration pausiert deshalb zuerst. Jetzt: Phase aus dem Stand nach dem Abonnieren,
    bei `run` erst `pause` (bis `stop`), dann `dock`.
  - Klick auf einen zweiten Knopf, während ein Befehl lief → Fehler (eine Verbindung je Roboter). Jetzt: Kachel sperrt
    die Befehlsknöpfe bis zur Rückmeldung (Sicherheitsnetz 40 s); im Treiber eine Semaphore je Roboter (30 s), sodass
    Abruf und Befehle (auch aus Skripten) nacheinander laufen.
  - Abschnitt „Einstellung" mit „0 m² · 0 min" missverständlich → „Überblick", Eintrag „🧹 bisher … m² · … min".
- Prüfstand: nachgestellter Roboter ignoriert `dock` beim Reinigen; Mutation „ohne vorheriges Anhalten" gefangen.

## 0.18.11 (24.10.2026)

- Forum (Thilo, Kachel-Test, zwei 980): Kachel-Schrift dunkel auf dunklem Hintergrund — die Kachel folgte nur
  `prefers-color-scheme` (Gerät/Browser hell, Symcon-Visualisierung dunkel). Symcon gibt einer Kachel kein Farbschema
  mit (HTML-SDK-Doku; Verbund-Befund NRGDashboard 31.07.2026). Neu: Property `Farbschema` (0 automatisch, 1 hell,
  2 dunkel); automatisch misst die Kachel den Hintergrund des umgebenden Rahmens (falls lesbar), sonst wie bisher.
- „Start" war immer hervorgehoben (feste Klasse). Jetzt `hauptknopf` aus dem Zustand: Reinigen → Pause, Fehler →
  keiner, sonst Start.
- „Start" aus der Kachel: gesendet, aber Roomba fuhr nicht. RoboHub trennte direkt nach dem Senden. Jetzt bleibt die
  Sitzung offen (abonniert, bestätigt), bis der Roboter die erwartete Phase meldet (start/resume → run, pause → stop,
  dock → hmUsrDock/dock/charge), höchstens 10 s; Rückmeldung nennt die Phase oder sagt ehrlich „keine Änderung".
- Prüfstand: nachgestellter Roboter meldet nach einem Befehl die neue Phase (Flag `.taub` = schweigt); Kachel-Felder.

## 0.18.10 (24.10.2026)

- Forum (Thilo, zwei Roomba 980, einer mit, einer ohne iRobot-Cloud, 0.18.9): Abruf in allen Lagen (Station, frei,
  Reinigen, Pause, Heimfahrt, Laden) richtig, Akku = App. **0.18.9 live bestätigt.** Sporadisch zwei Fehler:
  - „keinen Zustand gemeldet (11–12 Meldungen in 15 s: nur wifistat, QoS 0)" — Roboter erreichbar, schickt aber
    keinen Stand. Jetzt: letzter vollständiger Stand (Schnappschuss, neues Feld `_abgerufen`) bleibt bis 15 min
    stehen; danach ehrlicher Fehler.
  - „Zielcomputer verweigerte die Verbindung" auf 8883 (beide Wege) — Roboter erlaubt eine Verbindung und gibt sie
    verzögert frei. Jetzt: schnell abgewiesen (< 3 s) → nach 3 s ein zweiter Versuch.
- Der zuletzt erfolgreiche TLS-Weg je Adresse wird gemerkt (Temp-Datei) und zuerst versucht — beim 980 entfällt der
  vergebliche OpenSSL-Handschlag, der beim Roboter kurz die einzige Verbindung belegt (mögliche Ursache der Abweisung).
- Konto: als „RoboHub Echtzeit (…)" angelegter, aber ungenutzter Socket wird umbenannt (bei Thilo angelegt, als noch
  Dreame voreingestellt war).
- Prüfstand: nachgestellter Roboter kann „nur Funk" (Flag-Datei); Rückfall, Altersgrenze, gemerkter Weg, Wiederholung
  nach Abweisung — vier Mutationen gefangen.

## 0.18.9 (21.10.2026)

- Forum (Thilo, Roomba 980, Symcon 9.0 Windows — nicht 9.1): Konfigurator-Kette mit 0.18.8 ohne Fehler, Roboter
  angelegt, Befehl „Start" fährt los (erster echter Befehl über den eigenen TLS-Weg). Abruf meist „Der Roboter hat
  keinen Zustand gemeldet", einmal kam ein Stand (direkt nach dem Start), danach ging nichts mehr.
- Vermutete Ursache: `RoboHubMqttSitzung` bestätigte eingehende PUBLISH mit QoS 1/2 nicht (kein PUBACK). Ein
  Server mit kleinem Sendefenster schickt dann nichts weiter. roombapy (paho/aiomqtt) bestätigt automatisch.
- Jetzt: QoS 1 → PUBACK, QoS 2 → PUBREC/PUBCOMP (gilt für alle Treiber mit kurzer MQTT-Sitzung). iRobot-Stand
  wartet 15 s statt 8 s. Fehlermeldung und Debug „Zustand" nennen Anzahl, Themen, QoS und Größe der Meldungen.
- Prüfstand: nachgestellter alter Roomba schickt den Stand mit QoS 1 und erst nach jeder Bestätigung weiter;
  Mutation „keine Bestätigung" gefangen.

## 0.18.8 (20.10.2026)

- Forum (Thilo, Symcon 9.1, iRobot, nach 0.18.7): „Die Erstellungs-Kette für RoboHubKonto ist unvollständig", dazu
  ein zweites Konto mit gleichem Hersteller/IP. Ursache: Konten ohne Konto-Echtzeit (iRobot, Roborock, Worx & Co.,
  Ecovacs, Husqvarna, GARDENA) hatten bewusst keinen Client Socket; `RoboHubKonto` verlangt ihn aber in
  `parentRequirements`. Symcon verwendet laut Doku (Konfigurator, Variante 2) nur übergeordnete Instanzen wieder,
  „deren übergeordnete Instanzen die Anforderungen der Kette erfüllen" → Konto ohne Socket nicht wiederverwendet,
  neues Konto angelegt, dessen Kette nicht bis zum I/O reicht. Dreame/MOVA-Konten (mit Socket) betraf es nicht.
- Jetzt: jedes Konto bekommt einen Client Socket (bei Herstellern ohne Echtzeit „RoboHub Verbindung (…, ungenutzt)",
  bleibt geschlossen). Die `create`-Kette reicht bis zum Client Socket (ohne Vorgaben).
- Live an 9.1 noch nicht bestätigt; Thilo testet.

## 0.18.7 (20.10.2026)

- Forum (Thilo, Symcon 9.1, Roomba 980 nach erfolgreichem Koppeln): „Erstellen" im Konfigurator → „Instanz #… hat
  bereits ein übergeordnetes Objekt (Code -32603)", danach war auch das Konto weg (Symcon rollt die Kette zurück).
  Ursache: `ApplyChanges` des neuen Roboters verband ihn per `ordneKontoZu()` sofort selbst mit dem Konto; Symcon
  verbindet laut `create`-Kette erst danach und scheitert dann. Betrifft alle Hersteller unter 9.1.
- Jetzt: Roboter ohne Verbindung warten 15 s (Timer `Zuordnen`, `RequestAction('Zuordnen')`) und ordnen sich nur zu,
  wenn sie dann noch frei sind. Falsch verbundene korrigieren weiter sofort.

## 0.18.6 (20.10.2026)

- Forum (Thilo, Roomba 980): Koppeln scheiterte. Ohne HOME-Druck: „nicht erreichbar ()" — TLS-Handschlag
  abgelehnt, Grund leer, weil `@stream_socket_client` die OpenSSL-Meldung schluckte. Ursache sehr
  wahrscheinlich: Firmware 2.x beherrscht RFC 5746 nicht, OpenSSL 3 (Symcon-PHP: 3.5.2) lehnt das ab
  („unsafe legacy renegotiation disabled"); roombapy setzt dafür `OP_LEGACY_SERVER_CONNECT`, PHP hat keinen
  Schalter. Mit HOME-Druck: Roboter schwieg auf die UDP-Suche.
- Neu `libs/RoboHubTls.php` (Dietmars Entscheidung): schlanker TLS-1.2-Client, nur RSA-Schlüsseltausch mit
  AES-128/256-CBC und HMAC-SHA256/SHA1, ohne Neuverhandlung (Anfragen des Servers werden ignoriert).
  `RoboHubIrobot::geraetVerbinden` versucht erst OpenSSL, dann den eigenen Weg; Fingerabdruck-Prüfung (TOFU)
  für beide gleich. `RoboHubMqttSitzung` nimmt auch eine `RoboHubTls`-Leitung.
- Fehlermeldung nennt jetzt die OpenSSL-Gründe (`openssl_error_string`) und den des eigenen Wegs.
- UDP-Suche: bis zu drei Versuche; Antwort wird eine Stunde gemerkt (Temp-Datei je IP) und genutzt, wenn der
  Roboter im Koppelfenster schweigt. Anleitung: erst ohne HOME anmelden, dann mit.
- Prüfstand: nachgestellter „alter" Roboter (`roomba-nachbau.php … alt`, echtes OpenSSL, nur TLS 1.2 +
  RSA/AES-CBC), Koppeln/Abruf/Befehl über den eigenen Weg, PRF-Prüfvektor, gemerkte UDP-Antwort, fremdes
  Zertifikat, Störer (`tls-stoerer.php`) für verfälschten Handschlag und verfälschte Nutzdaten. Mutationen
  (Schlüsselblock, Auffüllung, Verlauf, MAC, Lese-Zähler) gefangen; die eigene Finished-Prüfung ist nicht
  erreichbar, weil der Server den verfälschten Verlauf zuerst bemerkt.

## 0.18.5 (14.10.2026)

- Forum (Stephen): „RoboHub Konto" in der Instanzauswahl nicht gefunden. Konfigurator hat jetzt den Knopf
  „➕ Neues RoboHub Konto anlegen" (`ROBOCFG_KontoAnlegen`), der die Konto-Instanz direkt anlegt; Hinweistext
  bei leerer Liste verweist darauf.

## 0.18.4 (14.10.2026)

- Symcon 9.1 „Rust" (Forum, Torsten): Client Socket zum Dreame-Echtzeitserver „als fehlerhaft markiert", Konto und
  Roboter zeigten „übergeordnete Instanz fehlerhaft". Ursache: Alle Zertifikate der Dreame-Kette (Blatt,
  Zwischen-, Wurzel) gelten ab 31.12.1969; rustls-webpki (`src/time.rs`) unterstützt keine Zeiten vor 1970
  und lehnt sie als BadDerTime ab. OpenSSL (Symcon ≤ 9.0, PHP) akzeptiert sie.
- Nach Dietmars Entscheidung: Scheitert der Socket, ohne dass je eine Echtzeit-Verbindung stand, schließt
  RoboHub ihn für 24 h (`EchtzeitGesperrtBis`), erklärt den Grund im Konto, protokolliert eine Warnung und fragt
  weiter ab. Neuer Versuch automatisch nach 24 h (Timer `Erneuern`) oder per Knopf
  (`ROBOHUB_EchtzeitNeuVersuchen`). Konten, deren Echtzeit schon einmal lief, verbinden wie bisher neu.
  Zertifikatsprüfung bleibt unverändert streng (kein VerifyPeer-Abschalten).

## 0.18.3 (14.10.2026)

- Fix (Forum, Torsten, Symcon 9.1): alle 2–4 Minuten „Warning: Parameter DIGITS is not defined for this
  presentation" (RoboHubGeraet Zeile 770). Die Wertanzeige kennt DIGITS laut Symcon-Doku nur bei Ganz- und
  Kommazahlen; RoboHub setzte es auch bei Text-Variablen. Jetzt nur noch bei Zahlen.
- Prüfstand: MaintainVariable-Nachbildung meldet Zahlen-Parameter der Wertanzeige bei Text/Schalter.
