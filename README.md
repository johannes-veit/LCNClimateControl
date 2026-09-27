# LCN Climate Control für IP-Symcon 9

Version **0.2.0**

GitHub-fertige Modulbibliothek für eine bestehende LCN-Fußbodenheizung mit GT8/GUS-Regelung.

## Grundprinzip

LCN bleibt vollständig Master. Das Modul setzt **keine Relais und keine LCN-Reglersollwerte direkt**. Es sendet ausschließlich die gleichen virtuellen LCN-KURZ-Tastenbefehle, die am GT8 verwendet werden:

- standardmäßig **A7 KURZ** = Regler-Sollwert +1 °C = Ventil Richtung AUF
- standardmäßig **A8 KURZ** = Regler-Sollwert -1 °C = Ventil Richtung ZU

Der tatsächlich erreichte Sollwert kommt ausschließlich aus der nativen Symcon-LCN-Variable **S1Target / Zieltemperatur S1 (Float)**.

## Neu in 0.2.0 – kompakte HTML-SDK-Visu

Die Instanz besitzt jetzt eine eigene kompakte Kachel für Symcon 9. Die Steuerlogik aus 0.1.1 bleibt unverändert.

### Heizen

Jeder Raum benötigt nur **eine einzige Zeile**:

`Raum | Solltemperatur-Regler | Soll | Ist`

- Sollwertbedienung 18–24 °C in 1-°C-Schritten
- Sollwert und Isttemperatur direkt rechts neben dem Regler
- Änderungen am GT8 werden sofort in der Kachel übernommen
- liegt der GT8-Sollwert außerhalb 18–24 °C, wird der reale Wert trotzdem angezeigt; über Symcon bleiben nur 18–24 °C anwählbar

### Kühlen

Jeder Raum benötigt ebenfalls nur eine Zeile:

`Raum | Nicht kühlen / Kühlen | Ist`

Unter allen Räumen steht klein:

**Kühlen = FHB-Ventil geöffnet · Nicht kühlen = FHB-Ventil geschlossen**

Die Schalter enthalten bewusst nur **„Kühlen“** und **„Nicht kühlen“**.

### Keine zusätzlichen Bedienvariablen mehr

Die HTML-SDK-Kachel kommuniziert direkt mit dem Modul. Version 0.2.0 benötigt deshalb keine zusätzlichen Symcon-Bedienvariablen für Betriebsart, Solltemperatur oder Kühlung. Beim Update werden die alten 0.1.x-Bedienvariablen und Isttemperatur-Links automatisch entfernt; die persistent gespeicherten Heizsollwerte und Kühlzustände bleiben erhalten.

## Betriebsarten

### Heizen
- Solltemperatur in Symcon: **18 bis 24 °C in 1-°C-Schritten**
- am GT8 bleibt der komplette in LCN-PRO programmierte Sollwertbereich nutzbar
- ein GT8-Eingriff wird von Symcon sofort übernommen
- während eines Symcon-Auftrags hat eine unerwartete LCN-/GT8-Änderung Vorrang und beendet den Raumauftrag

### Kühlen
LCN weiß nicht, ob Heiz- oder Kühlwasser fließt. Die Umschaltung der Hydraulik erfolgt weiterhin manuell.

Pro Raum gibt es nur:
- **Kühlen**: A7 schrittweise bis zur bestätigten oberen LCN-Regler-Endlage → Ventil geöffnet
- **Nicht kühlen**: A8 schrittweise bis zur bestätigten unteren LCN-Regler-Endlage → Ventil geschlossen

Die Endlagen werden **nicht berechnet**. Nach jedem Tastendruck wird auf S1Target gewartet. Bleibt S1Target trotz Nachlese unverändert, wird derselbe Richtungsbefehl erneut bestätigt. Erst nach mehreren bestätigten unveränderten Schritten gilt die LCN-Endlage als erreicht.

## Umschaltung Heizen/Kühlen

Beim Wechsel **Heizen → Kühlen** wird für jeden Raum zuerst der echte aktuelle S1Target-Wert dauerhaft als letzter Heizsollwert gespeichert. Anschließend werden die gespeicherten Kühlzustände nacheinander angefahren.

Beim Wechsel **Kühlen → Heizen** werden die gespeicherten Heizsollwerte nacheinander über A7/A8 wiederhergestellt.

Ein Neustart, GitHub-Update oder `ApplyChanges()` sendet **keinen einzigen LCN-Befehl**.

## Vorbereitung in Symcon

Für jedes neue LCN-Modul (Firmwaregeneration mit Variablen 1–12):
1. unter dem entsprechenden LCN-Modul eine Instanz **LCN Variable / LCN Wert** anlegen,
2. unter „Neue Module“ **S1Target** aktivieren,
3. als Rückmeldung die dabei erzeugte **Float-Variable „Zieltemperatur S1“** verwenden,
4. die zusätzlich erzeugte Boolean-Variable „Entriegelt“ nicht auswählen.

## Installation / Update über GitHub

1. ZIP entpacken.
2. Den **Inhalt** des entpackten Ordners in das vorhandene GitHub-Repository kopieren.
3. `.git` nicht löschen.
4. Commit und Push.
5. In Symcon unter **Kern Instanzen → Modules** auf Aktualisierung prüfen.
6. Version 0.2.0 installieren.
7. Instanz öffnen und einmal **Übernehmen**.

## Raumkonfiguration

Je Raum:
- Raumname
- natives LCN-Sendemodul
- vorhandene Isttemperatur-Floatvariable
- S1Target-Floatvariable
- Tastentabelle (normal A)
- Öffnen/+1 °C (normal Taste 7)
- Schließen/-1 °C (normal Taste 8)

## Prozesssicherheit

- immer nur **ein** LCN-Tastenbefehl zur Zeit
- standardmäßig 900 ms Wartezeit nach jedem Tastendruck
- echte S1Target-Rückmeldung vor dem nächsten Schritt
- bei fehlender Änderung gezielte `LCN_RequestRead()`-Nachlese
- Kühl-Endlage erst nach mehrfach bestätigter unveränderter Rückmeldung
- harte Maximalzahl an Tastendrücken verhindert Endlosschleifen
- keine `IPS_Sleep()`-Schleifen
- keine Befehle in `ApplyChanges()`
- keine direkte Relaissteuerung
- kein `LCN_SetTargetValue()` / `LCN_ShiftTargetValue()`
- lokale GT8-/LCN-Bedienung bleibt jederzeit unabhängig funktionsfähig
- HTML-Visu ist von der LCN-Steuerlogik entkoppelt; ein Darstellungsfehler darf keine LCN-Aktion auslösen

## Version
- Library GUID: `{1623F760-7CBB-4DDE-B08F-7625BCFC0278}`
- Modul GUID: `{209F110B-3209-4726-BEC9-12E9223F667B}`
- Version: 0.2.0
