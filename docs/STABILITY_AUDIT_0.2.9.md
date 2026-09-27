# Stabilitätsaudit 0.2.9

## Ziel

LCN bleibt Master. Symcon darf ausschließlich die am GT8 bereits vorhandenen
KURZ-Tasten A7/A8 virtuell auslösen und muss seine Zustände aus der echten
S1Target-Rückmeldung ableiten.

## Geprüfte Invarianten

1. Pro Raum höchstens ein Auftrag.
2. Kein Zurücksetzen von WAIT/Steps/SentAtMs beim Round-robin.
3. Pro Raum mindestens 900 ms zwischen zwei erfolgreichen Tastenschritten.
4. Global nur ein Worker und höchstens ein TS-Sendecall je Worker-Takt (250 ms).
5. Kein Schrittzähler-Inkrement bei abgelehntem Sendecall.
6. Zwei zeitversetzte Sendewiederholungen bei transientem `LCN_SendCommand=false`.
7. Ein Raumfehler löscht keine anderen Raumaufträge.
8. Falsche Raumkonfiguration blockiert die komplette Steuerung (fail-closed).
9. S1Target-Rückmeldung kann Queuezustände nicht parallel verändern.
10. GT8-Zwischenwerte während eines Symcon-Auftrags werden nicht als neuer
    Heizsollwert gespeichert.
11. Heizen/Kühlen-Umschaltung startet keine neuen Schritte des alten Modus.
12. ApplyChanges/Neustart sendet keine A7/A8-Kommandos.
13. Keine direkten LCN-Regler-/Relaisbefehle.
14. Keine eigene `LCN_RequestRead()`-Last.
15. Keine blockierenden Sleep-Warteketten.
16. HTML-Kachel nutzt serialisierte requestAction-Folgeaktionen und rollt
    nicht bestätigte optimistische Anzeigen zurück.
17. Benutzer-Objekteigenschaften werden nach der Ersterstellung respektiert.
18. Vor jedem Schritt wird zusätzlich die native LCN-Value/S1Target-Rückmeldeinstanz auf aktive Verbindung geprüft.
19. Steuerbefehle werden nur bei Symcon-Kernelzustand `KR_READY` zugelassen.
20. Unterbrochene Kühlfahrten gelten nicht als bestätigte Endlage.
21. Lokale Browserbedienung wird nicht vom Internet-/WAN-Status abhängig gemacht.

## Belastungsmodell

Zusätzlich zu den statischen Regressionstests wurde der Scheduler mit einem
Mehrraummodell geprüft:

- 1 bis 11 Räume,
- zufällige Startstellungen,
- Fahrten zu beiden Endlagen,
- 900 ms Mindestwartezeit je Raum,
- 250 ms globaler Worker-Takt,
- zwei unveränderte Bestätigungen an der Endlage,
- Sicherheitsgrenze 25 Schritte.

10.000 randomisierte Szenarien liefen ohne Verletzung von globalem Sendetakt,
Raum-Mindestwartezeit, Schrittgrenze oder Endlagenabschluss.

Worst-Case-Modell 11 Räume, alle von 12 nach 24:
- 154 erfolgreiche TS-Schritte inklusive Endlagenbestätigung,
- globale Mindestlücke 250 ms,
- derselbe Raum in diesem Mehrraumfall ca. 2,75 s zwischen seinen Schritten,
- modellierte Gesamtdauer ca. 41,25 s.

Die reale Dauer hängt von den tatsächlichen Startstellungen und LCN-Rückmeldungen ab.

## Bewusste Restgrenzen

- Es gibt öffentlich keine belastbare LCN/PCK-Angabe für eine garantierte maximale
  Telegrammrate. Die 250-ms-Grenze ist deshalb eine konservative Projektvorgabe,
  keine Hersteller-Spezifikation.
- Das Modul bestätigt die LCN-Regler-Endlage über S1Target. Es besitzt ohne
  zusätzliche Rückmeldung keinen direkten Nachweis der mechanischen Ventilstellung.
- Andere eigene LCN-Module im Projekt verwenden nicht automatisch dieselbe globale
  Sendesperre. Der native Symcon-LCN-Splitter/PCHK bleibt die gemeinsame
  Kommunikationsschicht. Eine projektweite gemeinsame Sendesperre kann später
  optional auch in Light/Jalousie/Fenster übernommen werden.
- Der abschließende Nachweis bleibt ein realer Soak-Test auf der SymBox/LCN-Anlage.
