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
function IPS_GetObject(int $id): array { return ['ObjectIdent'=>$GLOBALS['variables'][$id]['Ident']??'']; }
$GLOBALS['created'] = [];
$GLOBALS['commands'] = [];
function IPS_SemaphoreEnter(string $name, int $timeout): bool{return true;}
function IPS_SemaphoreLeave(string $name):void{}
function IPS_CreateInstance(string $guid):int{
    $id=701+count($GLOBALS['created']);
    $GLOBALS['instances'][$id]=['ConnectionID'=>0,'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>$guid]];
    $GLOBALS['configs'][$id]=['Unit'=>0,'Channel'=>0];
    $GLOBALS['created'][]=$id;
    return $id;
}
function IPS_ConnectInstance(int $id,int $parent):bool{$GLOBALS['instances'][$id]['ConnectionID']=$parent;return true;}
function IPS_SetProperty(int $id,string $name,mixed $value):bool{$GLOBALS['configs'][$id][$name]=$value;return true;}
function IPS_SetParent(int $id,int $parent):bool{return true;}
function IPS_SetName(int $id,string $name):bool{return true;}
function IPS_ApplyChanges(int $id):bool{return true;}
function IPS_DeleteInstance(int $id):bool{unset($GLOBALS['instances'][$id],$GLOBALS['configs'][$id]);return true;}
require __DIR__.'/../LCNClimateControl/CoolingDiscovery.php';
class Probe{
    use LCNClimateCoolingDiscovery;
    public const LCN_VALUE_MODULE_ID=VALUE;
    public const MODE_HEATING=0;
    public int $InstanceID=900;
    public int $mode=0;
    public bool $busy=false;
    public array $locks=[];
    public function GetMode():int{return $this->mode;}
    public function IsBusy():bool{return $this->busy;}
    public function ReadJsonAttribute(string $key):array{return $this->locks; }
    public function GetRuntimeRooms():array{
        return [['Name'=>'EG Buero','SendModule'=>11,'TargetVariable'=>10000]];
    }
    public function setup():string{return $this->PrepareCoolingRelayUnits();}
    public array $properties=['AutoCoolingPolarityConfirmed'=>true,'AutoCoolingOpenRelayOn'=>true];
    public function ReadPropertyBoolean(string $key): bool{return (bool)($this->properties[$key]??false);}
    private function IsOperationalLCNModule(int $id):bool{return IPS_InstanceExists($id)&&IPS_GetInstance($id)['InstanceStatus']===102;}
    public function go():?array{return $this->AutoCoolingReadOnlyDiscovery(['SendModule'=>11,'TargetVariable'=>10000]);}
    public function reason():string{return $this->AutoCoolingDiscoveryReason(['SendModule'=>11,'TargetVariable'=>10000]);}
}
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$p=new Probe();$m=$p->go();
check(is_array($m)&&$m['RelayModule']===92&&$m['RelayUnit']===700&&$m['RelayChannel']===1,'correct native link');
check($m['UnlockedVariable']===10001&&$m['RelayStatusVariable']===7001,'correct feedback');
check($m['RegulatorIndex']===2&&$m['OpenRelayOn']===true&&$m['PolarityConfirmed'],'verified S1 regulator & polarity');
$p->properties['AutoCoolingPolarityConfirmed']=false;
check($p->go()!==null,'confirmed valve wiring must not require old manual checkbox');
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
check($p->go()===null,'unknown feedback name and ident fails closed');
$GLOBALS['variables'][7001]['Ident']='Status';
check($p->go()!==null,'technical ident survives renamed relay label');
$GLOBALS['variables'][7001]['Name']='Status';
$GLOBALS['variables'][10001]['Name']='Umbenannt';
$GLOBALS['variables'][10001]['Ident']='Entriegelt';
check($p->go()!==null,'technical ident survives renamed unlocked label');
$GLOBALS['variables'][10001]['Name']='Entriegelt';
$GLOBALS['configs'][11]['Target']=99;
check($p->go()===null,'unknown source hardware must not be assigned');
$GLOBALS['configs'][11]['Target']=11;
check($p->go()!==null,'valid native mapping restored');
// Granulare Diagnose MUSS die Ursache nennen, ohne einen Befehl zu senden.
check(str_contains($p->reason(),'Alle Symcon-Instanzen gefunden'), 'successful mapping diagnostic');
$GLOBALS['configs'][92]['Segment']=1;
check(str_contains($p->reason(),'Relaismodul M92'), 'missing relay module diagnostic');
$GLOBALS['configs'][92]['Segment']=0;
$GLOBALS['configs'][700]['Unit']=1;
check(str_contains($p->reason(),'Unit=1'), 'unexpected LCN Unit type diagnostic');
$GLOBALS['configs'][700]['Unit']=2;
unset($GLOBALS['instances'][700],$GLOBALS['configs'][700]);
check(str_contains($p->reason(),'alle vorhandenen Units an Relaismodul #92: KEINE'), 'missing relay Unit diagnostic');
$GLOBALS['instances'][700]=['ConnectionID'=>92,'InstanceStatus'=>102,'ModuleInfo'=>['ModuleID'=>UNIT]];
$GLOBALS['configs'][700]=['Unit'=>2,'Channel'=>1];
$GLOBALS['variables'][7001]['Name']='Andere';
unset($GLOBALS['variables'][7001]['Ident']);
check(str_contains($p->reason(),'Boolean-Rueckmeldung Status'), 'missing relay status diagnostic');
$GLOBALS['variables'][7001]['Name']='Status';
$GLOBALS['variables'][10001]['Name']='Andere';
unset($GLOBALS['variables'][10001]['Ident']);
check(str_contains($p->reason(),'Entriegelt'), 'missing unlock feedback diagnostic');
$GLOBALS['variables'][10001]['Name']='Entriegelt';
check($p->go()!==null, 'read-only diagnostic leaves good discovery working');
// One-click setup: no switch commands, strictly idempotent and gated.
unset($GLOBALS['instances'][700],$GLOBALS['configs'][700]);
$p->mode=1;
check(str_contains($p->setup(),'ABBRUCH')&&$GLOBALS['created']===[],
    'cooling-mode setup must be blocked');
$p->mode=0;$p->busy=true;
check(str_contains($p->setup(),'ABBRUCH')&&$GLOBALS['created']===[],
    'busy setup must be blocked');
$p->busy=false;$p->locks=['10000'=>'locked'];
check(str_contains($p->setup(),'ABBRUCH')&&$GLOBALS['created']===[],
    'locked-controller setup must be blocked');
$p->locks=[];
$out=$p->setup();
check(count($GLOBALS['created'])===1 && $GLOBALS['instances'][701]['ConnectionID']===92
    && $GLOBALS['configs'][701]['Unit']===2
    && $GLOBALS['configs'][701]['Channel']===1,
    'missing relay Unit must be linked to exactly its own module+channel');
check(str_contains($out,'neue Symcon-Unit #701')&&$p->go()!==null,
    'new Unit must be discoverable afterwards');
$p->setup();
check(count($GLOBALS['created'])===1,
    'second setup must not create duplicates');
check($GLOBALS['commands']===[], 'setup may not emit a bus switch command');
echo "PASS: 24 native discovery and safe, idempotent unit-provisioning tests\n";
