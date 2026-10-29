# HEIZEN/KUEHLEN-Betriebsart: Handschalter oder Shelly 1 Gen3

Ab LCNClimateControl 0.5.16 Build 66 gibt es in der **Modulinstanz**
einen zweistufigen Schieberegler `Betriebsarten-Schalter`:

- **0 / WANDSCHALTER (Voreinstellung)**: alles bleibt wie bisher.
  Der Betreiber stellt zuerst den Wandschalter physisch auf HEIZEN/KUEHLEN
  und waehlt zusaetzlich die Betriebsart in der Symcon-Visualisierung.
  Der Betriebsart-Shelly wird von diesem Modul nicht angesprochen.
- **1 / SHELLY 1 Gen3**: Die **bereits vorhandene Betriebsart HEIZEN/KUEHLEN
  in der Symcon-Visualisierung** ist der alleinige Schaltausloeser.
  KUEHLEN sendet einen bestaetigten ON-Befehl an den Shelly 1 Gen3,
  HEIZEN sendet einen bestaetigten OFF-Befehl. **Keine weitere
  manuelle Umschaltung**.

**Bereits vorhandene Geraete-Zuordnung:** Der Shelly 1 Gen3 **192.168.103.71**
misst weiterhin die fuenf Wassertemperaturen T100–T104 ueber seinen
Sensor-Add-on und schaltet zusaetzlich mit dem eingebauten Relais I/O
den bisherigen Kontakt des Wandschalters. Es wird **kein zweiter Shelly**
fuer den Wandschalter benoetigt. Die fuehrenden Symcon-Temperaturvariablen
und ihre bestehenden MQTT-Datenquellen werden nicht veraendert.

Das ist eine Auswahl der physischen Schaltmethode, **kein zweiter
Betriebsart-Schalter**. Der bisherige Shelly Plug der EWT-Pumpe
(192.168.103.67) bleibt ausschliesslich der Taupunktsteuerung vorbehalten.

## Verdrahtung (nur nach Elektrofachkraft-Pruefung)

### Stellung 0: Wandschalter

- Bisherige Verdrahtung und der manuelle Wandschalter bleiben unveraendert.
- Es werden keine Schaltbefehle an den Betriebsart-Shelly gesendet.
- Ein bisher an SW gefuehrtes reines Schalter-Messsignal ist kein
  geschalteter I/O-Ausgang und wird nicht von diesem Modus gebraucht.

### Stellung 1: Shelly als Schalter

**Nur** wenn der bisherige Wandschalter tatsaechlich ein **einpoliger
Schliesser** ist und Lastart, Stromkreis und elektrische Leistung
fuer den Shelly 1 Gen3 zulaessig sind:

- **L/N:** Shelly 1 Gen3 dauerhaft mit der richtigen Spannung versorgen.
  Bestehende Versorgung bleibt erhalten.
- **I:** ankommender Leiter, der frueher an den Kontakt des Wandschalters
  angeschlossen war, nach eindeutiger Messung/Zuordnung.
- **O:** abgegangener geschalteter Leiter zur Kuehlsteckdose, nach
  eindeutiger Messung/Zuordnung.
- **SW:** ehemaliger Messanschluss der geschalteten Phase wird
  fachgerecht entfernt und sicher isoliert; danach unbelegt.
- **PE und N zur Steckdose:** bleiben ununterbrochen, nicht ueber
  den Relaiskontakt fuehren.

**Keine ungepruefte Bruecke L-I. Keine Leiter nach Farben raten.**
Bei Wechsler, mehrpoligem Schalter, Fremdspannung oder unbekannten
Zusatzlasten: passendes Schuetz/Umschaltrelais durch eine Fachkraft
waehlen. Die tatsaechliche Kontaktbelegung muss vor dem Umbau
gemessen werden. Diese Anleitung ist keine Freigabe fuer Arbeiten an
Netzspannung.

## Shelly 1 Gen3 Weboberflaeche konfigurieren

1. Bereits vorhandenen Fuehler-/Relais-Shelly unter **192.168.103.71**
   weiterverwenden. Diese Adresse ist im Modul voreingestellt.
   Auch wenn in der bestehenden Modulinstanz noch ein leeres IP-Feld
   aus 0.5.15 gespeichert ist, wird beim Schalten automatisch .71
   verwendet. Die Fuehler T100–T104, ihre Add-on-Anschluesse und
   die MQTT-Datenquelle werden NICHT veraendert. Der EWT-Pumpen-Plug
   bleibt weiterhin unter **192.168.103.67**.
2. **Input/Output Settings**: Eingangsmodus/Schaltertyp
   **Detached / Entkoppelt**. Der frei bleibende SW-Eingang schaltet
   den Ausgang damit nicht. Add-on und Temperatur-Einstellungen
   nicht veraendern.
3. **Power-on default / Einschaltzustand**: **OFF / AUS**
   (nicht ON, Restore Last oder Match Input).
4. **Auto-ON: AUS** und **Auto-OFF: AUS**. Das Modul setzt
   automatisch bei jedem ON einen **35 Sekunden langen lokalen
   `toggle_after`-Timer**, den es alle **10 Sekunden** erneuert.
   Ein dauerhaft gesetzter Shelly-Auto-OFF-Timer wuerde damit kollidieren.
5. **Zeitplaene, Szenen, Webhooks, Shelly-Skripte** zum Umschalten
   dieses Ausgangs deaktivieren; nicht parallel per App/Cloud steuern.
   Lokale HTTP-RPC-Befehle vom Symcon aus muessen erreichbar sein.
   Aktuelle Modulanbindung unterstuetzt nur lokale HTTP-RPC ohne
   eingerichtete HTTP-Authentifizierung.

Der technische Check `Switch.GetConfig?id=0` prueft
`initial_state=off`, `in_mode=detached`, `auto_on=false`
und `auto_off=false`. Bei fehlenden oder widerspruechlichen Feldern
wird KUEHLEN gesperrt.
`Switch.Set?id=0&on=true&toggle_after=35` und
`Switch.GetStatus?id=0` werden verwendet, um das Relais EIN
und bestaetigt zu halten.

## Reihenfolge & Fehlerfall

- Symcon-Visualisierung HEIZEN -> verifiziert Shelly-Relais OFF ->
  **dann** Symcon-Modus HEIZEN und LCN-Heizjobs.
- Symcon-Visualisierung KUEHLEN -> bestaetigte Schutzbedingungen ->
  verifiziert Shelly-Relais ON -> **dann** Symcon-Modus KUEHLEN
  und LCN-Kuehljobs.
- Fehlt die bestaetigte Shelly-Schaltung, wird die neue Betriebsart
  nicht als erfolgreich quittiert.
- Bei Kommunikations-/Konfigurations-/Sicherheitsfehlern im aktiven
  KUEHLEN sperrt der Watchdog weitere ON-Verlaengerungen und
  fordert OFF an. Bei ausbleibender Quittung erscheint eine
  Fehlermeldung. Der Taupunktwaechter unterlaesst dann weitere
  Zugriffe auf den moeglicherweise stromlosen EWT-Pumpen-Plug,
  zeigt **KUEHLEN GESPERRT** statt eines irrefuehrenden
  Kommunikationsfehlers und verlaengert dessen Pumpen-Watchdog
  nicht mehr. Die elektrische Stellung der angeschlossenen
  Steckdose wird damit **nicht unabhaengig gemessen**.
- Nach Modulupdate oder Kernelneustart bleibt KUEHLEN
  **vorsorglich gesperrt**, bis es in der Visualisierung
  erneut ausdruecklich angefordert wurde. Der Shelly schaltet
  beim letzten 35-s-Vertragsende eigenstaendig OFF.
- Die Steuerung der EWT-Pumpe bleibt vollstaendig separat
  beim Taupunktwaechter mit allen 11 Raumtaupunkten, T101
  und bestaetigten LCN-Ventilrelaiskontakten.
- Bereits vorhandene Kuehl-Inbetriebnahme-Pruefungen und die
  externen Software-/Hardwarefreigaben bleiben unveraendert
  erforderlich; der Shelly ersetzt keine Taupunkt-Sicherung.

Der zwei-positionige Schieberegler liegt bewusst nur in
**Modul-Konfiguration**, nicht als zweiter HEIZEN/KUEHLEN-Regler
in der Visualisierung.
