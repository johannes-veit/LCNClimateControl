<?php
declare(strict_types=1);
require_once __DIR__ . '/../LCNClimateControl/M11LockTest.php';

const KR_READY = 10103;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_FLOAT = 2;

$GLOBALS['probeVars'] = [];
$GLOBALS['probeValues'] = [];
$GLOBALS['probeCommands'] = [];
$GLOBALS['probeConfig'] = [];
$GLOBALS['probeIgnoreLockFeedback'] = false;
$GLOBALS['probeIgnoreReleaseFeedback'] = false;

function IPS_SemaphoreEnter(string $name, int $timeout): bool { return true; }
function IPS_SemaphoreLeave(string $name): void {}
function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_VariableExists(int $id): bool { return array_key_exists($id, $GLOBALS['probeVars']); }
function IPS_GetVariable(int $id): array { return $GLOBALS['probeVars'][$id]; }
function GetValue(int $id): mixed { return $GLOBALS['probeValues'][$id] ?? null; }
function IPS_GetParent(int $id): int { return in_array($id, [34107,46746], true) ? 19003 : 0; }
function IPS_GetObject(int $id): array { return ['ObjectIdent' => $id === 46746 ? 'S1TargetLock' : 'S1Target']; }
function IPS_FunctionExists(string $name): bool { return function_exists($name); }
function IPS_InstanceExists(int $id): bool { return in_array($id, [37617,36930,19003], true); }
function IPS_GetInstance(int $id): array { return ['ConnectionID' => $id === 19003 ? 37617 : 1]; }

function LCN_LockTargetValue(int $sender, int $idx): bool
{
    $GLOBALS['probeCommands'][] = ['lock',$sender,$idx];
    if (!$GLOBALS['probeIgnoreLockFeedback']) {
        $GLOBALS['probeValues'][46746] = true;
        $GLOBALS['probeVars'][46746]['VariableUpdated'] = time();
    }
    return true;
}
function LCN_ReleaseTargetValue(int $sender, int $idx): bool
{
    $GLOBALS['probeCommands'][] = ['release',$sender,$idx];
    if (!$GLOBALS['probeIgnoreReleaseFeedback']) {
        $GLOBALS['probeValues'][46746] = false;
        $GLOBALS['probeVars'][46746]['VariableUpdated'] = time();
        $GLOBALS['probeVars'][34107]['VariableUpdated'] = time();
    }
    return true;
}
function LCN_RequestRead(int $id): bool { return true; }

final class MockM11Climate
{
    use LCNClimateM11LockTest;
    public const MODE_HEATING = 0;
    public const MODE_COOLING = 1;
    private const GLOBAL_LCN_SEND_SEMAPHORE = 'LCN_BUS_SEND_GLOBAL';
    public int $InstanceID = 17597;
    public array $attrs = [];
    public array $timers = [];
    public int $mode = 0;
    public array $errors = [];
    public function GetMode(): int { return $this->mode; }
    private function GetPendingMode(): ?int { return null; }
    private function IsBusy(): bool { return false; }
    private function GetPendingCoolingRequests(): array { return []; }
    private function GetBuffer(string $key): string { return $key === 'ConfigurationValid' ? '1' : ''; }
    private function ReadJsonAttribute(string $name): array { return $this->attrs[$name] ?? []; }
    private function WriteJsonAttribute(string $name, array $data): void { $this->attrs[$name] = $data; }
    private function GetRuntimeRooms(): array
    {
        return [['Name'=>'EG Büro', 'SendModule'=>37617, 'TargetVariable'=>34107]];
    }
    private function IsOperationalLCNModule(int $id): bool { return $id === 37617 || $id === 36930; }
    private function AutoCoolingInstanceConfig(int $id): ?array
    {
        return $GLOBALS['probeConfig'][$id] ?? null;
    }
    private function AutoCoolingReadOnlyDiscovery(array $room): array
    {
        return [
            'RegulatorIndex'=>2, 'RelayChannel'=>1, 'TargetVariable'=>34107,
            'LockFeedbackIsLocked'=>true, 'OpenRelayOn'=>true,
            'RelayModule'=>36930, 'RelayStatusVariable'=>30465,
            'UnlockedVariable'=>46746
        ];
    }
    private function GetStoredCoolingState(int $target): bool { return false; }
    private function ReadTarget(int $target): ?float { return $target === 34107 ? 21.0 : null; }
    private function DirectCoolingSaveLockMapping(int $target, array $mapping): void
    {
        $this->attrs['DirectCoolingLockMappings'][(string)$target] = $mapping;
    }
    private function DirectCoolingSetLockState(int $target, string $state): void
    {
        if ($state === '') {
            unset($this->attrs['DirectCoolingLocks'][(string)$target]);
            unset($this->attrs['DirectCoolingLockMappings'][(string)$target]);
        } else {
            $this->attrs['DirectCoolingLocks'][(string)$target] = $state;
        }
    }
    private function SetTimerInterval(string $name, int $interval): void { $this->timers[$name] = $interval; }
    private function ClearRoomError(int $id): void { unset($this->errors[$id]); }
    private function SetRoomError(int $id, string $message): void { $this->errors[$id] = $message; }
    private function SendDebug(string $tag, string $message, int $format): void {}
    private function PushVisualizationState(): void {}
    private function UpdateChatGPTDiagnostic(): void {}
}

function t(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('TEST FAILED: ' . $message); }
}
function initProbe(): MockM11Climate
{
    $GLOBALS['probeVars'] = [
        34107=>['VariableUpdated'=>time(), 'VariableType'=>VARIABLETYPE_FLOAT],
        46746=>['VariableUpdated'=>time(), 'VariableType'=>VARIABLETYPE_BOOLEAN],
        30465=>['VariableUpdated'=>time(), 'VariableType'=>VARIABLETYPE_BOOLEAN]
    ];
    $GLOBALS['probeValues'] = [34107=>21.0, 46746=>false, 30465=>false];
    $GLOBALS['probeCommands'] = [];
    $GLOBALS['probeConfig'] = [37617=>['Target'=>11],36930=>['Target'=>92]];
    $GLOBALS['probeIgnoreLockFeedback'] = false;
    $GLOBALS['probeIgnoreReleaseFeedback'] = false;
    return new MockM11Climate();
}

// Positive: actual lock feedback followed by release feedback and S1Target.
$m=initProbe();
t(str_contains($m->StartM11RegulatorTest(),'gestartet'),'preflight accepts verified M11 only');
for($i=0;$i<5;$i++){$m->M11ProbeWorker();}
t(($m->attrs['M11ProbeSession']['phase'] ?? '')==='passed','successful full round-trip');
t(!isset($m->attrs['DirectCoolingLocks']['34107']),'confirmed release clears uncertainty');
t($GLOBALS['probeCommands']===[['lock',37617,2],['release',37617,2]],'only M11 S1 commands');
t(($m->timers['M11ProbeTimer']??-1)===0,'timer stops after pass');
echo "PASS M11 lock + release + S1 target confirmed\n";

// Reject incorrect hardware addressing before any bus command.
$m=initProbe();$GLOBALS['probeConfig'][37617]['Target']=12;
t(str_starts_with($m->StartM11RegulatorTest(),'ABBRUCH'),'reject wrong module hardware');
t($GLOBALS['probeCommands']===[],'no physical commands on invalid mapping');
echo "PASS reject other module\n";

// Reject when the room relay is currently energized.
$m=initProbe();$GLOBALS['probeValues'][30465]=true;
t(str_starts_with($m->StartM11RegulatorTest(),'ABBRUCH'),'relay ON fail-closed');
t($GLOBALS['probeCommands']===[],'no lock with energized relay');
echo "PASS reject relay ON\n";

// A lock command without confirmed lock must NEVER be counted as a success.
$m=initProbe();$GLOBALS['probeIgnoreLockFeedback']=true;
$m->StartM11RegulatorTest();$m->M11ProbeWorker();
$m->attrs['M11ProbeSession']['lockSent']=time()-13;
for($i=0;$i<4;$i++){$m->M11ProbeWorker();}
t(($m->attrs['M11ProbeSession']['phase']??'')==='failed_safe','unconfirmed lock is test failure');
t($GLOBALS['probeCommands']===[['lock',37617,2],['release',37617,2]],'release even after missing lock feedback');
echo "PASS unconfirmed lock still releases, not marked passed\n";

// If S1 release is never confirmed, keep uncertain marker & alert.
$m=initProbe();$m->StartM11RegulatorTest();
$m->M11ProbeWorker();$m->M11ProbeWorker();
$GLOBALS['probeIgnoreReleaseFeedback']=true;
for($i=0;$i<3;$i++){
    $m->M11ProbeWorker();
    $m->attrs['M11ProbeSession']['releaseSent']=time()-11;
    $m->M11ProbeWorker();
}
t(($m->attrs['M11ProbeSession']['phase']??'')==='manual_release_needed','max retries give explicit alarm');
t(isset($m->attrs['DirectCoolingLocks']['34107']),'never silently clear uncertain lock');
t(count(array_filter($GLOBALS['probeCommands'],fn($row)=>$row[0]==='release'))===3,
  'release retried exactly three times');
t(str_contains($m->M11ProbeEmergencyRelease(),'eingeplant'),'manual release remains possible');
echo "PASS failed release keeps blocker and permits emergency retry\n";

// While test is active never begin it a second time.
$m=initProbe();$m->StartM11RegulatorTest();
t(str_starts_with($m->StartM11RegulatorTest(),'ABBRUCH'),'duplicate test disallowed');
$m->mode=1;$m->M11ProbeWorker();
t($GLOBALS['probeCommands']===[],'unexpected mode switch sends no lock');
echo "PASS duplicate suppression and changed-mode guard\n";
echo "ALL SIX M11 PROBE TEST CASES PASSED\n";
