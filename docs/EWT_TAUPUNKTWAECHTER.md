# EWT-Taupunktwaechter – LCNClimateControl 0.5.8

Automatisch integriert in **bestehende Climate-Instanz #17597**.
Keine separate Instanz, kein zusätzliches Skript und keine Eingaben
für die elf bekannten LCN-Luftfeuchtesensoren erforderlich.

## Tatsächliche Hydraulik

FBH -> Ruecklauf -> Plattenwaermetauscher (Kuehlen) -> FBH-Wassersensor
101 -> Heizungspumpe -> Mischventil (Puffer im Sommer zu) -> FBH.

- **101 / #20510** = FBH-Wasser direkt nach dem Platten-WT, alleinige
  Wassertemperatur des EWT-Pumpenschutzes.
- **100 / #12630** = EWT-Quellwasser, nur Information und Anzeige.
- **Shelly Plug M Gen3 / 192.168.103.67** = EWT-Umwaelzpumpe,
  steckt in der physisch von der Betriebsart KUEHLEN geschalteten Steckdose.
- FBH-Heizungspumpe wird **niemals** von dieser Funktion geschaltet.

## Einfacher Entscheidungsweg (alle 10 Sekunden)

1. Pruefe Fühler 101 und die vorhandenen LCN-Temperatur-/RH-Variablen.
2. Berechne den Taupunkt jedes aktiven Climate-Raums (Magnus-Formel).
3. Nimm den hoechsten Raumtaupunkt.
4. Lies den **tatsaechlichen** Ausgang des Shelly Plug ab.
5. Ist die Pumpe an und T101 <= max(Taupunkt)+**1,5 K**,
   sende **AUS**. Andernfalls darf sie weiterlaufen.
6. Ist die Pumpe aus und T101 > max(Taupunkt)+**2,0 K**,
   beginnt eine **120-Sekunden-Stabilitätszeit**. Nur wenn der Abstand\n   durchgehend ausreichend bleibt, sende **EIN**. Sonst AUS.
7. Lies den Shelly-Schaltzustand erneut. Weicht er vom Befehl ab,\n   wiederhole ihn **einmal**, prüfe erneut und melde bei Fehlschlag\n   ausdrücklich **NICHT BESTAETIGT**.
8. Fehlender/veralteter/unplausibler Wert -> Befehl **AUS**;
   nicht erreichbarer Shelly -> **NICHT BESTAETIGT** (kein falscher
   Erfolgseintrag).

Keine Wartezeit beim Abschalten, keine manuelle Quittierung. Beim\nWiederanlauf gilt eine 120-Sekunden-Stabilitätszeit, die bei einem\nGrenzwertunterschreiten, Sensorfehler, Kommunikationsausfall oder\nlängerer Timer-Unterbrechung neu beginnt.

Für eingeschaltete Ausgänge wird alle 10 s ein **lokaler
Shelly-Auto-OFF nach 40 s** erneuert (`Switch.Set` mit
`toggle_after=40`). So fällt eine ausbleibende Freigabe bei
laufendem, erreichbarem Shelly nach Ablauf der Zeit ab.

## LCN-Sensorquellen: automatisch

Die echten GUS-Humidity-IDs wurden mit dem vorhandenen
`johannes-veit/HeliosSmart/libs/LCNRoomSources.php` abgeglichen.

| Raum | LCN-Isttemperatur | LCN-RH |
|---|---:|---:|
| EG Bad | 12812 | 53361 |
| EG Büro | 14055 | 19020 |
| EG Gäste WC | 15825 | 16107 |
| EG Küche | 21585 | 52026 |
| EG Schlafen | 25557 | 58531 |
| EG Wohnen | 29782 | 46360 |
| OG Ankleide | 17837 | 43385 |
| OG Bad | 36518 | 28802 |
| OG Flur | 34232 | 13394 |
| OG Schlafen 1 | 30970 | 37079 |
| OG Schlafen 2 | 25330 | 22436 |

EG Flur besitzt im bestehenden Back-up keine zugeordnete
eigene Temperaturvariable im Climate-Modul. Bei abweichenden
oder neuen IDs kann der Betreiber einzelne RH-IDs optional
in der bereits vorhandenen Raumtabelle ueberschreiben.

- Maximalalter des FBH-Wasserwerts: 15 Minuten.
- Maximalalter der Raumtemperaturen: 2 Stunden.
- Maximalalter unveraenderter LCN-Feuchtewerte: 12 Stunden.
- Ungueltige oder aeltere Werte verhindern den Pumpenstart.
- Der alte Standardwert von 900 Sekunden aus 0.5.6 wird
  automatisch als 12 h interpretiert, passend zum bekannten
  LCN-GUS-Verhalten, bei dem Werte teilweise nur bei Aenderung kommen.

## Software-Installation

Nach einem Update auf 0.5.8 laeuft die neue **EWT-Pumpenautomatik**
mit den bereits bekannten IDs und der vorhandenen Shelly-IP,
sofern der Plug erreichbar und seine Versorgung eingeschaltet ist.
Die Option `DewPointAutoPumpEnabled` ist standardmaessig EIN.
Die bisherigen alten Beobachtungs-/Aktivierungsfelder aus 0.5.6
werden aus der Maske entfernt und haben auf die Automatik
keinen Einfluss. Es wird nur die EWT-Pumpe geschaltet;
die separate LCN-Direktkuehlungsfreigabe wird nicht umgangen.

Das Modul hat die Statusvariable **EWT-Taupunktwaechter**
mit Temperatur, kritischstem Raum und bestaetigtem Schaltstatus;
zusaetzliche Meldungen koennen an eine WebFront-Instanz gehen.

## Grenze der Aussage einer Simulation

Die PHP-Simulation prueft die Entscheidung, 120-s-Stabilitaetszeit, Wertealter, Ein-/Aus-Befehle,
Rückmeldungen, Verlust der Kommunikation, einen ignorierten
Schaltbefehl und die automatische Wiederfreigabe. Sie kann
**weder die tatsaechliche Schaltbewegung des Shelly noch den
Wasserfluss oder die reale Kondensationsfreiheit nachweisen**.
Auch ein gemeldeter Ausgangszustand beweist nicht, dass der
230-V-Kontakt physikalisch offen ist. Der Software-Taupunktwaechter
ist kein unabhaengiger Hardware-Taupunktkontakt.

Die alte Pflicht `CoolingHardwareInterlockConfirmed` der
**separaten LCN-Direktkuehlung** bleibt unveraendert;
sie darf nicht wahrheitswidrig gesetzt werden.
