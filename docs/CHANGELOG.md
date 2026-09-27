# Changelog

## 0.2.0
- eigene kompakte HTML-SDK-Kachel für Symcon 9
- HTML-Darstellung bleibt auch im Vollbild aktiv
- Heizbetrieb: genau eine Zeile pro Raum mit Raum, Regler, Soll und Ist
- Kühlbetrieb: genau eine Zeile pro Raum mit „Nicht kühlen / Kühlen“ und Isttemperatur
- Erklärung im Kühlbetrieb: „Kühlen = FHB-Ventil geöffnet · Nicht kühlen = FHB-Ventil geschlossen“
- direkte Live-Aktualisierung bei GT8-/S1Target- und Isttemperaturänderungen
- alte 0.1.x-Bedienvariablen und Isttemperatur-Links werden beim Update automatisch entfernt
- Betriebsart wird persistent als Modulattribut gespeichert
- Steuerlogik und LCN-Sicherheitsprinzip aus 0.1.1 bleiben unverändert

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
