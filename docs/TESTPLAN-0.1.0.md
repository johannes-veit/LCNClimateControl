# Testplan 0.1.0

## Vor Produktivbetrieb zwingend am EG Bad testen

### A. Konfiguration
- M51 als Sendemodul
- vorhandene Isttemperatur auswählen
- S1Target-Floatvariable auswählen
- A7 / A8 prüfen

### B. Heizbetrieb
1. S1Target 20 °C, Symcon Soll 22 °C → genau schrittweise A7 bis 22 °C.
2. S1Target 22 °C, Symcon Soll 19 °C → schrittweise A8 bis 19 °C.
3. Während einer Symcon-Fahrt gegensätzlich am GT8 drücken → Raumauftrag muss abbrechen; GT8-Zustand darf nicht zurückerzwungen werden.
4. GT8 außerhalb Symcon-Bedienbereich, z. B. 15 °C → Symcon muss 15 °C anzeigen; neue Visu-Bedienung bleibt 18–24 °C.

### C. Kühlbetrieb
1. Vor Umschaltung Heizwert merken.
2. Kühlung AN → A7 bis obere LCN-Endlage; erst nach bestätigter unveränderter S1Target-Rückmeldung fertig.
3. Kühlung AUS → A8 bis untere LCN-Endlage.
4. Kühlung → Heizen → alter Heizwert muss wiederhergestellt werden.

### D. Neustart/Update
1. Im Kühlbetrieb Symcon neu starten.
2. Nach Neustart darf kein A7/A8 automatisch gesendet werden.
3. Gespeicherter Heizsollwert muss erhalten bleiben.
4. `ApplyChanges()` darf nichts bewegen.

### E. Fehler
- S1Target-Instanz deaktivieren/ungültig → keine Endlosschleife.
- LCN-Befehl schlägt fehl → Status Fehler, Queue läuft kontrolliert weiter.
- MaxSteps erreichen → Auftrag beendet.
