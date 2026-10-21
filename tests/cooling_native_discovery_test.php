<?php
declare(strict_types=1);
// Controlled native Symcon discovery fixtures. No real bus communication.
const VARIABLETYPE_BOOLEAN=0;
const MODULE='{0E31FED6-E465-4621-95D4-AAF2683C41EC}';
const UNIT='{2D871359-14D8-493F-9B01-26432E3A710F}';
const VALUE='{0102BDC9-3B85-4A11-968D-7D314DA07C06}';
$GLOBALS['instances']=[
11=>['ConnectionID'=>500,'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>MODULE]],
92=>['ConnectionID'=>500,'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>MODULE]],
700=>['ConnectionID'=>92,'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>UNIT]],
800=>['ConnectionID'=>11,'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>VALUE]]
];
$GLOBALS['configs']=[
11=>['Target'=>11,'Segment'=>0],
92=>['Target'=>92,'Segment'=>0],
700=>['Unit'=>2,'Channel'=>1],
800=>[]
];
$GLOBALS['variables']=[
10000=>['VariableType'=>2,'Parent'=>800,'Name'=>'S1Target'],
10001=>['VariableType'=>0,'Parent'=>800,'Name'=>'Entriegelt'],
7001=>['VariableType'=>0,'Parent'=>700,'Name'=>'Status']
];
function IPS_InstanceExists(int $id): bool{return isset($GLOBALS['instances'][$id]);}
function IPS_GetInstance(int $id): array{return $GLOBALS['instances'][$id];}
function IPS_GetConfiguration(int $id): string{return json_encode($GLOBALS['configs'][$id]);}
function IPS_GetInstanceListByModuleID(string $guid): array{
    $out=[];foreach($GLOBALS['instances'] as $id=>$data){
        if($data['ModuleInfo']['ModuleID']===$guid)$out[]=$id;
    }return $out;
}
function IPS_VariableExists(int $id): bool{return isset($GLOBALS['variables'][$id]);}
function IPS_GetVariable(int $id): array{return $GLOBALS['variables'][$id];}
function IPS_GetParent(int $id): int{return $GLOBALS['variables'][$id]['Parent']??0;}
function IPS_GetChildrenIDs(int $id): array{
    $out=[];foreach($GLOBALS['variables'] as $v=>$meta){if($meta['Parent']===$id)$out[]=$v;}return $out;
}
function IPS_GetName(int $id): string{return $GLOBALS['variables'][$id]['Name'];}
require __DIR__.'/../LCNClimateControl/CoolingDiscovery.php';
class Probe{
    use LCNClimateCoolingDiscovery;
    public const LCN_VALUE_MODULE_ID=VALUE;
    public array $properties=['AutoCoolingPolarityConfirmed'=>true,'AutoCoolingOpenRelayOn'=>true];
    public function ReadPropertyBoolean(string $key): bool{return (bool)($this->properties[$key]??false);}
    private function IsOperationalLCNModule(int $id):bool{return IPS_InstanceExists($id)&&IPS_GetInstance($id)['InstanceStatus']===102;}
    public function go():?array{return $this->AutoCoolingReadOnlyDiscovery(['SendModule'=>11,'TargetVariable'=>10000]);}
}
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$p=new Probe();$m=$p->go();
check(is_array($m)&&$m['RelayModule']===92&&$m['RelayUnit']===700&&$m['RelayChannel']===1,'correct native link');
check($m['UnlockedVariable']===10001&&$m['RelayStatusVariable']===7001,'correct feedback');
check($m['RegulatorIndex']===2&&$m['OpenRelayOn']===true&&$m['PolarityConfirmed'],'verified S1 regulator & polarity');
$p->properties['AutoCoolingPolarityConfirmed']=false;
check($p->go()===null,'unconfirmed polarity never starts direct');
$p->properties['AutoCoolingPolarityConfirmed']=true;
$GLOBALS['instances'][701]=$GLOBALS['instances'][700];
$GLOBALS['configs'][701]=$GLOBALS['configs'][700];
check($p->go()===null,'duplicate relay units are rejected');
unset($GLOBALS['instances'][701],$GLOBALS['configs'][701]);
$GLOBALS['configs'][92]['Segment']=1;
check($p->go()===null,'wrong segment not matched');
$GLOBALS['configs'][92]['Segment']=0;
$GLOBALS['instances'][92]['ConnectionID']=501;
check($p->go()===null,'wrong gateway not matched');
$GLOBALS['instances'][92]['ConnectionID']=500;
$GLOBALS['configs'][700]['Unit']=0;
check($p->go()===null,'output must not be treated as relay');
$GLOBALS['configs'][700]['Unit']=2;
$GLOBALS['variables'][7001]['Name']='Unbekannt';
check($p->go()===null,'unknown feedback name fails closed');
$GLOBALS['variables'][7001]['Name']='Status';
$GLOBALS['configs'][11]['Target']=99;
check($p->go()===null,'unknown source hardware must not be assigned');
$GLOBALS['configs'][11]['Target']=11;
check($p->go()!==null,'valid native mapping restored');
echo "PASS: 10 native hardware-mapping discovery safety cases\n";
