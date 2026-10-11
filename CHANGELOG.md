# Changelog

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
