# Update 0.2.1 – Visualisierung und Objektbaum korrigiert

## Fehler in 0.2.0
0.2.0 verwendete `SetVisualizationType(2)` und entfernte gleichzeitig die bisherigen
Bedien-/Diagnoseobjekte. Auf der getesteten Symcon-9-Installation führte das zu:
- leerer Instanz im Objektbaum,
- leerer Visualisierung,
- obwohl die Raumkonfiguration weiterhin gespeichert war.

## Korrektur
0.2.1:
- verwendet `SetVisualizationType(1)` für die HTML-SDK-Kachel,
- legt `Betriebsart`, `Ist`-Link, `Soll` und `Kühlung` wieder an,
- übernimmt die in 0.2.0 gespeicherte Betriebsart,
- behält die kompakte eigene Kachel,
- verändert die funktionierende LCN-Steuerlogik nicht.

Nach dem Update die Instanz einmal öffnen und **Übernehmen** drücken.
Danach müssen die Objekte im Objektbaum wieder erscheinen und die Kachel gerendert werden.
