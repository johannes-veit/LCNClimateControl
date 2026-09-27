# Changelog

## 0.2.3
- Visualisierung auf inkrementelle DOM-Aktualisierung umgestellt: normale LCN-/Temperaturmeldungen bauen die Kachel nicht mehr vollständig neu auf.
- Slider, Soll-/Istwerte und Kühlbuttons werden direkt gepatcht; dadurch deutlich weniger Flackern und spürbar flüssigere Bedienung.
- Heiz-Sollwert kann während einer laufenden mehrstufigen LCN-Fahrt sofort neu gewählt werden; der aktuelle Auftrag wird nach der bereits gesendeten und bestätigten Stufe auf das neue Ziel retargetet.
- Andere Räume bleiben während eines laufenden Raumauftrags bedienbar und werden sauber seriell eingereiht.
- Runtime-Raumkonfiguration wird nach ApplyChanges gecacht; auf häufigen S1Target-/Temperaturmeldungen entfallen wiederholte Instanz-/Property-Prüfungen.
- Identische Visualisierungsnachrichten werden nicht erneut übertragen.
- LCN-Prozessgeschwindigkeit bleibt bewusst unverändert (standardmäßig 900 ms je bestätigtem A7/A8-Schritt).
- Kachelkopf-Abstand aus 0.2.2 bleibt unverändert.

## 0.2.2
- Kachelkopf korrigiert: Der von Symcon selbst gezeichnete Instanzname bleibt allein im oberen Kachelbereich.
- Die eigene Zeile „Betriebsart / Heizen / Kühlen“ beginnt jetzt mit festem Sicherheitsabstand darunter.
- Desktop und schmale/mobile Darstellung erhalten jeweils einen eigenen oberen Abstand.
- Keine Änderung an Heiz-/Kühllogik, A7/A8, S1Target oder Endlagenerkennung.

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
