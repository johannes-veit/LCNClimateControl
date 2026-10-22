<?php
declare(strict_types=1);
// Exercises real DirectCooling trait with simulated LCN buses.
// NEVER sends switching commands: no lock/release/switch functions exist.
const VARIABLETYPE_BOOLEAN=0;
const VARIABLETYPE_FLOAT=2;
const VALUE_GUID='{0102BDC9-3B85-4A11-968D-7D314DA07C06}';
$GLOBALS['status']=[
    777=>['value'=>21.0,'updated'=>time()-100,'type'=>VARIABLETYPE_FLOAT],
    101=>['value'=>false,'updated'=>time()-100,'type'=>VARIABLETYPE_BOOLEAN],
    102=>['value'=>false,'updated'=>time()-100,'type'=>VARIABLETYPE_BOOLEAN]
];
$GLOBALS['reads']=[];
function IPS_VariableExists(int $id):bool{return isset($GLOBALS['status'][$id]);}
function IPS_GetVariable(int $id):array{
    return ['VariableType'=>$GLOBALS['status'][$id]['type'],
        'VariableUpdated'=>$GLOBALS['status'][$id]['updated']];
}
function IPS_GetParent(int $id):int{return $id===777 ? 888 : 0;}
function IPS_InstanceExists(int $id):bool{return $id===888;}
function IPS_GetInstance(int $id):array{
    return ['ModuleInfo'=>['ModuleID'=>VALUE_GUID],'ConnectionID'=>11];
}
function GetValue(int $id):mixed{return $GLOBALS['status'][$id]['value'];}
function IPS_FunctionExists(string $name):bool{
    return in_array($name,['LCN_RequestRead','LCN_RequestStatus'],true);
}
function IPS_SemaphoreEnter(string $key,int $timeout):bool{return true;}
function IPS_SemaphoreLeave(string $key):void{}
function LCN_RequestRead(int $valueInstance):bool{
    $GLOBALS['reads'][]=['read',$valueInstance];return true;
}
function LCN_RequestStatus(int $relayModule):bool{
    $GLOBALS['reads'][]=['status',$relayModule];return true;
}
require __DIR__.'/../LCNClimateControl/DirectCooling.php';
class ReconcileProbe {
    use LCNDirectCooling;
    public const MODE_COOLING=1;
    public const LCN_VALUE_MODULE_ID=VALUE_GUID;
    private const GLOBAL_LCN_SEND_SEMAPHORE='LCN_BUS_SEND_GLOBAL';
    public int $InstanceID=17597;
    public array $attrs=[
        'DirectCoolingLocks'=>['777'=>'uncertain'],
        'DirectCoolingLockMappings'=>[],
        'DirectCoolingReconcile'=>[],
        'CoolingFallbackReasons'=>[]
    ];
    public bool $busy=false;
    public bool $coolingState=false;
    public array $errors=[777=>'NC-Ventil AUS, Reglerzustand ungewiss'];
    public array $rows=[];
    public bool $relayOnline=true;
    private function UsesDirectCooling():bool{return true;}
    private function GetMode():int{return self::MODE_COOLING;}
    private function DirectCoolingSafe():bool{return true;}
    private function GetRuntimeRooms():array{
        return [['Name'=>'OG Flur','TargetVariable'=>777,'SendModule'=>11]];
    }
    private function ReadJsonAttribute(string $key):array{return $this->attrs[$key]??[];}
    private function WriteJsonAttribute(string $key,array $data):void{$this->attrs[$key]=$data;}
    private function GetStoredCoolingState(int $id):bool{return $this->coolingState;}
    private function HasJobForTarget(int $id):bool{return $this->busy;}
    private function DirectCoolingMapping(int $target):?array{
        return $target===777 ? [
            'RelayModule'=>92,'RelayStatusVariable'=>102,
            'UnlockedVariable'=>101,'OpenRelayOn'=>true,
            'LockFeedbackIsLocked'=>true
        ] : null;
    }
    private function IsOperationalLCNModule(int $id):bool{
        return $id===92 && $this->relayOnline;
    }
    private function DirectCoolingRegulatorUnlocked(array $m):bool{
        return !((bool)GetValue((int)$m['UnlockedVariable']));
    }
    private function ReadTarget(int $target):?float{
        $val=(float)GetValue($target);
        return $val>=5 && $val<=60 ? $val : null;
    }
    private function ClearRoomError(int $target):void{unset($this->errors[$target]);}
    private function PushVisualizationRowByTarget(int $target):void{$this->rows[]=$target;}
    private function SendDebug(string $name,string $message,int $level):void{}
    public function tick():void{$this->DirectCoolingReconcileUncertain();}
    public function lock():string{return $this->DirectCoolingLockState(777);}
    public function fallback():array{return $this->attrs['CoolingFallbackReasons']['777']??[];}
}
function check(bool $condition,string $explanation):void{
    if(!$condition){throw new RuntimeException($explanation);}
}
$p=new ReconcileProbe();
$p->tick(); // first pass must only start READ-ONLY requests
check(count($GLOBALS['reads'])===2
    && $GLOBALS['reads'][0]===['read',888]
    && $GLOBALS['reads'][1]===['status',92]
    && $p->lock()==='uncertain','first pass cannot unlock using cached Boolean');
$p->tick(); // 100s-old feedback must never clear uncertainty
check($p->lock()==='uncertain' && count($GLOBALS['reads'])===2,
    'cached S1TargetLock=false from BEFORE read must not clear uncertain');
// First genuine fresh answer (matching first bus read)
foreach([777,101,102] as $id){$GLOBALS['status'][$id]['updated']=time()+3;}
$p->tick();
check($p->lock()==='uncertain'
    && $p->attrs['DirectCoolingReconcile']['777']['proofs']===1,
    'first fresh sample cannot clear persisted uncertain lock');
$p->tick();
check(count($GLOBALS['reads'])===4
    && $GLOBALS['reads'][2]===['read',888],
    'second proof must issue second native read request');
// Same cached update must NOT count a second time
$p->tick();
check($p->lock()==='uncertain' &&
    $p->attrs['DirectCoolingReconcile']['777']['proofs']===1,
    'duplicate unchanged status update cannot be counted twice');
// Current hardware says LOCKED: never clear regardless of fresh timestamps
$GLOBALS['status'][101]['value']=true;
foreach([777,101,102] as $id){$GLOBALS['status'][$id]['updated']=time()+6;}
$p->tick();
check($p->lock()==='uncertain' && $p->fallback()===[],
    'a confirmed hardware lock may never be cleared');
// New clean pair of reads: reinitialize and test in a fresh probe
$q=new ReconcileProbe();$GLOBALS['reads']=[];
$GLOBALS['status'][101]['value']=false;
$GLOBALS['status'][102]['value']=true;
$q->tick();
foreach([777,101,102] as $id){$GLOBALS['status'][$id]['updated']=time()+10;}
$q->tick();
check($q->lock()==='uncertain',
    'energized NC relay must block automatic lock reconciliation');
// Only now first clean sample
$GLOBALS['status'][102]['value']=false;
$q->tick(); // starts new read if proof was reset
foreach([777,101,102] as $id){$GLOBALS['status'][$id]['updated']=time()+15;}
$q->tick();
check($q->lock()==='uncertain' && $q->attrs['DirectCoolingReconcile']['777']['proofs']===1,
    'clean regulator + relay sample one of two');
$q->tick();
foreach([777,101,102] as $id){$GLOBALS['status'][$id]['updated']=time()+20;}
$q->tick();
check($q->lock()==='' && ($q->fallback()['kind']??'')==='legacy'
    && !isset($q->errors[777])
    && $q->rows===[777], 'second independent fresh read clears stale software marker');
check(count($GLOBALS['reads'])>=4 &&
    array_unique(array_column($GLOBALS['reads'],'0'))!==null,
    'LCN read-only requests were made');
check($q->attrs['DirectCoolingLockMappings']===[],
    'reconcile never synthesizes a new regulator mapping');
echo "PASS: cached locks retained, fresh independent double bus proof, relay safety, legacy-only fallback\n";
