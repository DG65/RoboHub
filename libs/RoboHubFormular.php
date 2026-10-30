<?php

declare(strict_types=1);

// ===========================================================================
// Gemeinsame Formular-Bausteine aller RoboHub-Module (Konto, Konfigurator,
// Roboter) nach der DG65-Formular-Konvention: „Wozu dieses Modul?" →
// „Neu bis Version" → „Dokumentation & Hilfe" → Fachpanels →
// Rückmeldungs-Hinweis → „Über dieses Modul".
//
// Bewusst als Trait mit Modul-Präfix im Namen: Klassen- und Trait-Namen sind
// in Symcon prozessweit global, ein generischer Name würde mit fremden
// Modulen kollidieren.
//
// Jedes Modul liefert über robohubModulePrefix() seinen eigenen Präfix, damit
// die Ack-Knöpfe die richtige globale Funktion aufrufen (ROBOHUB_/ROBO_/
// ROBOCFG_) — gleiche Methodennamen in verschiedenen Präfixen sind erlaubt.
//
// „🪟 Formular: Aufbau" (SUITE.md 3a) erscheint, wenn ein Modul mehr als zwei
// benannte Fachpanels liefert (Konto, Roboter); dann braucht es die Property
// `FormLayout` ('[]'). Der Konfigurator hat nur ein Fachpanel und kein Panel.
// ===========================================================================

trait RoboHubFormular
{
    // „Was ist neu": Schlüssel = Version ohne Beta-/Build-Suffix.
    private static $ROBOHUB_NEWS_VERSIONS = [
        '0.1.0' => [
            '• Erstes Release: Anbindung von Dreame- und MOVA-Saug-/Wischrobotern über die Hersteller-Cloud (Dreamehome-/MOVAhome-Konto).',
            '• Konto-Instanz meldet sich einmal an und verwirft danach das Passwort — gespeichert bleibt nur der Zugangsschlüssel.',
            '• Konfigurator findet alle Roboter im Konto und legt sie mit einem Klick an.',
            '• Roboter-Instanz: Status, Akku, Reinigungsfortschritt, Verbrauchsteile, Station sowie Start/Pause/Stopp/Zur Station/Orten, Saugstufe, Wassermenge und Raumreinigung.',
        ],
        '0.1.1' => [
            '• Mähroboter: Gerätecodes vollständig (Fehler, Wartung, Hinweise getrennt) — reine Hinweise wie „Schlechtwetter-Schutz aktiv" erscheinen in der neuen Variable „Letzte Meldung" statt als Fehler. MOVA LiDAX Ultra 1000/2000 mit ihren abweichenden Codes 0 und 55.',
            '• Mähroboter: Zustände nach den Beschriftungen der Hersteller-App (3 = Pausiert, 4 = Pausiert wegen Fehler); Start/Stopp/Pause mit Ausweichweg, falls der Mäher einen Befehlsweg ablehnt.',
        ],
        '0.1.2' => [
            '• Konfigurator: bereits angelegte Roboter erscheinen nicht mehr zusätzlich als rote Zeile „nicht mehr im Konto".',
        ],
        '0.34.0' => [
            '• Neu: Eufy-Mähroboter E15 direkt im Heimnetz, ohne Cloud (Hersteller „Eufy Mähroboter E15"). Zustand, Akku, Schnitthöhe, Lautstärke; Start, Pause, Zur Station. Das Eufy-Konto wird nur einmal für den Geräteschlüssel gebraucht, das Passwort wird verworfen. E18 und C15 sind nicht geprüft.',
            '• Der Hersteller „Eufy" heißt jetzt ausdrücklich „nur Saugroboter"; die Fehlermeldung beim Anmelden nennt den Server und den richtigen Weg für Mäher.',
        ],
        '0.33.0' => [
            '• 🪟 Neu: Formular-Aufbau selbst wählen — im Konto und im Roboter legst du unter „🪟 Formular: Aufbau" fest, welche Bereiche beim Öffnen aufgeklappt, zugeklappt oder ausgeblendet sind und in welcher Reihenfolge sie stehen. Ausgeblendete Bereiche arbeiten weiter.',
        ],
        '0.32.0' => [
            '• Neu: Eufy RoboVac/Omni mit Eufys Cloud-Anbindung (z. B. X8/X9/X10, L60, C10/C20, G50, Omni S1 Pro). Zustand, Akku, Fehler, Verschleiß, Saugstufe; Start, Pause, Stopp, Zur Station, Orten. Ältere reine Tuya-Modelle noch nicht.',
        ],
        '0.31.0' => [
            '• Neu: Kärcher Home Robots (RCV, RCF, RVC, RVM, RVF; App „Kärcher Home Robots"). Zustand, Akku, Fehler, Verschleiß, Saugstufe, Wassermenge, Reinigungsmodus; Start, Pause, Stopp, Zur Station, Orten. Das Passwort wird nach der Anmeldung verworfen.',
        ],
        '0.30.0' => [
            '• Neu: Miele Scout über die offizielle Miele-Schnittstelle (Zugang bei Miele anlegen). Zustand mit Mieles eigenem Text, Fehler F1–F8, Akku, Programm (Auto/Turbo/Leise); Start, Pause, Stopp. „Zur Station" bietet Miele nicht.',
        ],
        '0.29.0' => [
            '• Neu: Vorwerk Kobold VR200/VR300 — Anmeldung per E-Mail-Code, kein Passwort. Zustand, Fehler, Hinweise, Akku; Start/Fortsetzen, Pause, Stopp, Zur Station, Orten, beim VR300 gespeicherte Bereiche reinigen. Der VR7 nutzt eine andere Cloud und geht noch nicht.',
        ],
        '0.28.0' => [
            '• Neu: AL-KO Robolinho über die offizielle AL-KO-Schnittstelle (Zugang bei AL-KO beantragen). Zustand mit Grund der Heimfahrt, Regen-/Frostpause, Fehler, Akku, WLAN, Laufzeiten, Messer, Wochenplan; Mähen, Anhalten, Zur Station.',
        ],
        '0.27.0' => [
            '• Neu: STIHL iMOW — neue Generation (iMOW 5/6/7, auch EVO) und ältere RMI-Modelle in einem Konto, RoboHub erkennt die Generation selbst. Zustand, Fehler mit STIHLs Text, Akku, Statistik, Zonen; Mähen, Pause, Zur Station.',
            '• Ältere iMOW: STIHL gibt den Zugang nur für 30 Tage — RoboHub speichert dein Passwort nicht, erinnert drei Tage vorher und bittet dann um eine neue Anmeldung.',
            '• Behoben: Raum/Zone mit der Nummer 0 wurde verworfen (betraf auch den Hauptbereich bei Husqvarna).',
        ],
        '0.26.0' => [
            '• Kachel mit Symcon-Symbolen statt Emojis — sehen auf jedem Gerät gleich aus, auch auf dem Raspberry Pi ohne Emoji-Schrift. Wer die bunten Emojis lieber mag: „Symbole in der Kachel" im Roboter-Formular.',
        ],
        '0.25.0' => [
            '• Variable „Zustand" zeigt wieder Text statt Zahl (z. B. „Fehler" statt 7).',
            '• Konten ohne Echtzeit (Worx, Roborock, Ecovacs, iRobot, Narwal, Navimow, Indego) melden nicht mehr „übergeordnete Instanz inaktiv" — unter dem Konto hängt jetzt ein immer aktiver Virtual IO statt eines geschlossenen Client Sockets. Wird automatisch umgestellt.',
            '• Worx & Co.: Akkutemperatur, Akkuspannung, Ladezyklen, WLAN-Signal, Strecke gesamt, Messerlaufzeit und Firmware.',
        ],
        '0.24.0' => [
            '• Neu: Narwal Saug-/Wischroboter (Flow, Flow 2, Freo Z10, Freo X10 Pro, Freo 20, JX) — lokal im Heimnetz, ohne Cloud und ohne Passwort. IP eintragen, koppeln. Zustand, Akku, Fehler, Räume; Start, Räume reinigen, Pause, Stopp, Zur Station, Orten.',
            '• Ein Narwal in der Station wird für die Abfrage nicht geweckt — nur für Befehle.',
        ],
        '0.23.0' => [
            '• Neu: Bosch Indego Mähroboter. Anmeldung über die Bosch-Anmeldeseite (SingleKey ID), RoboHub kennt dein Passwort nicht. Zustand, Akku, gemähter Anteil, Mähstunden, Mähen, Pause und Zur Station.',
            '• Neue Variable „Fortschritt" (in %), zuerst bei Indego.',
        ],
        '0.22.0' => [
            '• Neu: Segway Navimow Mähroboter. Anmeldung über die Segway-Anmeldeseite, RoboHub kennt dein Passwort nicht. Zustand, Akku, Start, Pause, Stopp und Zur Station.',
        ],
        '0.21.1' => [
            '• Befehle aus Skripten (ROBO_Starten, ROBO_ZurStation …) zeigen ihre Rückmeldung jetzt auch in der Kachel.',
        ],
        '0.21.0' => [
            '• Worx, Kress, LandXcape, Ferrex: Regenverzögerung (0–12 h), Mähzeiten an/aus (Party-Modus) und Zeitzuschlag (−100 bis +100 %) lesen und umstellen.',
            '• Worx & Co.: Der Wochenplan aus der App steht jetzt in der Variable „Zeitpläne" und in der Kachel, inklusive zweiter Mähzeit und Kantenschnitt.',
        ],
        '0.20.0' => [
            '• Husqvarna Automower und GARDENA: Echtzeit-Meldungen über WebSocket — Zustand, Akku und Einstellungen kommen sofort statt alle 5 bzw. 15 Minuten. Solange die Verbindung steht, fragt RoboHub nur noch stündlich vollständig ab (schont das Anfragelimit). Die Zertifikatsprüfung bleibt immer an.',
            '• Husqvarna: Schnitthöhe (Stufe 1–9) und Scheinwerfer (immer an/aus, nur abends, abends und nachts) lesen und umstellen.',
            '• Bestehende Husqvarna-/GARDENA-Konten tauschen ihre ungenutzte Verbindung von selbst gegen einen WebSocket Client.',
        ],
        '0.19.4' => [
            '• Variable „Zustand": Sie ließ sich in der Konsole nicht bearbeiten (z. B. Archiv einschalten) — „Diese Darstellung ist nur für Variablen mit einer Variablenaktion verfügbar". Sie nutzt jetzt die Wertanzeige.',
        ],
        '0.19.3' => [
            '• Roboter einzeln abschaltbar: „🔌 Diesen Roboter deaktivieren" oben im Formular. Keine Abfragen, keine Befehle, Status „Inaktiv" — Konto und die anderen Roboter laufen weiter.',
        ],
        '0.19.2' => [
            '• „Einstellungen des Roboters zeigen" betrifft jetzt nur noch den Überblick in der Kachel. Die Variablen der Instanz bleiben immer sichtbar; von 0.19.0 versteckte werden einmalig wieder eingeblendet.',
            '• Kachel-Überblick: Einstellungen stehen mit ihrem Namen da („Saugstufe: Hoch" statt nur „Hoch").',
            '• iRobot: Ist der Roboter gerade mit der iRobot-App oder einem anderen System verbunden, sagt die Fehlermeldung das jetzt.',
        ],
        '0.19.1' => [
            '• iRobot: Die Einstellungen heißen jetzt wie in der iRobot-App (Saugleistung Niedrig/Mittel/Hoch, Einfach-/Zweifach-Reinigung, Reinigung nach Raumgröße).',
        ],
        '0.19.0' => [
            '• iRobot: Reinigungseinstellungen wie in der iRobot-App — Saugleistung, Reinigungsdurchgänge, Kanten-Reinigung und Verhalten bei vollem Behälter, lesbar und umstellbar.',
            '• iRobot (900er wie der 980): Positionskarte mit dem gefahrenen Weg der laufenden Reinigung, Roboter mit Blickrichtung und Station.',
            '• Neue Einstellung „Einstellungen des Roboters zeigen" (unter „Kachel und IPSView"): aus = Einstellungs-Variablen in der Visualisierung versteckt und nicht in der Kachel, bedienbar bleiben sie in der Konsole und per Skript. Gilt für alle Hersteller.',
        ],
        '0.18.14' => [
            '• iRobot: „Reinigungen gesamt" erscheint jetzt bei jedem Roomba sofort, nicht erst, wenn der Roboter seine Statistik schickt.',
        ],
        '0.18.13' => [
            '• iRobot: Die Reinigungsdauer zählt jetzt auch beim Roomba 980 mit (aus dem Startzeitpunkt der Reinigung); die Fläche erscheint in der Kachel erst, wenn der Roboter eine meldet.',
        ],
        '0.18.12' => [
            '• iRobot: „Zur Station" mitten in der Reinigung hält den Roboter jetzt erst an und schickt ihn dann zurück — vorher reagierte der 980 nicht.',
            '• Kachel: Solange ein Befehl läuft, sind die Knöpfe gesperrt. Ein zweiter Klick führte vorher zu einem Fehler.',
            '• Kachel: „Einstellung" heißt jetzt „Überblick"; die laufende Reinigung steht dort als „🧹 bisher … m² · … min".',
        ],
        '0.18.11' => [
            '• Kachel: neue Einstellung „Farben der Kachel" (automatisch, hell, dunkel). Symcon teilt einer Kachel nicht mit, ob die Visualisierung dunkel ist — bei schlecht lesbarer Schrift fest einstellen.',
            '• Kachel: hervorgehoben ist jetzt der Knopf, der gerade naheliegt (Start, beim Reinigen Pause).',
            '• iRobot: Befehle warten jetzt, bis der Roboter reagiert, und melden, was er tut (z. B. „Roboter meldet: Reinigt").',
        ],
        '0.18.10' => [
            '• iRobot: Schickt der Roboter beim Abruf nur Funkmeldungen statt seines Stands (beim 980 ab und zu), bleibt der letzte Stand bis 15 Minuten stehen — keine Fehlermeldung mehr.',
            '• iRobot: Ist die Verbindung kurz belegt („Zielcomputer verweigerte die Verbindung"), versucht RoboHub es nach 3 Sekunden noch einmal. Der funktionierende Verschlüsselungsweg wird gemerkt, das spart einen vergeblichen Verbindungsversuch je Abruf.',
            '• iRobot: Der ungenutzte Client Socket heißt jetzt auch so, wenn er noch als „Echtzeit" angelegt wurde.',
        ],
        '0.18.9' => [
            '• iRobot: Ältere Roomba (980) meldeten beim Abruf oft „Der Roboter hat keinen Zustand gemeldet". RoboHub bestätigt jetzt jede Meldung des Roboters, damit er die nächste schickt, und wartet länger auf den vollständigen Stand. Die Fehlermeldung nennt, was angekommen ist.',
        ],
        '0.18.8' => [
            '• Konfigurator unter Symcon 9.1: „Die Erstellungs-Kette für RoboHubKonto ist unvollständig" und ein doppeltes Konto — behoben. Jedes Konto bekommt jetzt einen Client Socket, auch Hersteller ohne Echtzeit (dort bleibt er geschlossen). Doppelte, leere Konten kann man löschen.',
        ],
        '0.18.7' => [
            '• Konfigurator: Unter Symcon 9.1 brach „Erstellen" mit „Instanz … hat bereits ein übergeordnetes Objekt" ab, und Symcon nahm dabei auch das Konto wieder weg. Behoben: Der neue Roboter wartet jetzt, bis der Konfigurator ihn mit dem Konto verbunden hat.',
        ],
        '0.18.6' => [
            '• iRobot: Ältere Roomba (z. B. 980 mit Firmware 2.x) ließen sich nicht koppeln, weil das in Symcon eingebaute OpenSSL 3 ihre Verschlüsselung ablehnt. RoboHub spricht solche Roboter jetzt über einen eigenen TLS-1.2-Weg an, die Zertifikatsprüfung per gemerktem Fingerabdruck bleibt gleich.',
            '• iRobot: Koppeln in zwei Schritten — erst „Jetzt anmelden" (Roboter wird gesucht), dann HOME-Taste halten und noch einmal. Manche Roboter antworten während des Koppelfensters nicht auf die Suche. Fehlermeldungen nennen jetzt den genauen TLS-Grund.',
        ],
        '0.18.5' => [
            '• Konfigurator: neuer Knopf „➕ Neues RoboHub Konto anlegen" — falls man das Konto in der Instanzauswahl der Konsole nicht findet.',
        ],
        '0.18.4' => [
            '• Symcon 9.1: Die Echtzeit-Verbindung zu Dreame/MOVA scheitert dort an der neuen Zertifikatsprüfung (Herstellerzertifikat gilt ab 1969). RoboHub schließt die Verbindung dann selbst, erklärt den Grund im Konto und fragt regelmäßig ab — kein Fehlerstatus mehr. Neuer Versuch einmal am Tag oder per Knopf.',
        ],
        '0.18.3' => [
            '• Symcon 9.1: Die Warnung „Parameter DIGITS is not defined for this presentation" im Meldungsfenster (bei jedem Abruf) ist behoben.',
        ],
        '0.18.2' => [
            '• Konfigurator: Unter Symcon 9.1 kam beim Erstellen „Die erstellte Instanz wird nicht als Liste erstellt …" — behoben. Der Konfigurator gibt jetzt die Kette Roboter → Konto an, Symcon hängt den Roboter direkt an das passende Konto.',
        ],
        '0.18.1' => [
            '• Beim Anlegen eines Roboters brach neuere Symcon-Versionen mit „Entry in parameter OPTIONS includes unknown sub-parameters" ab — behoben (Auswahllisten nach der Symcon-Dokumentation).',
        ],
        '0.18.0' => [
            '• Neu: GARDENA smart system Mähroboter (Sileno) über die offizielle GARDENA-Schnittstelle — gleicher Zugang wie Husqvarna (Application Key/Secret, dazu „GARDENA smart system API" verbinden). Zustand, Akku, Fehler, Wetterpause, Betriebsstunden; Start, Parken.',
            '• Wegen des engen Abfragelimits höchstens alle 15 Minuten. Nicht an einem echten Mäher geprüft — Rückmeldungen im Forum-Thread sind sehr willkommen.',
        ],
        '0.17.0' => [
            '• Neu: Husqvarna Automower über die offizielle Automower Connect API. Application Key und Secret aus dem Husqvarna-Entwicklerportal eintragen (sie bleiben gespeichert). Zustand, Akku, Fehler, Wetterpause, Mähzeit; Start, Pause, Parken, Arbeitsbereich mähen.',
            '• Wegen des Limits von 10.000 Abfragen im Monat teilen sich alle Mäher eines Kontos eine Abfrage höchstens alle 5 Minuten. Nicht an einem echten Mäher geprüft — Rückmeldungen im Forum-Thread sind sehr willkommen.',
        ],
        '0.16.0' => [
            '• Neu: iRobot Roomba/Braava — lokal im Heimnetz, ganz ohne Cloud. IP eintragen, HOME-Taste halten bis Töne kommen, koppeln. Zustand, Akku, Fehler, Behälter, Statistik; Start/Fortsetzen, Pause, Stopp, Zur Station, Orten.',
            '• Sicherheit: RoboHub merkt sich beim Koppeln das Zertifikat des Roboters und lehnt danach jedes andere ab. Nicht an einem echten Roboter geprüft — Rückmeldungen im Forum-Thread sind sehr willkommen.',
        ],
        '0.15.0' => [
            '• Neu: Ecovacs-Saugroboter (Deebot) und -Mähroboter (GOAT), App „Ecovacs Home". Land wählen, mit E-Mail und Passwort anmelden; verlangt Ecovacs eine Bestätigung, kommt ein Code per E-Mail.',
            '• Ecovacs kennt keinen Erneuerungsschlüssel: RoboHub behält einen Hash des Passworts, um sich alle 7 Tage selbst neu anzumelden (Abmelden löscht ihn). Nicht an einem echten Gerät geprüft — Rückmeldungen im Forum-Thread sind sehr willkommen.',
        ],
        '0.14.1' => [
            '• Beim Neuanlegen eines Kontos erschien „Konnte nicht zur Instanz verbinden … hat bereits ein übergeordnetes Objekt" — behoben. RoboHub legt die Verbindungsinstanz nur noch selbst an, wenn nach einigen Sekunden keine da ist.',
            '• Konten ohne Echtzeit-Meldungen (Roborock, Worx, Kress, LandXcape, Ferrex) bekommen keine überflüssige Verbindungsinstanz mehr.',
        ],
        '0.14.0' => [
            '• Neu: Mähroboter von Worx (Landroid), Kress, LandXcape und Ferrex. Anmeldung mit E-Mail und Passwort (danach verworfen), Zustand, Akku, Laden, Fehler, Regen, Mähzeit gesamt; Start, Pause, Stopp, Zur Station.',
            '• Noch ohne Zonen, Zeitpläne und Echtzeit-Meldungen; nicht an einem echten Gerät geprüft — Rückmeldungen im Forum-Thread sind sehr willkommen.',
        ],
        '0.13.0' => [
            '• Neu: Roborock-Saugroboter (App „Roborock"). Anmeldung ohne Passwort per E-Mail-Code, danach Zustand, Akku, Verbrauchsteile, Räume, Start/Pause/Stopp/Station/Orten, Raum reinigen mit Saugstufe und Durchgängen, Nicht stören und Lautstärke.',
            '• Noch ohne Kartenbild und Echtzeit-Meldungen; nicht an einem echten Roborock geprüft — Rückmeldungen im Forum-Thread sind sehr willkommen.',
            '• Kachel: Die Auswahl bei „Raum reinigen mit Wahl" zeigt die Saugstufen und Modi, die dein Roboter wirklich kennt.',
        ],
        '0.12.5' => [
            '• Einträge im Symcon-Protokoll stehen jetzt unter dem Namen der Instanz, Fehler (z. B. fehlgeschlagene Befehle oder Anmeldung) als Warnung.',
        ],
        '0.12.4' => [
            '• Rückmeldungen gehen jetzt in den RoboHub-Thread im Symcon-Forum (Knopf im Panel „Rückmeldungen").',
        ],
        '0.12.3' => [
            '• Kurz nach einem Modul-Update meldete Symcon manchmal „keine aktive Konto-Instanz", obwohl das Konto lief — RoboHub prüft das Konto jetzt selbst.',
        ],
        '0.12.2' => [
            '• Live-Karte: Der fertige Weg bleibt nach der Arbeit stehen, auch wenn der Roboter ihn in der Station löscht.',
        ],
        '0.12.1' => [
            '• Die Variable „Einstellungen je Raum" wird in „Raum-Einstellungen im Roboter" umbenannt (nur, wenn sie noch den alten Namen trägt).',
        ],
        '0.12.0' => [
            '• Raum reinigen wie das „benutzerdefiniert"-Fenster der App: auf der Kachel nach der Raumwahl Modus, Saugstufe und Durchgänge für diesen Start wählen (oder ROBO_RaeumeReinigenMit).',
            '• Die im Roboter gespeicherten Raumwerte heißen jetzt „Raum-Einstellungen im Roboter" und sind nur noch Anzeige — die App nutzt sie nicht.',
        ],
        '0.11.5' => [
            '• Fehlersuche: „📋 Rohdaten" zeigen die Gerätedaten der Cloud (Suche nach den Raum-Einstellungen der App).',
        ],
        '0.11.4' => [
            '• Einstellungen je Raum: Ändert man sie in der Hersteller-App, meldet der Roboter das per Echtzeit — RoboHub liest die neuen Werte dann sofort.',
        ],
        '0.11.3' => [
            '• Fehlersuche: „📋 Rohdaten" zeigen, was beim Lesen des Kartenobjekts (Raum-Einstellungen) passiert.',
        ],
        '0.11.2' => [
            '• Einstellungen je Raum zeigen jetzt den Stand der Hersteller-App (aus dem Kartenobjekt der Cloud); die Live-Karte überschreibt ihn nicht mehr.',
        ],
        '0.11.1' => [
            '• Gespeicherte Karte wird jetzt in voller Fassung gelesen (statt der Vorschau). Nach dem Ändern einer Raum-Einstellung wird die Karte bei Bedarf ein zweites Mal gelesen.',
        ],
        '0.11.0' => [
            '• Saugroboter: Einstellungen je Raum ändern — Modus, Saugstufe und Durchgänge im Formular („🏠 Einstellungen je Raum") oder per ROBO_RaumEinstellungSetzen. Nur wenn der Roboter nicht reinigt.',
        ],
        '0.10.1' => [
            '• Einstellungen je Raum: Modus wie in der App (bei Geräten mit Mopp-Anhebung getauscht), in Reinigungsreihenfolge, Wassermenge nur bei Modi mit Wischen.',
        ],
        '0.10.0' => [
            '• Saugroboter: Einstellungen je Raum aus der Hersteller-App (Saugstufe, Durchgänge, Modus, Wasser/Feuchte) — als Variable und auf der Kachel unter den gewählten Räumen. Ändern folgt.',
        ],
        '0.9.5' => [
            '• Mähroboter: Mäh-Pläne ein- und ausschalten (Formular, Kachel, Skript) — nur wenn der Mäher ruht; der Mäher bestätigt jede Änderung, danach werden alle Pläne neu gelesen.',
        ],
        '0.9.4' => [
            '• Mäh-Pläne: leere Platzhalter ausgeblendet, „täglich"/„werktags" zusammengefasst, Pläne ohne Namen ab „Plan 1" gezählt.',
        ],
        '0.9.3' => [
            '• Mähroboter: Mäh-Pläne von MOVA-Mähern (z. B. LiDAX) werden jetzt gefunden — sie liegen in Plantabellen statt in einem Plan-Dokument.',
        ],
        '0.9.2' => [
            '• Mähroboter: Findet RoboHub keinen Mäh-Plan, stehen die Antworten des Mähers in „📋 Rohdaten" (Fehlersuche).',
        ],
        '0.9.1' => [
            '• Mähroboter: Mäh-Pläne aus der Hersteller-App (Name, Wochentage, Uhrzeit, ganze Fläche oder Zonen) als Variable, auf der Kachel und im Formular — vorerst nur Anzeige.',
        ],
        '0.9.0' => [
            '• Zeitpläne der Saugroboter aus der Hersteller-App: Uhrzeit, Wochentage, Räume bzw. alle Räume, Modus — als Variable, auf der Kachel und im Formular, dort und auf der Kachel ein- und ausschaltbar.',
        ],
        '0.8.1' => [
            '• Live-Karte: Räume farbig und mit Namen, auch wenn der Roboter im Live-Bild keine Raumliste mitschickt (Namen aus der gespeicherten Karte).',
        ],
        '0.8.0' => [
            '• Live-Karte: Während der Arbeit zeigt die Kachel den gefahrenen Weg und den Roboter (Punkt mit Blickrichtung) — Saugroboter alle 30 s, Mähroboter alle 60 s, dazwischen bewegt die Echtzeit-Verbindung den Roboter.',
            '• Nach der Arbeit bleibt der fertige Weg stehen. Abschaltbar unter „Kachel und IPSView" → „Live-Karte während der Arbeit".',
        ],
        '0.7.1' => [
            '• Kartenbild: feine weiße Trennlinien zwischen den Räumen, wie in der Dreamehome-App.',
        ],
        '0.7.0' => [
            '• Kartenbild auf der Kachel und der IPSView-Seite: Räume farbig mit Namen und Station (Saugroboter), Mähzonen und Sperrflächen (Mähroboter). Namen antippen wählt Räume/Zonen aus.',
            '• Abschaltbar unter „Kachel und IPSView" → „Kartenbild zeigen".',
        ],
        '0.6.0' => [
            '• Mähroboter: Mähzonen aus der gespeicherten Karte — Variable „Zone mähen" und Zonen-Knöpfe auf der Kachel, auch mehrere Zonen auf einmal.',
        ],
        '0.5.0' => [
            '• Nicht stören: an/aus sowie Beginn und Ende (HH:MM) direkt schaltbar — bei Saug- und Mährobotern.',
            '• Lautstärke als Schieberegler (0–100 %). Änderungen aus der Hersteller-App kommen per Echtzeit an.',
            '• Die Kachel zeigt 🌙 Nicht stören und 🔊 Lautstärke.',
        ],
        '0.4.4' => [
            '• Verbrauchsteile zurücksetzen: Nach Wechsel oder Reinigung den Zähler auf 100 % stellen — im Formular („🔧 Verbrauchsteile zurücksetzen", mit Sicherheitsabfrage) und auf der Kachel (↺, dann „Ja, zurücksetzen"). Jede Rücksetzung steht im Symcon-Protokoll.',
        ],
        '0.4.3' => [
            '• „📋 Rohdaten" zeigen jetzt auch die letzten 20 Echtzeit-Meldungen mit ihren Werten — hilfreich, wenn ein Wert anders ankommt als erwartet.',
        ],
        '0.4.2' => [
            '• Echtzeit: Die Verbindungsinstanz („RoboHub Echtzeit (…)", Client Socket) wird jetzt zuverlässig angelegt — auch bei Konten von vor 0.4.0.',
        ],
        '0.4.1' => [
            '• Echtzeit: Auch Konten, die vor 0.4.0 angelegt wurden, bekommen jetzt ihre Verbindungsinstanz (Client Socket).',
        ],
        '0.4.0' => [
            '• ⚡ Echtzeit-Meldungen: Änderungen am Roboter (Zustand, Akku, Fehler, Station) kommen sofort per verschlüsselter Verbindung von der Hersteller-Cloud — Panel „⚡ Echtzeit-Meldungen" in der Konto-Instanz. Dafür legt Symcon unter jedem Konto automatisch eine Client-Socket-Instanz an.',
        ],
        '0.3.5' => [
            '• Produktbild: sucht die Bildadresse jetzt im ganzen Gerätedatensatz (lag bei echten Konten nicht an der erwarteten Stelle).',
        ],
        '0.3.4' => [
            '• Kachel zeigt das Produktbild deines Roboters (wie in der Hersteller-App) mit farbigem Zustandsring; ohne Bild bleibt das Symbol.',
        ],
        '0.3.3' => [
            '• Kachel: Farbstufen der Verbrauchsteile wie in den Hersteller-Apps — rot nur bei 0–5 %, gelb („bald ersetzen") bis 35 %.',
        ],
        '0.3.2' => [
            '• Kachel: Inhalt beginnt bei allen Robotern auf gleicher Höhe direkt unter dem Namen, größeres Zustandssymbol, bis zu vier Verbrauchsteile immer offen, „Stand" am unteren Rand.',
        ],
        '0.3.1' => [
            '• Kachel überarbeitet: überlappt nicht mehr mit dem Instanznamen, nutzt breite Kacheln zweispaltig (links Zustand und Knöpfe, rechts Räume und Verbrauchsteile) und wächst mit der Kachelgröße mit.',
        ],
        '0.3.0' => [
            '• Jeder Roboter ist jetzt eine eigene Kachel: Zustand, Akku, Knöpfe, Räume zum Antippen und die fälligen Verbrauchsteile auf einen Blick.',
            '• Für IPSView: dieselbe Ansicht als eigenständige Seite (WebView-Element). Standardmäßig nur Anzeige; Bedienung erst nach Freigabe im Panel „📱 Kachel und IPSView" und nur mit geheimem Schlüssel in der Adresse.',
        ],
        '0.2.5' => [
            '• Saugroboter: zusätzlich Abwasserauslassfilter und Schmutzwasserbehälter des Roboters (% und Reststunden), sofern das Modell sie meldet.',
        ],
        '0.2.4' => [
            '• Reststunden der Verbrauchsteile wie in den Hersteller-Apps: Sauger (Hauptbürste, Seitenbürste, Filter, Sensoren, Mopp/Walze) und Mäher (Messer, Reinigungsbürste, Wartung) — z. B. für eine Erinnerung vor dem Wechsel.',
        ],
        '0.2.3' => [
            '• Mähroboter: zusätzlich „Reinigungsbürste" und „Wartung des Roboters" in % — wie in der Hersteller-App.',
        ],
        '0.2.2' => [
            '• Mähroboter: Zustand 19 wird als „Mäht" erkannt (bisher „Unbekannt") — am MOVA LiDAX Ultra 1000 live beobachtet.',
        ],
        '0.2.1' => [
            '• Schnellere Abfrage: Werte, die ein Roboter nachweislich nicht liefert (z. B. beim MOVA LiDAX die Auftragsdaten), fragt RoboHub nur noch stündlich nach statt bei jedem Abruf — der Abruf dauert dadurch Sekunden statt einer Viertelminute.',
        ],
        '0.2.0' => [
            '• Ein Konfigurator für alle Konten: Saugroboter (Dreamehome) und Mäher (MOVAhome) stehen in einer Liste, mit Spalte „Konto". Der Konfigurator braucht keine Verbindung mehr — vorhandene zweite Konfiguratoren kannst du löschen.',
            '• Roboter verbinden sich selbst mit dem Konto, in dem ihre Geräte-ID registriert ist (auch falsch eingehängte werden umgehängt). Das Roboter-Formular zeigt das verbundene Konto.',
        ],
        '0.1.3' => [
            '• Konfigurator: Bei mehreren Konten (z. B. Dreamehome und MOVAhome) zeigt jeder Konfigurator nur noch die Roboter seines eigenen Kontos.',
        ],
    ];

    private static $ROBOHUB_REPO_URL    = 'https://github.com/DG65/RoboHub';
    private static $ROBOHUB_ISSUES_URL  = 'https://github.com/DG65/RoboHub/issues';
    private static $ROBOHUB_FORUM_URL   = 'https://community.symcon.de/t/beta-modul-robohub-dreame-saugroboter-und-mova-maehroboter-in-ip-symcon-mit-karte-live-weg-zeitplaenen-und-raumreinigung/144636';
    private static $ROBOHUB_LICENSE_URL = 'https://github.com/DG65/RoboHub/blob/main/LICENSE';
    private static $ROBOHUB_PAYPAL_URL  = 'https://paypal.me/DietmarGureth';
    private static $ROBOHUB_LIBRARY_GUID = '{F4D2E22D-E5F0-444D-A2FF-359C105E74FB}';

    abstract protected function robohubModulePrefix(): string;

    /** Zweck-Text (2–3 Sätze) je Modul. */
    abstract protected function robohubPurposeLines(): array;

    /** Inhalt des Doku-Panels je Modul (ohne Versionszeile). */
    abstract protected function robohubDocLines(): array;

    protected function registerFormAttributes(): void
    {
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    public function AckNews(): void
    {
        // Gespeichert wird die tatsächlich INSTALLIERTE Version, nicht der
        // zuletzt gezeigte Eintrag (SUITE.md Formular-Optik Punkt 1).
        $this->WriteAttributeString('SeenNews', $this->robohubInstalledVersion());
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    protected static function robohubBaseVersion(string $version): string
    {
        return (string)preg_replace('/-.*$/', '', $version);
    }

    protected function robohubLibrary(): array
    {
        $lib = function_exists('IPS_GetLibrary') ? @IPS_GetLibrary(self::$ROBOHUB_LIBRARY_GUID) : false;
        return is_array($lib) ? $lib : [];
    }

    protected function robohubInstalledVersion(): string
    {
        $lib = $this->robohubLibrary();
        if (!empty($lib['Version'])) {
            return self::robohubBaseVersion((string)$lib['Version']);
        }
        $keys = array_keys(self::$ROBOHUB_NEWS_VERSIONS);
        usort($keys, 'version_compare');
        return (string)end($keys);
    }

    protected function formPurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        $items = [];
        foreach ($this->robohubPurposeLines() as $line) {
            $items[] = ['type' => 'Label', 'caption' => $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => $this->robohubModulePrefix() . '_AckPurposeIntro($id);'];
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  Wozu dieses Modul?',
            'items' => $items,
        ];
    }

    protected function formNewsBanner(): ?array
    {
        $seen = $this->ReadAttributeString('SeenNews');
        if ($seen === '') {
            $seen = '0';
        }
        $pending = [];
        foreach (self::$ROBOHUB_NEWS_VERSIONS as $version => $lines) {
            if (version_compare((string)$version, $seen, '>')) {
                $pending[(string)$version] = $lines;
            }
        }
        if (count($pending) === 0) {
            return null;
        }
        uksort($pending, 'version_compare');
        $items = [];
        foreach ($pending as $version => $lines) {
            $items[] = ['type' => 'Label', 'caption' => 'Version ' . $version . ':'];
            foreach ($lines as $line) {
                $items[] = ['type' => 'Label', 'caption' => $line];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => $this->robohubModulePrefix() . '_AckNews($id);'];
        $keys = array_keys($pending);
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => '🆕  Neu bis Version ' . end($keys),
            'items' => $items,
        ];
    }

    protected function formDocPanel(): array
    {
        $lib = $this->robohubLibrary();
        $ver = isset($lib['Version'])
            ? 'ℹ️ RoboHub Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ RoboHub';
        $items = [['type' => 'Label', 'caption' => $ver]];
        foreach ($this->robohubDocLines() as $line) {
            $items[] = ['type' => 'Label', 'caption' => $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Anleitung auf GitHub', 'onClick' => "echo '" . self::$ROBOHUB_REPO_URL . "';", 'link' => true];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '📖  Dokumentation & Hilfe',
            'items' => $items,
        ];
    }

    protected function formForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        // Forum-Thread seit 0.12.4; GitHub bleibt als zweiter Weg für Fehlerberichte.
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Rückmeldungen',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 RoboHub ist neu. Läuft dein Roboter? Fehlt ein Wert, oder hast du ein Modell, das noch nicht dabei ist? Schreib es gern in den RoboHub-Thread im Symcon-Forum — mit Modellname und, falls vorhanden, dem Inhalt von „Rohdaten".'],
                ['type' => 'Button', 'caption' => 'Zum Forum-Thread', 'onClick' => "echo '" . self::$ROBOHUB_FORUM_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Fehler auf GitHub melden', 'onClick' => "echo '" . self::$ROBOHUB_ISSUES_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => $this->robohubModulePrefix() . '_AckForumHint($id);'],
            ],
        ];
    }

    /** Wortlaut verbundweit identisch (SUITE.md Formular-Optik Punkt 5, Variante A). */
    protected function formLicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::$ROBOHUB_LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::$ROBOHUB_PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    /** Baut das komplette Formular aus Rahmen + Fachpanels. */
    /**
     * Fachpanels des Moduls, Schlüssel = stabiler Name (nicht die Caption). Module mit mehr als zwei
     * Fachpanels liefern sie hier, damit „Formular: Aufbau" und der Zurücksetzen-Knopf sie kennen
     * (SUITE.md 3a). Null-Einträge = Panel gibt es gerade nicht (z. B. keine Räume).
     */
    protected function robohubFachpanels(): array
    {
        return [];
    }

    protected function robohubBuildForm(array $fachpanels, array $actions, array $status): string
    {
        $fachpanels = array_filter($fachpanels);
        $aufbau = [];
        // Formular: Aufbau nur bei benannten Fachpanels und mehr als zwei davon (SUITE.md 3a).
        if (count($fachpanels) > 2 && count(array_filter(array_keys($fachpanels), 'is_string')) === count($fachpanels)) {
            $layout = $this->robohubFormLayout(array_keys($fachpanels));
            $mitte  = [];
            foreach ($layout as $key => $l) {
                $panel = $fachpanels[$key];
                if ($l['open'] === 'open') {
                    $panel['expanded'] = true;
                } elseif ($l['open'] === 'closed') {
                    $panel['expanded'] = false;
                } elseif ($l['open'] === 'hidden') {
                    // Nicht weglassen: die Felder bleiben im Formular, gespeicherte Werte bleiben beim „Übernehmen" erhalten
                    $panel['visible'] = false;
                }
                $mitte[] = $panel;
            }
            $fachpanels = $mitte;
            $aufbau = [$this->robohubLayoutPanel($layout, array_combine(array_keys($layout), $mitte))];
        }
        $elements = array_values(array_filter(array_merge(
            [$this->formPurposeIntro(), $this->formNewsBanner(), $this->formDocPanel()],
            array_values($fachpanels),
            $aufbau,
            [$this->formForumHint(), $this->formLicenseHint()]
        )));
        return (string)json_encode(['elements' => $elements, 'actions' => $actions, 'status' => $status]);
    }

    /**
     * Reihenfolge und Aufklapp-Zustand der Fachpanels aus der Einstellung „FormLayout".
     * Unbekannte oder fehlende Einträge fallen auf Standardreihenfolge und „Standard" zurück.
     *
     * @return array key => ['open' => 'std'|'open'|'closed'|'hidden', 'pos' => int, 'idx' => int], sortiert
     */
    protected function robohubFormLayout(array $keys): array
    {
        $saved = json_decode((string)$this->ReadPropertyString('FormLayout'), true);
        $by = [];
        foreach (is_array($saved) ? $saved : [] as $row) {
            if (is_array($row) && isset($row['Key'])) {
                $by[(string)$row['Key']] = $row;
            }
        }
        $out = [];
        foreach (array_values($keys) as $i => $k) {
            $r = $by[$k] ?? [];
            // Vorrang bei Widerspruch: ausgeblendet vor zugeklappt vor aufgeklappt, keines = Standard
            $open = !empty($r['Hidden']) ? 'hidden' : (!empty($r['Closed']) ? 'closed' : (!empty($r['Opened']) ? 'open' : 'std'));
            $out[$k] = [
                'open' => $open,
                'pos'  => isset($r['Pos']) ? max(1, min(99, (int)$r['Pos'])) : $i + 1,
                'idx'  => $i,
            ];
        }
        uasort($out, function ($a, $b) {
            return [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']];
        });
        return $out;
    }

    private static function robohubPanelName(array $panel, string $key): string
    {
        return trim((string)preg_replace('/\s*\(.*$/u', '', (string)($panel['caption'] ?? $key)));
    }

    /** Wählbarer Aufbau des Formulars: je Fachpanel Aufklapp-Zustand beim Öffnen und Position. */
    protected function robohubLayoutPanel(array $layout, array $panels): array
    {
        $rows   = [];
        $hidden = [];
        foreach ($layout as $key => $l) {
            $name = self::robohubPanelName($panels[$key] ?? [], (string)$key);
            $rows[] = ['Key' => (string)$key, 'Panel' => $name, 'Opened' => $l['open'] === 'open', 'Closed' => $l['open'] === 'closed',
                'Hidden' => $l['open'] === 'hidden', 'Pos' => $l['pos']];
            if ($l['open'] === 'hidden') {
                $hidden[] = trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', $name));
            }
        }
        $hiddenLine = $hidden
            ? [['type' => 'Label', 'caption' => '🙈 Ausgeblendet: ' . implode(', ', $hidden) . ' — zum Wiederzeigen den Haken bei „Ausgeblendet" entfernen. Ausgeblendete Bereiche arbeiten weiter, nur die Anzeige fehlt.']]
            : [];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🪟  Formular: Aufbau' . ($hidden ? ' (' . count($hidden) . ' ausgeblendet)' : ''),
            'items' => array_merge($hiddenLine, [
                ['type' => 'Label', 'caption' => 'Hier legst du fest, welche Bereiche beim Öffnen des Formulars aufgeklappt sind und in welcher Reihenfolge sie stehen. Symcon merkt sich das Auf- und Zuklappen nicht selbst, deshalb gilt die Einstellung hier jedes Mal beim Öffnen. Sie wirkt nach „Übernehmen" beim nächsten Öffnen des Formulars.'],
                ['type' => 'Label', 'caption' => 'Kein Haken heißt „Standard": wie das Modul es vorsieht. Die Position ist eine Zahl; bei gleicher Zahl bleibt die Standardreihenfolge. „Ausgeblendet" nimmt einen Bereich aus der Anzeige, ohne dass er etwas verliert: seine Einstellungen bleiben erhalten und wirken weiter. Zum Wiederzeigen den Haken entfernen oder „Auf Standard zurücksetzen" klicken. Sind mehrere Haken gesetzt, gilt „Ausgeblendet" vor „Zugeklappt" vor „Aufgeklappt". Die Bereiche oben (Zweck, Neu, Dokumentation) und unten (Rückmeldungen, Über dieses Modul) bleiben, wo sie sind, und lassen sich nicht ausblenden. Bereiche, die gerade nichts zu zeigen haben (z. B. Räume ohne Karte), stehen erst in der Liste, wenn es sie gibt.'],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => 'Bereiche', 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => 'Bereich', 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Schlüssel', 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => 'Aufgeklappt', 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Zugeklappt', 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => '🙈 Ausgeblendet', 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Position', 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => '↩️ Auf Standard zurücksetzen', 'onClick' => 'echo ' . $this->robohubModulePrefix() . '_ResetFormLayout($id);'],
            ]),
        ];
    }

    /** Setzt Aufklapp-Zustand und Reihenfolge der Fachpanels im offenen Formular auf den Standard (gilt nach „Übernehmen"). */
    public function ResetFormLayout(): string
    {
        $rows = [];
        $i = 0;
        foreach (array_filter($this->robohubFachpanels()) as $key => $panel) {
            $rows[] = ['Key' => (string)$key, 'Panel' => self::robohubPanelName($panel, (string)$key), 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => ++$i];
        }
        $this->UpdateFormField('FormLayout', 'values', (string)json_encode($rows, JSON_UNESCAPED_UNICODE));
        return 'ℹ️ Auf Standard gesetzt. Zum Speichern unten „Übernehmen" klicken.';
    }
}
