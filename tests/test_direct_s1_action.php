<?php
declare(strict_types=1);
require_once __DIR__ . '/../LCNClimateControl/DirectCooling.php';

const VARIABLETYPE_BOOLEAN = 0;
$GLOBALS['actVars'] = [
    34107=>['VariableType'=>2],
    46746=>['VariableType'=>0,'VariableAction'=>19003]
];
$GLOBALS['actCommands'] = [];
$GLOBALS['actSleeps'] = [];
$GLOBALS['actParent'] = 19003;
function IPS_FunctionExists(string $name): bool { return function_exists($name); }
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['actVars'][$id]); }
function IPS_GetVariable(int $id): array { return $GLOBALS['actVars'][$id]; }
function IPS_GetParent(int $id): int
{
    return ($id===46746||$id===34107) ? $GLOBALS['actParent'] : 0;
}
function IPS_InstanceExists(int $id): bool { return $id===19003; }
function IPS_GetInstance(int $id): array { return ['ConnectionID'=>37617]; }
function IPS_GetObject(int $id): array
{
    return ['ObjectIdent'=>$id===46746 ? 'S1TargetLock':'S1Target'];
}
function IPS_SemaphoreEnter(string $name,int $wait): bool { return true; }
function IPS_SemaphoreLeave(string $name): void {}
function IPS_Sleep(int $ms): bool { $GLOBALS['actSleeps'][]=$ms; return true; }
function RequestAction(int $id,bool $value): bool
{
    $GLOBALS['actCommands'][]=[$id,$value];
    return true;
}
final class DirectMock
{
    use LCNDirectCooling;
    private const GLOBAL_LCN_SEND_SEMAPHORE='LCN_BUS_SEND_GLOBAL';
    private function IsOperationalLCNModule(int $id): bool { return $id===37617; }
    private function IsOperationalTargetVariable(int $id,int $sender): bool
    {
        return $id===34107 && $sender===37617;
    }
    private function ReleaseLCNBusSlot(): void
    {
        IPS_Sleep(750);
        IPS_SemaphoreLeave(self::GLOBAL_LCN_SEND_SEMAPHORE);
    }
    private function SendDebug(string $kind,string $message,int $format): void {}
    public function targetAction(array $room,array $mapping): int
    {
        return $this->DirectCoolingLockActionID($room,$mapping);
    }
    public function execute(array $room,array $mapping,bool $locked): bool
    {
        return $this->DirectCoolingControllerCommand($room,$mapping,$locked);
    }
}
function assertion(bool $truth,string $message): void
{
    if (!$truth) { throw new RuntimeException('FAIL: '.$message); }
}
$m=new DirectMock();
$job=['SendModule'=>37617,'TargetVariable'=>34107];
$mapping=['UnlockedVariable'=>46746,'RegulatorIndex'=>2,'LockFeedbackIsLocked'=>true];
assertion($m->targetAction($job,$mapping)===46746,'native action correctly discovered');
assertion($m->execute($job,$mapping,true),'lock native action accepted');
assertion($m->execute($job,$mapping,false),'unlock native action accepted');
assertion($GLOBALS['actCommands']===[[46746,true],[46746,false]],
    'only native actions, no other LCN commands');
assertion($GLOBALS['actSleeps']===[750,750],
    'single 750ms traffic gap per active command');
echo "PASS native S1 action lock/release plus shared bus pacing\n";
$cases=[
  ['RegulatorIndex',0],
  ['UnlockedVariable',0],
  ['LockFeedbackIsLocked',false],
];
foreach($cases as [$key,$value]){
    $bad=$mapping;$bad[$key]=$value;
    assertion($m->targetAction($job,$bad)===0,'reject invalid '.$key);
    assertion(!$m->execute($job,$bad,true),'send blocked for invalid '.$key);
}
$previous=count($GLOBALS['actCommands']);
assertion($previous===2,'invalid commands never reached native action');
$GLOBALS['actVars'][46746]['VariableAction']=0;
assertion($m->targetAction($job,$mapping)===0,'must have native standard action');
assertion(!$m->execute($job,$mapping,true),'no bus call without standard action');
assertion(count($GLOBALS['actCommands'])===2,'no actuation during validation errors');
echo "PASS invalid mapping, regulator index and missing action fail closed\n";
echo "SIX DIRECT ACTION CASES DEFINED\n";
