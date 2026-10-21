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
        return $all[$target] ?? null;
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
        } else {
            $map[(string) $target] = $state;
        }
        $this->WriteJsonAttribute('DirectCoolingLocks', $map);
    }

    private function DirectCoolingSafe(): bool
    {
        $id = $this->ReadPropertyInteger('CoolingSafetyVariable');
        if ($id <= 0 || !IPS_VariableExists($id)) {
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
            if (!array_key_exists('OpenRelayOn', $m)) {
                $errors[] = $room['Name'] . ': elektrische AUF-Polaritaet nicht hinterlegt.';
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
            'Phase' => 'lock'
        ]);
    }

    private function DirectCoolingEnqueueRelease(array $room, float $restore): void
    {
        $this->AppendJob([
            'Type' => 'direct_release',
            'Name' => $room['Name'],
            'SendModule' => $room['SendModule'],
            'TargetVariable' => $room['TargetVariable'],
            'RestoreTarget' => $restore,
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
        if (!IPS_FunctionExists('LCN_SetRelay')
            || !$this->IsOperationalLCNModule($relayModule)) {
            return false;
        }
        if (!IPS_SemaphoreEnter(self::GLOBAL_LCN_SEND_SEMAPHORE, 2000)) {
            return false;
        }
        try {
            return (bool) LCN_SetRelay($relayModule, $mask);
        } catch (Throwable $e) {
            $this->SendDebug('DirectCooling', 'LCN_SetRelay: ' . $e->getMessage(), 0);
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

    private function DirectCoolingWait(array $job, string $next, string $name, bool $actual, bool $expected): bool
    {
        if ($actual === $expected && $this->NowMs() - (int) ($job['SentAtMs'] ?? 0) >= 500) {
            $job['Phase'] = $next;
            $this->YieldCurrentJob($job);
            return true;
        }
        if ($this->NowMs() - (int) ($job['SentAtMs'] ?? 0) > 7000) {
            $this->FailCurrentJob($job, $name . ': keine bestaetigte Rueckmeldung innerhalb 7 s. LCN-Zustand bitte vor Ort pruefen.');
            return true;
        }
        $this->YieldCurrentJob($job);
        return true;
    }

    private function HandleDirectCoolingJob(array $job): void
    {
        $target = (int) $job['TargetVariable'];
        $m = $this->DirectCoolingMapping($target);
        if ($m === null || !$this->IsOperationalLCNModule((int) $job['SendModule'])) {
            $this->FailCurrentJob($job, 'Relais-/Modul-Zuordnung nicht mehr gueltig.');
            return;
        }
        $type = (string) $job['Type'];
        $phase = (string) ($job['Phase'] ?? ($type === 'direct_cool' ? 'lock' : 'close'));
        $unlockID = (int) ($m['UnlockedVariable'] ?? 0);
        $statusID = (int) ($m['RelayStatusVariable'] ?? 0);
        if (!IPS_VariableExists($unlockID) || !IPS_VariableExists($statusID)) {
            $this->FailCurrentJob($job, 'Statusvariable entfernt; keine unbestaetigten Ventilbefehle.');
            return;
        }
        $unlocked = (bool) GetValue($unlockID);
        $electric = (bool) GetValue($statusID);
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
                $this->DirectCoolingSetLockState($target, 'uncertain');
                if (!$this->DirectCoolingControllerCommand($job, $m, true)) {
                    $this->FailCurrentJob($job, 'Reglersperre nicht gesendet; Ventil unveraendert.');
                    return;
                }
                $job['Phase'] = 'lock_wait';
                $job['SentAtMs'] = $this->NowMs();
                $this->YieldCurrentJob($job);
                return;
            }
            if ($phase === 'lock_wait') {
                if (!$unlocked) {
                    $this->DirectCoolingSetLockState($target, 'locked');
                }
                $this->DirectCoolingWait($job, 'relay', 'Reglersperre', $unlocked, false);
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
                $this->YieldCurrentJob($job);
                return;
            }
            if ($phase === 'relay_wait') {
                $want = $open ? (bool) $m['OpenRelayOn'] : !(bool) $m['OpenRelayOn'];
                if ($electric === $want && $this->NowMs() - (int) ($job['SentAtMs'] ?? 0) >= 500) {
                    $this->StoreCoolingState($target, $open);
                    $this->SetValueIfChanged($this->CoolIdent($target), $open);
                    $this->SetBuffer('CurrentJob', '');
                    $this->ClearRoomError($target);
                    $this->RefreshRuntimeStatus();
                    $this->PushVisualizationRowByTarget($target);
                    $this->PushVisualizationMeta();
                    return;
                }
                $this->DirectCoolingWait($job, 'relay_wait', 'Relaiszustand', $electric, $want);
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
                $this->YieldCurrentJob($job);
                return;
            }
            if ($phase === 'close_wait') {
                $want = !(bool) $m['OpenRelayOn'];
                $this->DirectCoolingWait($job, 'unlock', 'Ventil geschlossen', $electric, $want);
                return;
            }
            if ($phase === 'unlock') {
                if (!$this->DirectCoolingControllerCommand($job, $m, false)) {
                    $this->FailCurrentJob($job, 'Regler konnte nicht freigegeben werden.');
                    return;
                }
                $job['Phase'] = 'unlock_wait';
                $job['SentAtMs'] = $this->NowMs();
                $this->YieldCurrentJob($job);
                return;
            }
            if ($phase === 'unlock_wait') {
                if ($unlocked && $this->NowMs() - (int) ($job['SentAtMs'] ?? 0) >= 500) {
                    $this->DirectCoolingSetLockState($target, '');
                    $this->SetBuffer('CurrentJob', '');
                    $this->ClearRoomError($target);
                    $room = $this->FindRoomByTarget($target);
                    if ($room !== null) {
                        $this->EnqueueHeatJob($room, (float) $job['RestoreTarget']);
                    }
                    $this->RefreshRuntimeStatus();
                    $this->PushVisualizationRowByTarget($target);
                    return;
                }
                $this->DirectCoolingWait($job, 'unlock_wait', 'Regler freigegeben', $unlocked, true);
                return;
            }
        }
        $this->FailCurrentJob($job, 'Unbekannte Direktkuehlungsphase: ' . $phase);
    }

    /** Der externe Sensor MUSS zusaetzlich die Kuehlhydraulik hardwareseitig sperren. */
    public function DirectCoolingWatchdog(): void
    {
        if (!$this->UsesDirectCooling() || $this->GetMode() !== self::MODE_COOLING
            || $this->DirectCoolingSafe()) {
            return;
        }
        if (!IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 1000)) {
            return;
        }
        try {
            // Bei Freigabeverlust kein weiteres Oeffnen zulassen.
            $this->SetBuffer('Queue', '[]');
            $this->SetBuffer('CurrentJob', '');
            $this->ClearPendingMode();
            foreach ($this->GetRuntimeRooms() as $room) {
                $target = (int) $room['TargetVariable'];
                $m = $this->DirectCoolingMapping($target);
                if ($m !== null && $this->DirectCoolingLockState($target) !== '') {
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
}
