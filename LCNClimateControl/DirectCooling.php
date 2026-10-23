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
            // A genuinely confirmed release resets the bounded automatic
            // recovery counter; never clear it on mere command acceptance.
            $retries = $this->ReadJsonAttribute('DirectCoolingReleaseAttempts');
            if (isset($retries[(string) $target])) {
                unset($retries[(string) $target]);
                $this->WriteJsonAttribute('DirectCoolingReleaseAttempts', $retries);
            }
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
        // Independent physical dewpoint/hydraulic shutdown is mandatory in
        // BOTH modes. Checkbox is an operator signoff, NOT the protection.
        // The physical interlock cannot be verified remotely.
        if (!$this->ReadPropertyBoolean('CoolingHardwareInterlockConfirmed')
            || !$this->ReadPropertyBoolean('CoolingHeatingResumeConfirmed')) {
            return false;
        }
        $id = $this->ReadPropertyInteger('CoolingSafetyVariable');
        if ($id <= 0) {
            // Manual hydraulic mode: the operator selects KUEHLEN in Symcon
            // after switching the plant in the heating room. This explicit
            // installation opt-in replaces ONLY the missing software Boolean.
            // A user selection is not automatic feedback from the plant.
            return $this->ReadPropertyBoolean('ManualCoolingApproval');
        }
        // If configured, an external Boolean must always be respected:
        // the manual option can NEVER override an explicit safety veto.
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
        if ($safetyID <= 0) {
            if (!$this->ReadPropertyBoolean('ManualCoolingApproval')) {
                $errors[] = 'Weder manuelle Freigabe noch externe Kuehlfreigabe eingerichtet.';
            }
        } elseif (!IPS_VariableExists($safetyID)) {
            $errors[] = 'Konfigurierte externe Kuehlfreigabe nicht gefunden.';
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
            // This installation uses S1 only. No guessed R1 fallback.
            if ($lock !== 2 || $this->DirectCoolingLockActionID($room, $m) <= 0) {
                $errors[] = $room['Name'] . ': native S1TargetLock-Standardaktion fehlt.';
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
            $this->ReleaseLCNBusSlot();
        }
    }

    /**
     * Native Symcon S1 action, not a guessed LCN controller index.
     * Read-only validation shared by preflight and the actual command.
     */
    private function DirectCoolingLockActionID(array $room, array $m): int
    {
        $sender = (int) ($room['SendModule'] ?? 0);
        $target = (int) ($room['TargetVariable'] ?? 0);
        $lockId = (int) ($m['UnlockedVariable'] ?? 0);
        if ($sender <= 0 || $target <= 0 || $lockId <= 0
            || (int) ($m['RegulatorIndex'] ?? -1) !== 2
            || !(bool) ($m['LockFeedbackIsLocked'] ?? false)
            || !IPS_FunctionExists('RequestAction')
            || !$this->IsOperationalLCNModule($sender)
            || !IPS_VariableExists($lockId) || !IPS_VariableExists($target)
            || !$this->IsOperationalTargetVariable($target, $sender)) {
            return 0;
        }
        try {
            $parent = (int) IPS_GetParent($lockId);
            $v = IPS_GetVariable($lockId);
            if ($parent <= 0 || !IPS_InstanceExists($parent)
                || $parent !== (int) IPS_GetParent($target)
                || (int) ($v['VariableType'] ?? -1) !== VARIABLETYPE_BOOLEAN
                || (int) ($v['VariableAction'] ?? 0) !== $parent
                || (string) (IPS_GetObject($lockId)['ObjectIdent'] ?? '') !== 'S1TargetLock'
                || (int) (IPS_GetInstance($parent)['ConnectionID'] ?? 0) !== $sender) {
                return 0;
            }
        } catch (Throwable) {
            return 0;
        }
        return $lockId;
    }

    private function DirectCoolingControllerCommand(array $job, array $m, bool $lock): bool
    {
        $id = $this->DirectCoolingLockActionID($job, $m);
        if ($id <= 0 || !IPS_SemaphoreEnter(self::GLOBAL_LCN_SEND_SEMAPHORE, 2000)) {
            return false;
        }
        try {
            return (bool) RequestAction($id, $lock);
        } catch (Throwable $e) {
            $this->SendDebug('DirectCooling', 'S1 RequestAction #' . $id
                . ': ' . $e->getMessage(), 0);
            return false;
        } finally {
            $this->ReleaseLCNBusSlot(); // 750 ms, only one LCN command
        }
    }

    private function DirectCoolingTimeoutMs(): int
    {
        // 11 Raeume teilen sich einen kooperativen 250-ms-Worker.
        // Starre 7 s haetten bei paralleler Umschaltung falsch-positive
        // Timeouts erzeugt.
        // 750 ms bus cooldowns + up to 11 round-robin rooms + explicit
        // independent LCN read confirmations. Avoid false timeouts.
        return min(60000, max(20000, 8000 + count($this->GetRuntimeRooms()) * 3000));
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
                    // FALSE is not proof that no command reached LCN.
                    // Always close the valve and try to release S1.
                    $this->BeginDirectCoolingRecovery($job,
                        'S1-Sperrbefehl nicht angenommen, sichere Rueckkehr');
                    return;
                }
                $job['Phase'] = 'lock_wait';
                $job['SentAtMs'] = $this->NowMs();
                $job['SentAtEpoch'] = time();
                $this->YieldCurrentJob($job);
                return;
            }
            if ($phase === 'lock_wait') {
                if (!$unlocked && $this->DirectCoolingFeedbackFresh($unlockID, $job)
                    && $this->DirectCoolingLockFeedbackAfterRead($unlockID, $job)) {
                    $this->DirectCoolingSetLockState($target, 'locked');
                    $this->DirectCoolingWait($job, 'relay', 'Reglersperre', false, false, $unlockID);
                    return;
                }
                if ($this->NowMs() - (int) ($job['SentAtMs'] ?? 0)
                    > $this->DirectCoolingTimeoutMs()) {
                    $this->BeginDirectCoolingRecovery($job,
                        'S1-Sperre nicht bestaetigt, Ventil AUS und S1-Freigabe');
                    return;
                }
                $job = $this->DirectCoolingRequestFreshLock($job);
                $this->YieldCurrentJob($job);
                return;
            }
            if ($phase === 'relay') {
                if ($unlocked || $this->DirectCoolingLockState($target) !== 'locked') {
                    $this->FailCurrentJob($job, 'Regler nicht nachweislich gesperrt; Relaisbefehl unterdrueckt.');
                    return;
                }
                if (!$this->DirectCoolingRelaySet($m, $open)) {
                    $this->BeginDirectCoolingRecovery($job,
                        'Relaisbefehl unbestaetigt, Rueckweg Ventil AUS');
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
                if ($this->NowMs() - (int) ($job['SentAtMs'] ?? 0)
                    > $this->DirectCoolingTimeoutMs()) {
                    $this->BeginDirectCoolingRecovery($job,
                        'Relaisrueckmeldung fehlt, Rueckweg Ventil AUS');
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
                // Brief IP-Symcon/LCN bus contention must not permanently
                // strand this room in S1 LOCKED after switching to HEIZEN.
                // RELEASE is idempotent, limited and still verified by a
                // fresh native read before ANY heating TS command is allowed.
                $retryNotBefore = (int) ($job['ReleaseRetryNotBeforeMs'] ?? 0);
                if ($retryNotBefore > $this->NowMs()) {
                    $this->YieldCurrentJob($job);
                    return;
                }
                $attempts = (int) ($job['ReleaseCommandAttempts'] ?? 0) + 1;
                $job['ReleaseCommandAttempts'] = $attempts;
                if (!$this->DirectCoolingControllerCommand($job, $m, false)) {
                    if ($attempts >= 3) {
                        $this->FailCurrentJob($job,
                            'S1-Freigabebefehl 3-mal nicht angenommen; Heizfahrt bleibt gesperrt.');
                        return;
                    }
                    $job['ReleaseRetryNotBeforeMs'] = $this->NowMs() + 5000;
                    $this->YieldCurrentJob($job);
                    return;
                }
                unset($job['ReleaseRetryNotBeforeMs']);
                $job['Phase'] = 'unlock_wait';
                $job['SentAtMs'] = $this->NowMs();
                $job['SentAtEpoch'] = time();
                $this->YieldCurrentJob($job);
                return;
            }
            if ($phase === 'unlock_wait') {
                if ($unlocked && $this->DirectCoolingFeedbackFresh($unlockID, $job)
                    && $this->DirectCoolingLockFeedbackAfterRead($unlockID, $job)
                    && $this->NowMs() - (int) ($job['SentAtMs'] ?? 0) >= 500) {
                    $job['Phase'] = 'restore_target_wait';
                    $job['SentAtMs'] = $this->NowMs();
                    $this->YieldCurrentJob($job);
                    return;
                }
                $job = $this->DirectCoolingRequestFreshLock($job);
                $this->DirectCoolingWait($job, 'unlock_wait', 'Regler freigegeben', false, true, $unlockID);
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
                    // A heating setpoint selected while the regulator was
                    // locked supersedes the original switch-back target.
                    $restore = $this->GetLastHeatingTarget($target);
                    if ($room !== null && $restore !== null) {
                        $this->EnqueueHeatJob($room, $restore);
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
     * Restore HEATING after transient failed S1 release, including failures
     * persisted before a module update. At most three scheduled recovery
     * jobs per room. Never unlock in COOLING or when the worker owns a job.
     * The actual release always starts with NC relay OFF confirmation.
     */
    private function DirectCoolingRecoverHeatingLocks(): void
    {
        if ($this->GetMode() !== self::MODE_HEATING
            || $this->GetPendingMode() !== null
            || $this->CoolingCommissionActive()
            || $this->M11ProbeActive()) {
            return;
        }
        $locks = $this->ReadJsonAttribute('DirectCoolingLocks');
        if ($locks === []) {
            return;
        }
        $attempts = $this->ReadJsonAttribute('DirectCoolingReleaseAttempts');
        $scheduled = false;
        foreach ($this->GetRuntimeRooms() as $room) {
            $target = (int) ($room['TargetVariable'] ?? 0);
            if ($target <= 0 || !isset($locks[(string) $target])
                || $this->HasJobForTarget($target)) {
                continue;
            }
            $count = (int) ($attempts[(string) $target] ?? 0);
            if ($count >= 3) {
                $this->SetRoomError($target, (string) $room['Name']
                    . ': S1-Freigabe nach drei sicheren Versuchen nicht bestaetigt;'
                    . ' Heizbefehle bleiben blockiert. LCN-Status pruefen.');
                continue;
            }
            $mapping = $this->DirectCoolingMapping($target)
                ?? ($this->ReadJsonAttribute('DirectCoolingLockMappings')[(string) $target] ?? null);
            if (!is_array($mapping)
                || (int) ($mapping['UnlockedVariable'] ?? 0) <= 0
                || (int) ($mapping['RelayStatusVariable'] ?? 0) <= 0) {
                $this->SetRoomError($target, (string) $room['Name']
                    . ': S1-Freigabe nicht moeglich, LCN-Zuordnung fehlt; kein Heizbefehl.');
                continue;
            }
            $attempts[(string) $target] = $count + 1;
            // Persist the bounded attempt BEFORE enqueueing the hardware job.
            $this->WriteJsonAttribute('DirectCoolingReleaseAttempts', $attempts);
            $this->DirectCoolingEnqueueRelease($room, $this->GetLastHeatingTarget($target));
            $scheduled = true;
        }
        if ($scheduled) {
            $this->StartWorkerIfNeeded();
            $this->PushVisualizationState();
        }
    }

    /** Der externe Sensor MUSS zusaetzlich die Kuehlhydraulik hardwareseitig sperren. */
    public function DirectCoolingWatchdog(): void
    {
        if (!$this->UsesDirectCooling()) {
            return;
        }
        if ($this->GetMode() === self::MODE_HEATING) {
            // The user switches HEIZEN manually. A stranded LCN S1 lock
            // must be restored automatically rather than breaking the tile.
            if ($this->ReadJsonAttribute('DirectCoolingLocks') === []
                || !IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 1000)) {
                return;
            }
            try {
                $this->DirectCoolingRecoverHeatingLocks();
            } finally {
                IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
            }
            return;
        }
        if ($this->GetMode() !== self::MODE_COOLING || $this->DirectCoolingSafe()) {
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
                    $statusID = (int) ($m['RelayStatusVariable'] ?? 0);
                    $expectedOff = !(bool) ($m['OpenRelayOn'] ?? true);
                    $alreadyConfirmedOff = $statusID > 0 && IPS_VariableExists($statusID)
                        && (bool) GetValue($statusID) === $expectedOff
                        && (int) (IPS_GetVariable($statusID)['VariableUpdated'] ?? 0)
                            > time() - 180;
                    // No bus burst every 15 seconds for valves already
                    // confirmed OFF; still resend if state is ON or stale.
                    if ($alreadyConfirmedOff) {
                        continue;
                    }
                    $ok = $this->DirectCoolingRelaySet($m, false);
                    $this->SetRoomError($target, $ok
                        ? 'Kuehlfreigabe verloren: Ventil-AUS angefordert, Rueckmeldung ausstehend.'
                        : 'ALARM: Kuehlfreigabe verloren; Ventil-AUS fehlgeschlagen!');
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
            return 'manuelle/externe Kuehlfreigabe oder Sicherheitsabnahme fehlt';
        }
        if ($this->DirectCoolingLockActionID($room, $m) <= 0) {
            return 'native S1TargetLock-Standardaktion fehlt oder nicht eindeutig';
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
        foreach (['RequestAction', $relayFunction] as $fn) {
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
        // KUEHLEN EIN erfordert IMMER die gueltige Freigabe. In manueller
        // Betriebsart ersetzt die explizite Symcon-Freigabe die fehlende
        // externe Betriebsartmeldung, niemals den Taupunktschutz.
        if ($state && !$this->DirectCoolingSafe()) {
            $this->DirectCoolingFallbackSet($target, 'Kuehlfreigabe fehlt: Kuehlen EIN gesperrt', 'blocked');
            $this->SetValueIfChanged($this->CoolIdent($target), $previous);
            $this->SetRoomError($target, $room['Name'] . ': Kuehlfreigabe fehlt, keine Kuehlung eingeschaltet.');
            return;
        }
        $previousFallback = $this->DirectCoolingFallbacks()[(string) $target] ?? null;
        if (is_array($previousFallback) && ($previousFallback['kind'] ?? '') === 'legacy') {
            $this->DirectCoolingFallbackSet($target, 'Alter TS-Kuehlrueckfall deaktiviert', 'blocked');
            $this->SetValueIfChanged($this->CoolIdent($target), $previous);
            $this->SetRoomError($target, 'Kein Kuehlbefehl: bisheriger TS-Rueckfall ist aus Sicherheitsgruenden deaktiviert.');
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
            // Every room must have its LCN heater regulator held LOCKED
            // throughout COOLING, including a room requested OFF.
            // Otherwise an unlocked heating regulator may reenergize a
            // supposedly closed relay when room temperature drops.
            // No A7/A8 travel and no setpoint modifications in cooling.
            $this->DirectCoolingEnqueue($room, $state, $previous);
            return;
        }
        // No fallback from proven/attempted direct cooling to TS
        // upper/lower travel. A failed interlock must not open a valve.
        $this->DirectCoolingFallbackSet($target, $reason, 'blocked');
        $this->SetValueIfChanged($this->CoolIdent($target), $previous);
        $this->SetRoomError($target, $room['Name']
            . ': Direkter Kuehlweg ungueltig; kein automatischer TS-Rueckfall: ' . $reason);
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
                && $this->DirectCoolingLockFeedbackAfterRead($unlockId, $job)
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
            $job = $this->DirectCoolingRequestFreshLock($job);
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
                    // Deliberately do NOT fall back to TS/A7/A8 after
                    // any direct-controller failure. Keep cooling stopped.
                    $this->DirectCoolingFallbackSet($target,
                        'Rueckfall auf alte TS-Kuehlsteuerung deaktiviert', 'blocked');
                    $this->SetRoomError($target,
                        'Kuehlraum blockiert nach Direkt-Fehler; kein A7/A8-Rueckfall.');
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
        if (IPS_SemaphoreEnter(self::GLOBAL_LCN_SEND_SEMAPHORE, 2000)) {
            try {
                LCN_RequestStatus($module);
            } catch (Throwable $e) {
                $this->SendDebug('DirectCooling', 'Relais-Statusabfrage: ' . $e->getMessage(), 0);
            } finally {
                $this->ReleaseLCNBusSlot();
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
        if (IPS_SemaphoreEnter(self::GLOBAL_LCN_SEND_SEMAPHORE, 2000)) {
            try {
                LCN_RequestRead($valueInstance);
            } catch (Throwable $e) {
                $this->SendDebug('DirectCooling', 'S1Target-Statusabfrage: ' . $e->getMessage(), 0);
            } finally {
                $this->ReleaseLCNBusSlot();
            }
        }
        return $job;
    }

    /** Independent native LCN read: RequestAction's local Boolean update alone is insufficient. */
    private function DirectCoolingLockFeedbackAfterRead(int $lockID, array $job): bool
    {
        $readAt = (int) ($job['LockReadAtEpoch'] ?? 0);
        $sentAt = (int) ($job['SentAtEpoch'] ?? 0);
        return (bool) ($job['LockReadOK'] ?? false)
            && $readAt > $sentAt
            && IPS_VariableExists($lockID)
            && (int) (IPS_GetVariable($lockID)['VariableUpdated'] ?? 0) >= $readAt;
    }

    private function DirectCoolingRequestFreshLock(array $job): array
    {
        $now = $this->NowMs();
        if ($now - (int) ($job['LastLockReadAtMs'] ?? 0) < 5000
            || !IPS_FunctionExists('LCN_RequestRead')) {
            return $job;
        }
        $target = (int) ($job['TargetVariable'] ?? 0);
        if (!IPS_VariableExists($target)) {
            return $job;
        }
        $valueInstance = (int) IPS_GetParent($target);
        if (!IPS_InstanceExists($valueInstance)) {
            return $job;
        }
        $data = IPS_GetInstance($valueInstance);
        if ((int) ($data['ConnectionID'] ?? 0) !== (int) ($job['SendModule'] ?? 0)
            || strtoupper((string) ($data['ModuleInfo']['ModuleID'] ?? ''))
                !== strtoupper(self::LCN_VALUE_MODULE_ID)) {
            return $job;
        }
        $job['LastLockReadAtMs'] = $now;
        $job['LockReadAtEpoch'] = time();
        $job['LockReadOK'] = false;
        if (!IPS_SemaphoreEnter(self::GLOBAL_LCN_SEND_SEMAPHORE, 2000)) {
            return $job;
        }
        try {
            $job['LockReadOK'] = (bool) LCN_RequestRead($valueInstance);
        } catch (Throwable $e) {
            $this->SendDebug('DirectCooling', 'S1TargetLock-Leseabfrage: ' . $e->getMessage(), 0);
        } finally {
            $this->ReleaseLCNBusSlot();
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
