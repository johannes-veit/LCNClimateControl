# EWT-Taupunktwaechter im LCNClimateControl

## Funktion / Hydraulik

Der bestehende Plattenwaermetauscher sitzt im Ruecklauf der Fussbodenheizung:
FBH -> Ruecklauf -> Platten-WT -> Heizungspumpe -> Mischventil -> FBH.
Im Kuehlbetrieb ist die Pufferspeicher-Zufuhr geschlossen. Das nach dem
Platten-WT gemessene FBH-Wasser ist daher naeherungsweise der Kuehlvorlauf.

- Fühler **101**: Symcon-Variable **#20510**; FBH-Wasser nach Platten-WT;
  einzige Wassertemperatur in der Pumpenschutzentscheidung.
- Fühler **100**: **#12630**; Kaltwasser der EWT-Quelle; nur Anzeige,
  kein Einfluss auf die Sicherheitsregel.
- Shelly Plug M Gen3: **192.168.103.67**, Schaltausgang in Symcon **#30335**;
  steckt in der physisch nur bei KUEHLEN versorgten Steckdose.
- Raumtemperatur-/Luftfeuchtewerte: bestehende LCN-Variablen;
  neu **HumidityVariable** pro Raum in der bestehenden Raumtabelle.

## Regel

1. Alle aktivierten Raeume brauchen plausible, frische Temperatur- und
   Luftfeuchtewerte (Vorgabe 900 s maximale Aktualisierungszeit).
2. Berechne jeden Raumtaupunkt mit der Magnus-Formel und bilde das Maximum.
3. Bei laufender EWT-Pumpe: **STOPP**, sobald
   `T_FBH_101 - Taupunkt_max <= 1,5 K`.
4. Bei abgestellter Pumpe: neuer Start erst oberhalb **2,0 K**
   Abstand (0,5 K Hysterese), nach mindestens 30 Sekunden stabilen Werten
   sowie mindestens 60 Sekunden Pumpenpause.
5. Fehlende/ungültige/veraltete Sensorwerte: **STOPP**.
6. Nur die **EWT-Pumpe** wird abgeschaltet; die normale FBH-Pumpe bleibt
   unberührt. LCN-S1-/Ventilsteuerung wird durch diese Funktion nicht verändert.

Live-Steuerung benutzt **lokale Shelly-RPC** (keine Cloud).
Während Freigabe erneuert Symcon alle **10 s** den Shelly-Ausgang
mit `Switch.Set?id=0&on=true&toggle_after=40`. Ohne neue Freigabe
stellt der Shelly den Ausgang nach 40 s lokal ab (Firmwarefunktion).
Symcon prueft den Status mit `Switch.GetStatus`.

**Wichtig:** Auch 40 Sekunden Restlauf bei Fehler sind moeglich. Ein
ausgefallenes, falsch installiertes oder verzögertes Thermometer, ein
klebendes Relais, eine manuelle Shelly-Schaltung oder zu lange
Waermetransportzeiten sind damit NICHT unabhaengig abgedeckt. 1,5 K ist
die Betreiber-Auslegung, keine nachgewiesene Kondensationsfreiheit.
Beim Wiederstart kann der vorher erwaermte Fühler 101 den spaeteren
Zustand nach erneut einsetzender EWT-Kuehlung nicht vorhersagen.
Die Nachlauf-/Wiederanlauf-Funktion muss vor Ort unter realem Durchfluss
getestet werden.

## Installation und Inbetriebnahme

1. Shelly Plug **Power-on default OFF** setzen und manuelle
   Schaltmoeglichkeiten bzw. Shelly-Zeitplaene gesondert absichern.
   Die Socket-Betriebsart HEIZEN/KUEHLEN bleibt unveraendert.
2. Moduleigenschaft `DewPointGuardEnabled`: anfangs **false**.
3. In der bestehenden Raumtabelle in der neuen Spalte `Luftfeuchte %`
   pro aktivem Raum die zugehörige LCN-Feuchtevariable eintragen.
4. Fühler 101 `DewPointWaterVariable` (Standard #20510) prüfen;
   Shelly-IP (Standard 192.168.103.67) kontrollieren.
5. `DewPointGuardEnabled` aktivieren, aber
   `DewPointGuardSimulation` **true** lassen:
   Die Statusvariable `EWT-Taupunktwaechter` zeigt nur Messwerte
   und die rechnerische Entscheidung, es werden KEINE Shelly-Befehle gesendet.
6. Optional im Feld `DewPointPushWebFront` die WebFront-Konfiguratorinstanz
   für Pushmeldungen bei Pumpenstopp, Wiederfreigabe oder Steuerungsfehler
   auswählen (Standard 0 = keine Pushmeldungen).
7. In Betrieb mit thermisch richtig montiertem 101er Fühler auf
   Taupunkt- und Schwellwerte sowie Sensoralter achten.
8. Vor einer Live-Freigabe Tests mit echter Steckdose und EWT-Pumpe
   durchführen: Schaltausgang STOPP, fehlender Feuchtewert, abgezogener
   101er Sensor, unterbrochenes WLAN, unterbrochenes Symcon und
   automatischer Wiederanlauf. Die 40-s-Abschaltung des Shelly
   muss am echten Gerät nachgewiesen werden.
9. Erst nach positiver Inbetriebnahme `DewPointGuardSimulation=false`
   setzen. Das erlaubt explizit das Schalten der EWT-Pumpe.

**Keine falsche Hardware-Abnahme:** Die bestehende
`CoolingHardwareInterlockConfirmed`-Pflicht für die separate
LCN-Direktkuehlung bleibt absichtlich unverändert.
Der Software-Shelly-Guard darf dieses Häkchen nicht automatisch setzen
oder als unabhängiger physischer Taupunktkontakt ausgegeben werden.

Die EWT-Pumpenfunktion wird dadurch als eigener Teil des bestehenden
Climate-Moduls installiert und kann ohne Änderungen an den LCN-A7/A8,
S1TargetLock- oder Ventilkommandos verwendet werden.
