# Update 0.2.4 – Visualisierung und Bedientransport grundlegend überarbeitet

## Ursache der Probleme in 0.2.3

Die Schaltfläche **Heizen/Kühlen** wurde bei `busy=true` absichtlich deaktiviert.
Während einer mehrstufigen Kühl-/Heizfahrt wirkte sie deshalb wie ohne Funktion.
Zudem wurden Fehler von `requestAction()` im Browser still geschluckt und trotz
inkrementellem DOM weiterhin zu große Zustandsnachrichten übertragen.

## 0.2.4

- Betriebsartschalter bleiben bedienbar.
- Ein Wechsel während einer laufenden LCN-Fahrt wird vorgemerkt.
- Der bereits gesendete A7/A8-Schritt wird noch bestätigt; danach wechselt das Modul sicher die Betriebsart.
- Keine parallelen Symcon-API-Aufrufe; der neueste Folgewunsch wird gepuffert.
- Kommunikationsfehler werden sichtbar angezeigt.
- S1Target aktualisiert nur die betroffene Raumzeile.
- Isttemperatur aktualisiert nur den Temperaturwert.
- Globale Zustände werden über kleine Meta-Patches übertragen.
- Vollständiger Kachel-Neuaufbau nur noch bei einem echten Wechsel zwischen Heiz- und Kühlansicht bzw. geänderter Raumstruktur.

Die bewährte LCN-Steuerung mit ca. 900 ms pro bestätigtem A7/A8-Schritt bleibt unverändert.
