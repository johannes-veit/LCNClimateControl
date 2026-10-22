<?php
declare(strict_types=1);
// Real LCNClimateControl PHP class with an IPSModuleStrict runtime shim.
// No LCN command functions are registered: any attempt to send one fails.
const KR_READY=10103;
const VARIABLETYPE_BOOLEAN=0;
const VARIABLETYPE_FLOAT=2;
$GLOBALS['sentLCN']=0;
function IPS_SemaphoreEnter(string $name,int $timeout):bool{return true;}
function IPS_SemaphoreLeave(string $name):void{}
function IPS_GetKernelRunlevel():int{return KR_READY;}
function IPS_VariableExists(int $id):bool{return false;}
function IPS_InstanceExists(int $id):bool{return false;}

class IPSModuleStrict {
    public int $InstanceID=17597;
    public array $buffers=[];
    public array $attributes=['CoolingStates'=>'{}','LastError'=>'','CoolingFallbackReasons'=>'{}'];
    public array $properties=['DirectCoolingEnabled'=>false,'CoolingSafetyVariable'=>0];
    public array $values=['Mode'=>0];
    public array $visualization=[];
    public array $timers=[];
    public array $statuses=[];
    public function GetBuffer(string $name):string{return (string)($this->buffers[$name]??'');}
    public function SetBuffer(string $name,string $value):void{$this->buffers[$name]=$value;}
    public function ReadAttributeString(string $name):string{return (string)($this->attributes[$name]??'');}
    public function WriteAttributeString(string $name,string $val):void{$this->attributes[$name]=$val;}
    public function ReadAttributeInteger(string $name):int{return (int)($this->attributes[$name]??0);}
    public function WriteAttributeInteger(string $name,int $val):void{$this->attributes[$name]=$val;}
    public function ReadPropertyBoolean(string $name):bool{return (bool)($this->properties[$name]??false);}
    public function ReadPropertyInteger(string $name):int{return (int)($this->properties[$name]??0);}
    public function ReadPropertyString(string $name):string{return (string)($this->properties[$name]??'');}
    public function GetValue(string $ident):mixed{return $this->values[$ident]??null;}
    public function SetValue(string $ident,mixed $value):void{$this->values[$ident]=$value;}
    public function SetStatus(int $status):void{$this->statuses[]=$status;}
    public function SetTimerInterval(string $name,int $ms):void{$this->timers[$name]=$ms;}
    public function UpdateVisualizationValue(string $payload):void{$this->visualization[]=json_decode($payload,true);}
    public function SendDebug(string $tag,string $message,int $level):void{}
}
require __DIR__.'/../LCNClimateControl/module.php';
function check(bool $ok,string $reason):void{
    if(!$ok){throw new RuntimeException($reason);}
}
function callPrivate(object $obj,string $name,mixed ...$args):mixed{
    return (new ReflectionMethod($obj,$name))->invoke($obj,...$args);
}
$climate=new LCNClimateControl();
$rooms=[
  ['Name'=>'OG Ankleide / HWR','TargetVariable'=>58217,'SendModule'=>41134,'Table'=>'A','UpKey'=>7,'DownKey'=>8,'TemperatureVariable'=>0],
  ['Name'=>'OG Flur','TargetVariable'=>15371,'SendModule'=>11331,'Table'=>'A','UpKey'=>7,'DownKey'=>8,'TemperatureVariable'=>0]
];
$climate->buffers=[
    'RuntimeRooms'=>json_encode($rooms),
    'ConfigurationValid'=>'1','RoomErrors'=>'{}',
    'Queue'=>'[]','CurrentJob'=>'','PendingMode'=>'',
    'CoolingPendingRequests'=>'{}'
];
$climate->attributes['CoolingStates']=json_encode(['58217'=>true,'15371'=>true]);
// 1. Heating-mode cooling preselection must persist, never switch any LCN relay.
$climate->values['Mode']=0;
$climate->RequestAction('Cool_58217',false);
$climate->RequestAction('Cool_15371',false);
$states=json_decode($climate->attributes['CoolingStates'],true);
check($states['58217']===false && $states['15371']===false,
    'both rooms can preselect Not Cooling while heating');
check($climate->buffers['Queue']==='[]' && $climate->buffers['CurrentJob']==='',
    'heating-mode preselection MUST NOT enqueue physical cooling');
check($GLOBALS['sentLCN']===0,'preselection has zero LCN bus commands');
// 2. While an earlier cooling ON job for OG Ankleide is pending,
// OFF must be accepted and acknowledged rather than thrown / rolled back.
$climate->values['Mode']=1;
$climate->attributes['CoolingStates']=json_encode(['58217'=>false,'15371'=>false]);
$first=['Type'=>'cool_up','Phase'=>'wait','TargetVariable'=>58217,
    'Name'=>'OG Ankleide / HWR','SendModule'=>41134,
    'PreviousCoolingState'=>false,'Steps'=>1];
$climate->buffers['CurrentJob']=json_encode($first);
$climate->RequestAction('Cool_58217',false);
$pending=json_decode($climate->buffers['CoolingPendingRequests'],true);
check(array_key_exists('58217',$pending)&&$pending['58217']===false,
    'OFF for a busy room must be accepted and stored');
$display=callPrivate($climate,'BuildVisualizationRow',$rooms[0]);
check($display['cooling']===false && $display['busy']===true,
    'tile must immediately acknowledge most recent OFF while job is busy');
// 3. Rapid toggles last-write-wins.
$climate->RequestAction('Cool_58217',true);
$climate->RequestAction('Cool_58217',false);
check(json_decode($climate->buffers['CoolingPendingRequests'],true)['58217']===false,
    'latest user OFF must win rapid changes');
// 4. OG Flur is independent. Its request must be queued without affecting OG Ankleide.
$climate->RequestAction('Cool_15371',false);
$jobs=json_decode($climate->buffers['Queue'],true);
check(count($jobs)===1 && $jobs[0]['TargetVariable']===15371
    && $jobs[0]['Type']==='cool_down','another room queues independently');
// 5. Once first Ankleide ON job finishes, latest OFF must be queued.
callPrivate($climate,'CompleteCurrentJob',$first,26.0);
$cur=json_decode($climate->buffers['CurrentJob'],true);
$queue=json_decode($climate->buffers['Queue'],true);
$all=array_merge(is_array($cur)?[$cur]:[],is_array($queue)?$queue:[]);
$byTarget=[];foreach($all as $job){$byTarget[$job['TargetVariable']]=$job['Type'];}
check(($byTarget[58217]??'')==='cool_down','Ankleide must get a corrective OFF job');
check(($byTarget[15371]??'')==='cool_down','Flur OFF must remain queued');
check($GLOBALS['sentLCN']===0,'test only models state and does not touch hardware');
echo "PASS: heating-mode preselection, two concurrent rooms, busy OFF, rapid last-write-wins and corrective OFF queue\n";
