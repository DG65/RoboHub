# Changelog

Ältere Versionen: [CHANGELOG-Archiv.md](https://github.com/DG65/RoboHub/blob/main/CHANGELOG-Archiv.md)

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
