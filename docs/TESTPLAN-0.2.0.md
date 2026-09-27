# Testplan 0.2.0

## A. Update ohne Bewegung
1. Von 0.1.1 auf 0.2.0 aktualisieren.
2. Instanz öffnen und `Übernehmen`.
3. Es darf kein A7/A8-Befehl gesendet werden.
4. Die bisherige Betriebsart muss erhalten bleiben.
5. Alte Bedienvariablen/Links müssen aus der Instanz verschwinden.

## B. HTML-Kachel Heizen
1. Betriebsart Heizen.
2. Pro Raum genau eine Tabellenzeile.
3. Spalten: Raum | Solltemperatur | Soll | Ist.
4. Sollwert 18–24 °C über Slider, Minus und Plus bedienen.
5. GT8 manuell verstellen → Sollwert in der Kachel muss sofort folgen.
6. Isttemperatur ändern → Istwert in der Kachel muss ohne Neuladen folgen.

## C. HTML-Kachel Kühlen
1. Auf Kühlen wechseln.
2. Pro Raum genau eine Tabellenzeile.
3. Buttons heißen nur `Nicht kühlen` und `Kühlen`.
4. Unten muss die Ventilerklärung stehen.
5. Kühlen → obere LCN-Endlage.
6. Nicht kühlen → untere LCN-Endlage.

## D. Rückkehr Heizen
1. Vor Kühlen einen bekannten Heizsollwert einstellen.
2. Kühlen aktivieren und Endlage anfahren.
3. Zurück auf Heizen.
4. Gespeicherter Heizsollwert muss über A7/A8 wiederhergestellt werden.

## E. Mobil / Vollbild
1. Kachel auf schmaler mobiler Ansicht prüfen.
2. Alle Raumzeilen müssen ohne zweite Raumzeile bedienbar bleiben.
3. Vollbild muss die gleiche HTML-Kachel zeigen.
