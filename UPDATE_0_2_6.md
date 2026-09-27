# Update 0.2.6 – Betriebsart bleibt beim Scrollen sichtbar

Die Zeile **Betriebsart / Heizen / Kühlen** ist jetzt innerhalb des scrollbaren
Modulbereichs fest angeheftet.

Aufbau:

1. Symcon-Instanzname – fest, eigener opaker Titelbereich.
2. Betriebsart – `sticky`, bleibt direkt darunter sichtbar.
3. Raumtabelle – scrollt unter der Betriebsart weiter.

Die Betriebsart erhält denselben Kartenhintergrund und eine höhere z-Ebene,
damit keine Raumzeile durchscheint.

Die LCN-Steuerlogik wurde nicht verändert.
