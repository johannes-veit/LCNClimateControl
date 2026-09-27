# Update 0.2.8 – Mehrraum-/Parallelbedienung tiefengeprüft

Bei der Prüfung von 0.2.7 wurde ein wichtiger Schedulerfehler gefunden:
Ein im Round-robin zurückgestellter Raum befand sich z. B. in `WAIT`, doch
`StartNextJob()` setzte ihn beim erneuten Herausnehmen wieder auf `SEND`.
Damit hätte unter Last die Wartephase verloren gehen können.

0.2.8 behebt das vollständig.

## Garantien

- Pro Raum existiert höchstens **ein** Auftrag.
- Bereits gesendete Tastendrücke verlieren ihre WAIT-Phase nie.
- `SentAtMs`, Schrittzähler und Endlagenbestätigung bleiben über Round-robin erhalten.
- Visualisierungsaktionen und Worker ändern die Queue nie gleichzeitig
  (gemeinsamer Symcon-Semaphore).
- Schnelle Änderungen desselben Raums ersetzen nicht den bereits gesendeten
  Schritt, sondern retargeten den bestehenden Auftrag.
- Wechsel Kühlen → Nicht kühlen (oder umgekehrt) während WAIT:
  Der alte Tastendruck wird zuerst ausgewertet, anschließend wird sauber die
  neue Richtung gefahren.
- Zwischenstände von S1Target während einer Symcon-Fahrt werden nicht als
  dauerhafter Heizsollwert gespeichert.
- Global maximal ca. 4 TS-Telegramme/s (250-ms-Worker).
- Zusätzlich mindestens 900 ms je einzelnem Raum (Standard).

Damit können mehrere Räume gleichzeitig bzw. schnell nacheinander bedient werden,
ohne parallele TS-Sender oder verlorene Raumzustände.
