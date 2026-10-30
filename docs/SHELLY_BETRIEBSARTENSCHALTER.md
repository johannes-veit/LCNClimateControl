# LCNClimateControl 0.5.17 – Betriebsarten-Shelly mit inverser Schaltlogik

## Reale Anlage – verbindliche Polaritaet

Die Anzeige an der Heizung/Kuehlung selbst wurde am 30.10.2026
ueberprueft. Der bisherige Anschluss arbeitet umgekehrt zu einer
typischen Schliesserkontakt-Freigabe:

| Symcon-Visualisierung | Shelly 1 Gen3 192.168.103.71 | Anlagensteuerung |
| --- | --- | --- |
| HEIZEN | Relais EIN; Kontakt I/O GESCHLOSSEN | HEIZEN |
| KUEHLEN | Relais AUS; Kontakt I/O OFFEN | KUEHLEN |

**Allein die bestehende Betriebsart der Symcon-Visualisierung loest
die Schaltung aus.** In der Modulkonfiguration legt lediglich der
Schieberegler 0=Wandschalter / 1=Shelly fest, welches Geraet
physisch schaltet. Der bisherige EWT-Pumpen-Plug 192.168.103.67 bleibt
eine separate Komponente.

## Shelly-Weboberflaeche / Shelly-App

Fuer den Shelly 1 Gen3 **192.168.103.71**, der gleichzeitig die
fuenf Wasser-Temperaturfuehler **T100–T104** ueber sein Add-on meldet:

- Eingang: **Entkoppelt / Detached** (SW ist nicht mehr belegt).
- **Einschaltzustand bei Spannungsrueckkehr: EIN / ON**.
- Automatisch einschalten (**Auto-ON**) **AUS**.
- Automatisch ausschalten (**Auto-OFF**) **AUS**.
- Keine fremden Zeitplaene, Cloud-Szenen, Webhooks oder Skripte,
  die den Relaisausgang zusaetzlich schalten.
- WLAN, MQTT, Temperatur-Addon, bestehende Fuehler und
  deren Symcon-Zuordnung **unveraendert lassen**.
- Die lokale HTTP-RPC-Schnittstelle muss aus IP-Symcon erreichbar
  sein; dieses Modul hat keine HTTP-Digest-Authentifizierung.

Konfiguration nur lesend pruefbar:
`http://192.168.103.71/rpc/Switch.GetConfig?id=0`
Erforderlich sind `initial_state="on"`, `in_mode="detached"`,
`auto_on=false` und `auto_off=false`.

Das Modul **aendert diese Einstellungen nicht selbst**,
sondern sperrt KUEHLEN bei einem abweichenden Ergebnis.

## Verdrahtung (durch Elektrofachkraft zu pruefen)

Bei Stellung 0 (Wandschalter) bleibt die bisherige Verdrahtung
und doppelte Bedienung unveraendert.

Bei Stellung 1 (Shelly) ersetzt der potentialfreie Relaiskontakt
I/O **nur den nachweislich einpoligen Schliesserkontakt** des alten
Wandschalters; die beiden Kontakte zum Schalter gehen auf I und O
(nach Identifikation), L/N versorgen Shelly dauerhaft, SW bleibt
nach fachgerechtem Rueckbau der alten Messleitung frei.
Die Netzversorgung, der Schutzleiter und die vorhandenen
Temperaturfuehler bleiben unveraendert. Keine ungepruefte
L-I-Bruecke. Bei Wechsler-/mehrpoliger Funktion keine
direkte 1:1-Ersetzung.

## Schaltlogik, Migration, Fehlerverhalten

- **HEIZEN**: Schalte I/O **EIN**, pruefe per
  `Switch.GetStatus?id=0` die Rueckmeldung ON, erst danach
  Symcon auf HEIZEN und LCN-Heizauftraege.
- **KUEHLEN**: Schutzvoraussetzungen und Shelly-Konfiguration
  pruefen, I/O **AUS** und Feedback OFF bestaetigen,
  erst danach Symcon KUEHLEN und LCN-Kuehlauftraege.
- **Keine 35-s-`toggle_after`-Schaltbefehle mehr**:
  Im Heizbetrieb wuerde deren Ablauf faelschlich KUEHLEN waehlen.
  Auch bei fehlendem WLAN darf kein zeitgesteuertes Relais-AUS
  programmiert werden.
- Der 10-s-Kontrolltimer liest die reale Relaisstellung.
  Im Heizbetrieb setzt er unerwartetes OFF wieder auf ON,
  und ein alter `toggle_after`-Timer wird erkannt und
  per dauerhaftem Set(ON) beseitigt.
- Im **laufenden** Kuehlbetrieb werden externe Freigaben statt
  der einmaligen Inbetriebnahme-Vorabpruefung kontrolliert,
  denn LCN-Reglersperren sind waehrend der Kuehlung normal.
  Bei Fehlern: **KUEHLEN gesperrt**, Shelly EIN
  (HEIZEN) versuchen; die getrennte EWT-Pumpen-Absicherung
  verlaengert ihre 40-s-Pumpenfreigabe nicht.
- Nach Update/Neustart **keine automatische Kuehlfreigabe**:
  Das Modul versucht sofort Relais EIN / HEIZEN
  und verlangt vor erneutem KUEHLEN eine ausdrueckliche
  Betriebsartenwahl in der Symcon-Visualisierung.
  Bereits aktive LCN-Reglersperren verhindern bei einer
  erneuten **expliziten** KUEHLEN-Wahl keinen Wiederanlauf
  allein deshalb, weil sie ordnungsgemaess aktiv sind.

## Wichtige physikalische Grenze

**Vollstaendiger Stromausfall der Gesamtanlage**:
Es laufen nach Betreiberangabe keine Heizung, keine Kuehlpumpe
und kein sonstiger aktiver Waerme-/Kaelteerzeuger.

**Isolierter Stromausfall des Shelly 1 Gen3**, waehrend
die Heizungs-/Kuehlungsanlage weiter Spannung hat:
I/O faellt als NO-Kontakt auf **OFF = KUEHLEN**.
Software kann diesen Fehler ohne separate physische Verriegelung
nicht absichern. Die Versorgung des Shelly muss deshalb
entsprechend zuverlaessig sein. Der Modus-Umschalter ist
kein unabhaengiger Sicherheitskontakt und ersetzt keinen
Taupunkt-/Hydraulikschutz.

Die Wasser-Temperaturmessung T100–T104
und die separate Taupunkt-Pumpensteuerung werden nicht
umkonfiguriert. Der Regler prueft nur die eigene Relaisrueckmeldung,
nicht unabhaengig die Spannung an der Kuehlsteckdose.
