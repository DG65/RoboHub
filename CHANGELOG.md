# Changelog

Ältere Versionen: [CHANGELOG-Archiv.md](https://github.com/DG65/RoboHub/blob/main/CHANGELOG-Archiv.md)

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

## 0.18.2 (13.10.2026)

- Fix (Forum, Torsten, Symcon 9.1 „Rust"): Erstellen über den Konfigurator meldete „Die erstellte Instanz
  wird nicht als Liste erstellt. Sie benötigt eine eigene Verbindung oder ist nicht mit der Verbindung des
  Konfigurators kompatibel." Der Konfigurator hat seit 0.2.0 keine eigene übergeordnete Instanz; nach
  Symcon-Doku (Konfigurator, `create` Variante 2) wird jetzt die Kette Roboter → Konto angegeben
  (Konto über Hersteller + Kontofeld), Symcon nutzt das vorhandene Konto. Die Selbstzuordnung der
  Roboter bleibt als Sicherheitsnetz.
- Torstens L10 (`dreame.vacuum.r5023a`) liefert laut Rohdaten alle Blöcke — Anmeldung und Abruf laufen.

## 0.18.1 (13.10.2026)

- Fix (Forum, Torsten, Windows-Symcon): Anlegen eines Roboters brach ab mit „Warning: Entry in parameter
  OPTIONS includes unknown sub-parameters" (module.php Zeile 80/81 = Zustand/Befehl). Ursache: Schlüssel
  `Icon` in den Aufzählungs-Optionen; laut Symcon-Doku heißt er `IconValue` (Value, Caption, IconActive,
  IconValue, Color). Ältere Versionen ignorierten das, neuere brechen ab. Die Regel in SUITE.md (Punkt 9)
  nannte ebenfalls `Icon` — an die EMS-Sitzung gemeldet.
- Prüfstand: MaintainVariable-Nachbildung meldet unbekannte OPTIONS-Schlüssel wie neuere Symcon-Versionen.

## 0.18.0 (13.10.2026)

- **Neuer Treiber GARDENA smart system** (`libs/RoboHubGardena.php`, nach py-smart-gardena, MIT), abgeleitet
  von RoboHubHusqvarna (gleiche Anmeldung, Zwischenspeicher je Marke):
  - Orte `GET /v2/locations` → `GET /v2/locations/{id}`, Geräte aus „included" (Dienst-ID bis „:"),
    Werte aus `{"value": …}`; nur Geräte mit MOWER-Dienst.
  - Zustand aus activity/state (PARKED_AUTOTIMER/FROST = Wetterpause), lastErrorCode deutsch,
    batteryLevel, operatingHours.
  - Befehle `PUT /v2/command/{MOWER-Dienst}` MOWER_CONTROL: START_SECONDS_TO_OVERRIDE (3 h) bzw.
    START_DONT_OVERRIDE nach Pause, PARK_UNTIL_NEXT_TASK, PARK_UNTIL_FURTHER_NOTICE; Pause/Orten ehrlich
    „gibt es nicht".
  - Abruf aller Mäher zwischengespeichert 15 Minuten.
- RoboHubHusqvarna für Ableitung geöffnet (`apiBasis`, `apiName`, `marke`, `maeherHolen`).
- Prüfstand 14 Mutationen; live: Endpunkt antwortet ohne Token mit 401. **Kein echter Mäher getestet.**

## 0.17.0 (13.10.2026)

- **Neuer Treiber Husqvarna Automower** (`libs/RoboHubHusqvarna.php`, nach aioautomower, MIT), offizielle
  Automower Connect API:
  - Anmeldung `client_credentials` mit Application Key/Secret (Husqvarna-Entwicklerportal); beides bleibt
    nach Dietmars Entscheidung im Bündel, Token (24 h) wird damit erneuert. Köpfe Bearer,
    `Authorization-Provider: husqvarna`, `X-Api-Key`.
  - Ein gemeinsamer Abruf `/mowers` für alle Mäher eines Kontos, 5 Minuten zwischengespeichert
    (Limit 10.000/Monat), nach Befehlen verworfen; HTTP 429 → Konto wartet 1 h.
  - Zustand aus state/activity/restrictedReason (Wettersensor/Frost = Wetterpause), Fehlercodes deutsch,
    Akku, Laden, Mähzeit gesamt; Arbeitsbereiche als Zonen. Befehle: Start (180 min) bzw.
    ResumeSchedule nach Pause, Pause, ParkUntilNextSchedule (Zur Station), ParkUntilFurtherNotice
    (Stopp), StartInWorkArea.
- Konto: Felder „Application Key"/„Application Secret", Anleitung Entwicklerportal, ehrlicher Hinweis.
- Prüfstand: Anmeldung, Köpfe, gemeinsamer Abruf, Zustände, Befehle, Erneuerung, Limit; 14 Mutationen
  gefangen. Live (ohne Konto): Token-Endpunkt antwortet auf unbekannten Schlüssel mit `invalid_client`.
  **Kein echter Mäher getestet.**

## 0.16.0 (13.10.2026)

- **Neuer Treiber iRobot Roomba/Braava** (`libs/RoboHubIrobot.php`, nach roombapy, MIT), lokal ohne Cloud:
  - Koppeln statt Anmelden: UDP „irobotmcs" an die IP (Port 5678) → BLID aus „Roomba-…", dann
    Passwort-Magie über TLS 8883 nach HOME-Tastendruck. Mehrere Roboter je Konto (Bündel wird ergänzt).
  - Zertifikat nach Dietmars Entscheidung „Trust on first use": SHA-256 beim Koppeln gemerkt, danach jede
    abweichende Verbindung abgelehnt, bevor Anmeldedaten gesendet werden. Cloud-Sitzungen
    (`RoboHubMqttSitzung::oeffnen`) bleiben unverändert streng; neu nur `ausStrom` für den bereits
    geprüften Gerätestrom.
  - Je Abruf kurze MQTT-Sitzung (Client-ID = Benutzer = BLID, Abo „#"), Zustand aus `state.reported`
    zusammengeführt: Phase/Zyklus, Fehler, Akku, Fläche (sqft → m²), Dauer, Behälter, Gesamtstunden und
    -reinigungen. Befehle auf „cmd": start/resume, pause, stop, dock, find.
  - Konto: Hersteller „iRobot", IP-Feld, Anleitung HOME-Taste, kein Passwortfeld (`koppeln`).
- Prüfstand: nachgestellter Roboter (`.tools/roomba-nachbau.php`) mit echtem UDP, TLS (selbst ausgestelltes
  Zertifikat) und MQTT; Koppeln, Abruf, Befehle, zweites Gerät mit anderem Zertifikat (abgelehnt, keine
  Anmeldedaten gesendet); 11 Mutationen gefangen. **Kein echter Roboter getestet.**

## 0.15.0 (13.10.2026)

- **Neuer Treiber Ecovacs** (`libs/RoboHubEcovacs.php`), neu geschrieben nach der Protokollbeschreibung von
  DeebotUniverse/client.py (GPL-3.0, kein Code übernommen):
  - Anmeldung: signierte private API (`user/login`, md5-Passwort), Auth-Code, `loginByItToken` am Portal
    des Kontinents. Gerätebestätigung (Code 1013) zweistufig: RSA-verschlüsselte E-Mail
    (`common/getConfig` → `user/sendEmailVerifyCode`), Code → `user/verifyDevice`.
  - Zugangsdaten nach Dietmars Entscheidung: md5-Hash im Bündel (Attribut), automatische Neuanmeldung
    vor Ablauf (7 Tage); Abmelden löscht ihn.
  - Geräte aus `GetDeviceList` + `GetGlobalDeviceList`; Befehle per HTTP `iot/devmanager.do` (JSON).
    Klassenlisten (XML / clean_V2 / Mäher) aus deebot_client/hardware abgeleitet.
  - Zustand (getCleanInfo[_V2], Laden, Akku, Fehler), Saugstufe, Verschleiß (brush/sideBrush/heap/unitCare/
    roundMop bzw. blade/lensBrush), Gesamtstatistik, Lautstärke; Räume über getCachedMapInfo → getMapSet →
    getMapSubSet (Raumtyp-Namen deutsch). Befehle Start/Fortsetzen, Pause, Stopp, Station, Orten (Ton),
    Räume (spotArea), Saugstufe, Lautstärke, Verschleiß zurücksetzen.
- Konto: Herstellerliste um Ecovacs (Land statt Region), zweistufige Anmeldung allgemein
  (`RoboHubFehler::$vorstufe`, Attribut `AnmeldeVorstufe`, `anmeldenMitCode`).
- `curl_close()` entfernt (seit PHP 8.0 wirkungslos, unter 8.5 veraltet).
- Prüfstand: Signatur gegen Python-Referenz, RSA mit eigenem Schlüsselpaar, Bestätigungsablauf,
  Neuanmeldung mit Hash; 19 Mutationen gefangen. Live geprüft (ohne Konto): signierte Abfrage
  `common/getConfig` von Ecovacs angenommen, 1024-Bit-Schlüssel geliefert. **Kein Gerät live getestet.**

## 0.14.1 (13.10.2026)

- Fix (Forum, Torsten, Dreame L10): Beim Neuanlegen eines Kontos meldete die Konsole „Konnte nicht zur
  Instanz verbinden — Instanz … hat bereits ein übergeordnetes Objekt (Code -32603)". Ursache: RoboHub
  legte in `ApplyChanges` sofort einen eigenen Client Socket an und verband ihn (Weg für Bestandskonten,
  bei denen `RequireParent` nichts anlegt); die Konsole wollte danach ihren eigenen einhängen. Jetzt
  übernimmt ein Timer (15 s, `ROBOHUB_VerbindungPruefen`) die Anlage nur, wenn dann noch keiner da
  ist; ein inzwischen überflüssiger eigener Socket wird wieder gelöscht.
- Hersteller ohne Konto-Echtzeit bekommen keinen Client Socket mehr.

## 0.14.0 (13.10.2026)

- **Neuer Treiber Worx/Kress/LandXcape/Ferrex** (`libs/RoboHubWorx.php`, nach ioBroker.worx, MIT):
  - Anmeldung OAuth „password" je Marke (eigener Login-Server und App-Client), danach nur der
    rotierende Erneuerungsschlüssel; Passwort wird verworfen.
  - Geräte und Zustand aus `/api/v2/product-items?status=1` (letzter vom Mäher gemeldeter Status):
    Statuscodes ls (Zu Hause + Laden = Lädt, Regenverzögerung = Wetterpause), Fehler le, Akku,
    Laden, Regensensor, Mähzeit gesamt.
  - Befehle Start 1 / Pause 2 / Stopp 2 / Zur Station 3 über MQTT an `mqtt_topics.command_in`:
    WebSocket `/mqtt` zum AWS-IoT-Endpunkt (Amazon Root CA 1 gepinnt), Zugangsschlüssel im
    Benutzernamen (wie pyworxcloud), QoS 1 mit Bestätigung. Nachricht wie ioBroker sendPing
    (Draht-Mäher sn/tm/dt, Vision-Mäher uuid/ISO-Zeit).
- **Gemeinsame MQTT-Sitzung** `libs/RoboHubMqttSitzung.php` (TLS immer geprüft, wahlweise WebSocket
  nach RFC 6455); Roborock nutzt sie jetzt auch. `RoboHubMqtt`: PUBLISH mit QoS 1, CONNECT ohne
  Passwort.
- Konto: zentrale Herstellerliste (Auswahl, Anzeige, Code-Anmeldung, Regionswahl); Region nur noch
  bei Dreame/MOVA; Echtzeit-Hinweis je Treiber (`echtzeitMoeglich`).
- Prüfstand: WebSocket-Werte aus RFC 6455, MQTT-Benutzername gegen Python `urllib.quote`, echte
  Treiberwahl je Hersteller; 18 Mutationen gefangen. Echt geprüft: WebSocket-Handschlag mit
  iot.eu-west-1.worxlandroid.com (101 + Accept), falsche Wurzel abgelehnt — ohne Anmeldung.
  **Nicht an einem echten Mäher getestet.**

## 0.13.0 (12.10.2026)

- **Neuer Treiber Roborock** (`libs/RoboHubRoborock.php`, Hersteller „roborock" im Konto), nach
  python-roborock (Apache-2.0):
  - Anmeldung per E-Mail-Code (getUrlByEmail → v4 Code → key/sign + Vertragsversion → login/code),
    kein Passwort; gespeichert wird nur das Nutzer-Bündel (rriot + token). Neuer Knopf
    `ROBOHUB_CodeAnfordern`, das Passwortfeld nimmt bei Roborock den Code.
  - Geräte und Raumnamen über die Web-API (Hawk-HMAC-SHA256), Gerätschlüssel bleiben im Konto
    (`GeraeteListe` gibt sie nicht heraus).
  - Zustand und Befehle über den Roborock-MQTT-Server: Rahmen „1.0" (AES-128-ECB mit
    md5(Zeitstempel kodiert + Gerätschlüssel + Salz), CRC32), Nutzlast mit Sicherheitsdaten, je Abruf
    eine kurze TLS-Sitzung, geprüft gegen DigiCert Global Root G2 (Name + Kette, nie abschaltbar).
  - Werte: Zustand, Fehler, Akku, Fläche, Dauer, Saugstufe/Wassermenge (Auswahl), Verbrauchsteile
    in % und Reststunden, Station (dss), Gesamtstatistik, Nicht stören, Lautstärke.
  - Befehle: Start (setzt pausierte Raum-/Zonenreinigung fort), Pause, Stopp, Station, Orten, Räume
    mit Saugstufe und Durchgängen (Rückfall auf einfache Segmentliste), Verbrauchsteile zurücksetzen.
  - Noch nicht: Kartenbild, Echtzeit über das Konto, Zeitpläne, Protokolle A01/B01.
- Kachel: Auswahl „Raum reinigen mit Wahl" aus den Treiber-Optionen statt fest verdrahteter
  Dreame-Werte (für Dreame unverändert).
- Prüfstand: Referenzwerte aus unabhängiger Nachbildung (Python-Standardbibliothek + openssl) und
  python-roborock-Tests (Endpunkt, Nutzlast), Antwortbeispiele aus python-roborock; 15 Mutationen
  gefangen. TLS-Aufbau aus PHP gegen mqtt-eu-3.roborock.com mit der eigenen Wurzel geprüft (falscher
  Name abgelehnt). **Nicht an einem echten Roborock getestet** (Dietmar hat keinen).

## 0.12.5 (12.10.2026)

- Store-Review (SUITE.md Checkliste 15, Hinweis der EMS-Sitzung): Protokoll über `$this->LogMessage` statt
  `IPS_LogMessage` (Konto und Roboter je eine Stelle). Fehler als `KL_WARNING`, sonst `KL_MESSAGE`.
  Prüfstand: kein `IPS_LogMessage` in Modulquellen, Stufe bei Rücksetzen/Fehlschlag geprüft (Mutationen gefangen).

## 0.12.4 (12.10.2026)

- Forum-Thread steht: Panel „Rückmeldungen" verweist jetzt auf
  [RoboHub im Symcon-Forum](https://community.symcon.de/t/beta-modul-robohub-dreame-saugroboter-und-mova-maehroboter-in-ip-symcon-mit-karte-live-weg-zeitplaenen-und-raumreinigung/144636), GitHub-Issues bleiben als zweiter Knopf.

## 0.12.3 (12.10.2026)

- Live-Befund LiDAX (16:18–16:25, Dietmar draußen, Rasen frei): Zone mähen (o 102, Zone 1) → „Mäht", Pause → „Pausiert",
  Stopp → „Bereit", Zur Station → „Fährt zur Station". **Erste Echtzeit-Meldungen vom Mäher** (2.1, 3.1, 2.50 TASK,
  2.56 Zonenstatus, 300.1) und **Position aus 1.4** auf der Karte — Lage von Dietmar bestätigt. Mähweg (M_PATH) blieb
  in den 7 Minuten leer.
- Fix: `HasActiveParent()` meldete kurz nach Modul-Updates mehrfach „nein", obwohl das Konto Status 102 hatte
  (Befehl ging dann nicht raus). Jetzt zusätzlich Status der übergeordneten Instanz selbst prüfen.

## 0.12.2 (12.10.2026)

- Live-Befund (Barny, Küche 16:07–16:14): Start mit Wahl, Live-Karte während der Reinigung, Pause/Fortsetzen/Stopp/
  Zur Station bestätigt. Nach der Rückkehr lieferte der letzte Rahmen keinen Weg mehr und überschrieb den fertigen
  Weg → jetzt bleibt bei leerem Weg außerhalb der Arbeit der bisherige stehen; bei neuer Arbeit wird er ersetzt.

## 0.12.1 (12.10.2026)

- Live 0.12.0: Symcons `MaintainVariable` benennt eine vorhandene Variable nicht um — Variable hieß weiter
  „Einstellungen je Raum". Jetzt gezielt `IPS_SetName`, nur wenn noch der alte Standardname gilt. Prüfstand
  bildet das Live-Verhalten nach (MaintainVariable ohne Umbenennung).
