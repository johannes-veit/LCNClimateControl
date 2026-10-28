<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/LCNClimateControl/DewPointGuard.php';
if (!defined('VARIABLETYPE_FLOAT')) define('VARIABLETYPE_FLOAT', 2);
if (!defined('VARIABLETYPE_INTEGER')) define('VARIABLETYPE_INTEGER', 1);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . "\n");
        exit(1);
    }
}

$dew = LCNClimateDewPointLogic::dewPoint(24.0, 60.0);
check($dew !== null && $dew > 15.0 && $dew < 17.0, 'Magnus 24C/60RH');
check(LCNClimateDewPointLogic::dewPoint(24.0, 0.0) === null, 'invalid humidity');
check(!LCNClimateDewPointLogic::allow(17.5, 16.0, true), 'stop at 1.5 K inclusive');
check(LCNClimateDewPointLogic::allow(17.51, 16.0, true), 'run above 1.5 K');
check(!LCNClimateDewPointLogic::allow(18.0, 16.0, false), 'do not restart at exactly 2.0 K');
check(LCNClimateDewPointLogic::allow(18.01, 16.0, false), 'restart above 2.0 K');
check(!LCNClimateDewPointLogic::allow(null, 16.0, true), 'missing water stops');

$GLOBALS['ewt_data'] = [
    20510 => ['v'=>19.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT],
    12812 => ['v'=>24.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT], // EG Bad T
    53361 => ['v'=>60.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT], // EG Bad RH
    36518 => ['v'=>22.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT], // OG Bad T
    28802 => ['v'=>50.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT], // OG Bad RH
];
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['ewt_data'][$id]); }
function IPS_GetVariable(int $id): array
{
    return [
        'VariableUpdated'=>$GLOBALS['ewt_data'][$id]['at'],
        'VariableType'=>$GLOBALS['ewt_data'][$id]['t']
    ];
}
function GetValue(int $id): mixed { return $GLOBALS['ewt_data'][$id]['v']; }
function IPS_SemaphoreEnter(string $key, int $ms): bool { return true; }
function IPS_SemaphoreLeave(string $key): void {}

final class EwtSimulation
{
    use LCNClimateDewPointGuard;
    public int $InstanceID = 200;
    public array $p = [
        'DewPointAutoPumpEnabled'=>true,
        'DewPointWaterVariable'=>20510,
        'DewPointMaxAgeSeconds'=>900, // old 0.5.6 legacy default auto-migration
        'DewPointMarginK'=>1.5,
        'DewPointHysteresisK'=>0.5,
        'DewPointPumpIP'=>'192.168.103.67',
        'DewPointPushWebFront'=>0
    ];
    public array $rooms = [
        ['Enabled'=>true,'Name'=>'EG Bad','TemperatureVariable'=>12812,'HumidityVariable'=>0],
        ['Enabled'=>true,'Name'=>'OG Bad','TemperatureVariable'=>36518,'HumidityVariable'=>0],
    ];
    public array $attr = ['DewPointGuardNotifyState'=>''];
    public array $values=[];
    public array $rpcCalls=[];
    public bool $remoteOn = false;
    public bool $remoteOnline = true;
    public bool $ignoreCommands = false;

    public function ReadPropertyBoolean(string $key): bool { return (bool) ($this->p[$key] ?? false); }
    public function ReadPropertyInteger(string $key): int { return (int) ($this->p[$key] ?? 0); }
    public function ReadPropertyFloat(string $key): float { return (float) ($this->p[$key] ?? 0); }
    public function ReadPropertyString(string $key): string { return (string) ($this->p[$key] ?? ''); }
    public function ReadAttributeString(string $key): string { return (string) ($this->attr[$key] ?? ''); }
    public function WriteAttributeString(string $key, string $value): void { $this->attr[$key] = $value; }
    public function SetValueIfChanged(string $key, mixed $v): void { $this->values[$key] = $v; }
    public function GetRooms(): array { return $this->rooms; }
    public function SendDebug(string $kind, string $message, int $level): void {}
    public function snapshot(): array { return $this->DewPointGuardSnapshot(); }

    protected function DewPointPumpRpc(string $method, array $params = []): array
    {
        $this->rpcCalls[] = [$method,$params];
        if (!$this->remoteOnline) throw new RuntimeException('simuliert: Shelly ohne Strom/WLAN');
        if ($method === 'Switch.GetStatus') return ['output'=>$this->remoteOn];
        if ($method === 'Switch.Set') {
            $before = $this->remoteOn;
            if (!$this->ignoreCommands) $this->remoteOn = $params['on'] === 'true';
            return ['was_on'=>$before];
        }
        throw new RuntimeException('unbekannter RPC-Aufruf');
    }
}
$sim=new EwtSimulation();
check($sim->snapshot()['ok'] === true, 'known LCN humidity resolved without user configuration');
check($sim->snapshot()['room'] === 'EG Bad', 'highest dewpoint room selected');
$sim->DewPointGuardTick();
check($sim->remoteOn, 'automatic start when safe, no start delay');
check(str_contains($sim->values['DewPointGuardStatus'],'bestaetigt'), 'feedback checked');
check($sim->rpcCalls[1][0] === 'Switch.Set', 'command after status read');
check($sim->rpcCalls[1][1]['toggle_after'] === 40, 'local 40-second lease installed');

$dew=$sim->snapshot()['dew'];
$GLOBALS['ewt_data'][20510]['v'] = $dew + 1.5;
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'stop at 1.5 K threshold');
$GLOBALS['ewt_data'][20510]['v'] = $dew + 1.8;
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'remain off in 0.5 K hysteresis');
$GLOBALS['ewt_data'][20510]['v'] = $dew + 2.1;
$sim->DewPointGuardTick();
check($sim->remoteOn, 'restart immediately above 2.0 K');

unset($GLOBALS['ewt_data'][53361]);
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'missing room humidity stops');
$GLOBALS['ewt_data'][53361] = ['v'=>60.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT];
$GLOBALS['ewt_data'][20510]['v'] = 19.0;
$sim->DewPointGuardTick();
check($sim->remoteOn, 'recover without manual acknowledgement');

$GLOBALS['ewt_data'][20510]['v'] = -127;
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'faulty DS18B20 measurement stops');
$GLOBALS['ewt_data'][20510]['v'] = 19;
$GLOBALS['ewt_data'][20510]['at'] = time() - 901;
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'water temperature older than 15 min stops');
$GLOBALS['ewt_data'][20510]['at'] = time();
$GLOBALS['ewt_data'][53361]['at'] = time() - 3600;
check($sim->snapshot()['ok'] === true, 'unchanged LCN RH valid at 1 hour, legacy setting auto-migrates');
$GLOBALS['ewt_data'][53361]['at'] = time() - 43201;
check($sim->snapshot()['ok'] === false, 'LCN RH older than 12 h stops');
$GLOBALS['ewt_data'][53361]['at'] = time();

$sim->remoteOnline = false;
$sim->DewPointGuardTick();
check($sim->attr['DewPointGuardState'] === 'UNKNOWN', 'unreachable Shelly not claimed OFF');
check(str_contains($sim->values['DewPointGuardStatus'],'NICHT BESTAETIGT'), 'explicit connection failure');
$sim->remoteOnline = true;
$sim->ignoreCommands = true;
$sim->remoteOn = false;
$sim->DewPointGuardTick();
check($sim->attr['DewPointGuardState'] === 'UNKNOWN', 'wrong Shelly feedback detected');
$sim->ignoreCommands = false;
$sim->DewPointGuardTick();
check($sim->remoteOn, 'recover after shelly feedback error');

$sim->p['DewPointAutoPumpEnabled'] = false;
$sim->rpcCalls=[];
$sim->DewPointGuardTick();
check(count($sim->rpcCalls) === 0, 'disabled guard does not touch pump');
echo "EWT automatic guard: 23 simulation assertions OK\n";
