# EWT-Taupunktwaechter – LCNClimateControl 0.5.11

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

## Betriebsart HEIZEN / KUEHLEN

Die KUEHLEN-Steckdose wird vom vorhandenen physischen Betriebsartschalter
versorgt. Bei HEIZEN ist der Shelly deshalb normalerweise stromlos und
nicht erreichbar. Das ist kein Fehler.

- **Symcon HEIZEN**: Taupunktwaechter pausiert, keine HTTP-Abfrage,
  kein Schaltversuch, keine Fehlermeldung und keine Push-Stoerung.
  Status: `HEIZEN – EWT-Taupunktwaechter inaktiv (Shelly stromlos erwartet)`.
  Der tatsächliche Shelly-Ausgang wird dabei **nicht** als AUS bestaetigt.
- **Symcon KUEHLEN**: Taupunktwaechter aktiv; der Shelly muss bei
  eingeschalteter physischer KUEHLEN-Versorgung erreichbar sein.
  Fehlende Kommunikation bleibt eine Sicherheitsstoerung.
- Beim Wechsel von HEIZEN nach KUEHLEN beginnt die 120-s-Freigabezeit
  neu. Es wird nicht blind wieder eingeschaltet.

**Wichtig:** Der physische Betriebsartschalter wird mangels Rueckmeldung
nicht von Symcon gemessen. Die Symcon-Betriebsart muss mit der tatsaechlichen
Anlagenstellung uebereinstimmen; sonst kann Software die Pumpensicherheit
nicht sicherstellen. Am Shelly ist `initial_state=off` (Power-on AUS)
empfohlen, damit nach Wiederkehr der Netzspannung keine unkontrollierte
Pumpe anlaeuft.

## Fuenf eingebaute Wasser-Temperaturfuehler (Stand 29.10.2026)

Alle Messstellen sind als Variablen der bestehenden Shelly-Fuehler-Instanz
#38067 vorhanden; die Werte werden nur gelesen, ohne die Quellen zu
konfigurieren. Die bestehenden IP-Symcon-IDs sind:

| Kennung | IPS-ID | Bedeutung | Verwendung |
|---|---:|---|---|
| T100 | 12630 | EWT-Vorlauf vor Kuehlen-WT | Diagnose |
| T101 | 20510 | FBH-Ruecklauf nach Kuehlen-WT | **alleiniger Wassersensor fuer den Taupunktschutz** |
| T102 | 21409 | Heizungs-/FBH-Vorlauf | Diagnose |
| T103 | 14561 | FBH-Ruecklauf vor Kuehlen-WT | Diagnose |
| T104 | 23904 | EWT-Ruecklauf nach Kuehlen-WT | Diagnose |

Die vorhandene Statusvariable `EWT-Taupunktwaechter` zeigt im
KUEHLEN-Betrieb alle fuenf Temperaturen an. Wenn sowohl Zeitpunkt als
auch Gueltigkeit passen (Alter maximal 15 min, Unterschied der
beiden Aenderungszeitpunkte hoechstens 120 s), wird daneben die
Temperaturveraenderung am Plattenwaermetauscher angezeigt:
`FBH-WT=T101-T103` und `EWT-WT=T104-T100`.
Ohne ausreichend zeitgleiche Werte steht `nicht synchron`
oder `--` anstelle einer irrefuehrenden Differenz. Vorzeichen
und geringe Deltas alleine beweisen weder Durchfluss noch
Waermetauscherleistung.

**Wichtig:** T100, T102, T103 und T104 sind reine Diagnosesensoren.
Ein fehlender, veralteter oder unplausibler Zusatzwert veraendert
die EWT-Sicherheitsfreigabe **nicht**. Diese haengt weiterhin
von T101, elf Raumtaupunkten und bestaetigten Kuehlrelais ab.
Die physische Anlagenstellung wird durch diese Fuenf-Fuehler-Anzeige
nicht erkannt oder bestaetigt.

## Wann die EWT-Pumpe laufen darf

Auch ein Raum mit **Nicht kuehlen** (z. B. EG Gaeste WC) bleibt Teil
der 11-Raum-Taupunktberechnung. Das schuetzt gemeinsame Kaltwasserleitungen
vor den Verteilern, die unabhaengig vom einzelnen Raumventil abkuehlen koennen.

Im KUEHLEN-Betrieb ist zusaetzlich **mindestens ein Kuehlventil offen**
erforderlich. Die Software nutzt dafuer den vorhandenen bestaetigten
CoolingState UND die zugehoerige native LCN-Relais-Rueckmeldung
mit bekannter NC-Polaritaet. Bei fehlender/unlesbarer Relaisrueckmeldung wird keine Pumpenfreigabe erteilt.
Nur tatsaechlich als offen gemeldete Kreise zaehlen. Alle Ventile geschlossen
bedeutet Pumpen AUS, ohne Alarmpush (normaler Leerlauf).

Die Raum-Taupunktberechnung umfasst immer alle 11 konfigurierten
Sensorpaare, selbst wenn ein Raum als 'Nicht kuehlen' oder
steuerungstechnisch deaktiviert markiert ist. Bei fehlendem/dupliziertem
Sensor oder weniger als 11 Raumzuordnungen erfolgt keine Freigabe.

**Hinweis**: LCN-Relais-Rueckmeldungen koennen sich nur bei Zustandswechsel
aktualisieren. Der vorhandene Bool-Status bestaetigt die elektrische
Relaismeldung, aber nicht mechanisch die tatsaechliche Ventilstellung.
Auch das Shelly-Ausgangsfeedback beweist nicht den Wasserfluss.

## Einfacher Entscheidungsweg (alle 10 Sekunden)

1. Pruefe Fühler 101 und die vorhandenen LCN-Temperatur-/RH-Variablen.
2. Berechne den Taupunkt aller 11 Climate-Raeume, auch Nicht kuehlen (Magnus-Formel).
3. Nimm den hoechsten Raumtaupunkt.
4. Pruefe, ob mindestens ein LCN-Kuehlventil laut gespeicherter Bestaetigung und Relais-Feedback offen ist. Wenn keines: EWT-Pumpe AUS.
5. Lies den **tatsaechlichen** Ausgang des Shelly Plug ab.
7. Ist die Pumpe an und T101 <= max(Taupunkt)+**1,5 K**,
   sende **AUS**. Andernfalls darf sie weiterlaufen.
7. Ist die Pumpe aus und T101 > max(Taupunkt)+**2,0 K**,
   beginnt eine **120-Sekunden-Stabilitätszeit**. Nur wenn der Abstand
   durchgehend ausreichend bleibt, sende **EIN**. Sonst AUS.
8. Lies den Shelly-Schaltzustand erneut. Weicht er vom Befehl ab,
   wiederhole ihn **einmal**, prüfe erneut und melde bei Fehlschlag
   ausdrücklich **NICHT BESTAETIGT**.
9. Fehlender/veralteter/unplausibler Wert -> Befehl **AUS**;
   nicht erreichbarer Shelly -> **NICHT BESTAETIGT** (kein falscher
   Erfolgseintrag).

Keine Wartezeit beim Abschalten, keine manuelle Quittierung. Beim
Wiederanlauf gilt eine 120-Sekunden-Stabilitätszeit, die bei einem
Grenzwertunterschreiten, Sensorfehler, Kommunikationsausfall oder
längerer Timer-Unterbrechung neu beginnt.

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

Nach einem Update auf 0.5.11 laeuft die neue **EWT-Pumpenautomatik**
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
