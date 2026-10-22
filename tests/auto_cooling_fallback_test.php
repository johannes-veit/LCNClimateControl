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
// 5. Nach gescheiterter Direktsteuerung erst RELAIS AUS, dann
// Regler entriegeln, dann frischen normalen Sollwert nachweisen.
$e=new AutoProbe();config($e);$e->lockState(777,'uncertain');
$job=['Type'=>'direct_recover','TargetVariable'=>777,'SendModule'=>11,'Phase'=>'close',
  'FallbackReason'=>'Relais-Timeout','DesiredOpen'=>true,'PreviousCoolingState'=>false];
$e->recover($job);
check(count($e->jobs)===1 && $e->jobs[0]['Phase']==='close_wait','send relay OFF before unlocking');
$next=$e->jobs[0];$e->jobs=[];
$e->now=$next['SentAtMs']+600;$GLOBALS['v'][102]=false;$GLOBALS['updated'][102]=time();
$e->recover($next);
check(count($e->jobs)===1 && $e->jobs[0]['Phase']==='unlock','relay OFF confirmed first');
$next=$e->jobs[0];$e->jobs=[];
$e->recover($next);
check(count($e->jobs)===1 && $e->jobs[0]['Phase']==='unlock_wait','send unlock after OFF confirmation');
$next=$e->jobs[0];$e->jobs=[];
$e->now=$next['SentAtMs']+600;$GLOBALS['v'][101]=true;$GLOBALS['updated'][101]=time();
$e->recover($next);
check(count($e->jobs)===1 && $e->jobs[0]['Phase']==='normal_target_wait','normal target must be checked');
$next=$e->jobs[0]; $e->jobs=[];
$GLOBALS['updated'][777]=time();
$e->recover($next);
check($e->attrs['DirectCoolingLocks']===[],'release clears persistent lock');
check(count($e->jobs)===1 && $e->jobs[0]['Type']==='cool_up','legacy only after relay+release+target proof');
// 6. Kein Freigabebefehl: kein Legacy.
$f=new AutoProbe();config($f);$f->lockState(777,'uncertain');
$GLOBALS['release_ok']=false;
$f->recover($job);
$phase=$f->jobs[0];$f->jobs=[];$f->now=$phase['SentAtMs']+600;
$f->recover($phase);$phase=$f->jobs[0];$f->jobs=[];
$f->recover($phase);
check($f->jobs===[] && str_contains($f->summary(),'gestoppt'),'release failure blocks');
$GLOBALS['release_ok']=true;
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
// 10. Bei ungewissem Reglerzustand wird KEINE alte A7/A8-Fahrt
// gestartet: nur der sichere Einzelrelais-AUS-Auftrag darf erfolgen.
$i=new AutoProbe();config($i);$i->lockState(777,'uncertain');$i->choose(false);
check(count($i->jobs)===1
    && $i->jobs[0]['Type']==='direct_emergency_close',
    'uncertain lock must allow only verified native relay OFF, never legacy TS');
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
// 14. Safety-Flag wird NACH altem Fallback FALSE: nie erneut Kuehlen EIN.
$n=new AutoProbe();$n->choose(true); // auto -> bisherige TS-Methode
check(count($n->jobs)===1 && $n->jobs[0]['Type']==='cool_up','legacy fallback initially');
$n->jobs=[];$GLOBALS['v'][100]=false;
$n->choose(true);
check($n->jobs===[] && str_contains($n->summary(),'gestoppt'),
    'configured safety veto must override ongoing legacy fallback');
$GLOBALS['v'][100]=true;
// 15. Kuehlen AUS am Beginn braucht nie eine Reglersperre.
$off=new AutoProbe();config($off);$off->choose(false);
check(count($off->jobs)===1 && $off->jobs[0]['Type']==='cool_down',
    'cooling OFF must preserve legacy route and never lock controller');
// 16. Gesicherte Relais-/Reglerzuordnung muss einen Neustart der
// Laufzeitdiagnose ueberleben; spaeterer Release benutzt gespeicherte
// Zuordnung, nicht geratenes LCN-Ziel.
$saved=new AutoProbe();config($saved);
$reflect=new ReflectionClass($saved);
$fn=$reflect->getMethod('DirectCoolingSaveLockMapping');
$fn->invoke($saved,777,[
    'TargetVariable'=>777,'RelayModule'=>92,'RelayUnit'=>700,
    'RelayChannel'=>1,'RegulatorIndex'=>2,
    'UnlockedVariable'=>101,'RelayStatusVariable'=>102,
    'OpenRelayOn'=>true,'PolarityConfirmed'=>true
]);
check(isset($saved->attrs['DirectCoolingLockMappings']['777']),
    'durable mapping must be saved');
$job=['TargetVariable'=>777,'SendModule'=>11,'Type'=>'direct_release','Phase'=>'unlock'];
$get=$reflect->getMethod('DirectCoolingMappingForJob');
check(($get->invoke($saved,$job)['RelayUnit']??0)===700,
    'recovery must honor saved mapping');
// 17. Nach erfolgreicher Entsperrung muss der gespeicherte Mapping-Eintrag
// geloescht werden.
$saved->lockState(777,'locked');
$saved->lockState(777,'');
check(!isset($saved->attrs['DirectCoolingLockMappings']['777']),
    'durable mapping must be deleted only after confirmed unlock');
// 18. Vollstaendige neue Direktschaltung EIN ueber den echten PHP
// Zustandsautomaten (ohne Hardware): lock -> feedback -> relay -> feedback.
$z=new AutoProbe();config($z);
$GLOBALS['v'][101]=true;$GLOBALS['v'][102]=false;
$z->choose(true);$job=$z->jobs[0];$z->jobs=[];
$z->processDirect($job);
check(count($z->jobs)===1 && $z->jobs[0]['Phase']==='lock_wait',
    'direct phase must first send controller lock');
$job=$z->jobs[0];$z->jobs=[];$z->now=$job['SentAtMs']+600;
$GLOBALS['v'][101]=false;$GLOBALS['updated'][101]=time();
$z->processDirect($job);
check($z->attrs['DirectCoolingLocks']['777']==='locked'
    && $z->jobs[0]['Phase']==='relay','lock feedback must precede relay');
$job=$z->jobs[0];$z->jobs=[];$z->processDirect($job);
check($z->jobs[0]['Phase']==='relay_wait','relay switch must await feedback');
$job=$z->jobs[0];$z->jobs=[];$z->now=$job['SentAtMs']+600;
$GLOBALS['v'][102]=true;$GLOBALS['updated'][102]=time();
$z->processDirect($job);
check(($z->coolingStored[777]??false)===true
    && $z->jobs===[], 'only confirmed relay ON may store cooling ON');
$GLOBALS['v'][101]=true;$GLOBALS['v'][102]=false;
// 19. Vollstaendige Rueckkehr zur Heizregelung nach direkter Kuehlung.
$back=new AutoProbe();config($back);$back->lockState(777,'locked');
$GLOBALS['v'][101]=false;$GLOBALS['v'][102]=true;
$back->release(22.0);check(count($back->jobs)===1,'release job enqueued');
$job=$back->jobs[0];$back->jobs=[];
$back->processDirect($job);
check($back->jobs[0]['Phase']==='close_wait','release must first de-energize actuator');
$job=$back->jobs[0];$back->jobs=[];
$back->now=$job['SentAtMs']+600;$GLOBALS['v'][102]=false;$GLOBALS['updated'][102]=time();
$back->processDirect($job);
check($back->jobs[0]['Phase']==='unlock','relay OFF precedes controller release');
$job=$back->jobs[0];$back->jobs=[];$back->processDirect($job);
check($back->jobs[0]['Phase']==='unlock_wait','controller unlock pending');
$job=$back->jobs[0];$back->jobs=[];
$back->now=$job['SentAtMs']+600;$GLOBALS['v'][101]=true;$GLOBALS['updated'][101]=time();
$back->processDirect($job);
check($back->jobs[0]['Phase']==='restore_target_wait','normal S1Target required before heating');
$job=$back->jobs[0];$back->jobs=[];$GLOBALS['updated'][777]=time();
$back->processDirect($job);
check($back->jobs[0]['Type']==='heat' && $back->jobs[0]['Desired']===22.0,
    'heating target restore must use saved value');
check(($back->attrs['DirectCoolingLocks']??[])===[],
    'lock must be released after final feedback confirmation');
$GLOBALS['v'][101]=true;$GLOBALS['v'][102]=false;
// 20. Echtes Symcon S1TargetLock: FALSE=freigegeben, TRUE=gesperrt.
$native=new AutoProbe();config($native);
$cfg=json_decode($native->properties['CoolingRelays'],true);
$cfg[0]['LockFeedbackIsLocked']=true;
$native->properties['CoolingRelays']=json_encode($cfg);
$GLOBALS['v'][101]=false;$GLOBALS['v'][102]=false;
$native->choose(true);
check(count($native->jobs)===1 && $native->jobs[0]['Type']==='direct_cool',
    'S1TargetLock=false must allow the new direct controller');
$job=$native->jobs[0];$native->jobs=[];$native->processDirect($job);
check($native->jobs[0]['Phase']==='lock_wait','S1 lock command before relay');
$job=$native->jobs[0];$native->jobs=[];
$native->now=$job['SentAtMs']+600;$GLOBALS['v'][101]=true;
$GLOBALS['updated'][101]=time();$native->processDirect($job);
check($native->jobs[0]['Phase']==='relay'
    && $native->attrs['DirectCoolingLocks']['777']==='locked',
    'S1TargetLock=true proves native controller actually locked');
$job=$native->jobs[0];$native->jobs=[];$native->processDirect($job);
check($native->jobs[0]['Phase']==='relay_wait','open only AFTER S1TargetLock=true');
$job=$native->jobs[0];$native->jobs=[];$native->now=$job['SentAtMs']+600;
$GLOBALS['v'][102]=true;$GLOBALS['updated'][102]=time();$native->processDirect($job);
check(($native->coolingStored[777]??false)===true,'confirmed relay true opens NC valve');
// 21. Native Reglerfreigabe: S1TargetLock must go TRUE -> FALSE.
$native->release(21.5);
$job=$native->jobs[0];$native->jobs=[];$native->processDirect($job);
check($native->jobs[0]['Phase']==='close_wait','close relay first');
$job=$native->jobs[0];$native->jobs=[];
$native->now=$job['SentAtMs']+600;$GLOBALS['v'][102]=false;
$GLOBALS['updated'][102]=time();$native->processDirect($job);
check($native->jobs[0]['Phase']==='unlock','relay off confirmed');
$job=$native->jobs[0];$native->jobs=[];$native->processDirect($job);
check($native->jobs[0]['Phase']==='unlock_wait','native release is sent');
$job=$native->jobs[0];$native->jobs=[];$native->now=$job['SentAtMs']+600;
$GLOBALS['v'][101]=false;$GLOBALS['updated'][101]=time();
$native->processDirect($job);
check($native->jobs[0]['Phase']==='restore_target_wait',
    'S1TargetLock=false proves unlocked');
$job=$native->jobs[0];$native->jobs=[];$GLOBALS['updated'][777]=time();
$native->processDirect($job);
check($native->jobs[0]['Type']==='heat' &&
    $native->jobs[0]['Desired']===21.5,
    'native feedback permits restoring previous heating target');
check($native->attrs['DirectCoolingLocks']===[], 'native lock state cleared');
// 22. Already locked by LCN or another controller: never try relay.
$external=new AutoProbe();config($external);
$extcfg=json_decode($external->properties['CoolingRelays'],true);
$extcfg[0]['LockFeedbackIsLocked']=true;
$external->properties['CoolingRelays']=json_encode($extcfg);
$GLOBALS['v'][101]=true;$external->choose(true);
check($external->jobs===[] &&
    str_contains($external->summary(),'gestoppt'),
    'external S1TargetLock=true must not be taken over');
$GLOBALS['v'][101]=true;$GLOBALS['v'][102]=false;
// 23. Falls die direkte Reglersperre ungewiss ist, muss OFF trotzdem
// den korrekten NC-Relaiskanal stromlos schalten koennen – OHNE UNLOCK.
$emergency=new AutoProbe();config($emergency);$emergency->lockState(777,'uncertain');
$GLOBALS['v'][102]=true;
$emergency->choose(false,true);
check(count($emergency->jobs)===1
    && $emergency->jobs[0]['Type']==='direct_emergency_close',
    'uncertain lock must still accept cooling OFF');
$job=$emergency->jobs[0];$emergency->jobs=[];$emergency->processDirect($job);
check($emergency->jobs[0]['Phase']==='close_wait',
    'NC valve receives only relay OFF');
$job=$emergency->jobs[0];$emergency->jobs=[];
$emergency->now=$job['SentAtMs']+600;
$GLOBALS['v'][102]=false;$GLOBALS['updated'][102]=time();
$emergency->processDirect($job);
check(($emergency->coolingStored[777]??true)===false,
    'NC relay OFF must be confirmed');
check(($emergency->attrs['DirectCoolingLocks']['777']??'')==='uncertain',
    'relay close must NOT pretend regulator was unlocked');
check($emergency->jobs===[],'no unexpected unlock or heating job');
$GLOBALS['v'][102]=false;
echo "PASS: 23 scenarios incl. blocked-regulator NC-valve emergency OFF\n";

