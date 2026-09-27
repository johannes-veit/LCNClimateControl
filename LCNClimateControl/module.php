<?php

declare(strict_types=1);

/**
 * LCN Heizung / Kühlung für IP-Symcon 9
 *
 * Grundprinzip:
 * - LCN bleibt Master und regelt die Fußbodenheizung weiterhin vollständig.
 * - Symcon setzt niemals LCN-Reglerwerte oder Relais direkt.
 * - Jede Änderung erfolgt ausschließlich über die vorhandenen LCN-KURZ-Tasten
 *   (standardmäßig A7 = Soll +1 °C / Ventil Richtung AUF,
 *    A8 = Soll -1 °C / Ventil Richtung ZU).
 * - Die native S1Target-Floatvariable ist die einzige Wahrheitsquelle für den
 *   tatsächlich erreichten LCN-Reglersollwert.
 */
class LCNClimateControl extends IPSModuleStrict
{
    private const STATUS_ACTIVE = 102;
    private const STATUS_INACTIVE = 104;
    private const STATUS_CONFIG_ERROR = 201;
    private const STATUS_RUNTIME_ERROR = 202;
    private const STATUS_BUSY = 203;

    private const MSG_VARIABLE_UPDATE = 10603; // VM_UPDATE

    private const MODE_HEATING = 0;
    private const MODE_COOLING = 1;

    private const JOB_HEAT = 'heat';
    private const JOB_COOL_UP = 'cool_up';
    private const JOB_COOL_DOWN = 'cool_down';

    private const PHASE_SEND = 'send';
    private const PHASE_WAIT = 'wait';

    // Native LCN module ID used by the existing, proven LCN-Light integration.
    private const LCN_MODULE_MODULE_ID = '{0E31FED6-E465-4621-95D4-AAF2683C41EC}';

    private const PROFILE_MODE = 'LCNC.Mode';
    private const PROFILE_HEAT = 'LCNC.HeatSetpoint';
    private const PROFILE_COOL = 'LCNC.Cooling';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Rooms', '[]');
        $this->RegisterPropertyInteger('StepWaitMs', 900);
        $this->RegisterPropertyInteger('MaxSteps', 25);
        $this->RegisterPropertyInteger('NoChangeConfirmations', 2);

        $this->RegisterAttributeString('LastHeatingTargets', '{}');
        $this->RegisterAttributeString('CoolingStates', '{}');
        $this->RegisterAttributeString('RegisteredTargetMessages', '[]');
        $this->RegisterAttributeString('RegisteredReferences', '[]');
        $this->RegisterAttributeString('LastError', '');

        $this->RegisterTimer('Worker', 0, 'LCNC_Worker($_IPS[\'TARGET\']);');

        $this->EnsureProfiles();

        $this->RegisterVariableInteger('Mode', 'Betriebsart', self::PROFILE_MODE, 10);
        $this->EnableAction('Mode');

    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();
        $this->SetTimerInterval('Worker', 0);
        $this->SetBuffer('Queue', '[]');
        $this->SetBuffer('CurrentJob', '');

        $this->DetachMessagesAndReferences();

        $rooms = $this->GetRooms();
        $valid = true;
        $position = 100;

        foreach ($rooms as $room) {
            if (!$room['Enabled']) {
                continue;
            }

            if (!$this->ValidateRoom($room, false)) {
                $valid = false;
                continue;
            }

            $targetID = $room['TargetVariable'];
            $tempID = $room['TemperatureVariable'];
            $sendModule = $room['SendModule'];

            $this->RegisterReferenceSafe($sendModule);
            $this->RegisterReferenceSafe($targetID);
            if ($tempID > 0) {
                $this->RegisterReferenceSafe($tempID);
            }

            $this->RegisterMessage($targetID, self::MSG_VARIABLE_UPDATE);
            $this->AppendRegisteredTarget($targetID);

            $heatIdent = $this->HeatIdent($targetID);
            $coolIdent = $this->CoolIdent($targetID);

            $this->MaintainVariable($heatIdent, $room['Name'] . ' – Soll', VARIABLETYPE_FLOAT, self::PROFILE_HEAT, $position + 1, true);
            $this->EnableAction($heatIdent);

            $this->MaintainVariable($coolIdent, $room['Name'] . ' – Kühlung', VARIABLETYPE_BOOLEAN, self::PROFILE_COOL, $position + 1, true);
            $this->EnableAction($coolIdent);

            if ($tempID > 0) {
                $this->EnsureLink($this->TempLinkIdent($targetID), $room['Name'] . ' – Ist', $tempID, $position);
            } else {
                $this->RemoveObjectByIdent($this->TempLinkIdent($targetID));
            }

            $currentTarget = $this->ReadTarget($targetID);
            if ($currentTarget !== null && $this->GetMode() === self::MODE_HEATING) {
                $this->SetValueIfChanged($heatIdent, $currentTarget);
                $this->StoreLastHeatingTarget($targetID, $currentTarget);
            }

            $coolingState = $this->GetStoredCoolingState($targetID);
            $this->SetValueIfChanged($coolIdent, $coolingState);

            $position += 10;
        }

        $this->CleanupStaleRoomObjects($rooms);
        $this->ApplyModeVisibility();

        if (!$valid) {
            $this->SetStatus(self::STATUS_CONFIG_ERROR);
        } elseif ($this->CountEnabledValidRooms($rooms) === 0) {
            $this->SetStatus(self::STATUS_INACTIVE);
        } elseif ($this->ReadAttributeString('LastError') !== '') {
            $this->SetStatus(self::STATUS_RUNTIME_ERROR);
        } else {
            $this->SetStatus(self::STATUS_ACTIVE);
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message !== self::MSG_VARIABLE_UPDATE) {
            return;
        }

        $room = $this->FindRoomByTarget($SenderID);
        if ($room === null) {
            return;
        }

        // Niemals $Data interpretieren: den echten aktuellen Variablenwert lesen.
        $value = $this->ReadTarget($SenderID);
        if ($value === null) {
            return;
        }

        $currentJob = $this->GetCurrentJob();
        $jobOwnsTarget = is_array($currentJob)
            && (int) ($currentJob['TargetVariable'] ?? 0) === $SenderID;

        if ($this->GetMode() === self::MODE_HEATING && !$jobOwnsTarget) {
            $this->SetValueIfChanged($this->HeatIdent($SenderID), $value);
            $this->StoreLastHeatingTarget($SenderID, $value);
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'Mode') {
            $this->RequestMode((int) $Value);
            return;
        }

        $rooms = $this->GetRooms();
        foreach ($rooms as $room) {
            if (!$room['Enabled'] || !$this->ValidateRoom($room, false)) {
                continue;
            }

            $targetID = $room['TargetVariable'];

            if ($Ident === $this->HeatIdent($targetID)) {
                $this->RequestHeatingTarget($room, (float) $Value);
                return;
            }

            if ($Ident === $this->CoolIdent($targetID)) {
                $this->RequestCoolingState($room, (bool) $Value);
                return;
            }
        }

        throw new InvalidArgumentException('Unbekannte Aktion: ' . $Ident);
    }

    public function Worker(): void
    {
        if (!IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 1000)) {
            return;
        }

        try {
            $job = $this->GetCurrentJob();
            if (!is_array($job)) {
                $job = $this->StartNextJob();
                if (!is_array($job)) {
                    $this->SetTimerInterval('Worker', 0);
                    if ($this->ReadAttributeString('LastError') !== '') {
                        $this->SetStatus(self::STATUS_RUNTIME_ERROR);
                    } else {
                        $this->SetStatus(self::STATUS_ACTIVE);
                    }
                    return;
                }
            }

            $this->ProcessJob($job);
        } finally {
            IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
        }
    }

    public function RequestAllTargets(): bool
    {
        $ok = true;
        foreach ($this->GetRooms() as $room) {
            if (!$room['Enabled'] || !$this->ValidateRoom($room, false)) {
                continue;
            }
            $ok = $this->RequestTargetRead($room['TargetVariable']) && $ok;
        }
        return $ok;
    }

    public function Abort(): void
    {
        if (IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 1000)) {
            try {
                $this->SetBuffer('Queue', '[]');
                $this->SetBuffer('CurrentJob', '');
                $this->SetTimerInterval('Worker', 0);
                $this->SyncAllHeatingDisplayFromLCN();
                $this->SetStatus($this->ReadAttributeString('LastError') === '' ? self::STATUS_ACTIVE : self::STATUS_RUNTIME_ERROR);
                $this->SendDebug('Abort', 'Laufender Symcon-Auftrag wurde abgebrochen. LCN/GT8 bleiben unverändert bedienbar.', 0);
            } finally {
                IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
            }
        }
    }

    public function ClearError(): void
    {
        $this->WriteAttributeString('LastError', '');
        if ($this->IsBusy()) {
            $this->SetStatus(self::STATUS_BUSY);
        } elseif ($this->CountEnabledValidRooms($this->GetRooms()) > 0) {
            $this->SetStatus(self::STATUS_ACTIVE);
        } else {
            $this->SetStatus(self::STATUS_INACTIVE);
        }
    }

    private function RequestMode(int $Mode): void
    {
        if (!in_array($Mode, [self::MODE_HEATING, self::MODE_COOLING], true)) {
            throw new InvalidArgumentException('Ungültige Betriebsart.');
        }

        if ($Mode === $this->GetMode()) {
            return;
        }

        if ($this->IsBusy()) {
            throw new RuntimeException('Betriebsart kann während eines laufenden LCN-Auftrags nicht gewechselt werden.');
        }

        $rooms = array_values(array_filter(
            $this->GetRooms(),
            fn(array $room): bool => $room['Enabled'] && $this->ValidateRoom($room, false)
        ));

        if ($Mode === self::MODE_COOLING) {
            // Vor jeglicher Kühlbewegung den echten aktuellen Heizsollwert jedes Raums sichern.
            foreach ($rooms as $room) {
                $actual = $this->ReadTarget($room['TargetVariable']);
                if ($actual !== null) {
                    $this->StoreLastHeatingTarget($room['TargetVariable'], $actual);
                    $this->SetValueIfChanged($this->HeatIdent($room['TargetVariable']), $actual);
                }
            }

            $this->SetValue('Mode', self::MODE_COOLING);
            $this->ApplyModeVisibility();

            foreach ($rooms as $room) {
                $state = $this->GetStoredCoolingState($room['TargetVariable']);
                $this->EnqueueCoolingJob($room, $state, $state);
            }
        } else {
            $this->SetValue('Mode', self::MODE_HEATING);
            $this->ApplyModeVisibility();

            foreach ($rooms as $room) {
                $restore = $this->GetLastHeatingTarget($room['TargetVariable']);
                if ($restore === null) {
                    // Kein gespeicherter Heizwert: sicherheitshalber nichts bewegen.
                    continue;
                }
                $this->SetValueIfChanged($this->HeatIdent($room['TargetVariable']), $restore);
                $this->EnqueueHeatJob($room, $restore);
            }
        }

        $this->StartWorkerIfNeeded();
    }

    private function RequestHeatingTarget(array $Room, float $Value): void
    {
        if ($this->GetMode() !== self::MODE_HEATING) {
            throw new RuntimeException('Solltemperatur ist nur im Heizbetrieb bedienbar.');
        }

        $rounded = round($Value);
        if ($rounded < 18.0 || $rounded > 24.0 || abs($Value - $rounded) > 0.01) {
            throw new InvalidArgumentException('Symcon erlaubt im Heizbetrieb nur ganze Sollwerte von 18 bis 24 °C.');
        }

        $targetID = $Room['TargetVariable'];
        $this->SetValueIfChanged($this->HeatIdent($targetID), (float) $rounded);
        $this->EnqueueHeatJob($Room, (float) $rounded);
        $this->StartWorkerIfNeeded();
    }

    private function RequestCoolingState(array $Room, bool $State): void
    {
        if ($this->GetMode() !== self::MODE_COOLING) {
            throw new RuntimeException('Kühlung ist nur im Kühlbetrieb bedienbar.');
        }

        $targetID = $Room['TargetVariable'];
        $previous = $this->GetStoredCoolingState($targetID);

        // Optimistische Bedienanzeige; bei Fehler wird auf previous zurückgestellt.
        $this->SetValueIfChanged($this->CoolIdent($targetID), $State);
        $this->EnqueueCoolingJob($Room, $State, $previous);
        $this->StartWorkerIfNeeded();
    }

    private function ProcessJob(array $Job): void
    {
        $targetID = (int) $Job['TargetVariable'];
        $actual = $this->ReadTarget($targetID);
        if ($actual === null) {
            $this->FailCurrentJob($Job, 'S1Target ist nicht lesbar.');
            return;
        }

        $phase = (string) ($Job['Phase'] ?? self::PHASE_SEND);

        if ($phase === self::PHASE_SEND) {
            if ($Job['Type'] === self::JOB_HEAT) {
                $desired = (float) $Job['Desired'];
                if (abs($actual - $desired) < 0.25) {
                    $this->CompleteCurrentJob($Job, $actual);
                    return;
                }
                $direction = $actual < $desired ? 1 : -1;
            } elseif ($Job['Type'] === self::JOB_COOL_UP) {
                $direction = 1;
            } else {
                $direction = -1;
            }

            $steps = (int) ($Job['Steps'] ?? 0);
            if ($steps >= $this->GetMaxSteps()) {
                $this->FailCurrentJob($Job, 'Sicherheitsgrenze von ' . $this->GetMaxSteps() . ' Tastendrücken erreicht.');
                return;
            }

            if (!$this->SendShortKey($Job, $direction > 0)) {
                $this->FailCurrentJob($Job, 'LCN-KURZ-Tastenbefehl konnte nicht gesendet werden.');
                return;
            }

            $Job['Before'] = $actual;
            $Job['Direction'] = $direction;
            $Job['Steps'] = $steps + 1;
            $Job['Phase'] = self::PHASE_WAIT;
            $Job['WaitStage'] = 0;
            $Job['SentAtMs'] = $this->NowMs();
            $this->SetCurrentJob($Job);
            return;
        }

        $elapsed = $this->NowMs() - (int) ($Job['SentAtMs'] ?? 0);
        if ($elapsed < $this->GetStepWaitMs()) {
            return;
        }

        $before = (float) ($Job['Before'] ?? $actual);
        $delta = $actual - $before;
        $direction = (int) ($Job['Direction'] ?? 0);

        if (abs($delta) >= 0.40) {
            // Erwartet sind 1-K-Schritte. Jede andere oder entgegengesetzte Änderung
            // wird als externer GT8-/LCN-Eingriff gewertet: GT8 hat Vorrang.
            $expectedDelta = $direction > 0 ? 1.0 : -1.0;
            if (abs($delta - $expectedDelta) > 0.35) {
                $this->FailCurrentJob(
                    $Job,
                    sprintf('Unerwartete S1Target-Änderung %.1f K erkannt; externer LCN/GT8-Eingriff hat Vorrang.', $delta)
                );
                return;
            }

            $Job['NoChange'] = 0;
            $Job['Phase'] = self::PHASE_SEND;
            $this->SetCurrentJob($Job);
            return;
        }

        $waitStage = (int) ($Job['WaitStage'] ?? 0);
        if ($waitStage === 0) {
            if (!$this->RequestTargetRead($targetID)) {
                $this->FailCurrentJob($Job, 'LCN_RequestRead für S1Target fehlgeschlagen.');
                return;
            }
            $Job['WaitStage'] = 1;
            $Job['SentAtMs'] = $this->NowMs();
            $this->SetCurrentJob($Job);
            return;
        }

        // Nach Tastendruck + bestätigter Nachlese weiterhin unverändert.
        $noChange = (int) ($Job['NoChange'] ?? 0) + 1;
        $Job['NoChange'] = $noChange;

        if ($Job['Type'] === self::JOB_HEAT) {
            if ($noChange >= 2) {
                $this->FailCurrentJob(
                    $Job,
                    'Heiz-Sollwert konnte nicht weiter verändert werden. Möglicherweise liegt der gewünschte Wert außerhalb der LCN-Reglergrenze.'
                );
                return;
            }
            $Job['Phase'] = self::PHASE_SEND;
            $this->SetCurrentJob($Job);
            return;
        }

        if ($noChange >= $this->GetNoChangeConfirmations()) {
            // Kühlbetrieb braucht keinen Zahlenwert. Zwei (konfigurierbare) bestätigte
            // unveränderte Tastendrücke bedeuten: LCN-Regler-Endlage erreicht.
            $this->CompleteCurrentJob($Job, $actual);
            return;
        }

        $Job['Phase'] = self::PHASE_SEND;
        $this->SetCurrentJob($Job);
    }

    private function CompleteCurrentJob(array $Job, float $Actual): void
    {
        $targetID = (int) $Job['TargetVariable'];

        if ($Job['Type'] === self::JOB_HEAT) {
            $this->SetValueIfChanged($this->HeatIdent($targetID), $Actual);
            $this->StoreLastHeatingTarget($targetID, $Actual);
        } else {
            $state = $Job['Type'] === self::JOB_COOL_UP;
            $this->SetValueIfChanged($this->CoolIdent($targetID), $state);
            $this->StoreCoolingState($targetID, $state);
        }

        $this->SendDebug(
            'Complete',
            sprintf('%s: Auftrag fertig nach %d Tastendrücken; S1Target %.1f °C', $Job['Name'], (int) ($Job['Steps'] ?? 0), $Actual),
            0
        );

        $this->SetBuffer('CurrentJob', '');
        $this->StartNextJob();
    }

    private function FailCurrentJob(array $Job, string $Reason): void
    {
        $targetID = (int) ($Job['TargetVariable'] ?? 0);

        if (($Job['Type'] ?? '') === self::JOB_HEAT) {
            $actual = $this->ReadTarget($targetID);
            if ($actual !== null) {
                $this->SetValueIfChanged($this->HeatIdent($targetID), $actual);
                if ($this->GetMode() === self::MODE_HEATING) {
                    $this->StoreLastHeatingTarget($targetID, $actual);
                }
            }
        } else {
            $previous = (bool) ($Job['PreviousCoolingState'] ?? false);
            $this->SetValueIfChanged($this->CoolIdent($targetID), $previous);
            $this->StoreCoolingState($targetID, $previous);
        }

        $message = ($Job['Name'] ?? 'Raum') . ': ' . $Reason;
        $this->WriteAttributeString('LastError', $message);
        $this->SendDebug('ERROR', $message, 0);
        $this->SetStatus(self::STATUS_RUNTIME_ERROR);

        // Nur der fehlerhafte Raumauftrag wird verworfen. Andere Räume einer
        // globalen Umschaltung werden weiter abgearbeitet.
        $this->SetBuffer('CurrentJob', '');
        $this->StartNextJob();
    }

    private function StartNextJob(): ?array
    {
        $queue = $this->GetQueue();
        if ($queue === []) {
            $this->SetBuffer('CurrentJob', '');
            return null;
        }

        $job = array_shift($queue);
        $this->SetQueue($queue);
        $job['Phase'] = self::PHASE_SEND;
        $job['Steps'] = 0;
        $job['NoChange'] = 0;
        $job['WaitStage'] = 0;
        $this->SetCurrentJob($job);
        $this->SetStatus(self::STATUS_BUSY);
        return $job;
    }

    private function EnqueueHeatJob(array $Room, float $Desired): void
    {
        $this->AppendJob([
            'Type' => self::JOB_HEAT,
            'Name' => $Room['Name'],
            'SendModule' => $Room['SendModule'],
            'TargetVariable' => $Room['TargetVariable'],
            'Table' => $Room['Table'],
            'UpKey' => $Room['UpKey'],
            'DownKey' => $Room['DownKey'],
            'Desired' => $Desired
        ]);
    }

    private function EnqueueCoolingJob(array $Room, bool $State, bool $Previous): void
    {
        $this->AppendJob([
            'Type' => $State ? self::JOB_COOL_UP : self::JOB_COOL_DOWN,
            'Name' => $Room['Name'],
            'SendModule' => $Room['SendModule'],
            'TargetVariable' => $Room['TargetVariable'],
            'Table' => $Room['Table'],
            'UpKey' => $Room['UpKey'],
            'DownKey' => $Room['DownKey'],
            'PreviousCoolingState' => $Previous
        ]);
    }

    private function AppendJob(array $Job): void
    {
        $queue = $this->GetQueue();

        // Für denselben Raum nur den neuesten noch nicht gestarteten Auftrag behalten.
        $targetID = (int) $Job['TargetVariable'];
        $queue = array_values(array_filter(
            $queue,
            fn(array $queued): bool => (int) ($queued['TargetVariable'] ?? 0) !== $targetID
        ));
        $queue[] = $Job;
        $this->SetQueue($queue);
    }

    private function StartWorkerIfNeeded(): void
    {
        if ($this->GetCurrentJob() === null) {
            $this->StartNextJob();
        }
        if ($this->GetCurrentJob() !== null || $this->GetQueue() !== []) {
            $this->SetStatus(self::STATUS_BUSY);
            $this->SetTimerInterval('Worker', 250);
        }
    }

    private function SendShortKey(array $Job, bool $Up): bool
    {
        $sendModule = (int) $Job['SendModule'];
        if ($sendModule <= 0 || !IPS_InstanceExists($sendModule)) {
            return false;
        }

        $table = $this->NormalizeTable((string) $Job['Table']);
        $key = (int) ($Up ? $Job['UpKey'] : $Job['DownKey']);
        $data = $this->BuildShortTSData($table, $key);

        try {
            $result = (bool) LCN_SendCommand($sendModule, 'TS', $data);
            $this->SendDebug(
                'TS',
                sprintf('%s %s%d KURZ -> %s über #%d', $Job['Name'], $table, $key, $data, $sendModule),
                0
            );
            return $result;
        } catch (Throwable $e) {
            $this->SendDebug('TS', 'LCN_SendCommand: ' . $e->getMessage(), 0);
            return false;
        }
    }

    private function BuildShortTSData(string $Table, int $Key): string
    {
        $tableIndex = array_search($Table, ['A', 'B', 'C', 'D'], true);
        if ($tableIndex === false || $Key < 1 || $Key > 8) {
            throw new InvalidArgumentException('Ungültige LCN-Taste.');
        }

        $tables = ['-', '-', '-', '-'];
        $tables[$tableIndex] = 'K';
        $keys = array_fill(0, 8, '0');
        $keys[$Key - 1] = '1';

        return implode('', $tables) . implode('', $keys);
    }

    private function RequestTargetRead(int $TargetVariable): bool
    {
        if ($TargetVariable <= 0 || !IPS_VariableExists($TargetVariable)) {
            return false;
        }

        $parent = IPS_GetParent($TargetVariable);
        if ($parent <= 0 || !IPS_InstanceExists($parent)) {
            return false;
        }

        try {
            $result = (bool) LCN_RequestRead($parent);
            $this->SendDebug('RequestRead', sprintf('S1Target #%d über LCN-Wertinstanz #%d', $TargetVariable, $parent), 0);
            return $result;
        } catch (Throwable $e) {
            $this->SendDebug('RequestRead', 'Fehler: ' . $e->getMessage(), 0);
            return false;
        }
    }

    private function ValidateRoom(array $Room, bool $Debug = true): bool
    {
        if (!$Room['Enabled']) {
            return false;
        }

        if ($Room['Name'] === '') {
            if ($Debug) {
                $this->SendDebug('Config', 'Raum ohne Namen.', 0);
            }
            return false;
        }

        $sendModule = $Room['SendModule'];
        if ($sendModule <= 0 || !IPS_InstanceExists($sendModule)) {
            if ($Debug) {
                $this->SendDebug('Config', $Room['Name'] . ': LCN-Sendemodul ungültig.', 0);
            }
            return false;
        }

        try {
            $instance = IPS_GetInstance($sendModule);
            $moduleID = strtoupper((string) ($instance['ModuleInfo']['ModuleID'] ?? ''));
            if ($moduleID !== strtoupper(self::LCN_MODULE_MODULE_ID)) {
                if ($Debug) {
                    $this->SendDebug('Config', $Room['Name'] . ': gewählte Sendemodul-Instanz ist kein natives LCN Modul.', 0);
                }
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $targetID = $Room['TargetVariable'];
        if ($targetID <= 0 || !IPS_VariableExists($targetID)) {
            return false;
        }

        try {
            $var = IPS_GetVariable($targetID);
            if ((int) ($var['VariableType'] ?? -1) !== VARIABLETYPE_FLOAT) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $targetParent = IPS_GetParent($targetID);
        if ($targetParent <= 0 || !IPS_InstanceExists($targetParent)) {
            return false;
        }

        // Prozesssicherheitsprüfung: Die S1Target-LCN-Wertinstanz muss technisch
        // genau an dem LCN-Modul hängen, über das auch A7/A8 gesendet werden.
        // Dadurch kann eine falsch zugeordnete Sendemodul-ID nicht unbemerkt
        // einen anderen Raum bedienen.
        try {
            $targetParentInstance = IPS_GetInstance($targetParent);
            $connectionID = (int) ($targetParentInstance['ConnectionID'] ?? 0);
            if ($connectionID <= 0 || $connectionID !== $sendModule) {
                if ($Debug) {
                    $this->SendDebug(
                        'Config',
                        sprintf(
                            '%s: S1Target ConnectionID #%d passt nicht zum gewählten LCN-Sendemodul #%d.',
                            $Room['Name'],
                            $connectionID,
                            $sendModule
                        ),
                        0
                    );
                }
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        // Schutz vor versehentlich ausgewählter beliebiger Float-Variable:
        // die native LCN-Wertinstanz muss S1TargetEnabled als Property besitzen und aktiv haben.
        try {
            $cfg = json_decode(IPS_GetConfiguration($targetParent), true);
            if (!is_array($cfg) || !array_key_exists('S1TargetEnabled', $cfg) || !(bool) $cfg['S1TargetEnabled']) {
                if ($Debug) {
                    $this->SendDebug('Config', $Room['Name'] . ': Parent der Zielvariable ist keine aktive S1Target-LCN-Wertinstanz.', 0);
                }
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $tempID = $Room['TemperatureVariable'];
        if ($tempID > 0) {
            if (!IPS_VariableExists($tempID)) {
                return false;
            }
            try {
                $var = IPS_GetVariable($tempID);
                if ((int) ($var['VariableType'] ?? -1) !== VARIABLETYPE_FLOAT) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return in_array($Room['Table'], ['A', 'B', 'C', 'D'], true)
            && $Room['UpKey'] >= 1 && $Room['UpKey'] <= 8
            && $Room['DownKey'] >= 1 && $Room['DownKey'] <= 8;
    }

    private function GetRooms(): array
    {
        $decoded = json_decode($this->ReadPropertyString('Rooms'), true);
        if (!is_array($decoded)) {
            return [];
        }

        $rooms = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $targetID = (int) ($row['TargetVariable'] ?? 0);
            $name = trim((string) ($row['Name'] ?? ''));
            if ($name === '' && $targetID > 0 && IPS_VariableExists($targetID)) {
                $name = IPS_GetName($targetID);
            }

            $rooms[] = [
                'Enabled' => (bool) ($row['Enabled'] ?? true),
                'Name' => $name,
                'SendModule' => (int) ($row['SendModule'] ?? 0),
                'TemperatureVariable' => (int) ($row['TemperatureVariable'] ?? 0),
                'TargetVariable' => $targetID,
                'Table' => $this->NormalizeTable((string) ($row['Table'] ?? 'A')),
                'UpKey' => max(1, min(8, (int) ($row['UpKey'] ?? 7))),
                'DownKey' => max(1, min(8, (int) ($row['DownKey'] ?? 8)))
            ];
        }

        return $rooms;
    }

    private function CountEnabledValidRooms(array $Rooms): int
    {
        $count = 0;
        foreach ($Rooms as $room) {
            if ($room['Enabled'] && $this->ValidateRoom($room, false)) {
                $count++;
            }
        }
        return $count;
    }

    private function FindRoomByTarget(int $TargetID): ?array
    {
        foreach ($this->GetRooms() as $room) {
            if ($room['Enabled'] && $room['TargetVariable'] === $TargetID) {
                return $room;
            }
        }
        return null;
    }

    private function ReadTarget(int $TargetID): ?float
    {
        if ($TargetID <= 0 || !IPS_VariableExists($TargetID)) {
            return null;
        }
        try {
            return (float) GetValue($TargetID);
        } catch (Throwable) {
            return null;
        }
    }

    private function GetMode(): int
    {
        try {
            $mode = (int) $this->GetValue('Mode');
        } catch (Throwable) {
            return self::MODE_HEATING;
        }
        return $mode === self::MODE_COOLING ? self::MODE_COOLING : self::MODE_HEATING;
    }

    private function EnsureProfiles(): void
    {
        if (!IPS_VariableProfileExists(self::PROFILE_MODE)) {
            IPS_CreateVariableProfile(self::PROFILE_MODE, VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileAssociation(self::PROFILE_MODE, self::MODE_HEATING, 'Heizen', '', -1);
        IPS_SetVariableProfileAssociation(self::PROFILE_MODE, self::MODE_COOLING, 'Kühlen', '', -1);

        if (!IPS_VariableProfileExists(self::PROFILE_HEAT)) {
            IPS_CreateVariableProfile(self::PROFILE_HEAT, VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileValues(self::PROFILE_HEAT, 18.0, 24.0, 1.0);
        IPS_SetVariableProfileDigits(self::PROFILE_HEAT, 0);
        IPS_SetVariableProfileText(self::PROFILE_HEAT, '', ' °C');

        if (!IPS_VariableProfileExists(self::PROFILE_COOL)) {
            IPS_CreateVariableProfile(self::PROFILE_COOL, VARIABLETYPE_BOOLEAN);
        }
        IPS_SetVariableProfileAssociation(self::PROFILE_COOL, 0, 'AUS – keine Kühlung', '', -1);
        IPS_SetVariableProfileAssociation(self::PROFILE_COOL, 1, 'AN – Kühlen', '', -1);
    }

    private function ApplyModeVisibility(): void
    {
        $cooling = $this->GetMode() === self::MODE_COOLING;

        foreach ($this->GetRooms() as $room) {
            if (!$room['Enabled'] || $room['TargetVariable'] <= 0) {
                continue;
            }

            $heatID = $this->FindOwnObjectByIdent($this->HeatIdent($room['TargetVariable']));
            if (is_int($heatID) && $heatID > 0 && IPS_ObjectExists($heatID)) {
                IPS_SetHidden($heatID, $cooling);
            }

            $coolID = $this->FindOwnObjectByIdent($this->CoolIdent($room['TargetVariable']));
            if (is_int($coolID) && $coolID > 0 && IPS_ObjectExists($coolID)) {
                IPS_SetHidden($coolID, !$cooling);
                IPS_SetInfo($coolID, 'AN = Kühlen = Ventil geöffnet; AUS = keine Kühlung = Ventil geschlossen.');
            }
        }
    }

    private function EnsureLink(string $Ident, string $Name, int $TargetID, int $Position): void
    {
        $objectID = $this->FindOwnObjectByIdent($Ident);
        if (!is_int($objectID) || $objectID <= 0 || !IPS_LinkExists($objectID)) {
            $objectID = IPS_CreateLink();
            IPS_SetParent($objectID, $this->InstanceID);
            IPS_SetIdent($objectID, $Ident);
        }
        IPS_SetName($objectID, $Name);
        IPS_SetPosition($objectID, $Position);
        IPS_SetLinkTargetID($objectID, $TargetID);
    }

    private function CleanupStaleRoomObjects(array $Rooms): void
    {
        $wanted = ['Mode' => true];
        foreach ($Rooms as $room) {
            if (!$room['Enabled'] || $room['TargetVariable'] <= 0) {
                continue;
            }
            $wanted[$this->HeatIdent($room['TargetVariable'])] = true;
            $wanted[$this->CoolIdent($room['TargetVariable'])] = true;
            if ($room['TemperatureVariable'] > 0) {
                $wanted[$this->TempLinkIdent($room['TargetVariable'])] = true;
            }
        }

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $object = IPS_GetObject($childID);
            $ident = (string) ($object['ObjectIdent'] ?? '');
            if (
                (str_starts_with($ident, 'Heat_') || str_starts_with($ident, 'Cool_') || str_starts_with($ident, 'Temp_'))
                && !isset($wanted[$ident])
            ) {
                if (str_starts_with($ident, 'Temp_') && IPS_LinkExists($childID)) {
                    IPS_DeleteLink($childID);
                } elseif (str_starts_with($ident, 'Heat_') || str_starts_with($ident, 'Cool_')) {
                    $this->UnregisterVariable($ident);
                }
            }
        }
    }

    private function RemoveObjectByIdent(string $Ident): void
    {
        $id = $this->FindOwnObjectByIdent($Ident);
        if (is_int($id) && $id > 0 && IPS_LinkExists($id)) {
            IPS_DeleteLink($id);
        }
    }

    private function FindOwnObjectByIdent(string $Ident): int
    {
        $id = @IPS_GetObjectIDByIdent($Ident, $this->InstanceID);
        return is_int($id) ? $id : 0;
    }

    private function HeatIdent(int $TargetID): string
    {
        return 'Heat_' . $TargetID;
    }

    private function CoolIdent(int $TargetID): string
    {
        return 'Cool_' . $TargetID;
    }

    private function TempLinkIdent(int $TargetID): string
    {
        return 'Temp_' . $TargetID;
    }

    private function NormalizeTable(string $Table): string
    {
        $table = strtoupper(trim($Table));
        return in_array($table, ['A', 'B', 'C', 'D'], true) ? $table : 'A';
    }

    private function GetStepWaitMs(): int
    {
        return max(500, min(3000, $this->ReadPropertyInteger('StepWaitMs')));
    }

    private function GetMaxSteps(): int
    {
        return max(15, min(50, $this->ReadPropertyInteger('MaxSteps')));
    }

    private function GetNoChangeConfirmations(): int
    {
        return max(2, min(4, $this->ReadPropertyInteger('NoChangeConfirmations')));
    }

    private function NowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private function GetQueue(): array
    {
        $decoded = json_decode($this->GetBuffer('Queue'), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function SetQueue(array $Queue): void
    {
        $this->SetBuffer('Queue', json_encode(array_values($Queue), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
    }

    private function GetCurrentJob(): ?array
    {
        $raw = $this->GetBuffer('CurrentJob');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function SetCurrentJob(array $Job): void
    {
        $this->SetBuffer('CurrentJob', json_encode($Job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function IsBusy(): bool
    {
        return $this->GetCurrentJob() !== null || $this->GetQueue() !== [];
    }

    private function SetValueIfChanged(string $Ident, mixed $Value): void
    {
        try {
            $current = $this->GetValue($Ident);
            if (is_float($Value)) {
                if (abs((float) $current - $Value) < 0.001) {
                    return;
                }
            } elseif ($current === $Value) {
                return;
            }
            $this->SetValue($Ident, $Value);
        } catch (Throwable) {
            // Ident may be absent during configuration changes.
        }
    }

    private function StoreLastHeatingTarget(int $TargetID, float $Value): void
    {
        $map = $this->ReadJsonAttribute('LastHeatingTargets');
        $map[(string) $TargetID] = $Value;
        $this->WriteJsonAttribute('LastHeatingTargets', $map);
    }

    private function GetLastHeatingTarget(int $TargetID): ?float
    {
        $map = $this->ReadJsonAttribute('LastHeatingTargets');
        $key = (string) $TargetID;
        return array_key_exists($key, $map) ? (float) $map[$key] : null;
    }

    private function StoreCoolingState(int $TargetID, bool $State): void
    {
        $map = $this->ReadJsonAttribute('CoolingStates');
        $map[(string) $TargetID] = $State;
        $this->WriteJsonAttribute('CoolingStates', $map);
    }

    private function GetStoredCoolingState(int $TargetID): bool
    {
        $map = $this->ReadJsonAttribute('CoolingStates');
        return (bool) ($map[(string) $TargetID] ?? false);
    }

    private function ReadJsonAttribute(string $Name): array
    {
        $decoded = json_decode($this->ReadAttributeString($Name), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function WriteJsonAttribute(string $Name, array $Data): void
    {
        $this->WriteAttributeString($Name, json_encode($Data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    private function SyncAllHeatingDisplayFromLCN(): void
    {
        if ($this->GetMode() !== self::MODE_HEATING) {
            return;
        }

        foreach ($this->GetRooms() as $room) {
            if (!$room['Enabled'] || !$this->ValidateRoom($room, false)) {
                continue;
            }
            $actual = $this->ReadTarget($room['TargetVariable']);
            if ($actual !== null) {
                $this->SetValueIfChanged($this->HeatIdent($room['TargetVariable']), $actual);
                $this->StoreLastHeatingTarget($room['TargetVariable'], $actual);
            }
        }
    }

    private function DetachMessagesAndReferences(): void
    {
        foreach ($this->ReadJsonAttribute('RegisteredTargetMessages') as $id) {
            $id = (int) $id;
            if ($id > 0) {
                try {
                    $this->UnregisterMessage($id, self::MSG_VARIABLE_UPDATE);
                } catch (Throwable) {
                }
            }
        }
        $this->WriteAttributeString('RegisteredTargetMessages', '[]');

        foreach ($this->ReadJsonAttribute('RegisteredReferences') as $id) {
            $id = (int) $id;
            if ($id > 0) {
                try {
                    $this->UnregisterReference($id);
                } catch (Throwable) {
                }
            }
        }
        $this->WriteAttributeString('RegisteredReferences', '[]');
    }

    private function AppendRegisteredTarget(int $ID): void
    {
        $ids = $this->ReadJsonAttribute('RegisteredTargetMessages');
        if (!in_array($ID, $ids, true)) {
            $ids[] = $ID;
            $this->WriteAttributeString('RegisteredTargetMessages', json_encode($ids) ?: '[]');
        }
    }

    private function RegisterReferenceSafe(int $ID): void
    {
        if ($ID <= 0 || (!IPS_InstanceExists($ID) && !IPS_VariableExists($ID))) {
            return;
        }
        $this->RegisterReference($ID);
        $ids = $this->ReadJsonAttribute('RegisteredReferences');
        if (!in_array($ID, $ids, true)) {
            $ids[] = $ID;
            $this->WriteAttributeString('RegisteredReferences', json_encode($ids) ?: '[]');
        }
    }
}
