# RoboHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul-0.3.4-informational)
![Symcon Version](https://img.shields.io/badge/Symcon-9.0%2B-informational)
![License](https://img.shields.io/badge/License-PolyForm%20Noncommercial%201.0.0-orange)
[![Check Style](https://github.com/DG65/RoboHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/RoboHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Spenden-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Saug-, Wisch- und Mähroboter in IP-Symcon — herstellerneutral aufgebaut, als erster
Hersteller **Dreame** und **MOVA** über deren Cloud (Apps „Dreamehome" und „MOVAhome").

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
4. **Einen** RoboHub Konfigurator anlegen — er zeigt die Roboter aller Konten. Roboter
   auswählen, „Erstellen". Achtung: Der Papierkorb hinter einer Zeile löscht die Instanz.

## Variablen

Eine Variable entsteht erst, wenn dein Modell den Wert tatsächlich liefert.

- **Zustand** (herstellerneutral): Bereit, Reinigt/Mäht, Pausiert, Fährt zur Station,
  Lädt, In der Station, Station arbeitet, Fehler, Ruht, Kartiert, Aktualisiert Firmware,
  Wetterpause (Mäher). Dazu **Zustand (Hersteller)** mit dem Originalwert im Klartext.
- **Befehl**: Start/Fortsetzen, Pause, Stopp, Zur Station, Orten.
- **Raum reinigen** (Sauger): Auswahl der Räume aus der gespeicherten Karte.
- Fehler, Akku, Lädt, gereinigte Fläche, Reinigungsdauer, Verbrauchsteile (Hauptbürste,
  Seitenbürste, Filter, Mopp/Walze, Sensoren, beim Mäher Messer), Station, Frisch-/
  Schmutzwasser, Staubbeutel, Reinigungsmittel, Gesamtstatistik.
- Saugstufe, Wassermenge, Reinigungsmodus: direkt schaltbar.

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

## Grenzen (Version 0.1)

- Abfrage per Intervall (Standard 120 s, während der Arbeit 30 s); keine Echtzeit-Meldungen
  (MQTT) und keine Kartenansicht.
- Mäher: noch keine Zonen-Auswahl.
- Andere Regionen als „Europa" sind ungeprüft.

## Danksagung

Die Cloud-Schnittstelle ist aus zwei quelloffenen Projekten (MIT-Lizenz) nachvollzogen:
[TA2k/ioBroker.dreame](https://github.com/TA2k/ioBroker.dreame) und
[Tasshack/dreame-vacuum](https://github.com/Tasshack/dreame-vacuum).

## Lizenz

PolyForm Noncommercial 1.0.0 — private und nicht-kommerzielle Nutzung ist frei, gewerbliche
Nutzung erfordert eine gesonderte Lizenz vom Rechteinhaber (DG65). Vollständiger Text:
[LICENSE](LICENSE). Spenden sind willkommen:
[paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).
