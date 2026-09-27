# Update 0.1.1

## Änderung
Während eines normalen laufenden Heiz-/Kühlauftrags wurde intern Statuscode 203 gesetzt.
IP-Symcon stellt 2xx-Instanzstatus als Warnung/Fehler dar, weshalb kurzzeitig ein rotes
Ausrufezeichen an der Instanz erschien.

Version 0.1.1 lässt die Instanz während eines normalen Auftrags auf **Aktiv (102)**.
Die roten Fehlerzustände 201/202 werden nur noch für echte Konfigurations- bzw.
Laufzeitfehler verwendet.

Die LCN-Steuerlogik, A7/A8-Sequenzen, S1Target-Rückmeldung und gespeicherten Werte
wurden nicht verändert.
