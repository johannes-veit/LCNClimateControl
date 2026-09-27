# Update 0.2.3 – flüssigere Kachel

Die LCN-Steuerung war bereits zuverlässig, die HTML-Kachel reagierte jedoch unnötig träge,
weil bei praktisch jeder S1Target- oder Temperaturmeldung der komplette DOM neu aufgebaut
wurde und während eines Auftrags alle Bedienelemente global gesperrt waren.

## Optimierungen

- normaler Laufzeitstatus wird nur noch **inkrementell** in vorhandene HTML-Elemente geschrieben,
- vollständiger Kachel-Neuaufbau nur noch bei Wechsel Heizen/Kühlen oder geänderter Raumstruktur,
- Sollwertregler reagiert sofort lokal und bleibt auch während einer laufenden Fahrt bedienbar,
- laufender Heizauftrag kann auf einen neu gewählten Sollwert umgestellt werden,
- andere Räume können währenddessen bereits bedient und seriell eingereiht werden,
- Runtime-Räume werden gecacht; häufige Busmeldungen müssen die Konfiguration nicht neu validieren,
- identische HTML-SDK-Nachrichten werden unterdrückt.

Die LCN-Schrittzeit selbst wurde **nicht beschleunigt**. A7/A8 laufen weiterhin bewusst langsam
und bestätigt, standardmäßig ca. 900 ms je Schritt. Dadurch bleibt die bewährte Prozesssicherheit erhalten.
