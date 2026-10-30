<?php
declare(strict_types=1);
const VARIABLETYPE_BOOLEAN = 0;
$GLOBALS['feedback']=[
    101=>['VariableType'=>0,'VariableUpdated'=>time()-10,'Value'=>false],
    102=>['VariableType'=>0,'VariableUpdated'=>time()-10,'Value'=>true],
    201=>['VariableType'=>0,'VariableUpdated'=>time()-390,'Value'=>true]
];
$GLOBALS['writes']=[];
function IPS_VariableExists(int $id):bool{return isset($GLOBALS['feedback'][$id]) || $id===999;}
function IPS_GetVariable(int $id):array{return $GLOBALS['feedback'][$id];}
function GetValue(int $id):mixed{return $GLOBALS['feedback'][$id]['Value']??null;}
// Global write intentionally forbidden for IPSModuleStrict: the test
// must fail if production code reintroduces SetValueString().
function SetValueString(int $id,string $value):void{
    throw new RuntimeException('IPSModuleStrict eigene Variable ist schreibgeschuetzt');
}
require __DIR__.'/../LCNClimateControl/ChatGPTDiagnostic.php';
class DiagnosticProbe {
    use LCNClimateChatGPTDiagnostic;
    public const MODE_HEATING=0;
    public int $InstanceID=17597;
    public bool $busy=false;
    public int $mode=0;
    public array $locks=[];
    public array $pendingCooling=[];
    private function GetPendingCoolingRequests():array{return $this->pendingCooling;}
    public array $errors=[];
    public bool $throwOnFallback = false;
    public bool $manualApproval = false;
    public int $safetyVar = 0;
    public array $verified = [];
    private function GetMode():int{return $this->mode;}
    private function GetRuntimeRooms():array{return [
        ['Name'=>'EG Büro','TargetVariable'=>100,'SendModule'=>37617,'TemperatureVariable'=>400],
        ['Name'=>'OG Bad','TargetVariable'=>200,'SendModule'=>32128,'TemperatureVariable'=>401]
    ];}
    private function ReadJsonAttribute(string $name):array{
        return $name==='DirectCoolingLocks'?$this->locks:[];
    }
    private function DirectCoolingFallbacks():array{if($this->throwOnFallback){throw new RuntimeException('Demo-Diagnosefehler');}return ['200'=>['kind'=>'legacy','reason'=>'Sperrfeedback zu alt']];}
    private function M11ProbeSession():array{return [];}
    private function CoolingCommissionVerified(array $room):bool{
        return (bool) ($this->verified[(int)$room['TargetVariable']] ?? false);
    }
    private function CoolingCommissionRelayRoomVerified(array $room):bool{return false;}
    private function CoolingCommissionReadinessErrors():array{return ['Hardwarepruefung ausstehend'];}
    private function DirectCoolingLockActionID(array $room,array $mapping):int {
        return (int) $room['TargetVariable'] === 100 ? 101 : 0;
    }
    private function GetCurrentJob():?array{return null;}
    private function GetQueue():array{return [];}
    private function IsBusy():bool{return $this->busy;}
    private function ReadPropertyInteger(string $name):int{
        return $name==='CoolingSafetyVariable' ? $this->safetyVar : 0;
    }
    private function ReadPropertyBoolean(string $name):bool{
        return $name==='ManualCoolingApproval' && $this->manualApproval;
    }
    private function UsesDirectCooling():bool{return true;}
    private function DirectCoolingMapping(int $id):?array{
        return $id===100 ? [
            'RelayModule'=>36930,'RelayUnit'=>47971,'RelayChannel'=>1,
            'RelayStatusVariable'=>102
        ] : null;
    }
    private function DirectCoolingEligibility(array $room):string{
        return (int)$room['TargetVariable']===100?'':'Sperrrueckmeldung nicht aktualisiert';
    }
    private function AutoCoolingLockFeedbackForRoom(array $room):?array{
        $id=(int)$room['TargetVariable'];
        return $id===100 ? ['id'=>101,'lockedWhenTrue'=>true,'type'=>'S1TargetLock']
            : ['id'=>201,'lockedWhenTrue'=>true,'type'=>'S1TargetLock'];
    }
    private function AutoCoolingDiscoveryReason(array $room):string{return 'Status fehlt';}
    private function ReadFloatVariable(int $id):?float{return $id===400?21.2:22.0;}
    private function ReadTarget(int $id):?float{return $id===100?22.0:24.0;}
    private function GetStoredCoolingState(int $id):bool{return $id===200;}
    private function GetRoomError(int $id):string{return $this->errors[$id]??'';}
    private function ReadAttributeString(string $name):string{return '';}
    private function GetIDForIdent(string $ident):int{return $ident==='ChatGPTDiagnostic'?999:0;}
    private function SetValue(string $ident, mixed $value):void{
        if ($ident !== 'ChatGPTDiagnostic') {
            throw new RuntimeException('unexpected module variable Ident');
        }
        $GLOBALS['writes'][]=[$ident,(string)$value];
    }
    private function SendDebug(string $n,string $message,int $format):void{}
}
function ensure(bool $ok,string $message):void{
    if(!$ok){throw new RuntimeException($message);}
}
$p=new DiagnosticProbe();
$text=$p->BuildChatGPTDiagnosticReport();
ensure(str_contains($text,'SENDEN AN CHATGPT') &&
    str_contains($text,'Symcon-Modulinstanz: #17597'), 'report header and module');
ensure(str_contains($text,'Software-Kuehlfreigabe: keine externe Boolean, manuelle Freigabe aus'),
    'external Boolean absent and manual approval disabled is clearly blocked');
$p->manualApproval = true;
$text = $p->BuildChatGPTDiagnosticReport();
ensure(str_contains($text,'manuelle Freigabe konfiguriert'),
    'manual software approval is accurately reported without implying physical mode');
$p->manualApproval = false;
$p->safetyVar = 102;
$text = $p->BuildChatGPTDiagnosticReport();
ensure(str_contains($text,'Software-Kuehlfreigabe: Boolean #102 = TRUE'),
    'valid external Boolean is reported TRUE');
$p->safetyVar = 400;
$GLOBALS['feedback'][400] = ['VariableType'=>2,'VariableUpdated'=>time(),
    'Value'=>1.0];
$text = $p->BuildChatGPTDiagnosticReport();
ensure(str_contains($text,'Boolean #400 = UNGUELTIGER TYP'),
    'a FLOAT must never be presented as a valid external Boolean');
$p->safetyVar = 0;
$p->verified=[100=>true, 200=>true];
$text = $p->BuildChatGPTDiagnosticReport();
ensure(str_contains($text,'S1-Einzeltests bestanden: 2') &&
    str_contains($text,'S1-Einzeltests ausstehend: 0') &&
    !str_contains($text,'S1-Sperr-/Freigabetest noch ausstehend'),
    'all individual S1 commissioning certificates count, not only historic M11');
ensure(str_contains($text,'EG Büro') && str_contains($text,'OG Bad') &&
    str_contains($text,'Relais-Modul #36930') && str_contains($text,'Unit #47971'),
    'all rooms and relay details');
ensure(str_contains($text,'S1TargetLock #101: FREI') &&
    str_contains($text,'S1TargetLock #201: GESPERRT'),
    'correct lock-feedback polarity');
ensure(str_contains($text,'VERALTET') &&
    str_contains($text,'alte Steuerung: 1') &&
    str_contains($text,'Regler-Sperrung erlaubt'),
    'staleness, fallback and unknown LCN-PRO permission');
ensure(str_contains($text,'KEINE LCN-Befehle gesendet'),
    'state passive no active bus tests');
$p->UpdateChatGPTDiagnostic();
ensure(count($GLOBALS['writes'])===1 &&
    $GLOBALS['writes'][0][0]==='ChatGPTDiagnostic' &&
    str_contains($GLOBALS['writes'][0][1],'OG Bad'),
    'IPSModuleStrict must write one diagnostic string through internal SetValue');
$p->mode=1;
$p->locks=['100'=>'uncertain'];
$p->errors=[100=>'Freigabe nicht bestaetigt'];
$text=$p->BuildChatGPTDiagnosticReport();
ensure(str_contains($text,'KUEHLEN') &&
    str_contains($text,'GESTOPPT/PRUEFEN') &&
    str_contains($text,'FEHLER: Freigabe nicht bestaetigt'),
    'cooling fault and persistent uncertain lock');
$GLOBALS['writes']=[];
$p->throwOnFallback=true;
$p->UpdateChatGPTDiagnostic();
ensure(count($GLOBALS['writes'])===1 &&
    $GLOBALS['writes'][0][0]==='ChatGPTDiagnostic' &&
    str_contains($GLOBALS['writes'][0][1],'DIAGNOSEFEHLER: Demo-Diagnosefehler'),
    'failed generation must write visible error, not leave variable at Never');
$p->throwOnFallback=false;
$p->pendingCooling=['200'=>false];
$text=$p->BuildChatGPTDiagnosticReport();
ensure(str_contains($text,'NEUER SCHALTWUNSCH VORGEMERKT: NICHT KUEHLEN') &&
    str_contains($text,'Kuehlzustand bestaetigt AN'),
    'pending OFF and electrically confirmed previous ON must be shown separately');
echo "PASS: strict diagnostic writes, visible failures and pending cooling intent\n";
