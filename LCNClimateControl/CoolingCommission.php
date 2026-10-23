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
            'lock', 'lock_wait', 'relay_on', 'relay_on_wait',
            'relay_off', 'relay_off_wait', 'release', 'release_wait',
            'manual_release', 'manual_relay_off'
        ], true);
    }

    private function CoolingCommissionSave(array $p): void
    {
        $this->WriteJsonAttribute('CoolingCommissionSession', $p);
        $this->SetTimerInterval('CoolingCommissionTimer',
            in_array((string) ($p['phase'] ?? ''), [
                'lock', 'lock_wait', 'relay_on', 'relay_on_wait',
                'relay_off', 'relay_off_wait', 'release', 'release_wait'
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
        return $this->CoolingCommissionStartKind('regulator');
    }

    public function CoolingCommissionStartRelay(): string
    {
        return $this->CoolingCommissionStartKind('relay');
    }

    private function CoolingCommissionStartKind(string $kind): string
    {
        if (!in_array($kind, ['regulator','relay'], true)) {
            return 'ABBRUCH: Unbekannter Pruefmodus.';
        }
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
            if ($kind === 'relay' && !$this->CoolingCommissionAllRegulatorsVerified()) {
                return 'ABBRUCH: Zuerst alle elf S1-Regler erfolgreich pruefen.';
            }
            $skipped = [];
            foreach ($this->GetRuntimeRooms() as $room) {
                if ($kind === 'regulator' && $this->CoolingCommissionVerified($room)) {
                    continue;
                }
                if ($kind === 'relay' && (!$this->CoolingCommissionVerified($room)
                    || $this->CoolingCommissionRelayRoomVerified($room))) {
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
                    'phase'=>'lock', 'kind'=>$kind, 'target'=>$target,
                    'sender'=>(int) $room['SendModule'],
                    'name'=>(string) $room['Name'],
                    'lockID'=>$lockID, 'mapping'=>$map,
                    'valueInstance'=>(int) IPS_GetParent($lockID),
                    'started'=>time(), 'phaseAt'=>time(),
                    'lockConfirmed'=>false, 'lockAck'=>false,
                    'releaseAck'=>false, 'releaseTries'=>0,
                    'message'=>$kind === 'relay'
                        ? 'Einzelraum-Relaispruefung mit voruebergehender S1-Sperre. EIN/AUS und Freigabe.'
                        : 'Einzelraum-S1-Sperrtest gestartet (keine Relaisbefehle).'
                ]);
                $this->UpdateChatGPTDiagnostic();
                return 'START ' . (string) $room['Name']
                    . ($kind === 'relay'
                        ? ': S1 sperren, NUR zugeordnetes Heizventil kurz EIN/AUS, S1 freigeben. '
                        : ': genau ein S1-Sperr-/Freigabetest, KEIN Relaisbefehl. ')
                    . 'Automatische Freigabe nach bestaetigten Rueckmeldungen. '
                    . 'Anschliessend Teststatus anzeigen.';
            }
            return 'Kein weiterer aktuell pruefbarer Raum. '
                . (count($skipped) > 0 ? 'Heizventil offen: '
                    . implode(', ', $skipped) . '. Bei Heizrelais AUS spaeter erneut pruefen.'
                    : ($kind === 'relay'
                        ? 'Alle 11 Ventilrelais sind geprueft.'
                        : 'Alle 11 S1-Regler sind bereits erfolgreich geprueft.'));
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

    /** One response must be newer than the just-issued relay command and read request. */
    private function CoolingCommissionRelayFreshRead(array $p, bool $electric): bool
    {
        $map = $p['mapping'] ?? [];
        $id = (int) ($map['RelayStatusVariable'] ?? 0);
        $sent = (int) ($electric ? ($p['relayOnAt'] ?? 0) : ($p['relayOffAt'] ?? 0));
        $read = (int) ($p['relayReadAt'] ?? 0);
        if (!(bool) ($p['relayReadAck'] ?? false) || $read <= $sent
            || !IPS_VariableExists($id) || (bool) GetValue($id) !== $electric) {
            return false;
        }
        return (int) (IPS_GetVariable($id)['VariableUpdated'] ?? 0) >= $read;
    }

    private function CoolingCommissionRelayRead(array &$p): void
    {
        if (time() - (int) ($p['relayReadAt'] ?? 0) < 4
            || !IPS_FunctionExists('LCN_RequestStatus')) {
            return;
        }
        $id = (int) ($p['mapping']['RelayModule'] ?? 0);
        if (!$this->IsOperationalLCNModule($id)) {
            return;
        }
        $p['relayReadAt'] = time();
        $p['relayReadAck'] = false;
        if (!IPS_SemaphoreEnter(self::GLOBAL_LCN_SEND_SEMAPHORE, 2000)) {
            return;
        }
        try {
            $p['relayReadAck'] = (bool) LCN_RequestStatus($id);
        } catch (Throwable $e) {
            $this->SendDebug('Commission-RelayRead', $e->getMessage(), 0);
        } finally {
            $this->ReleaseLCNBusSlot();
        }
    }

    public function CoolingCommissionWorker(): void
    {
        if (!IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 150)) {
            return;
        }
        try {
            $p = $this->CoolingCommissionSession();
            $phase = (string) ($p['phase'] ?? '');
            if (!in_array($phase, [
                'lock','lock_wait','relay_on','relay_on_wait','relay_off',
                'relay_off_wait','release','release_wait'
            ], true)) {
                $this->SetTimerInterval('CoolingCommissionTimer', 0);
                return;
            }
            $target = (int) ($p['target'] ?? 0);
            $sender = (int) ($p['sender'] ?? 0);
            $map = $p['mapping'] ?? null;
            if (!is_array($map)) {
                $this->CoolingCommissionPhase($p,
                    ($p['kind'] ?? '') === 'relay' ? 'manual_relay_off' : 'manual_release',
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
                    $relayTest = (string) ($p['kind'] ?? 'regulator') === 'relay';
                    $this->CoolingCommissionPhase($p,
                        $relayTest ? 'relay_on' : 'release',
                        $relayTest ? 'S1 GESPERRT bestaetigt; Relais EIN pruefen.'
                            : 'Regler GESPERRT bestaetigt; sofort freigeben.');
                } elseif (time() - (int) ($p['lockAt'] ?? 0) >= 20) {
                    $this->CoolingCommissionPhase($p,'release',
                        'Sperre nicht bestaetigt; Freigabe hat Vorrang.');
                } else {
                    $this->CoolingCommissionRead($p);
                }
            } elseif ($phase === 'relay_on') {
                // On restart never repeat ON; perform OFF as the only
                // permitted recovery. Only after a confirmed S1 lock.
                $this->CoolingCommissionPhase($p,'relay_off',
                    'Relais EIN gestartet: Rueckweg RELAIS AUS gesichert.');
                $p['relayOnAt'] = time();
                $this->CoolingCommissionSave($p);
                $ok = (bool) $p['lockConfirmed']
                    && $this->DirectCoolingRegulatorUnlocked($map) === false
                    && $this->DirectCoolingRelaySet($map, true);
                $p['relayOnAck'] = $ok;
                $this->CoolingCommissionPhase($p, $ok ? 'relay_on_wait' : 'relay_off',
                    $ok ? 'Relais EIN angefordert, LCN-Rueckmeldung ausstehend.'
                        : 'Relais EIN nicht angenommen: sofort Relais AUS.');
            } elseif ($phase === 'relay_on_wait') {
                if ($this->CoolingCommissionRelayFreshRead($p, true)) {
                    $p['relayOnConfirmed'] = true;
                    $this->CoolingCommissionPhase($p,'relay_off',
                        'Relais EIN bestaetigt, jetzt zwingend AUS.');
                } elseif (time() - (int) ($p['relayOnAt'] ?? 0) >= 16) {
                    $this->CoolingCommissionPhase($p,'relay_off',
                        'Relais EIN nicht bestaetigt, AUS wird trotzdem angefordert.');
                } else {
                    $this->CoolingCommissionRelayRead($p);
                }
            } elseif ($phase === 'relay_off') {
                $p['relayOffTries'] = (int) ($p['relayOffTries'] ?? 0) + 1;
                $p['relayOffAt'] = time();
                $p['relayReadAt'] = 0;
                $p['relayReadAck'] = false;
                $this->CoolingCommissionPhase($p,'relay_off_wait',
                    'Relais AUS angefordert, Rueckmeldung ausstehend.');
                $this->CoolingCommissionSave($p);
                $p['relayOffAck'] = $this->DirectCoolingRelaySet($map, false);
            } elseif ($phase === 'relay_off_wait') {
                if ($this->CoolingCommissionRelayFreshRead($p, false)) {
                    $p['relayOffConfirmed'] = true;
                    $this->CoolingCommissionPhase($p,'release',
                        'Ventilrelais AUS bestaetigt. Jetzt S1 freigeben.');
                } elseif (time() - (int) ($p['relayOffAt'] ?? 0) >= 14) {
                    if ((int) ($p['relayOffTries'] ?? 0) < 3) {
                        $this->CoolingCommissionPhase($p,'relay_off',
                            'Relais AUS nicht bestaetigt, erneut AUS senden.');
                    } else {
                        $this->CoolingCommissionPhase($p,'manual_relay_off',
                            'ALARM: Ventilrelais AUS nicht bestaetigt! LCN-PRO/Relaishardware pruefen.');
                    }
                } else {
                    $this->CoolingCommissionRelayRead($p);
                }
            } elseif ($phase === 'release') {
                if ((string) ($p['kind'] ?? '') === 'relay'
                    && !(bool) ($p['relayOffConfirmed'] ?? false)) {
                    $this->CoolingCommissionPhase($p,'relay_off',
                        'Sicherheitsreihenfolge: erst Ventil AUS, dann S1 freigeben.');
                    $this->CoolingCommissionSave($p);
                    return;
                }
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
                    if ($p['lockAck'] && $p['lockConfirmed'] && $p['releaseAck']
                        && ((string) ($p['kind'] ?? '') !== 'relay'
                            || ((bool) ($p['relayOnAck'] ?? false)
                                && (bool) ($p['relayOnConfirmed'] ?? false)
                                && (bool) ($p['relayOffAck'] ?? false)
                                && (bool) ($p['relayOffConfirmed'] ?? false)))) {
                        if ((string) ($p['kind'] ?? '') === 'relay') {
                            $verified = $this->ReadJsonAttribute('CoolingCommissionRelays');
                            $verified[(string) $target] = [
                                'relayModule'=>(int) ($map['RelayModule'] ?? 0),
                                'unit'=>(int) ($map['RelayUnit'] ?? 0),
                                'channel'=>(int) ($map['RelayChannel'] ?? 0),
                                'at'=>time()
                            ];
                            $this->WriteJsonAttribute('CoolingCommissionRelays', $verified);
                        } else {
                            $verified = $this->ReadJsonAttribute('CoolingCommissionVerified');
                            $verified[(string) $target] = [
                                'sender'=>$sender, 'lockID'=>(int) $p['lockID'],
                                'path'=>'RequestAction','at'=>time()
                            ];
                            $this->WriteJsonAttribute('CoolingCommissionVerified', $verified);
                        }
                        $this->CoolingCommissionPhase($p,'passed',
                            (string) ($p['kind'] ?? '') === 'relay'
                                ? 'BESTANDEN: S1 gesperrt, Relais EIN/AUS bestaetigt, S1 FREI.'
                                : 'BESTANDEN: S1 GESPERRT und FREI bestaetigt. Keine Relaisbefehle.');
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
            if (!in_array((string) ($p['phase'] ?? ''),
                ['manual_release','manual_relay_off'], true)) {
                $this->CoolingCommissionPhase($p,
                    (string) ($p['kind'] ?? '') === 'relay' ? 'relay_off' : 'release',
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
                ['lock','lock_wait','relay_on','relay_on_wait','relay_off',
                 'relay_off_wait','release','release_wait',
                 'manual_release','manual_relay_off'], true)) {
                return 'Keine offene Regler-Testsperre vorhanden; kein Befehl.';
            }
            $relayNeedsOff = (string) ($p['kind'] ?? '') === 'relay'
                && !(bool) ($p['relayOffConfirmed'] ?? false);
            $this->CoolingCommissionPhase($p, $relayNeedsOff ? 'relay_off' : 'release',
                $relayNeedsOff ? 'Not-AUS Ventil vor Reglerfreigabe.'
                    : 'Manueller erneuter S1-Freigabeversuch eingeplant.');
            $p['releaseTries'] = 0;
            $p['relayOffTries'] = 0;
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

    /**
     * HARD STOP until all hardware is commissioned.
     * An IPS Boolean can gate software, but it NEVER substitutes
     * for the independent dewpoint switch that physically stops cold water.
     */
    private function CoolingCommissionReadinessErrors(): array
    {
        $errors = [];
        if (!$this->UsesDirectCooling()) {
            $errors[] = 'Direkte Kuehlsteuerung nicht aktiviert';
        }
        if (!$this->CoolingCommissionAllRegulatorsVerified()) {
            $errors[] = 'S1-Regler nicht vollstaendig getestet (11 erforderlich)';
        }
        if (!$this->CoolingCommissionAllRelaysVerified()) {
            $errors[] = 'Ventilrelais nicht vollstaendig getestet (11 erforderlich)';
        }
        if ($this->CoolingCommissionActive() || $this->M11ProbeActive()
            || $this->ReadJsonAttribute('DirectCoolingLocks') !== []) {
            $errors[] = 'Noch offene/ungewisse Reglersperrung';
        }
        $safetyID = (int) $this->ReadPropertyInteger('CoolingSafetyVariable');
        if ($safetyID <= 0 || !$this->DirectCoolingSafe()) {
            $errors[] = 'Externe Software-Kuehlfreigabe fehlt oder FALSE';
        }
        if (!$this->ReadPropertyBoolean('CoolingHardwareInterlockConfirmed')) {
            $errors[] = 'Hardware-Taupunktwaechter/Wasserabschaltung nicht abgenommen';
        }
        if ($errors === []) {
            $errors = $this->DirectCoolingPreflight();
        }
        return $errors;
    }

    private function CoolingCommissionRelayRoomVerified(array $room): bool
    {
        $target = (int) ($room['TargetVariable'] ?? 0);
        $map = $this->DirectCoolingMapping($target);
        $all = $this->ReadJsonAttribute('CoolingCommissionRelays');
        $record = $all[(string) $target] ?? null;
        return $this->CoolingCommissionVerified($room)
            && is_array($map) && is_array($record)
            && (int) ($record['relayModule'] ?? 0) === (int) ($map['RelayModule'] ?? 0)
            && (int) ($record['unit'] ?? 0) === (int) ($map['RelayUnit'] ?? 0)
            && (int) ($record['channel'] ?? 0) === (int) ($map['RelayChannel'] ?? 0)
            && (int) ($record['at'] ?? 0) > 0;
    }

    private function CoolingCommissionAllRelaysVerified(): bool
    {
        $rooms = $this->GetRuntimeRooms();
        if (count($rooms) !== 11) {
            return false;
        }
        foreach ($rooms as $room) {
            if (!$this->CoolingCommissionRelayRoomVerified($room)) {
                return false;
            }
        }
        return true;
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
