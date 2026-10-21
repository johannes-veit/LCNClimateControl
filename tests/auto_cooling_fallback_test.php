<?php
declare(strict_types=1);
const VARIABLETYPE_BOOLEAN = 0;
$GLOBALS['v'] = [100 => true, 101 => true, 102 => false];
$GLOBALS['updated'] = [100 => time(), 101 => time(), 102 => time()];
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
    public array $room = ['Name'=>'EG Buero','SendModule'=>11,'TargetVariable'=>777,'Table'=>'A','UpKey'=>7,'DownKey'=>8];
    public int $now = 10000;
    private function ReadPropertyBoolean(string $name): bool { return (bool) ($this->properties[$name] ?? false); }
    private function ReadPropertyInteger(string $name): int { return (int) ($this->properties[$name] ?? 0); }
    private function ReadPropertyString(string $name): string { return (string) ($this->properties[$name] ?? ''); }
    private function ReadJsonAttribute(string $name): array { return $this->attrs[$name] ?? []; }
    private function WriteJsonAttribute(string $name, array $value): void { $this->attrs[$name]=$value; }
    private function IsOperationalLCNModule(int $id): bool { return in_array($id, [11,21,31,41,51,61,111,121,131,141,151,92,192], true); }
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
    private function PushVisualizationRowByTarget(int $id): void {}
    private function PushVisualizationMeta(): void {}
    private function SendDebug(string $name, string $text, int $format): void {}
    private function FailCurrentJob(array $job, string $reason): void { $this->errors[(int)($job['TargetVariable']??0)]=$reason; }
    public function choose(bool $on, bool $previous=false): void { $this->EnqueueCoolingAutomatically($this->room,$on,$previous); }
    public function chooseRoom(array $room, bool $on): void { $this->EnqueueCoolingAutomatically($room,$on,false); }
    public function summary(): string { return $this->DirectCoolingFallbackSummary(); }
    public function recover(array $job): void { $this->HandleDirectCoolingRecovery($job); }
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
// 1. Kein Mapping -> alte Steuerung und kleine Meldung.
$a=new AutoProbe(); $a->choose(true);
check(count($a->jobs)===1 && $a->jobs[0]['Type']==='cool_up','legacy no mapping');
check(str_contains($a->summary(),'bisherige Steuerung aktiv'),'fallback footnote');
// 2. Vollaendige, frische Daten -> neuer Direktweg.
$b=new AutoProbe(); config($b);$b->choose(true);
check(count($b->jobs)===1 && $b->jobs[0]['Type']==='direct_cool','direct path');
// 3. Externe Sperre -> kein Ein-Befehl, weder alt noch neu.
$c=new AutoProbe();config($c);$GLOBALS['v'][100]=false;$c->choose(true);
check($c->jobs===[] && str_contains($c->summary(),'gestoppt'),'no cooling on safety veto');
$GLOBALS['v'][100]=true;
// 4. Reglerzustand ungewiss -> kein Legacy-Befehl.
$d=new AutoProbe();config($d);$d->lockState(777,'uncertain');$d->choose(true);
check($d->jobs===[] && str_contains($d->summary(),'gestoppt'),'uncertain lock fails closed');
// 5. Nach gescheiterter Direktsteuerung zuerst entriegeln.
$e=new AutoProbe();config($e);$e->lockState(777,'uncertain');
$job=['Type'=>'direct_recover','TargetVariable'=>777,'SendModule'=>11,'Phase'=>'unlock',
  'FallbackReason'=>'Relais-Timeout','DesiredOpen'=>true,'PreviousCoolingState'=>false];
$e->recover($job);
check(count($e->jobs)===1 && $e->jobs[0]['Phase']==='unlock_wait','send release before legacy');
$next=$e->jobs[0];$e->jobs=[];
$e->now=$next['SentAtMs']+600;$GLOBALS['v'][101]=true;$GLOBALS['updated'][101]=time();
$e->recover($next);
check(count($e->jobs)===1 && $e->jobs[0]['Phase']==='normal_target_wait','must verify normal S1Target after unlock');
$next=$e->jobs[0]; $e->jobs=[];
$e->recover($next);
check($e->attrs['DirectCoolingLocks']===[],'release clears persistent lock');
check(count($e->jobs)===1 && $e->jobs[0]['Type']==='cool_up','legacy only after release proof');
// 6. Kein Freigabebefehl: kein Legacy.
$f=new AutoProbe();config($f);$f->lockState(777,'uncertain');
$GLOBALS['release_ok']=false;$f->recover($job);
check($f->jobs===[] && str_contains($f->summary(),'gestoppt'),'release failure blocks');$GLOBALS['release_ok']=true;
// 7. Wiederholte Kuehlaktionen innerhalb einer Betriebsart bleiben im Legacy.
$a->jobs=[];config($a);$a->choose(false);
check(count($a->jobs)===1 && $a->jobs[0]['Type']==='cool_down','no repeated direct attempt after legacy');
// 8. Im realen LCN darf die neue Methode nicht einen bereits gesperrten Regler uebernehmen.
$g=new AutoProbe();config($g);$GLOBALS['v'][101]=false;$g->choose(true);
check($g->jobs===[] && str_contains($g->summary(),'gestoppt'),'externally locked controller blocks fallback');
$GLOBALS['v'][101]=true;
// 9. Ohne Polaritetsbestaetigung: herkoemmliche TS-Kuehlung.
$h=new AutoProbe();config($h);
$settings=json_decode($h->properties['CoolingRelays'],true);unset($settings[0]['PolarityConfirmed']);
$h->properties['CoolingRelays']=json_encode($settings);$h->choose(true);
check(count($h->jobs)===1 && $h->jobs[0]['Type']==='cool_up','unconfirmed polarity means legacy');
// 10. Bei bestaetigtem Regler-Sperrzustand keine alte TS-Fahrt.
$i=new AutoProbe();config($i);$i->lockState(777,'uncertain');$i->choose(false);
check($i->jobs===[],'blocked state remains blocked for cooling OFF');
// 11. Der Direktkuehlungs-Watchdog darf laufende Alt-Kuehlung bei
// fehlender neuer Sicherheitseingabe NICHT unterbrechen.
$j=new AutoProbe();$j->properties['CoolingSafetyVariable']=0;$j->choose(true);
$before=$j->jobs;$j->DirectCoolingWatchdog();
check($j->jobs===$before && $j->buffers===[],'watchdog must not touch legacy jobs');
// 12. Parallele Raeume: M11 direkt, M21 ohne Mapping alt.
$k=new AutoProbe();config($k);
$k->rooms=[$k->room, [
    'Name'=>'EG Kueche', 'SendModule'=>21, 'TargetVariable'=>778,
    'Table'=>'A','UpKey'=>7,'DownKey'=>8
]];
$k->choose(true);
$k->chooseRoom($k->rooms[1],true);
check(count($k->jobs)===2 && $k->jobs[0]['Type']==='direct_cool'
    && $k->jobs[1]['Type']==='cool_up','independent paths in parallel rooms');
// 13. Elf Raeume werden unabhaengig in einem Kuehlzyklus geplant:
// direkt pro bestaetigtem Raum und automatisch alt ohne Voraussetzungen.
$m=new AutoProbe();
$roomSpecs=[
  ['EG Buero',11,92,1],['EG Kueche',21,92,2],
  ['EG Wohnen',31,92,3],['EG Schlafen',41,92,5],
  ['EG Bad',51,92,6],['EG Gaeste-WC',61,92,4],
  ['OG Schlafen1',111,192,4],['OG Schlafen2',121,192,3],
  ['OG Ankleide',131,192,1],['OG Bad',141,192,2],['OG Flur',151,192,5]
];
$bindings=[];$m->rooms=[];
foreach($roomSpecs as $index=>$spec){
  $target=777+$index;
  $m->rooms[]=['Name'=>$spec[0],'SendModule'=>$spec[1],
    'TargetVariable'=>$target,'Table'=>'A','UpKey'=>7,'DownKey'=>8];
  $unlock=2000+$index;$relay=3000+$index;
  $GLOBALS['v'][$unlock]=true;$GLOBALS['v'][$relay]=false;
  $GLOBALS['updated'][$unlock]=time();$GLOBALS['updated'][$relay]=time();
  if ($index%2===0) {
      $bindings[]=['TargetVariable'=>$target,'RelayModule'=>$spec[2],
        'RelayChannel'=>$spec[3],'RegulatorIndex'=>2,
        'UnlockedVariable'=>$unlock,'RelayStatusVariable'=>$relay,
        'OpenRelayOn'=>true,'PolarityConfirmed'=>true];
  }
}
$m->properties['CoolingRelays']=json_encode($bindings);
foreach($m->rooms as $row){$m->chooseRoom($row,true);}
check(count($m->jobs)===11,'all 11 rooms get exactly one job');
foreach($m->jobs as $index=>$job){
  $expected=$index%2===0 ? 'direct_cool' : 'cool_up';
  check($job['Type']===$expected,'per-room independent selection at '.$index);
}
check(str_contains($m->summary(),'bisherige Steuerung aktiv'),'mixed rooms show legacy footer');
echo "PASS: 13 scenarios incl. all 11 parallel room decisions\n";
