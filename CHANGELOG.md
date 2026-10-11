# Changelog

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
