# Changelog

Ältere Versionen: [CHANGELOG-Archiv.md](https://github.com/DG65/RoboHub/blob/main/CHANGELOG-Archiv.md)

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

## 0.12.0 (12.10.2026)

- Klärung (Dietmars Bildschirmfoto): die „Raum-Einstellungen" der Dreamehome-App sind das Startfenster
  „benutzerdefiniert" — Modus und Saugleistung werden erst mit „Jetzt reinigen" geschickt, nirgends vorher
  gespeichert. Das erklärt alle Befunde 0.11.x.
- Neu: **Start mit Wahl** — `ROBO_RaeumeReinigenMit($id, "3,5", Modus, Saugstufe, Durchgänge)` (−1 = wie
  eingestellt); Modus vorher als Reinigungsmodus (4.23, Read-Modify-Write mit Mopp-Tausch), Saugstufe und
  Durchgänge in `selects`. Kachel: nach der Raumwahl Auswahlfelder, Wert an RequestAction/Seite als
  „IDs|Modus|Saugstufe|Durchgänge".
- Die im Roboter gespeicherten Raumwerte (`cleanset`) nur noch als Anzeige „Raum-Einstellungen im Roboter";
  `ROBO_RaumEinstellungSetzen` gesperrt (Treiber-Weg 6.2 bleibt, getestet, aus der Instanz nicht erreichbar).
- Noch nicht live geprüft (braucht eine echte Raumreinigung).

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
