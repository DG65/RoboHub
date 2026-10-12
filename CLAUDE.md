# RoboHub — Hinweise für die Arbeit an diesem Repository

## Einordnung

- **Eigenständiges DG65-Modul** (wie WarnHub), weder NRG-Stack noch DG65 Toolkit — Dietmars
  Entscheidung 11.10.2026. `library.json→name` = „RoboHub". Übernimmt trotzdem alle
  handwerklichen Verbund-Konventionen aus `/Users/dietmar/Nextcloud/Claude/SUITE.md`
  (Formular-Optik, Lizenz-Panel, Rückmeldungspflicht, Store-Checkliste, Stolpersteine).
- **Generischer Hub** (Dietmars Entscheidung 11.10.2026): herstellerneutrales Modell
  (`libs/RoboHubModell.php`), Hersteller als Treiber (`RoboHubTreiber`). Erster Treiber:
  Dreame + MOVA (`libs/RoboHubDreame.php`). Neue Hersteller = neuer Treiber + Eintrag im
  Hersteller-Select des Kontos, nichts an Variablen/Idents ändern.
- Drei Module: `RoboHubKonto` (Splitter, `ROBOHUB_`), `RoboHubGeraet` (Gerät, `ROBO_`),
  `RoboHubKonfigurator` (Konfigurator, `ROBOCFG_`, **seit 0.2.0 ohne Parent**: sammelt alle Konten
  über `ROBOHUB_GeraeteListe`; Roboter verbinden sich in `ordneKontoZu()` selbst mit dem Konto,
  das ihre Geräte-ID kennt — Dietmars Wunsch „ein Konfigurator für beide Roboter"). Datenfluss Kind → Konto über
  `SendDataToParent`/`ForwardData` (DataIDs in den module.json). Nur das Konto spricht mit
  der Cloud — ein Schlüssel je Konto, Erneuerung unter Semaphore.
- Klassen/Präfixe/GUIDs sind eingefroren, sobald eine Instanz existiert (TOOLKIT.md-Faustregel,
  gilt hier genauso).

## Dreame/MOVA-Cloud — belegte Fakten (Quellcode ioBroker.dreame 0.4.12 + Tasshack 2.0.1, 11.10.2026)

- Login `POST https://<region>.iot.dreame.tech:13267/dreame-auth/oauth/token` (MOVA:
  `iot.mova-tech.com`), Passwort `md5(pw . 'RAylYC%fmSKp7%Tq')`, Tenant/Basic/RLC-Schlüssel je
  Marke in `RoboHubDreame::MARKEN`. Refresh per `grant_type=refresh_token` ohne Passwort;
  **der Refresh-Token rotiert** — immer den neuen speichern. Laufzeit des Refresh-Tokens ist
  nirgends belegt.
- **Passwort wird nicht gespeichert** (Credential-Regel). Folge: Lehnt die Cloud den
  Refresh-Token ab, muss der Nutzer neu anmelden (Status 201). Bewusst so, nicht „verbessern",
  ohne mit Dietmar zu sprechen.
- Befehle/Abfragen: `POST /dreame-iot-com-<10000|20000>/device/sendCommand`, Methoden
  `get_properties`/`set_properties`/`action`. Code 80001/-8 = Gerät offline, kein Auth-Fehler.
- **r95475-Falle (Aqua10 Ultra Roller Complete; Dietmars Gerät meldet sich live als `dreame.vacuum.r9547a`, Gerät „Barny II", did 2066117758 — gleiche Familie):** liefert 2.1 (Zustand) und 3.1
  (Akku) nicht per Abfrage, nur per MQTT. Deshalb Rückfall auf `latestStatus`/`battery` aus
  `listV2` (wie ioBroker `_bridgeStatusSummary`). Echte Abfrage gewinnt, wenn vorhanden.
- Raumreinigung: `action 4.1`, `in [{piid:1,value:18},{piid:10,value:{"selects":[[seg,1,saug,wasser,1]]}}]`
  — **5. Feld fest 1** (sonst Abbruch nach dem ersten Raum bei Gen 5).
- Reinigungsmodus 4.23 ist gepackt (Bits 0–1), nur Read-Modify-Write; bei Mopp-Anhebung
  0↔2 getauscht. Erkennung hier heuristisch: 4.25 UND 27.3 geliefert (ioBroker-Heuristik
  „Waschstation + Absaugstation").
- Räume: 6.8 → `object_name` → `iotfile/getDownloadUrl` → Datei → `mapstr[].map` → Base64
  (URL-sicher) → ggf. AES-256-CBC (Schlüssel `substr(sha256(Suffix),0,32)`, IV bekannt nur
  für r2253, sonst 16 Nullbytes — **für r95475 unbelegt**) → zlib → 27-Byte-Kopf + Bild →
  JSON `seg_inf`. Scheitert die Dekodierung, gibt es einfach keine Raumvariable (Debug „Karte").
- Mäher: Klasse am Modellnamen (`mower`). **Dietmars Mäher: MOVA LiDAX Ultra 1000 =
  `mova.mower.g2529c`** (EvotecIT supported-mowers.md, „field-reported: MOVAhome EU login,
  commands, battery"). Dritte Quelle seit 0.1.1: EvotecIT/homeassistant-dreamelawnmower (MIT).
  - Zustand 2.1: Widerspruch ioBroker-Code (3 Mähen, 4 Pause, 15/16 Ortung) vs. App-Beschriftungen
    bei EvotecIT + ioBroker-README (3 Pause, 4 Pause wegen Fehler, 15/16 Laden pausiert). Gewählt:
    App-Beschriftungen. 7–12 (Fehler, Regenpause, Kantenmähen …) nur ioBroker, übernommen.
    **Live prüfen**, Rohwert steht in „Zustand (Hersteller)".
  - 2.2 ist ein Gerätecode (Fehler/Wartung/Info), EvotecIT-Register; LiDAX g2529c/f, g2584a:
    0 = angehoben, 55 = Start wegen Akku unmöglich. Infos → Variable „Meldung".
  - Befehle: EvotecIT Start 5.1/Stopp 5.2/Pause 5.4, ioBroker-Code 2.1/2.2/2.4 → 5.x zuerst,
    bei result.code ≠ 0 einmal 2.x. **Station 5.3** in beiden (3.1 wirkt nicht). Welcher Weg
    griff, steht im Debug „Befehl". Orten über 2.50 `o:9`, Messer über 2.50
    `{"m":"g","t":"CMS"}` (Max 6000 min). Mäh-Zonen seit 0.6.0 (siehe unten).
- Nicht gebaut, bewusst: MQTT-Push (Ausbaustufe; TLS, user=uid, pass=access_token),
  Tasshacks Body-Signatur/TLS-Fingerprint (ioBroker funktioniert ohne).

## Live-Stand (Dietmars Anlage, 11.10.2026)

- Konten: Dreame #42970, MOVA #50686 (Symcon „Privat"), beide angemeldet, Login + listV2 live ok.
- **Barny II #54764** (`dreame.vacuum.r9547a`, FW 4.3.9_3855): `ROBO_Aktualisieren` live ok, alle
  Blöcke beantwortet. **r9547a liefert 2.1/3.1 per Abfrage** (Rückfall nicht nötig, schadet nicht).
  4.23 = 5120 → Bits 0–1 = 0 → mit Mopp-Anhebungs-Tausch „Saugen und Wischen" (gegen App prüfen).
  3.2 = 1 (lädt) trotz 100 %/Zustand 13. **Raumnamen aus der Karte live dekodiert** (7 Räume,
  eigene Namen + Typ-Namen; „Küche 2" = Index 1 wie ioBroker, gegen App prüfen).
- LiDAX-Instanz #19587 war weg — sehr wahrscheinlich über den Papierkorb der roten Doppelzeile im
  Konfigurator gelöscht (Papierkorb löscht laut SDK-Doku die Instanz). Neu: **#11377** (0.2.0,
  ein Konfigurator #12732 für beide Konten, Selbstzuordnung → MOVA #50686 live korrekt).
- **LiDAX live (FW 4.3.6_0529):** 2.1 = 13 „Geladen", 3.1 = 100, **3.2 = 2** (in keiner Quelle
  belegt, vermutlich „voll"; → Lädt = false), Messer 34 % aus CMS. **2.2 kam nicht**, **Block 4.x
  (4.1/4.2/4.3/4.7) wird nicht beantwortet** → Abruf ~13 s. Seit 0.2.1 gebaut (Dietmars Auftrag): `BlockSperre`
  im Konto, 1 h, nur sperren, wenn ein anderer Block antwortete.
- „Orten" an Barny II live: Cloud angenommen und **Barny hat gepiept** (Dietmar bestätigt) — erster echter Befehl.
- **Raumreinigung live (Barny, Raum 1 „Vorrat")**: action 4.1 mit selects `[[1,1,saug,wasser,1]]` angenommen; danach 2.1 = 12 „Saugt und wischt", 4.1 = 18 (Raumreinigung), 4.7 = 3 (Raum). 11.10.2026 17:55, Dietmar vor Ort.
- **Reinigungsmodus-Tausch live bestätigt (Barny)**: Leitung 0 lief als „Saugt und wischt" (2.1 = 12); „Saugen" gesetzt → 4.23 5120 → 5122 (nur Bits 0–1 geändert, Feuchte erhalten), Gerät übernahm. Danach 2.1 = 9 „Wäscht Mopp".
- **Kompletter Auftrag live verfolgt (Barny, 11.10.2026 17:55–18:06)**: Start Raum 1 → 12 „Saugt und wischt" → Modus „Saugen" gesetzt → 9 „Wäscht Mopp" (Station 4.25 = 1, Auftrag 4.7 = 3 blieb aktiv) → nur gesaugt (Dietmar vor Ort bestätigt) → 5 „Fährt zur Station", 2 m²/5 min, Akku 93 %. Zustandswechsel vom 30-s-Takt selbst erkannt.
- **Start am LiDAX live (18:07)**: angenommen in 0,6 s mit einer Anfrage → Weg **5.1** (EvotecIT) greift, Ausweichweg 2.x nicht nötig. Danach 2.1 = **19** (in keiner Quelle) = mäht (Dietmar bestätigt) → seit 0.2.2 „Mäht". 3.2 = 2 = „lädt nicht" (Homey-App andiwirz bestätigt).
- **Zur Station am LiDAX live (18:13)**: 5.3 angenommen (0,6 s), danach 2.1 = 5 „Fährt zur Station", 3.1 = 91, 3.2 = 5 (bei Saugern „Rückkehr zum Laden"). Pause/Stopp am Mäher noch ungetestet.
- Barny 2.1 = 35 „Trocknet Staubbeutel" (Tasshack) **gegen die Dreamehome-App bestätigt** (20:23). LiDAX nach Rückkehr 2.1 = 6 „Lädt", 3.2 = 1.
- **Verbrauchsteile Barny gegen App exakt bestätigt** (20:41): Filter 12 % (11.1), Seitenbürste 34 % (10.2), Hauptbürste 56 % (9.2), Sensoren 0 % (16.1). App zeigt zusätzlich Reststunden (19/69/169/0 h) — RoboHub liest die Stunden-piids noch nicht.
- **Mäher-Verschleiß gegen MOVAhome-App bestätigt** (21:08): Klingen 34 % (33,7 h), Reinigungsbürste 87 % (433,7 h → Max 30000 min stimmt), Wartung 72 % (42,9 h → Max 3600 min stimmt). Seit 0.2.3 alle drei als Variable. Dietmar **hat eine Garage** (die App erkennt sie nur nicht; ihr „Garage erwerben" ist kein Beleg — meine Fehldeutung, von Dietmar korrigiert). Zustand 19 = „Mäht" bleibt trotzdem belegt: er mähte dabei nachweislich (Dietmar vor Ort). Ob Garagen-Ein-/Ausfahrt einen eigenen Zustand hat, ist offen.
- **Reststunden live (0.2.4, 21:24)**: Barny 169/69/19/0 h = App ✓, Mopp/Walze 90 %/99 h (18.1/18.2) = App „Mopp-Pad" ✓ (21:25). App zeigt zusätzlich „Abwasserauslassfilter des Roboters" 0 %/0 h (35.2/35.1, RoboHub liest 35.2 schon, ohne Variable) und „Schmutzwasserbehälter des Roboters" 0 %/0 h (ioBroker 26.x) — seit 0.2.5 gebaut (Dietmars Auftrag). **Offen:** Reihenfolge %/h bei 35 nur aus Tasshack, live beide 0 → nicht unterscheidbar. Live 0.2.5 (21:29): r9547a liefert 26.1/26.2/35.1/35.2 alle = 0 = App ✓, Block vollständig beantwortet. Nach Filterwechsel prüfen, ob „Abwasserauslassfilter" (35.2) auf 100 % springt; LiDAX 33,7/433,7/42,9 h = App ✓. Stundenblock bei r9547a vollständig beantwortet.
- Raumumbenennung in der App („Küche 2" → „Küche") per `ROBO_RaeumeLaden` sofort übernommen.
- „Orten" am LiDAX live: Kanal 2.50 `{"m":"a","p":0,"o":9}` funktioniert, **LiDAX hat gepiept** (Dietmar bestätigt). Start/Stopp/Pause (5.x vs. 2.x) noch ungetestet.
- 0.2.1 live gemessen am LiDAX: 1. Abruf 9,2 s (Block 4.x versucht), 2. Abruf **0,5 s** (Block übersprungen).
- **MC_DeleteModule/CreateModule für RoboHub hat Symcon ~5 min komplett neu starten lassen**
  (Port 3777 zu); Attribute/Anmeldungen blieben dabei erhalten. Nicht leichtfertig wiederholen.

## Kachel und IPSView (0.3.0)

- Kachel in `RoboHubGeraet` (`module.html`, `kachelDaten()`), Aktualisierung nach jedem Abruf,
  Raum-Laden und Befehl (`kachelAktualisieren()`, mit Rückmeldung). Kachel-Bedienung über
  `requestAction()` → `RequestAction()` (Befehl/Raum/Raeume).
- IPSView rendert keine HTML-Kacheln (Verbund-Befund Prognose/NRGDashboard) → WebHook-Seite.
  **Sicherheit, nicht aufweichen:** Seite ohne Anmeldung erreichbar → Befehle nur mit
  `WebBedienung` (Standard aus) UND Schlüssel (`hash_equals`, leerer Schlüssel nie gültig —
  Attribut kann nach MC-Neuladen leer sein), Positivliste Befehl/Raum/Raeume; ohne Freigabe nie
  den Schlüssel ausliefern. Prüfstand + 6 Sicherheits-Mutationen (alle gefangen).
- Live 0.3.0 (21:43): beide Hooks im WebHook-Control eingetragen, `/hook/robohub54764` und
  `/hook/robohub11377` liefern HTML + echte Daten (Nur-Anzeige, kein Schlüssel); Befehl ohne Freigabe
  und mit geratenem Schlüssel live abgelehnt („🔒 … nicht freigegeben"). Darstellung in Dietmars
  Kachel-Visualisierung und in IPSView noch nicht gesehen.

- **Kachel-Layout (0.3.1):** Symcon-Titelzeile (Instanzname + ⤢) liegt als Ebene über den
  oberen ~44 px → `body{padding-top:44px}` (nicht bei `.eigen`). Erste Fassung 0.3.0 überlappte
  (Dietmars Screenshot 21:47). **0.3.1 live in Dietmars Kachel-Visualisierung bestätigt (21:51, Screenshot):** keine Überlappung, zweispaltig, dunkler Modus korrekt. **0.3.2 live bestätigt (21:55):** gleiche Starthöhe, großes Symbol, Mäher-Teile offen, Stand unten. Ampelschwellen seit 0.3.3 an die Apps angelehnt: rot ≤ 5 %, gelb ≤ 35 % (`TEIL_ROT`/`TEIL_GELB` in module.html); exakte App-Grenze liegt bei MOVA zwischen 34 und 72 %, nicht weiter belegt. Vorschau-Generator mit nachgebildetem Titelstreifen + dunklem
  Modus lag im Scratchpad (`vorschau.py`), nicht im Repo.

- **Produktbild (0.3.4):** `RoboHubDreame::bildVon()` aus listV2 `deviceInfo.*.imageUrl`
  (öffentlich auf oss.iot.dreame.tech, ohne Anmeldung abrufbar, HTTP 200 geprüft), Attribut
  `Bild`, Kachel lädt direkt (Hotlink, keine Kopie — Bildrechte beim Hersteller). **Live bestätigt
  (22:07):** Dreame r9547a UND MOVA g2529c liefern `deviceInfo.mainImage/popup/icon/overlook.imageUrl`
  (MOVA-Pfad enthält `/000002/ali_dreame/null/`). Kachel zeigt beide Bilder (Screenshot 22:05).
  Meine Zwischen-These „mainImage fehlt" war falsch — die Daten stammten von vor dem Update.

## Echtzeit (MQTT, 0.4.0)

- Konto hat `parentRequirements` Client Socket (`{3CFF0FD9-…}`, Daten `{79827379-…}`/`{018EF6B5-…}`),
  `RequireParent` in `Create()`. Socket-Eigenschaften live an Dietmars System gelesen:
  Host/Port/Open/UseSSL/VerifyPeer/VerifyHost/CertificateAuthority/Certificate/PrivateKey/UseCertificate/Password.
  **Offen (live):** ob `CertificateAuthority` base64(PEM) erwartet (so umgesetzt) — sonst TLS-Fehler sichtbar.
- Private Wurzel-CA in `RoboHubDreame::MQTT_WURZEL` (gültig bis 2969). SAN deckt `*.mt.<region>.iot.dreame.tech`
  und `*.mt.<region>.iot.mova-tech.com`. Nie auf `VerifyPeer=false` zurückfallen, ohne mit Dietmar zu sprechen.
- Bytes über den Socket als UTF-8 (`mb_convert_encoding … ISO-8859-1`), Empfangspuffer base64 in `SetBuffer`.
- Push → `Schnappschuss` (letzter `get_properties`-Stand je did) + Delta → `normalisiere()` → `SendDataToChildren`.
- Prüfstand: 30 Echtzeit-Prüfungen, 7/8 Mutationen gefangen (8. gleichwertig).
- **Live bestätigt (11.10.2026, 0.4.2):** `RequireParent` wirkte bei Bestandskonten NICHT (auch nicht in
  ApplyChanges) → ausdrücklich `IPS_CreateInstance`/`IPS_SetName`/`IPS_ConnectInstance`. Danach beide Sockets
  Status 102, TLS mit `CertificateAuthority` = base64(PEM) und VerifyPeer/VerifyHost an funktioniert.
  Dreame-Server 10000.mt.eu.iot.dreame.tech:19973, MOVA 20000.mt.eu.iot.mova-tech.com:19974 (aus bindDomain).
  CONNACK angenommen, je 1 Gerät abonniert. Saugstufe in der App auf „Stark" und zurück → beide Änderungen
  per Echtzeit in Symcon (letzte um 23:01:02, Abruf 23:01:10 bestätigt). 7 Meldungen in 4 Minuten.

## Verbrauchsteile zurücksetzen (0.4.4)

- Sauger: `action` ohne Parameter auf den Teil-Service (Tasshack `ACTION_MAPPING`: 9.1, 10.1, 11.1,
  16.1, 18.1, 26.1, 35.1). Mäher: CMS über 2.50 lesen (`m:g`), einen Index auf 0, alles zurück
  (`m:s`, `d.value`) — ioBroker `resetIndex` 0/1/2. CMS-Werte sind **verbrauchte** Minuten (0 = 100 %).
- Doppelte Sicherung: Gerät prüft Teil in `TEILE` UND vorhandene Variable, Treiber prüft die
  Zuordnung je Klasse. Nicht rückgängig zu machen → Formular `confirm`, Kachel zweistufig, Hook nur
  mit Freigabe + Schlüssel. Jede Rücksetzung per `protokolliere()` (Vorher-Wert).
- **Live bestätigt (11.10.2026 23:20, Barny, Sensoren nach Reinigung durch Dietmar):** action 16.1
  angenommen, Abruf danach 16.1 = 100 %, 16.2 = 30 h = Dreamehome-App (Dietmar bestätigt), Dauer 2,7 s inkl. Abruf, Protokolleintrag da.
  Mäher-CMS-Weg und die übrigen Sauger-Teile noch nicht live. Weiter nur auf Dietmars ausdrückliches
  Wort und an einem Teil, das er gerade wirklich gewechselt/gereinigt hat.

## Nicht stören und Lautstärke (0.5.0)

- Sauger: 5.4 (JSON-Fensterliste `[{"id","en","st","et","wk","ss"}]`, Tasshack-Fähigkeit `dnd_task`) hat
  Vorrang vor 5.1/5.2/5.3; erstes Fenster nach Startzeit (Tasshack-Sortierung) ist „das" Nicht-stören.
  Lautstärke 7.1 (Tasshack spielt danach einen Ton — RoboHub bewusst nicht).
- Mäher: CFG über 2.50 (`DND` [an, min, min], `VOL`); eine zusätzliche Anfrage je Abruf.
- Schreiben immer Read-Modify-Write (nichts überschreiben, was nicht geändert werden soll).
- **Live gelesen (11.10.2026 23:32, 0.5.0):** Barny r9547a liefert **beides** — 5.1 = false, 5.2 = "22:00",
  5.3 = "08:00" UND 5.4 = `[{"id":0,"en":false,"st":"22:00","et":"08:00","wk":127,"ss":0}]` (id 0, nicht 1);
  RoboHub nimmt 5.4 (Tasshack-Vorrang). Lautstärke 7.1 = 59. Alle Blöcke beantwortet.
  LiDAX: CFG beantwortet — DND aus, 20:00–11:00, VOL 0. **Alle Lesewerte von Dietmar gegen beide Apps bestätigt.**
- **Lautstärke schreiben live bestätigt (Barny, 23:36):** 59 → 50 (set_properties 7.1, Abruf danach 7.1 = 50)
  → zurück 59 (7.1 = 59).
- **Nicht stören schreiben live bestätigt (Barny, 23:37–23:39):** Ende 08:00 → 07:30 per 5.4 (Fenster id 0,
  übrige Felder unverändert); Barny spiegelt selbst nach 5.3 = "07:30"; Dreamehome-App zeigte 22:00–07:30
  (Dietmar bestätigt); zurück auf 08:00 (5.3/5.4 = 08:00).
- **Mäher-Lautstärke schreiben live bestätigt (LiDAX, 23:40–23:41):** `{"m":"s","t":"VOL","d":{"value":10}}`
  angenommen, frisch gelesenes CFG danach VOL = 10; zurück auf 0 (CFG VOL = 0).
- **Mäher-DND schreiben live bestätigt (LiDAX, 23:42–23:43):** Ende 11:00 → 10:30 per
  `{"m":"s","t":"DND","d":{"value":0,"time":[1200,630]}}`, CFG danach aus/20:00–10:30, MOVAhome-App zeigte
  20:00–10:30 (Dietmar bestätigt); zurück auf 11:00. **Damit ist 0.5.0 an beiden Geräten vollständig live geprüft.**

## Mähzonen (0.6.0)

- Zonen: `getDeviceData {did}` → `MAP.0…n` + `MAP.info` zusammensetzen → JSON-Liste von Slots (Objekt oder
  JSON-Text) → erster Slot → `mowingAreas.value [[id,{name,area,…}]]`. ID ≤ 0 übergangen, leerer Name → „Zone n".
  Welche Karte aktiv ist, ist unbelegt (ioBroker kennt keinen Zeiger) → erster Slot.
- Start: 2.50 `{"m":"a","p":0,"o":102,"d":{"region":[…]}}` — nur aus ioBroker (reverse-engineered aus dem
  App-Plugin), **live noch nicht geprüft**. Bewegungsbefehl → nur mit Dietmars Freigabe (Rasen frei, er vor Ort).
- Variable/Attribut wie bei den Sauger-Räumen (`Raum`, `Raeume`), Kachel-Text „mähen" statt „reinigen".
- **Live gelesen (11.10.2026 23:55, LiDAX):** getDeviceData liefert die Karte im erwarteten Format, 1 Zone:
  ID 1 „Rasen" → Variable „Zone mähen" angelegt; MOVAhome kennt ebenfalls nur „Rasen" (Dietmar bestätigt). Zonen-Start (o 102) noch nicht live.

## Kartenbild (0.7.0)

- `libs/RoboHubKartenbild.php`: Sauger-PNG (eigener PNG-Schreiber, Palette + tRNS, gzcompress) und Mäher-SVG.
- Sauger-Kodierung nach Tasshack map.py (Rahmen „I"): `standard` (Raum Bits 0–5, Bit 7 Wand/Grenze),
  `v3` (Raum Bits 0–4, 31 = Boden, Bits 5–6 Wand, 3 = nicht zeichnen), `rahmen` bei `fsm`=1 (Raum Bits 2–7).
  Auswahl über Abgleich mit `seg_inf` — Tasshacks echte Auswahl hängt an Kartenversion/`ris`, die RoboHub
  nicht nachbildet. Darstellung gespiegelt (Tasshack render: `pixel_type[x, height - y - 1]`).
- Kartentext: `thb` vor `map` (seit 0.1.x so) — falls Barnys Bild grob/klein ist, liegt es daran.
- Attribut `Karte` (JSON), Kachel-Feld `karte`; leeres Bild überschreibt kein vorhandenes.
- Trennlinien (0.7.1): Palette 3 weiß, nur Raum↔anderer Raum (rechts/unten), letzte Teilspalte/-zeile eines
  Kartenpunkts; Vergrößerung mindestens 2.
  Live (12.10.2026 00:17, Barny): PNG gültig 538×400 (Vergrößerung 2), 377 Linienpunkte — die meisten Räume
  der gespeicherten Karte haben ohnehin schon eine Lücke dazwischen, Linien entstehen nur, wo sie direkt
  aneinanderstoßen (z. B. Wohnzimmer/Esszimmer). Hinweis: Bild nie per Hand aus dem Protokoll abschreiben
  (Prüfsumme kaputt) — in Symcon selbst prüfen. Dietmar: „Linien passen so" (Stärke so lassen).
- Vorschau-Generator im Scratchpad (`vorschau-karte.py` + `karte-demo.php`), nicht im Repo.
- **Live bestätigt (12.10.2026 00:08, 0.7.0):** Barny r9547a → PNG 269×200 Punkte (Vergrößerung 1), 7 Räume
  farbig + Namen + Station; gespeicherte Karte enthält kaum Wandpunkte (nur Hindernisse), Räume grenzen direkt
  aneinander. LiDAX → SVG, Zone „Rasen" als L-Form (~7 × 14 m). **Beide von Dietmar gegen Dreamehome/MOVAhome
  bestätigt (Ausrichtung, Form, Raumlage).**

## Live-Karte (0.8.0)

- Sauger: action 6.1 mit 6.2 (`frame_type` I) → 6.3 Zwischendatei / 6.1 Text / 6.13 alt; Zwischendatei über
  dasselbe `getDownloadUrl` wie die gespeicherte Karte (Tasshack-Strings 18/34/48 = dreame-user-iot/iotfile/
  getDownloadUrl, Parameter filename). Schlüssel hinter dem Komma im Dateinamen → sha256 wie gespeicherte Karte.
- Weg „tr": Tasshack-Regex `[MWSLl](-?\d+),(-?\d+)`; Kartenwinkel 0° = +x, Kachel dreht um 90° − Winkel
  (Richtung beim Sauger live bestätigt, Mäher offen).
- Mäher: M_PATH ×10; Telemetrie 1.4 nach ioBroker (Bitlage siehe `maeherPosition`). **Noch nie eine Mäher-
  Echtzeit-Meldung gesehen** — ob 1.4 überhaupt über die Cloud kommt, ist offen.
- Kachel-Feld `karte` bekommt `weg`, `roboter`, `live` (Zeitstempel); `geometrie` bleibt im Attribut.
- Takt `LiveKarte` getrennt vom Abruf-Takt (der mit Echtzeit 120 s bleibt).
- Prüfstand: 19/19 Mutationen gefangen.
- **Live (12.10.2026 00:35, Barny in der Station, 0.8.0):** Anforderung beantwortet, Bild 374×394, Weg der letzten
  Reinigung 138 Punkte, Roboter = Station. **Live-Rahmen ohne `seg_inf`** (Raumnummern nur in den Bildpunkten)
  → seit 0.8.1 Namen aus dem Attribut `Raeume` (Gerät → Konto → Treiber `$geraet['raeume']`).
  0.8.1 live (00:37): 7 Räume farbig + Namen (Palette 11), Weg 138 Punkte, Roboter auf Station, Winkel 269.
  Pfeilrichtung bestätigt (Dietmar: Barny schaut in der Station in den Raum, Pfeil passt; Formel 90° − Winkel stimmt).
  Offen: Verhalten während einer echten Reinigung, Mäher (Weg, Position, Pfeilrichtung).

## Zeitpläne (0.9.0)

- Sauger 8.2, eigener Block am Ende. Lesen: `RoboHubDreame::zeitplaeneLesen()` (Tasshack + ioBroker
  lib/schedule.js, dort von TA2k gegen Live-Messpunkte geprüft). Schreiben nur Feld 1 (an/aus) per
  Read-Modify-Write — Tasshack schreibt 8.2 gar nicht (nur Löschen über Aktion), ioBroker schaltet so.
- Raum-Termine: Raumnamen aus dem Attribut `Raeume` (gleiche Segment-IDs wie die Karte).
- Mäher: `SCHEDULE.0…n` aus getDeviceData nur roh (Attribut `ZeitplanRoh`, „📋 Rohdaten"); Auswertung nach
  Live-Befund.
- **Live bestätigt (12.10.2026 00:46–00:50, 0.9.0):** Barny 8.2 = `1-1-09:00-0101010-1-0-16-297795601-0` →
  „09:00 · Mo, Mi, Fr · alle Räume · Saugen" = Dreamehome-App (Dietmar). Aus → 8.2 Feld 1 = 0, Ein → 1, beide
  vom Gerät zurückgemeldet. Termin wieder aktiv.
- Mäher (0.9.1): `SCHEDULE` aus getDeviceData ist beim LiDAX leer, obwohl ein (deaktivierter) Plan existiert →
  Lesen nach EvotecIT über 2.50 `SCHDIV`/`SCHDDV` (siehe CHANGELOG). Nur Anzeige; `SCHDSV<n>`
  {"i": Platz, "v": Version, "s": [1/0 je Plan]} zum Schalten wäre der nächste Schritt (EvotecIT verlangt
  dafür einen ruhenden Mäher ohne offene Aufgabe). **Live 0.9.2:** LiDAX antwortet auf SCHDIV2/3 an allen Plätzen
  mit `{"m":"r","r":3}` → seit 0.9.3 zuerst Tabellenform SCHDI/SCHDC (EvotecIT: für MOVA bevorzugt).
  **Live 0.9.3 gelesen:** ein Plan (aus) mit Termin täglich 12:00 ganze Fläche + drei leere Platzhalter-Tabellen
  (Feld 2 = 1, Feld 5 = 7, Bedeutung unbekannt). 0.9.4 zeigt „⏸️ Plan 1 · täglich 12:00 · alles" = MOVAhome (Dietmar
  bestätigt 12.10.2026 01:05). Schalten seit 0.9.5 (SCHDS): **live bestätigt 12.10.2026 10:32–10:33** — Plan 1 ein
  (Mäher bestätigt, Zustand „Lädt (6)"), sofort wieder aus (bestätigt); Plan ist wieder aus. App-Anzeige des
  Zwischenstands nicht abgewartet (12:00-Termin zu nah). Früher Hinweis „Testidee";
  Testidee: deaktivierten Plan kurz ein- und wieder aus, weit weg von 12:00.

## Prüfen

```
php .tools/test-robohub.php   # 0 = alles bestanden (läuft auch in der CI)
```

Der Prüfstand simuliert IPSModule und die Cloud (`FakeCloud` ersetzt nur `http()`).
Mutationsprobe 11.10.2026: 10/10 gezielt eingebaute Fehler gefangen. Live-Test bleibt
Pflicht: Dietmars Aqua10 Ultra Roller Complete (Dreamehome) und MOVA-Mäher (MOVAhome).

## Branch-Modell

**`main` und `beta` laufen gleich** (Dietmars Entscheidung 11.10.2026: „Du kannst alles auch
nach main pushen") — jeder Push geht auf beide, aber nur bei grünem Prüfstand:
`php .tools/test-robohub.php >/dev/null && git push origin beta && git push origin beta:main`
(nie mit `;` verketten). `LICENSE_URL` zeigt auf `main` (PolyForm von Anfang an).
