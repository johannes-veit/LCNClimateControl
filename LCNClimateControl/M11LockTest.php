<?php
declare(strict_types=1);

/**
 * One-room M11 S1 regulator lock diagnostic.
 * Explicit opt-in only. NEVER switches relays, changes setpoints or cooling mode.
 * Persistent session survives ApplyChanges and is resumed in RELEASE mode.
 */
trait LCNClimateM11LockTest
{
    private function M11ProbeSession(): array
    {
        try {
            return $this->ReadJsonAttribute('M11ProbeSession');
        } catch (Throwable) {
            return [];
        }
    }

    private function M11ProbeActive(): bool
    {
        $p = $this->M11ProbeSession();
        return in_array((string) ($p['phase'] ?? ''), [
            'lock', 'lock_wait', 'release', 'release_wait',
            'target_wait', 'manual_release_needed'
        ], true);
    }

    private function M11ProbeWrite(array $p): void
    {
        $this->WriteJsonAttribute('M11ProbeSession', $p);
        $this->SetTimerInterval('M11ProbeTimer', in_array((string) ($p['phase'] ?? ''), [
            'lock', 'lock_wait', 'release', 'release_wait', 'target_wait'
        ], true) ? 1000 : 0);
    }

    private function M11ProbeRoom(): ?array
    {
        $matches = [];
        foreach ($this->GetRuntimeRooms() as $room) {
            $sender = (int) ($room['SendModule'] ?? 0);
            if (!$this->IsOperationalLCNModule($sender)) {
                continue;
            }
            $cfg = $this->AutoCoolingInstanceConfig($sender);
            if (is_array($cfg)
                && (int) ($cfg['Target'] ?? -1) === 11) {
                $matches[] = $room;
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    private function M11ProbeMapping(array $room): ?array
    {
        $m = $this->AutoCoolingReadOnlyDiscovery($room);
        if (!is_array($m)
            || (int) ($m['RegulatorIndex'] ?? -1) !== 2
            || (int) ($m['RelayChannel'] ?? -1) !== 1
            || (int) ($m['TargetVariable'] ?? 0) !== (int) $room['TargetVariable']
            || (bool) ($m['LockFeedbackIsLocked'] ?? false) !== true
            || (bool) ($m['OpenRelayOn'] ?? false) !== true) {
            return null;
        }
        $relay = $this->AutoCoolingInstanceConfig((int) ($m['RelayModule'] ?? 0));
        if (!is_array($relay) || (int) ($relay['Target'] ?? -1) !== 92) {
            return null;
        }
        $id = (int) ($m['UnlockedVariable'] ?? 0);
        $target = (int) $room['TargetVariable'];
        if ($id <= 0 || !IPS_VariableExists($id) || !IPS_VariableExists($target)
            || IPS_GetParent($id) !== IPS_GetParent($target)
            || (string) (IPS_GetObject($id)['ObjectIdent'] ?? '') !== 'S1TargetLock') {
            return null;
        }
        return $m;
    }

    private function M11ProbeRecent(int $id, int $maxAge = 120): bool
    {
        if (!IPS_VariableExists($id)) {
            return false;
        }
        try {
            $v = IPS_GetVariable($id);
            $then = (int) ($v['VariableUpdated'] ?? 0);
            return $then > 0 && $then <= time() && time() - $then <= $maxAge;
        } catch (Throwable) {
            return false;
        }
    }

    public function StartM11RegulatorTest(): string
    {
        if (!IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 1000)) {
            return 'ABBRUCH: Climate-Modul beschaeftigt.';
        }
        try {
            if ($this->M11ProbeActive()
                || $this->GetMode() !== self::MODE_HEATING
                || $this->GetPendingMode() !== null || $this->IsBusy()
                || $this->GetBuffer('ConfigurationValid') !== '1'
                || $this->ReadJsonAttribute('DirectCoolingLocks') !== []
                || $this->GetPendingCoolingRequests() !== []
                || IPS_GetKernelRunlevel() !== KR_READY) {
                return 'ABBRUCH: Nur im ruhenden HEIZBETRIEB ohne offene Sperren/Auftraege.';
            }
            $room = $this->M11ProbeRoom();
            $m = is_array($room) ? $this->M11ProbeMapping($room) : null;
            if (!is_array($room) || !is_array($m)) {
                return 'ABBRUCH: M11/S1/M92-R1 nicht eindeutig erkannt. Keine Befehle.';
            }
            $target = (int) $room['TargetVariable'];
            $lock = (int) $m['UnlockedVariable'];
            $relay = (int) $m['RelayStatusVariable'];
            if (!$this->M11ProbeRecent($target) || !$this->M11ProbeRecent($lock)
                || !$this->M11ProbeRecent($relay)
                || (bool) GetValue($lock)
                || (bool) GetValue($relay)
                || $this->GetStoredCoolingState($target)
                || $this->ReadTarget($target) === null) {
                return 'ABBRUCH: M11 braucht aktuelle Rueckmeldungen, S1 FREI, Relais AUS und gueltigen Sollwert.';
            }
            if (!IPS_FunctionExists('LCN_LockTargetValue')
                || !IPS_FunctionExists('LCN_ReleaseTargetValue')) {
                return 'ABBRUCH: Symcon LCN-Sperrfunktionen nicht vorhanden.';
            }

            // Before any lock attempt: persist an uncertain marker and
            // a recovery session so that a kernel reload can release it.
            $this->DirectCoolingSaveLockMapping($target, $m);
            $this->DirectCoolingSetLockState($target, 'uncertain');
            $this->M11ProbeWrite([
                'phase' => 'lock', 'target' => $target,
                'sender' => (int) $room['SendModule'],
                'lockID' => $lock,
                'valueInstance' => (int) IPS_GetParent($target),
                'started' => time(), 'phaseSince' => time(),
                'lockConfirmed' => false, 'tries' => 0,
                'message' => 'M11-Test gestartet, Regler-Sperrung wird kurz geprueft.'
            ]);
            $this->UpdateChatGPTDiagnostic();
            return 'M11 (EG Buero): isolierter S1-Sperrtest gestartet. '
                . 'Automatische Freigabe folgt; keine Relaisschaltung. '
                . 'Nach etwa einer Minute die Taste M11-Teststatus anzeigen verwenden.';
        } finally {
            IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
        }
    }

    /** M11 hardware identity check before every physical command. */
    private function M11ProbeCommand(array $p, bool $lock): bool
    {
        $sender = (int) ($p['sender'] ?? 0);
        if (!$this->IsOperationalLCNModule($sender)) {
            return false;
        }
        $cfg = $this->AutoCoolingInstanceConfig($sender);
        if (!is_array($cfg) || (int) ($cfg['Target'] ?? -1) !== 11
            || !IPS_FunctionExists($lock ? 'LCN_LockTargetValue' : 'LCN_ReleaseTargetValue')
            || !IPS_SemaphoreEnter(self::GLOBAL_LCN_SEND_SEMAPHORE, 500)) {
            return false;
        }
        try {
            return $lock
                ? (bool) LCN_LockTargetValue($sender, 2)
                : (bool) LCN_ReleaseTargetValue($sender, 2);
        } catch (Throwable $e) {
            $this->SendDebug('M11-Sperrtest', $e->getMessage(), 0);
            return false;
        } finally {
            IPS_SemaphoreLeave(self::GLOBAL_LCN_SEND_SEMAPHORE);
        }
    }

    private function M11ProbeRequestFeedback(array &$p): void
    {
        if (time() - (int) ($p['lastRead'] ?? 0) < 5
            || !IPS_FunctionExists('LCN_RequestRead')) {
            return;
        }
        $valueInstance = (int) ($p['valueInstance'] ?? 0);
        if (!IPS_InstanceExists($valueInstance)
            || (int) (IPS_GetInstance($valueInstance)['ConnectionID'] ?? 0)
                !== (int) ($p['sender'] ?? 0)) {
            return;
        }
        $p['lastRead'] = time();
        if (IPS_SemaphoreEnter(self::GLOBAL_LCN_SEND_SEMAPHORE, 250)) {
            try {
                LCN_RequestRead($valueInstance);
            } catch (Throwable $e) {
                $this->SendDebug('M11-Leseanfrage', $e->getMessage(), 0);
            } finally {
                IPS_SemaphoreLeave(self::GLOBAL_LCN_SEND_SEMAPHORE);
            }
        }
    }

    private function M11ProbeSetPhase(array &$p, string $phase, string $message): void
    {
        $p['phase'] = $phase;
        $p['phaseSince'] = time();
        $p['message'] = $message;
    }

    public function M11ProbeWorker(): void
    {
        if (!IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 100)) {
            return;
        }
        try {
            $p = $this->M11ProbeSession();
            $phase = (string) ($p['phase'] ?? '');
            if ($phase === '' || in_array($phase, ['passed', 'failed_safe', 'manual_release_needed'], true)) {
                $this->SetTimerInterval('M11ProbeTimer', 0);
                return;
            }

            $target = (int) ($p['target'] ?? 0);
            $lockId = (int) ($p['lockID'] ?? 0);
            // If mode unexpectedly changed, prioritize release and never send a lock.
            if ($this->GetMode() !== self::MODE_HEATING
                && in_array($phase, ['lock', 'lock_wait'], true)) {
                $this->M11ProbeSetPhase($p, 'release', 'Betriebsart geaendert: Freigabe erzwungen.');
                $phase = 'release';
            }
            if ($phase === 'lock') {
                // Persist the release path before sending a lock:
                // a PHP exception or restart must never replay the lock!
                $this->M11ProbeSetPhase($p, 'release', 'Sperrbefehl gestartet; Freigabe ausstehend.');
                $p['lockSent'] = time();
                $this->M11ProbeWrite($p);
                $ok = $this->M11ProbeCommand($p, true);
                $this->M11ProbeSetPhase($p, $ok ? 'lock_wait' : 'release',
                    $ok ? 'Sperrbefehl gesendet; Rueckmeldung wird erwartet.'
                        : 'Sperrbefehl fehlgeschlagen; Freigabe wird sicherheitshalber versucht.');
            } elseif ($phase === 'lock_wait') {
                if (IPS_VariableExists($lockId)
                    && (bool) GetValue($lockId)
                    && (int) (IPS_GetVariable($lockId)['VariableUpdated'] ?? 0)
                        >= (int) ($p['lockSent'] ?? PHP_INT_MAX)) {
                    $p['lockConfirmed'] = true;
                    $this->M11ProbeSetPhase($p, 'release', 'LCN-Sperre BESTAETIGT; sofortige Freigabe.');
                } elseif (time() - (int) ($p['lockSent'] ?? 0) >= 12) {
                    $this->M11ProbeSetPhase($p, 'release', 'Sperre nicht bestaetigt; Freigabe wird versucht.');
                } else {
                    $this->M11ProbeRequestFeedback($p);
                }
            } elseif ($phase === 'release') {
                $p['tries'] = (int) ($p['tries'] ?? 0) + 1;
                $p['releaseSent'] = time();
                $this->M11ProbeSetPhase($p, 'release_wait', 'Freigabebefehl an M11 gesendet, Rueckmeldung ausstehend.');
                $this->M11ProbeWrite($p);
                if (!$this->M11ProbeCommand($p, false)) {
                    $p['message'] = 'Freigabebefehl nicht angenommen; erneuter Versuch folgt.';
                }
            } elseif ($phase === 'release_wait') {
                if (IPS_VariableExists($lockId)
                    && !(bool) GetValue($lockId)
                    && (int) (IPS_GetVariable($lockId)['VariableUpdated'] ?? 0)
                        > (int) ($p['releaseSent'] ?? PHP_INT_MAX)) {
                    $this->M11ProbeSetPhase($p, 'target_wait', 'S1-Freigabe bestaetigt; Sollwert wird geprueft.');
                } elseif (time() - (int) ($p['releaseSent'] ?? 0) >= 10) {
                    if ((int) ($p['tries'] ?? 0) < 3) {
                        $this->M11ProbeSetPhase($p, 'release', 'Freigabe weiterhin unbestaetigt; Wiederholung.');
                    } else {
                        $this->M11ProbeSetPhase($p, 'manual_release_needed',
                            'ALARM: M11-Reglerfreigabe nicht bestaetigt. LCN-PRO pruefen; keine Heizbefehle senden.');
                        $this->SetRoomError($target, $p['message']);
                    }
                } else {
                    $this->M11ProbeRequestFeedback($p);
                }
            } elseif ($phase === 'target_wait') {
                $actual = $this->ReadTarget($target);
                if (IPS_VariableExists($lockId)
                    && !(bool) GetValue($lockId)
                    && (int) (IPS_GetVariable($lockId)['VariableUpdated'] ?? 0)
                        > (int) ($p['releaseSent'] ?? PHP_INT_MAX)
                    && $actual !== null && IPS_VariableExists($target)
                    && (int) (IPS_GetVariable($target)['VariableUpdated'] ?? 0)
                        > (int) ($p['releaseSent'] ?? PHP_INT_MAX)) {
                    $this->DirectCoolingSetLockState($target, '');
                    if ((bool) ($p['lockConfirmed'] ?? false)) {
                        $this->M11ProbeSetPhase($p, 'passed',
                            'ERFOLGREICH: M11 S1 gesperrt, entsperrt und S1Target erneut bestaetigt. Kein Relaisbefehl.');
                    } else {
                        $this->M11ProbeSetPhase($p, 'failed_safe',
                            'SICHER FREIGEGEBEN, aber Sperre nicht bestaetigt. M11-Test NICHT bestanden.');
                    }
                    $this->ClearRoomError($target);
                } elseif (time() - (int) ($p['phaseSince'] ?? 0) >= 30) {
                    $this->M11ProbeSetPhase($p, 'manual_release_needed',
                        'S1-Freigabe gemeldet, aber kein frischer plausibler Sollwert. Bitte M11 kontrollieren.');
                    $this->SetRoomError($target, $p['message']);
                } else {
                    $this->M11ProbeRequestFeedback($p);
                }
            } else {
                $this->M11ProbeSetPhase($p, 'manual_release_needed', 'Unbekannte Testphase; M11-Freigabe pruefen.');
            }
            $this->M11ProbeWrite($p);
            $this->UpdateChatGPTDiagnostic();
            $this->PushVisualizationState();
        } catch (Throwable $e) {
            $p = $this->M11ProbeSession();
            $this->M11ProbeSetPhase($p, 'release',
                'Interner Testfehler; automatischer Freigabeversuch: ' . $e->getMessage());
            $this->M11ProbeWrite($p);
            $this->SendDebug('M11-Test Exception', $e->getMessage(), 0);
        } finally {
            IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
        }
    }

    /** The user may manually request another RELEASE without a lock command. */
    public function M11ProbeEmergencyRelease(): string
    {
        if (!IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 1000)) {
            return 'ABBRUCH: Test beschaeftigt.';
        }
        try {
            $p = $this->M11ProbeSession();
            if ($p === [] || !isset($p['sender'], $p['target'])) {
                return 'Kein dokumentierter M11-Testlauf vorhanden; kein Befehl.';
            }
            if (!in_array((string) ($p['phase'] ?? ''), [
                'lock', 'lock_wait', 'release', 'release_wait',
                'target_wait', 'manual_release_needed'
            ], true)) {
                return 'Keine aktive/ungewisse Testsperre; kein Befehl.';
            }
            $this->M11ProbeSetPhase($p, 'release', 'Manuelle Wiederfreigabe angefordert.');
            $p['tries'] = 0;
            $this->M11ProbeWrite($p);
            return 'M11: Freigabe zur naechsten Timer-Pruefung eingeplant. '
                . 'Bitte danach Teststatus kontrollieren.';
        } finally {
            IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
        }
    }

    public function M11ProbeStatus(): string
    {
        $p = $this->M11ProbeSession();
        if ($p === []) {
            return 'M11-Sperrtest: noch nicht gestartet. Keine Befehle.';
        }
        $lock = (int) ($p['lockID'] ?? 0);
        $actual = (int) ($p['target'] ?? 0);
        $status = 'M11-Sperrtest: ' . (string) ($p['phase'] ?? 'unbekannt')
            . "\n" . (string) ($p['message'] ?? '');
        foreach ([$lock, $actual] as $id) {
            if (IPS_VariableExists($id)) {
                $shown = $id === $lock
                    ? ((bool) GetValue($id) ? 'GESPERRT' : 'FREIGEGEBEN')
                    : number_format((float) GetValue($id), 1, ',', '') . ' Grad C';
                $status .= "\n#" . $id . ': ' . $shown
                    . ' / aktualisiert ' . date('H:i:s',
                        (int) (IPS_GetVariable($id)['VariableUpdated'] ?? 0));
            }
        }
        return $status . "\nNur M11 S1 (Reglerindex 2); keine Relais- und Sollwertbefehle.";
    }
}
