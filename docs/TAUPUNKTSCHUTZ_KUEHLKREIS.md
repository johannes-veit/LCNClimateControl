# Taupunktschutz – mechanischer Kuehlkreis, nicht Symcon

## Status und technische Grenze
Die Symcon-Kachel darf unabhaengig von einem optionalen Software-Taupunktsignal
funktionieren. **Kuehlwasser darf erst bei vorhandener, gepruefter
hardwareseitiger Kondensationssicherung in die Flaechenheizung gelangen.**

Eine feste Mindest-Vorlauftemperatur von 17 Grad C garantiert das nicht.
Bei 27 Grad C / 70 % relativer Feuchte liegt der Taupunkt bereits bei
ungefaehr 21 Grad C. Ungedaemmte Verteiler, Leitungen und die
Fussbodenoberflaechen sind in die Gefahrenanalyse einzubeziehen.

## Gewuenschte Hydraulik (Planungsvorschlag, keine Fernausfuehrung)

1. Kuehlfreigabe ueber einen **kontaktgestuetzten Taupunktwaechter** oder
   Kondensationswaechter in der relevanten, kalten Verteiler-/Rohrzone;
   gegebenenfalls mehr als einen Sensor (EG/OG sowie gefaehrdete Bereiche).
   Alle fuer die Abschaltung wichtigen Kontakte bilden eine fehlersichere
   Freigabekette, keine alleinige Symcon-Berechnung.
2. Die Freigabekette schaltet die Versorgung einer **stromlos schliessenden,
   hydraulisch geeigneten 2-Wege-Kuehlkreissperre** bzw. die
   Kuehlfreigabe des Waermeerzeugers/Mischers. Beides kann je nach
   Anlagenhydraulik erforderlich sein. Ausfall der Versorgung, Fühlerfehler
   oder Kondensationsmeldung muessen den Kuehlwasserzufluss unterbrechen.
3. Kuehlmischer mit kontrollierter Mindest-Vorlauftemperatur
   (beispielsweise 17 Grad C, projektbezogen zu bestaetigen).
   Taupunktsperre hat Vorrang vor Temperaturregelung.
4. Vorhandene Umschaltung Heizen/Kuehlen verriegeln, sodass nie
   gleichzeitig Heiss- und Kaltwasser unkontrolliert gemischt werden.
5. Alle Leitungen und Armaturen, die im Kuehlbetrieb unter den
   Umgebungs-Taupunkt gelangen koennen, diffusionsdicht daemmen.
6. Qualifizierten SHK-/Elektro-Fachbetrieb Hydraulik, Betriebsart,
   Fail-safe-Funktion, Sensorpositionen, Verdrahtung und Abschaltprobe
   bei Fehlern/Spannungsverlust pruefen lassen.

Optionale Symcon-Anzeige kann spaeter das *Rueckmeldesignal*
dieser hardwareseitigen Freigabe nur lesen. Niemals die
Schutzwirkung von einer Symcon-Instanz, WLAN oder der Kachel abhaengig machen.

## Tests vor realem Kuehlwasser

- Sensor/Kontakt gezielt ausloesen, Kuehlventil schliesst vollstaendig.
- Sensorleitung/Spannung getrennt: Kuehlventil stromlos geschlossen.
- Vorlauf-Unterschreitung: Mischer stoppt/korrigiert.
- EG/OG Verteiler sowie ungedaemmte Stellen bei hoher Luftfeuchtigkeit
  auf Kondensat pruefen.
- Waehler "Kuehlen AN" am Symcon darf keinen Schutzkontakt ueberbruecken.

**Diese Datei ist ein mechanisches Konzept; am Haus wurde keine
physische Einrichtung eingebaut oder umverdrahtet.**
