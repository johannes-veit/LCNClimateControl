# Changelog

## 0.2.9
- Stabilitätsaudit gegen aktuelle IP-Symcon-9-SDK-Vorgaben und die vorhandene LCN/PCHK-Anbindung.
- Visualisierungs-Transport korrigiert: schnelle Bedienung verschiedener Räume wird nicht mehr auf nur einen globalen Folgebefehl reduziert. Unterschiedliche Raumaktionen bleiben erhalten; nur mehrfache noch nicht gesendete Änderungen derselben Variable werden zusammengefasst.
- Betriebsartwechsel verwirft bewusst noch nicht gesendete Raumaktionen des alten Modus.
- Optimistische Visuänderungen werden bei Transportfehler/Timeout auf den vorherigen Wert zurückgesetzt.
- `ApplyChanges()` wird mit derselben Instanz-Sperre wie Worker/RequestAction gegen Parallelzugriffe geschützt.
- Ein einzelner Raumfehler stoppt nicht mehr die komplette Queue aller anderen Räume.
- Raumfehler werden separat geführt, in der Kachel am Raum markiert und nach einem erfolgreichen Folgeauftrag automatisch gelöscht.
- `LCN_SendCommand()` erhält bis zu zwei zeitversetzte Wiederholungen, wenn Symcon den Befehl nicht annimmt.
- Der eigentliche Sendecall wird zusätzlich über eine globale LCN-Sendesperre zwischen mehreren Klima-Instanzen serialisiert.
- LCN-Sendemodule werden zur Laufzeit auf aktiv, Modul-ID und Splittertyp geprüft.
- Konfiguration prüft zusätzlich native LCN-Value-Instanz, eindeutige S1Target-Zuordnung, eindeutige Sendemodul/TS-Route und verschiedene AUF/AB-Tasten.
- `SelectInstance` ist im Formular auf native LCN-Module eingeschränkt.
- Dynamisches `IPS_SetHidden`/`IPS_SetInfo` entfernt; die HTML-Kachel steuert die Darstellung, Benutzer-Objekteigenschaften werden nicht fortlaufend überschrieben.
- Vorhandene Istwert-Links behalten bei ApplyChanges benutzerdefinierte Namen/Positionen.
- Eigene `LCN_RequestRead()`-Pfade vollständig entfernt; das Modul arbeitet ausschließlich mit den nativen laufenden S1Target-Rückmeldungen.
- `MessageSink()` wird mit derselben Instanz-Sperre wie Worker/RequestAction serialisiert, damit Rückmeldungen keine halb umgeschalteten Queue-Zustände sehen.
- Vor jedem LCN-Schritt muss auch die native S1Target/LCN-Value-Rückmeldeinstanz aktiv und weiterhin mit dem erwarteten LCN-Modul verbunden sein; bei transientem Ausfall wird zeitversetzt wiederholt, aber nicht blind gesendet.
- Bedienbefehle werden nur bei `KR_READY` angenommen; während Symcon-Start/Shutdown werden keine neuen LCN-Schritte erzeugt.
- Eine manuell oder durch ApplyChanges/Update unterbrochene Kühlfahrt wird als „Endlage nicht bestätigt“ markiert, statt weiterhin fälschlich AN/AUS als sicher anzuzeigen.
- Die HTML-Kachel hängt nicht vom Browser-Internetstatus ab; lokale SymBox-Bedienung bleibt auch bei WAN-Ausfall möglich.
- Fehlerhafte aktive Raumkonfiguration arbeitet fail-closed: es werden bis zur Korrektur keinerlei LCN-Steuerbefehle gesendet.
- Bekanntermaßen zuverlässige Mindestwartezeit von 900 ms pro Raum wird nun auch als technische Untergrenze erzwungen.
- Konfigurationsfehlerstatus kann durch erfolgreiche Raumaufträge nicht mehr verdeckt werden.
- Unerwartete interne Worker-Ausnahmen werden abgefangen und raumbezogen isoliert, statt den Timer in einer Fehlerfolge hängen zu lassen.
- Frontend-Folgeaktionen behalten unterschiedliche Räume vollständig; zusammengefasste Aktionen derselben Variable behalten den letzten serverbestätigten Rollbackwert.
- Beim Betriebsartwechsel werden noch nicht versendete optimistische Raumänderungen sichtbar zurückgerollt.
- Dauerhaft pulsierende Aktivitätspunkte durch statische Aktivitätsmarkierung ersetzt.
- Umfangreiche neue Regressionstests für Queue, Fehlerisolation, TS-Retry, Semaphore, Konfigurations-Fail-Closed, Symcon-Store-Konformität und Mehrraumbedienung.

## 0.2.8
- Round-robin-Scheduler nach Tiefenprüfung korrigiert: ein zurückgestellter WAIT-Job behält jetzt zwingend Phase, SentAtMs, Steps und NoChange.
- Kritischen Reset in `StartNextJob()` entfernt; dadurch können mehrere Räume nicht mehr versehentlich zu schnell oder mit verlorener Rückmeldung weitergeschaltet werden.
- Alle Bedienaktionen aus der Visualisierung werden mit demselben Semaphore wie der Worker serialisiert.
- Ein Raum kann gleichzeitig nur genau einen Auftrag besitzen; Queue-Duplikate werden verhindert.
- Heiz-Sollwertänderungen eines bereits wartenden Raums retargeten den bestehenden Auftrag, ohne den schon gesendeten Schritt zu verlieren.
- Kühlung AN/AUS kann ebenfalls sicher während einer laufenden WAIT-Phase geändert werden; der alte Schritt wird zuerst ausgewertet, erst danach wird die Richtung gewechselt.
- MessageSink erkennt jetzt auch in der Queue wartende Raumaufträge. Zwischenwerte einer Symcon-Fahrt werden dadurch nicht mehr versehentlich als neuer Heizsollwert gespeichert.
- Globales LCN-Limit bewusst auf konservative 250 ms zwischen Worker-Takten gesetzt (max. ca. 4 TS-Befehle/s).
- Pro Raum bleibt die separate Mindestwartezeit von standardmäßig 900 ms bestehen.
- Keine Änderung an TS-Codierung A7/A8, S1Target-Wahrheitsquelle oder GT8-Priorität.

## 0.2.7
- Automatisches `LCN_RequestRead()` aus der Kühl-Endlagenerkennung entfernt.
- Ein temporär abgelehnter Read erzeugt dadurch keinen roten Raumfehler mehr.
- Kühl-Endlagen werden über zwei zeitlich getrennte, erfolgreich gesendete A7/A8-Schritte ohne S1Target-Änderung bestätigt.
- Mehrere Räume werden jetzt round-robin abgearbeitet.
- Während Raum A wartet, kann Raum B bereits einen Schritt senden.
- Globaler Worker-Takt 150 ms; Mindestwartezeit je Raum weiterhin standardmäßig 900 ms.
- Globale Umschaltungen mit vielen Räumen werden dadurch deutlich schneller.
- Vorgemerkte Betriebsartwechsel starten keine neuen Schritte des alten Modus.
- Alte `LCN_RequestRead für S1Target fehlgeschlagen`-Fehler werden beim Update automatisch gelöscht.
- Keine Änderung an A7/A8-Codierung oder GT8-Priorität.

## 0.2.6
- Betriebsart-Zeile bleibt beim Scrollen dauerhaft direkt unter dem Symcon-Kachelkopf sichtbar.
- Umsetzung über `position: sticky` innerhalb des bereits getrennten `.content-scroll`-Bereichs.
- Opaker Kartenhintergrund und eigene z-Ebene verhindern Durchscheinen der Raumzeilen.
- Desktop- und Mobilabstände sind auf den jeweiligen Scrollcontainer abgestimmt.
- Keine Änderung an Heiz-/Kühllogik oder LCN-Kommunikation.

## 0.2.5
- Scroll-/Titelarchitektur der Kachel grundlegend korrigiert.
- `html`/`body` und der äußere Kachelcontainer scrollen nicht mehr.
- Der von Symcon belegte Titelbereich erhält einen permanenten opaken Hintergrundschutz.
- Nur ein eigener Inhaltsbereich unterhalb des Symcon-Titels ist scrollbar.
- Dadurch können Raumzeilen beim Scrollen konstruktiv nicht mehr hinter Instanzname oder Maximieren-Symbol laufen.
- Kartenhintergrund/Textfarbe werden jetzt direkt aus den Symcon-Themevariablen übernommen.
- Desktop und mobile Ansicht besitzen getrennte feste Titelzonen.
- Keine Änderung an LCN-, A7/A8-, S1Target-, Heiz- oder Kühllogik.

## 0.2.4
- Visualisierungsaktionen vollständig überarbeitet und an den bewährten HTML-SDK-Transport der LCN-Jalousie angelehnt.
- Kein stilles Verschlucken von `requestAction()`-Fehlern mehr; Transportfehler werden in der Kachel angezeigt.
- Keine parallelen API-Aufrufe: schnelle Folgebefehle werden lokal gepuffert, nur der jeweils neueste Wunsch bleibt erhalten.
- Heizen/Kühlen-Schalter werden nicht mehr durch einen laufenden Raumauftrag deaktiviert.
- Betriebsartwechsel während einer laufenden A7/A8-Fahrt wird sicher vorgemerkt und nach dem bereits gesendeten/bestätigten Schritt ausgeführt.
- Alte Warteschlangenaufträge des vorherigen Modus werden beim vorgemerkten Betriebsartwechsel verworfen.
- Laufzeitmeldungen verwenden kleine `meta`-, `row`- und `temperature`-Patches statt jedes Mal den kompletten Zustand aller Räume zu übertragen.
- Kachel reagiert lokal sofort auf Slider, Plus/Minus, Kühlung und Betriebsart; Serverrückmeldung bestätigt anschließend den Zustand.
- LCN-Schrittzeit und Prozesssicherheitslogik bleiben unverändert.

## 0.2.3
- Visualisierung auf inkrementelle DOM-Aktualisierung umgestellt: normale LCN-/Temperaturmeldungen bauen die Kachel nicht mehr vollständig neu auf.
- Slider, Soll-/Istwerte und Kühlbuttons werden direkt gepatcht; dadurch deutlich weniger Flackern und spürbar flüssigere Bedienung.
- Heiz-Sollwert kann während einer laufenden mehrstufigen LCN-Fahrt sofort neu gewählt werden; der aktuelle Auftrag wird nach der bereits gesendeten und bestätigten Stufe auf das neue Ziel retargetet.
- Andere Räume bleiben während eines laufenden Raumauftrags bedienbar und werden sauber seriell eingereiht.
- Runtime-Raumkonfiguration wird nach ApplyChanges gecacht; auf häufigen S1Target-/Temperaturmeldungen entfallen wiederholte Instanz-/Property-Prüfungen.
- Identische Visualisierungsnachrichten werden nicht erneut übertragen.
- LCN-Prozessgeschwindigkeit bleibt bewusst unverändert (standardmäßig 900 ms je bestätigtem A7/A8-Schritt).
- Kachelkopf-Abstand aus 0.2.2 bleibt unverändert.

## 0.2.2
- Kachelkopf korrigiert: Der von Symcon selbst gezeichnete Instanzname bleibt allein im oberen Kachelbereich.
- Die eigene Zeile „Betriebsart / Heizen / Kühlen“ beginnt jetzt mit festem Sicherheitsabstand darunter.
- Desktop und schmale/mobile Darstellung erhalten jeweils einen eigenen oberen Abstand.
- Keine Änderung an Heiz-/Kühllogik, A7/A8, S1Target oder Endlagenerkennung.

## 0.2.1
- Fix: HTML-SDK-Kachel auf den stabilen Visualisierungstyp 1 umgestellt.
- Fix: Betriebsart-, Soll-, Kühl- und Ist-Link-Objekte werden wieder unter der Instanz angelegt.
- Update von 0.2.0 rekonstruiert die zuvor entfernten Objekte automatisch bei `Übernehmen`.
- Betriebsart aus 0.2.0 wird über das persistente Attribut übernommen.
- kompakte Heizansicht: eine Zeile je Raum mit Regler, Soll und Ist.
- kompakte Kühlansicht: eine Zeile je Raum mit `Nicht kühlen / Kühlen` und Ist.
- Isttemperaturänderungen aktualisieren die HTML-Kachel live.
- LCN-Steuerlogik A7/A8, S1Target und Endlagenerkennung unverändert.

## 0.1.1
- Normaler laufender LCN-Auftrag verwendet keinen 2xx-Instanzstatus mehr.
- Dadurch erscheint während Sollwertfahrten kein rotes Ausrufezeichen mehr.
- Fehlerstatus 201/202 bleiben ausschließlich echten Konfigurations-/Laufzeitfehlern vorbehalten.

## 0.1.0
- Erstversion.
- globale Betriebsart Heizen/Kühlen
- Räume zentral in einer Instanz
- Heiz-Sollwert 18–24 °C in Symcon
- Kühlung AN/AUS über erkannte LCN-Regler-Endlagen
- persistente Heizsollwerte und Kühlzustände
- serielle LCN-TS-Queue
- echte S1Target-Rückmeldung
- GT8-Vorrang
- keine Befehle bei ApplyChanges/Neustart
