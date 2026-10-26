# LCN Climate Control – IP-Symcon 9

## SMART-FBH – Langzeit-Speicherung (Entwicklungszweig, noch nicht freigegeben)

- 30-Minuten-Lern- und Diagnosetakt. Die bestehende LCN-Heizregelung ist davon unabhängig.
- **Keine neuen Zeitreihen:** Der passive Lerner speichert je Raum nur den aktuellen Prüf-/Phasenzustand sowie begrenzte aggregierte Kennwerte, keine Liste aller Messungen.
- Beim Lernlauf werden Einträge ehemaliger/entfernter Raum-IDs aus dem Attribut `ThermalLearningState` gelöscht, sodass Konfigurationsänderungen nicht über Jahre Einträge anhäufen.
- Unbekannte zusätzliche Top-Level-Raumfelder werden entfernt. Alle regulär erzeugten Statistikzähler sind begrenzt.
- Das Modell liegt in **einem** Symcon-Attribut; es erzeugt nicht pro Messung zusätzliche Objekte oder Dateien.
- **Wichtig:** Die separate Diagnosestringvariable `ThermalLearningDiagnostic` darf nicht zusätzlich zur langfristigen Archivprotokollierung aktiviert werden. Falls die Archivierung dafür manuell eingeschaltet wurde, separat in der Symcon-Archivkonfiguration ausschalten. Das Lernmodul selbst schaltet Archivfunktionen nicht um.
- Langzeittests für tatsächliche Speicherbelegung, Archivkonfiguration und reale Sensorstatus-Rückmeldungen stehen noch aus. Keine produktive Freigabe.


**Stand: v0.5.5 Build 55 – SMART-FBH mit eigener ChatGPT-Lerndiagnose; HEIZ-/KÜHL-Regelung unverändert.**

## 0.5.5 Build 55 – Lern-Diagnose an ChatGPT

- Genau eine zusätzliche Stringvariable in der bestehenden Instanz: **„SMART-FBH – Senden an ChatGPT“** (`ThermalLearningDiagnostic`). Die bisherige Steuerungsdiagnose bleibt bestehen.
- Diagnose enthält Aktivierung, Zeitpunkt des letzten Lernlaufs, Betriebsart, Datenfrische der elf Räume (Ist, Soll, native Relaisrückmeldung), beobachtete Heiz-/Auskühl-/Nachlauffenster und Fehlerursachen.
- WetterZentrale `#40477` wird **ausschließlich lesend** über ihren bestehenden Snapshot geprüft: Qualität, Aktualität, Außentemperatur und Begründung, ohne eigene Wetterabrufe.
- Bei aktivierter Lernfunktion automatisches Diagnose-Update alle fünf Minuten; direkt nach „Übernehmen“ einmal sofort. Zusätzlich Konfigurationsknopf zum Anzeigen.
- Lernfunktion unverändert **standardmäßig AUS**; in Instanz `#17597` im Panel „SMART-FBH“ aktivieren und **Übernehmen** drücken. Nach 5–10 Minuten die neue Variable kopieren und an ChatGPT senden; stabile Lernraten entstehen erst bei echten längeren Heizzyklen.
- **Keine** Änderungen an bestehenden LCN-Reglern, A7/A8, Relaisbefehlen, S1-Sperren, Kühlfreigaben, Raum-Sollwerten, Visualisierung oder Heiz-/Kühl-Workern.



## 0.5.4 Build 54 – SMART-FBH lernt nur lesend

- Neues, separat getaktetes PHP-Lernmodell. **Standardmäßig AUS**, ausdrücklich in der Modulkonfiguration aktivieren.
- Alle fünf Minuten werden nur frische Isttemperaturen, echte S1-Sollwerte und frische Relais-Statusvariablen gelesen; die bestehenden nativen Relaiszuordnungen werden ausschließlich lesend genutzt.
- Vorhandene **WetterZentrale #40477** wird über `JVWC_Snapshot` abgefragt, ohne HTTP-Abruf, Änderungen am Lüftungsmodul oder eigene neue Wettervariablen.
- Pro Raum werden beobachtete Heiz-/Auskühlraten und Nachlaufwerte als begrenzte persistente Statistik gespeichert. Ungültige, fehlende oder zeitlich unterbrochene Daten werden nicht als Beweise verbucht. Bis mindestens drei gültige Lernfenster vorliegen, erscheint kein quantitativer Wert im Lernbericht.
- Keine neue Variable, keine Änderung an bestehenden Kacheln, Heiz-Sollwerten, A7/A8, LCN-Sperren, Relais-Befehlen, Kühlfreigabe oder bestehenden Instanzzuständen. Die Lernfunktion kann jederzeit wieder ausgeschaltet werden; gesammelte Statistik bleibt vorhanden.
- Lernbericht in der Modulkonfiguration über **„SMART-FBH: Passiven Lernstatus anzeigen (nur lesen)“**.
- Bisherige Raumtemperaturen bei zuvor manuell geöffneten Ventilen / falscher OG-Verdrahtung werden nicht rückwirkend übernommen. Wetter- und Temperaturtrends können durch offene Türen, Sonne und unbekannte Vorlauftemperatur verfälscht sein: **noch keine automatische Heizstrategie oder Vorlaufempfehlung**.

**Stand: v0.4.1 Build 41 – Kuehlsoftware abgesichert, Inbetriebnahme noch offen; KUEHLEN bis zur realen Abnahme gesperrt.**


## 0.4.1 Build 41 – Schutz vor unvollstaendiger Inbetriebnahme

Neuer Hinweis fuer LCN-Firmware 1F0715: In der LCN-Community
wird berichtet, dass nach dem Entsperren eines zuvor
gesperrten Reglers ggf. zusaetzlich "Setze Regler Heizen"
notwendig ist, um dessen regulaere Ausgangssteuerung
wiederaufzunehmen. Die bisherige reine FREE-Boolean- und
S1Target-Rueckmeldung beweist den Heizungs-Wiederanlauf
NICHT. Der Softwarecode sendet daher kein geratenes,
undokumentiertes LCN-Heizen-Kommando. Stattdessen ist eine
explizite Heizwiederanlauf-Abnahme vorgesehen
(CoolingHeatingResumeConfirmed, standardmaessig FALSE).
Nach dem kontrollierten LCN-Sperrtest muss vor Ort bei
tatsaechlich anliegender Heizanforderung nachgewiesen werden,
dass der LCN-Regler den Relaisausgang wieder selbststaendig
steuert. Erst anschliessend diesen Schalter bestaetigen.

Wegen des automatischen Heizbetriebs gilt vor und nach
S1-Tests die unveraenderte Verantwortung der LCN-Heizregler.
Kuehlwasser darf waehrend der Regler- und Relais-Pruefungen
NICHT in die Fussbodenheizung eingespeist werden.

- Softwarefreigabe (`CoolingSafetyVariable`) ist nun
  Pflicht statt "optional". Ohne Boolean TRUE bleibt
  KUEHLEN blockiert.
- `CoolingHardwareInterlockConfirmed`: notwendige physische
  Abnahme des unabhaengigen hydraulischen Taupunktwaechters.
- `CoolingHeatingResumeConfirmed`: notwendige separate
  Abnahme des realen Heiz-Wiederanlaufs nach Entsperrung.
- Die neuere Diagnose nennt technisch zugeordnete
  S1-/Relaisinstanzen trotz fehlender Kuehlfreigabe
  DIREKT VORBEREITET statt irrefuehrend ALTE A7/A8.
- Kein Rueckfall auf die alte A7/A8-Kuehlsteuerung.
- Im laufenden KUEHLEN wird bei fehlender Softwarefreigabe
  versucht, alle offenen NC-Ventile zu schliessen; eine
  hydraulische Sperre MUSS unabhaengig wirken.
- Bei Fehlern eines neuen S1- oder Relaisauftrags
  versucht das Modul OFF-first, anschliessend erst S1-FREI.
  Bei einem Abbruch werden fuer noch gesperrte Raeume
  OFF-only-Arbeiten eingeplant.
- Die Modifikation veraendert keine Hardware und greift
  im Zustand HEIZEN nicht selbsttaetig ein.
- Eine leere GitHub-Statusmeldung ist keine bestaetigte
  PHP-CI-Gruenmeldung. Reale Funktionstests bleiben offen.

Offizielle Anbindung: 
https://www.symcon.de/de/service/dokumentation/modulreferenz/geraete/lcn/lcn-requestread/
https://www.symcon.de/de/service/dokumentation/modulreferenz/geraete/lcn/lcn-requeststatus/
Bekannter FW-1F0715-Hinweis:
https://forum.lcn.eu/showthread.php?tid=1862

## 1. Produktiver HEIZEN-Modus

Unveraendert: 11 bestehende LCN-Regler, GT8 A7/A8 als kurze
TS-Tastensignale, S1Target als einziger echter Sollwert,
Raum-Kachel ohne neue sichtbare Variablen.
LCN steuert weiterhin die Stellantriebe. Kein zusaetzlicher
Sensor, kein neuer Regler, kein Aendern bestehender Fremdmodule.

## 2. KUEHLEN – neue einfache Betriebslogik

- Vor JEDEM Umschalten prueft Symcon: genau 11 gueltige
  S1-Regler- und Einzelrelais-Zuordnungen; bestandener
  echter Lock/Release-Test je Regler; bestandener
  EIN/AUS-Test je Relais; aktuelle native Statuswerte;
  externe Boolean-Kuehlfreigabe TRUE; vor Ort bestaetigter
  **unabhaengiger hydraulischer Taupunktschutz**.
- Fehlt auch nur eine Voraussetzung, bleibt Betriebsart
  **HEIZEN** aktiv und KUEHLEN wird abgelehnt.
- Kuehlstart: Fuer jeden Raum S1TargetLock ueber die
  native LCN-Variable mit `RequestAction(lockID,true)`
  sperren und eine separate LCN-Statusabfrage bestaetigen.
- Regler bleiben in KUEHLEN auch bei "Kuehlen AUS"
  GESPERRT, damit die Heizregelung den Relaisausgang
  nicht selbststaendig wieder einschalten kann.
- Danach genau **ein** zugeordnetes Einzelrelais
  je Raum schalten: KUEHLEN EIN = Ventil bestromt /
  AUF; KUEHLEN AUS = stromlos / ZU.
- Nur nach aktuellen Relais-Rueckmeldungen
  Zustandswechsel als erfolgreich melden.
- Zurueck auf HEIZEN: Erst jedes NC-Ventil AUS und
  AUS-Rueckmeldung bestaetigen, danach S1-Freigabe
  via `RequestAction(lockID,false)`; dann erst
  normaler S1-Sollwert bzw. die bewaehrten GT8-TS-Befehle.
- Bei Statusfehlern OFF-first-Sicherungsversuch und
  keine ungetestete A7/A8-Kuehl-Endlagenfahrt.
- 750 ms Mindestabstand zwischen aktiven LCN-Busaktionen.
  Rotierende Einzelraum-Jobs statt 11 parallelen
  Regler-/Relaiskommandos.

## 3. Inbetriebnahme – kein automatischer Sammeltest

Die vorhandene Konfiguration ist **11/11 eindeutig**:
EG Bad, EG Buero, EG Gaeste WC, EG Kueche, EG Schlafen,
EG Wohnen, OG Ankleide/HWR, OG Bad, OG Flur,
OG Schlafen 1 und OG Schlafen 2.
Einzig M11/EG Buero ist per `RequestAction(#46746,true/false)`
bereits am 23.10.2026 nachweislich bestanden.
Die anderen zehn S1-Regler sowie alle elf Relais
muessen am realen LCN-Bus geprueft werden.

Zwei neue Aktionen in der Modulkonfiguration:

1. `Inbetriebnahme: naechsten S1-Regler pruefen`:
   nur ein Raum; im HEIZEN-Betrieb S1 SPERREN,
   LCN_RequestRead pruefen, S1 FREIGEBEN und
   Rueckmeldung/Sollwert pruefen. **Kein Relaisbefehl.**
   M11 wird wegen bereits bestandener Pruefung
   automatisch uebersprungen.
2. `Inbetriebnahme: naechstes Ventilrelais testen`:
   erst nachdem alle 11 S1-Regler erfolgreich
   getestet wurden; im HEIZEN-Modus S1 SPERREN,
   ausschliesslich das zugeordnete Relais kurz
   EIN und Rueckmeldung pruefen, zwingend AUS
   und Rueckmeldung pruefen, erst DANACH S1 FREI.
   Der Test bestromt das betreffende NC-Ventil
   kurzfristig! Nur vor Ort unter Aufsicht und
   **ohne Kaltwasserzufuhr** pruefen.
   Bei derzeit aktivem Heizrelais (z. B. OG Bad)
   wird der Raum uebersprungen, bis das
   Heizrelais natuerlich AUS meldet.

Test- und Freigabe-Zustand werden platzsparend
in Modul-Attributen abgelegt; keine neuen
sichtbaren Symcon-Variablen. Einmal je Raum
explizit den naechsten Test anklicken, dann
Teststatus auslesen. Keine unbeaufsichtigte
11-Raum-Automatik und keine Massenschaltbefehle.
Bei Update waehrend einer aktiven Relaispruefung
erfolgt zuerst Relais AUS und erst danach S1 FREI.

Eine bestehende Aktion zeigt den allgemeinen
Sperrstatus; die neue `Inbetriebnahme anzeigen`
zeigt die Nachweise getrennt als S1 x/11 und
Relais y/11 sowie den Grund, warum KUEHLEN noch
gesperrt ist. Der Bericht `Senden an ChatGPT`
enthaelt dieselben Abnahmezaehler.

## 4. Wasserseitige Sicherheit – unabhaengige Pflicht

- Externen Taupunktwaechter fachgerecht montieren.
  Der Waechter muss **unabhaengig von Symcon**
  die hydraulische Kaltwasserfreigabe unterbrechen,
  bevor Tauwasser entstehen kann.
- Die Kuehlwasserfreigabe zusaetzlich in Symcon
  als vorhandene Boolean-Variable verknuepfen
  (`CoolingSafetyVariable`, TRUE freigegeben,
  FALSE nicht freigegeben).
- Nach physischer Funktionspruefung den Checkbox-
  Eintrag `CoolingHardwareInterlockConfirmed`
  **bewusst** auf JA setzen. Nicht vorab setzen.
- Sinkt die Softwarefreigabe auf FALSE oder wird
  die Hardware-Abnahme widerrufen, versucht das
  Modul offene Einzelrelais zu schliessen.
  Entscheidend bleibt die **unabhaengige
  mechanische/hydraulische Abschaltung**.
- Die reale Ventilbewegung kann aus einem
  elektrischen Relaisstatus allein NICHT
  sicher abgeleitet werden. Bei jedem
  Einzelrelais-Test vor Ort pruefen.

Keine Einstellung "minimal 17/18 Grad C" ersetzt
den echten Taupunktwaechter bei erhoehter
Luftfeuchte. Bei Feuchtraeumen/Kuechen
die Nutzungsfreigabe separat fachlich bewerten.

## 5. Tests und Rollback

Neue Tests `tests/test_cooling_commission.php`
und bestehende Mock-Tests simulieren die
verschachtelten S1-/Relais-Protokolle,
R1/S1-Validierung und die Freigabesperre.
GitHub CI lintet alle PHP-Dateien.
Der Entwicklungsstand bedeutet **keine
automatische Hardwarefreigabe** und keine
behauptete bestandene Live-Pruefung.

Bei Softwarefehlern HEIZEN weiterbetreiben,
keine Kuehlwasserzufuhr erlauben und den
letzten bestandenen Stand 0.3.23
ueber GitHub-Commit wiederherstellen.
Im Fall noch gespannter S1-Regler oder
unklarer Relaisstatus zuvor die spezielle
Not-AUS/S1-Freigabe abschliessen.

---
## Ueberlieferte Dokumentation

Die folgenden Abschnitte enthalten teils historische
Betriebsablaeufe frueherer Versionen. Bei Widerspruechen
gilt ausschliesslich der oben genannte aktuelle Stand.
Insbesondere wird Kuehlung derzeit **nicht** automatisch
freigegeben oder als getestete Funktion bezeichnet.

## Installation über GitHub

1. ZIP entpacken.
2. In GitHub Desktop ein Repository z. B. `IPSymcon-LCNClimateControl` erstellen.
3. Den **Inhalt** des entpackten Ordners in das Repository kopieren (`library.json`, `LCNClimateControl`, `README.md`, `docs`, `tests`).
4. Commit und Push.
5. In IP-Symcon unter **Kern Instanzen → Modules** die öffentliche GitHub-Repository-URL hinzufügen.
6. Instanz **LCN Heizung / Kühlung** anlegen.
7. Räume konfigurieren und **Übernehmen**.

## Raumkonfiguration

Je Raum:
- Raumname
- natives LCN-Sendemodul (das Modul mit den GT8-Tasten)
- vorhandene Isttemperatur-Floatvariable
- S1Target-Floatvariable
- Tastentabelle (normal A)
- Öffnen/+1 °C (normal Taste 7)
- Schließen/-1 °C (normal Taste 8)

## Prozesssicherheit

- immer nur **ein** LCN-Tastenbefehl zur Zeit
- standardmäßig 900 ms Wartezeit nach jedem Tastendruck
- echte S1Target-Rückmeldung vor dem nächsten Schritt
- Kühl-Endlage erst nach mehrfach bestätigter unveränderter Rückmeldung
- harte Maximalzahl an Tastendrücken verhindert Endlosschleifen
- keine `IPS_Sleep()`-Schleifen
- keine Befehle in `ApplyChanges()`
- keine direkte Relaissteuerung
- kein `LCN_SetTargetValue()` / `LCN_ShiftTargetValue()`
- lokale GT8-/LCN-Bedienung bleibt jederzeit unabhängig funktionsfähig

## Version
- Library GUID: `{1623F760-7CBB-4DDE-B08F-7625BCFC0278}`
- Modul GUID: `{209F110B-3209-4726-BEC9-12E9223F667B}`
- Version: 0.2.10


## Kompakte Kachel ab 0.2.1

Die Instanz verwendet eine eigene HTML-SDK-Kachel. Die bewährten Symcon-Objekte bleiben gleichzeitig unter der Instanz erhalten:

- `Betriebsart`
- pro Raum Link `Ist`
- pro Raum Variable `Soll`
- pro Raum Variable `Kühlung`

Diese Objekte dienen Diagnose und Fallback. Die eigentliche Bedienung erfolgt kompakt in der eigenen Kachel.

### Heizbetrieb
Eine Zeile pro Raum:

`Raum | Solltemperatur-Regler | Soll | Ist`

### Kühlbetrieb
Eine Zeile pro Raum:

`Raum | Nicht kühlen / Kühlen | Ist`

Unter allen Räumen:

`Kühlen = FHB-Ventil geöffnet · Nicht kühlen = FHB-Ventil geschlossen`

### Update-Hinweis 0.2.0 → 0.2.1
Version 0.2.0 verwendete einen falschen Visualisierungstyp und entfernte die Diagnose-/Bedienobjekte. 0.2.1 stellt die Objekte beim nächsten `Übernehmen` automatisch wieder her und verwendet den stabilen HTML-SDK-Kacheltyp.

## Visu-Kopf

Der obere Bereich der HTML-Kachel bleibt Symcon vorbehalten. Der Instanzname wird
ausschließlich von Symcon dargestellt; die eigene Zeile **Betriebsart** beginnt
mit Abstand darunter und überlagert den Kacheltitel nicht.

## Visualisierungs-Performance 0.2.3

Die HTML-SDK-Kachel arbeitet inkrementell: Bus- und Temperaturmeldungen aktualisieren nur
die betroffenen Texte, Slider und Buttonzustände. Der DOM wird nicht mehr bei jedem
LCN-Schritt vollständig neu erzeugt. Die LCN-Befehle selbst bleiben bewusst langsam und bestätigt.

## Bedientransport 0.2.4

Die HTML-Kachel serialisiert Bedienbefehle, puffert schnelle Folgewünsche und bestätigt
Aktionen über die vom Modul zurückgesendeten Zustands-Patches. Ein Betriebsartwechsel
kann auch während einer laufenden LCN-Fahrt angefordert werden; er wird an der nächsten
sicheren Zustandsgrenze ausgeführt.

## Kachel-Scrollarchitektur 0.2.5

Der Symcon-Titelbereich und der scrollbare Modulinhaltsbereich sind strikt getrennt.
Der Titelbereich besitzt einen opaken Kartenhintergrund; nur der darunterliegende
Inhalt scrollt. Dadurch können Raumzeilen nicht mehr unter den Instanznamen laufen.

## Sticky Betriebsart 0.2.6

Die Zeile **Betriebsart / Heizen / Kühlen** bleibt beim Scrollen direkt unter
dem Symcon-eigenen Instanznamen sichtbar.

## Parallelbedienung 0.2.8

Queue und Worker sind gegen zeitgleiche Visualisierungsaktionen serialisiert.
Pro Raum kann nur ein Auftrag existieren. Round-robin erhält den vollständigen
WAIT-Zustand jedes Raums; global werden konservativ höchstens etwa vier
TS-Befehle pro Sekunde erzeugt.

## Stabilitätsaudit 0.2.9

Die Laufzeit trennt Raumfehler voneinander, serialisiert UI/Worker und den eigentlichen
LCN-Sendecall, erhält schnelle Bedienaktionen verschiedener Räume und prüft die native
LCN-Modul-/LCN-Value-Zuordnung strenger. Ein einzelner Raumfehler stoppt die restlichen
Räume nicht mehr.

## Version 0.2.10 – Konfigurationsdiagnose und Bedien-Sicherheit

- Ungültige Raumzuordnungen werden mit Raumname und beteiligten IDs konkret gemeldet.
- Keine fatalen Bedien-Dialoge durch den bewusst blockierten Betriebsartwechsel bei Konfigurationsfehlern. Der Fehler steht in der Kachel und im Modul-Debug.
- Worker blockiert auch bereits anstehende Aufträge, solange die Konfiguration fehlerhaft ist.
- Smartphone-/Browser-Kachel behandelt verspätete Fehlerrückmeldungen ohne Rollback neuer Aktionen.
- PHP-/Node-Regressionstests und gemockte IP-Symcon-Laufzeit für Heizen → Kühlen → Heizen sind im GitHub-Workflow enthalten.
- Bekannte Grenzen: Die S1Target-basierte Endlagenerkennung beweist keine mechanische Ventilstellung; reale SymBox-/GT8-Tests bleiben nötig.



## Direktkuehlung (separate Testversion 0.3.0-rc1)

Die bestehende Visualisierung, der GT8 und die Heizfahrten ueber A7/A8 bleiben gleich. Die neue Funktion ist **standardmaessig AUS** (DirectCoolingEnabled=false): Somit arbeitet das Modul bis zur expliziten Freigabe exakt wie v0.2.10.

Bei expliziter Aktivierung und vollstaendiger Konfiguration pro Raum: Regler 1 sperren, Entsperrt-Feedback pruefen, ausschliesslich das zugeordnete Relais mit 8-stelliger Maske steuern, Relais-Feedback pruefen. Bei Rueckkehr in den Heizbetrieb: Relais schliessen, Regler entsperren, Heizsollwert ueber bestehende TS-Logik wiederherstellen. Fehlendes Feedback stoppt den betroffenen Raum und erfordert Vor-Ort-Pruefung. Im Sperrzustand darf das Modul keinesfalls ohne Wiederherstellung auf 0.2.10 zurueckgesetzt werden.

Details siehe docs/DIREKTKUEHLUNG_ROLLBACK.md. Es ist eine **RC/Testfassung**, kein produktiver Freigabestand: Reglerindex R1/S1, Relaispolaritaet, Zustand nach Neustart und Taupunktsperre muessen an M11 nachgewiesen werden.


## 0.3.1-rc1 – Automatische Rueckfallsteuerung (separater Entwicklungsbranch)

Mit aktivierter Option `DirectCoolingEnabled` prueft das Modul bei Wahl von Kuehlen pro Raum, ob native Regler- und Relaisfunktionen, Zuordnungen, Rueckmeldungen sowie die externe Freigabe vorhanden sind. Fehlende Voraussetzung: bisherige A7/A8-Kuehlung und kleine Statusmeldung unten in der Kachel. Scheitert der Direktversuch NACH einer moeglichen Reglersperre, sendet Symcon zuerst die Reglerfreigabe und wartet auf die Rueckmeldung; nur nach positivem Nachweis wird die alte Logik verwendet. Bei nicht bestaetigter Freigabe keine automatische Umschaltung! `main` und das Backup bleiben unveraendert. Die alte Methode ist KEIN Nachweis geoeffneter Kuehlventile bei hohen Raumtemperaturen. 

## 0.3.2 – Automatische Erkennung je Raum (aktuelle Version)

Das Modul kann unmittelbar ueber das Update installiert werden. In einer
bestehenden Installation mit unveraenderten Symcon-/LCN-Einstellungen nutzt
es weiterhin die klassische Kuehlung mit A7/A8. Heizregelung, GT8,
Kachelbedienung, Raumobjekte und gespeicherte Solltemperaturen bleiben erhalten.

Bei jeder erneuten Auswahl von Kuehlen evaluiert das Modul **jeden Raum
separat**. Direkte Sperr-/Relaissteuerung wird nur aktiviert, wenn ALLE
folgenden Voraussetzungen nachweisbar sind:
- die native LCN-Sendereglerinstanz und die native Relais-Splitterinstanz
  sind korrekt in Symcon verbunden und erreichbar;
- der passende Reglerindex sowie native Boolean-Variable "Entriegelt"
  und die Boolean-Rueckmeldung des EINZELNEN Relais sind hinterlegt;
- die EIN/AUS-Ventilpolaritaet wurde ausdruecklich bestaetigt;
- eine externe Boolean-Kuehlfreigabe liegt vor und ist TRUE;
- der Heizregler meldet vor dem ersten Sperrversuch "Entriegelt".
Erst nach erfolgreichem Sperrbefehl und bestaetigtem Zustand wird ein
Einzelrelaiskanal geschaltet und dessen Rueckmeldung ueberprueft.

**Ohne diese Voraussetzungen erfolgt niemals ein probeweises Sperren.**
Stattdessen laeuft der bisherige A7/A8-Kuehlbetrieb inklusive einer
kleinen Fussnote in der Kachel. Nach einem Direktsteuerungsfehler wird
erst nach bestaetigter Reglerfreigabe und normalem S1Target automatisch
zur alten Methode zurueckgekehrt. Bei unklarer Freigabe stoppt der Raum.

LCN-PRO selbst stellt seine interne Option "Reglersperre erlaubt" nicht
ueber die dokumentierte Symcon-API bereit. Eine Aenderung dieser
Konfiguration kann daher **nicht allein** eine automatische Freischaltung
bewirken: die einmalige eindeutige Verbindung der 11 Relaiskanaele
samt Rueckmeldeobjekten und der Sicherheitsfreigabe in Symcon ist weiterhin
notwendig. Danach erfolgt die Zuordnung bei jedem neuen Kuehlzyklus
automatisch ohne weitere Eingriffe.

**Taupunktschutz:** 17 Grad Mindestwasser allein schuetzt nicht gegen
Kondensation. Die Kuehlhydraulik braucht einen eigenstaendigen
sicherheitsgerichteten Taupunkt-/Kondensatschutz; ein Symcon-Bool allein
ist keine sichere Hardwareverriegelung.

Rollback auf den unveraenderten Stand v0.2.10:
`backup/v0.2.10-pre-cooling-2026-10-21`
(siehe `docs/DIREKTKUEHLUNG_ROLLBACK.md`).

## 0.3.3 – Automatische LCN-Verknuepfung ohne manuelle 11-fache IDs

- Aus der LP3-Datei bekannte physische Relaiszuordnung M11..M151 -> M92/M192/R1..R6.
- Sendemodul und S1Target stehen bereits in der bestehenden LCNClimateControl-Raumliste.
- Das Modul erkennt bei jedem Kuehlzyklus die korrespondierende native Symcon
  LCN-Unit (Typ Relais = Unit 2), Status, S1Target-Entriegelt, Segment/Gateway.
- Konfigurationsknopf "LCN-Zuordnungen aus Symcon auslesen" listet alle live
  Symcon-IDs **nur lesend**. Bekanntes Beispiel aus frueheren Tests:
  EG Buero LCN-Modul #37617; EG Bad S1Target #21099,
  Entriegelt #19580. Diese IDs werden zur Vermeidung veralteter
  Zuordnungen NICHT hartcodiert.
- Die Stellantriebe sind **NC/stromlos geschlossen**. Daher liegt
  werkseitig Relais EIN = Ventil AUF als Vorschlag vor. Aber: ein
  NC-Stellantrieb sagt allein nicht aus, ob der entsprechende Relaiskontakt
  als Schliesser oder Oeffner verdrahtet wurde. Darum muss der Haken
  "Stellantriebe/Relaissinn geprueft" einmalig gesetzt werden, bevor ein
  Direktbefehl freigegeben wird. Ohne Haken bleibt A7/A8 aktiv.
- Es erfolgt KEINE unkontrollierte Selbsterprobung durch Sperren.
  Geprueft werden native Instanzen, eindeutige Rueckmeldungen und
  Rueckfallbedingungen pro Raum.
- Hardware-Taupunktsicherung geplant in
  `docs/TAUPUNKTSCHUTZ_KUEHLKREIS.md`:
  potentialfreier Feuchtesensor-/Taupunktwächter-Schaltkontakt,
  stromlos schliessende Kuehlkreisfreigabe und hydraulische
  Mindest-Vorlauftemperatur. Nichts davon wurde vor Ort eingebaut.
- Im Symcon-Modul selbst lediglich schriftlicher Hinweis. Software-Eingang
  zur Kuehlfreigabe bleibt optional; falls konfiguriert, wird er geprueft.


## v0.3.4 Build 19 – NC-Relais und Rueckmeldungen automatisch

Vom Anlagenbetreiber verifiziert: Relais EIN legt Spannung am
NC-Stellantrieb an; Ventil oeffnet. Daher braucht die automatische
LCN-Erkennung **keinen zusaetzlichen Polaritaetshaken**. Dies gilt fuer die
in der LP3 analysierten elf Raum/Relais-Zuordnungen.

Der bestehende Raum-Eintrag in der Symcon-Modulinstanz liefert bereits
`SendModule` sowie `S1Target`. Der neue Discovery-Code liest zugehoerige
native Symcon-Instanzen auf der laufenden SymBox **nur lesend**:
LCN Hardware-Target, Segment, gleiche Gateway-Verbindung, Relais-Unit
(Unit=2, Channel=1..8), Float-S1Target, Boolean-Entriegelt,
Boolean-Relais-Status. Zuerst wird der stabile technische ObjectIdent,
erst dann der Anzeigename verwendet. Mehrfachtreffer oder fehlende
Statusvariablen -> alte A7/A8-Kuehlung, ohne Reglersperre.

**Wichtig:** Aus einem GitHub-Quellcode-Backup oder einer .lp3-Datei
sind aktuelle Symcon-Instanz-IDs der laufenden SymBox nicht auslesbar.
Das Modul ermittelt sie in seiner eigenen lokalen Runtime; ich habe
von hier keinen direkten Live-Zugriff auf die SymBox.

Per Knopf `LCN-Zuordnungen aus Symcon auslesen (nur Diagnose)`
kann der aktuelle Live-Zuordnungsstand ohne Stellbefehl ausgegeben werden.

Die alte A7/A8-Steuerung ist ein Kompatibilitaetsrueckfall, aber bei
hohen Raumtemperaturen kein Nachweis geoeffneter Ventile.
Die unabhaengige mechanische Taupunktsperre bleibt vor realer
Kuehlwasserversorgung erforderlich.


## v0.3.5 Build 20 – Sicherheits- und Regressionstest vor Installation

Die komplette v0.3.4-Heizbedienung, GT8-Verknuepfung, A7/A8-Heizsteuerung
und die Kachelvisualisierung bleiben unveraendert. 0 neue LCN-Tasten,
0 neue Symcon-Laufzeitvariablen. Der Stand v0.2.10 bleibt auf Branch
`backup/v0.2.10-pre-cooling-2026-10-21` wiederherstellbar.

Korrekturen gegenueber 0.3.4:
- Bei `Nicht kuehlen` erfolgt im entsperrten Zustand KEINE unnoetige
  direkte Reglersperre; das bestehende A8-Verfahren schliesst den Raum.
- Nach der ersten geplanten Reglersperre wird die **exakt erkannte** LCN-
  Zuordnung als internes persistentes Attribut abgelegt. Damit bleibt
  die Freigabe nach fehlgeschlagener Neusuche oder Modulneustart moeglich.
  Die interne Kopie wird erst nach bestaetigter Freigabe entfernt.
- Ein gescheiterter Direktkuehlungs-Versuch fuehrt ausschliesslich nach
  folgender Folge zur alten Steuerung zurueck:
  **Relais elektrisch AUS bestaetigt -> Regler freigegeben bestaetigt ->
  S1Target plausibel und frisch -> alter TS-Befehl.**
  Sonst kein automatischer Rueckfall; der betreffende Raum zeigt Fehler.
- Native Statusabfragen `LCN_RequestStatus` am Relaismodul sowie
  `LCN_RequestRead` an der bestehenden LCN-Value-Instanz helfen
  bei fehlender oder unveraenderter Rueckmeldung, max. einmal in 9 s
  je laufendem Auftrag. Keine zusaetzlichen Busobjekte.
- Der Zeitgrenzfehlertext entspricht der tatsaechlich konfigurierten,
  von der Raumanzahl abhaengigen Rueckmeldezeit.

Simuliert im PHP-Test: neue Direktsteuerung mit Sperr-/Relaisfeedback,
komplette Rueckkehr in den Heizbetrieb, alle 11 Raeume, Autowechsel und
Fehlerfaelle. Github-CI prueft zudem die bestehenden Heiz- und
Visualisierungsregressionen. Dies ersetzt **keine echte
LCN-Firmware-Pruefung**.

Mechanischer Taupunktschutz vor Freigabe realen Kuehlwassers erforderlich.
17 Grad C Mindest-Vorlauf allein ist nicht ausreichend. Der Hinweis
bleibt in der Kachel; Schutzkette nicht auf Symcon verlaengern.


## v0.3.6 Build 21 – Diagnostik der fehlenden Relais-Zuordnungen

Ein Live-Bericht vom 22.10.2026 erkannte 11 korrekte LCN-Sendemodule
und die folgenden S1Target Variablen, aber keinerlei native LCN-Relais-
Rueckmeldezuordnungen. Alle Räume blieben auf dem bewährten A7/A8-Weg.

Die Schaltlogik bleibt von 0.3.5 UNVERAENDERT. Neu ist die detaillierte
read-only Fehlersuche pro Raum hinter dem bestehenden Konfigurationsknopf
`LCN-Zuordnungen aus Symcon auslesen (nur Diagnose)`:
- Prueft LCN-Sendeinstanz: Hardware-Target, Segment und Gateway.
- Findet native Relais-Splitter M92/M192 exakt in demselben Segment/Gateway.
- Zeigt ALLE LCN-Units und deren tatsaechliche Unit-/Channel-Konfiguration.
- Unterscheidet fehlende Relay-Unit, falsche Unit-Konfiguration,
  fehlenden Boolean-Status, fehlende LCN-Value-Verknuepfung und
  fehlende Entriegelt-Rueckmeldung.
- Es erfolgt KEINE automatische Aenderung an Symcon-Instanzen oder LCN.
- Kein Statuswert in diesem Bericht darf eine wirkliche
  Reglersperre oder den elektrischen Relaiszustand ersetzen.

Symcon IDs aus dem bereits uebermittelten Bericht:
EG Bad: Sender 15856 / S1Target 21099
EG Buero: Sender 37617 / S1Target 34107
EG Gaeste WC: Sender 57174 / S1Target 10099
EG Kueche: Sender 30987 / S1Target 30507
EG Schlafen: Sender 12826 / S1Target 29239
EG Wohnen: Sender 37476 / S1Target 59161
OG Ankleide / HWR: Sender 41134 / S1Target 58217
OG Bad: Sender 32128 / S1Target 55084
OG Flur: Sender 11331 / S1Target 15371
OG Schlafen 1: Sender 13700 / S1Target 49519
OG Schlafen 2: Sender 41951 / S1Target 34041

Bestehende 0.2.10 Backup-Version bleibt unveraendert. Kein physischer
Kuehlwasserbetrieb ohne unabhängige mechanische Taupunktsicherung.


## v0.3.7 Build 22: fehlende LCN-Relais-Units per Knopfdruck vorbereiten

Der Diagnosebericht von 22.10.2026 bestaetigt beide vorhandenen
Symcon-LCN-Module M92 (Instanz #36930) und M192 (Instanz #25610),
aber NULL native Relais-Units an diesen Modulen. Alle elf Raeume bleiben
zunaechst korrekt auf A7/A8.

Die bestaetigte 11-fache LP3-Relaiszuordnung ist fest im Modul vorhanden.
Im Modul-Konfigurationsfenster bei Betriebsart HEIZEN und ohne laufende
Schaltauftraege den neuen Knopf
**„Fehlende 11 LCN-Relais-Units anlegen“** betaetigen.

Sicherheitsregeln des Knopfes:
- Vollstaendige read-only Vorpruefung aller vorhandenen Sender und
  korrespondierenden Relaismodule, Segment/Gateway/Hardware-Target.
- Neue native LCN-Unit-Instanzen nur fuer tatsaechlich fehlende
  Einzelrelais, maximal 11; Eigenschaft Unit=2, Channel=R1..R6,
  Verbindung zu M92/M192 (die vorhandenen Splitter bleiben unveraendert).
- Neue Instanzen befinden sich gesammelt unter dem Klimamodul,
  keine elf neuen Kategorien und kein Eingriff in andere Module.
- Keine LCN-Schaltkommandos oder Reglersperren bei der Vorbereitung.
- Bei laufendem Auftrag, KUEHLEN oder gesperrtem Regler: Abbruch.
- Bei Fehlern: neu erzeugte Units werden zurueckzusetzen versucht;
  vorhandene Instanzen werden nie geloescht oder umgeschrieben.
- Wiederholtes Klicken legt keine Duplikate an.

Im Anschluss den bestehenden Button „LCN-Zuordnungen aus Symcon auslesen“
benutzen. Die Rueckmeldung „Status“ der Relais wird durch die native
LCN-Unit zur Verfuegung gestellt. Ob die native „Entriegelt“-Boolean-
Variable auf den vorhandenen LCN-Value-Instanzen existiert, muss der
neue Diagnosebericht erst nachweisen.

**Keine automatische hardwareseitige Schaltfreigabe durch das
Einrichten von Symcon-Instanzen.** Die echte Regler-Sperr-/Freigabe-
Funktion bleibt bis zum Praxistest nicht bestaetigt. Rueckfall auf
alte A7/A8-Steuerung bleibt erhalten, sofern keine direkte
Reglersperre erfolgt ist.


## v0.3.8 Build 23 – Native Reglersperren-Rueckmeldung korrigiert

Bestaetigter 11-Raum-Livebericht nach erfolgreichem Einrichten nativer
M92/M192-Relais-Units:
- Die zugehoerige Symcon LCN-Value-Instanz besitzt jeweils die
  native Boolean-Variable mit **ObjectIdent=S1TargetLock**.
- Symcon zeigt als Label "Zieltemperatur S1"; dieses LABEL ist
  nicht als Bool-Bedeutung geeignet.
- **S1TargetLock TRUE = REGELSPERRE AKTIV (gesperrt).**
- **S1TargetLock FALSE = REGLER FREIGEGEBEN.**

Fuer automatische LP3-Erkennung wird zwingend zuerst das native
ObjectIdent S1TargetLock (mit richtiger Polaritaet) gesucht.
Nur die bewusst manuelle, anders benannte Rueckmeldung
"Entriegelt" verwendet weiterhin TRUE=freigegeben.

Die neue Regler-Zustandsmaschine verwendet diese Polaritaet in
folgenden Phasen: Praeflight, Sperren, Bestatigen der Sperre,
vor Relais-EIN, Freigabe nach Relais-AUS, Freigaberueckmeldung
und Fehler-Rueckfall. Kein Rueckfall auf A7/A8, bevor der
Regler nachweislich freigegeben ist.

Automatische Erkennung der elf native Relais-Units bleibt wie
in v0.3.7. Die PHP-Testreihe prueft den direkten Betrieb unter
S1TargetLock FALSE->TRUE->FALSE sowie die Kompatibilitaet mit
vorhandenen manuellen "Entriegelt"-Zuordnungen.
**Der reale LCN-Firmwaretest ist weiterhin ausstehend.**

Alle elf aktuellen native Symcon-Bool-Rueckmeldungen:
EG Bad 19580; EG Buero 46746; EG Gaeste WC 32130;
EG Kueche 53830; EG Schlafen 18533; EG Wohnen 38100;
OG Ankleide/HWR 24239; OG Bad 59442; OG Flur 31648;
OG Schlafen 1 46283; OG Schlafen 2 33374.


## v0.3.9 Build 24 – Reglersperre ohne Eingriff lesen

Die Regelung unterscheidet drei Sachverhalte strikt:
1. **Aktuelle Sperrung:** `S1TargetLock` an der vorhandenen nativen
   LCN-Value-Instanz; TRUE=gesperrt, FALSE=freigegeben.
2. **Aktualitaet:** `VariableUpdated` zeigt, wie alt die empfangene
   Rueckmeldung ist. Ohne Aktualisierung kann der Zustand NICHT
   sicher aus einer vorgabemaessigen Boolean-Variable abgeleitet werden.
3. **LCN-PRO-Option „Regler-Sperrung erlaubt/verboten“:** Dieser
   Konfigurationsparameter ist ueber die dokumentierte IP-Symcon
   LCN-Leseschnittstelle **nicht auslesbar**. „Regler gerade frei“
   ist kein Nachweis der Erlaubnis.

Neue Schaltflaechen im Modul:
- `LCN-Reglersperren: aus dem LCN-Bus abfragen (nur Leseanfragen)`:
  sendet maximal 11 reine native `LCN_RequestRead`-Anfragen
  an die bereits vorhandenen Value-Instanzen. Mit kurzem Bus-Abstand,
  ohne Reglersperre, Sollwert- oder Relaisbefehl.
- `LCN-Reglersperren: Zustand und Aktualisierung aller 11 Räume anzeigen`:
  liefert pro Raum den aktuellen Boolean-Rueckmeldestand, ID,
  gemeldete Bedeutung, Zeitstempel, Alter sowie einen deutlichen
  „Sperrung erlaubt: nicht auslesbar“-Hinweis.
  Ohne Status-/Verbindungsnachweis oder bei fehlender
  Erstmeldung wird der Zustand als unbekannt behandelt.

Das vorhandene Projekt `JV_2025_Test EG Kamin nur an wenn sonst alles aus.lp3`
enthielt 11 konfigurierte Regler; die exakte herstellerspezifische
Bitzuordnung des Parameters „Sperrung erlaubt“ wurde bislang nicht
sicher dekodiert. Eine Offline-Projektdatei ist ohnehin kein
garantierter Nachweis fuer den aktuellen Zustand auf dem LCN-Bus.

Die Option selbst laesst sich in LCN-PRO am entsprechenden Regler
als „Regler-Sperrung erlaubt/verboten“ kontrollieren. Ob die
laufende Firmware die Sperre und Rueckkehr in die Heizregelung
sauber verarbeitet, kann nur ein kontrollierter Funktionstest
bestimmen. Die normale A7/A8-Regelung bleibt unveraendert.

Kein Hardware- oder Software-Freigabehinweis dieses Berichts
ersetzt den unabhängigen mechanischen Taupunktschutz.


## v0.3.10 Build 25 – Eine Diagnosevariable „Senden an ChatGPT“

Nach der Aktualisierung erzeugt dieselbe LCNClimateControl-Instanz genau **eine** zusätzliche String-Variable:

- **Senden an ChatGPT** (String, technische Ident `ChatGPTDiagnostic`).
- Der mehrzeilige Inhalt wird **automatisch alle 60 Sekunden** und sofort bei `ApplyChanges` neu erstellt. Es braucht keinen Diagnoseknopf und keine manuelle Abfrage.
- Der Bericht fasst die Betriebsart HEIZEN/KUEHLEN, aktuellen Auftrag, Queue,
  Software-Kuehlfreigabe und **alle elf Räume** zusammen: Raumtemperatur,
  LCN-Sollwert, Kuehlwunsch, native LCN-Sendeinstanz und S1Target, Relais-Modul,
  einzelne Relay-Unit und Rueckmelde-ID, Boolean-Zustand und dessen Alter,
  S1TargetLock (TRUE=gesperrt), direkte/alte Regelart, Rueckfallgrund,
  interne Sperrvormerkung und eventuelle Fehlermeldung.
- Die automatische Diagnose **liest ausschliesslich vorhandene Symcon-Variablen und Attribute**.
  Sie sendet keine LCN-Kommandos, keine LCN-Statusabfragen und schaltet kein Relais.
- Die Einstellung `Regler-Sperrung erlaubt/verboten` in LCN-PRO ist
  nicht aus dem aktuellen `S1TargetLock` ableitbar. Die Diagnose kennzeichnet
  die Erlaubnis und den realen Hardwaretest deshalb weiterhin als unbestätigt.
- In der vorhandenen Kachel werden keine zusätzlichen Bedienelemente hinzugefügt;
  die String-Variable steht unter der Modulinstanz im Symcon-Objektbaum.
- Bestehende Diagnoseknöpfe bleiben optional bestehen; die einzelne Variable
  ersetzt sie für die normale Fehleranalyse.
- Eine unabhängige Taupunktabsicherung des Kühlkreises ist vor physischer
  Kühlwasserfreigabe weiterhin erforderlich.

**Nutzung:** Im Objektbaum unter `Heizen / Kuehlen` die Variable
`Senden an ChatGPT` öffnen, ihren kompletten Inhalt kopieren und in
ChatGPT einfügen. Nach weiteren Messungen wird die Variable automatisch aktualisiert.


## v0.3.11 Build 26 – „Senden an ChatGPT“ bleibt auf „Nie“

Fehler in 0.3.10 behoben: `LCNClimateControl` verwendet `IPSModuleStrict`.
Modulintern registrierte Variablen sind schreibgeschuetzt; die globale
Funktion `SetValueString` darf sie nicht veraendern. Die Diagnose
schreibt daher jetzt korrekt per `$this->SetValue('ChatGPTDiagnostic', $content)`.
Der automatische 60-Sekunden-Timer und das sofortige Update nach
`ApplyChanges` bleiben bestehen. Es wird weiterhin nur eine
String-Variable erstellt und es werden keine LCN-Kommandos gesendet.
Ein Regressionstest verbietet jetzt die globale Schreibfunktion bewusst.

Nach Modulupdate sollte `Senden an ChatGPT` innerhalb von ApplyChanges
sofort einen Bericht erhalten und dann automatisch minuetlich aktualisiert
werden. Heizungs- und Kuehlungssteuerung sind unveraendert.


## v0.3.12 Build 27 – Alle elf Kuehlraeume einzeln schaltbar

Konkrete Fehlermeldung in der Visu: OG Ankleide/HWR und OG Flur
sprangen nach `Nicht kuehlen` wieder auf `Kuehlen`.
Drei Ursachen wurden behoben:

1. **Heizbetrieb:** Die boolesche Kuehl-Vorwahl darf jetzt jederzeit
   geaendert werden, ohne den Heizbetrieb zu beeinflussen und ohne
   Relais, LCN-Regler oder TS-Tasten anzufassen. Sie wird als Wunsch fuer
   den naechsten Kuehlzyklus im bestehenden Attribut gespeichert.
2. **Raumfahrt noch aktiv:** Neue An/Aus-Wuensche werden nicht mehr mit
   `Vorheriger Kuehlbefehl noch nicht bestaetigt` abgelehnt, sondern
   je Raum auf den *neuesten* Wert zusammengefasst. Sobald die bisherige
   bestaetigte Fahrt beendet ist, wird die neue Stellung eingereiht.
   Die Kachel bestaetigt den angenommenen Wunsch, kennzeichnet den
   Raum bis zur physischen Rueckmeldung aber weiterhin als `busy`.
   Die Regler-/Relais-Rueckmeldungen bleiben die einzige Quelle fuer
   tatsaechliche Aktorstellung.
3. **Sperre ungewiss:** `Nicht kuehlen` darf das zugeordnete NC-Ventil
   ueber sein einzelnes LCN-Relais abschalten, ohne den regulatorischen
   Sperrzustand vorzutäuschen oder den Regler blind freizugeben.
   Erst nach positiver Relais-AUS-Rueckmeldung wird `Nicht kuehlen`
   bestaetigt; bei ausbleibender Rueckmeldung erscheint ein Fehler.
   Die gesonderte Warnung wegen unbekanntem Sperrstatus bleibt sichtbar.

Es entstehen keine weiteren Symcon-Variablen. Ein fluechtiger
`CoolingPendingRequests`-Puffer pro Modul fasst gleichzeitige
Aenderungen mehrerer Raeume zusammen. Die bestehende Heizsteuerung,
GT8, A7/A8, die Rueckfalllogik und die gesicherte v0.2.10-Backup-Version
bleiben erhalten. Die reale LCN-Firmwarefreigabe der Reglersperre
bleibt bis zum vor-Ort-Test unbestaetigt.

**Testfolge:** Modus HEIZEN – Kühlvorwahl der Räume umstellen,
keine LCN-Kommandos; danach im Kuehlbetrieb bei laufender
Raumfahrt ON/OFF wiederholt setzen – der letzte Wunsch muss
nach Abschluss der vorherigen Bewegung wirksam werden; bei
Sperrfehler nur NC-Relais AUS, kein blindes Entsperren.
Die physische Taupunkt-Abschaltung im Kuehlkreis ist unabhaengig
von dieser Bedienkorrektur weiterhin erforderlich.


## v0.3.13 Build 28 – Fehlgeschlagene Kuehlauftraege nicht automatisch wiederholen

Sicherheitskorrektur der Auftragswarteschlange: Falls ein Kuehl-Stellauftrag
aufgrund fehlender oder unplausibler LCN-Rueckmeldung scheitert, wird ein
waehrend der Fahrt vorgemerkter weiterer Benutzerwunsch **nicht** automatisch
als neuer Stellauftrag gestartet. Andernfalls konnten immer neue A7/A8-Fahrten
mit demselben Rueckmeldefehler entstehen. Der Raum behält seinen zuletzt
bestaetigten Kuehlzustand und zeigt den Fehler; zur erneuten Ansteuerung ist
ein neuer ausdruecklicher Bedienwunsch notwendig.

Bei erfolgreich quittierten Fahrten bleibt die in v0.3.12 eingefuehrte
Last-Request-Wins-Warteschlange unveraendert. Andere Raeume laufen unabhaengig
weiter. Keine neuen IP-Symcon-Variablen, keine neuen LCN-Kommandotypen,
keine Aenderung an GT8, Heiz-Sollwerten oder S1Target-Pruefung.

Quellcodekontrolle durchgefuehrt; ein echter Test an der SymBox/LCN-Hardware
ist weiterhin erforderlich. Die mechanische Taupunktsicherung ist fuer
reale Kuehlung weiterhin unverzichtbar.


## v0.3.14 Build 30 – LCN-Statusabfrage gegen Timeouts begrenzt

Die vorherige Aktion hat bis zu elf synchrone LCN_RequestRead-Aufrufe ausgefuehrt. Bei stockendem LCN-Server konnte die Bedienoberflaeche mit einer Zeitueberschreitung reagieren.

Die Abfrage bearbeitet jetzt maximal zwei LCN-Value-Instanzen pro Aufruf und setzt beim naechsten Klick an der naechsten Instanz fort. Lese-Fehler eines Raums werden getrennt ausgegeben. Keine Reglersperren, Freigaben oder Relaisschaltungen. Ein blockierender einzelner nativer LCN-Aufruf kann weiterhin verzögern.

Die LP3-Auswertung belegt die elf Regler und M92/M192-Relaiszuordnung. Der herstellerspezifische Parameter 'Reglersperrung erlaubt' ist nicht sicher dekodiert. Interne Sperr-Vormerkungen 'uncertain' werden nicht aufgrund eines alten passiven Snapshots geloescht. Vor einer Freigabe bleibt ein bestaetigter aktueller Funktionstest erforderlich.

Offline-Regression und echter SymBox-/Bus-Funktionstest stehen noch aus.


## v0.3.15 Build 31 – alte 'uncertain'-Vormerkungen kontrolliert bereinigen

Nach bestaetigter LCN-Bus-Leseabfrage vom 22.10.2026 meldeten OG Ankleide/HWR
(S1Target #58217, S1TargetLock #24239, Relaisstatus #24994) und OG Flur
(S1Target #15371, S1TargetLock #31648, Relaisstatus #49744) freie Regler,
plausible 21,0-Grad-Sollwerte und Relais AUS. Trotzdem blieben in der
Modulinstanz #17597 zwei alte persistente 'uncertain'-Vormerkungen bestehen.

Neuer Button in der Instanzkonfiguration:
**Interne Reglersperr-Vormerkungen prüfen und bereinigen (nur Software, keine LCN-Befehle)**

Die Aktion laeuft NUR im ruhenden HEIZBETRIEB und nur fuer Marker vom Typ
'uncertain'. Fuer jeden Raum werden die gespeicherte Zuordnung mit der
aktuellen nativen Discovery (Sender, Regler S1, Unit, Relaiskanal und
Rueckmelde-IDs), S1TargetLock FALSE, Einzelrelais AUS (NC-Ventil),
gespeicherter Kuehlzustand AUS sowie plausibler Float-S1Target ueberprueft.
Alle drei Rueckmeldungen muessen innerhalb der vergangenen 180 Sekunden
aktualisiert worden sein, hoechstens 120 Sekunden auseinanderliegen und
duerfen nicht vor dem letzten gespeicherten Fehlerintrag liegen.
Bei erfolgreicher Pruefung werden nur die alten internen Sperr- und
Fallback-Vermerke des konkreten Raums entfernt und der Diagnosebericht
sofort neu geschrieben. Beim ersten Fehler bleibt die Vormerkung bestehen;
der Button zeigt den konkreten Grund an.

KEINE LCN-Kommandos, KEIN Relaisbefehl, KEINE Sollwertverstellung, KEINE
automatische Heiz-/Kuehlmodus-Aenderung. Echte LCN-PRO-Sperrerlaubnis,
Reglersperr-Funktionstest und mechanischer Taupunktschutz bleiben
unabhaengige offene Sicherheitsvoraussetzungen fuer reale Kuehlung.

Auch korrigiert: Die rotierende LCN-Statusabfrage sendet am letzten
Durchlauf bei elf Raeumen nur noch eine Anfrage (11-11 statt 11-12),
und Zeilenumbrueche werden in der Ausgabe korrekt dargestellt.

Quellcode- und Strukturpruefung bestanden; vollstaendige automatische
PHP-Regressionssimulation und Hardwaretest stehen weiterhin aus.



## v0.3.16 Build 32 – Isolierter M11-S1-Sperr- und Freigabetest

Ausgangslage: Alle elf Raumregler melden derzeit FREI. Die Option
"Sperrung zulassen" war in LCN-PRO urspruenglich bei keinem Regler
aktiv. Der Anlagenbetreiber hat sie ausdruecklich NUR an M11 (EG
Buero Saeule, 000,011) aktiviert und online gespeichert. Der reale
Sperrbefehl wurde bisher nicht getestet.

Neuer, explizit per Button zu startender **M11-Einzelraumtest**:
- Nur im ruhenden HEIZBETRIEB, ohne laufende LCN-Auftraege oder offene
  Sperrvormerkungen.
- Verifiziert nativen Sender mit Hardwareziel M11, Reglerindex S1=2,
  M92/R1, echte S1TargetLock-Variable am selben LCN-Value und
  aktuelle Rueckmeldungen (<=120s) fuer freigegebenen Regler,
  Relais AUS und plausiblen S1Target.
- Fuehrt ausschliesslich LCN_LockTargetValue(M11,2) und danach
  LCN_ReleaseTargetValue(M11,2) aus: **KEIN Relaisbefehl,
  KEIN Sollwertbefehl, KEIN Kuehlwasserwechsel, KEIN Moduswechsel**.
- Verlangt nach dem Sperrbefehl S1TargetLock=TRUE mit aktualisierter
  Rueckmeldung. Auch bei Fehlschlag oder nicht eingetroffener
  Rueckmeldung folgt der Freigabeversuch.
- Meldet Erfolg nur bei nachfolgendem S1TargetLock=FALSE und
  nach der Freigabe erneut empfangenem plausiblem S1Target.
  Zwecks Sekunden-Zeitstempel muss die Freigabe-Rueckmeldung
  **nach** der Befehlssekunde aktualisiert worden sein.
- Rueckmeldezeitgrenze fuer Sperre 12 Sekunden; nach jeder
  Freigabe hoechstens 10 Sekunden warten und maximal drei
  Freigabeversuche. Ohne gueltige Rueckmeldung bleibt die
  interne `uncertain`-Vormerkung erhalten und eine Warnung sichtbar.
- Persistenter Sessionzustand: Ein Modul-Neustart oder ApplyChanges
  im laufenden Test fuehrt NUR in die Freigabephase zurueck; ein
  Sperrbefehl wird nicht wiederholt.
- Bedienung am Modul #17597 ist waehrend des laufenden Tests
  fuer Betriebsart und M11 gesperrt, die restlichen zehn Raeume
  bleiben unabhaengig steuerbar. Kein Eingriff in den GT8.
- Im Config-Fenster gibt es drei ausdrueckliche Aktionen:
  1. "M11 EG Büro: S1-Sperrtest starten (kurz sperren, automatisch wieder freigeben)"
  2. "M11 EG Büro: Sperrtest-Status anzeigen (nur lesen)"
  3. "M11 EG Büro: NOT-FREIGABE erneut auslösen (nur S1 entsperren)"
- Eine Zeile im bestehenden ChatGPT-Bericht meldet Testphase und
  Freigabeergebnis. Keine neuen sichtbaren Symcon-Variablen.

Vorgehen: Nur M11 darf die LCN-PRO-Option "Sperrung zulassen"
besitzen. Heizbetrieb und hydraulische Heizfunktion muessen
unveraendert bleiben. Keine reale Kuehlwasserfreigabe. Nach
Installation zuerst mit dem Testbutton starten und anschliessend
"Status anzeigen" auslesen; bei "manual_release_needed" sofort
spezifische Not-Freigabe pruefen und LCN-PRO kontrollieren.
"passed" beweist den beobachteten S1-Sperren-/Freigabe-Ablauf,
aber keine unter Last gemessene Heizfunktion und keine
Sperrerlaubnis der uebrigen zehn Module.

Regressionstests fuer positive Freigabe, fehlende Rueckmeldung,
Falschmodul, bestromtes Relais, Freigabefehler und Mode-Konflikt
liegen unter tests/test_m11_lock_probe.php. Die GitHub-Action
lintet PHP und ruft beide Mock-Testreihen auf. Ein realer
LCN-Hardwaretest wurde noch nicht durchgefuehrt.



## v0.3.17 Build 33 – Ursachenanalyse des ersten M11-Sperrtests

Live-Bericht (22.10.2026, 21:50): M11/EG Buero meldete
"failed_safe". S1TargetLock #46746 war nach dem Test FREIGEGEBEN;
S1Target #34107 war 21,0 Grad. Der Sperrzustand selbst wurde nicht
bestaetigt. Die normale Heizfreigabe ist gemeldet, der reale
Sperrtest ist NICHT bestanden.

Die alte v0.3.16-Testlogik hielt nicht dauerhaft fest, ob
LCN_LockTargetValue(M11, 2) den Befehl angenommen hatte. Ein
"failed_safe"-Ergebnis kann daher ohne weitere Daten nicht zwischen
Sendeproblem, fehlender oder verspaeteter Bus-Rueckmeldung und
wirklich ausgebliebener Sperre unterscheiden.

Neue passive Diagnose im bestehenden Button
"M11 EG Büro: Sperrtest-Status anzeigen (nur lesen)":
- Letzte Testphase, Bestaetigung der wirklichen Sperre
- Zahl der Freigabeversuche
- Start-, Sperr-, Rueckmelde- und Freigabezeiten
- Fuer kuenftige Testlaeufe: Ergebnissignale beider nativen API-Befehle
  sowie letzte beobachtete Lock-Rueckmeldung (TRUE/FALSE)

Fuer den ALTEN Testlauf zeigt der Bericht beim Sperrbefehl
"NICHT PROTOKOLLIERT (alter Testlauf v0.3.16)" statt einen
unbelegten Erfolg vorzugeben. Die Session wird beim Update nicht
geloescht.

Sicherheit: Ein bereits mit "failed_safe" oder "passed" beendeter
M11-Test wird beim Startbutton nicht automatisch wiederholt. Keine
Aenderung an Relais, Heizung, GT8, S1Target oder LCN-Firmware.
Die Berichtserweiterung sendet keinerlei LCN-Befehle.
Erneute physische Sperrversuche nur nach gesonderter Ursachenpruefung
und neuer Freigabe.

Die offizielle Symcon-Zuordnung 0=R1, 1=R2, 2=S1, 3=S2 bestaetigt
den bislang benutzten Index 2 fuer die native S1-Rueckmeldung,
beweist aber allein nicht, dass der Befehl das richtige Reglerziel
in der vorliegenden LCN-PRO-Konfiguration erreicht hat.
LCN-PRO-Projekteinstellung "Sperrung zulassen" fuer M11 ist vom
Eigentuemer online aktiviert worden; diese Option ist passiv in
Symcon weiterhin nicht auslesbar.

Testcode erweitert um die Erkennung fehlender Sperrbestaetigung
und die Unterdrueckung eines unabsichtlichen zweiten Sperrversuchs.
GitHub Action zur PHP-Pruefung im Repository vorhanden; ein
vollstaendiger Hardwaretest und ein bestaetigter CI-Durchlauf
werden nicht behauptet.


## v0.3.18 Build 34 – ein gezielter M11-Wiederholungstest mit Fehlerursache

Der Anlagenbetreiber hat am 22.10.2026 gemeldet, die LCN-PRO-Option
"Sperrung zulassen" bei allen elf LCN-Raumreglern aktiviert zu haben.
Ob jede Aenderung online uebertragen wurde, kann das Modul nicht
passiv auslesen. Der bislang einzige Funktionstest (M11) blieb
"failed_safe": Lockversuch 21:49:45, Releaseversuch 21:49:46;
S1TargetLock #46746 war danach FREIGEGEBEN, S1Target #34107 bei
21,0 Grad. Ein Sperrzustand wurde nicht nachgewiesen.

Die nach nur einer Sekunde eingeleitete Freigabe ist mit der
normalerweise vorgesehenen 12-Sekunden-Rueckmeldefrist nicht
vereinbar und deutet auf eine sofortige Ablehnung (Vorbedingung,
Sendesperre oder nativer LCN-Lock-Aufruf) hin. Der alte Test
speicherte den Rueckgabewert noch nicht, daher ist die Ursache
nicht rekonstruiert.

**Neue Aktion** in Instanz #17597:
"M11 EG Büro: S1-Sperrtest einmalig wiederholen
(sendet nur M11 S1-Sperre/Freigabe)"

- Genau ein ausdruecklicher Wiederholungstest nach einem alten
  `failed_safe`-Lauf; keine automatische Wiederholung.
- Identische Schutzpruefungen wie bisher (HEIZEN, keine Jobs,
  keine Sperren, eindeutig M11 mit S1=2 und M92/R1, frische
  Rueckmeldungen, Relais AUS, gueltiger Sollwert).
- Kein Relaiskommando, kein Heizsollwertkommando, kein
  Betriebsartenwechsel, keine Befehle an andere Raummodule.
- Bei Nichtbestaetigung automatisch S1 FREIGEBEN versuchen,
  maximal drei Versuche. Andernfalls unveraenderte Warnung.
- Jetzt wird fuer Sperr- und Freigabebefehl getrennt protokolliert,
  ob der native LCN-Aufruf TRUE oder FALSE liefert, oder welche
  Vorbedingung (Senderstatus, Modulziel, IPS-Funktion,
  500-ms-Bus-Semaphor, Exception) den Versand verhindert hat.
- Der reine Statusknopf zeigt die Fehlermeldung des nativen
  Befehls, Lock-/Release-Antworten, Zeitstempel sowie den
  Anzahlzaehler fuer den Einzel-Wiederholungstest.
- Nach Abschluss dieses Wiederholungstests ist kein dritter
  Testversuch ueber die Moduloberflaeche erlaubt.

Die verifizierte API-Signatur laut Symcon dokumentiert
bool LCN_LockTargetValue(int InstanzID,int Ziel), mit
0=R1, 1=R2, 2=S1, 3=S2. Ein TRUE bedeutet nur
erfolgreichen Befehlsaufruf und beweist noch keine
physische Reglersperre.

Der Test wurde durch Regressionstest-Szenarien fuer FALSE-
Rueckgabewerte, Wiederholungsschutz und erfolgreiche
Wiederfreigabe ergaenzt. Reale Tests der Hardware sowie
CI-Ausfuehrung bleiben separat nachzuweisen.



## v0.3.19 Build 35 – Diagnose statt weiterer Sperrversuche

Live-Ergebnis des EINMALIGEN M11-Wiederholungstests am 22.10.2026:
LCN_LockTargetValue(#37617,2) = FALSE,
LCN_ReleaseTargetValue(#37617,2) = FALSE.
S1TargetLock #46746 danach FREIGEGEBEN,
S1Target #34107 nach der Freigabe 21,0 Grad,
kein S1-Sperrzustand nachgewiesen.
Die LCN-PRO-Option "Sperrung zulassen" wurde laut Betreiber inzwischen
in allen elf Modulen aktiviert. Dies ist keine Bestaetigung eines
funktionierenden IPS-Befehls.

Ein weiteres blindes Sperren, Entsperren oder Wechseln auf Reglerindex 0
ist nicht zu verantworten: In LCN-PRO ist Regler 1 dokumentiert,
Symcon liest S1Target. Ob diese Objekte in der bestehenden Konfiguration
denselben Regler bezeichnen, muss erst technisch identifiziert werden.
Die offiziellen IPS-Zielwerte sind 0=R1, 1=R2, 2=S1, 3=S2,
doch API-Aufruf und tatsächliche Firmwareunterstuetzung sind getrennte Fragen.

NEUER reiner Lesebutton:
"LCN-Regler: R1/S1-Schnittstellen und Verbindungen prüfen (nur lesen)"

Der neue Bericht zeigt fuer alle elf konfigurierten LCN-Raeume
die Hardwareadresse, Segment, native IPS-Modul-GUID, InstanceStatus,
ConnectionID, native LCN-Value-Instanz, R1TargetEnabled/S1TargetEnabled
und vorhandene Sperr-Rueckmeldungen R1TargetLock/S1TargetLock.
Gateway-Konfiguration wird auf fest definierte, unkritische technische
Felder begrenzt; Kennwoerter, Benutzernamen und IP-Adressen werden nicht
ausgegeben. Alle Daten werden rein in Symcon gelesen.
**Weder Bus-Anfragen noch Sperr-, Freigabe-, Relais- oder TS-Befehle.**

Der bestehende ChatGPT-Snapshot nennt konfigurierte, bislang ungepruefte
Raeume jetzt "DIREKT VORBEREITET (Reglersperre noch nicht nachgewiesen)"
anstelle von "DIREKT BEREIT". Die zusammenfassende Zahl bezeichnet
technische Zuordnungen, nicht durchgeführte Sperrtests.

Die bisherige M11-Testsession und die ausgefallenen API-Ergebnisse
bleiben unveraendert gespeichert. Insbesondere keine Wiederholung
durch Update oder durch den neuen Diagnoseknopf.

Regressionstest test_native_interface_audit.php prueft, dass native
R1/S1-Objekte und Konfiguration korrekt auslesbar sind und keine
vertraulichen Gateway-Felder ausgegeben werden. LCN-Live-Funktionstest
wurde nicht durchgefuehrt.


**Zusaetzliche Sicherheitskorrektur im selben Release:** Sobald der
persistierte M11-Funktionstest die Phase failed_safe hat und
DirectCoolingEnabled=true ist, verweigert RequestMode den
Moduswechsel auf KUEHLEN. Die bis dahin funktionierende
Heizsteuerung bleibt unveraendert; kein blindes Anfahren aller
11 Raumregler und keine automatische Umschaltung in den alten
A7/A8-Kuehlweg nach fehlgeschlagenem native Lock.
Diese Sperre wird nicht durch eine Leseabfrage aufgeloest;
zuerst muss die native LCN-Schnittstelle nachweisbar funktionieren.

Der M11-Sperrtest selbst bewertet jetzt einen Durchlauf nur dann als
passed, wenn die native Sperr- UND die native Freigabe-API
TRUE lieferten und die aktuelle Sperr-/Freigabe-Rueckmeldung
einschliesslich S1-Sollwert nachgewiesen ist. Gibt eine API FALSE
zurueck, aber eine Statusvariable meldet "frei", ist das
failed_safe und **keine bewiesene erfolgreiche Testfahrt**.


## v0.3.20 Build 36 – globales, kooperatives LCN-Bus-Timing

Praxis-Hinweis des Anlagenbetreibers vom 22.10.2026: Mehrere
LCN-Kommandos muessen zeitlich versetzt werden, weil zu dicht
gesendete Befehle eine Ueberlast erzeugen koennen und einzelne
Kommandos bzw. Rueckmeldungen verlorengehen.

Daher wird jetzt nach JEDEM aktiven LCN-Aufruf des Climate-Moduls
fuer **750 ms** die bereits bestehende instanzuebergreifende
Sendesperre `LCN_BUS_SEND_GLOBAL` gehalten:
- Vorhandene TS-Kurztasten (Heiz-Sollwert A7/A8),
- direkte native S1-Lock/Release-Aufrufe (Test und spaeter Betrieb),
- genau ein Relais Rn mittels LCN_SwitchRelay bzw. LCN_SetRelay,
- aktive LCN_RequestRead- und LCN_RequestStatus-Abfragen,
- die manuellen rotierenden Zweier-Batches fuer S1-Statusupdates.

Dadurch kann das naechste Climate-LCN-Kommando erst gesendet
werden, nachdem der vorangegangene Aufruf beendet und
die 750-ms-Wartezeit abgelaufen ist. Dies gilt auch nach
FALSE-Rueckgaben/Exceptions, um Burst-Schleifen zu verhindern.
Technische Umsetzung: neues Trait `TrafficPacing.php`,
`ReleaseLCNBusSlot()`, `IPS_Sleep(750)` im gesicherten
`finally` vor dem Freigeben des globalen Semaphors.

Die Bus-Sperrwartezeit fuer aktive M11-Kommandos wurde auf
2500 ms, fuer M11-Rueckfragen auf 1500 ms und fuer die beiden
Recovery-Statusabfragen auf 2000 ms angepasst, sodass 750-ms-
Cool-downs nicht voreilig als belegter Bus behandelt werden.
Keine neuen Variablen, keine Relais- oder Sollwertaenderungen
durch das Update, keine Wiederholung bereits abgeschlossener
Auftraege. Der laufende/abgeschlossene M11-Test und seine
`failed_safe`-Vormerkung bleiben unveraendert.

**Wichtig:** Diese Taktung gilt nur fuer Kommandos aus
LCN Climate Control beziehungsweise weitere Module, die
freiwillig exakt dasselbe Symcon-Semaphor nutzen. Andere
LCN-Instanzen, LCN-PRO, lokale GT8-Tasten sowie sonstige
Busmaster sind dadurch NICHT automatisch synchronisiert.
750 ms ist ein konservativer Startwert, kein vom LCN-Hersteller
bestaetigter Garantiewert.

Die beiden vorherigen nativen M11-Sperr-/Freigabe-Aufrufe
gaben bereits `FALSE` zurueck. Pacing behebt diese
API-Fehlfunktion nicht automatisch. Vor erneuter direkter
Kuehlung bleibt die genaue native R1/S1-Schnittstellenpruefung
erforderlich. `RequestAction()` an der nativen Sperrvariable
ist ein alternativer, noch nicht praktisch bestaetigter Ansatz;
er wird absichtlich nicht automatisch ausgeloest.

Regressionstest `test_m11_lock_probe.php` prueft die beiden
750-ms-Sendeschleusen ohne reale Wartezeit durch einen
`IPS_Sleep`-Mock. Ein Live-Test am LCN-Bus und ein GitHub-CI-
Ergebnis muessen separat bestaetigt werden.


## v0.3.21 Build 37 – M11 native RequestAction als isolierter Funktionstest

Bestätigtes Live-Ergebnis am 22.10.2026, Symcon 9:
`RequestAction(46746, false)` meldete TRUE.
Vorher/Nachher `S1TargetLock #46746 = FALSE (FREI)`;
die native Standardaktion der Boolean-Variablen war
`#19003 – LCN Variable Soll-Temperatur`. Im Gegensatz dazu
hatten `LCN_LockTargetValue(#37617,2)` und
`LCN_ReleaseTargetValue(#37617,2)` jeweils FALSE geliefert.
WICHTIG: Die erfolgreiche Anforderung der Freigabe am
bereits freigegebenen Regler ist KEIN Sperrtest.

Neue **ausdrücklich aufzurufende** Aktion für M11:
"M11 EG Büro: RequestAction-Sperrtest EINMALIG starten (mit automatischer Freigabe)"

- Aktivierbar ausschliesslich nach dem bereits protokollierten
  `failed_safe` und dem einen vorherigen nativen Wiederholungstest.
  Danach wird der RequestAction-Test nur EINMAL zugelassen.
- Vorbedingung: ruhender Heizbetrieb, freigegebener M11,
  M92 R1 elektrisch AUS, frischer Sollwert und aktuelle Statuswerte,
  valide LCN-Hardwareadresse 11 sowie native S1TargetLock-
  Variable mit genau der nachgewiesenen Standardaktion #19003.
- Sendet `RequestAction(#46746, true)` zum SPERREN und nach
  bestätigter Sperre sofort `RequestAction(#46746, false)`
  zur FREIGABE. Auch bei abgelehntem/ungewissen Sperrbefehl
  erfolgt der Freigabeversuch.
- Pro Busanfrage mindestens 750ms Abstand über das bereits
  verwendete globale Sendesemaphor. Es werden keine Heiz-Sollwerte,
  Relais, Kühlfreigaben oder weiteren Raummodule angesteuert.
- Test besteht nur bei vom API angenommenem Sperr- und
  Freigabebefehl sowie später gemeldeten S1TargetLock TRUE,
  S1TargetLock FALSE und frischem plausiblen S1Target.
- Maximal 12 Sekunden auf Sperre warten, danach Freigabephase.
  Bei unbekannter Freigabe bis zu 3 Versuche, Warnung und
  Not-Freigabeknopf für M11. Der persistente Testzustand
  ueberlebt einen Modulneustart (ausschliesslich mit Freigabe).
- Der Statusknopf nennt nun den tatsaechlich verwendeten
  Befehlspfad und die API-Rueckgaben.
- Selbst bei bestandenem M11-Test wird die PRODUKTIVE
  Direktkuehlung weiterhin nicht freigegeben: Die bisherige
  Controllerfunktion benutzt unveraendert die fehlgeschlagene
  native LCN-API und bei den anderen zehn Modulen liegt noch
  keine kontrollierte Funktionsfreigabe vor. Die bisherige
  Heizsteuerung bleibt uneingeschraenkt.

Regressionsszenarien für positive und fehlerhafte
RequestAction-Aufrufe wurden als Mocktests hinzugefügt.
Kein realer Sperr- oder Freigabebefehl wurde vom Entwickler
an die Busanlage gesendet. Ein tatsächlich bestandener
CI-Lauf und der reale M11-Sperrtest stehen noch aus.


**Verstaerkte Live-Rueckmeldepruefung:** `RequestAction` kann die
Variable bereits im Rahmen der Aktion aktualisieren. Das ist allein
kein Beweis einer Bus-Rueckmeldung. Der M11-Action-Test fordert
deshalb nach jeder Aktion getrennt `LCN_RequestRead(#19003)`
an und verlangt eine erfolgreiche Statusanforderung sowie eine
anschliessend frische `S1TargetLock`-Rueckmeldung (und nach dem
Entsperren auch einen frischen plausiblen S1Target-Wert).
Bleibt die Rueckmeldung aus, wird der Sperrtest NICHT als
bestanden gewertet und der Freigabeversuch hat Vorrang.
Auch diese beiden Leseaufrufe verwenden die 750-ms-Sendesperre.

Der vorhandene Benutzer-Test `RequestAction(#46746, false) = TRUE`
belegt nur den angenommenen Freigabeversuch im Zustand FREI.
Der physische TRUE/FALSE-Rundlauf ist damit noch nicht getestet.


## v0.3.23 Build 39 – Read-only-Bericht erkennt M11-Funktionstest

Der gespeicherte, nach zwei nativen Fehlversuchen erstmals erfolgreiche
M11-RequestAction-Test ist die erste **real nachgewiesene** LCN-S1-
Sperr-/Freigabefunktion in diesem System. Der bestehende ChatGPT-Bericht
hatte trotzdem alle elf Raeume als noch ungetestet ausgewiesen.

Ohne neue Symcon-Variablen, Timer oder LCN-Befehle wertet der Bericht
jetzt `M11ProbeSession` mit passendem Sender #37617, Sollwertvariable
#34107, Sperrstatusvariable #46746, Befehlspfad RequestAction,
beiden API-Bestaetigungen und bestaetigtem Lock-Ereignis aus.

Nur **EG Buero** wird dann als `S1-Sperr-/Freigabetest BESTANDEN`
bezeichnet, sofern der aktuelle Status zugleich FREI meldet.
Die restlichen zehn Raeume bleiben korrekt als nicht getestet.
Eine getrennte Zusammenfassung meldet die Zahl technisch erkannter
Raeume und die Zahl **tatsaechlich gepruefter** Sperr-/Freigaben.

Technische Zuordnung 11/11 bedeutet weiterhin **nicht** aktive
Kuehlfreigabe. Der aktuelle M11-Test schaltete kein Ventilrelais.
Im HEIZEN-Modus darf beispielsweise OG Bad weiter elektrisch EIN
melden, wenn die LCN-Heizregelung Waerme anfordert.
