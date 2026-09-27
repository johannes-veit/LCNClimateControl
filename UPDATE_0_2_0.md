# Update auf Version 0.2.0

## Zweck
Version 0.2.0 ersetzt die Standard-Variablenansicht durch eine kompakte HTML-SDK-Kachel.

### Heizbetrieb
Eine Zeile pro Raum:
`Raum | Solltemperatur | Soll | Ist`

### Kühlbetrieb
Eine Zeile pro Raum:
`Raum | Nicht kühlen / Kühlen | Ist`

Unter allen Räumen:
`Kühlen = FHB-Ventil geöffnet · Nicht kühlen = FHB-Ventil geschlossen`

## Migration
- vorhandene Raumkonfiguration bleibt erhalten
- gespeicherte Heizsollwerte bleiben erhalten
- gespeicherte Kühlzustände bleiben erhalten
- die alte 0.1.x-Betriebsart wird übernommen
- alte Bedienvariablen/Links werden automatisch entfernt
- beim Update und beim anschließenden `Übernehmen` wird kein A7/A8-Befehl gesendet

## GitHub
Gesamten Inhalt des ZIP über das bestehende Repository kopieren, `.git` beibehalten, committen und pushen.
