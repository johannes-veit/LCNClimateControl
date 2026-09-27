# Update 0.2.9 – Stabilitätsaudit

Diese Version ist kein Funktionsausbau, sondern eine Härtung der bereits
funktionierenden Heiz-/Kühlsteuerung.

## Symcon-Seite

- `IPSModuleStrict` bleibt vollständig typisiert.
- Ein einziger registrierter Worker-Timer übernimmt die zeitliche Abarbeitung.
- Keine `sleep`/`usleep`/`IPS_Sleep`-Warteketten.
- Worker, Benutzeraktionen, ApplyChanges und S1Target-MessageSink sind gegen
  konkurrierende Zugriffe auf die interne Queue geschützt.
- `MessageSink` benutzt nicht das undokumentierte `$Data`-Format, sondern liest
  den echten Variablenwert über die Sender-ID.
- HTML-SDK aktualisiert nur betroffene Zeilen/Meta-Daten.
- Benutzer-Objekteigenschaften wie Hidden/Info/Position werden nach der
  Ersterstellung nicht mehr laufend überschrieben.
- Fehlerhafte Raumkonfiguration arbeitet fail-closed: keine LCN-Befehle bis zur
  Korrektur.

## LCN-Seite

- Keine direkte Relais- oder Sollwertsteuerung.
- Ausschließlich die vorhandenen TS-KURZ-Kommandos A7/A8.
- S1Target bleibt die einzige Wahrheitsquelle für die erreichte Reglerstellung.
- Keine eigene `LCN_RequestRead()`-Abfrage mehr.
- Pro Raum mindestens 900 ms zwischen zwei eigenen Tastenschritten.
- Global konservativ maximal ungefähr vier TS-Sendeversuche pro Sekunde.
- Ein Sendecall wird auf aktives natives LCN-Modul/Splitter geprüft.
- Zusätzlich muss die zugehörige native LCN-Value/S1Target-Rückmeldeinstanz aktiv
  und weiterhin mit genau diesem Sendemodul verbunden sein.
- Während Symcon nicht `KR_READY` ist, werden keine neuen LCN-Schritte erzeugt.
- `LCN_SendCommand=false` erhält zwei zeitversetzte Wiederholungen; fehlgeschlagene
  Sendungen erhöhen den Schrittzähler nicht.
- Der eigentliche Sendecall ist zusätzlich über eine globale LCN-Sendesperre
  zwischen mehreren Climate-Control-Instanzen serialisiert.

## Fehlerisolation

Ein fehlerhafter Raum stoppt die übrigen Räume nicht. Der Raum wird markiert und
kann später erneut bedient werden. Ein nachfolgend erfolgreicher Auftrag löscht
den Raumfehler automatisch.

Konfigurationsfehler sind davon bewusst ausgenommen: Weil eine globale
Heizen/Kühlen-Umschaltung sonst nur einen Teil des Hauses bedienen könnte, wird
bei einer ungültigen aktiven Raumzuordnung die gesamte Steuerung blockiert, bis
die Konfiguration korrigiert ist.

## Mehrraumbetrieb

Pro Raum kann genau ein Auftrag existieren. Round-robin erhält Phase, Zeitstempel,
Schrittzähler und Endlagenbestätigung eines wartenden Raums vollständig.

Schnelle Folgeänderungen desselben Raumes retargeten den bestehenden Auftrag.
Unterschiedliche Räume bleiben in der Bedienwarteschlange erhalten.

Die bekannte TS-Codierung und die funktionierende LCN-Regelung werden nicht
verändert.
