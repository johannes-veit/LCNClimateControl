# Update 0.2.7 – RequestRead-Fehler behoben und Mehrraumablauf beschleunigt

Die rote Meldung `LCN_RequestRead für S1Target fehlgeschlagen` stammte nur von
einer zusätzlichen Kontrollabfrage am Endanschlag. Die eigentlichen A7/A8-Befehle
waren nicht fehlerhaft.

Da die neuen LCN-Module S1Target zuverlässig selbstständig melden, verwendet die
automatische Endlagenerkennung ab 0.2.7 kein LCN_RequestRead mehr. Eine Endlage
gilt als bestätigt, wenn zwei zeitlich getrennte, erfolgreich gesendete Befehle
in dieselbe Richtung keine S1Target-Änderung mehr auslösen.

Zusätzlich werden mehrere Räume nicht mehr vollständig nacheinander gefahren.
Während ein Raum seine 900-ms-Mindestwartezeit abwartet, darf der nächste Raum
bereits einen Schritt senden. Die Wartezeiten überlappen sich dadurch, ohne
einen einzelnen LCN-Regler schneller anzutakten.
