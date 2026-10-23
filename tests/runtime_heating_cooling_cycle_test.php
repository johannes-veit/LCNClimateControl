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
 public function ReadPropertyBoolean(string $s): bool {return false;}
 public function GetValue(string $s): mixed {return $this->own[$s]??0;}
 public function SetValue(string $s,mixed $v): void {$this->own[$s]=$v;}
 public function SetTimerInterval(string $name,int $ms): void {$this->interval=$ms;}
 public function SetStatus(int $status): void {$this->attrs['Status']=$status;}
 public function SendDebug(string $a,string $b,int $c): void {}
 public function UpdateVisualizationValue(string $json): void {}
}
$values=[701=>20.0,801=>20.0];$sendLog=[];
function IPS_GetKernelRunlevel(): int {return KR_READY;}
function IPS_SemaphoreEnter(string $s,int $timeout): bool {return true;}
function IPS_SemaphoreLeave(string $s): void {}
function IPS_Sleep(int $ms): bool {return true;}
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
// The obsolete TS/A7-A8 cooling-bound emulation is no longer a valid
// operating mode. Production cooling must fail closed until ALL eleven
// S1 tests, relay tests, software and physical interlocks are certified.
$before=count($sendLog);
try { $mod->RequestAction('Mode',1); } catch (Throwable) {
    // Rejection may be raised as a module exception.
}
test($mod->own['Mode']===0,'Uncommissioned cooling MUST remain in HEIZEN');
test(count($sendLog)===$before,'No additional legacy TS cooling commands');
test($values[701]===22.0,'Cooling rejection must not disturb heating setpoint');
foreach($sendLog as $command)
    test(in_array($command,['K---00000010','K---00000001'],true),'Invalid heating TS');
echo "PASS: heat 20->22 by TS; uncommissioned cooling blocked, no fallback.\n";

 
// OG Schlafzimmer use the native LCN-PRO temperature range 12..22 degrees.
// Never enqueue a forbidden TS command from the Symcon UI or direct IPS action.
$og1=['Enabled'=>true,'Name'=>'OG Schlafen 1','SendModule'=>501,
  'TargetVariable'=>49519,'TemperatureVariable'=>801,'Table'=>'A','UpKey'=>7,'DownKey'=>8];
$og2=['Enabled'=>true,'Name'=>'OG Schlafen 2','SendModule'=>501,
  'TargetVariable'=>34041,'TemperatureVariable'=>801,'Table'=>'A','UpKey'=>7,'DownKey'=>8];
$mod->SetBuffer('RuntimeRooms',json_encode([$room,$og1,$og2]));
$limits=new ReflectionMethod(LCNClimateControl::class,'HeatingLimits');
foreach ([$og1,$og2] as $og) {
    $range=$limits->invoke($mod,$og);
    test($range===['min'=>12,'max'=>22],$og['Name'].' must match native LCN bounds');
    foreach ([11,23,24,25] as $invalid) {
        $countBefore=count($sendLog);
        $queueBefore=$mod->GetBuffer('Queue');
        $rejected=false;
        try { $mod->RequestAction('Heat_'.$og['TargetVariable'],(float)$invalid); }
        catch (InvalidArgumentException) {$rejected=true;}
        test($rejected,$og['Name'].': invalid heat setpoint '.$invalid.' was accepted');
        test($queueBefore===$mod->GetBuffer('Queue'), 'invalid heat action modified queue');
        test(count($sendLog)===$countBefore,'invalid heat action sent TS command');
    }
    $mod->RequestAction('Heat_'.$og['TargetVariable'],22.0);
    $q=json_decode($mod->GetBuffer('Queue'),true)?:[];
    $rowJobs=array_values(array_filter($q,fn($x)=>($x['TargetVariable']??0)===$og['TargetVariable']));
    test(count($rowJobs)===1 && (float)$rowJobs[0]['Desired']===22.0,
        $og['Name'].' 22 degree request not queued');
    $mod->RequestAction('Heat_'.$og['TargetVariable'],12.0);
    $q=json_decode($mod->GetBuffer('Queue'),true)?:[];
    $rowJobs=array_values(array_filter($q,fn($x)=>($x['TargetVariable']??0)===$og['TargetVariable']));
    test(count($rowJobs)===1 && (float)$rowJobs[0]['Desired']===12.0,
        $og['Name'].' lower boundary request not retargeted');
}
test($limits->invoke($mod,$room)===['min'=>18,'max'=>24],
    'non-OG room heat range changed');
$mod->RequestAction('Heat_701',24.0);
$q=json_decode($mod->GetBuffer('Queue'),true)?:[];
$egJobs=array_values(array_filter($q,fn($x)=>($x['TargetVariable']??0)===701));
test(count($egJobs)===1 && (float)$egJobs[0]['Desired']===24.0,
    'other heating rooms must retain 24 degree capacity');
test(count($sendLog)===$before,'test must not send extra TS commands');
echo "PASS: OG Schlafen 1/2 accept only 12..22, other rooms preserve 18..24, no forbidden TS.\n";
