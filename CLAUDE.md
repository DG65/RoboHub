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
    `{"m":"g","t":"CMS"}` (Max 6000 min). Mäh-Zonen noch nicht gebaut.
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
- 0.2.1 live gemessen am LiDAX: 1. Abruf 9,2 s (Block 4.x versucht), 2. Abruf **0,5 s** (Block übersprungen).
- **MC_DeleteModule/CreateModule für RoboHub hat Symcon ~5 min komplett neu starten lassen**
  (Port 3777 zu); Attribute/Anmeldungen blieben dabei erhalten. Nicht leichtfertig wiederholen.

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
