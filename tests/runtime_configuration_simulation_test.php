<?php
declare(strict_types=1);

// Echte Methoden des produktiven Moduls, aber IP-Symcon/LCN vollständig gemockt.
// Wichtig: Keine Verbindung zum LCN-Bus und kein Sendebefehl.
const VARIABLETYPE_FLOAT = 2;
const KR_READY = 10103;
class IPSModuleStrict {
    public int $InstanceID = 123;
    public array $buffers = ['ConfigurationValid'=>'1', 'ConfigurationErrors'=>'[]', 'Queue'=>'[]', 'CurrentJob'=>'', 'RoomErrors'=>'{}', 'PendingMode'=>''];
    public array $debug = [];
    public array $sent = [];
    public int $timerInterval = -1;
    public function GetBuffer(string $name): string { return $this->buffers[$name] ?? ''; }
    public function SetBuffer(string $name, string $value): void { $this->buffers[$name] = $value; }
    public function SendDebug(string $type, string $message, int $level): void { $this->debug[] = "$type $message"; }
    public function SetTimerInterval(string $name, int $interval): void { $this->timerInterval = $interval; }
    public function GetValue(string $ident): mixed { return 0; }
    public function UpdateVisualizationValue(string $message): void { $this->sent[] = $message; }
    public function ReadAttributeString(string $ident): string { return ''; }
}
$instances = [501=>['ModuleInfo'=>['ModuleID'=>'{0E31FED6-E465-4621-95D4-AAF2683C41EC}', 'ModuleType'=>2], 'InstanceStatus'=>102],
 601=>['ModuleInfo'=>['ModuleID'=>'{0102BDC9-3B85-4A11-968D-7D314DA07C06}', 'ModuleType'=>3], 'ConnectionID'=>501, 'InstanceStatus'=>102]];
$variables = [701=>['VariableType'=>VARIABLETYPE_FLOAT],801=>['VariableType'=>VARIABLETYPE_FLOAT]];
$parents=[701=>601];$configs=[601=>'{"S1TargetEnabled":true}'];$sendCount=0;
function IPS_InstanceExists(int $id): bool {global $instances;return isset($instances[$id]);}
function IPS_GetInstance(int $id): array {global $instances;return $instances[$id];}
function IPS_VariableExists(int $id): bool {global $variables;return isset($variables[$id]);}
function IPS_GetVariable(int $id): array {global $variables;return $variables[$id];}
function IPS_GetParent(int $id): int {global $parents;return $parents[$id]??0;}
function IPS_GetConfiguration(int $id): string {global $configs;return $configs[$id]??'{}';}
function IPS_SemaphoreEnter(string $name, int $wait): bool {return true;}
function IPS_SemaphoreLeave(string $name): void {}
function IPS_GetKernelRunlevel(): int {return KR_READY;}
function IPS_FunctionExists(string $name): bool {return true;}
function LCN_SendCommand(int $id,string $command,string $data): bool {global $sendCount;$sendCount++;return true;}
require dirname(__DIR__).'/LCNClimateControl/module.php';
function check(bool $b,string $reason): void {if (!$b) throw new RuntimeException($reason);}
$mod=(new ReflectionClass(LCNClimateControl::class))->newInstanceWithoutConstructor();
$validate=(new ReflectionClass(LCNClimateControl::class))->getMethod('ValidateRoom');
$room=['Enabled'=>true,'Name'=>'EG Bad','SendModule'=>501,'TargetVariable'=>701,
'TemperatureVariable'=>801,'Table'=>'A','UpKey'=>7,'DownKey'=>8];
check($validate->invoke($mod,$room,true),'valides LCN-Modul plus S1Target muss akzeptiert werden');
$scenarios=0;
$bad=$room;$bad['TargetVariable']=999;
check(!$validate->invoke($mod,$bad,true),'fehlendes S1Target nicht erkannt');$scenarios++;
$instances[601]['ConnectionID']=999;
check(!$validate->invoke($mod,$room,true),'falsche ConnectionID nicht erkannt');$scenarios++;
$instances[601]['ConnectionID']=501;
$configs[601]='{"S1TargetEnabled":false}';
check(!$validate->invoke($mod,$room,true),'deaktiviertes S1Target nicht erkannt');$scenarios++;
$configs[601]='{"S1TargetEnabled":true}';
$instances[501]['ModuleInfo']['ModuleType']=3;
check(!$validate->invoke($mod,$room,true),'falscher Splittertyp nicht erkannt');$scenarios++;
$instances[501]['ModuleInfo']['ModuleType']=2;
$bad=$room;$bad['UpKey']=$bad['DownKey'];
check(!$validate->invoke($mod,$bad,true),'identische TS-Tasten nicht erkannt');$scenarios++;
$bad=$room;$bad['TemperatureVariable']=222;
check(!$validate->invoke($mod,$bad,true),'fehlende Temperatur nicht erkannt');$scenarios++;
$variables[801]['VariableType']=1;
check(!$validate->invoke($mod,$room,true),'falscher Temperaturtyp nicht erkannt');$scenarios++;
$variables[801]['VariableType']=VARIABLETYPE_FLOAT;
check($validate->invoke($mod,$room,true),'reparierte Konfiguration nicht akzeptiert');
$errors=json_decode($mod->GetBuffer('ConfigurationErrors'),true);
check(count($errors)>=7,'fehlende raumspezifische Fehlermeldungen');
check(str_contains(implode(' ', $errors),'EG Bad'),'Fehlermeldung ohne Raumname');
$mod->SetBuffer('ConfigurationValid','0');
$mod->SetBuffer('Queue','[{"TargetVariable":701}]');
$mod->Worker();
check($sendCount===0 && $mod->timerInterval===0,'Worker hat trotz ungültiger Konfiguration nicht blockiert');
$mod->RequestAction('Mode',1);
check($sendCount===0,'RequestAction sendet trotz ungültiger Konfiguration');
check(count($mod->sent)>=1,'Frontend erhält keinen Konfigurationsstatus');
check(str_contains(end($mod->sent),'Konfiguration fehlerhaft'),'Frontend meldet Konfigurationsfehler nicht');
echo "PASS: $scenarios ungültige Szenarien, valider Pfad, Worker-Sperre, Modalvermeidung und Frontend-Status\n";
