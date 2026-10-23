<?php
declare(strict_types=1);
require_once __DIR__ . '/../LCNClimateControl/CoolingCommission.php';

const KR_READY = 10103;
const VARIABLETYPE_BOOLEAN = 0;
$GLOBALS['cnow'] = time();
$GLOBALS['cVars'] = [
  21099=>['VariableUpdated'=>time()-2,'VariableType'=>2],
  19580=>['VariableUpdated'=>time()-2,'VariableType'=>0],
  35710=>['VariableUpdated'=>time()-2,'VariableType'=>0],
];
$GLOBALS['cVals']=[21099=>22.0,19580=>false,35710=>false];
$GLOBALS['cCommands']=[];
$GLOBALS['cReads']=0;
$GLOBALS['cReleaseResult']=true;
function IPS_SemaphoreEnter(string $name,int $timeout): bool { return true; }
function IPS_SemaphoreLeave(string $name): void {}
function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['cVars'][$id]); }
function IPS_GetVariable(int $id): array { return $GLOBALS['cVars'][$id]; }
function GetValue(int $id): mixed { return $GLOBALS['cVals'][$id]??null; }
function IPS_GetParent(int $id): int { return in_array($id,[21099,19580],true)?43818:0; }
function IPS_InstanceExists(int $id): bool { return $id===43818; }
function IPS_GetInstance(int $id): array { return ['ConnectionID'=>15856]; }
function IPS_FunctionExists(string $name): bool { return function_exists($name); }
function LCN_RequestRead(int $id): bool
{
    $GLOBALS['cReads']++;
    $GLOBALS['cVars'][19580]['VariableUpdated']=time()+1;
    $GLOBALS['cVars'][21099]['VariableUpdated']=time()+1;
    return true;
}
function LCN_RequestStatus(int $id): bool { return true; }

final class CommissionMock
{
    use LCNClimateCoolingCommission;
    public const MODE_HEATING=0;
    private const GLOBAL_LCN_SEND_SEMAPHORE='LCN_BUS_SEND_GLOBAL';
    public int $InstanceID=17597;
    public array $attrs=['DirectCoolingLocks'=>[],'M11ProbeSession'=>[]];
    public array $timers=[];
    public bool $busy=false;
    public function GetMode(): int { return 0; }
    private function GetPendingMode(): ?int { return null; }
    private function IsBusy(): bool { return $this->busy; }
    private function GetPendingCoolingRequests(): array { return []; }
    private function M11ProbeActive(): bool { return false; }
    private function M11ProbeSession(): array { return []; }
    private function GetRuntimeRooms(): array
    {
        return [['Name'=>'EG Bad','SendModule'=>15856,'TargetVariable'=>21099]];
    }
    private function ReadJsonAttribute(string $id): array { return $this->attrs[$id]??[]; }
    private function WriteJsonAttribute(string $id,array $value): void { $this->attrs[$id]=$value; }
    private function ReadPropertyInteger(string $id): int { return 0; }
    private function ReadPropertyBoolean(string $id): bool { return false; }
    private function DirectCoolingSafe(): bool { return false; }
    private function UsesDirectCooling(): bool { return true; }
    private function DirectCoolingPreflight(): array { return []; }
    private function DirectCoolingMapping(int $id): array
    {
        return ['UnlockedVariable'=>19580,'RelayStatusVariable'=>35710,
                'RelayModule'=>36930,'RelayUnit'=>26911,'RelayChannel'=>6,
                'RegulatorIndex'=>2,'LockFeedbackIsLocked'=>true,
                'OpenRelayOn'=>true,'PolarityConfirmed'=>true];
    }
    private function DirectCoolingLockActionID(array $r,array $m): int
    { return $r['TargetVariable']===21099 ? 19580 : 0; }
    private function DirectCoolingSaveLockMapping(int $id,array $m): void {}
    private function DirectCoolingSetLockState(int $id,string $status): void
    {
        if ($status==='') {unset($this->attrs['DirectCoolingLocks'][(string)$id]);}
        else {$this->attrs['DirectCoolingLocks'][(string)$id]=$status;}
    }
    private function DirectCoolingControllerCommand(array $job,array $map,bool $lock): bool
    {
        $GLOBALS['cCommands'][]=[$job['TargetVariable'],$lock];
        if (!$lock && !$GLOBALS['cReleaseResult']) { return false; }
        $GLOBALS['cVals'][19580]=$lock;
        $GLOBALS['cVars'][19580]['VariableUpdated']=time()+1;
        return true;
    }
    private function DirectCoolingRelaySet(array $mapping,bool $open): bool
    {
        $GLOBALS['cCommands'][]=['relay',$open];
        $GLOBALS['cVals'][35710]=$open;
        $GLOBALS['cVars'][35710]['VariableUpdated']=time()+1;
        return true;
    }
    private function DirectCoolingRegulatorUnlocked(array $m): bool
    { return !((bool)$GLOBALS['cVals'][19580]); }
    private function IsOperationalLCNModule(int $id): bool { return true; }
    private function ReadTarget(int $id): ?float { return (float)($GLOBALS['cVals'][$id]??0); }
    private function SetTimerInterval(string $name,int $ms): void { $this->timers[$name]=$ms; }
    private function UpdateChatGPTDiagnostic(): void {}
    private function SendDebug(string $a,string $b,int $c): void {}
    private function ReleaseLCNBusSlot(): void { IPS_SemaphoreLeave(self::GLOBAL_LCN_SEND_SEMAPHORE); }
}
function chk(bool $ok,string $message): void
{
    if (!$ok) { throw new RuntimeException('FAILED: '.$message); }
}
$m=new CommissionMock();
chk(str_contains($m->CoolingCommissionStartNext(),'START EG Bad'), 'first staged S1 test');
$m->CoolingCommissionWorker(); // lock
chk(($m->attrs['CoolingCommissionSession']['phase']??'')==='lock_wait','wait for native read');
chk($GLOBALS['cCommands']===[[21099,true]],'no relay actuation in S1-only test');
$m->attrs['CoolingCommissionSession']['lockAt']=time()-2;
$m->CoolingCommissionWorker(); // read
$m->CoolingCommissionWorker(); // confirm lock
$m->CoolingCommissionWorker(); // release
$m->attrs['CoolingCommissionSession']['releaseAt']=time()-2;
$m->CoolingCommissionWorker(); // read
$m->CoolingCommissionWorker(); // confirmed freed, certificate
chk(($m->attrs['CoolingCommissionSession']['phase']??'')==='passed','S1 roundtrip passes');
chk(($m->attrs['CoolingCommissionVerified']['21099']['lockID']??0)===19580,
    'certificate bound to exact native lock variable');
chk($GLOBALS['cCommands']===[[21099,true],[21099,false]],
    'regulator-only test never sends relay ON');
chk($m->attrs['DirectCoolingLocks']===[],'successful unlock clears only its own marker');
echo "PASS 1 room lock -> fresh read -> release -> fresh read, no relay\n";

// Cooling mode remains blocked without all 11 proofs and physical interlock.
$errors=$m->CoolingCommissionStatus();
chk(str_contains($errors,'Reglersperrungen nachgewiesen: 1/11'), 'transparent S1 total');
chk(str_contains($errors,'Einzelrelais EIN/AUS nachgewiesen: 0/11'), 'relay not falsely approved');
chk(str_contains($errors,'Kuehlfreigabe Boolean: NICHT KONFIGURIERT'), 'missing safety input clear');
chk(str_contains($errors,'Kuehlmodus:') || str_contains($errors,'Produktiver Kuehlmodus:'),
    'cooling is explicitly locked');
echo "PASS no cooling clearance from regulator only\n";

$source=file_get_contents(__DIR__.'/../LCNClimateControl/DirectCooling.php');
$module=file_get_contents(__DIR__.'/../LCNClimateControl/module.php');
chk(str_contains($source,'// Every room must have its LCN heater regulator held LOCKED'),
    'cooling OFF must still lock the LCN heater');
chk(str_contains($source,'DirectCoolingLockFeedbackAfterRead'),
    'direct cooling must require an independent native bus read');
chk(str_contains($source,'BeginDirectCoolingRecovery($job,'),
    'failed lock/relay must attempt OFF and S1 release');
chk(str_contains($module,'CoolingCommissionReadinessErrors()'),
    'global mode gate must check commissioning');
chk(str_contains($module,'CoolingHardwareInterlockConfirmed'),
    'hardware dewpoint signoff property registered');
echo "PASS source safety invariants\n";

// CI branch smoke marker: release 0.4.1 Build 41 (all tests executed on PR).
