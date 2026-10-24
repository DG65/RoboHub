# RoboHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul-0.19.1-informational)
![Symcon Version](https://img.shields.io/badge/Symcon-9.0%2B-informational)
![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-orange)
[![Check Style](https://github.com/DG65/RoboHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/RoboHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Spenden-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Saug-, Wisch- und Mähroboter in IP-Symcon — herstellerneutral aufgebaut, heute mit
**Dreame** und **MOVA** (Apps „Dreamehome" und „MOVAhome"), **Roborock** (App „Roborock"),
**Ecovacs** Deebot und GOAT (App „Ecovacs Home"), **iRobot** Roomba/Braava (lokal, ohne Cloud), **Husqvarna Automower** und **GARDENA smart system** (jeweils offizielle API) sowie
den Mährobotern von **Worx** (Landroid), **Kress**, **LandXcape** und **Ferrex** — jeweils über die
Hersteller-Cloud.

## Was tut dieses Modul?

RoboHub holt Zustand, Akku, Reinigungs- bzw. Mähfortschritt und den Verschleiß der
Verbrauchsteile deiner Roboter nach Symcon und nimmt Befehle an: starten, anhalten,
stoppen, zur Station schicken, orten, einzelne Räume reinigen, Saugstufe/Wassermenge/
Reinigungsmodus wählen. Damit werden Roboter Teil deiner Automationen, z. B. „saugen,
wenn alle das Haus verlassen haben" oder „Mähen bei Regen abbrechen".

Alle Roboter bekommen dieselben Variablen und Befehle, egal von welchem Hersteller —
weitere Hersteller kommen als zusätzlicher Treiber dazu, ohne dass sich an Variablen,
Skripten oder Visualisierung etwas ändert.

## Aufbau

| Instanz | Aufgabe |
|---|---|
| **RoboHub Konto** | Anmeldung am Hersteller-Konto, verwaltet den Zugangsschlüssel, vermittelt alle Anfragen. Eine Instanz je Konto (z. B. eine fürs Dreamehome-Konto, eine fürs MOVAhome-Konto). |
| **RoboHub Konfigurator** | Eine Liste für die Roboter **aller** Konten; legt sie mit einem Klick an. Jeder Roboter verbindet sich danach selbst mit dem Konto, in dem er registriert ist. |
| **RoboHub Roboter** | Eine Instanz je Roboter: Variablen, Befehle, Raumreinigung. |

## Einrichtung

1. Modul über die Modulverwaltung hinzufügen: `https://github.com/DG65/RoboHub`
2. Instanz **RoboHub Konto** anlegen, Hersteller und Region wählen (Deutschland/Österreich/
   Schweiz: „Europa"), E-Mail und Passwort des App-Kontos eintragen, „Übernehmen", dann
   „🔑 Jetzt anmelden".
3. Für jedes weitere Hersteller-Konto (z. B. MOVAhome für den Mäher) eine weitere
   Konto-Instanz anlegen und anmelden.
   **Roborock** meldet sich ohne Passwort an: Hersteller „Roborock" wählen, E-Mail eintragen,
   „Übernehmen", „📧 Code anfordern", den Code aus der E-Mail ins Feld eintragen, „Übernehmen",
   „🔑 Jetzt anmelden". Die Region findet Roborock selbst.
   **Worx, Kress, LandXcape, Ferrex**: Marke wählen, E-Mail und Passwort der App eintragen,
   „Übernehmen", „🔑 Jetzt anmelden" — gespeichert bleibt nur der Erneuerungsschlüssel.
   **Ecovacs**: Land des Kontos wählen, E-Mail und Passwort eintragen, anmelden. Verlangt Ecovacs eine
   Bestätigung, „📧 Code anfordern", den Code aus der E-Mail ins Passwortfeld, „Übernehmen", erneut
   anmelden.
   **iRobot** (lokal): Hersteller „iRobot" wählen, IP-Adresse des Roboters eintragen, „Übernehmen". Roboter
   steht auf der Station: einmal „🔑 Jetzt anmelden" (RoboHub sucht den Roboter), dann HOME-Taste etwa
   2 Sekunden halten, bis Töne kommen, und sofort noch einmal „🔑 Jetzt anmelden". Für weitere Roboter
   dasselbe mit deren IP — sie landen im selben Konto.
   **Husqvarna**: Auf [developer.husqvarnagroup.cloud](https://developer.husqvarnagroup.cloud/) mit dem
   Husqvarna-Konto anmelden, eine Anwendung anlegen (Redirect-URL beliebig, z. B. `http://localhost`),
   „Authentication API" und „Automower Connect API" verbinden. Application Key ins Feld „Application Key",
   Application Secret ins Feld darunter, anmelden.
   **GARDENA**: genauso, nur „GARDENA smart system API" statt „Automower Connect API" mit der Anwendung
   verbinden (eine Anwendung kann beide haben).
4. **Einen** RoboHub Konfigurator anlegen — er zeigt die Roboter aller Konten. Roboter
   auswählen, „Erstellen". Achtung: Der Papierkorb hinter einer Zeile löscht die Instanz.

## Variablen

Eine Variable entsteht erst, wenn dein Modell den Wert tatsächlich liefert.

- **Zustand** (herstellerneutral): Bereit, Reinigt/Mäht, Pausiert, Fährt zur Station,
  Lädt, In der Station, Station arbeitet, Fehler, Ruht, Kartiert, Aktualisiert Firmware,
  Wetterpause (Mäher). Dazu **Zustand (Hersteller)** mit dem Originalwert im Klartext.
- **Befehl**: Start/Fortsetzen, Pause, Stopp, Zur Station, Orten.
- **Raum reinigen** (Sauger) bzw. **Zone mähen** (Mäher): Auswahl der Räume bzw. Mähzonen aus der
  gespeicherten Karte, auch mehrere auf einmal.
- Fehler, Akku, Lädt, gereinigte Fläche, Reinigungsdauer, Verbrauchsteile (Hauptbürste,
  Seitenbürste, Filter, Mopp/Walze, Sensoren, beim Mäher Messer), Station, Frisch-/
  Schmutzwasser, Staubbeutel, Reinigungsmittel, Gesamtstatistik.
- Saugstufe, Wassermenge, Reinigungsmodus: direkt schaltbar.
- **Kartenbild** auf Kachel und IPSView-Seite: Räume bzw. Mähzonen mit Namen zum Antippen.
- **Raum reinigen mit Wahl** wie das „benutzerdefiniert"-Fenster der App: Modus, Saugstufe und
  Durchgänge nur für diesen Start.
- **Zeitpläne** (Saug- und Mähroboter) aus der Hersteller-App anzeigen und ein-/ausschalten.
- **Live-Karte** während der Arbeit: gefahrener Weg und Roboterposition, fertiger Weg bleibt danach stehen.
- **Nicht stören** (an/aus, Beginn, Ende) und **Lautstärke**: direkt schaltbar, bei Saug- und
  Mährobotern.
- **Verbrauchsteile zurücksetzen** nach Wechsel oder Reinigung — im Formular (mit
  Sicherheitsabfrage) und auf der Kachel (↺, dann bestätigen). Jede Rücksetzung steht im
  Symcon-Protokoll.

## Echtzeit-Meldungen

Jede Konto-Instanz hält (abschaltbar, Panel „⚡ Echtzeit-Meldungen") eine verschlüsselte
MQTT-Verbindung zum Server des Herstellers. Ändert sich etwas am Roboter — Zustand, Akku,
Fehler, Station —, steht es sofort in Symcon und auf der Kachel. Die Verbindung läuft über
eine automatisch angelegte Client-Socket-Instanz; das Server-Zertifikat wird gegen die
Zertifizierungsstelle des Herstellers geprüft (Zertifikat und Servername), fremde Server
werden abgelehnt. **Symcon 9.1 (Rust):** Dort lehnt die neue TLS-Prüfung die Zertifikate des
Dreame/MOVA-Echtzeitservers ab (sie gelten ab 31.12.1969, unterstützt wird nichts vor 1970). RoboHub
schließt die Verbindung dann selbst und fragt nur ab; einmal am Tag versucht es die Echtzeit erneut.
Die regelmäßige Abfrage bleibt als Sicherheitsnetz, die schnelle
30-Sekunden-Abfrage während der Arbeit entfällt, solange Meldungen ankommen.

## Kachel und IPSView

**Kachel-Visualisierung:** Jede Roboter-Instanz ist selbst eine Kachel — Zustand mit
Symbol, Akku, Knöpfe für Start/Pause/Station/Orten, Räume zum Antippen („Vorrat, Küche
reinigen"), Modus und Saugstufe sowie die Verbrauchsteile, die fällig sind, zuerst. Die
Bedienung nutzt die Anmeldung der Visualisierung. Über den Doppelpfeil kommen wie
gewohnt alle Variablen.

**IPSView und Browser:** IPSView kann Symcon-Kacheln nicht darstellen. Deshalb liefert
jeder Roboter dieselbe Ansicht zusätzlich als eigenständige Seite:
`http://<Symcon-IP>:3777/hook/robohub<InstanzID>` — in IPSView als WebView-Element
einbinden; die Seite aktualisiert sich alle 15 Sekunden.

Diese Seite ist ohne Symcon-Anmeldung erreichbar und deshalb standardmäßig **nur
Anzeige**. Bedienen lässt sie sich erst, wenn du es im Formular („📱 Kachel und
IPSView") erlaubst — und dann nur mit dem geheimen Schlüssel in der Adresse
(`…?key=…`, im Formular angezeigt, jederzeit neu erzeugbar). Alternativ bindest du in
IPSView die Variablen „Befehl" und „Raum reinigen" direkt; dann gilt die
IPSView-Anmeldung.

## Skripte

```php
ROBO_Starten($id);              // alle liefern einen Klartext zurück
ROBO_Anhalten($id);
ROBO_Stoppen($id);
ROBO_ZurStation($id);
ROBO_Orten($id);
ROBO_RaeumeReinigen($id, "3,5"); // Raum-IDs zeigt das Formular
ROBO_RaeumeReinigenMit($id, "3", 0, 0, 1); // Modus, Saugstufe, Durchgänge nur für diesen Start, -1 = wie eingestellt
ROBO_LiveKarteLaden($id);
ROBO_VerbrauchsteilZuruecksetzen($id, "Filter"); // nach Wechsel/Reinigung, Ident der %-Variable
ROBO_Aktualisieren($id);
IPS_RequestAction($id, 'Befehl', 1); // 1 Start, 2 Pause, 3 Stopp, 4 Station, 5 Orten
```

Ein Befehl heißt zunächst nur „gesendet" — ob der Roboter ihn ausführt, zeigt die
Variable „Zustand" wenige Sekunden später.

## Sicherheit und Datenschutz

Das Passwort wird nur für die Erstanmeldung verwendet und danach verworfen; gespeichert
bleibt der Zugangsschlüssel. **Ausnahme Ecovacs:** Ecovacs kennt keinen Erneuerungsschlüssel, der
Zugang läuft nach 7 Tagen ab und lässt sich nur mit dem Passwort erneuern. RoboHub behält deshalb
einen md5-Hash des Passworts (nicht das Passwort selbst) im Konto, um sich selbst neu anzumelden;
„Abmelden" löscht ihn. **iRobot** arbeitet lokal: Das Gerätepasswort des Roboters bleibt im Konto, und
weil jeder Roboter ein selbst ausgestelltes Zertifikat hat, merkt sich RoboHub beim Koppeln dessen
Fingerabdruck und lehnt danach jede Verbindung mit einem anderen Zertifikat ab. **Husqvarna:**
Application Key und Secret bleiben im Konto, weil die offizielle Schnittstelle den Zugang (24 h) nur
damit erneuert; du kannst sie im Entwicklerportal jederzeit widerrufen. Er steht nicht im Formular und nicht im Protokoll — Symcon
verschlüsselt gespeicherte Werte aber grundsätzlich nicht, er liegt also im Klartext in
der Symcon-Datenbank, wie bei jedem Cloud-Modul.

RoboHub spricht mit der Hersteller-Cloud über dieselbe Schnittstelle wie die App. Sie ist
nicht offiziell dokumentiert und kann sich ändern.

## Grenzen

- Live geprüft mit Dreame Aqua10 Ultra Roller Complete und MOVA LiDAX Ultra 1000. Andere
  Dreame-/MOVA-Modelle derselben Apps sollten funktionieren, sind aber ungeprüft —
  Rückmeldungen sind willkommen.
- **Roborock** (seit 0.13.0): nach dem Protokoll von python-roborock gebaut und gegen dessen
  Testdaten geprüft, aber noch an keinem echten Gerät. Unterstützt sind Geräte mit dem
  Protokoll „1.0" (S-, Q-, Qrevo-, Saros-Reihen); neuere Geräte mit „A01"/„B01" meldet RoboHub als
  „noch nicht unterstützt". Noch ohne Kartenbild, Echtzeit-Meldungen und Zeitpläne.
- **Worx, Kress, LandXcape, Ferrex** (seit 0.14.0): nach ioBroker.worx gebaut, an keinem echten
  Gerät geprüft. Zustand kommt aus dem letzten Status, den der Mäher an die Cloud meldet; Befehle
  gehen über MQTT (WebSocket). Noch ohne Zonen, Zeitpläne, Regenverzögerung, Echtzeit-Meldungen
  und Messer-Rücksetzen; „Orten" gibt es bei diesen Mähern nicht.
- **Ecovacs** (seit 0.15.0): Deebot-Sauger und GOAT-Mäher mit JSON-Protokoll, an keinem echten Gerät
  geprüft (die signierte Anfrage an die Ecovacs-Anmeldung ist live bestätigt). Ältere Modelle mit
  XML-Protokoll meldet RoboHub als „noch nicht unterstützt". Raumnamen nur bei Modellen mit
  unkomprimierter Raumliste; noch ohne Kartenbild, Echtzeit und Zeitpläne.
- **iRobot** (seit 0.16.0): lokal nach roombapy gebaut; Abruf und Befehle an zwei Roomba 980 im Forum
  bestätigt (Oktober 2026). Seit 0.19.0 Reinigungseinstellungen wie in der iRobot-App (Saugleistung,
  Durchgänge, Kanten-Reinigung, Verhalten bei vollem Behälter) und eine Positionskarte mit dem Weg der
  laufenden Reinigung — beides abschaltbar. Modelle, die ihr Passwort nur über die iRobot-Cloud
  herausgeben, gehen (noch) nicht. Ein Roboter erlaubt nur eine lokale Verbindung gleichzeitig — läuft
  daneben z. B. Home Assistant lokal, stören sich beide. Ältere Firmware (z. B. Roomba 980 mit 2.x), die
  OpenSSL 3 ablehnt, spricht RoboHub seit 0.18.6 über einen eigenen TLS-1.2-Weg an. Ohne Räume (Namen nur in der Cloud),
  Verschleißteile, Grundriss und Echtzeit.
- **Husqvarna Automower** (seit 0.17.0): offizielle Automower Connect API nach aioautomower, an keinem
  echten Mäher geprüft (Token-Adresse live bestätigt). Daten höchstens 5 Minuten alt (Limit 10.000
  Abfragen/Monat), „Start" mäht 3 Stunden bzw. setzt nach einer Pause den Zeitplan fort. Noch ohne
  Echtzeit (WebSocket), Zeitpläne, Schnitthöhe, Messer-Rücksetzen.
- **GARDENA smart system** (seit 0.18.0): Mähroboter über die offizielle Schnittstelle nach py-smart-gardena,
  an keinem echten Mäher geprüft. Daten höchstens 15 Minuten alt (enges Abfragelimit). „Pause" gibt es
  bei GARDENA nicht. Ventile, Sensoren und Steckdosen bindet RoboHub nicht ein.
- **Segway Navimow**: zurückgestellt — der einzige Zugang ist das Partner-Login von Home Assistant.
- Zeitpläne lassen sich ein- und ausschalten, aber nicht neu anlegen oder in der Uhrzeit ändern.
- Die im Roboter gespeicherten Raum-Einstellungen werden nur angezeigt; abweichende Werte je
  Start gibt es über „Raum reinigen mit Wahl".
- Mäher: der gefahrene Mähweg auf der Live-Karte ist noch nicht bestätigt, die Position schon.
- Andere Regionen als „Europa" sind ungeprüft.

## Danksagung

Die Cloud-Schnittstelle von Dreame/MOVA ist aus drei quelloffenen Projekten (MIT-Lizenz) nachvollzogen:
[TA2k/ioBroker.dreame](https://github.com/TA2k/ioBroker.dreame),
[Tasshack/dreame-vacuum](https://github.com/Tasshack/dreame-vacuum) und
[EvotecIT/homeassistant-dreamelawnmower](https://github.com/EvotecIT/homeassistant-dreamelawnmower).
Der Roborock-Treiber folgt [Python-roborock/python-roborock](https://github.com/Python-roborock/python-roborock)
(Apache-2.0); dessen Antwortbeispiele liegen unverändert im Prüfstand (`.tools/roborock/`).
Der Worx-/Kress-/LandXcape-/Ferrex-Treiber folgt
[iobroker-community-adapters/ioBroker.worx](https://github.com/iobroker-community-adapters/ioBroker.worx) (MIT);
die Anmeldung am Befehlsserver ist mit [MTrab/pyworxcloud](https://github.com/MTrab/pyworxcloud) abgeglichen
(GPL-3.0, nur als Protokollbeschreibung genutzt, kein Code übernommen).
Der Ecovacs-Treiber ist nach der Protokollbeschreibung von
[DeebotUniverse/client.py](https://github.com/DeebotUniverse/client.py) neu geschrieben (GPL-3.0, kein Code
übernommen; die Liste der Geräteklassen ist daraus abgeleitet).
Der iRobot-Treiber folgt [pschmitt/roombapy](https://github.com/pschmitt/roombapy) (MIT), der
Husqvarna-Treiber [Thomas55555/aioautomower](https://github.com/Thomas55555/aioautomower) (MIT), der
GARDENA-Treiber [py-smart-gardena](https://github.com/py-smart-gardena/py-smart-gardena) (MIT).

## Rückmeldungen

Fragen, Erfahrungen mit weiteren Modellen und Wünsche gern in den
[RoboHub-Thread im Symcon-Forum](https://community.symcon.de/t/beta-modul-robohub-dreame-saugroboter-und-mova-maehroboter-in-ip-symcon-mit-karte-live-weg-zeitplaenen-und-raumreinigung/144636),
Fehler auch als [GitHub-Issue](https://github.com/DG65/RoboHub/issues).

## Lizenz

PolyForm Noncommercial 1.0.0 — private und nicht-kommerzielle Nutzung ist frei, gewerbliche
Nutzung erfordert eine gesonderte Lizenz vom Rechteinhaber (DG65). Vollständiger Text:
[LICENSE](LICENSE). Spenden sind willkommen:
[paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).
