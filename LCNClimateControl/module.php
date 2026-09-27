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
    private const LCN_VALUE_MODULE_ID = '{0102BDC9-3B85-4A11-968D-7D314DA07C06}';

    // Instanzübergreifende Sendesperre für mehrere LCN-Klima-Instanzen.
    // Andere Module können denselben Namen übernehmen, um denselben PCHK-Pfad
    // ebenfalls kooperativ zu serialisieren.
    private const GLOBAL_LCN_SEND_SEMAPHORE = 'LCN_BUS_SEND_GLOBAL';

    private const SEND_RETRY_MAX = 2;
    private const SEND_RETRY_DELAY_MS = 750;

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

        // OperatingMode dient nur der verlustfreien Migration aus der fehlerhaften
        // 0.2.0-Vorschau, in der die sichtbare Betriebsart-Variable entfernt wurde.
        $this->RegisterAttributeInteger('OperatingMode', self::MODE_HEATING);
        $this->RegisterAttributeString('LastHeatingTargets', '{}');
        $this->RegisterAttributeString('CoolingStates', '{}');
        $this->RegisterAttributeString('RegisteredTargetMessages', '[]');
        $this->RegisterAttributeString('RegisteredReferences', '[]');
        $this->RegisterAttributeString('LastError', '');

        $this->RegisterTimer('Worker', 0, 'LCNC_Worker($_IPS[\'TARGET\']);');

        $this->EnsureProfiles();

        // Offizieller HTML-SDK-Kacheltyp. Typ 1 ist derselbe Transportweg,
        // der in der produktiven LCN-Jalousie-Kachel verwendet wird.
        $this->SetVisualizationType(1);

        $this->RegisterVariableInteger('Mode', 'Betriebsart', self::PROFILE_MODE, 10);
        $this->EnableAction('Mode');

    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $applyLock = 'LCNC_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($applyLock, 5000)) {
            $this->SetStatus(self::STATUS_RUNTIME_ERROR);
            $this->SendDebug('ApplyChanges', 'Laufende Steuerung konnte nicht innerhalb von 5 s exklusiv angehalten werden.', 0);
            return;
        }

        try {
            $interruptedCoolingJobs = [];
            if ($this->GetMode() === self::MODE_COOLING) {
                $existingJobs = [];
                $existingCurrent = $this->GetCurrentJob();
                if (is_array($existingCurrent)) {
                    $existingJobs[] = $existingCurrent;
                }
                foreach ($this->GetQueue() as $existingQueued) {
                    if (is_array($existingQueued)) {
                        $existingJobs[] = $existingQueued;
                    }
                }

                foreach ($existingJobs as $existingJob) {
                    $type = (string) ($existingJob['RetargetType'] ?? $existingJob['Type'] ?? '');
                    if (in_array($type, [self::JOB_COOL_UP, self::JOB_COOL_DOWN], true)) {
                        $interruptedCoolingJobs[(int) ($existingJob['TargetVariable'] ?? 0)] =
                            (string) ($existingJob['Name'] ?? 'Raum');
                    }
                }
            }

            $this->EnsureProfiles();
        $this->SetVisualizationType(1);
        $this->SetTimerInterval('Worker', 0);
        $this->SetBuffer('Queue', '[]');
        $this->SetBuffer('CurrentJob', '');
        $this->SetBuffer('RuntimeRooms', '[]');
        $this->SetBuffer('PendingMode', '');
        $this->SetBuffer('RoomErrors', '{}');
        $this->SetBuffer('ConfigurationValid', '1');

        // Laufzeitfehler aus älteren Versionen dürfen nach einem erfolgreichen
        // ApplyChanges nicht dauerhaft hängen bleiben. Ab 0.2.9 werden
        // Raumfehler bewusst nur flüchtig geführt und bei Erfolg automatisch
        // wieder aufgehoben.
        $this->WriteAttributeString('LastError', '');

        // 0.2.0 hatte die Bedienobjekte entfernt. Hier werden sie absichtlich
        // wieder als Diagnose-/Fallbackobjekte angelegt. Die kompakte HTML-Kachel
        // bleibt die eigentliche Visualisierung.
        $existingModeID = $this->FindOwnObjectByIdent('Mode');
        if ($existingModeID > 0 && IPS_VariableExists($existingModeID)) {
            try {
                $existingMode = (int) GetValue($existingModeID);
                if (in_array($existingMode, [self::MODE_HEATING, self::MODE_COOLING], true)) {
                    $this->WriteAttributeInteger('OperatingMode', $existingMode);
                }
            } catch (Throwable) {
            }
        }

        $this->MaintainVariable('Mode', 'Betriebsart', VARIABLETYPE_INTEGER, self::PROFILE_MODE, 10, true);
        $this->EnableAction('Mode');
        $this->SetValueIfChanged('Mode', $this->ReadAttributeInteger('OperatingMode'));

        $this->DetachMessagesAndReferences();

        $rooms = $this->GetRooms();
        $runtimeRooms = [];
        $valid = true;
        $position = 100;
        $seenTargets = [];
        $seenRoutes = [];

        foreach ($rooms as $room) {
            if (!$room['Enabled']) {
                continue;
            }

            $targetKey = (string) $room['TargetVariable'];
            $routeKey = implode('|', [
                (string) $room['SendModule'],
                (string) $room['Table'],
                (string) $room['UpKey'],
                (string) $room['DownKey']
            ]);

            if ($room['TargetVariable'] > 0 && isset($seenTargets[$targetKey])) {
                $valid = false;
                $this->SendDebug(
                    'Config',
                    sprintf('%s: S1Target #%d ist bereits dem Raum %s zugeordnet.', $room['Name'], $room['TargetVariable'], $seenTargets[$targetKey]),
                    0
                );
                continue;
            }

            if ($room['SendModule'] > 0 && isset($seenRoutes[$routeKey])) {
                $valid = false;
                $this->SendDebug(
                    'Config',
                    sprintf('%s: dieselbe LCN-Sendemodul-/TS-Route wird bereits von %s verwendet.', $room['Name'], $seenRoutes[$routeKey]),
                    0
                );
                continue;
            }

            if (!$this->ValidateRoom($room, true)) {
                $valid = false;
                continue;
            }

            $seenTargets[$targetKey] = $room['Name'];
            $seenRoutes[$routeKey] = $room['Name'];
            $runtimeRooms[] = $room;

            $targetID = $room['TargetVariable'];
            $tempID = $room['TemperatureVariable'];
            $sendModule = $room['SendModule'];

            $this->RegisterReferenceSafe($sendModule);
            $this->RegisterReferenceSafe($targetID);
            if ($tempID > 0) {
                $this->RegisterReferenceSafe($tempID);
            }

            $this->RegisterMessage($targetID, self::MSG_VARIABLE_UPDATE);
            $this->AppendRegisteredMessage($targetID);

            if ($tempID > 0) {
                $this->RegisterMessage($tempID, self::MSG_VARIABLE_UPDATE);
                $this->AppendRegisteredMessage($tempID);
            }

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

        $this->SetRuntimeRooms($runtimeRooms);
        $this->SetBuffer('ConfigurationValid', $valid ? '1' : '0');

        $runtimeTargets = [];
        foreach ($runtimeRooms as $runtimeRoom) {
            $runtimeTargets[(int) $runtimeRoom['TargetVariable']] = true;
        }
        foreach ($interruptedCoolingJobs as $interruptedTarget => $interruptedName) {
            if ($interruptedTarget > 0 && isset($runtimeTargets[$interruptedTarget])) {
                $this->SetRoomError(
                    $interruptedTarget,
                    $interruptedName . ': Kühlfahrt durch Übernehmen/Update unterbrochen; Endlage nicht bestätigt.'
                );
            }
        }

        $this->CleanupStaleRoomObjects($rooms);
        $this->ApplyModeVisibility();

        $this->RefreshRuntimeStatus();
        $this->PushVisualizationState();
        } finally {
            IPS_SemaphoreLeave($applyLock);
        }

    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message !== self::MSG_VARIABLE_UPDATE) {
            return;
        }

        if (!IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 2000)) {
            $this->SendDebug(
                'MessageSink',
                'Rückmeldung konnte wegen einer länger laufenden exklusiven Modulaktion nicht sofort verarbeitet werden.',
                0
            );
            return;
        }

        $targetRoom = null;
        $temperatureRoom = null;

        try {
            $room = $this->FindRoomByTarget($SenderID);
            if ($room !== null) {
                // Niemals $Data interpretieren: dessen Aufbau ist je nach
                // Symcon-Nachrichtentyp nicht verbindlich dokumentiert.
                $value = $this->ReadTarget($SenderID);
                if ($value !== null) {
                    $jobOwnsTarget = $this->HasJobForTarget($SenderID);

                    if ($this->GetMode() === self::MODE_HEATING && !$jobOwnsTarget) {
                        $this->SetValueIfChanged($this->HeatIdent($SenderID), $value);
                        $this->StoreLastHeatingTarget($SenderID, $value);
                    }
                }

                $targetRoom = $room;
            } else {
                $temperatureRoom = $this->FindRoomByTemperature($SenderID);
            }
        } finally {
            IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
        }

        // HTML-SDK-Nachrichten werden außerhalb des kritischen Abschnitts
        // verschickt, damit die Queue-Sperre möglichst kurz gehalten wird.
        if ($targetRoom !== null) {
            $this->PushVisualizationRow($targetRoom);
        } elseif ($temperatureRoom !== null) {
            $this->PushVisualizationTemperature($temperatureRoom);
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if (!IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 2000)) {
            throw new RuntimeException('LCN-Klimasteuerung ist kurzzeitig beschäftigt. Bitte erneut versuchen.');
        }

        try {
            if ($this->GetBuffer('ConfigurationValid') === '0') {
                throw new RuntimeException(
                    'Konfiguration fehlerhaft. Aus Sicherheitsgründen werden keine LCN-Befehle gesendet, bis alle aktiven Räume korrekt zugeordnet sind.'
                );
            }

            if (IPS_GetKernelRunlevel() !== KR_READY) {
                throw new RuntimeException('IP-Symcon ist noch nicht vollständig betriebsbereit.');
            }

            if ($Ident === 'Mode') {
                $this->RequestMode((int) $Value);
                return;
            }

            foreach ($this->GetRuntimeRooms() as $room) {
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
        } finally {
            IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
        }
    }

    public function Worker(): void
    {
        if (!IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 1000)) {
            return;
        }

        try {
            try {
                $job = $this->GetCurrentJob();

                if (!is_array($job)) {
                    $job = $this->StartNextJob();

                    if (!is_array($job)) {
                        $pending = $this->GetPendingMode();
                        if ($pending !== null) {
                            $this->ApplyModeChange($pending);
                            return;
                        }

                        $this->SetTimerInterval('Worker', 0);
                        $this->RefreshRuntimeStatus();
                        $this->PushVisualizationMeta();
                        return;
                    }
                }

                $this->ProcessJob($job);
            } catch (Throwable $e) {
                $job = $this->GetCurrentJob();
                $message = 'Interner Worker-Fehler: ' . $e->getMessage();
                $this->SendDebug('Worker-Exception', $message, 0);

                if (is_array($job)) {
                    $targetID = (int) ($job['TargetVariable'] ?? 0);
                    $name = (string) ($job['Name'] ?? 'Raum');
                    $this->SetRoomError($targetID, $name . ': ' . $message);
                    $this->SetBuffer('CurrentJob', '');
                    if ($targetID > 0) {
                        $this->PushVisualizationRowByTarget($targetID);
                    }
                } else {
                    $this->WriteAttributeString('LastError', $message);
                }

                // Nach einem unerwarteten internen Fehler keinen automatischen
                // Betriebsartwechsel fortsetzen. Andere bereits wartende Räume
                // dürfen weiterlaufen.
                $this->ClearPendingMode();
                if ($this->GetQueue() === []) {
                    $this->SetTimerInterval('Worker', 0);
                }
                $this->RefreshRuntimeStatus();
                $this->PushVisualizationMeta();
            }
        } finally {
            IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
        }
    }

    public function Abort(): void
    {
        if (IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 1000)) {
            try {
                $affectedCooling = [];
                if ($this->GetMode() === self::MODE_COOLING) {
                    $jobs = [];
                    $current = $this->GetCurrentJob();
                    if (is_array($current)) {
                        $jobs[] = $current;
                    }
                    foreach ($this->GetQueue() as $queued) {
                        if (is_array($queued)) {
                            $jobs[] = $queued;
                        }
                    }

                    foreach ($jobs as $job) {
                        $type = (string) ($job['RetargetType'] ?? $job['Type'] ?? '');
                        if (in_array($type, [self::JOB_COOL_UP, self::JOB_COOL_DOWN], true)) {
                            $affectedCooling[(int) ($job['TargetVariable'] ?? 0)] =
                                (string) ($job['Name'] ?? 'Raum');
                        }
                    }
                }

                $this->SetBuffer('Queue', '[]');
                $this->SetBuffer('CurrentJob', '');
                $this->ClearPendingMode();
                $this->SetTimerInterval('Worker', 0);
                $this->SyncAllHeatingDisplayFromLCN();

                foreach ($affectedCooling as $targetID => $name) {
                    if ($targetID > 0) {
                        $this->SetRoomError(
                            $targetID,
                            $name . ': Kühlfahrt manuell abgebrochen; Endlage nicht bestätigt.'
                        );
                    }
                }

                $this->RefreshRuntimeStatus();
                $this->SendDebug(
                    'Abort',
                    'Laufender Symcon-Auftrag wurde abgebrochen. LCN/GT8 bleiben unverändert bedienbar.',
                    0
                );
                $this->PushVisualizationState();
            } finally {
                IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
            }
        }
    }

    public function ClearError(): void
    {
        if (!IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 1000)) {
            return;
        }

        try {
            $this->WriteAttributeString('LastError', '');
            $this->SetBuffer('RoomErrors', '{}');

            $this->RefreshRuntimeStatus();
            $this->PushVisualizationState();
        } finally {
            IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
        }
    }

    private function RequestMode(int $Mode): void
    {
        if (!in_array($Mode, [self::MODE_HEATING, self::MODE_COOLING], true)) {
            throw new InvalidArgumentException('Ungültige Betriebsart.');
        }

        $currentMode = $this->GetMode();
        $pendingMode = $this->GetPendingMode();

        if ($Mode === $currentMode) {
            if ($pendingMode !== null) {
                $this->ClearPendingMode();
                $this->PushVisualizationMeta();
            }
            return;
        }

        if ($this->IsBusy()) {
            // Bereits gesendete Schritte laufen noch ihre Mindestwartezeit ab.
            // Neue Schritte des alten Modus werden nicht mehr gestartet.
            $this->SetPendingMode($Mode);
            $this->PushVisualizationMeta();
            return;
        }

        $this->ApplyModeChange($Mode);
    }

    private function ApplyModeChange(int $Mode): void
    {
        if ($Mode === $this->GetMode()) {
            $this->ClearPendingMode();
            $this->PushVisualizationMeta();
            return;
        }

        $rooms = $this->GetRuntimeRooms();
        $this->ClearPendingMode();
        $this->SetQueue([]);

        if ($Mode === self::MODE_COOLING) {
            // Beim Wechsel in die Kühlung zählt der zuletzt gewünschte Heizwert.
            // Während einer gerade laufenden Heizfahrt enthält die sichtbare
            // Heat-Variable bereits das Benutzerziel und nicht den Zwischenwert.
            foreach ($rooms as $room) {
                $targetID = $room['TargetVariable'];
                $preserve = $this->ReadOwnFloat($this->HeatIdent($targetID));
                if ($preserve === null) {
                    $preserve = $this->ReadTarget($targetID);
                }
                if ($preserve !== null) {
                    $this->StoreLastHeatingTarget($targetID, $preserve);
                }
            }

            $this->SetValue('Mode', self::MODE_COOLING);
            $this->WriteAttributeInteger('OperatingMode', self::MODE_COOLING);
            $this->ApplyModeVisibility();

            foreach ($rooms as $room) {
                $state = $this->GetStoredCoolingState($room['TargetVariable']);
                $this->EnqueueCoolingJob($room, $state, $state);
            }
        } else {
            $this->SetValue('Mode', self::MODE_HEATING);
            $this->WriteAttributeInteger('OperatingMode', self::MODE_HEATING);
            $this->ApplyModeVisibility();

            foreach ($rooms as $room) {
                $restore = $this->GetLastHeatingTarget($room['TargetVariable']);
                if ($restore === null) {
                    continue;
                }
                $this->SetValueIfChanged($this->HeatIdent($room['TargetVariable']), $restore);
                $this->EnqueueHeatJob($room, $restore);
            }
        }

        $this->StartWorkerIfNeeded();
        $this->PushVisualizationState();
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
        $this->ClearRoomError($targetID);
        $this->RefreshRuntimeStatus();
        $this->SetValueIfChanged($this->HeatIdent($targetID), (float) $rounded);
        $this->RetargetOrEnqueueHeatingJob($Room, (float) $rounded);
        $this->StartWorkerIfNeeded();
        $this->PushVisualizationRow($Room);
        $this->PushVisualizationMeta();
    }

    private function RequestCoolingState(array $Room, bool $State): void
    {
        if ($this->GetMode() !== self::MODE_COOLING) {
            throw new RuntimeException('Kühlung ist nur im Kühlbetrieb bedienbar.');
        }

        $targetID = $Room['TargetVariable'];
        $this->ClearRoomError($targetID);
        $this->RefreshRuntimeStatus();
        $previous = $this->GetStoredCoolingState($targetID);

        // Optimistische Bedienanzeige; bei Fehler wird auf previous zurückgestellt.
        $this->SetValueIfChanged($this->CoolIdent($targetID), $State);
        $this->RetargetOrEnqueueCoolingJob($Room, $State, $previous);
        $this->StartWorkerIfNeeded();
        $this->PushVisualizationRow($Room);
        $this->PushVisualizationMeta();
    }

    private function ProcessJob(array $Job): void
    {
        $targetID = (int) $Job['TargetVariable'];
        $sendModule = (int) ($Job['SendModule'] ?? 0);

        $feedbackRetryNotBefore = (int) ($Job['FeedbackRetryNotBeforeMs'] ?? 0);
        if ($feedbackRetryNotBefore > $this->NowMs()) {
            $this->YieldCurrentJob($Job);
            return;
        }

        if (!$this->IsOperationalTargetVariable($targetID, $sendModule)) {
            $feedbackFailures = (int) ($Job['FeedbackFailures'] ?? 0) + 1;

            if ($feedbackFailures <= self::SEND_RETRY_MAX) {
                $Job['FeedbackFailures'] = $feedbackFailures;
                $Job['FeedbackRetryNotBeforeMs'] = $this->NowMs() + self::SEND_RETRY_DELAY_MS;
                $this->SendDebug(
                    'Feedback-Retry',
                    sprintf(
                        '%s: S1Target-Rückmeldeinstanz nicht betriebsbereit; Wiederholung %d/%d.',
                        $Job['Name'],
                        $feedbackFailures,
                        self::SEND_RETRY_MAX
                    ),
                    0
                );
                $this->YieldCurrentJob($Job);
                return;
            }

            $this->FailCurrentJob(
                $Job,
                'S1Target-Rückmeldung ist nicht betriebsbereit; es wurden keine weiteren LCN-Schritte gesendet.'
            );
            return;
        }

        $Job['FeedbackFailures'] = 0;
        unset($Job['FeedbackRetryNotBeforeMs']);

        $actual = $this->ReadTarget($targetID);
        if ($actual === null) {
            $this->FailCurrentJob($Job, 'S1Target ist nicht lesbar.');
            return;
        }

        $phase = (string) ($Job['Phase'] ?? self::PHASE_SEND);
        $pendingMode = $this->GetPendingMode();

        if ($pendingMode !== null && $phase === self::PHASE_SEND) {
            $this->DiscardCurrentJob($Job);
            return;
        }

        if ($phase === self::PHASE_SEND) {
            $retryNotBefore = (int) ($Job['RetryNotBeforeMs'] ?? 0);
            if ($retryNotBefore > $this->NowMs()) {
                $this->YieldCurrentJob($Job);
                return;
            }

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
                $this->FailCurrentJob(
                    $Job,
                    'Sicherheitsgrenze von ' . $this->GetMaxSteps() . ' Tastendrücken erreicht.'
                );
                return;
            }

            if (!$this->SendShortKey($Job, $direction > 0)) {
                $sendFailures = (int) ($Job['SendFailures'] ?? 0) + 1;

                if ($sendFailures <= self::SEND_RETRY_MAX) {
                    $Job['SendFailures'] = $sendFailures;
                    $Job['RetryNotBeforeMs'] = $this->NowMs() + self::SEND_RETRY_DELAY_MS;
                    $this->SendDebug(
                        'TS-Retry',
                        sprintf(
                            '%s: LCN_SendCommand nicht angenommen; Wiederholung %d/%d nach %d ms.',
                            $Job['Name'],
                            $sendFailures,
                            self::SEND_RETRY_MAX,
                            self::SEND_RETRY_DELAY_MS
                        ),
                        0
                    );
                    $this->YieldCurrentJob($Job);
                    return;
                }

                $this->FailCurrentJob(
                    $Job,
                    'LCN-KURZ-Tastenbefehl wurde auch nach ' . self::SEND_RETRY_MAX . ' Wiederholungen nicht angenommen.'
                );
                return;
            }

            $Job['SendFailures'] = 0;
            unset($Job['RetryNotBeforeMs']);

            $Job['Before'] = $actual;
            $Job['Direction'] = $direction;
            $Job['Steps'] = $steps + 1;
            $Job['Phase'] = self::PHASE_WAIT;
            $Job['SentAtMs'] = $this->NowMs();

            // Round-robin: Während dieser Raum wartet, darf der nächste Raum
            // einen Schritt senden.
            $this->YieldCurrentJob($Job);
            return;
        }

        $elapsed = $this->NowMs() - (int) ($Job['SentAtMs'] ?? 0);
        if ($elapsed < $this->GetStepWaitMs()) {
            $this->YieldCurrentJob($Job);
            return;
        }

        // Bei vorgemerktem Betriebsartwechsel ist der zuletzt gesendete Schritt
        // jetzt lange genug gelaufen und wird nicht fortgesetzt.
        if ($pendingMode !== null) {
            $this->DiscardCurrentJob($Job);
            return;
        }

        $before = (float) ($Job['Before'] ?? $actual);
        $delta = $actual - $before;
        $direction = (int) ($Job['Direction'] ?? 0);

        if (abs($delta) >= 0.40) {
            $expectedDelta = $direction > 0 ? 1.0 : -1.0;

            if (abs($delta - $expectedDelta) > 0.35) {
                $this->FailCurrentJob(
                    $Job,
                    sprintf(
                        'Unerwartete S1Target-Änderung %.1f K erkannt; externer LCN/GT8-Eingriff hat Vorrang.',
                        $delta
                    )
                );
                return;
            }

            $Job['NoChange'] = 0;

            if (isset($Job['RetargetType'])) {
                $Job['Type'] = (string) $Job['RetargetType'];
                unset($Job['RetargetType']);
                $Job['NoChange'] = 0;
                $Job['Phase'] = self::PHASE_SEND;
                $this->SetCurrentJob($Job);
                $this->ProcessJob($Job);
                return;
            }

            if ($Job['Type'] === self::JOB_HEAT) {
                $desired = (float) $Job['Desired'];
                if (abs($actual - $desired) < 0.25) {
                    $this->CompleteCurrentJob($Job, $actual);
                    return;
                }
            }

            $Job['Phase'] = self::PHASE_SEND;
            $this->SetCurrentJob($Job);
            $this->ProcessJob($Job);
            return;
        }

        // Wurde während der Wartephase die Kühlrichtung geändert, gehört
        // diese Nichtänderung noch zum ALTEN Tastendruck und darf niemals als
        // Endlagenbestätigung der neuen Richtung gezählt werden.
        if (isset($Job['RetargetType'])) {
            $Job['Type'] = (string) $Job['RetargetType'];
            unset($Job['RetargetType']);
            $Job['NoChange'] = 0;
            $Job['Phase'] = self::PHASE_SEND;
            $this->SetCurrentJob($Job);
            $this->ProcessJob($Job);
            return;
        }

        // Kein automatisches LCN_RequestRead mehr:
        // Zwei zeitlich getrennte, erfolgreich gesendete Befehle ohne
        // S1Target-Änderung bestätigen die Kühl-Endlage.
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
            $this->ProcessJob($Job);
            return;
        }

        if ($noChange >= $this->GetNoChangeConfirmations()) {
            $this->CompleteCurrentJob($Job, $actual);
            return;
        }

        $Job['Phase'] = self::PHASE_SEND;
        $this->SetCurrentJob($Job);
        $this->ProcessJob($Job);
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
        $this->ClearRoomError($targetID);
        $this->RefreshRuntimeStatus();
        $this->PushVisualizationRowByTarget($targetID);
        $this->PushVisualizationMeta();
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
            // Der zuletzt erfolgreich bestätigte Kühlzustand bleibt die
            // Fallbackanzeige. Eine teilweise angefahrene Zwischenlage wird
            // nicht fälschlich als sicherer Zustand gespeichert.
            $previous = (bool) ($Job['PreviousCoolingState'] ?? $this->GetStoredCoolingState($targetID));
            $this->SetValueIfChanged($this->CoolIdent($targetID), $previous);
        }

        $message = ($Job['Name'] ?? 'Raum') . ': ' . $Reason;
        $this->SetRoomError($targetID, $message);
        $this->SendDebug('ERROR', $message, 0);

        // Ein einzelner Raumfehler darf niemals die komplette Heiz-/Kühlung
        // aller übrigen Räume stoppen. Nur dieser Job wird beendet.
        $this->SetBuffer('CurrentJob', '');
        $this->RefreshRuntimeStatus();

        if ($this->GetQueue() === [] && $this->GetPendingMode() === null) {
            $this->SetTimerInterval('Worker', 0);
        }

        $this->PushVisualizationRowByTarget($targetID);
        $this->PushVisualizationMeta();
    }

    private function HasJobForTarget(int $TargetID): bool
    {
        if ($TargetID <= 0) {
            return false;
        }

        $current = $this->GetCurrentJob();
        if (is_array($current) && (int) ($current['TargetVariable'] ?? 0) === $TargetID) {
            return true;
        }

        foreach ($this->GetQueue() as $queued) {
            if ((int) ($queued['TargetVariable'] ?? 0) === $TargetID) {
                return true;
            }
        }

        return false;
    }

    private function YieldCurrentJob(array $Job): void
    {
        $targetID = (int) ($Job['TargetVariable'] ?? 0);
        $queue = array_values(array_filter(
            $this->GetQueue(),
            fn(array $queued): bool => (int) ($queued['TargetVariable'] ?? 0) !== $targetID
        ));
        $queue[] = $Job;
        $this->SetQueue($queue);
        $this->SetBuffer('CurrentJob', '');
    }

    private function DiscardCurrentJob(array $Job): void
    {
        $targetID = (int) ($Job['TargetVariable'] ?? 0);
        $this->SetBuffer('CurrentJob', '');

        if ($targetID > 0) {
            $this->PushVisualizationRowByTarget($targetID);
        }
        $this->PushVisualizationMeta();
    }

    private function StartNextJob(): ?array
    {
        $queue = $this->GetQueue();
        if ($queue === []) {
            $this->SetBuffer('CurrentJob', '');
            return null;
        }

        // Round-robin-Jobs tragen ihren vollständigen Zustand mit sich.
        // Insbesondere WAIT, SentAtMs, Steps und NoChange dürfen beim erneuten
        // Herausnehmen aus der Queue niemals zurückgesetzt werden.
        $job = array_shift($queue);
        $this->SetQueue($queue);
        $this->SetCurrentJob($job);
        $this->RefreshRuntimeStatus();
        return $job;
    }

    private function RetargetOrEnqueueHeatingJob(array $Room, float $Desired): void
    {
        $targetID = (int) $Room['TargetVariable'];

        $current = $this->GetCurrentJob();
        if (is_array($current)
            && (int) ($current['TargetVariable'] ?? 0) === $targetID
            && ($current['Type'] ?? '') === self::JOB_HEAT) {
            // Phase, Before und SentAtMs bleiben erhalten. Ein bereits
            // gesendeter A7/A8-Schritt wird vollständig ausgewertet; danach
            // wird anhand des neuen Ziels weitergefahren.
            $current['Desired'] = $Desired;
            $current['NoChange'] = 0;
            $this->SetCurrentJob($current);
            return;
        }

        $queue = $this->GetQueue();
        foreach ($queue as $index => $queued) {
            if ((int) ($queued['TargetVariable'] ?? 0) !== $targetID
                || ($queued['Type'] ?? '') !== self::JOB_HEAT) {
                continue;
            }

            $queued['Desired'] = $Desired;
            $queued['NoChange'] = 0;
            $queue[$index] = $queued;
            $this->SetQueue($queue);
            return;
        }

        $this->EnqueueHeatJob($Room, $Desired);
    }

    private function RetargetOrEnqueueCoolingJob(array $Room, bool $State, bool $Previous): void
    {
        $targetID = (int) $Room['TargetVariable'];
        $newType = $State ? self::JOB_COOL_UP : self::JOB_COOL_DOWN;

        $current = $this->GetCurrentJob();
        if (is_array($current) && (int) ($current['TargetVariable'] ?? 0) === $targetID) {
            if (($current['Phase'] ?? self::PHASE_SEND) === self::PHASE_WAIT) {
                // Der bereits gesendete alte Schritt wird noch sauber
                // ausgewertet. Erst danach wird auf den neuen Kühlwunsch
                // gewechselt.
                $current['RetargetType'] = $newType;
            } else {
                $current['Type'] = $newType;
                unset($current['RetargetType']);
                $current['NoChange'] = 0;
            }
            $this->SetCurrentJob($current);
            return;
        }

        $queue = $this->GetQueue();
        foreach ($queue as $index => $queued) {
            if ((int) ($queued['TargetVariable'] ?? 0) !== $targetID) {
                continue;
            }

            if (($queued['Phase'] ?? self::PHASE_SEND) === self::PHASE_WAIT) {
                $queued['RetargetType'] = $newType;
            } else {
                $queued['Type'] = $newType;
                unset($queued['RetargetType']);
                $queued['NoChange'] = 0;
            }
            // Der ursprüngliche stabile Kühlzustand bleibt als Rollbackwert.
            if (!array_key_exists('PreviousCoolingState', $queued)) {
                $queued['PreviousCoolingState'] = $Previous;
            }

            $queue[$index] = $queued;
            $this->SetQueue($queue);
            return;
        }

        $this->EnqueueCoolingJob($Room, $State, $Previous);
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
        $targetID = (int) ($Job['TargetVariable'] ?? 0);

        // Doppelte Jobs desselben Raums dürfen nicht entstehen. Benutzer-
        // Änderungen laufen über die Retarget-Funktionen und erhalten einen
        // eventuell bereits gesendeten WAIT-Schritt.
        if ($this->HasJobForTarget($targetID)) {
            $this->SendDebug('Queue', 'Doppelter Raumauftrag #' . $targetID . ' wurde nicht angelegt.', 0);
            return;
        }

        $Job['Phase'] = self::PHASE_SEND;
        $Job['Steps'] = 0;
        $Job['NoChange'] = 0;
        $Job['SentAtMs'] = 0;

        $queue = $this->GetQueue();
        $queue[] = $Job;
        $this->SetQueue($queue);
    }

    private function StartWorkerIfNeeded(): void
    {
        if ($this->GetCurrentJob() === null) {
            $this->StartNextJob();
        }
        if ($this->GetCurrentJob() !== null || $this->GetQueue() !== []) {
            $this->RefreshRuntimeStatus();
            // Konservativ: global maximal vier TS-Befehle pro Sekunde.
            // Die Mindestwartezeit pro EINZELNEM Raum bleibt zusätzlich
            // vollständig erhalten.
            $this->SetTimerInterval('Worker', 250);
        }
    }

    private function SendShortKey(array $Job, bool $Up): bool
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->SendDebug('TS', 'Kernel ist nicht KR_READY; es wird kein LCN-Befehl gesendet.', 0);
            return false;
        }

        $sendModule = (int) $Job['SendModule'];
        if (!$this->IsOperationalLCNModule($sendModule)) {
            $this->SendDebug('TS', 'LCN-Sendemodul ist nicht betriebsbereit: #' . $sendModule, 0);
            return false;
        }

        if (!IPS_FunctionExists('LCN_SendCommand')) {
            $this->SendDebug('TS', 'LCN_SendCommand ist in dieser Symcon-Laufzeit nicht verfügbar.', 0);
            return false;
        }

        $table = $this->NormalizeTable((string) $Job['Table']);
        $key = (int) ($Up ? $Job['UpKey'] : $Job['DownKey']);
        $data = $this->BuildShortTSData($table, $key);

        // Zusätzlich zur Instanz-Queue wird der sehr kurze eigentliche
        // LCN_SendCommand-Aufruf global zwischen mehreren Klima-Instanzen
        // serialisiert. Es gibt bewusst kein Sleep in dieser Sperre.
        if (!IPS_SemaphoreEnter(self::GLOBAL_LCN_SEND_SEMAPHORE, 2000)) {
            $this->SendDebug('TS', 'Globale LCN-Sendesperre war länger als 2 s belegt.', 0);
            return false;
        }

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
        } finally {
            IPS_SemaphoreLeave(self::GLOBAL_LCN_SEND_SEMAPHORE);
        }
    }

    private function IsOperationalLCNModule(int $InstanceID): bool
    {
        if ($InstanceID <= 0 || !IPS_InstanceExists($InstanceID)) {
            return false;
        }

        try {
            $instance = IPS_GetInstance($InstanceID);
            return (int) ($instance['InstanceStatus'] ?? 0) === self::STATUS_ACTIVE
                && strtoupper((string) ($instance['ModuleInfo']['ModuleID'] ?? '')) === strtoupper(self::LCN_MODULE_MODULE_ID)
                && (int) ($instance['ModuleInfo']['ModuleType'] ?? -1) === 2;
        } catch (Throwable) {
            return false;
        }
    }

    private function IsOperationalTargetVariable(int $TargetVariable, int $ExpectedSendModule): bool
    {
        if ($TargetVariable <= 0 || !IPS_VariableExists($TargetVariable)) {
            return false;
        }

        $parent = IPS_GetParent($TargetVariable);
        if ($parent <= 0 || !IPS_InstanceExists($parent)) {
            return false;
        }

        try {
            $instance = IPS_GetInstance($parent);
            return (int) ($instance['InstanceStatus'] ?? 0) === self::STATUS_ACTIVE
                && strtoupper((string) ($instance['ModuleInfo']['ModuleID'] ?? '')) === strtoupper(self::LCN_VALUE_MODULE_ID)
                && (int) ($instance['ConnectionID'] ?? 0) === $ExpectedSendModule;
        } catch (Throwable) {
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
            $moduleType = (int) ($instance['ModuleInfo']['ModuleType'] ?? -1);
            if ($moduleID !== strtoupper(self::LCN_MODULE_MODULE_ID) || $moduleType !== 2) {
                if ($Debug) {
                    $this->SendDebug('Config', $Room['Name'] . ': gewählte Sendemodul-Instanz ist kein natives LCN Modul/Splitter.', 0);
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

        try {
            $targetParentInfo = IPS_GetInstance($targetParent);
            $targetParentModuleID = strtoupper((string) ($targetParentInfo['ModuleInfo']['ModuleID'] ?? ''));
            if ($targetParentModuleID !== strtoupper(self::LCN_VALUE_MODULE_ID)) {
                if ($Debug) {
                    $this->SendDebug('Config', $Room['Name'] . ': Parent der Zielvariable ist keine native LCN-Value-Instanz.', 0);
                }
                return false;
            }
        } catch (Throwable) {
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
            && $Room['DownKey'] >= 1 && $Room['DownKey'] <= 8
            && $Room['UpKey'] !== $Room['DownKey'];
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


    private function FindRoomByTarget(int $TargetID): ?array
    {
        foreach ($this->GetRuntimeRooms() as $room) {
            if ($room['TargetVariable'] === $TargetID) {
                return $room;
            }
        }
        return null;
    }

    private function FindRoomByTemperature(int $VariableID): ?array
    {
        foreach ($this->GetRuntimeRooms() as $room) {
            if ($room['TemperatureVariable'] === $VariableID) {
                return $room;
            }
        }
        return null;
    }

    private function GetRuntimeRooms(): array
    {
        $decoded = json_decode($this->GetBuffer('RuntimeRooms'), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function SetRuntimeRooms(array $Rooms): void
    {
        $this->SetBuffer(
            'RuntimeRooms',
            json_encode(array_values($Rooms), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]'
        );
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
            $mode = $this->ReadAttributeInteger('OperatingMode');
        }
        return $mode === self::MODE_COOLING ? self::MODE_COOLING : self::MODE_HEATING;
    }

    public function GetVisualizationTile(): string
    {
        $path = __DIR__ . '/module.html';
        $html = file_get_contents($path);
        if ($html === false || $html === '') {
            return '<div style="padding:1rem">Visualisierung konnte nicht geladen werden.</div>';
        }

        $initialState = json_encode(
            $this->BuildVisualizationState(),
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
                | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($initialState === false) {
            $initialState = '{}';
        }

        return str_replace(
            '/*__LCNC_INITIAL_STATE__*/',
            'lcncInitialState = ' . $initialState . ';',
            $html
        );
    }

    private function BuildVisualizationState(): array
    {
        $rows = [];
        foreach ($this->GetRuntimeRooms() as $room) {
            $rows[] = $this->BuildVisualizationRow($room);
        }

        return [
            'type' => 'state',
            'mode' => $this->GetMode(),
            'pendingMode' => $this->GetPendingMode(),
            'busy' => $this->IsBusy(),
            'error' => $this->GetVisualizationErrorSummary(),
            'rows' => $rows
        ];
    }

    private function BuildVisualizationRow(array $Room): array
    {
        $targetID = $Room['TargetVariable'];
        $actualTarget = $this->ReadTarget($targetID);
        $temperature = $this->ReadFloatVariable($Room['TemperatureVariable']);
        $displayTarget = $actualTarget;
        $displayCooling = $this->GetStoredCoolingState($targetID);
        $roomBusy = false;

        $jobsToInspect = [];
        $currentJob = $this->GetCurrentJob();
        if (is_array($currentJob)) {
            $jobsToInspect[] = $currentJob;
        }
        foreach ($this->GetQueue() as $queuedJob) {
            if (is_array($queuedJob)) {
                $jobsToInspect[] = $queuedJob;
            }
        }

        foreach ($jobsToInspect as $job) {
            if ((int) ($job['TargetVariable'] ?? 0) !== $targetID) {
                continue;
            }
            $roomBusy = true;
            if (($job['Type'] ?? '') === self::JOB_HEAT) {
                $displayTarget = (float) ($job['Desired'] ?? $displayTarget ?? 18.0);
            } else {
                $effectiveType = (string) ($job['RetargetType'] ?? $job['Type'] ?? '');
                if ($effectiveType === self::JOB_COOL_UP) {
                    $displayCooling = true;
                } elseif ($effectiveType === self::JOB_COOL_DOWN) {
                    $displayCooling = false;
                }
            }
        }

        return [
            'name' => $Room['Name'],
            'targetId' => $targetID,
            'temperature' => $temperature,
            'target' => $actualTarget,
            'displayTarget' => $displayTarget,
            'cooling' => $displayCooling,
            'heatIdent' => $this->HeatIdent($targetID),
            'coolIdent' => $this->CoolIdent($targetID),
            'busy' => $roomBusy,
            'error' => $this->GetRoomError($targetID)
        ];
    }

    private function PushVisualizationPayload(array $Payload): void
    {
        try {
            $payload = json_encode($Payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                return;
            }
            $this->UpdateVisualizationValue($payload);
        } catch (Throwable $e) {
            $this->SendDebug('Visualization', $e->getMessage(), 0);
        }
    }

    private function PushVisualizationMeta(): void
    {
        $this->PushVisualizationPayload([
            'type' => 'meta',
            'mode' => $this->GetMode(),
            'pendingMode' => $this->GetPendingMode(),
            'busy' => $this->IsBusy(),
            'error' => $this->GetVisualizationErrorSummary()
        ]);
    }

    private function PushVisualizationRow(array $Room): void
    {
        $this->PushVisualizationPayload([
            'type' => 'row',
            'row' => $this->BuildVisualizationRow($Room)
        ]);
    }

    private function PushVisualizationRowByTarget(int $TargetID): void
    {
        $room = $this->FindRoomByTarget($TargetID);
        if ($room !== null) {
            $this->PushVisualizationRow($room);
        }
    }

    private function PushVisualizationTemperature(array $Room): void
    {
        $this->PushVisualizationPayload([
            'type' => 'temperature',
            'targetId' => $Room['TargetVariable'],
            'temperature' => $this->ReadFloatVariable($Room['TemperatureVariable'])
        ]);
    }

    private function PushVisualizationState(): void
    {
        $this->PushVisualizationPayload($this->BuildVisualizationState());
    }


    private function GetPendingMode(): ?int
    {
        $raw = $this->GetBuffer('PendingMode');
        if ($raw === '') {
            return null;
        }
        $mode = (int) $raw;
        return in_array($mode, [self::MODE_HEATING, self::MODE_COOLING], true) ? $mode : null;
    }

    private function SetPendingMode(int $Mode): void
    {
        $this->SetBuffer('PendingMode', (string) $Mode);
    }

    private function ClearPendingMode(): void
    {
        $this->SetBuffer('PendingMode', '');
    }

    private function ReadOwnFloat(string $Ident): ?float
    {
        $id = $this->FindOwnObjectByIdent($Ident);
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return null;
        }

        try {
            return (float) GetValue($id);
        } catch (Throwable) {
            return null;
        }
    }

    private function ReadFloatVariable(int $VariableID): ?float
    {
        if ($VariableID <= 0 || !IPS_VariableExists($VariableID)) {
            return null;
        }

        try {
            return (float) GetValue($VariableID);
        } catch (Throwable) {
            return null;
        }
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
        IPS_SetVariableProfileAssociation(self::PROFILE_COOL, 0, 'Nicht kühlen', '', -1);
        IPS_SetVariableProfileAssociation(self::PROFILE_COOL, 1, 'Kühlen', '', -1);
    }

    private function ApplyModeVisibility(): void
    {
        // Die eigene HTML-SDK-Kachel entscheidet selbst, welche Bedienung
        // sichtbar ist. Objekt-Eigenschaften wie Hidden/Info gehören nach den
        // Symcon-Vorgaben nach der Erstellung in die Hoheit des Benutzers und
        // werden deshalb im laufenden Betrieb nicht mehr umgeschrieben.
    }

    private function EnsureLink(string $Ident, string $Name, int $TargetID, int $Position): void
    {
        $objectID = $this->FindOwnObjectByIdent($Ident);
        $created = false;

        if (!is_int($objectID) || $objectID <= 0 || !IPS_LinkExists($objectID)) {
            $objectID = IPS_CreateLink();
            IPS_SetParent($objectID, $this->InstanceID);
            IPS_SetIdent($objectID, $Ident);
            IPS_SetName($objectID, $Name);
            IPS_SetPosition($objectID, $Position);
            $created = true;
        }

        try {
            $link = IPS_GetLink($objectID);
            if ($created || (int) ($link['TargetID'] ?? 0) !== $TargetID) {
                IPS_SetLinkTargetID($objectID, $TargetID);
            }
        } catch (Throwable) {
            IPS_SetLinkTargetID($objectID, $TargetID);
        }
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
        // 900 ms ist in der realen Anlage als zuverlässig bestätigt.
        // Schnellere Werte werden absichtlich nicht zugelassen.
        return max(900, min(3000, $this->ReadPropertyInteger('StepWaitMs')));
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

    private function GetRoomErrors(): array
    {
        $decoded = json_decode($this->GetBuffer('RoomErrors'), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function SetRoomError(int $TargetID, string $Message): void
    {
        if ($TargetID <= 0) {
            $this->WriteAttributeString('LastError', $Message);
            return;
        }

        $errors = $this->GetRoomErrors();
        $errors[(string) $TargetID] = [
            'message' => $Message,
            'time' => time()
        ];
        $this->SetBuffer(
            'RoomErrors',
            json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
        );
    }

    private function ClearRoomError(int $TargetID): void
    {
        $errors = $this->GetRoomErrors();
        $key = (string) $TargetID;

        if (!array_key_exists($key, $errors)) {
            return;
        }

        unset($errors[$key]);
        $this->SetBuffer(
            'RoomErrors',
            json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
        );
    }

    private function GetRoomError(int $TargetID): string
    {
        $errors = $this->GetRoomErrors();
        $entry = $errors[(string) $TargetID] ?? null;

        if (is_array($entry)) {
            return (string) ($entry['message'] ?? '');
        }
        return is_string($entry) ? $entry : '';
    }

    private function GetVisualizationErrorSummary(): string
    {
        if ($this->GetBuffer('ConfigurationValid') === '0') {
            return 'Konfiguration enthält ungültige oder mehrfach zugeordnete Räume.';
        }

        $fatal = $this->ReadAttributeString('LastError');
        if ($fatal !== '') {
            return $fatal;
        }

        $errors = $this->GetRoomErrors();
        $count = count($errors);
        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            $entry = reset($errors);
            if (is_array($entry) && (string) ($entry['message'] ?? '') !== '') {
                return (string) $entry['message'];
            }
            if (is_string($entry) && $entry !== '') {
                return $entry;
            }
            return '1 Raum konnte nicht vollständig eingestellt werden.';
        }

        return $count . ' Räume konnten nicht vollständig eingestellt werden.';
    }

    private function RefreshRuntimeStatus(): void
    {
        if ($this->GetBuffer('ConfigurationValid') === '0') {
            $this->SetStatus(self::STATUS_CONFIG_ERROR);
        } elseif ($this->ReadAttributeString('LastError') !== '' || $this->GetRoomErrors() !== []) {
            $this->SetStatus(self::STATUS_RUNTIME_ERROR);
        } elseif (count($this->GetRuntimeRooms()) > 0) {
            $this->SetStatus(self::STATUS_ACTIVE);
        } else {
            $this->SetStatus(self::STATUS_INACTIVE);
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

        foreach ($this->GetRuntimeRooms() as $room) {
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

    private function AppendRegisteredMessage(int $ID): void
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
