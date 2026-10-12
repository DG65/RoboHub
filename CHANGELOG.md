# Changelog

Ältere Versionen: [CHANGELOG-Archiv.md](https://github.com/DG65/RoboHub/blob/main/CHANGELOG-Archiv.md)

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
