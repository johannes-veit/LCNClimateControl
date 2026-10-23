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

// A failed-safe partial S1 test must remain blocked and be transparent
// without issuing another lock or any relay instruction.
$previousCommands = $GLOBALS['cCommands'];
$m->attrs['CoolingCommissionSession'] = [
    'phase'=>'failed_safe', 'kind'=>'regulator',
    'name'=>'OG Schlafen 2', 'sender'=>41951, 'target'=>34041,
    'lockID'=>33374, 'lockAck'=>true, 'lockConfirmed'=>false,
    'releaseAck'=>true, 'releaseTries'=>1,
    'message'=>'S1 ist FREI, aber Sperr-/Freigabetest NICHT bestanden.'
];
$diag = $m->CoolingCommissionStatus();
chk(str_contains($diag,'S1-Fehlerdiagnose: Sperrbefehl ANGENOMMEN'), 'diagnose successful command receipt');
chk(str_contains($diag,'Sperre bestaetigt NEIN'), 'diagnose missing physical read proof');
chk(str_contains($diag,'Freigabebefehl ANGENOMMEN'), 'diagnose unlock command receipt');
chk(str_contains($diag,'S1-Testobjekte: LCN-Modul #41951'), 'specific LCN module identified');
chk(str_starts_with($m->CoolingCommissionStartNext(),'ABBRUCH:'), 'failed-safe lockout still active');
chk($GLOBALS['cCommands']===$previousCommands, 'diagnostic emitted no hardware commands');
echo "PASS read-only failed-safe diagnosis, no automatic retries\n";



// One explicit retry is allowed only for the ORIGINAL failed room, in
// unchanged HEATING mode, with current free S1 and relay OFF feedback.
chk(str_starts_with($m->CoolingCommissionRetryFailedRegulator(),'ABBRUCH:'),
    'cannot silently retarget an OG failed-safe to another room');
$m->attrs['CoolingCommissionVerified'] = [];
$m->attrs['CoolingCommissionSession'] = [
    'phase'=>'failed_safe','kind'=>'regulator',
    'name'=>'EG Bad','sender'=>15856,'target'=>21099,
    'lockID'=>19580,'lockAck'=>false,'lockConfirmed'=>false,
    'releaseAck'=>false,'releaseTries'=>1,
];
foreach ([21099,19580,35710] as $vid) {
    $GLOBALS['cVars'][$vid]['VariableUpdated'] = time()-2;
}
$previousCommands=$GLOBALS['cCommands'];
$GLOBALS['cVals'][19580]=true;
chk(str_starts_with($m->CoolingCommissionRetryFailedRegulator(),'ABBRUCH:'),
    'refuse repeat if S1 is locked');
$GLOBALS['cVals'][19580]=false;
$GLOBALS['cVals'][35710]=true;
chk(str_starts_with($m->CoolingCommissionRetryFailedRegulator(),'ABBRUCH:'),
    'refuse repeat if NC relay currently on');
$GLOBALS['cVals'][35710]=false;
chk($GLOBALS['cCommands']===$previousCommands,
    'blocked retries issue zero commands');
chk(str_contains($m->CoolingCommissionRetryFailedRegulator(),'EINMALIGE WIEDERHOLUNG EG Bad'),
    'single correct-room repeat can be staged');
chk(($m->attrs['CoolingCommissionSession']['retryCount']??-1)===1,
    'one retry marker persisted before any command');
chk($GLOBALS['cCommands']===$previousCommands,
    'repeat start never directly sends S1 or relay commands');
$m->CoolingCommissionWorker();
$m->attrs['CoolingCommissionSession']['lockAt']=time()-2;
$m->CoolingCommissionWorker();
$m->CoolingCommissionWorker();
$m->CoolingCommissionWorker();
$m->attrs['CoolingCommissionSession']['releaseAt']=time()-2;
$m->CoolingCommissionWorker();
$m->CoolingCommissionWorker();
chk(($m->attrs['CoolingCommissionSession']['phase']??'')==='passed',
    'approved same-room retry completes two confirmed S1 states');
chk($GLOBALS['cCommands']===array_merge($previousCommands,[[21099,true],[21099,false]]),
    'retry only sends S1 lock then S1 release');
$m->attrs['CoolingCommissionSession']['phase']='failed_safe';
chk(str_starts_with($m->CoolingCommissionRetryFailedRegulator(),'ABBRUCH:'),
    'second retry is blocked even after one successful guarded attempt');
echo "PASS one explicit fail-safe retry, same-room only, zero relays, no second attempt\n";


// Manual plant mode must NOT bypass the independently tested interlocks.
// The IPS mode switch provides the operator's deliberate cooling consent,
// while an optional external Boolean always retains veto authority.
require_once __DIR__.'/../LCNClimateControl/DirectCooling.php';
final class ManualCoolingGuardMock
{
    use LCNDirectCooling;
    public array $flags = [
        'ManualCoolingApproval'=>false,
        'CoolingHardwareInterlockConfirmed'=>false,
        'CoolingHeatingResumeConfirmed'=>false
    ];
    public int $safetyID = 0;
    private function ReadPropertyBoolean(string $name): bool
    {
        return (bool) ($this->flags[$name] ?? false);
    }
    private function ReadPropertyInteger(string $name): int
    {
        return $name === 'CoolingSafetyVariable' ? $this->safetyID : 0;
    }
    private function GetRuntimeRooms(): array { return []; }
    public function safe(): bool { return $this->DirectCoolingSafe(); }
    public function preflight(): array { return $this->DirectCoolingPreflight(); }
}
$guard = new ManualCoolingGuardMock();
chk(!$guard->safe(), 'default fail closed');
chk($guard->preflight()!==[], 'manual and external permission absent');
$guard->flags['ManualCoolingApproval']=true;
chk(!$guard->safe(), 'manual authorization cannot bypass dewpoint hardware');
$guard->flags['CoolingHardwareInterlockConfirmed']=true;
chk(!$guard->safe(), 'manual authorization cannot bypass heater restart signoff');
$guard->flags['CoolingHeatingResumeConfirmed']=true;
chk($guard->safe(), 'manual operator opt-in with both hardware acknowledgements');
chk($guard->preflight()===[], 'external Boolean not required in opt-in manual mode');
$guard->flags['CoolingHardwareInterlockConfirmed']=false;
chk(!$guard->safe(), 'hardware signoff revoked: no cooling');
$guard->flags['CoolingHardwareInterlockConfirmed']=true;
$guard->flags['CoolingHeatingResumeConfirmed']=false;
chk(!$guard->safe(), 'heating resume signoff revoked: no cooling');
$guard->flags['CoolingHeatingResumeConfirmed']=true;
$guard->safetyID=50001;
$GLOBALS['cVars'][50001]=['VariableUpdated'=>time(), 'VariableType'=>VARIABLETYPE_BOOLEAN];
$GLOBALS['cVals'][50001]=false;
chk(!$guard->safe(), 'external Boolean FALSE vetoes manual approval');
$GLOBALS['cVals'][50001]=true;
chk($guard->safe(), 'external Boolean TRUE allows if hardware commissioned');
$guard->flags['ManualCoolingApproval']=false;
chk($guard->safe(), 'prior external Boolean mode continues to work');
$guard->flags['ManualCoolingApproval']=true;
$GLOBALS['cVars'][50001]['VariableType']=2;
chk(!$guard->safe(), 'invalid external variable type cannot be overridden');
chk($guard->preflight()!==[], 'invalid external Boolean is reported');
unset($GLOBALS['cVars'][50001],$GLOBALS['cVals'][50001]);
chk(!$guard->safe(), 'missing configured external Boolean never falls back to manual');
echo "PASS manual Symcon authorization with physical safety + heater resume; optional external veto\n";

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
