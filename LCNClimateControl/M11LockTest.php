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
        return $this->M11ProbeStart(false);
    }

    /** One deliberately clicked retry after a proven safe first release. */
    public function RetestM11Regulator(): string
    {
        return $this->M11ProbeStart(true);
    }

    /**
     * First and only RequestAction-based lock/unlock diagnostic.
     * The legacy native M11 test already failed and remains preserved
     * through its historical report/retest count.
     */
    public function StartM11ViaActionTest(): string
    {
        return $this->M11ProbeStart(false, true);
    }

    private function M11ProbeStart(bool $allowRetry, bool $viaAction = false): string
    {
        if (!IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 1000)) {
            return 'ABBRUCH: Climate-Modul beschaeftigt.';
        }
        try {
            $previous = $this->M11ProbeSession();
            $previousPhase = (string) ($previous['phase'] ?? '');
            $retryCount = (int) ($previous['retestCount'] ?? 0);
            if ($viaAction) {
                if ($previousPhase !== 'failed_safe'
                    || $retryCount !== 1
                    || (bool) ($previous['viaActionAttempted'] ?? false)) {
                    return 'ABBRUCH: Der einmalige RequestAction-Test ist nur nach dem bereits fehlgeschlagenen nativen M11-Wiederholungstest erlaubt.';
                }
            } elseif ($allowRetry) {
                if ($previousPhase !== 'failed_safe' || $retryCount >= 1) {
                    return 'ABBRUCH: Genau ein ausdruecklicher Wiederholungstest ist nach failed_safe zulaessig.';
                }
            } elseif (in_array($previousPhase, ['passed', 'failed_safe'], true)) {
                return 'ABBRUCH: Test ist bereits abgeschlossen. Nach Ursachenanalyse nur den EINMALIGEN M11-Wiederholungstest verwenden.';
            }
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
            if ($viaAction) {
                $parent = (int) IPS_GetParent($lock);
                if (!$this->IsOperationalTargetVariable($target, (int) $room['SendModule'])
                    || $parent <= 0 || !IPS_InstanceExists($parent)
                    || (int) (IPS_GetInstance($parent)['ConnectionID'] ?? 0)
                        !== (int) $room['SendModule']
                    || (int) (IPS_GetVariable($lock)['VariableType'] ?? -1)
                        !== VARIABLETYPE_BOOLEAN
                    || (int) (IPS_GetVariable($lock)['VariableAction'] ?? 0) !== $parent
                    || !IPS_FunctionExists('RequestAction')) {
                    return 'ABBRUCH: S1TargetLock besitzt nicht die nachgewiesene native Standardaktion.';
                }
            } elseif (!IPS_FunctionExists('LCN_LockTargetValue')
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
                'retestCount' => $retryCount + ($allowRetry ? 1 : 0),
                'viaActionAttempted' => $viaAction,
                'commandPath' => $viaAction ? 'RequestAction' : 'LCN_LockTargetValue',
                'commandDetail' => 'Sperrbefehl noch nicht ausgefuehrt.',
                'message' => 'M11-Test gestartet, Regler-Sperrung wird kurz geprueft.'
            ]);
            $this->UpdateChatGPTDiagnostic();
            return 'M11 (EG Buero): isolierter S1-Sperrtest gestartet ueber '
                . ($viaAction ? 'RequestAction(#' . $lock . ', true/false)' : 'LCN_LockTargetValue') . '. '
                . 'Automatische Freigabe folgt; keine Relaisschaltung. '
                . 'Nach etwa einer Minute die Taste M11-Teststatus anzeigen verwenden.';
        } finally {
            IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
        }
    }

    /**
     * Applies exactly ONE native S1 command to hardware address M11.
     * The bool result reports command submission, not confirmed hardware state.
     */
    private function M11ProbeCommand(array $p, bool $lock, ?string &$detail = null): bool
    {
        $sender = (int) ($p['sender'] ?? 0);
        $detail = 'Nicht ausgefuehrt.';
        if (!$this->IsOperationalLCNModule($sender)) {
            $detail = 'LCN-Senderinstanz nicht betriebsbereit.';
            return false;
        }
        $cfg = $this->AutoCoolingInstanceConfig($sender);
        if (!is_array($cfg) || (int) ($cfg['Target'] ?? -1) !== 11) {
            $detail = 'LCN-Moduladresse nicht M11.';
            return false;
        }
        $viaAction = (string) ($p['commandPath'] ?? '') === 'RequestAction';
        $fn = $viaAction ? 'RequestAction'
            : ($lock ? 'LCN_LockTargetValue' : 'LCN_ReleaseTargetValue');
        $lockId = (int) ($p['lockID'] ?? 0);
        $parent = (int) ($p['valueInstance'] ?? 0);
        if ($viaAction) {
            // Never pass a different Boolean or an arbitrary variable action.
            // The lock Boolean belongs to the M11 LCN Value instance.
            if ($lockId <= 0 || !IPS_VariableExists($lockId)
                || $parent <= 0 || !IPS_InstanceExists($parent)
                || IPS_GetParent($lockId) !== $parent
                || (int) (IPS_GetInstance($parent)['ConnectionID'] ?? 0) !== $sender
                || (int) (IPS_GetVariable($lockId)['VariableType'] ?? -1) !== VARIABLETYPE_BOOLEAN
                || (int) (IPS_GetVariable($lockId)['VariableAction'] ?? 0) !== $parent
                || (string) (IPS_GetObject($lockId)['ObjectIdent'] ?? '') !== 'S1TargetLock') {
                $detail = 'ABBRUCH: native M11-S1TargetLock Standardaktion nicht identisch.';
                return false;
            }
        }
        if (!IPS_FunctionExists($fn)) {
            $detail = $fn . ' fehlt.';
            return false;
        }
        if (!IPS_SemaphoreEnter(self::GLOBAL_LCN_SEND_SEMAPHORE, 2500)) {
            $detail = 'LCN_BUS_SEND_GLOBAL 2500 ms belegt; kein Befehl gesendet.';
            return false;
        }
        try {
            if ($viaAction) {
                $ok = (bool) RequestAction($lockId, $lock);
                $detail = 'RequestAction(#' . $lockId . ', '
                    . ($lock ? 'true=SPERREN' : 'false=FREIGEBEN')
                    . ') hat ' . ($ok ? 'TRUE' : 'FALSE') . ' zurueckgegeben.';
            } else {
                $ok = $lock
                    ? (bool) LCN_LockTargetValue($sender, 2)
                    : (bool) LCN_ReleaseTargetValue($sender, 2);
                $detail = $fn . '(#' . $sender . ', 2) hat '
                    . ($ok ? 'TRUE' : 'FALSE') . ' zurueckgegeben.';
            }
            return $ok;
        } catch (Throwable $e) {
            $detail = $fn . ' Exception: ' . $e->getMessage();
            $this->SendDebug('M11-Sperrtest', $detail, 0);
            return false;
        } finally {
            $this->ReleaseLCNBusSlot();
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
        $p['lastReadAccepted'] = false;
        if (IPS_SemaphoreEnter(self::GLOBAL_LCN_SEND_SEMAPHORE, 1500)) {
            try {
                $p['lastReadAccepted'] = (bool) LCN_RequestRead($valueInstance);
            } catch (Throwable $e) {
                $this->SendDebug('M11-Leseanfrage', $e->getMessage(), 0);
            } finally {
                $this->ReleaseLCNBusSlot();
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
                $detail = null;
                $ok = $this->M11ProbeCommand($p, true, $detail);
                $p['lockCommandDetail'] = (string) $detail;
                $p['lockCommandAccepted'] = $ok;
                $p['lockCommandAt'] = time();
                $this->M11ProbeSetPhase($p, $ok ? 'lock_wait' : 'release',
                    $ok ? 'Sperrbefehl gesendet; Rueckmeldung wird erwartet.'
                        : 'Sperrbefehl fehlgeschlagen; Freigabe wird sicherheitshalber versucht.');
            } elseif ($phase === 'lock_wait') {
                $readConfirmed = (string) ($p['commandPath'] ?? '') !== 'RequestAction'
                    || ((bool) ($p['lastReadAccepted'] ?? false)
                        && (int) ($p['lastRead'] ?? 0) > (int) ($p['lockSent'] ?? 0)
                        && IPS_VariableExists($lockId)
                        && (int) (IPS_GetVariable($lockId)['VariableUpdated'] ?? 0)
                            >= (int) ($p['lastRead'] ?? PHP_INT_MAX));
                $p['lastLockSample'] = IPS_VariableExists($lockId)
                    ? ((bool) GetValue($lockId) ? 'GESPERRT' : 'FREI') : 'FEHLT';
                $p['lastLockSampleAt'] = time();
                $p['lastLockFeedbackAt'] = IPS_VariableExists($lockId)
                    ? (int) (IPS_GetVariable($lockId)['VariableUpdated'] ?? 0) : 0;
                if ($readConfirmed
                    && IPS_VariableExists($lockId)
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
                // The action's local Boolean update is NOT a proof of real
                // bus feedback. Require a separate post-release read.
                $p['lastRead'] = 0;
                $p['lastReadAccepted'] = false;
                $this->M11ProbeSetPhase($p, 'release_wait', 'Freigabebefehl an M11 gesendet, Rueckmeldung ausstehend.');
                $this->M11ProbeWrite($p);
                $detail = null;
                $accepted = $this->M11ProbeCommand($p, false, $detail);
                $p['releaseCommandDetail'] = (string) $detail;
                $p['releaseCommandAccepted'] = $accepted;
                if (!$accepted) {
                    $p['message'] = 'Freigabebefehl nicht angenommen; erneuter Versuch folgt.';
                }
            } elseif ($phase === 'release_wait') {
                $readConfirmed = (string) ($p['commandPath'] ?? '') !== 'RequestAction'
                    || ((bool) ($p['lastReadAccepted'] ?? false)
                        && (int) ($p['lastRead'] ?? 0) > (int) ($p['releaseSent'] ?? 0)
                        && IPS_VariableExists($lockId)
                        && (int) (IPS_GetVariable($lockId)['VariableUpdated'] ?? 0)
                            >= (int) ($p['lastRead'] ?? PHP_INT_MAX));
                if ($readConfirmed && IPS_VariableExists($lockId)
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
                $readConfirmed = (string) ($p['commandPath'] ?? '') !== 'RequestAction'
                    || ((bool) ($p['lastReadAccepted'] ?? false)
                        && (int) ($p['lastRead'] ?? 0) > (int) ($p['releaseSent'] ?? 0));
                if ($readConfirmed && IPS_VariableExists($lockId)
                    && !(bool) GetValue($lockId)
                    && (int) (IPS_GetVariable($lockId)['VariableUpdated'] ?? 0)
                        > (int) ($p['releaseSent'] ?? PHP_INT_MAX)
                    && $actual !== null && IPS_VariableExists($target)
                    && (int) (IPS_GetVariable($target)['VariableUpdated'] ?? 0)
                        > (int) ($p['releaseSent'] ?? PHP_INT_MAX)) {
                    $this->DirectCoolingSetLockState($target, '');
                    // Successful status feedback alone is not evidence
                    // that both native API calls were accepted.
                    $allCommandsAccepted = (bool) ($p['lockCommandAccepted'] ?? false)
                        && (bool) ($p['releaseCommandAccepted'] ?? false);
                    if ($allCommandsAccepted && (bool) ($p['lockConfirmed'] ?? false)) {
                        $this->M11ProbeSetPhase($p, 'passed',
                            'ERFOLGREICH: M11 S1 gesperrt, entsperrt und S1Target erneut bestaetigt. Kein Relaisbefehl.');
                    } else {
                        $this->M11ProbeSetPhase($p, 'failed_safe',
                            'LCN meldet FREIGEGEBEN, aber Sperr-/Freigabe-API oder Sperrrueckmeldung nicht bestaetigt. M11-Test NICHT bestanden.');
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
            . "\n" . (string) ($p['message'] ?? '')
            . "\nBefehlspfad: " . (string) ($p['commandPath'] ?? 'LCN-Funktion (Altbericht)');
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
        $lockAccepted = array_key_exists('lockCommandAccepted', $p)
            ? ((bool) $p['lockCommandAccepted'] ? 'JA' : 'NEIN')
            : 'NICHT PROTOKOLLIERT (alter Testlauf v0.3.16)';
        $releaseAccepted = array_key_exists('releaseCommandAccepted', $p)
            ? ((bool) $p['releaseCommandAccepted'] ? 'JA' : 'NEIN')
            : 'NICHT PROTOKOLLIERT (alter Testlauf v0.3.16)';
        $status .= "\nSperrbefehl angenommen: " . $lockAccepted
            . "\nSperrzustand waehrend Test bestaetigt: "
            . ((bool) ($p['lockConfirmed'] ?? false) ? 'JA' : 'NEIN')
            . "\nFreigabebefehl angenommen: " . $releaseAccepted
            . "\nFreigabeversuche: " . (int) ($p['tries'] ?? 0)
            . "\nWiederholungstests: " . (int) ($p['retestCount'] ?? 0);
        foreach (['lockCommandDetail' => 'Sperr-API-Diagnose',
            'releaseCommandDetail' => 'Freigabe-API-Diagnose'] as $key => $title) {
            if (isset($p[$key])) {
                $status .= "\n" . $title . ': ' . (string) $p[$key];
            }
        }
        foreach (['started' => 'Startzeit', 'lockSent' => 'Sperrversuch',
            'lastLockFeedbackAt' => 'Letzte Sperrstatusmeldung',
            'releaseSent' => 'Letzter Freigabeversuch'] as $key => $label) {
            $v = (int) ($p[$key] ?? 0);
            if ($v > 0) {
                $status .= "\n" . $label . ': ' . date('H:i:s', $v);
            }
        }
        if (isset($p['lastLockSample'])) {
            $status .= "\nLetzte Sperr-Rueckmeldung im Test: "
                . (string) $p['lastLockSample'];
        }
        return $status
            . "\nNur M11 S1 (Reglerindex 2); keine Relais- und Sollwertbefehle."
            . "\nEin angenommener Befehl beweist NICHT die tatsaechliche Reglersperre.";
    }
}
