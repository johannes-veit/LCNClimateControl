# LCNClimateControl 0.3.0-rc1 – Direktkuehlung / Rueckkehr zu 0.2.10

## Derzeitiger Sicherheitsstatus
ENTWICKLUNGSVERSION, NICHT FUER KUEHLBETRIEB FREIGEGEBEN.
- Produktivstand main: Version 0.2.10 (unveraendert)
- Unveraenderliches Backup: backup/v0.2.10-pre-cooling-2026-10-21
- Entwicklungsstand: feature/cooling-direct-relays-v0.3.0
- DirectCoolingEnabled = FALSE ab Werk: bisherige Heizung und Kuehlung bleiben unveraendert.
- Aktive SymBox wurde NICHT geaendert.

## Bedienoberflaeche
Die HTML-/Kachelvisualisierung wurde nicht veraendert. Bedienung wie bisher:
Betriebsart Heizen/Kuehlen und je Raum Kuehlen/Nicht kuehlen.

## Konfiguration (erst vor dem Hardware-Test)
Das neue, zugeklappte Konfigurationsfeld "Direkte Kuehlung":
- DirectCoolingEnabled: Aus lassen bis zur Vor-Ort-Freigabe.
- CoolingSafetyVariable: TRUE nur wenn eine EXTERNE sichere Kuehlfreigabe anliegt.
- CoolingRelays: je S1Target-Variablen-ID die native LCN-Relais-Modul-Instanz und R1-R8,
  Index des tatsaechlich verwendeten Reglers (R1=0 / S1=2), vorhandene
  Boolean-Variable "Entriegelt", vorhandenen Boolean-Relaisstatus und
  bestaetigte elektrische AUF-Polaritaet konfigurieren.
  Reglerindex und Relaispolaritaet NIE aus vermuteten Hardwarebezeichnungen raten.
- In LCN-PRO werden keine 55 Tasten benoetigt und Regler 2 wird nicht gebraucht.

## Gewuenschte Zuordnung aus LP3 (LCN-Modulnummern; NICHT Symcon-Instanz-IDs)
EG Buero M11 -> M92/R1; Kueche M21 -> M92/R2; Wohnen M31 -> M92/R3;
Schlafen M41 -> M92/R5; Bad M51 -> M92/R6; Gaeste-WC M61 -> M92/R4.
OG Schlafen1 M111 -> M192/R4; Schlafen2 M121 -> M192/R3;
Ankleide M131 -> M192/R1; Bad M141 -> M192/R2; Flur M151 -> M192/R5.

## Sicherheits- und Fehlerstrategie
- Direkte Relaissteuerung nur mit externem Freigabe-Bool und bestaetigtem Sperrstatus.
- Einzelrelaismaske '--------' mit exakt einem '0' oder '1'.
- Meldet eine Statusvariable den Zielzustand nicht innerhalb von 7 Sekunden,
  stoppt der Raumauftrag, der LCN-Zustand bleibt als ungewiss gespeichert.
- Beim Wechsel in HEIZEN: zuerst Relais elektrisch auf ZU, Rueckmeldung
  abwarten, Regler entsperren, Rueckmeldung abwarten, dann TS-Heizsollwert anfahren.
- Bei Wegfall der Kuehlfreigabe waehrend Kuehlen sendet Watchdog
  Schliessbefehle an markierte Regler; das ersetzt KEINE autarke
  sicherheitsgerichtete Kuehlwasser-/Taupunktsperre.
- Bei Symcon-Neustart keine automatischen neuen Stellbefehle. Persistente
  Sperrhinweise bleiben als DirectCoolingLocks erhalten. Vor einer erneuten
  Aktivierung Status und Schaltfolge pruefen.

## Ausdrueckliche Hardware-Abnahme vor DirectCoolingEnabled=true
1. Freigabe fuer Kuehlwasser physisch abschalten (kein Kaltwasser).
2. Einzeln M11 identifizieren: pruefen, welcher Regler R1 oder S1 ist.
3. Entriegelt-Status ohne Nachlaufproblem: Sperren -> FALSE,
   Freigeben -> TRUE. Reihenfolge und Firmwaregeneration dokumentieren.
4. Relais M92/R1: EIN/AUS gegen mechanische Ventil-/Durchflusslage pruefen.
5. Von Kuehlen zu Heizen: automatische Temperaturregelung an M11
   nach Freigabe und Wiederherstellung der TS-Heizsollwerte nachweisen;
   wenn erforderlich gesonderten "Regler Heizen"-Befehl pruefen.
6. Erst danach weitere Raeume pruefen und Freigabekette installieren.
7. Kuehlwasser darf ohne physisch wirksame Taupunktsicherung nicht
   freigegeben werden. 17 Grad C minimale Vorlauftemperatur allein ist
   KEIN Kondensationsschutz bei hoher Luftfeuchte.

## Rollback – unbedingt REIHENFOLGE
A) Falls Direktkuehlung NIE aktiviert war:
   - Instanz in HEIZEN lassen. Modulquelle in Symcon auf main bzw. Backup
     0.2.10 zurueckstellen, Modul aktualisieren. Keine LCN-Aktion erforderlich.

B) Falls Direktkuehlung JE aktiviert war:
   1. Kuehlwasser hydraulisch sperren.
   2. Im aktiven RC-Modul in HEIZEN wechseln und alle Raumregler
      nachweislich entsperrt, normale Temperaturregelung getestet.
   3. Vor-Ort-Beleg, dass keine Ventile manuell erzwungen und alle
      Raumregler aktiv sind; Fehlerzustand/DirectCoolingLocks pruefen.
   4. Erst dann auf 0.2.10-Backup zurueckstellen.
   5. Bei jeglichem Zweifel: Betrieb nicht mit unverifiziert gesperrten
      Reglern fortsetzen; LCN-PRO manuell anhand der Originaldatei
      kontrollieren, bevor die Software getauscht wird.

Rollback-Code/Repository:
https://github.com/johannes-veit/LCNClimateControl/tree/backup/v0.2.10-pre-cooling-2026-10-21

## Grenzen der Offline-Tests
PHP/JSON-Syntax und Relaismasken werden im GitHub-CI getestet.
LCN-Firmware, Symcon-Hardware und sicherheitsrelevante Taupunktfunktion
wurden nicht simuliert oder an der realen Anlage validiert.
