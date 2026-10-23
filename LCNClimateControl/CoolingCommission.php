<?php
declare(strict_types=1);

/**
 * Commissioning of native S1 actions, one HEATING room per explicit click.
 * Never touches relays or targets and never starts cooling.
 * Uses existing runtime rooms and LCN Value standard actions.
 * Persisted state is resumed in RELEASE, never LOCK, after ApplyChanges.
 */
trait LCNClimateCoolingCommission
{
    private function CoolingCommissionSession(): array
    {
        return $this->ReadJsonAttribute('CoolingCommissionSession');
    }

    private function CoolingCommissionActive(): bool
    {
        return in_array((string) ($this->CoolingCommissionSession()['phase'] ?? ''), [
            'lock', 'lock_wait', 'release', 'release_wait', 'manual_release'
        ], true);
    }

    private function CoolingCommissionSave(array $p): void
    {
        $this->WriteJsonAttribute('CoolingCommissionSession', $p);
        $this->SetTimerInterval('CoolingCommissionTimer',
            in_array((string) ($p['phase'] ?? ''), [
                'lock', 'lock_wait', 'release', 'release_wait'
            ], true) ? 1000 : 0);
    }

    private function CoolingCommissionRecordM11(array $room): bool
    {
        $p = $this->M11ProbeSession();
        return (int) ($room['SendModule'] ?? 0) === 37617
            && (int) ($room['TargetVariable'] ?? 0) === 34107
            && (string) ($p['phase'] ?? '') === 'passed'
            && (string) ($p['commandPath'] ?? '') === 'RequestAction'
            && (int) ($p['sender'] ?? 0) === 37617
            && (int) ($p['target'] ?? 0) === 34107
            && (int) ($p['lockID'] ?? 0) === 46746
            && (bool) ($p['lockConfirmed'] ?? false)
            && (bool) ($p['lockCommandAccepted'] ?? false)
            && (bool) ($p['releaseCommandAccepted'] ?? false);
    }

    private function CoolingCommissionVerified(array $room): bool
    {
        $target = (int) ($room['TargetVariable'] ?? 0);
        $sender = (int) ($room['SendModule'] ?? 0);
        $map = $this->DirectCoolingMapping($target);
        if ($map === null || $this->DirectCoolingLockActionID($room, $map) <= 0) {
            return false;
        }
        $id = (int) $map['UnlockedVariable'];
        if ($this->CoolingCommissionRecordM11($room) && $id === 46746) {
            return true;
        }
        $verified = $this->ReadJsonAttribute('CoolingCommissionVerified');
        $v = $verified[(string) $target] ?? null;
        return is_array($v)
            && (int) ($v['sender'] ?? 0) === $sender
            && (int) ($v['lockID'] ?? 0) === $id
            && (string) ($v['path'] ?? '') === 'RequestAction'
            && (int) ($v['at'] ?? 0) > 0;
    }

    private function CoolingCommissionFresh(int $id, int $maxAge = 180): bool
    {
        if (!IPS_VariableExists($id)) {
            return false;
        }
        $t = (int) (IPS_GetVariable($id)['VariableUpdated'] ?? 0);
        return $t > 0 && $t <= time() && time() - $t <= $maxAge;
    }

    /**
     * Dry regulator test, no relay commands. Skip currently HEATING relays ON.
     * A failed test cannot trigger testing a different regulator implicitly.
     */
    public function CoolingCommissionStartNext(): string
    {
        if (!IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 1500)) {
            return 'ABBRUCH: Climate-Instanz beschaeftigt.';
        }
        try {
            $old = $this->CoolingCommissionSession();
            if ($this->CoolingCommissionActive()
                || (string) ($old['phase'] ?? '') === 'failed_safe'
                || $this->M11ProbeActive()
                || $this->GetMode() !== self::MODE_HEATING
                || $this->GetPendingMode() !== null || $this->IsBusy()
                || $this->ReadJsonAttribute('DirectCoolingLocks') !== []
                || $this->GetPendingCoolingRequests() !== []
                || IPS_GetKernelRunlevel() !== KR_READY) {
                return 'ABBRUCH: Nur ruhender HEIZEN-Modus ohne offene Sperren/Fehler.';
            }
            $skipped = [];
            foreach ($this->GetRuntimeRooms() as $room) {
                if ($this->CoolingCommissionVerified($room)) {
                    continue;
                }
                $target = (int) ($room['TargetVariable'] ?? 0);
                $map = $this->DirectCoolingMapping($target);
                if ($map === null || $this->DirectCoolingLockActionID($room, $map) <= 0) {
                    return 'ABBRUCH: ' . (string) $room['Name']
                        . ' hat keine validierte S1TargetLock-Standardaktion.';
                }
                $lockID = (int) $map['UnlockedVariable'];
                $relayID = (int) ($map['RelayStatusVariable'] ?? 0);
                if (!$this->CoolingCommissionFresh($lockID)
                    || !$this->CoolingCommissionFresh($target)
                    || !$this->CoolingCommissionFresh($relayID)) {
                    return 'ABBRUCH: ' . (string) $room['Name']
                        . ' hat keine aktuellen LCN-Rueckmeldungen (max. 180 Sekunden).';
                }
                if ((bool) GetValue($lockID)) {
                    return 'ABBRUCH: ' . (string) $room['Name'] . ' ist bereits GESPERRT.';
                }
                if ((bool) GetValue($relayID)) {
                    $skipped[] = (string) $room['Name'] . ' (Heizrelais EIN)';
                    continue;
                }
                if ($this->ReadTarget($target) === null) {
                    return 'ABBRUCH: ' . (string) $room['Name'] . ' ohne plausiblen S1-Sollwert.';
                }
                $this->DirectCoolingSaveLockMapping($target, $map);
                $this->DirectCoolingSetLockState($target, 'uncertain');
                $this->CoolingCommissionSave([
                    'phase'=>'lock', 'target'=>$target,
                    'sender'=>(int) $room['SendModule'],
                    'name'=>(string) $room['Name'],
                    'lockID'=>$lockID, 'mapping'=>$map,
                    'valueInstance'=>(int) IPS_GetParent($lockID),
                    'started'=>time(), 'phaseAt'=>time(),
                    'lockConfirmed'=>false, 'lockAck'=>false,
                    'releaseAck'=>false, 'releaseTries'=>0,
                    'message'=>'Einzelraum-S1-Sperrtest gestartet (keine Relaisbefehle).'
                ]);
                $this->UpdateChatGPTDiagnostic();
                return 'START ' . (string) $room['Name']
                    . ': genau ein S1-Sperr-/Freigabetest. '
                    . 'Automatische Freigabe, KEIN Relaisbefehl. '
                    . 'Anschliessend Teststatus anzeigen.';
            }
            return 'Kein weiterer aktuell pruefbarer Raum. '
                . (count($skipped) > 0 ? 'Heizventil offen: '
                    . implode(', ', $skipped) . '. Bei Heizrelais AUS spaeter erneut pruefen.'
                    : 'Alle 11 S1-Regler sind bereits erfolgreich geprueft.');
        } finally {
            IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
        }
    }

    /** Rate-limited read of exactly one native LCN Value instance. */
    private function CoolingCommissionRead(array &$p): void
    {
        if (time() - (int) ($p['lastReadAt'] ?? 0) < 4
            || !IPS_FunctionExists('LCN_RequestRead')) {
            return;
        }
        $id = (int) ($p['valueInstance'] ?? 0);
        if (!IPS_InstanceExists($id)
            || (int) (IPS_GetInstance($id)['ConnectionID'] ?? 0)
                !== (int) ($p['sender'] ?? 0)) {
            return;
        }
        $p['lastReadAt'] = time();
        $p['lastReadAck'] = false;
        if (!IPS_SemaphoreEnter(self::GLOBAL_LCN_SEND_SEMAPHORE, 2000)) {
            return;
        }
        try {
            $p['lastReadAck'] = (bool) LCN_RequestRead($id);
        } catch (Throwable $e) {
            $this->SendDebug('Commission-Read', $e->getMessage(), 0);
        } finally {
            $this->ReleaseLCNBusSlot();
        }
    }

    private function CoolingCommissionPhase(array &$p, string $phase, string $message): void
    {
        $p['phase'] = $phase;
        $p['phaseAt'] = time();
        $p['message'] = $message;
        $p['lastReadAt'] = 0;
        $p['lastReadAck'] = false;
    }

    private function CoolingCommissionFreshRead(array $p, bool $locked): bool
    {
        $lockID = (int) ($p['lockID'] ?? 0);
        $sent = (int) ($locked ? ($p['lockAt'] ?? 0) : ($p['releaseAt'] ?? 0));
        $read = (int) ($p['lastReadAt'] ?? 0);
        if (!$p['lastReadAck'] || $read <= $sent
            || !IPS_VariableExists($lockID)
            || (bool) GetValue($lockID) !== $locked) {
            return false;
        }
        $updated = (int) (IPS_GetVariable($lockID)['VariableUpdated'] ?? 0);
        return $updated >= $read && $updated > $sent;
    }

    public function CoolingCommissionWorker(): void
    {
        if (!IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 150)) {
            return;
        }
        try {
            $p = $this->CoolingCommissionSession();
            $phase = (string) ($p['phase'] ?? '');
            if (!in_array($phase, ['lock','lock_wait','release','release_wait'], true)) {
                $this->SetTimerInterval('CoolingCommissionTimer', 0);
                return;
            }
            $target = (int) ($p['target'] ?? 0);
            $sender = (int) ($p['sender'] ?? 0);
            $map = $p['mapping'] ?? null;
            if (!is_array($map)) {
                $this->CoolingCommissionPhase($p,'manual_release',
                    'ALARM: Hardwarezuordnung fehlt. Regler in LCN-PRO kontrollieren.');
            } elseif ($phase === 'lock') {
                // Durable release state BEFORE the physical lock.
                $this->CoolingCommissionPhase($p,'release',
                    'Reglersperre wird gesendet; Freigabe bleibt vorrangig.');
                $p['lockAt'] = time();
                $this->CoolingCommissionSave($p);
                $ok = $this->GetMode() === self::MODE_HEATING
                    && $this->DirectCoolingControllerCommand([
                        'SendModule'=>$sender,'TargetVariable'=>$target
                    ], $map, true);
                $p['lockAck'] = $ok;
                $this->CoolingCommissionPhase($p,$ok ? 'lock_wait' : 'release',
                    $ok ? 'Reglersperre angenommen; Status wird bestaetigt.'
                        : 'Sperrbefehl fehlgeschlagen; Freigabe wird versucht.');
            } elseif ($phase === 'lock_wait') {
                if ($this->CoolingCommissionFreshRead($p, true)) {
                    $p['lockConfirmed'] = true;
                    $this->CoolingCommissionPhase($p,'release',
                        'Regler GESPERRT bestaetigt; sofort freigeben.');
                } elseif (time() - (int) ($p['lockAt'] ?? 0) >= 20) {
                    $this->CoolingCommissionPhase($p,'release',
                        'Sperre nicht bestaetigt; Freigabe hat Vorrang.');
                } else {
                    $this->CoolingCommissionRead($p);
                }
            } elseif ($phase === 'release') {
                $p['releaseTries'] = (int) ($p['releaseTries'] ?? 0) + 1;
                $p['releaseAt'] = time();
                $this->CoolingCommissionPhase($p,'release_wait',
                    'M11-bewaehrter nativer S1-Freigabepfad ausgefuehrt.');
                $this->CoolingCommissionSave($p); // never repeat lock on restart
                $ok = $this->DirectCoolingControllerCommand([
                    'SendModule'=>$sender,'TargetVariable'=>$target
                ], $map, false);
                $p['releaseAck'] = $ok;
                if (!$ok) {
                    $p['message'] = 'S1-Freigabe nicht angenommen; erneuter Freigabeversuch.';
                }
            } elseif ($phase === 'release_wait') {
                $readOK = $this->CoolingCommissionFreshRead($p, false);
                $targetFresh = IPS_VariableExists($target)
                    && (int) (IPS_GetVariable($target)['VariableUpdated'] ?? 0)
                        >= (int) ($p['lastReadAt'] ?? PHP_INT_MAX)
                    && $this->ReadTarget($target) !== null;
                if ($readOK && $targetFresh) {
                    $this->DirectCoolingSetLockState($target, '');
                    if ($p['lockAck'] && $p['lockConfirmed'] && $p['releaseAck']) {
                        $verified = $this->ReadJsonAttribute('CoolingCommissionVerified');
                        $verified[(string) $target] = [
                            'sender'=>$sender, 'lockID'=>(int) $p['lockID'],
                            'path'=>'RequestAction','at'=>time()
                        ];
                        $this->WriteJsonAttribute('CoolingCommissionVerified', $verified);
                        $this->CoolingCommissionPhase($p,'passed',
                            'BESTANDEN: GESPERRT und FREI mit LCN-Rueckmeldung bestaetigt. Keine Relaisbefehle.');
                    } else {
                        $this->CoolingCommissionPhase($p,'failed_safe',
                            'S1 ist FREI, aber Sperr-/Freigabetest NICHT bestanden.');
                    }
                } elseif (time() - (int) ($p['releaseAt'] ?? 0) >= 14) {
                    if ((int) ($p['releaseTries'] ?? 0) < 3) {
                        $this->CoolingCommissionPhase($p,'release',
                            'S1-Freigabe nicht bestaetigt; einmal erneut versuchen.');
                    } else {
                        $this->CoolingCommissionPhase($p,'manual_release',
                            'ALARM: S1-Freigabe nicht bestaetigt. LCN-PRO pruefen!');
                    }
                } else {
                    $this->CoolingCommissionRead($p);
                }
            }
            $this->CoolingCommissionSave($p);
            $this->UpdateChatGPTDiagnostic();
        } catch (Throwable $e) {
            $p = $this->CoolingCommissionSession();
            if ((string) ($p['phase'] ?? '') !== 'manual_release') {
                $this->CoolingCommissionPhase($p,'release',
                    'Interner Fehler; Freigabe erneut versuchen: ' . $e->getMessage());
                $this->CoolingCommissionSave($p);
            }
        } finally {
            IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
        }
    }

    public function CoolingCommissionEmergencyRelease(): string
    {
        if (!IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 1500)) {
            return 'ABBRUCH: Instanz beschaeftigt.';
        }
        try {
            $p = $this->CoolingCommissionSession();
            if (!in_array((string) ($p['phase'] ?? ''),
                ['lock','lock_wait','release','release_wait','manual_release'], true)) {
                return 'Keine offene Regler-Testsperre vorhanden; kein Befehl.';
            }
            $this->CoolingCommissionPhase($p,'release',
                'Manueller erneuter S1-Freigabeversuch eingeplant.');
            $p['releaseTries'] = 0;
            $this->CoolingCommissionSave($p);
            return 'Freigabe eingeplant. Anschliessend Teststatus pruefen.';
        } finally {
            IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
        }
    }

    public function CoolingCommissionStatus(): string
    {
        $p = $this->CoolingCommissionSession();
        $rows = ['LCN KUEHLUNG – S1-INBETRIEBNAHME (NUR LESEN)'];
        $passed = 0;
        foreach ($this->GetRuntimeRooms() as $room) {
            $cert = $this->CoolingCommissionVerified($room);
            if ($cert) { $passed++; }
            $rows[] = (string) ($room['Name'] ?? 'Raum') . ': '
                . ($cert ? 'S1-GEPRUEFT' : 'NOCH NICHT GEPRUEFT');
        }
        $rows[] = 'Reglersperrungen nachgewiesen: ' . $passed . '/11';
        $rows[] = 'Letzter/aktueller Einzeltest: '
            . (string) ($p['name'] ?? 'keiner') . ' / '
            . (string) ($p['phase'] ?? 'noch keiner');
        $rows[] = (string) ($p['message'] ?? '');
        $rows[] = 'WICHTIG: Die Reglertests schalten KEIN Relais.';
        $rows[] = 'Produktive Kuehlung braucht auch Relais-/Taupunktschutz-Abnahme.';
        return implode("\n", $rows);
    }

    private function CoolingCommissionAllRegulatorsVerified(): bool
    {
        $rooms = $this->GetRuntimeRooms();
        if (count($rooms) !== 11 || $this->CoolingCommissionActive()) {
            return false;
        }
        foreach ($rooms as $room) {
            if (!$this->CoolingCommissionVerified($room)) {
                return false;
            }
        }
        return true;
    }
}
