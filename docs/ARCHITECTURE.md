# Architektur / Sicherheitsregeln

1. **LCN ist Master.** Symcon erzeugt nur TS-KURZ-Befehle.
2. **S1Target ist die Wahrheit.** Eigene Statusvariablen werden niemals als Beweis für einen LCN-Zustand verwendet.
3. **Nur ein Worker.** Alle Raumaufträge laufen seriell über eine einzige Queue.
4. **Kein Befehl bei ApplyChanges/Neustart.**
5. **GT8 hat Vorrang.** Unerwartete S1Target-Schritte während eines Symcon-Auftrags brechen den Raumauftrag ab.
6. **Kühl-Endlagen werden erkannt, nicht berechnet.** Mehrfach bestätigte unveränderte S1Target-Rückmeldung = Endlage.
7. **Heizwerte bleiben persistent erhalten**, während S1Target im Kühlbetrieb absichtlich an die Endlagen gefahren wird.
8. **Keine versteckten Direktbefehle** auf Regler/Relais.
