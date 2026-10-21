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
            'Phase' => 'lock'
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
                $this->DirectCoolingWait($job, 'unlock_wait', 'Regler freigegeben', $unlocked, true, $unlockID);
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
        foreach (['LCN_LockTargetValue', 'LCN_ReleaseTargetValue', 'LCN_SetRelay'] as $fn) {
            if (!IPS_FunctionExists($fn)) {
                return 'LCN-Funktion ' . $fn . ' nicht vorhanden';
            }
        }
        // Keine fremde Reglersperre uebernehmen.
        $unlockedId = (int) ($m['UnlockedVariable'] ?? 0);
        try {
            if (!(bool) GetValue($unlockedId)) {
                return 'LCN-Regler bereits gesperrt; kein TS-Rueckfall erlaubt';
            }
            if (function_exists('IPS_GetParent')
                && IPS_GetParent($unlockedId) !== IPS_GetParent($target)) {
                return 'Entriegelt-Rueckmeldung gehoert nicht zum S1Target';
            }
        } catch (Throwable) {
            return 'Entriegelt-Rueckmeldung nicht lesbar';
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
            $this->SetValueIfChanged($this->CoolIdent($target), $previous);
            $this->SetRoomError($target, $room['Name'] . ': Reglerzustand ungewiss; keine neue Ventilfahrt.');
            return;
        }
        if ($locked !== '') {
            // Bestehenden Direktsperrzustand nur durch Direktlogik steuern;
            // vor Wiederherstellung niemals TS-Tasten an gesperrte Regler senden.
            $m = $this->DirectCoolingMapping($target);
            if ($m === null) {
                $this->DirectCoolingFallbackSet($target, 'LCN-Regler gesperrt, Zuordnung fehlt', 'blocked');
                $this->SetValueIfChanged($this->CoolIdent($target), $previous);
                $this->SetRoomError($target, $room['Name'] . ': Regler gesperrt; manueller Eingriff erforderlich.');
                return;
            }
            $this->DirectCoolingEnqueue($room, $state, $previous);
            return;
        }
        $previousFallback = $this->DirectCoolingFallbacks()[(string) $target] ?? null;
        if (is_array($previousFallback) && ($previousFallback['kind'] ?? '') === 'legacy') {
            $this->EnqueueLegacyCoolingJob($room, $state, $previous);
            return;
        }
        $safetyID = (int) $this->ReadPropertyInteger('CoolingSafetyVariable');
        if ($state && $safetyID > 0 && !$this->DirectCoolingSafe()) {
            $this->DirectCoolingFallbackSet($target, 'Externe Kuehlfreigabe fehlt: Kuehlen EIN gesperrt', 'blocked');
            $this->SetValueIfChanged($this->CoolIdent($target), $previous);
            $this->SetRoomError($target, $room['Name'] . ': Kuehlfreigabe fehlt, keine Kuehlung eingeschaltet.');
            return;
        }
        $reason = $this->DirectCoolingEligibility($room);
        if (str_contains($reason, 'bereits gesperrt')
            || str_contains($reason, 'gehoert nicht zum S1Target')) {
            $this->DirectCoolingFallbackSet($target, $reason, 'blocked');
            $this->SetValueIfChanged($this->CoolIdent($target), $previous);
            $this->SetRoomError($target, $room['Name'] . ': ' . $reason);
            return;
        }
        if ($reason === '') {
            $this->DirectCoolingFallbackSet($target, '');
            $this->DirectCoolingEnqueue($room, $state, $previous);
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
        $m = $this->DirectCoolingMapping($target);
        if ($m === null || !in_array((int) ($m['RegulatorIndex'] ?? -1), [0, 2], true)) {
            $this->DirectCoolingFallbackSet($target, $reason . ' (Reglerfreigabe nicht moeglich)', 'blocked');
            $job['Type'] = 'direct_recover';
            $this->FailCurrentJob($job, 'Kein automatischer Rueckfall: Reglerfreigabe nicht pruefbar.');
            return;
        }
        $this->DirectCoolingSetLockState($target, 'uncertain');
        $this->DirectCoolingFallbackSet($target, $reason . ' – Reglerfreigabe wird geprueft', 'blocked');
        $job['Type'] = 'direct_recover';
        $job['Phase'] = 'unlock';
        $job['FallbackReason'] = $reason;
        $this->YieldCurrentJob($job);
        $this->PushVisualizationMeta();
    }

    private function HandleDirectCoolingRecovery(array $job): void
    {
        $target = (int) $job['TargetVariable'];
        $m = $this->DirectCoolingMapping($target);
        $phase = (string) ($job['Phase'] ?? '');
        $unlockId = (int) ($m['UnlockedVariable'] ?? 0);
        if ($m === null || !IPS_VariableExists($unlockId)) {
            $this->DirectCoolingFallbackSet($target, 'Rueckmeldevariable zur Reglerfreigabe fehlt', 'blocked');
            $this->FailCurrentJob($job, 'Automatischer Rueckfall blockiert: Entriegelt-Rueckmeldung fehlt.');
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
                && (bool) GetValue($unlockId)
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
            if (!(bool) GetValue($unlockId)) {
                $this->DirectCoolingFallbackSet($target, 'Regler erneut gesperrt', 'blocked');
                $this->FailCurrentJob($job, 'Regler erneut gesperrt; kein automatischer Rueckfall.');
                return;
            }
            if ($this->ReadTarget($target) !== null) {
                $this->DirectCoolingSetLockState($target, '');
                $reason = (string) ($job['FallbackReason'] ?? 'Direkte Kuehlung nicht bestaetigt');
                $this->DirectCoolingFallbackSet($target, $reason);
                $this->SetBuffer('CurrentJob', '');
                $room = $this->FindRoomByTarget($target);
                if ($room !== null && $this->GetMode() === self::MODE_COOLING
                    && (!(bool) ($job['DesiredOpen'] ?? false) || $this->DirectCoolingSafe())) {
                    $this->EnqueueLegacyCoolingJob(
                        $room,
                        (bool) ($job['DesiredOpen'] ?? false),
                        (bool) ($job['PreviousCoolingState'] ?? false)
                    );
                }
                if ((bool) ($job['DesiredOpen'] ?? false) && !$this->DirectCoolingSafe()) {
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
            $this->YieldCurrentJob($job);
            return;
        }
        $this->DirectCoolingFallbackSet($target, 'Unbekannter Freigabezustand', 'blocked');
        $this->FailCurrentJob($job, 'Unbekannter Rueckfallzustand.');
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
