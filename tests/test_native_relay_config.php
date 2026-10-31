<?php
declare(strict_types=1);
require __DIR__ . '/../LCNClimateControl/DirectCooling.php';

// No Symcon installation or LCN bus: real relay method with strict mocks.
$GLOBALS['relayUnit'] = ['ConnectionID'=>100, 'InstanceStatus'=>102,
    'ModuleInfo'=>['ModuleID'=>'{2D871359-14D8-493F-9B01-26432E3A710F}']];
$GLOBALS['relayProperties'] = ['Unit'=>2,'Channel'=>1];
$GLOBALS['jsonFailure'] = false;
$GLOBALS['propertyFailure'] = false;
$GLOBALS['relayCalls'] = [];
function IPS_FunctionExists(string $name): bool { return function_exists($name); }
function IPS_InstanceExists(int $id): bool { return $id === 300; }
function IPS_GetInstance(int $id): array { return $GLOBALS['relayUnit']; }
function IPS_GetConfiguration(int $id): string|false {
    return $GLOBALS['jsonFailure'] ? false : json_encode($GLOBALS['relayProperties']);
}
function IPS_GetProperty(int $id,string $name): mixed {
    if ($GLOBALS['propertyFailure']) { throw new RuntimeException('property offline'); }
    return $GLOBALS['relayProperties'][$name] ?? null;
}
function LCN_SwitchRelay(int $id,bool $on): bool {
    $GLOBALS['relayCalls'][] = [$id,$on];
    return true;
}
function IPS_SemaphoreEnter(string $name,int $timeout): bool { return true; }
function IPS_SemaphoreLeave(string $name): void {}
final class RelayConfigProbe {
    use LCNDirectCooling;
    private const GLOBAL_LCN_SEND_SEMAPHORE='MOCK';
    private function IsOperationalLCNModule(int $id): bool { return $id === 100; }
    private function ReleaseLCNBusSlot(): void {}
    private function SendDebug(string $name,string $msg,int $level): void {}
    public function relay(int $channel=1,bool $open=true): bool {
        return $this->DirectCoolingRelaySet([
            'RelayModule'=>100,'RelayUnit'=>300,'RelayChannel'=>$channel,
            'OpenRelayOn'=>true
        ],$open);
    }
}
function must(bool $ok,string $what): void {
    if (!$ok) { throw new RuntimeException($what); }
}
$p=new RelayConfigProbe();
must($p->relay(),'baseline switch works');
$GLOBALS['jsonFailure']=true;
must($p->relay(),'false JSON but matching native Unit properties still works');
$sent=count($GLOBALS['relayCalls']);
$GLOBALS['relayProperties']['Unit']=1;
must(!$p->relay(),'wrong Unit must block relay');
$GLOBALS['relayProperties']['Unit']=2;
$GLOBALS['relayProperties']['Channel']=2;
must(!$p->relay(),'wrong native relay Channel must block relay');
$GLOBALS['relayProperties']['Channel']=1;
$GLOBALS['propertyFailure']=true;
must(!$p->relay(),'both JSON and properties missing must block relay');
$GLOBALS['propertyFailure']=false;
$GLOBALS['relayUnit']['ConnectionID']=101;
must(!$p->relay(),'wrong LCN module connection must block');
$GLOBALS['relayUnit']['ConnectionID']=100;
$GLOBALS['relayUnit']['ModuleInfo']['ModuleID']='{FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF}';
must(!$p->relay(),'non-native Unit GUID must block');
$GLOBALS['relayUnit']['ModuleInfo']['ModuleID']='{2D871359-14D8-493F-9B01-26432E3A710F}';
must(!$p->relay(0),'zero relay channel must block');
must(!$p->relay(9),'channel beyond 8 must block');
must(count($GLOBALS['relayCalls'])===$sent,
    'no real output command may pass failed native mapping validation');
must($p->relay(1,false),'valid restored relay can be safely switched off');
must($GLOBALS['relayCalls'][count($GLOBALS['relayCalls'])-1]===[300,false],
    'correct native relay off polarity');
echo "PASS: native relay config false fallback, exact GUID, Unit, parent, mask and no failed-command leakage\n";
