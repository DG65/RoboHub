# RoboHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul-0.14.1-informational)
![Symcon Version](https://img.shields.io/badge/Symcon-9.0%2B-informational)
![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-orange)
[![Check Style](https://github.com/DG65/RoboHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/RoboHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Spenden-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Saug-, Wisch- und Mähroboter in IP-Symcon — herstellerneutral aufgebaut, heute mit
**Dreame** und **MOVA** (Apps „Dreamehome" und „MOVAhome"), **Roborock** (App „Roborock") sowie
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
werden abgelehnt. Die regelmäßige Abfrage bleibt als Sicherheitsnetz, die schnelle
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
bleibt der Zugangsschlüssel. Er steht nicht im Formular und nicht im Protokoll — Symcon
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

## Rückmeldungen

Fragen, Erfahrungen mit weiteren Modellen und Wünsche gern in den
[RoboHub-Thread im Symcon-Forum](https://community.symcon.de/t/beta-modul-robohub-dreame-saugroboter-und-mova-maehroboter-in-ip-symcon-mit-karte-live-weg-zeitplaenen-und-raumreinigung/144636),
Fehler auch als [GitHub-Issue](https://github.com/DG65/RoboHub/issues).

## Lizenz

PolyForm Noncommercial 1.0.0 — private und nicht-kommerzielle Nutzung ist frei, gewerbliche
Nutzung erfordert eine gesonderte Lizenz vom Rechteinhaber (DG65). Vollständiger Text:
[LICENSE](LICENSE). Spenden sind willkommen:
[paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).
