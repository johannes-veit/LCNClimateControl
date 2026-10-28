<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/LCNClimateControl/DewPointGuard.php';
if (!defined('VARIABLETYPE_FLOAT')) define('VARIABLETYPE_FLOAT', 2);
if (!defined('VARIABLETYPE_INTEGER')) define('VARIABLETYPE_INTEGER', 1);

function check(bool $condition, string $message): void
{
    $GLOBALS['assertions'] = ($GLOBALS['assertions'] ?? 0) + 1;
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
    public bool $ignoreNextCommand = false;

    public function ReadPropertyBoolean(string $key): bool { return (bool) ($this->p[$key] ?? false); }
    public function ReadPropertyInteger(string $key): int { return (int) ($this->p[$key] ?? 0); }
    public function ReadPropertyFloat(string $key): float { return (float) ($this->p[$key] ?? 0); }
    public function ReadPropertyString(string $key): string { return (string) ($this->p[$key] ?? ''); }
    public function ReadAttributeString(string $key): string { return (string) ($this->attr[$key] ?? ''); }
    public function WriteAttributeString(string $key, string $value): void { $this->attr[$key] = $value; }
    public function ReadAttributeInteger(string $key): int { return (int) ($this->attr[$key] ?? 0); }
    public function WriteAttributeInteger(string $key, int $value): void { $this->attr[$key] = $value; }
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
            if ($this->ignoreNextCommand) $this->ignoreNextCommand = false;
            elseif (!$this->ignoreCommands) $this->remoteOn = $params['on'] === 'true';
            return ['was_on'=>$before];
        }
        throw new RuntimeException('unbekannter RPC-Aufruf');
    }
}
$sim=new EwtSimulation();
check($sim->snapshot()['ok'] === true, 'LCN humidity IDs mapped without manual configuration');
check($sim->snapshot()['room'] === 'EG Bad', 'highest of all room dewpoints selected');
$dew = $sim->snapshot()['dew'];

// Initial output OFF: even with safe water, restart is only allowed
// after 120 seconds of CONTINUOUS headroom.
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'no immediate initial start');
check($sim->attr['DewPointGuardSafeSince'] > 0, 'safe interval started');
check(str_contains($sim->values['DewPointGuardStatus'], 'Wiederanlauf in'), 'wait visible');
check(count(array_filter($sim->rpcCalls, fn($x) => $x[0] === 'Switch.Set')) === 0,
    'no unnecessary OFF command when already OFF');

$sim->attr['DewPointGuardSafeSince'] = time() - 119;
$sim->DewPointGuardTick();
check(!$sim->remoteOn, '119 s are insufficient for restart');
$sim->attr['DewPointGuardSafeSince'] = time() - 121;
$sim->DewPointGuardTick();
check($sim->remoteOn, 'safe 120-second period permits start');
check(str_contains($sim->values['DewPointGuardStatus'],'bestaetigt'), 'readback verified');
$onSets = array_values(array_filter($sim->rpcCalls, fn($x) => $x[0] === 'Switch.Set' && $x[1]['on'] === 'true'));
check(count($onSets) > 0 && $onSets[0][1]['toggle_after'] === 40, '40-second local Shelly auto-OFF lease');

// Running pump: no wait before STOP, 1.5 K boundary included.
$GLOBALS['ewt_data'][20510]['v'] = $dew + 1.5;
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'immediate stop at <= 1.5 K');
check($sim->attr['DewPointGuardLastStop'] > 0, 'stop timestamp stored');
$GLOBALS['ewt_data'][20510]['v'] = $dew + 1.8;
$sim->DewPointGuardTick();
check(!$sim->remoteOn && $sim->attr['DewPointGuardSafeSince'] === 0,
    'hysteresis prevents chatter between 1.5 K and 2.0 K');
$GLOBALS['ewt_data'][20510]['v'] = $dew + 2.1;
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'restart requires stable headroom');
$GLOBALS['ewt_data'][20510]['v'] = $dew + 1.8;
$sim->DewPointGuardTick();
check($sim->attr['DewPointGuardSafeSince'] === 0, 'unsafe dip resets two-minute wait');
$GLOBALS['ewt_data'][20510]['v'] = $dew + 2.1;
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'new period begins after dip');
$sim->attr['DewPointGuardSafeSince'] = time() - 121;
$sim->DewPointGuardTick();
check($sim->remoteOn, 'restart only after renewed stable interval');

// Failed sensors always stop and do not silently exclude a room.
unset($GLOBALS['ewt_data'][53361]);
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'missing LCN humidity stops');
$GLOBALS['ewt_data'][53361] = ['v'=>60.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT];
$GLOBALS['ewt_data'][20510]['v'] = 19.0;
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'restored sensor does not start immediately');
$sim->attr['DewPointGuardSafeSince'] = time()-121;
$sim->DewPointGuardTick();
check($sim->remoteOn, 'recovers automatically after stable safe readings');
$GLOBALS['ewt_data'][20510]['v'] = -127;
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'invalid DS18B20 water value stops');
$GLOBALS['ewt_data'][20510]['v'] = 19.0;
$GLOBALS['ewt_data'][20510]['at'] = time() - 901;
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'water value older than 15 min blocks start');
$GLOBALS['ewt_data'][20510]['at'] = time();
$GLOBALS['ewt_data'][53361]['at'] = time() - 3600;
check($sim->snapshot()['ok'] === true, 'unchanged LCN humidity valid at one hour');
$GLOBALS['ewt_data'][53361]['at'] = time() - 43201;
check($sim->snapshot()['ok'] === false, 'humidity older than 12 hours is stale');
$GLOBALS['ewt_data'][53361]['at'] = time();

// Simulate a long Symcon timer pause: previous stability must not
// count across a stopped watchdog or restart.
$sim->attr['DewPointGuardSafeSince'] = time() - 121;
$sim->attr['DewPointGuardLastRenew'] = time() - 50;
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'safety wait resets after watchdog gap');
check($sim->attr['DewPointGuardSafeSince'] >= time()-1, 'new wait begins');

// Missing Shelly -> UNKNOWN, never an unverified OFF confirmation.
$sim->remoteOnline = false;
$sim->DewPointGuardTick();
check($sim->attr['DewPointGuardState'] === 'UNKNOWN', 'unreachable Shelly state UNKNOWN');
check(str_contains($sim->values['DewPointGuardStatus'], 'NICHT BESTAETIGT'), 'reachability failure visible');
$sim->remoteOnline = true;
$sim->remoteOn = false;
$sim->attr['DewPointGuardLastRenew'] = time();
$sim->attr['DewPointGuardSafeSince'] = time()-121;
$sim->ignoreCommands = true;
$sim->rpcCalls = [];
$sim->DewPointGuardTick();
check($sim->attr['DewPointGuardState'] === 'UNKNOWN', 'ignored command is not confirmed');
check(count(array_filter($sim->rpcCalls, fn($x) => $x[0] === 'Switch.Set')) === 2,
    'wrong readback triggers exactly one retry');

// Transient lost first command: second attempt succeeds automatically.
$sim->ignoreCommands = false;
$sim->ignoreNextCommand = true;
$sim->attr['DewPointGuardLastRenew'] = time();
$sim->attr['DewPointGuardSafeSince'] = time()-121;
$sim->rpcCalls = [];
$sim->DewPointGuardTick();
check($sim->remoteOn && $sim->attr['DewPointGuardState'] === 'ON', 'single failed command recovered');
check(count(array_filter($sim->rpcCalls, fn($x) => $x[0] === 'Switch.Set')) === 2,
    'one retry and then confirmed');

// Unsafe sensors + missing status: still try OFF, and do not claim success.
$sim->remoteOn = true;
$sim->remoteOnline = false;
$GLOBALS['ewt_data'][20510]['v'] = -127;
$sim->rpcCalls = [];
$sim->DewPointGuardTick();
check($sim->attr['DewPointGuardState'] === 'UNKNOWN', 'offline during unsafe sensor does not imply OFF');
check(count(array_filter($sim->rpcCalls, fn($x) => $x[0] === 'Switch.Set')) === 2,
    'unsafe state attempts OFF despite initial status failure');

// Disabled automatic controller never touches the pump.
$sim->remoteOnline = true;
$sim->p['DewPointAutoPumpEnabled'] = false;
$sim->rpcCalls=[];
$sim->DewPointGuardTick();
check(count($sim->rpcCalls) === 0, 'disabled guard sends no commands');
echo "EWT stable pump guard: " . $GLOBALS['assertions'] . " assertions OK\\n";
