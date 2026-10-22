<?php
declare(strict_types=1);

/**
 * Opt-in-Direktkuehlung fuer LCNClimateControl.
 * NICHT ohne reale Rueckmeldungen und unabhaengige hydraulische Taupunktsperre aktivieren.
 * Alle Hardwareoperationen laufen ueber die bestehende Worker-Queue und deren Instanz-Semaphor.
 */
trait LCNDirectCooling
{
    private function UsesDirectCooling(): bool
    {
        // Auch alte gemockte Laufzeiten und migrationsbedingt fehlende
        // Properties muessen im bewahrten Legacy-Pfad sicher bleiben.
        try {
            return (bool) $this->ReadPropertyBoolean('DirectCoolingEnabled');
        } catch (Throwable) {
            return false;
        }
    }

    private function DirectCoolingMappings(): array
    {
        $rows = json_decode($this->ReadPropertyString('CoolingRelays'), true);
        $result = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                $id = (int) ($row['TargetVariable'] ?? 0);
                if ($id > 0 && !isset($result[$id])) {
                    $result[$id] = $row;
                }
            }
        }
        return $result;
    }

    private function DirectCoolingMapping(int $target): ?array
    {
        $all = $this->DirectCoolingMappings();
        if (isset($all[$target])) {
            return $all[$target];
        }
        // Native IPS-Autozuordnung nutzt ausschliesslich Hardware-IDs,
        // Konfiguration und eindeutige Rueckmeldeobjekte.
        if (!method_exists($this, 'AutoCoolingReadOnlyDiscovery')) {
            return null;
        }
        $room = $this->FindRoomByTarget($target);
        if ($room === null) {
            return null;
        }
        return $this->AutoCoolingReadOnlyDiscovery($room);
    }

    private function DirectCoolingLockState(int $target): string
    {
        $map = $this->ReadJsonAttribute('DirectCoolingLocks');
        return (string) ($map[(string) $target] ?? '');
    }

    private function DirectCoolingSetLockState(int $target, string $state): void
    {
        $map = $this->ReadJsonAttribute('DirectCoolingLocks');
        if ($state === '') {
            unset($map[(string) $target]);
            $proofs = $this->ReadJsonAttribute('DirectCoolingReconcile');
            unset($proofs[(string) $target]);
            $this->WriteJsonAttribute('DirectCoolingReconcile', $proofs);
            $saved = $this->ReadJsonAttribute('DirectCoolingLockMappings');
            unset($saved[(string) $target]);
            $this->WriteJsonAttribute('DirectCoolingLockMappings', $saved);
        } else {
            $map[(string) $target] = $state;
        }
        $this->WriteJsonAttribute('DirectCoolingLocks', $map);
    }

    private function DirectCoolingSaveLockMapping(int $target, array $mapping): void
    {
        // Persistiert nur bereits eindeutig validierte, native Instanz-IDs.
        // Nach einem ApplyChanges oder einer fehlgeschlagenen Relais-Suche
        // muss sich ein eventuell gesperrter Regler weiterhin freigeben lassen.
        $map = $this->ReadJsonAttribute('DirectCoolingLockMappings');
        $map[(string) $target] = $mapping;
        $this->WriteJsonAttribute('DirectCoolingLockMappings', $map);
    }

    private function DirectCoolingMappingForJob(array $job): ?array
    {
        if (is_array($job['Mapping'] ?? null)) {
            return $job['Mapping'];
        }
        $target = (int) ($job['TargetVariable'] ?? 0);
        $saved = $this->ReadJsonAttribute('DirectCoolingLockMappings');
        if (is_array($saved[(string) $target] ?? null)) {
            return $saved[(string) $target];
        }
        return $this->DirectCoolingMapping($target);
    }

    /**
     * Normalisiert zwei verschiedene native Rueckmelde-Semantiken.
     * S1TargetLock: TRUE=gesperrt. Aeltere "Entriegelt"-Konfiguration:
     * TRUE=freigegeben. Direktsteuerung benutzt NUR diese Ableitung.
     */
    private function DirectCoolingRegulatorUnlocked(array $mapping): bool
    {
        $status = (bool) GetValue((int) ($mapping['UnlockedVariable'] ?? 0));
        return (bool) ($mapping['LockFeedbackIsLocked'] ?? false)
            ? !$status : $status;
    }

    private function DirectCoolingSafe(): bool
    {
        $id = $this->ReadPropertyInteger('CoolingSafetyVariable');
        // Optionaler SOFTWARE-Schutzeingang. Taupunkt-Wasserabschaltung
        // wird unabhaengig ueber einen potentialfreien mechanischen
        // Taupunktwächter / Kuehlkreisfreigabe realisiert.
        if ($id === 0) {
            return true;
        }
        if (!IPS_VariableExists($id)) {
            return false;
        }
        try {
            return (int) IPS_GetVariable($id)['VariableType'] === VARIABLETYPE_BOOLEAN
                && (bool) GetValue($id);
        } catch (Throwable) {
            return false;
        }
    }

    private function DirectCoolingPreflight(): array
    {
        $errors = [];
        $safetyID = $this->ReadPropertyInteger('CoolingSafetyVariable');
        if ($safetyID <= 0 || !IPS_VariableExists($safetyID)) {
            $errors[] = 'Externe Kuehlfreigabe (Boolean) fehlt.';
        } elseif ((int) (IPS_GetVariable($safetyID)['VariableType'] ?? -1) !== VARIABLETYPE_BOOLEAN) {
            $errors[] = 'Externe Kuehlfreigabe ist nicht Boolean.';
        }
        $seenRelay = [];
        foreach ($this->GetRuntimeRooms() as $room) {
            $target = (int) $room['TargetVariable'];
            $m = $this->DirectCoolingMapping($target);
            if ($m === null) {
                $errors[] = $room['Name'] . ': Relaiszuordnung fehlt.';
                continue;
            }
            $relayModule = (int) ($m['RelayModule'] ?? 0);
            $channel = (int) ($m['RelayChannel'] ?? 0);
            $lock = (int) ($m['RegulatorIndex'] ?? -1);
            $unlocked = (int) ($m['UnlockedVariable'] ?? 0);
            $status = (int) ($m['RelayStatusVariable'] ?? 0);
            if (!$this->IsOperationalLCNModule($relayModule) || $channel < 1 || $channel > 8) {
                $errors[] = $room['Name'] . ': Relais-Modul oder Kanal ungueltig.';
            }
            $relayKey = $relayModule . '/' . $channel;
            if (isset($seenRelay[$relayKey])) {
                $errors[] = $room['Name'] . ': Relais wird bereits von ' . $seenRelay[$relayKey] . ' verwendet.';
            }
            $seenRelay[$relayKey] = $room['Name'];
            // Die Reglerklasse (R1=0 / S1=2) wird NIE geraten.
            if (!in_array($lock, [0, 2], true)) {
                $errors[] = $room['Name'] . ': Reglernummer R1/S1 nicht bestaetigt.';
            }
            foreach (['Entriegelt' => $unlocked, 'Relaisstatus' => $status] as $name => $id) {
                if ($id <= 0 || !IPS_VariableExists($id)
                    || (int) (IPS_GetVariable($id)['VariableType'] ?? -1) !== VARIABLETYPE_BOOLEAN) {
                    $errors[] = $room['Name'] . ': Boolean-' . $name . '-Rueckmeldung fehlt.';
                }
            }
            if (!array_key_exists('OpenRelayOn', $m)
                || !((bool) ($m['PolarityConfirmed'] ?? false))) {
                $errors[] = $room['Name'] . ': elektrische AUF-Polaritaet nicht bestaetigt.';
            }
        }
        return $errors;
    }

    private function DirectCoolingEnqueue(array $room, bool $open, bool $previous): void
    {
        $this->AppendJob([
            'Type' => 'direct_cool',
            'Name' => $room['Name'],
            'SendModule' => $room['SendModule'],
            'TargetVariable' => $room['TargetVariable'],
            'DesiredOpen' => $open,
            'PreviousCoolingState' => $previous,
            'Mapping' => $this->DirectCoolingMapping((int) $room['TargetVariable']),
            'Phase' => 'lock'
        ]);
    }

    private function DirectCoolingEnqueueEmergencyClose(array $room, bool $previous): void
    {
        $target = (int) $room['TargetVariable'];
        $mapping = $this->DirectCoolingMapping($target)
            ?? ($this->ReadJsonAttribute('DirectCoolingLockMappings')[(string) $target] ?? null);
        if (!is_array($mapping)) {
            $this->SetRoomError($target, $room['Name']
                . ': Relais nicht eindeutig erreichbar; AUS konnte nicht ausgefuehrt werden.');
            $this->SetValueIfChanged($this->CoolIdent($target), $previous);
            return;
        }
        $this->AppendJob([
            'Type' => 'direct_emergency_close',
            'Name' => $room['Name'],
            'SendModule' => $room['SendModule'],
            'TargetVariable' => $target,
            'PreviousCoolingState' => $previous,
            'Mapping' => $mapping,
            'Phase' => 'close'
        ]);
    }

    private function DirectCoolingEnqueueRelease(array $room, ?float $restore): void
    {
        $this->AppendJob([
            'Type' => 'direct_release',
            'Name' => $room['Name'],
            'SendModule' => $room['SendModule'],
            'TargetVariable' => $room['TargetVariable'],
            'RestoreTarget' => $restore,
            'Mapping' => $this->DirectCoolingMapping((int) $room['TargetVariable'])
                ?? ($this->ReadJsonAttribute('DirectCoolingLockMappings')[(string) $room['TargetVariable']] ?? null),
            'Phase' => 'close'
        ]);
    }

    private function DirectCoolingRelaySet(array $m, bool $open): bool
    {
        $relayModule = (int) $m['RelayModule'];
        $channel = (int) $m['RelayChannel'];
        $electric = $open ? (bool) $m['OpenRelayOn'] : !(bool) $m['OpenRelayOn'];
        $mask = str_repeat('-', 8);
        $mask[$channel - 1] = $electric ? '1' : '0';
        $unit = (int) ($m['RelayUnit'] ?? 0);
        if (!$this->IsOperationalLCNModule($relayModule)) {
            return false;
        }
        // Bevorzugt den EINZELRELAIS-Befehl der nativ identifizierten LCN
        // Unit Instanz. Die manuelle Altkonfiguration bleibt kompatibel
        // und nutzt weiterhin eine genau einen Kanal veraendernde Maske.
        if ($unit > 0) {
            if (!IPS_FunctionExists('LCN_SwitchRelay')
                || !IPS_InstanceExists($unit)) {
                return false;
            }
            try {
                $data = IPS_GetInstance($unit);
                $cfg = json_decode(IPS_GetConfiguration($unit), true);
                if ((int) ($data['ConnectionID'] ?? 0) !== $relayModule
                    || (int) ($data['InstanceStatus'] ?? 0) !== 102
                    || !is_array($cfg)
                    || (int) ($cfg['Unit'] ?? -1) !== 2
                    || (int) ($cfg['Channel'] ?? -1) !== $channel) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        } elseif (!IPS_FunctionExists('LCN_SetRelay')) {
            return false;
        }
        if (!IPS_SemaphoreEnter(self::GLOBAL_LCN_SEND_SEMAPHORE, 2000)) {
            return false;
        }
        try {
            return $unit > 0
                ? (bool) LCN_SwitchRelay($unit, $electric)
                : (bool) LCN_SetRelay($relayModule, $mask);
        } catch (Throwable $e) {
            $this->SendDebug('DirectCooling', 'Relaiskommando: ' . $e->getMessage(), 0);
            return false;
        } finally {
            IPS_SemaphoreLeave(self::GLOBAL_LCN_SEND_SEMAPHORE);
        }
    }

    private function DirectCoolingControllerCommand(array $job, array $m, bool $lock): bool
    {
        $sender = (int) $job['SendModule'];
        if (!$this->IsOperationalLCNModule($sender)) {
            return false;
        }
        $index = (int) $m['RegulatorIndex'];
        $name = $lock ? 'LCN_LockTargetValue' : 'LCN_ReleaseTargetValue';
        if (!IPS_FunctionExists($name)
            || !IPS_SemaphoreEnter(self::GLOBAL_LCN_SEND_SEMAPHORE, 2000)) {
            return false;
        }
        try {
            return $lock
                ? (bool) LCN_LockTargetValue($sender, $index)
                : (bool) LCN_ReleaseTargetValue($sender, $index);
        } catch (Throwable $e) {
            $this->SendDebug('DirectCooling', $name . ': ' . $e->getMessage(), 0);
            return false;
        } finally {
            IPS_SemaphoreLeave(self::GLOBAL_LCN_SEND_SEMAPHORE);
        }
    }

    private function DirectCoolingTimeoutMs(): int
    {
        // 11 Raeume teilen sich einen kooperativen 250-ms-Worker.
        // Starre 7 s haetten bei paralleler Umschaltung falsch-positive
        // Timeouts erzeugt.
        return min(30000, max(8000, 5000 + count($this->GetRuntimeRooms()) * 1500));
    }

    private function DirectCoolingWait(array $job, string $next, string $name, bool $actual, bool $expected, int $statusID = 0): bool
    {
        if ($actual === $expected
            && ($statusID <= 0 || $this->DirectCoolingFeedbackFresh($statusID, $job))
            && $this->NowMs() - (int) ($job['SentAtMs'] ?? 0) >= 500) {
            $job['Phase'] = $next;
            $this->YieldCurrentJob($job);
            return true;
        }
        if ($this->NowMs() - (int) ($job['SentAtMs'] ?? 0) > $this->DirectCoolingTimeoutMs()) {
            $this->FailCurrentJob($job, $name . ': keine frische Rueckmeldung innerhalb ' .
                (int) ceil($this->DirectCoolingTimeoutMs() / 1000) .
                ' s. LCN-Zustand bitte vor Ort pruefen.');
            return true;
        }
        if ($statusID > 0 && in_array($name, ['Relaiszustand', 'Ventil geschlossen'], true)) {
            $job = $this->DirectCoolingRequestFreshRelay($job, $this->DirectCoolingMappingForJob($job) ?? []);
        }
        $this->YieldCurrentJob($job);
        return true;
    }

    private function HandleDirectCoolingJob(array $job): void
    {
        $target = (int) $job['TargetVariable'];
        $m = $this->DirectCoolingMappingForJob($job);
        if ($m === null || !$this->IsOperationalLCNModule((int) $job['SendModule'])) {
            $this->FailCurrentJob($job, 'Relais-/Modul-Zuordnung nicht mehr gueltig.');
            return;
        }
        $type = (string) $job['Type'];
        $phase = (string) ($job['Phase'] ?? ($type === 'direct_cool' ? 'lock' : 'close'));
        $unlockID = (int) ($m['UnlockedVariable'] ?? 0);
        $statusID = (int) ($m['RelayStatusVariable'] ?? 0);
        if (!IPS_VariableExists($statusID)
            || ($type !== 'direct_emergency_close' && !IPS_VariableExists($unlockID))) {
            $this->FailCurrentJob($job, 'Statusvariable entfernt; keine unbestaetigten Ventilbefehle.');
            return;
        }
        // Not-AUS liest bewusst nur die Relaisrueckmeldung und greift die
        // unklare Reglersperre weder an noch interpretiert sie um.
        $unlocked = $type === 'direct_emergency_close' ? false
            : $this->DirectCoolingRegulatorUnlocked($m);
        $electric = (bool) GetValue($statusID);
        if ($type === 'direct_emergency_close') {
            if ($phase === 'close') {
                if (!$this->DirectCoolingRelaySet($m, false)) {
                    $this->FailCurrentJob($job, 'NC-Ventil AUS konnte nicht gesendet werden.');
                    return;
                }
                $job['Phase'] = 'close_wait';
                $job['SentAtMs'] = $this->NowMs();
                $job['SentAtEpoch'] = time();
                $this->YieldCurrentJob($job);
                return;
            }
            if ($phase === 'close_wait') {
                $want = !(bool) ($m['OpenRelayOn'] ?? true);
                if ($electric === $want
                    && $this->DirectCoolingFeedbackFresh($statusID, $job)
                    && $this->NowMs() - (int) ($job['SentAtMs'] ?? 0) >= 500) {
                    $this->StoreCoolingState($target, false);
                    $this->SetValueIfChanged($this->CoolIdent($target), false);
                    $this->SetBuffer('CurrentJob', '');
                    // Es wird NUR das NC-Ventil geschlossen. Eine moegliche
                    // Reglersperre bleibt als gesonderter Fehler bestehen.
                    $this->SetRoomError($target,
                        'NC-Ventil AUS bestaetigt; Reglersperre ungewiss – '
                        . 'vor Heizbetrieb in LCN kontrollieren.');
                    $this->ConsumePendingCoolingRequest($target);
                    $this->RefreshRuntimeStatus();
                    $this->PushVisualizationRowByTarget($target);
                    $this->PushVisualizationMeta();
                    return;
                }
                if ($this->NowMs() - (int) ($job['SentAtMs'] ?? 0) >
                    $this->DirectCoolingTimeoutMs()) {
                    $this->FailCurrentJob($job,
                        'NC-Ventil AUS nicht bestaetigt; reale Stellung pruefen.');
                    return;
                }
                $job = $this->DirectCoolingRequestFreshRelay($job, $m);
                $this->YieldCurrentJob($job);
                return;
            }
            $this->FailCurrentJob($job, 'Unbekannte NC-Not-AUS-Phase: ' . $phase);
            return;
        }
        if ($type === 'direct_cool') {
            $open = (bool) ($job['DesiredOpen'] ?? false);
            if ($open && !$this->DirectCoolingSafe()) {
                $this->FailCurrentJob($job, 'Kuehlfreigabe fehlt; Ventil wird nicht geoeffnet.');
                return;
            }
            if ($phase === 'lock') {
                if ($this->DirectCoolingLockState($target) === 'locked' && !$unlocked) {
                    $job['Phase'] = 'relay';
                    $this->YieldCurrentJob($job);
                    return;
                }
                $this->DirectCoolingSaveLockMapping($target, $m);
                $this->DirectCoolingSetLockState($target, 'uncertain');
                if (!$this->DirectCoolingControllerCommand($job, $m, true)) {
                    $this->FailCurrentJob($job, 'Reglersperre nicht gesendet; Ventil unveraendert.');
                    return;
                }
                $job['Phase'] = 'lock_wait';
                $job['SentAtMs'] = $this->NowMs();
                $job['SentAtEpoch'] = time();
                $this->YieldCurrentJob($job);
                return;
            }
            if ($phase === 'lock_wait') {
                if (!$unlocked && $this->DirectCoolingFeedbackFresh($unlockID, $job)) {
                    $this->DirectCoolingSetLockState($target, 'locked');
                }
                $this->DirectCoolingWait($job, 'relay', 'Reglersperre', $unlocked, false, $unlockID);
                return;
            }
            if ($phase === 'relay') {
                if ($unlocked) {
                    $this->FailCurrentJob($job, 'Regler unerwartet entsperrt; Relaisbefehl unterdrueckt.');
                    return;
                }
                if (!$this->DirectCoolingRelaySet($m, $open)) {
                    $this->FailCurrentJob($job, 'Relaisbefehl fehlgeschlagen.');
                    return;
                }
                $job['Phase'] = 'relay_wait';
                $job['SentAtMs'] = $this->NowMs();
                $job['SentAtEpoch'] = time();
                $this->YieldCurrentJob($job);
                return;
            }
            if ($phase === 'relay_wait') {
                $want = $open ? (bool) $m['OpenRelayOn'] : !(bool) $m['OpenRelayOn'];
                if ($electric === $want && $this->DirectCoolingFeedbackFresh($statusID, $job)
                    && $this->NowMs() - (int) ($job['SentAtMs'] ?? 0) >= 500) {
                    $this->StoreCoolingState($target, $open);
                    $this->SetValueIfChanged($this->CoolIdent($target), $open);
                    $this->SetBuffer('CurrentJob', '');
                    $this->ClearRoomError($target);
                    $this->ApplyPendingCoolingRequest($target);
                    $this->RefreshRuntimeStatus();
                    $this->PushVisualizationRowByTarget($target);
                    $this->PushVisualizationMeta();
                    return;
                }
                $this->DirectCoolingWait($job, 'relay_wait', 'Relaiszustand', $electric, $want, $statusID);
                return;
            }
        } elseif ($type === 'direct_release') {
            if ($phase === 'close') {
                if (!$this->DirectCoolingRelaySet($m, false)) {
                    $this->FailCurrentJob($job, 'Ventil beim Zurueckschalten nicht geschlossen.');
                    return;
                }
                $job['Phase'] = 'close_wait';
                $job['SentAtMs'] = $this->NowMs();
                $job['SentAtEpoch'] = time();
                $this->YieldCurrentJob($job);
                return;
            }
            if ($phase === 'close_wait') {
                $want = !(bool) $m['OpenRelayOn'];
                $this->DirectCoolingWait($job, 'unlock', 'Ventil geschlossen', $electric, $want, $statusID);
                return;
            }
            if ($phase === 'unlock') {
                if (!$this->DirectCoolingControllerCommand($job, $m, false)) {
                    $this->FailCurrentJob($job, 'Regler konnte nicht freigegeben werden.');
                    return;
                }
                $job['Phase'] = 'unlock_wait';
                $job['SentAtMs'] = $this->NowMs();
                $job['SentAtEpoch'] = time();
                $this->YieldCurrentJob($job);
                return;
            }
            if ($phase === 'unlock_wait') {
                if ($unlocked && $this->DirectCoolingFeedbackFresh($unlockID, $job)
                    && $this->NowMs() - (int) ($job['SentAtMs'] ?? 0) >= 500) {
                    $job['Phase'] = 'restore_target_wait';
                    $job['SentAtMs'] = $this->NowMs();
                    $this->YieldCurrentJob($job);
                    return;
                }
                $this->DirectCoolingWait($job, 'unlock_wait', 'Regler freigegeben', $unlocked, true, $unlockID);
                return;
            }
            if ($phase === 'restore_target_wait') {
                if (!$unlocked) {
                    $this->FailCurrentJob($job, 'LCN-Regler wurde unerwartet erneut gesperrt; Heizfahrt blockiert.');
                    return;
                }
                // Eine bestaetigte Bool-Freigabe allein reicht bei FW 1F0715
                // nicht aus: erst wieder ein PLAUSIBLER, frisch gelesener
                // S1Target belegt die Rueckkehr zum normalen Regelbetrieb.
                if ($this->ReadTarget($target) !== null
                    && $this->DirectCoolingFeedbackFresh($target, $job)) {
                    $this->DirectCoolingSetLockState($target, '');
                    $this->SetBuffer('CurrentJob', '');
                    $this->ClearRoomError($target);
                    $room = $this->FindRoomByTarget($target);
                    if ($room !== null && isset($job['RestoreTarget'])) {
                        $this->EnqueueHeatJob($room, (float) $job['RestoreTarget']);
                    }
                    $this->RefreshRuntimeStatus();
                    $this->PushVisualizationRowByTarget($target);
                    return;
                }
                if ($this->NowMs() - (int) ($job['SentAtMs'] ?? 0) > 120000) {
                    $this->FailCurrentJob($job, 'Regler entriegelt, aber kein normaler S1Target bestaetigt. LCN-PRO pruefen.');
                    return;
                }
                $job = $this->DirectCoolingRequestFreshTarget($job);
                $this->YieldCurrentJob($job);
                return;
            }
        }
        $this->FailCurrentJob($job, 'Unbekannte Direktkuehlungsphase: ' . $phase);
    }

    /**
     * Reconcile a stale INTERNAL 'uncertain' flag against two independent
     * ACTIVE bus reads, not against any cached "unlocked" Boolean.
     *
     * The LCN lock command can be rejected when LCN-PRO disallows locking;
     * the regulator then remains unlocked, although we already wrote the
     * conservative internal "uncertain" marker before sending the command.
     * This read-only recovery NEVER unlocks the regulator or switches a relay.
     *
     * Success makes the affected room LEGACY-only for the current cooling
     * session. A fresh direct-lock attempt may only happen in a later session.
     */
    private function DirectCoolingReconcileUncertain(): void
    {
        if (!$this->UsesDirectCooling() || $this->GetMode() !== self::MODE_COOLING
            || !IPS_FunctionExists('LCN_RequestRead')
            || !IPS_FunctionExists('LCN_RequestStatus')
            || !IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 100)) {
            return;
        }
        try {
            $tracker = $this->ReadJsonAttribute('DirectCoolingReconcile');
            foreach ($this->GetRuntimeRooms() as $room) {
                $target = (int) ($room['TargetVariable'] ?? 0);
                if ($target <= 0 || $this->DirectCoolingLockState($target) !== 'uncertain'
                    || $this->HasJobForTarget($target)
                    || $this->GetStoredCoolingState($target)) {
                    continue;
                }
                $m = $this->DirectCoolingMapping($target)
                    ?? ($this->ReadJsonAttribute('DirectCoolingLockMappings')[(string) $target] ?? null);
                if (!is_array($m)
                    || !$this->IsOperationalLCNModule((int) ($m['RelayModule'] ?? 0))) {
                    continue;
                }
                $lockID = (int) ($m['UnlockedVariable'] ?? 0);
                $relayID = (int) ($m['RelayStatusVariable'] ?? 0);
                $valueParent = IPS_VariableExists($target) ? (int) IPS_GetParent($target) : 0;
                if ($valueParent <= 0 || !IPS_InstanceExists($valueParent)
                    || !IPS_VariableExists($lockID) || !IPS_VariableExists($relayID)
                    || $this->ReadTarget($target) === null) {
                    continue;
                }
                try {
                    $valueInfo = IPS_GetInstance($valueParent);
                    if (strtoupper((string) ($valueInfo['ModuleInfo']['ModuleID'] ?? ''))
                            !== strtoupper(self::LCN_VALUE_MODULE_ID)
                        || (int) ($valueInfo['ConnectionID'] ?? 0)
                            !== (int) ($room['SendModule'] ?? 0)) {
                        continue;
                    }
                    if ((int) (IPS_GetVariable($lockID)['VariableType'] ?? -1)
                            !== VARIABLETYPE_BOOLEAN
                        || (int) (IPS_GetVariable($relayID)['VariableType'] ?? -1)
                            !== VARIABLETYPE_BOOLEAN) {
                        continue;
                    }
                } catch (Throwable) {
                    continue;
                }
                $key = (string) $target;
                $progress = is_array($tracker[$key] ?? null) ? $tracker[$key] : [];
                $sentAt = (int) ($progress['sentAt'] ?? 0);
                $proofs = (int) ($progress['proofs'] ?? 0);
                $priorUpdated = (int) ($progress['priorUpdated'] ?? 0);
                if ($sentAt > 0) {
                    try {
                        // Strictly AFTER our last explicit request; the old
                        // report's 80s-old snapshots are never sufficient.
                        $lockUpdated = (int) (IPS_GetVariable($lockID)['VariableUpdated'] ?? 0);
                        $relayUpdated = (int) (IPS_GetVariable($relayID)['VariableUpdated'] ?? 0);
                        $targetUpdated = (int) (IPS_GetVariable($target)['VariableUpdated'] ?? 0);
                        $fresh = min($lockUpdated, $relayUpdated, $targetUpdated)
                            > max($sentAt, $priorUpdated);
                        if ($fresh) {
                            $isFree = $this->DirectCoolingRegulatorUnlocked($m);
                            $relayOff = (bool) GetValue($relayID)
                                === !(bool) ($m['OpenRelayOn'] ?? true);
                            $targetNormal = $this->ReadTarget($target) !== null;
                            if (!$isFree || !$relayOff || !$targetNormal) {
                                // Missing safety evidence must never clear
                                // the persisted regulator lock uncertainty.
                                $tracker[$key] = ['sentAt' => 0, 'proofs' => 0];
                                continue;
                            }
                            $proofs++;
                            if ($proofs >= 2) {
                                $this->DirectCoolingSetLockState($target, '');
                                $this->DirectCoolingFallbackSet($target,
                                    'Reglersperre nicht bestaetigt. Zweimal frisch '
                                    . 'aus LCN gelesen: Regler FREI, NC-Relais AUS, '
                                    . 'S1Target plausibel. Bisherige A7/A8-Steuerung '
                                    . 'aktiv; LCN-PRO Sperrerlaubnis pruefen.',
                                    'legacy');
                                $this->ClearRoomError($target);
                                unset($tracker[$key]);
                                $this->PushVisualizationRowByTarget($target);
                                continue;
                            }
                            $tracker[$key] = [
                                'sentAt' => 0, 'proofs' => $proofs,
                                'priorUpdated' => max($lockUpdated, $relayUpdated, $targetUpdated)
                            ];
                            continue;
                        }
                    } catch (Throwable) {
                    }
                    if (time() - $sentAt < 30) {
                        continue;
                    }
                }

                // Initiate the next PASSIVE sample. Both return codes must
                // be true. Never send a controller lock/unlock, TS or relay.
                if (!IPS_SemaphoreEnter(self::GLOBAL_LCN_SEND_SEMAPHORE, 300)) {
                    continue;
                }
                try {
                    $time = time();
                    if ((bool) LCN_RequestRead($valueParent)
                        && (bool) LCN_RequestStatus((int) $m['RelayModule'])) {
                        $tracker[$key] = [
                            'sentAt' => $time, 'proofs' => $proofs,
                            'priorUpdated' => $priorUpdated
                        ];
                    }
                } catch (Throwable $e) {
                    $this->SendDebug('Uncertain-Reconcile', $e->getMessage(), 0);
                } finally {
                    IPS_SemaphoreLeave(self::GLOBAL_LCN_SEND_SEMAPHORE);
                }
            }
            // The map remains small (at most one entry for each of 11 rooms).
            $this->WriteJsonAttribute('DirectCoolingReconcile', $tracker);
        } finally {
            IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
        }
    }

    /** Der externe Sensor MUSS zusaetzlich die Kuehlhydraulik hardwareseitig sperren. */
    public function DirectCoolingWatchdog(): void
    {
        $this->DirectCoolingReconcileUncertain();
        if (!$this->UsesDirectCooling() || $this->GetMode() !== self::MODE_COOLING
            || $this->DirectCoolingSafe()) {
            return;
        }
        // Die optionale Direktsteuerung darf niemals alte TS-Kuehlauftraege
        // der anderen Raeume abbrechen. Ohne tatsaechliche Reglersperre
        // ist die gesamte Schutzroutine inaktiv.
        if ($this->ReadJsonAttribute('DirectCoolingLocks') === []) {
            return;
        }
        if (!IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 1000)) {
            return;
        }
        try {
            // Alte TS-Kuehlauftraege sowie Rueckkehr-nach-Heizen-Jobs
            // bleiben bestehen. Die direkte Kuehlung prueft ihre Freigabe
            // vor jedem einzelnen Oeffnungs-Schaltbefehl erneut.
            foreach ($this->GetRuntimeRooms() as $room) {
                $target = (int) $room['TargetVariable'];
                $m = $this->DirectCoolingMapping($target)
                    ?? ($this->ReadJsonAttribute('DirectCoolingLockMappings')[(string) $target] ?? null);
                if (is_array($m) && $this->DirectCoolingLockState($target) !== '') {
                    $ok = $this->DirectCoolingRelaySet($m, false);
                    $this->SetRoomError($target, $ok
                        ? 'Kuehlfreigabe verloren: Schliessbefehl gesendet; Stellung ungeprueft.'
                        : 'ALARM: Kuehlfreigabe verloren; Schliessbefehl fehlgeschlagen!');
                }
            }
            $this->RefreshRuntimeStatus();
            $this->PushVisualizationState();
        } finally {
            IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
        }
    }

    /**
     * Raumspezifische Vorpruefung. Eine fehlende Direktfunktion darf die
     * funktionsfaehige alte TS-Kuehlung NICHT deaktivieren.
     * Ein bereits gesperrter/ungewisser Regler ist dagegen ein harter Stopp.
     */
    private function DirectCoolingEligibility(array $room): string
    {
        $target = (int) ($room['TargetVariable'] ?? 0);
        if ($this->DirectCoolingLockState($target) !== '') {
            return 'LCN-Regler noch gesperrt oder Zustand ungewiss';
        }
        $m = $this->DirectCoolingMapping($target);
        if ($m === null) {
            return 'keine Direktrelais-Zuordnung';
        }
        if (!$this->DirectCoolingSafe()) {
            return 'externe Freigabe fehlt';
        }
        if (!in_array((int) ($m['RegulatorIndex'] ?? -1), [0, 2], true)) {
            return 'LCN-Reglerindex nicht bestaetigt';
        }
        if (!array_key_exists('OpenRelayOn', $m)
            || !((bool) ($m['PolarityConfirmed'] ?? false))) {
            return 'Ventilpolaritaet nicht bestaetigt';
        }
        if ((int) ($m['RelayChannel'] ?? 0) < 1
            || (int) ($m['RelayChannel'] ?? 0) > 8
            || !$this->IsOperationalLCNModule((int) ($m['RelayModule'] ?? 0))
            || !$this->IsOperationalLCNModule((int) ($room['SendModule'] ?? 0))) {
            return 'LCN-Modul oder Relaiskanal nicht erreichbar';
        }
        foreach (['UnlockedVariable', 'RelayStatusVariable'] as $field) {
            $id = (int) ($m[$field] ?? 0);
            if ($id <= 0 || !IPS_VariableExists($id)) {
                return 'LCN-Rueckmeldung ' . $field . ' fehlt';
            }
            try {
                if ((int) IPS_GetVariable($id)['VariableType'] !== VARIABLETYPE_BOOLEAN) {
                    return 'LCN-Rueckmeldung ' . $field . ' nicht Boolean';
                }
            } catch (Throwable) {
                return 'LCN-Rueckmeldung ' . $field . ' nicht lesbar';
            }
        }
        foreach ($this->DirectCoolingMappings() as $otherTarget => $other) {
            if ((int) $otherTarget !== $target
                && (int) ($other['RelayModule'] ?? 0) === (int) $m['RelayModule']
                && (int) ($other['RelayChannel'] ?? 0) === (int) $m['RelayChannel']) {
                return 'Relaiskanal mehrfach konfiguriert';
            }
        }
        $relayFunction = (int) ($m['RelayUnit'] ?? 0) > 0
            ? 'LCN_SwitchRelay' : 'LCN_SetRelay';
        foreach (['LCN_LockTargetValue', 'LCN_ReleaseTargetValue', $relayFunction] as $fn) {
            if (!IPS_FunctionExists($fn)) {
                return 'LCN-Funktion ' . $fn . ' nicht vorhanden';
            }
        }
        // Keine fremde Reglersperre uebernehmen.
        $unlockedId = (int) ($m['UnlockedVariable'] ?? 0);
        try {
            if (!$this->DirectCoolingRegulatorUnlocked($m)) {
                return 'LCN-Regler bereits gesperrt; kein TS-Rueckfall erlaubt';
            }
            if (function_exists('IPS_GetParent')
                && IPS_GetParent($unlockedId) !== IPS_GetParent($target)) {
                return 'Reglersperren-Rueckmeldung gehoert nicht zum S1Target';
            }
        } catch (Throwable) {
            return 'Reglersperren-Rueckmeldung nicht lesbar';
        }
        return '';
    }

    private function ResetDirectCoolingSession(): void
    {
        // Nur vollstaendig entsperrte Raeume duerfen beim neuen Kuehlzyklus
        // einen erneuten Direktversuch erhalten.
        $map = $this->DirectCoolingFallbacks();
        foreach ($this->GetRuntimeRooms() as $room) {
            $target = (int) $room['TargetVariable'];
            if ($this->DirectCoolingLockState($target) === '') {
                unset($map[(string) $target]);
            }
        }
        $this->WriteJsonAttribute('CoolingFallbackReasons', $map);
    }

    private function DirectCoolingFallbacks(): array
    {
        try {
            return $this->ReadJsonAttribute('CoolingFallbackReasons');
        } catch (Throwable) {
            return [];
        }
    }

    private function DirectCoolingFallbackSet(int $target, string $reason, string $kind = 'legacy'): void
    {
        $map = $this->DirectCoolingFallbacks();
        if ($reason === '') {
            unset($map[(string) $target]);
        } else {
            $map[(string) $target] = [
                'reason' => $reason,
                'kind' => $kind,
                'time' => time()
            ];
        }
        $this->WriteJsonAttribute('CoolingFallbackReasons', $map);
    }

    private function DirectCoolingFallbackSummary(): string
    {
        if (!$this->UsesDirectCooling() || $this->GetMode() !== self::MODE_COOLING) {
            return '';
        }
        $all = $this->DirectCoolingFallbacks();
        $legacy = 0;
        $blocked = 0;
        $first = '';
        $firstBlocked = '';
        foreach ($this->GetRuntimeRooms() as $room) {
            $entry = $all[(string) $room['TargetVariable']] ?? null;
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['kind'] ?? '') === 'blocked') {
                $blocked++;
                if ($firstBlocked === '') {
                    $firstBlocked = (string) $room['Name'] . ': ' . (string) ($entry['reason'] ?? '');
                }
            } else {
                $legacy++;
            }
            if ($first === '') {
                $first = (string) $room['Name'] . ': ' . (string) ($entry['reason'] ?? '');
            }
        }
        if ($blocked > 0) {
            return 'Direktkühlung gestoppt (' . $blocked
                . ' Raum/Räume): Reglerzustand nicht sicher. Kein automatischer Rueckfall. '
                . $firstBlocked;
        }
        if ($legacy > 0) {
            return 'Neue Kühlsteuerung nicht verfügbar (' . $legacy
                . ' Raum/Räume) – bisherige Steuerung aktiv. ' . $first;
        }
        return '';
    }

    private function EnqueueLegacyCoolingJob(array $room, bool $state, bool $previous): void
    {
        $this->AppendJob([
            'Type' => $state ? self::JOB_COOL_UP : self::JOB_COOL_DOWN,
            'Name' => $room['Name'],
            'SendModule' => $room['SendModule'],
            'TargetVariable' => $room['TargetVariable'],
            'Table' => $room['Table'],
            'UpKey' => $room['UpKey'],
            'DownKey' => $room['DownKey'],
            'PreviousCoolingState' => $previous
        ]);
    }

    private function EnqueueCoolingAutomatically(array $room, bool $state, bool $previous): void
    {
        $target = (int) $room['TargetVariable'];
        $locked = $this->DirectCoolingLockState($target);
        if ($locked === 'uncertain') {
            $this->DirectCoolingFallbackSet($target, 'Reglerzustand ungewiss', 'blocked');
            if (!$state) {
                // AUS ist eine ausdruecklich erlaubte, physisch sicherere
                // Aktion: Strom vom NC-Ventil nehmen, aber KEINEN
                // ungewissen Regler entsperren und keinen Heizwert fahren.
                $this->DirectCoolingEnqueueEmergencyClose($room, $previous);
                return;
            }
            $this->SetValueIfChanged($this->CoolIdent($target), $previous);
            $this->SetRoomError($target, $room['Name'] . ': Reglerzustand ungewiss; EIN bleibt gesperrt.');
            return;
        }
        if ($locked !== '') {
            // Bestehenden Direktsperrzustand nur durch Direktlogik steuern;
            // vor Wiederherstellung niemals TS-Tasten an gesperrte Regler senden.
            $m = $this->DirectCoolingMapping($target)
                ?? ($this->ReadJsonAttribute('DirectCoolingLockMappings')[(string) $target] ?? null);
            if ($m === null) {
                $this->DirectCoolingFallbackSet($target, 'LCN-Regler gesperrt, Zuordnung fehlt', 'blocked');
                $this->SetValueIfChanged($this->CoolIdent($target), $previous);
                $this->SetRoomError($target, $room['Name'] . ': Regler gesperrt; manueller Eingriff erforderlich.');
                return;
            }
            $this->DirectCoolingEnqueue($room, $state, $previous);
            return;
        }
        // Eine konfigurierte externe Kuehlfreigabe sperrt KUEHLEN EIN
        // AUSNAHMSLOS, auch wenn dieser Raum bereits in der laufenden
        // Kuehlsaison auf die bewahrte A7/A8-Variante zurueckgefallen ist.
        $safetyID = (int) $this->ReadPropertyInteger('CoolingSafetyVariable');
        if ($state && $safetyID > 0 && !$this->DirectCoolingSafe()) {
            $this->DirectCoolingFallbackSet($target, 'Externe Kuehlfreigabe fehlt: Kuehlen EIN gesperrt', 'blocked');
            $this->SetValueIfChanged($this->CoolIdent($target), $previous);
            $this->SetRoomError($target, $room['Name'] . ': Kuehlfreigabe fehlt, keine Kuehlung eingeschaltet.');
            return;
        }
        $previousFallback = $this->DirectCoolingFallbacks()[(string) $target] ?? null;
        if (is_array($previousFallback) && ($previousFallback['kind'] ?? '') === 'legacy') {
            $this->EnqueueLegacyCoolingJob($room, $state, $previous);
            return;
        }
        $reason = $this->DirectCoolingEligibility($room);
        if (str_contains($reason, 'bereits gesperrt')
            || str_contains($reason, 'gehoert nicht zum S1Target')) {
            $this->DirectCoolingFallbackSet($target, $reason, 'blocked');
            if (!$state && str_contains($reason, 'bereits gesperrt')) {
                $this->DirectCoolingEnqueueEmergencyClose($room, $previous);
                return;
            }
            $this->SetValueIfChanged($this->CoolIdent($target), $previous);
            $this->SetRoomError($target, $room['Name'] . ': ' . $reason);
            return;
        }
        if ($reason === '') {
            $this->DirectCoolingFallbackSet($target, '');
            if ($state) {
                $this->DirectCoolingEnqueue($room, true, $previous);
            } else {
                // Bereits AUS und noch nicht durch dieses Modul gesperrt:
                // keine unnoetige Reglersperre fuer 11 Raeume am Kuehlstart.
                // Der bestehende TS-Schliessweg bleibt identisch zu v0.2.10.
                $this->EnqueueLegacyCoolingJob($room, false, $previous);
            }
            return;
        }
        $this->DirectCoolingFallbackSet($target, $reason);
        $this->EnqueueLegacyCoolingJob($room, $state, $previous);
    }

    /**
     * Die Direktsteuerung ist fehlgeschlagen: REGLER ENTSPERREN UND BESTAETIGEN,
     * erst danach ueberhaupt alte TS-Kuehlung anfordern. Bei unklarer
     * Rueckmeldung wird KEIN alter Befehl ausgefuehrt.
     */
    private function BeginDirectCoolingRecovery(array $job, string $reason): void
    {
        $target = (int) ($job['TargetVariable'] ?? 0);
        $m = $this->DirectCoolingMappingForJob($job);
        if ($m === null || !in_array((int) ($m['RegulatorIndex'] ?? -1), [0, 2], true)) {
            $this->DirectCoolingFallbackSet($target, $reason . ' (Reglerfreigabe nicht moeglich)', 'blocked');
            $job['Type'] = 'direct_recover';
            $this->FailCurrentJob($job, 'Kein automatischer Rueckfall: Reglerfreigabe nicht pruefbar.');
            return;
        }
        $this->DirectCoolingSetLockState($target, 'uncertain');
        $this->DirectCoolingFallbackSet($target, $reason . ' – Reglerfreigabe wird geprueft', 'blocked');
        $job['Type'] = 'direct_recover';
        $job['Phase'] = 'close';
        $job['FallbackReason'] = $reason;
        $this->YieldCurrentJob($job);
        $this->PushVisualizationMeta();
    }

    private function HandleDirectCoolingRecovery(array $job): void
    {
        $target = (int) $job['TargetVariable'];
        $m = $this->DirectCoolingMappingForJob($job);
        $phase = (string) ($job['Phase'] ?? '');
        $unlockId = (int) ($m['UnlockedVariable'] ?? 0);
        if ($m === null || !IPS_VariableExists($unlockId)) {
            $this->DirectCoolingFallbackSet($target, 'Rueckmeldevariable zur Reglerfreigabe fehlt', 'blocked');
            $this->FailCurrentJob($job, 'Automatischer Rueckfall blockiert: Entriegelt-Rueckmeldung fehlt.');
            return;
        }
        if ($phase === 'close') {
            // Ein zuvor unbestaetigter Direktschaltbefehl koennte das
            // Ventil noch bestromen. Vor dem Legacy-Rueckfall zuerst
            // Relais AUS senden und diesen elektrischen Zustand bestaetigen.
            if (!$this->DirectCoolingRelaySet($m, false)) {
                $this->DirectCoolingFallbackSet($target, 'Relais konnte vor Rueckfall nicht abgeschaltet werden', 'blocked');
                $this->FailCurrentJob($job, 'Kein Rueckfall: Relais AUS konnte nicht gesendet werden.');
                return;
            }
            $job['Phase'] = 'close_wait';
            $job['SentAtMs'] = $this->NowMs();
            $job['SentAtEpoch'] = time();
            $this->YieldCurrentJob($job);
            return;
        }
        if ($phase === 'close_wait') {
            $statusID = (int) ($m['RelayStatusVariable'] ?? 0);
            if (!IPS_VariableExists($statusID)) {
                $this->FailCurrentJob($job, 'Relaisrueckmeldung fehlt: kein automatischer Rueckfall.');
                return;
            }
            $want = !(bool) ($m['OpenRelayOn'] ?? true);
            if ($this->DirectCoolingFeedbackFresh($statusID, $job)
                && (bool) GetValue($statusID) === $want
                && $this->NowMs() - (int) ($job['SentAtMs'] ?? 0) >= 500) {
                $job['Phase'] = 'unlock';
                $this->YieldCurrentJob($job);
                return;
            }
            if ($this->NowMs() - (int) ($job['SentAtMs'] ?? 0) > $this->DirectCoolingTimeoutMs()) {
                $this->DirectCoolingFallbackSet($target, 'Relais AUS nicht bestaetigt', 'blocked');
                $this->FailCurrentJob($job, 'Relais AUS nicht bestaetigt: Regler bleibt gesperrt, kein Rueckfall.');
                return;
            }
            $job = $this->DirectCoolingRequestFreshRelay($job, $m);
            $this->YieldCurrentJob($job);
            return;
        }
        if ($phase === 'unlock') {
            if (!$this->DirectCoolingControllerCommand($job, $m, false)) {
                $this->DirectCoolingFallbackSet($target, 'Reglerfreigabe konnte nicht gesendet werden', 'blocked');
                $this->FailCurrentJob($job, 'Automatischer Rueckfall blockiert: Reglerfreigabe fehlgeschlagen.');
                return;
            }
            $job['Phase'] = 'unlock_wait';
            $job['SentAtMs'] = $this->NowMs();
            $job['SentAtEpoch'] = time();
            $this->YieldCurrentJob($job);
            return;
        }
        if ($phase === 'unlock_wait') {
            if ($this->DirectCoolingFeedbackFresh($unlockId, $job)
                && $this->DirectCoolingRegulatorUnlocked($m)
                && $this->NowMs() - (int) ($job['SentAtMs'] ?? 0) >= 500) {
                $job['Phase'] = 'normal_target_wait';
                $job['SentAtMs'] = $this->NowMs();
                $this->YieldCurrentJob($job);
                return;
            }
            if ($this->NowMs() - (int) ($job['SentAtMs'] ?? 0) > $this->DirectCoolingTimeoutMs()) {
                $this->DirectCoolingFallbackSet($target, 'Reglerfreigabe nicht bestaetigt', 'blocked');
                $this->FailCurrentJob($job, 'Regler nicht sicher entsperrt: kein automatischer Rueckfall.');
                return;
            }
            $this->YieldCurrentJob($job);
            return;
        }
        if ($phase === 'normal_target_wait') {
            if (!$this->DirectCoolingRegulatorUnlocked($m)) {
                $this->DirectCoolingFallbackSet($target, 'Regler erneut gesperrt', 'blocked');
                $this->FailCurrentJob($job, 'Regler erneut gesperrt; kein automatischer Rueckfall.');
                return;
            }
            if ($this->ReadTarget($target) !== null
                && $this->DirectCoolingFeedbackFresh($target, $job)) {
                $this->DirectCoolingSetLockState($target, '');
                $reason = (string) ($job['FallbackReason'] ?? 'Direkte Kuehlung nicht bestaetigt');
                $this->DirectCoolingFallbackSet($target, $reason);
                $this->SetBuffer('CurrentJob', '');
                $nextWanted = $this->ConsumePendingCoolingRequest($target);
                $restoreWanted = $nextWanted ?? (bool) ($job['DesiredOpen'] ?? false);
                $this->SetValueIfChanged($this->CoolIdent($target), $restoreWanted);
                $room = $this->FindRoomByTarget($target);
                if ($room !== null && $this->GetMode() === self::MODE_COOLING
                    && (!$restoreWanted || $this->DirectCoolingSafe())) {
                    $this->EnqueueLegacyCoolingJob(
                        $room,
                        $restoreWanted,
                        (bool) ($job['PreviousCoolingState'] ?? false)
                    );
                    $this->StartWorkerIfNeeded();
                }
                if ($restoreWanted && !$this->DirectCoolingSafe()) {
                    $this->DirectCoolingFallbackSet($target, 'Externe Kuehlfreigabe verloren: Kuehlung blockiert', 'blocked');
                    $this->SetRoomError($target, 'Kuehlfreigabe fehlt; keine Ersatzsteuerung gestartet.');
                } else {
                    $this->ClearRoomError($target);
                }
                $this->RefreshRuntimeStatus();
                $this->PushVisualizationRowByTarget($target);
                $this->PushVisualizationMeta();
                return;
            }
            if ($this->NowMs() - (int) ($job['SentAtMs'] ?? 0) > 120000) {
                $this->DirectCoolingFallbackSet($target, 'Nach Freigabe kein gueltiger Heizsollwert', 'blocked');
                $this->FailCurrentJob($job, 'Regler freigegeben, aber Sollwert unplausibel; kein automatischer Rueckfall.');
                return;
            }
            $job = $this->DirectCoolingRequestFreshTarget($job);
            $this->YieldCurrentJob($job);
            return;
        }
        $this->DirectCoolingFallbackSet($target, 'Unbekannter Freigabezustand', 'blocked');
        $this->FailCurrentJob($job, 'Unbekannter Rueckfallzustand.');
    }

    private function DirectCoolingRequestFreshRelay(array $job, array $mapping): array
    {
        // Der elektrische Relaisstatus kann bereits den Sollzustand haben.
        // Bei unveraendertem Zustand wird nicht zwingend automatisch eine
        // neue Rueckmeldung gesendet: Statusabfrage vorsichtig takten.
        $now = $this->NowMs();
        if ($now - (int) ($job['LastRelayReadAtMs'] ?? 0) < 9000) {
            return $job;
        }
        $module = (int) ($mapping['RelayModule'] ?? 0);
        if (!$this->IsOperationalLCNModule($module)
            || !IPS_FunctionExists('LCN_RequestStatus')) {
            return $job;
        }
        $job['LastRelayReadAtMs'] = $now;
        if (IPS_SemaphoreEnter(self::GLOBAL_LCN_SEND_SEMAPHORE, 500)) {
            try {
                LCN_RequestStatus($module);
            } catch (Throwable $e) {
                $this->SendDebug('DirectCooling', 'Relais-Statusabfrage: ' . $e->getMessage(), 0);
            } finally {
                IPS_SemaphoreLeave(self::GLOBAL_LCN_SEND_SEMAPHORE);
            }
        }
        return $job;
    }

    private function DirectCoolingRequestFreshTarget(array $job): array
    {
        // Die LCN-Target-Variable wird nach dem Entsperren nicht zwingend
        // automatisch aktualisiert. Eine gezielte native Statusabfrage
        // darf erst nach bestaetigter Reglersperren-Freigabe stattfinden.
        $now = $this->NowMs();
        if ($now - (int) ($job['LastReadAtMs'] ?? 0) < 9000) {
            return $job;
        }
        $target = (int) ($job['TargetVariable'] ?? 0);
        if (!IPS_VariableExists($target) || !IPS_FunctionExists('LCN_RequestRead')) {
            return $job;
        }
        $valueInstance = IPS_GetParent($target);
        if ($valueInstance <= 0 || !IPS_InstanceExists($valueInstance)) {
            return $job;
        }
        try {
            $info = IPS_GetInstance($valueInstance);
            if (strtoupper((string) ($info['ModuleInfo']['ModuleID'] ?? ''))
                !== strtoupper(self::LCN_VALUE_MODULE_ID)
                || (int) ($info['ConnectionID'] ?? 0) !== (int) ($job['SendModule'] ?? 0)) {
                return $job;
            }
        } catch (Throwable) {
            return $job;
        }
        $job['LastReadAtMs'] = $now;
        if (IPS_SemaphoreEnter(self::GLOBAL_LCN_SEND_SEMAPHORE, 500)) {
            try {
                LCN_RequestRead($valueInstance);
            } catch (Throwable $e) {
                $this->SendDebug('DirectCooling', 'S1Target-Statusabfrage: ' . $e->getMessage(), 0);
            } finally {
                IPS_SemaphoreLeave(self::GLOBAL_LCN_SEND_SEMAPHORE);
            }
        }
        return $job;
    }

    private function DirectCoolingFeedbackFresh(int $id, array $job): bool
    {
        try {
            $variable = IPS_GetVariable($id);
            // Sekundenauflosung des IPS-Zeitstempels:
            // Aktualisierung muss in/seit der Befehlssekunde erfolgt sein.
            return (int) ($variable['VariableUpdated'] ?? 0) >= (int) ($job['SentAtEpoch'] ?? PHP_INT_MAX);
        } catch (Throwable) {
            return false;
        }
    }
}
