# LCNClimateControl v0.2.10 — Freigabe zur manuellen SymBox-Erprobung

## Zweck
Gezielter Sicherheits- und Diagnosefix auf Basis von v0.2.9; kein Umbau der LCN-Steuerung.

## Korrekturen
1. Anzeige konkreter Fehlzuordnungen nach **Übernehmen** (Raumname, LCN-Sendemodul, S1Target/Value, ConnectionID, Tasten und Istvariable).
2. Die globale Konfigurationssperre bleibt unverändert wirksam. Auch ein versehentlich weiterlaufender Worker darf bei ungültiger Konfiguration nichts senden.
3. Kein störender Fatal-Popup beim Versuch, trotz bekannter Konfigurationsfehler den Betriebsmodus umzuschalten. Die Kachel erhält den Fehlertext und rollt den angefragten Status zurück.
4. Veraltete asynchrone Promise-Ablehnungen dürfen eine neuere Aktion in der mobilen HTML-Kachel nicht löschen.

## Tests
Die GitHub-Actions-Pipeline prüft alle bestehenden PHP-Regressionsdateien, die PHP-8.4-Syntax, den tatsächlichen PHP-Raumvalidator mit gemockten Symcon-Funktionen, einen echten Modul-Worker-Zyklus an einer simulierten LCN-Schnittstelle und das asynchrone HTML-Frontend mit Node.

### Simulierter Regelzyklus (keine Hardware)
- Heizen: S1Target 20 → 22 °C.
- Wechsel zu Kühlen, gespeicherter Kühlzustand AUS: 22 → 18 °C (untere simulierte Endlage).
- Kühlen EIN: 18 → 24 °C (obere simulierte Endlage).
- Zurück zu Heizen: gespeicherter Sollwert 22 °C wird wiederhergestellt.
- Ausschließlich TS A7/A8 und native S1Target-Logik.

## Explizite Grenzen
- Ohne gesonderte Ventilstellungsrückmeldung bestätigt S1Target nur eine LCN-Reglerstellung, keine echte mechanische Ventilposition.
- Ein unveränderter S1Target-Wert kann die Endlage nur unter der Annahme funktionierender Statuskommunikation bestätigen.
- Globale 250-ms-Rate gilt für einen Worker; die LCN-Sendesperre serialisiert Aufrufe über Instanzen, aber garantiert keinen instanzübergreifenden Mindestabstand.
- Dieser Stand wurde **nicht** auf der SymBox installiert oder gegen die reale Anlage getestet. Nach GitHub-Freigabe auf der SymBox erst Debug-Ausgabe der Raumkonfiguration prüfen, dann einen Raum testen, dann globale Umschaltung.

## Vor Installation
Die aktuell aktive Version und Raumkonfiguration sichern. Symcon Modules aus GitHub aktualisieren; Heiz-/Kühl-Instanz **Übernehmen**. Wenn Fehlerstatus 201 erscheint, Debug der Instanz prüfen und Konfigurationszuordnung korrigieren. Keine LCN-Befehle per Hand als „Test“ nachschießen. Bei grüner Instanz zunächst einen Raum prüfen.
