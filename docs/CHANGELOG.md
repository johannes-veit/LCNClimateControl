# Changelog

## 0.2.1
- Fix: HTML-SDK-Kachel auf den stabilen Visualisierungstyp 1 umgestellt.
- Fix: Betriebsart-, Soll-, Kühl- und Ist-Link-Objekte werden wieder unter der Instanz angelegt.
- Update von 0.2.0 rekonstruiert die zuvor entfernten Objekte automatisch bei `Übernehmen`.
- Betriebsart aus 0.2.0 wird über das persistente Attribut übernommen.
- kompakte Heizansicht: eine Zeile je Raum mit Regler, Soll und Ist.
- kompakte Kühlansicht: eine Zeile je Raum mit `Nicht kühlen / Kühlen` und Ist.
- Isttemperaturänderungen aktualisieren die HTML-Kachel live.
- LCN-Steuerlogik A7/A8, S1Target und Endlagenerkennung unverändert.

## 0.1.1
- Normaler laufender LCN-Auftrag verwendet keinen 2xx-Instanzstatus mehr.
- Dadurch erscheint während Sollwertfahrten kein rotes Ausrufezeichen mehr.
- Fehlerstatus 201/202 bleiben ausschließlich echten Konfigurations-/Laufzeitfehlern vorbehalten.

## 0.1.0
- Erstversion.
- globale Betriebsart Heizen/Kühlen
- Räume zentral in einer Instanz
- Heiz-Sollwert 18–24 °C in Symcon
- Kühlung AN/AUS über erkannte LCN-Regler-Endlagen
- persistente Heizsollwerte und Kühlzustände
- serielle LCN-TS-Queue
- echte S1Target-Rückmeldung
- GT8-Vorrang
- keine Befehle bei ApplyChanges/Neustart
