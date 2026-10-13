<?php
declare(strict_types=1);
// Closed-loop test of the real module Worker(): fake IP-Symcon and fake LCN,
// never touches physical hardware or a SymBox.
const VARIABLETYPE_FLOAT=2;
const KR_READY=10103;
class IPSModuleStrict {
 public int $InstanceID=301;
 public array $buffers=['ConfigurationValid'=>'1','ConfigurationErrors'=>'[]','Queue'=>'[]','CurrentJob'=>'',
   'RuntimeRooms'=>'[]','PendingMode'=>'','RoomErrors'=>'{}'];
 public array $attrs=['OperatingMode'=>0,'LastHeatingTargets'=>'{}','CoolingStates'=>'{}','LastError'=>''];
 public array $own=['Mode'=>0,'Heat_701'=>20.0,'Cool_701'=>false];
 public int $interval=0;
 public function GetBuffer(string $s): string {return $this->buffers[$s]??'';}
 public function SetBuffer(string $s,string $v): void {$this->buffers[$s]=$v;}
 public function ReadAttributeInteger(string $s): int {return (int)($this->attrs[$s]??0);}
 public function WriteAttributeInteger(string $s,int $v): void {$this->attrs[$s]=$v;}
 public function ReadAttributeString(string $s): string {return (string)($this->attrs[$s]??'');}
 public function WriteAttributeString(string $s,string $v): void {$this->attrs[$s]=$v;}
 public function ReadPropertyInteger(string $s): int {return match($s){'StepWaitMs'=>900,'MaxSteps'=>25,'NoChangeConfirmations'=>2,default=>0};}
 public function GetValue(string $s): mixed {return $this->own[$s]??0;}
 public function SetValue(string $s,mixed $v): void {$this->own[$s]=$v;}
 public function SetTimerInterval(string $name,int $ms): void {$this->interval=$ms;}
 public function SendDebug(string $a,string $b,int $c): void {}
 public function UpdateVisualizationValue(string $json): void {}
}
$values=[701=>20.0,801=>20.0];$sendLog=[];
function IPS_GetKernelRunlevel(): int {return KR_READY;}
function IPS_SemaphoreEnter(string $s,int $timeout): bool {return true;}
function IPS_SemaphoreLeave(string $s): void {}
function IPS_FunctionExists(string $s): bool {return true;}
function IPS_InstanceExists(int $id): bool {return in_array($id,[501,601],true);}
function IPS_GetInstance(int $id): array {
 if($id===501)return ['InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>'{0E31FED6-E465-4621-95D4-AAF2683C41EC}','ModuleType'=>2]];
 return ['InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>'{0102BDC9-3B85-4A11-968D-7D314DA07C06}'],'ConnectionID'=>501];
}
function IPS_VariableExists(int $id): bool {global $values;return array_key_exists($id,$values);}
function IPS_GetParent(int $id): int {return 601;}
function GetValue(int $id): mixed {global $values;return $values[$id];}
function IPS_GetObjectIDByIdent(string $ident,int $parent): int {return 0;}
function IPS_GetVariable(int $id): array {return ['VariableType'=>2];}
function LCN_SendCommand(int $id,string $command,string $data): bool {
 global $values,$sendLog;
 if($id!==501 || $command!=='TS')throw new RuntimeException('Wrong command route');
 $up=$data==='K---00000010';$down=$data==='K---00000001';
 if(!$up&&!$down)throw new RuntimeException('Unexpected TS payload '.$data);
 $sendLog[]=$data;
 $values[701]=min(24.0,max(18.0,$values[701]+($up?1.0:-1.0)));
 return true;
}
function test(bool $okay,string $message): void {if(!$okay)throw new RuntimeException($message);}
require dirname(__DIR__).'/LCNClimateControl/module.php';
$mod=(new ReflectionClass(LCNClimateControl::class))->newInstanceWithoutConstructor();
$room=['Enabled'=>true,'Name'=>'EG Bad','SendModule'=>501,'TargetVariable'=>701,
'TemperatureVariable'=>801,'Table'=>'A','UpKey'=>7,'DownKey'=>8];
$mod->SetBuffer('RuntimeRooms',json_encode([$room]));
function drain(LCNClimateControl $mod,int $limit=70): void {
 for($i=0;$i<$limit;$i++){
  // Simulate elapsed >= 900 ms without actually sleeping, so module.php
  // evaluates genuine WAIT-phase data and native ReadTarget feedback.
  $queue=json_decode($mod->GetBuffer('Queue'),true)?:[];
  foreach($queue as &$job)if(($job['Phase']??'')==='wait')$job['SentAtMs']=(int)round(microtime(true)*1000)-2000;
  unset($job);
  $mod->SetBuffer('Queue',json_encode($queue));
  $current=json_decode($mod->GetBuffer('CurrentJob'),true);
  if(is_array($current)&&($current['Phase']??'')==='wait'){
   $current['SentAtMs']=(int)round(microtime(true)*1000)-2000;
   $mod->SetBuffer('CurrentJob',json_encode($current));
  }
  $mod->Worker();
  if($mod->GetBuffer('CurrentJob')==='' && (json_decode($mod->GetBuffer('Queue'),true)?:[])===[] &&
    $mod->GetBuffer('PendingMode')==='')return;
 }
 throw new RuntimeException('Worker did not settle in '.$limit.' ticks');
}
$mod->RequestAction('Heat_701',22.0);drain($mod);
test($values[701]===22.0,'Heating target must be reached');
test($mod->own['Mode']===0,'Heating mode changed unexpectedly');
test(count($sendLog)===2,'Heating should take exactly 2 TS steps');
$mod->RequestAction('Mode',1);drain($mod);
test($mod->own['Mode']===1,'Cooling mode not activated');
test($values[701]===18.0,'Cooling off must reach lower control bound');
test($mod->own['Cool_701']===false,'Cooling off state must be confirmed');
$mod->RequestAction('Cool_701',true);drain($mod);
test($values[701]===24.0,'Cooling on must reach upper control bound');
test($mod->own['Cool_701']===true,'Cooling on state must be confirmed');
$mod->RequestAction('Mode',0);drain($mod);
test($values[701]===22.0,'Heating target was not restored');
test($mod->own['Mode']===0,'Heating mode not restored');
foreach($sendLog as $command)test(in_array($command,['K---00000010','K---00000001'],true),'Invalid TS');
echo 'PASS: real Worker + fake LCN feedback: heat 20→22, cool-off→18, cool-on→24, restore heat→22. '.count($sendLog).' TS steps.\n';
