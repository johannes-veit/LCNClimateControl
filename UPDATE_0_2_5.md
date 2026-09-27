# Update 0.2.5 – Scrollbereich vom Symcon-Kachelkopf getrennt

## Fehlerursache

Bisher wurde der Abstand zum von Symcon selbst gezeichneten Instanznamen nur
durch oberes Padding erzeugt. Padding gehört jedoch zum scrollenden Inhalt.
Beim Herunterscrollen verschwand dieser Abstand und die Raumzeilen konnten
hinter dem Symcon-Titel sichtbar werden.

## Dauerhafte Lösung

Die Kachel ist jetzt konstruktiv in zwei feste Bereiche geteilt:

1. **Symcon-Titelzone**
   - nicht scrollbar,
   - opaker Kartenhintergrund,
   - bleibt ausschließlich für Instanzname und Maximieren-Symbol reserviert.

2. **Modul-Inhalt**
   - beginnt erst unterhalb der Titelzone,
   - nur dieser Bereich ist scrollbar,
   - enthält Betriebsart, Tabelle, Räume und Hinweise.

`html`, `body` und der äußere Kachelcontainer selbst haben `overflow: hidden`.
Damit kann der scrollende Inhalt den Titelbereich nicht mehr erreichen.

Dieses Prinzip wird durch einen eigenen Regressionstest abgesichert.
