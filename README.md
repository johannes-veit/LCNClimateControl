# LCN Climate Control für IP-Symcon 9

Version **0.2.9**

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
- Version: 0.2.9


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
