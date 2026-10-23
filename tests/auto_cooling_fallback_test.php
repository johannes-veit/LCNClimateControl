<?php
declare(strict_types=1);
const VARIABLETYPE_BOOLEAN = 0;
$GLOBALS['v'] = [100 => true, 101 => true, 102 => false, 777 => 21.0];
$GLOBALS['updated'] = [100 => time(), 101 => time(), 102 => time(), 777 => time()];
$GLOBALS['release_ok'] = true;
function IPS_VariableExists(int $id): bool { return array_key_exists($id, $GLOBALS['v']); }
function IPS_GetVariable(int $id): array { return ['VariableType' => VARIABLETYPE_BOOLEAN, 'VariableUpdated' => $GLOBALS['updated'][$id] ?? 0]; }
function GetValue(int $id): bool { return (bool) ($GLOBALS['v'][$id] ?? false); }
function IPS_FunctionExists(string $name): bool { return in_array($name,['LCN_LockTargetValue','LCN_ReleaseTargetValue','LCN_SetRelay'],true); }
function IPS_SemaphoreEnter(string $name, int $wait): bool { return true; }
function IPS_SemaphoreLeave(string $name): void {}
function LCN_ReleaseTargetValue(int $id, int $index): bool { return (bool) $GLOBALS['release_ok']; }
function LCN_LockTargetValue(int $id, int $index): bool { return true; }
function LCN_SetRelay(int $id, string $mask): bool { return true; }
require __DIR__ . '/../LCNClimateControl/DirectCooling.php';
final class AutoProbe {
    use LCNDirectCooling;
    private const GLOBAL_LCN_SEND_SEMAPHORE = 'test';
    public const MODE_COOLING = 1;
    public const JOB_COOL_UP = 'cool_up';
    public const JOB_COOL_DOWN = 'cool_down';
    public array $properties = ['DirectCoolingEnabled'=>true,'CoolingSafetyVariable'=>100,'CoolingRelays'=>'[]'];
    public array $attrs = ['DirectCoolingLocks'=>[], 'CoolingFallbackReasons'=>[]];
    public array $jobs = [];
    public array $buffers = [];
    public array $errors = [];
    public array $coolingStored = [];
    public array $room = ['Name'=>'EG Buero','SendModule'=>11,'TargetVariable'=>777,'Table'=>'A','UpKey'=>7,'DownKey'=>8];
    public int $now = 10000;
    private function ReadPropertyBoolean(string $name): bool { return (bool) ($this->properties[$name] ?? false); }
    private function ReadPropertyInteger(string $name): int { return (int) ($this->properties[$name] ?? 0); }
    private function ReadPropertyString(string $name): string { return (string) ($this->properties[$name] ?? ''); }
    private function ReadJsonAttribute(string $name): array { return $this->attrs[$name] ?? []; }
    private function WriteJsonAttribute(string $name, array $value): void { $this->attrs[$name]=$value; }
    private function IsOperationalLCNModule(int $id): bool { return in_array($id, [11,21,31,41,51,61,111,121,131,141,151,92,192], true); }
    // Other mock suites validate real native S1 action ownership.
    private function DirectCoolingLockActionID(array $room,array $mapping): int
    { return (int) ($mapping['UnlockedVariable'] ?? 0); }
    private function AppendJob(array $job): void { $this->jobs[]=$job; }
    private function YieldCurrentJob(array $job): void { $this->jobs[]=$job; }
    public array $rooms = [];
    private function GetRuntimeRooms(): array { return $this->rooms !== [] ? $this->rooms : [$this->room]; }
    private function GetMode(): int { return self::MODE_COOLING; }
    private function NowMs(): int { return $this->now; }
    private function ReadTarget(int $id): ?float { return $id===777 ? 21.0 : null; }
    private function SetBuffer(string $name, string $value): void { $this->buffers[$name]=$value; }
    private function SetRoomError(int $target, string $message): void { $this->errors[$target]=$message; }
    private function ClearRoomError(int $target): void { unset($this->errors[$target]); }
    private function FindRoomByTarget(int $target): ?array { return $target===777 ? $this->room : null; }
    private function SetValueIfChanged(string $id, mixed $value): void {}
    private function CoolIdent(int $id): string { return 'C'.$id; }
    private function RefreshRuntimeStatus(): void {}
    private function StartWorkerIfNeeded(): void {}
    private function PushVisualizationRowByTarget(int $id): void {}
    private function PushVisualizationMeta(): void {}
    private function SendDebug(string $name, string $text, int $format): void {}
    private function FailCurrentJob(array $job, string $reason): void { $this->errors[(int)($job['TargetVariable']??0)]=$reason; }
    public function choose(bool $on, bool $previous=false): void { $this->EnqueueCoolingAutomatically($this->room,$on,$previous); }
    public function chooseRoom(array $room, bool $on): void { $this->EnqueueCoolingAutomatically($room,$on,false); }
    public function summary(): string { return $this->DirectCoolingFallbackSummary(); }
    public function recover(array $job): void { $this->HandleDirectCoolingRecovery($job); }
    public function processDirect(array $job): void { $this->HandleDirectCoolingJob($job); }
    public function release(float $target): void { $this->DirectCoolingEnqueueRelease($this->room, $target); }
    private function EnqueueHeatJob(array $room, float $target): void { $this->jobs[]=['Type'=>'heat','Desired'=>$target]; }
    private function StoreCoolingState(int $target, bool $state): void { $this->coolingStored[$target]=$state; }
    private function ApplyPendingCoolingRequest(int $target):void{}
    private function ConsumePendingCoolingRequest(int $target):?bool{return null;}
    public function lockState(int $target, string $state): void { $this->DirectCoolingSetLockState($target,$state); }
}
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function config(AutoProbe $p): void {
    $p->properties['CoolingRelays'] = json_encode([[
        'TargetVariable'=>777,'RelayModule'=>92,'RelayChannel'=>1,
        'RegulatorIndex'=>2,'UnlockedVariable'=>101,'RelayStatusVariable'=>102,
        'OpenRelayOn'=>true,'PolarityConfirmed'=>true
    ]]);
}
// Architecture: HEIZEN is unaffected; KUEHLEN has NO automatic
// A7/A8 fallback. Every active cooling command requires an explicitly
// commissioned native S1 action, relay and an independent coolant gate.
$a=new AutoProbe();
$a->choose(true);
check($a->jobs===[],'No legacy TS when a mapping is missing');
check(str_contains($a->summary(),'gestoppt'),
    'Missing native mapping is reported as blocked');
echo "PASS unmapped room never launches old TS cooling\n";

// A software variable TRUE is not a physical dewpoint interlock.
$b=new AutoProbe();
config($b);
$b->choose(true);
check($b->jobs===[],'Software signal cannot bypass missing physical interlock');
check(str_contains($b->summary(),'gestoppt'),'Missing physical interlock blocks cooling');
echo "PASS hardware dewpoint interlock missing blocks direct/legacy\n";

// Even a signed-off interlock cannot bypass a FALSE safety signal.
$c=new AutoProbe();
config($c);
$c->properties['CoolingHardwareInterlockConfirmed']=true;
$GLOBALS['v'][100]=false;
$c->choose(true);
check($c->jobs===[],'External Boolean FALSE must suppress cooling');
check(str_contains($c->summary(),'gestoppt'),'Safety veto is visible');
$GLOBALS['v'][100]=true;
echo "PASS software coolant safety veto\n";

// A cooling OFF request with uncertain regulator status can only
// queue the one-room native relay close, never an unlock or TS job.
$d=new AutoProbe();
config($d);
$d->lockState(777,'uncertain');
$d->choose(false);
check(count($d->jobs)===1
    && $d->jobs[0]['Type']==='direct_emergency_close',
    'Uncertain lock allows only NC relay OFF');
$d->jobs=[];
$d->choose(true);
check($d->jobs===[],'Uncertain lock forbids valve opening');
echo "PASS uncertain lock only permits OFF\n";

// A stale or unverified mapping must never fall back to TS.
$e=new AutoProbe();
config($e);
$settings=json_decode($e->properties['CoolingRelays'],true);
unset($settings[0]['PolarityConfirmed']);
$e->properties['CoolingRelays']=json_encode($settings);
$e->properties['CoolingHardwareInterlockConfirmed']=true;
$e->choose(true);
check($e->jobs===[],'Missing relay polarity blocks cooling');
echo "PASS missing polarity does not trigger legacy A7/A8\n";

// Source-level guard: the old helper may remain for migration, but
// EnqueueCoolingAutomatically itself must not call it.
$source=file_get_contents(__DIR__.'/../LCNClimateControl/DirectCooling.php');
$start=strpos($source,'private function EnqueueCoolingAutomatically(');
$end=strpos($source,'private function BeginDirectCoolingRecovery(',$start);
check($start!==false && $end!==false,'Cooling control boundaries found');
$function=substr($source,$start,$end-$start);
check(!str_contains($function,'$this->EnqueueLegacyCoolingJob('),
    'Automatic cooling selector has no old TS fallback');
check(str_contains($function,'$this->DirectCoolingEnqueue($room, $state, $previous)'),
    'Cooling OFF and ON both use native S1 locking');
check(str_contains($source,'private function DirectCoolingLockFeedbackAfterRead('),
    'Independent S1 feedback confirmation required');
echo "PASS no legacy fallback; all rooms S1-locked in cooling\n";
echo "ALL SAFE COOLING FALLBACK SCENARIOS PASSED\n";
