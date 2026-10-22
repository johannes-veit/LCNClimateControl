<?php
declare(strict_types=1);

/**
 * Explicit, read-only LCN feedback reconciliation for old "uncertain"
 * software markers. NEVER sends any bus command or changes an actuator.
 */
trait LCNClimateLockRecovery
{
    public function ReconcileUncertainCoolingLocks(): string
    {
        if (!IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 1000)) {
            return 'ABBRUCH: Klimamodul ist beschaeftigt. Keine Aenderung.';
        }

        try {
            if ($this->GetMode() !== 0
                || $this->IsBusy()
                || $this->GetPendingMode() !== null
                || $this->GetBuffer('ConfigurationValid') !== '1'
                || $this->GetPendingCoolingRequests() !== []) {
                return 'ABBRUCH: Nur im ruhenden HEIZBETRIEB mit gueltiger Konfiguration moeglich. Keine Aenderung.';
            }

            $locks = $this->ReadJsonAttribute('DirectCoolingLocks');
            $saved = $this->ReadJsonAttribute('DirectCoolingLockMappings');
            $fallbacks = $this->ReadJsonAttribute('CoolingFallbackReasons');
            $rooms = [];
            foreach ($this->GetRuntimeRooms() as $room) {
                $rooms[(int) $room['TargetVariable']] = $room;
            }

            $lines = [];
            $recovered = 0;
            $rejected = 0;
            foreach ($locks as $key => $state) {
                if ((string) $state !== 'uncertain') {
                    continue; // NEVER remove a proven "locked" marker.
                }
                $target = (int) $key;
                $room = $rooms[$target] ?? null;
                $name = is_array($room) ? (string) $room['Name'] : ('S1Target #' . $target);

                try {
                    if (!is_array($room)) {
                        throw new RuntimeException('Raum nicht mehr eindeutig konfiguriert.');
                    }
                    if ($this->GetStoredCoolingState($target)) {
                        throw new RuntimeException('Kuehlzustand nicht AUS bestaetigt.');
                    }
                    $mapping = $this->AutoCoolingReadOnlyDiscovery($room);
                    $previous = $saved[(string) $target] ?? null;
                    if (!is_array($mapping) || !is_array($previous)) {
                        throw new RuntimeException('Aktuelle oder gespeicherte LCN-Zuordnung fehlt.');
                    }
                    foreach (['TargetVariable', 'RelayModule', 'RelayUnit',
                        'RelayChannel', 'RegulatorIndex', 'UnlockedVariable',
                        'RelayStatusVariable'] as $field) {
                        if ((int) ($mapping[$field] ?? -1) !== (int) ($previous[$field] ?? -2)) {
                            throw new RuntimeException('LCN-Zuordnung hat sich geaendert: ' . $field);
                        }
                    }
                    if ((int) ($mapping['RegulatorIndex'] ?? -1) !== 2
                        || ($mapping['LockFeedbackIsLocked'] ?? null) !== true
                        || ($mapping['OpenRelayOn'] ?? null) !== true) {
                        throw new RuntimeException('S1-Regler oder NC-Ventilpolaritaet nicht nachgewiesen.');
                    }

                    $lockID = (int) $mapping['UnlockedVariable'];
                    $relayID = (int) $mapping['RelayStatusVariable'];
                    $valueParent = IPS_GetParent($target);
                    if ($valueParent <= 0
                        || IPS_GetParent($lockID) !== $valueParent
                        || (string) (IPS_GetObject($lockID)['ObjectIdent'] ?? '') !== 'S1TargetLock') {
                        throw new RuntimeException('Sperr-Rueckmeldung ist nicht das native S1TargetLock.');
                    }

                    $ids = [$target, $lockID, $relayID];
                    $updatedTimes = [];
                    $now = time();
                    $lastIncident = (int) ($fallbacks[(string) $target]['time'] ?? 0);
                    foreach ($ids as $id) {
                        if (!IPS_VariableExists($id)) {
                            throw new RuntimeException('Rueckmeldung fehlt: #' . $id);
                        }
                        $variable = IPS_GetVariable($id);
                        $expectedType = $id === $target ? VARIABLETYPE_FLOAT : VARIABLETYPE_BOOLEAN;
                        if ((int) ($variable['VariableType'] ?? -1) !== $expectedType) {
                            throw new RuntimeException('Rueckmeldung mit falschem Datentyp: #' . $id);
                        }
                        $when = (int) ($variable['VariableUpdated'] ?? 0);
                        if ($when <= 0 || $when > $now + 5 || $now - $when > 180) {
                            throw new RuntimeException('Rueckmeldung nicht aktuell (max. 180 s): #' . $id);
                        }
                        if ($lastIncident > 0 && $when < $lastIncident) {
                            throw new RuntimeException('Rueckmeldung liegt vor der letzten Fehlermeldung: #' . $id);
                        }
                        $updatedTimes[] = $when;
                    }
                    if (max($updatedTimes) - min($updatedTimes) > 120) {
                        throw new RuntimeException('Statusmeldungen liegen zeitlich zu weit auseinander.');
                    }
                    if ((bool) GetValue($lockID)) {
                        throw new RuntimeException('LCN-Regler ist aktuell als GESPERRT gemeldet.');
                    }
                    if ((bool) GetValue($relayID)) {
                        throw new RuntimeException('NC-Ventilrelais ist aktuell EIN.');
                    }
                    $actualTarget = (float) GetValue($target);
                    if (!is_finite($actualTarget) || $actualTarget < 10.0 || $actualTarget > 35.0) {
                        throw new RuntimeException('S1Target ist nicht plausibel.');
                    }

                    // Clears ONLY this module's old bookkeeping for the
                    // explicitly verified room. No LCN/GT8/relay calls.
                    $this->DirectCoolingSetLockState($target, '');
                    $this->DirectCoolingFallbackSet($target, '');
                    $this->ClearRoomError($target);
                    $lines[] = $name . ': interne Vormerkung bereinigt (S1 FREI, Relais AUS, Soll '
                        . number_format($actualTarget, 1, ',', '') . ' Grad C; Rueckmeldungen aktuell).';
                    $recovered++;
                } catch (Throwable $e) {
                    $lines[] = $name . ': NICHT BEREINIGT: ' . $e->getMessage();
                    $rejected++;
                }
            }

            $this->RefreshRuntimeStatus();
            $this->UpdateChatGPTDiagnostic();
            $this->PushVisualizationState();
            return 'PRUEFUNG INTERNER SPERRVORMERKUNGEN (HEIZEN / NUR LESEN)'
                . "\nBereinigt: " . $recovered . '; nicht bereinigt: ' . $rejected . '.'
                . "\nKeine LCN-Befehle, keine Reglersperre/-freigabe, keine Relaisbetaetigung."
                . "\nNur das interne Software-Merkmal uncertain wurde bei positivem Nachweis entfernt."
                . "\nDie LCN-PRO-Sperrerlaubnis und die reale Sperrfunktion sind NICHT nachgewiesen."
                . ($lines === [] ? "\nKeine uncertain-Vormerkungen vorhanden." : "\n" . implode("\n", $lines));
        } finally {
            IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
        }
    }
}
