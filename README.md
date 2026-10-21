# LCN Climate Control für IP-Symcon 9

Version **0.3.0-rc1 (Entwicklungsbranch, Direktkuehlung AUS)**

GitHub-fertige Modulbibliothek für eine bestehende LCN-Fußbodenheizung mit GT8/GUS-Regelung.

## Grundprinzip

LCN bleibt vollständig Master. Das Modul setzt **keine Relais und keine LCN-Reglersollwerte direkt**. Es sendet ausschließlich die gleichen virtuellen LCN-KURZ-Tastenbefehle, die am GT8 verwendet werden:

- standardmäßig **A7 KURZ** = Regler-Sollwert +1 °C = Ventil Richtung AUF
- standardmäßig **A8 KURZ** = Regler-Sollwert -1 °C = Ventil Richtung ZU

Der tatsächlich erreichte Sollwert kommt ausschließlich aus der nativen Symcon-LCN-Variable **S1Target / Zieltemperatur S1 (Float)**.

## Betriebsarten

### Heizen
- pro Raum Isttemperatur als Link auf die vorhandene GUS-Temperatur
- Solltemperatur in Symcon: **18 bis 24 °C in 1-°C-Schritten**
- am GT8 bleibt der komplette in LCN-PRO programmierte Sollwertbereich nutzbar
- ein GT8-Eingriff wird von Symcon übernommen; während eines Symcon-Auftrags hat ein unerwarteter GT8-/LCN-Eingriff Vorrang und beendet den Raumauftrag

### Kühlen
LCN weiß nicht, ob Heiz- oder Kühlwasser fließt. Die Umschaltung der Hydraulik erfolgt weiterhin manuell.

Pro Raum gibt es nur:
- **Kühlen**: A7 schrittweise bis zur bestätigten oberen LCN-Regler-Endlage → Ventil geöffnet
- **Nicht kühlen**: A8 schrittweise bis zur bestätigten unteren LCN-Regler-Endlage → Ventil geschlossen

Die Endlagen werden **nicht berechnet**. Nach jedem Tastendruck wird auf S1Target gewartet. Bleibt S1Target trotz Nachlese unverändert, wird der gleiche Befehl nochmals bestätigt. Erst nach mehreren bestätigten unveränderten Schritten gilt die LCN-Endlage als erreicht.

## Umschaltung Heizen/Kühlen

Beim Wechsel **Heizen → Kühlen** wird für jeden Raum zuerst der echte aktuelle S1Target-Wert dauerhaft als letzter Heizsollwert gespeichert. Anschließend werden die gespeicherten Kühlzustände nacheinander angefahren.

Beim Wechsel **Kühlen → Heizen** werden die gespeicherten Heizsollwerte nacheinander über A7/A8 wiederhergestellt.

Ein Neustart oder `ApplyChanges()` sendet **keinen einzigen LCN-Befehl**.

## Vorbereitung in Symcon

Für jedes neue LCN-Modul (Firmwaregeneration mit Variablen 1–12):
1. unter dem entsprechenden LCN-Modul eine Instanz **LCN Variable / LCN Wert** anlegen,
2. unter „Neue Module“ **S1Target** aktivieren,
3. als Rückmeldung die dabei erzeugte **Float-Variable „Zieltemperatur S1“** verwenden,
4. die zusätzlich erzeugte Boolean-Variable „Entriegelt“ nicht auswählen.

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
