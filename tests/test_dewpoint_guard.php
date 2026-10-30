<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/LCNClimateControl/DewPointGuard.php';
if (!defined('VARIABLETYPE_FLOAT')) define('VARIABLETYPE_FLOAT', 2);
if (!defined('VARIABLETYPE_BOOLEAN')) define('VARIABLETYPE_BOOLEAN', 0);
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
    12630 => ['v'=>14.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT],
    20510 => ['v'=>19.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT],
    21409 => ['v'=>22.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT],
    14561 => ['v'=>20.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT],
    23904 => ['v'=>15.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT],
    12812 => ['v'=>24.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT], // EG Bad T
    53361 => ['v'=>60.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT], // EG Bad RH
    36518 => ['v'=>22.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT], // OG Bad T
    28802 => ['v'=>50.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT], // OG Bad RH
];
// Remaining nine known LCN room sensors: tests use full 11-room coverage.
foreach ([
    [14055, 19020], [15825, 16107], [21585, 52026],
    [25557, 58531], [29782, 46360], [17837, 43385],
    [34232, 13394], [30970, 37079], [25330, 22436]
] as [$temp, $rh]) {
    $GLOBALS['ewt_data'][$temp] = ['v'=>22.0, 'at'=>time(), 't'=>VARIABLETYPE_FLOAT];
    $GLOBALS['ewt_data'][$rh] = ['v'=>40.0, 'at'=>time(), 't'=>VARIABLETYPE_FLOAT];
}
$GLOBALS['ewt_data'][6001] = ['v'=>true, 'at'=>time(), 't'=>VARIABLETYPE_BOOLEAN];
$GLOBALS['ewt_data'][6002] = ['v'=>false, 'at'=>time(), 't'=>VARIABLETYPE_BOOLEAN];
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
    private const MODE_COOLING = 1;
    public int $symconMode = 1;
    private function GetMode(): int { return $this->symconMode; }
    public int $InstanceID = 200;
    public array $p = [
        'DewPointAutoPumpEnabled'=>true,
        'DewPointEfficiencyEnabled'=>false,
        'DewPointEwtVLMinC'=>5.0,
        'DewPointEwtVLMaxC'=>25.0,
        'DewPointEwtDeltaMinK'=>0.3,
        'DewPointEwtDeltaMaxK'=>10.0,
        'DewPointWaterVariable'=>20510,
        'DewPointMaxAgeSeconds'=>900, // old 0.5.6 legacy default auto-migration
        'DewPointMarginK'=>1.5,
        'DewPointHysteresisK'=>0.5,
        'DewPointPumpIP'=>'192.168.103.67',
        'DewPointPushWebFront'=>0
    ];
    public array $rooms = [
        ['Enabled'=>true,'Name'=>'EG Bad','TargetVariable'=>50001,'TemperatureVariable'=>12812,'HumidityVariable'=>0],
        ['Enabled'=>true,'Name'=>'OG Bad','TargetVariable'=>50002,'TemperatureVariable'=>36518,'HumidityVariable'=>0],
        ['Enabled'=>true,'Name'=>'EG Gäste WC','TargetVariable'=>50003,'TemperatureVariable'=>15825,'HumidityVariable'=>0],
        ['Enabled'=>true,'Name'=>'EG Büro','TargetVariable'=>50004,'TemperatureVariable'=>14055,'HumidityVariable'=>0],
        ['Enabled'=>true,'Name'=>'EG Küche','TargetVariable'=>50005,'TemperatureVariable'=>21585,'HumidityVariable'=>0],
        ['Enabled'=>true,'Name'=>'EG Schlafen','TargetVariable'=>50006,'TemperatureVariable'=>25557,'HumidityVariable'=>0],
        ['Enabled'=>true,'Name'=>'EG Wohnen','TargetVariable'=>50007,'TemperatureVariable'=>29782,'HumidityVariable'=>0],
        ['Enabled'=>true,'Name'=>'OG Ankleide','TargetVariable'=>50008,'TemperatureVariable'=>17837,'HumidityVariable'=>0],
        ['Enabled'=>true,'Name'=>'OG Flur','TargetVariable'=>50009,'TemperatureVariable'=>34232,'HumidityVariable'=>0],
        ['Enabled'=>true,'Name'=>'OG Schlafen 1','TargetVariable'=>50010,'TemperatureVariable'=>30970,'HumidityVariable'=>0],
        ['Enabled'=>true,'Name'=>'OG Schlafen 2','TargetVariable'=>50011,'TemperatureVariable'=>25330,'HumidityVariable'=>0]
    ];
    public array $coolingStates = [50001 => true]; // only EG Bad is cooling
    public array $relayMappings = [
        50001 => ['RelayStatusVariable'=>6001,'OpenRelayOn'=>true,'PolarityConfirmed'=>true],
        50002 => ['RelayStatusVariable'=>6002,'OpenRelayOn'=>true,'PolarityConfirmed'=>true]
    ];
    public array $attr = ['DewPointGuardNotifyState'=>''];
    public array $values=[];
    public array $rpcCalls=[];
    public bool $remoteOn = false;
    public bool $remoteOnline = true;
    public bool $ignoreCommands = false;
    public bool $ignoreNextCommand = false;
    public bool $failNextStatusRead = false;
    public bool $reportAutoOffLease = true;
    public bool $offHasUnexpectedTimer = false;

    public function ReadPropertyBoolean(string $key): bool { return (bool) ($this->p[$key] ?? false); }
    public function ReadPropertyInteger(string $key): int { return (int) ($this->p[$key] ?? 0); }
    public function ReadPropertyFloat(string $key): float { return (float) ($this->p[$key] ?? 0); }
    public function ReadPropertyString(string $key): string { return (string) ($this->p[$key] ?? ''); }
    public function ReadAttributeString(string $key): string { return (string) ($this->attr[$key] ?? ''); }
    public function WriteAttributeString(string $key, string $value): void { $this->attr[$key] = $value; }
    public function ReadAttributeInteger(string $key): int { return (int) ($this->attr[$key] ?? 0); }
    public function ReadAttributeBoolean(string $key): bool { return (bool) ($this->attr[$key] ?? false); }
    public function WriteAttributeInteger(string $key, int $value): void { $this->attr[$key] = $value; }
    public function SetValueIfChanged(string $key, mixed $v): void { $this->values[$key] = $v; }
    public function GetRooms(): array { return $this->rooms; }
    private function GetStoredCoolingState(int $target): bool {
        return (bool) ($this->coolingStates[$target] ?? false);
    }
    private function DirectCoolingMapping(int $target): ?array {
        return $this->relayMappings[$target] ?? null;
    }
    public function SendDebug(string $kind, string $message, int $level): void {}
    public function snapshot(): array { return $this->DewPointGuardSnapshot(); }

    protected function DewPointPumpRpc(string $method, array $params = []): array
    {
        $this->rpcCalls[] = [$method,$params];
        if (!$this->remoteOnline) throw new RuntimeException('simuliert: Shelly ohne Strom/WLAN');
        if ($method === 'Switch.GetStatus') {
            if ($this->failNextStatusRead) {
                $this->failNextStatusRead = false;
                throw new RuntimeException('simuliert: Switch.GetStatus voruebergehend nicht lesbar');
            }
            $status = ['output'=>$this->remoteOn];
            if ($this->remoteOn && $this->reportAutoOffLease) {
                $status += ['timer_type'=>'toggle_after', 'timer_started_at'=>time(),
                            'timer_duration'=>40];
            } elseif (!$this->remoteOn && $this->offHasUnexpectedTimer) {
                $status += ['timer_type'=>'auto_on', 'timer_started_at'=>time(),
                            'timer_duration'=>20];
            }
            return $status;
        }
        if ($method === 'Switch.Set') {
            $before = $this->remoteOn;
            if ($this->ignoreNextCommand) $this->ignoreNextCommand = false;
            elseif (!$this->ignoreCommands) {
                $this->remoteOn = $params['on'] === 'true';
                if (!$this->remoteOn) $this->offHasUnexpectedTimer = false;
            }
            return ['was_on'=>$before];
        }
        throw new RuntimeException('unbekannter RPC-Aufruf');
    }
}
$sim=new EwtSimulation();
check($sim->snapshot()['ok'] === true, 'LCN humidity IDs mapped without manual configuration');
check($sim->snapshot()['room'] === 'EG Bad', 'highest of all room dewpoints selected');
$dew = $sim->snapshot()['dew'];

// 15-minute RH polling: an actual sensor update must be within 30 min.
// Legacy settings are capped, no old 6-h value can authorize cooling.
$clock = time();
$GLOBALS['ewt_data'][53361]['at'] = $clock - 300;
check($sim->snapshot()['ok'] === true, 'RH updated 5 min ago is valid');
foreach ([900, 1800, 3600, 43200, 90000, 21600] as $savedAgeSetting) {
    $sim->p['DewPointMaxAgeSeconds'] = $savedAgeSetting;
    $GLOBALS['ewt_data'][53361]['at'] = $clock - 1790;
    check($sim->snapshot()['ok'] === true,
        'RH under 30 min valid with stored setting ' . $savedAgeSetting);
    $GLOBALS['ewt_data'][53361]['at'] = $clock - 1801;
    $stale = $sim->snapshot();
    check($stale['ok'] === false,
        'RH over 30 min blocks with stored setting ' . $savedAgeSetting);
    check(str_contains($stale['reason'], 'EG Bad')
        && str_contains($stale['reason'], 'Luftfeuchte (#53361)')
        && str_contains($stale['reason'], '0.5 h'),
        'stale RH report names failing sensor and 30-min limit');
}
// An explicitly stricter setting must never be relaxed to 30 min.
$sim->p['DewPointMaxAgeSeconds'] = 600;
$GLOBALS['ewt_data'][53361]['at'] = $clock - 590;
check($sim->snapshot()['ok'] === true, 'explicit 10-min RH limit remains valid');
$GLOBALS['ewt_data'][53361]['at'] = $clock - 610;
check($sim->snapshot()['ok'] === false, 'explicit 10-min RH limit rejects stale data');
$sim->p['DewPointMaxAgeSeconds'] = 21600;
$GLOBALS['ewt_data'][53361]['at'] = $clock - 1233 * 60;
check($sim->snapshot()['ok'] === false,
    'previously accepted 20h33min RH reading cannot pass 30-min protection');
$GLOBALS['ewt_data'][53361]['at'] = time();
$GLOBALS['ewt_data'][12812]['at'] = time() - 7201;
$oldTemp = $sim->snapshot();
check($oldTemp['ok'] === false,
    'room temperature remains limited to two hours');
check(str_contains($oldTemp['reason'], 'Temperatur (#12812)')
    && str_contains($oldTemp['reason'], '2.0 h'),
    'stale temperature status names original variable and two-hour limit');
$GLOBALS['ewt_data'][12812]['at'] = time();
check($sim->snapshot()['ok'] === true,
    'fresh room temperature and humidity release sensor check');

// The independent mode-contact timer must stay live. If its last
// physical COOLING confirmation is stale, the EWT guard must not renew
// its 40-s plug lease, even when the dew point and valves are safe.
$sim->p['ModeSwitchSource'] = 1;
$sim->attr['ModeSwitchBlocked'] = false;
$sim->attr['ModeSwitchLastVerifiedAt'] = time() - 26;
$sim->DewPointGuardTick();
check($sim->attr['DewPointGuardState'] === 'COOLING_INTERLOCK',
    '26-second old mode contact inhibits cooling pump even when sensors are safe');
check(count($sim->rpcCalls) === 0 && !$sim->remoteOn,
    'stale mode confirmation cannot trigger pump output commands');
$sim->attr['ModeSwitchLastVerifiedAt'] = time() + 35;
$sim->DewPointGuardTick();
check($sim->attr['DewPointGuardState'] === 'COOLING_INTERLOCK',
    'future-dated mode contact confirmation never authorizes cooling');
$sim->attr['ModeSwitchLastVerifiedAt'] = time();
$sim->DewPointGuardTick();
check($sim->attr['DewPointGuardState'] === 'OFF'
    && $sim->attr['DewPointGuardSafeSince'] > 0
    && str_contains($sim->values['DewPointGuardStatus'], 'Wiederanlauf in'),
    'fresh mode contact permits normal 120-second dew-point restart wait');
$sim->p['ModeSwitchSource'] = 0; // remaining legacy pump test scenarios
$sim->attr['DewPointGuardSafeSince'] = 0;
$sim->attr['DewPointGuardLastRenew'] = 0;

// When the physical cooling outlet returns power, Shelly could report
// output ON by its own startup configuration or a manual app command.
// Such an unleased ON must never bypass the 120-second safety interval.
$sim->remoteOn = true;
$sim->attr['DewPointGuardState'] = 'UNKNOWN';
$sim->attr['DewPointGuardLastRenew'] = 0;
$sim->DewPointGuardTick();
check(!$sim->remoteOn && $sim->attr['DewPointGuardState'] === 'OFF',
    'unexpected plug ON is switched OFF before any new pump permission');
check(str_contains($sim->values['DewPointGuardStatus'], 'ungeplanter Pumpenstart abgeschaltet'),
    'pump diagnostic names power-on/manual uncontrolled start');
check(count(array_filter($sim->rpcCalls,
    fn($x) => $x[0] === 'Switch.Set' && $x[1]['on'] === 'true')) === 0,
    'unexpected pump output cannot obtain a new ON lease immediately');
$sim->rpcCalls = [];
$sim->attr['DewPointGuardSafeSince'] = 0;
$sim->attr['DewPointGuardLastRenew'] = 0;

// Initial output OFF: even with safe water, restart is only allowed
// after 120 seconds of CONTINUOUS headroom.
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'no immediate initial start');
check($sim->attr['DewPointGuardSafeSince'] > 0, 'safe interval started');
check(str_contains($sim->values['DewPointGuardStatus'], 'Wiederanlauf in'), 'wait visible');

check(str_contains($sim->values['DewPointGuardStatus'], 'T100 14.00'), 'sensor 100 display');
check(str_contains($sim->values['DewPointGuardStatus'], 'T101 19.00'), 'sensor 101 display');
check(str_contains($sim->values['DewPointGuardStatus'], 'T102 22.00'), 'sensor 102 display');
check(str_contains($sim->values['DewPointGuardStatus'], 'T103 20.00'), 'sensor 103 display');
check(str_contains($sim->values['DewPointGuardStatus'], 'T104 15.00'), 'sensor 104 display');
check(str_contains($sim->values['DewPointGuardStatus'], 'FBH-WT -1.00 K'), 'FBH delta');
check(str_contains($sim->values['DewPointGuardStatus'], 'EWT-WT +1.00 K'), 'EWT delta');
$GLOBALS['ewt_data'][23904]['at'] = time() - 600;
$sim->DewPointGuardTick();
check(str_contains($sim->values['DewPointGuardStatus'], 'EWT-WT nicht synchron'), 'no unsynced delta');
$GLOBALS['ewt_data'][23904]['at'] = time() - 901;
$sim->DewPointGuardTick();
check(str_contains($sim->values['DewPointGuardStatus'], 'T104 --'), 'old sensor omitted');
check(str_contains($sim->values['DewPointGuardStatus'], 'T104 -- (15 min alt)'),
    'stale optional EWT sensor shows its age instead of an unexplained dash');
$GLOBALS['ewt_data'][23904]['at'] = time();

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
check(str_contains($sim->values['DewPointGuardStatus'], 'Luftfeuchte (#53361)')
    && str_contains($sim->values['DewPointGuardStatus'], 'Messvariable fehlt'),
    'missing humidity reports the actual missing variable');
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
check(str_contains($sim->values['DewPointGuardStatus'], 'FBH-Fühler 101')
    && str_contains($sim->values['DewPointGuardStatus'], 'Kuehlwasser')
    && str_contains($sim->values['DewPointGuardStatus'], '0.2 h'),
    'stale T101 status exposes precise 15-minute limit');
$GLOBALS['ewt_data'][20510]['at'] = time();
$GLOBALS['ewt_data'][53361]['at'] = time() - 1790;
check($sim->snapshot()['ok'] === true, 'humidity under 30-minute limit remains valid');
$GLOBALS['ewt_data'][53361]['at'] = time() - 1801;
check($sim->snapshot()['ok'] === false, 'humidity above 30-minute limit is stale');
$GLOBALS['ewt_data'][53361]['at'] = time() - 3600;
check($sim->snapshot()['ok'] === false, 'one-hour humidity must not allow pump');
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
check(count(array_filter($sim->rpcCalls,
    fn($x) => $x[0] === 'Switch.Set' && $x[1]['on'] === 'true')) === 2,
    'wrong readback triggers one ON retry; then emergency OFF');

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

// Missing Shelly auto-OFF proof: even a successful ON output must be
// switched back OFF, rather than left powered indefinitely.
$missingLease = new EwtSimulation();
$missingLease->reportAutoOffLease = false;
$missingLease->attr['DewPointGuardSafeSince'] = time() - 121;
$missingLease->attr['DewPointGuardLastRenew'] = time();
$missingLease->DewPointGuardTick();
check(!$missingLease->remoteOn, 'ON without confirmed 40-s Shelly timer is stopped');
check($missingLease->attr['DewPointGuardState'] === 'UNKNOWN',
    'unconfirmed timer never produces authorized ON status');
check(count(array_filter($missingLease->rpcCalls,
    fn($x) => $x[0] === 'Switch.Set' && $x[1]['on'] === 'false')) >= 1,
    'timer verification failure explicitly orders emergency OFF');
// Even an OFF output may contain an active timer to toggle back ON.
$offTimer = new EwtSimulation();
$offTimer->offHasUnexpectedTimer = true;
$offTimer->DewPointGuardTick();
check(!$offTimer->remoteOn && !$offTimer->offHasUnexpectedTimer,
    'pending auto-ON while OFF is canceled');
check(count(array_filter($offTimer->rpcCalls,
    fn($x) => $x[0] === 'Switch.Set' && $x[1]['on'] === 'false')) === 1,
    'pending timer canceled with permanent OFF (no toggle_after)');
// Unsafe sensors + missing status: still try OFF, and do not claim success.
$sim->remoteOn = true;
$sim->remoteOnline = false;
$GLOBALS['ewt_data'][20510]['v'] = -127;
$sim->rpcCalls = [];
$sim->DewPointGuardTick();
check($sim->attr['DewPointGuardState'] === 'UNKNOWN', 'offline during unsafe sensor does not imply OFF');
check(count(array_filter($sim->rpcCalls, fn($x) => $x[0] === 'Switch.Set')) === 2,
    'unsafe state attempts OFF despite initial status failure');

// HEIZEN is the hardware-unpowered outlet, therefore an unreachable
// Shelly is expected and MUST NEVER raise a monitoring error.
$sim->symconMode = 0;
$sim->remoteOnline = false;
$sim->rpcCalls = [];
$sim->DewPointGuardTick();
check(count($sim->rpcCalls) === 0, 'HEIZEN does not contact an unpowered Shelly');
check($sim->attr['DewPointGuardState'] === 'HEATING_PAUSED', 'heating has a distinct idle state');
check(str_contains($sim->values['DewPointGuardStatus'], 'HEIZEN'), 'heating status names operating mode');
check(!str_contains($sim->values['DewPointGuardStatus'], 'NICHT BESTAETIGT'), 'no false alarm while heating');
check($sim->attr['DewPointGuardNotifyState'] === 'HEATING_PAUSED', 'old error notification state cleared');
check($sim->attr['DewPointGuardSafeSince'] === 0 && $sim->attr['DewPointGuardLastRenew'] === 0,
    'heating resets restart timer and watchdog freshness');

// HEIZEN -> KUEHLEN: Shelly starts unpowered/off then must be
// reachable and satisfy the full stable-temperature restart.
$sim->symconMode = 1;
$sim->remoteOnline = true;
$sim->remoteOn = false;
$GLOBALS['ewt_data'][20510]['v'] = 19.0;
$sim->rpcCalls = [];
$sim->DewPointGuardTick();
check($sim->attr['DewPointGuardState'] === 'OFF', 'cooling resumes real Shelly monitoring');
check($sim->attr['DewPointGuardSafeSince'] > 0 && !$sim->remoteOn,
    'cooling restarts with a fresh 120-second safe period');

// In KUEHLEN a missing Shelly is still a real fault.
$sim->remoteOnline = false;
$sim->DewPointGuardTick();
check($sim->attr['DewPointGuardState'] === 'UNKNOWN', 'cooling unreachable Shelly is still a fault');


 // Gate pump ON by CONFIRMED active cooling relay, never by a room UI preference alone.
$sim->remoteOnline = true;
$sim->remoteOn = true;
$GLOBALS['ewt_data'][20510]['v'] = 19.0;
$sim->coolingStates = [];
$sim->rpcCalls = [];
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'all room cooling toggles OFF stop running EWT pump');
check(str_contains($sim->values['DewPointGuardStatus'], 'kein Kuehlventil offen'),
    'status identifies idle EWT pump when all valves closed');
check($sim->attr['DewPointGuardState'] === 'OFF', 'all-closed state confirmed OFF');
$sim->rpcCalls = [];
$sim->DewPointGuardTick();
check(count(array_filter($sim->rpcCalls, fn($x) => $x[0] === 'Switch.Set')) === 0,
    'all-closed pump does not receive redundant OFF command');

$sim->coolingStates = [50001=>true];
$GLOBALS['ewt_data'][6001]['v'] = false;
$sim->attr['DewPointGuardSafeSince'] = time() - 121;
$sim->DewPointGuardTick();
check(!$sim->remoteOn && $sim->attr['DewPointGuardSafeSince'] === 0,
    'UI cooling ON without relay-open feedback cannot start pump');
$GLOBALS['ewt_data'][6001]['v'] = true;
unset($GLOBALS['ewt_data'][6001]);
$sim->DewPointGuardTick();
check(!$sim->remoteOn && str_contains($sim->values['DewPointGuardStatus'], 'Rueckmeldung fehlt'),
    'unavailable active relay feedback blocks pump');
$GLOBALS['ewt_data'][6001] = ['v'=>true,'at'=>time(),'t'=>VARIABLETYPE_FLOAT];
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'non-boolean relay status blocks pump');
$GLOBALS['ewt_data'][6001] = ['v'=>true,'at'=>time(),'t'=>VARIABLETYPE_BOOLEAN];

$sim->relayMappings[50001]['PolarityConfirmed'] = false;
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'unknown relay polarity cannot release pump');
$sim->relayMappings[50001]['PolarityConfirmed'] = true;
$sim->DewPointGuardTick();
check(!$sim->remoteOn && $sim->attr['DewPointGuardSafeSince'] > 0,
    'restored active relay enters fresh 120-second safety wait');
$sim->attr['DewPointGuardSafeSince'] = time() - 121;
$sim->DewPointGuardTick();
check($sim->remoteOn, 'one confirmed open NC valve can authorize EWT after stable delay');

// Another selected room is not cooling but has the highest humidity.
// It still limits pump operation because shared supply pipes can condense.
$sim->rooms[2]['Enabled'] = false; // even module-disabled rooms count for dew
$GLOBALS['ewt_data'][15825]['v'] = 23.0;
$GLOBALS['ewt_data'][16107]['v'] = 90.0;
check($sim->snapshot()['room'] === 'EG Gäste WC' && $sim->snapshot()['rooms'] === 11,
    'highest dewpoint includes inactive and disabled EG Gäste WC');
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'humid NON-COOLING guest WC protects shared pipes');
$GLOBALS['ewt_data'][16107]['v'] = 40.0;

// Sensor completeness: 10 of 11 is not sufficient for the shared pipes.
$removed = array_pop($sim->rooms);
check($sim->snapshot()['ok'] === false, 'fewer than 11 rooms cannot authorize pump');
$sim->rooms[] = $removed;
check($sim->snapshot()['ok'] === true, 'full 11-room set restored');
$oldTemp = $sim->rooms[3]['TemperatureVariable'];
$sim->rooms[3]['TemperatureVariable'] = $sim->rooms[0]['TemperatureVariable'];
check($sim->snapshot()['ok'] === false, 'duplicate room-temperature source blocks pump');
$sim->rooms[3]['TemperatureVariable'] = $oldTemp;
$sim->rooms[3]['HumidityVariable'] = 53361;
check($sim->snapshot()['ok'] === false, 'duplicate room-humidity source blocks pump');
$sim->rooms[3]['HumidityVariable'] = 0;
check($sim->snapshot()['ok'] === true, 'unique room sensor sources restored');

// It also works when a DIFFERENT room is the single cooling room.
$sim->coolingStates = [50002=>true];
$GLOBALS['ewt_data'][6002]['v'] = true;
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'changing active cooling zone restarts safety delay');
$sim->attr['DewPointGuardSafeSince'] = time()-121;
$sim->DewPointGuardTick();
check($sim->remoteOn, 'second active cooling room confirmed by relay feedback');
$GLOBALS['ewt_data'][6002]['v'] = false;
$sim->DewPointGuardTick();
check(!$sim->remoteOn, 'last physical cooling valve closing immediately stops EWT pump');

// Opt-in additional-water-sensor controller: OFF must preserve old logic.
$GLOBALS['ewt_data'][12630] = ['v'=>14.0, 'at'=>time(), 't'=>VARIABLETYPE_FLOAT];
$GLOBALS['ewt_data'][20510] = ['v'=>19.0, 'at'=>time(), 't'=>VARIABLETYPE_FLOAT];
$GLOBALS['ewt_data'][21409] = ['v'=>14.0, 'at'=>time(), 't'=>VARIABLETYPE_FLOAT];
$GLOBALS['ewt_data'][14561] = ['v'=>20.0, 'at'=>time(), 't'=>VARIABLETYPE_FLOAT];
$GLOBALS['ewt_data'][23904] = ['v'=>15.0, 'at'=>time(), 't'=>VARIABLETYPE_FLOAT];
$opt = new EwtSimulation();
$opt->remoteOn = true;
$opt->attr['DewPointGuardState'] = 'ON';
$opt->attr['DewPointGuardLastRenew'] = time();
$opt->DewPointGuardTick();
check($opt->remoteOn, 'default OFF keeps original controller even if T102 is cold');
check(!str_contains($opt->values['DewPointGuardStatus'], 'Zusatzregelung EIN'),
    'default OFF does not advertise advanced control');
$opt->p['DewPointEfficiencyEnabled'] = true;
$opt->DewPointGuardTick();
check(!$opt->remoteOn, 'selected extra controller stops on cold FBH supply T102');
check(str_contains($opt->values['DewPointGuardStatus'], 'T102 nahe Taupunkt'),
    'extra supply protection identified in existing status');
check($opt->attr['DewPointGuardNotifyState'] === 'SUPPLY_STOP',
    'T102 safety stop has its own notification classification');

$GLOBALS['ewt_data'][21409]['v'] = 22.0;
$opt->DewPointGuardTick();
check(!$opt->remoteOn, 'extra guard still requires original 120 s stability');
$opt->attr['DewPointGuardSafeSince'] = time()-121;
$opt->DewPointGuardTick();
check($opt->remoteOn, 'advanced mode restarts only with original guard approval');
$opt->attr['DewPointEfficiencyRunSince'] = time()-1000;
$opt->attr['DewPointEfficiencyWeakSince'] = time()-310;
$opt->DewPointGuardTick();
check($opt->remoteOn, 'healthy measured cooling does not enter an efficiency pause');
check($opt->attr['DewPointEfficiencyWeakSince'] === 0,
    'good cooling resets accumulated weak-effect interval');

// Weak exchanger measurements require BOTH 10min running and 5min evidence.
$GLOBALS['ewt_data'][12630]['v'] = 20.0;
$GLOBALS['ewt_data'][20510]['v'] = 20.0;
$GLOBALS['ewt_data'][14561]['v'] = 20.0;
$GLOBALS['ewt_data'][23904]['v'] = 20.0;
$opt->attr['DewPointEfficiencyRunSince'] = time()-601;
$opt->attr['DewPointEfficiencyWeakSince'] = 0;
$opt->DewPointGuardTick();
check($opt->remoteOn, 'single weak sample is insufficient to stop pump');
check($opt->attr['DewPointEfficiencyWeakSince'] > 0, 'weak cooling evidence timer begins');
$opt->attr['DewPointEfficiencyWeakSince'] = time()-301;
$opt->DewPointGuardTick();
check(!$opt->remoteOn, 'sustained low exchanger effect triggers efficiency pause');
check($opt->attr['DewPointEfficiencyPauseUntil'] >= time()+899,
    'efficiency pause lasts 15 min');
check(str_contains($opt->values['DewPointGuardStatus'], 'Effizienzpause'),
    'pause visible in existing status');
$opt->DewPointGuardTick();
check(!$opt->remoteOn, 'still OFF during efficiency pause');
check($opt->attr['DewPointGuardSafeSince'] === 0,
    'efficiency pause cannot count toward 120-second safety approval');

// A real dewpoint fault during an efficiency pause is still a safety stop,
// not a quiet optimization pause.
$GLOBALS['ewt_data'][20510]['v'] = $dew + 1.5;
$opt->DewPointGuardTick();
check($opt->attr['DewPointGuardNotifyState'] === 'DEW_STOP',
    'dewpoint safety stop takes priority over efficiency pause notification');
$GLOBALS['ewt_data'][20510]['v'] = 20.0;

// OFF returns immediately to original control after normal stabilization.
$opt->p['DewPointEfficiencyEnabled'] = false;
$GLOBALS['ewt_data'][21409]['v'] = 14.0;
$opt->DewPointGuardTick();
check($opt->attr['DewPointEfficiencyPauseUntil'] === 0,
    'disabling advanced mode cancels its pause and counters');
check(!$opt->remoteOn, 'legacy guard still enforces restart wait');
$opt->attr['DewPointGuardSafeSince'] = time()-121;
$opt->DewPointGuardTick();
check($opt->remoteOn, 'legacy mode ignores optional T102 and resumes normally');

// Missing or asynchronous optional data never produce a false efficiency stop.
$opt->p['DewPointEfficiencyEnabled'] = true;
$GLOBALS['ewt_data'][21409]['v'] = 22.0;
$opt->attr['DewPointEfficiencyRunSince'] = time()-1000;
$opt->attr['DewPointEfficiencyWeakSince'] = time()-310;
$GLOBALS['ewt_data'][12630]['at'] = time()-301;
$opt->DewPointGuardTick();
check($opt->remoteOn, 'stale T100 never forces an efficiency pause');
check($opt->attr['DewPointEfficiencyWeakSince'] === 0,
    'stale efficiency data clear weak-effect evidence');
$GLOBALS['ewt_data'][12630]['at'] = time();
$GLOBALS['ewt_data'][23904]['at'] = time()-150;
$opt->attr['DewPointEfficiencyRunSince'] = time()-1000;
$opt->attr['DewPointEfficiencyWeakSince'] = time()-310;
$opt->DewPointGuardTick();
check($opt->remoteOn, 'time-asynchronous optional sensors cannot force pause');
$GLOBALS['ewt_data'][23904]['at'] = time();

// Original dewpoint safety wins even when efficiency mode is active.
$GLOBALS['ewt_data'][20510]['v'] = $dew + 1.5;
$opt->DewPointGuardTick();
check(!$opt->remoteOn, 'main dewpoint stop always wins over optional efficiency');
$GLOBALS['ewt_data'][20510]['v'] = 19.0;
$opt->symconMode = 0;
$opt->remoteOnline = false;
$opt->DewPointGuardTick();
check($opt->attr['DewPointEfficiencyPauseUntil'] === 0
    && $opt->attr['DewPointEfficiencyRunSince'] === 0,
    'HEIZEN clears optional efficiency control without Shelly communication');

// User-configurable source-water range T100 and return-minus-supply delta.
// A standing-water reading cannot permanently block a safe startup trial.
$GLOBALS['ewt_data'][12630] = ['v'=>18.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT];
$GLOBALS['ewt_data'][20510] = ['v'=>20.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT];
$GLOBALS['ewt_data'][21409] = ['v'=>22.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT];
$GLOBALS['ewt_data'][14561] = ['v'=>21.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT];
$GLOBALS['ewt_data'][23904] = ['v'=>19.0,'at'=>time(),'t'=>VARIABLETYPE_FLOAT];
$cfg = new EwtSimulation();
$cfg->p['DewPointEfficiencyEnabled'] = true;
$cfg->p['DewPointEwtVLMinC'] = 19.0;
$cfg->p['DewPointEwtVLMaxC'] = 24.0;
$cfg->p['DewPointEwtDeltaMinK'] = 0.5;
$cfg->p['DewPointEwtDeltaMaxK'] = 3.0;
$cfg->DewPointGuardTick();
check(!$cfg->remoteOn && $cfg->attr['DewPointGuardSafeSince'] > 0,
    'standing EWT temperature never bypasses the core 120s startup');
$cfg->attr['DewPointGuardSafeSince'] = time()-121;
$cfg->DewPointGuardTick();
check($cfg->remoteOn, 'cold standing T100 is measured by a short ON trial');
$cfg->attr['DewPointEfficiencyRunSince'] = time()-181;
$cfg->DewPointGuardTick();
check($cfg->remoteOn && $cfg->attr['DewPointEfficiencySourceBadSince'] > 0,
    'T100 below configured minimum starts 60-second temperature evidence');
$cfg->attr['DewPointEfficiencySourceBadSince'] = time()-61;
$cfg->DewPointGuardTick();
check(!$cfg->remoteOn, 'T100 under minimum leads to 15-minute pump pause');
check(str_contains($cfg->values['DewPointGuardStatus'], 'T100 18.00'),
    'status displays violated T100 band and actual value');

// While paused, even a corrected sensor must not circumvent the pause.
$GLOBALS['ewt_data'][12630]['v'] = 21.0;
$cfg->DewPointGuardTick();
check(!$cfg->remoteOn, 'valid T100 alone never cancels active pause');
$cfg->attr['DewPointEfficiencyPauseUntil'] = time()-1;
$cfg->DewPointGuardTick();
check(!$cfg->remoteOn && $cfg->attr['DewPointGuardSafeSince'] > 0,
    'expired pause restarts 120s basic taupoint interval');
$cfg->attr['DewPointGuardSafeSince'] = time()-121;
$cfg->DewPointGuardTick();
check($cfg->remoteOn, 'trial resumes automatically after pause with safe base values');

// T100 above the selected maximum also forces a pause.
$GLOBALS['ewt_data'][12630]['v'] = 25.0;
$cfg->attr['DewPointEfficiencyRunSince'] = time()-181;
$cfg->attr['DewPointEfficiencySourceBadSince'] = time()-61;
$cfg->DewPointGuardTick();
check(!$cfg->remoteOn, 'T100 above user-selected maximum stops pump');

// A delta INSIDE the chosen interval permits continued operation.
$deltaOk = new EwtSimulation();
$deltaOk->p['DewPointEfficiencyEnabled'] = true;
$deltaOk->p['DewPointEwtVLMinC'] = 16.0;
$deltaOk->p['DewPointEwtVLMaxC'] = 22.0;
$deltaOk->p['DewPointEwtDeltaMinK'] = 0.5;
$deltaOk->p['DewPointEwtDeltaMaxK'] = 2.0;
$GLOBALS['ewt_data'][12630]['v'] = 18.0;
$GLOBALS['ewt_data'][23904]['v'] = 19.0;
$deltaOk->remoteOn = true;
$deltaOk->attr['DewPointGuardState'] = 'ON';
$deltaOk->attr['DewPointGuardLastRenew'] = time();
$deltaOk->attr['DewPointGuardLastRenew'] = time();
$deltaOk->attr['DewPointEfficiencyRunSince'] = time()-1000;
$deltaOk->DewPointGuardTick();
check($deltaOk->remoteOn, 'delta within configured 0.5-2 K keeps pump ON');
check(str_contains($deltaOk->values['DewPointGuardStatus'], 'Delta 1.00 K im Band'),
    'status displays current EWT delta');

// Raising minimum delta causes stop after sustained out-of-range difference.
$deltaOk->p['DewPointEwtDeltaMinK'] = 1.5;
$deltaOk->DewPointGuardTick();
check($deltaOk->remoteOn && $deltaOk->attr['DewPointEfficiencyWeakSince'] > 0,
    'delta below configured min first begins confirmation period');
$deltaOk->attr['DewPointEfficiencyWeakSince'] = time()-301;
$deltaOk->DewPointGuardTick();
check(!$deltaOk->remoteOn, 'delta below user-selected minimum eventually pauses pump');

// Delta above maximum is also user-configurable.
$deltaHigh = new EwtSimulation();
$deltaHigh->p['DewPointEfficiencyEnabled'] = true;
$deltaHigh->p['DewPointEwtDeltaMinK'] = 0.2;
$deltaHigh->p['DewPointEwtDeltaMaxK'] = 0.8;
$deltaHigh->remoteOn = true;
$deltaHigh->attr['DewPointGuardState'] = 'ON';
$deltaHigh->attr['DewPointGuardLastRenew'] = time();
$deltaHigh->attr['DewPointGuardLastRenew'] = time();
$deltaHigh->attr['DewPointEfficiencyRunSince'] = time()-1000;
$deltaHigh->attr['DewPointEfficiencyWeakSince'] = time()-301;
$deltaHigh->DewPointGuardTick();
check(!$deltaHigh->remoteOn, 'delta above configured maximum pauses pump');

// Invalid crossed minimum/maximum values must not weaken or break the
// existing core protection; output stays under original supervision.
$invalid = new EwtSimulation();
$invalid->p['DewPointEfficiencyEnabled'] = true;
$invalid->p['DewPointEwtVLMinC'] = 25.0;
$invalid->p['DewPointEwtVLMaxC'] = 15.0;
$invalid->remoteOn = true;
$invalid->attr['DewPointGuardState'] = 'ON';
$invalid->attr['DewPointGuardLastRenew'] = time();
$invalid->DewPointGuardTick();
check($invalid->remoteOn && str_contains($invalid->values['DewPointGuardStatus'], 'ungueltige'),
    'invalid source band reports configuration error, original controller continues');
$invalid->p['DewPointEwtVLMinC'] = 5.0;
$invalid->p['DewPointEwtDeltaMinK'] = 9.0;
$invalid->p['DewPointEwtDeltaMaxK'] = 3.0;
$invalid->DewPointGuardTick();
check($invalid->remoteOn, 'invalid delta band does not override original safety');

// With option OFF, forbidden source temperature and delta have zero effect.
$invalid->p['DewPointEfficiencyEnabled'] = false;
$GLOBALS['ewt_data'][12630]['v'] = 38.0;
$GLOBALS['ewt_data'][23904]['v'] = 38.0;
$invalid->DewPointGuardTick();
check($invalid->remoteOn, 'legacy option OFF entirely ignores all EWT optimization bands');

// A transient unreadable initial Shelly status is NOT safe to ignore.
// A verified OFF is issued, and prior restart approval is invalidated.
$readError = new EwtSimulation();
$readError->remoteOn = true;
$readError->attr['DewPointGuardLastRenew'] = time();
$readError->attr['DewPointGuardSafeSince'] = time()-250;
$readError->failNextStatusRead = true;
$GLOBALS['ewt_data'][20510]['v'] = 20.0;
$GLOBALS['ewt_data'][12630]['v'] = 18.0;
$GLOBALS['ewt_data'][21409]['v'] = 22.0;
$readError->DewPointGuardTick();
check(!$readError->remoteOn, 'unreadable initial Shelly status forces confirmed OFF');
check($readError->attr['DewPointGuardState'] === 'OFF',
    'verified OFF is reported only after real Shelly readback');
check($readError->attr['DewPointGuardSafeSince'] >= time()-1,
    'status loss cancels stale 120s authorization');
check(count(array_filter($readError->rpcCalls,
    fn($item) => $item[0] === 'Switch.Set' && $item[1]['on'] === 'false')) === 1,
    'transient read failure issues exactly one successful OFF command');
$readError->DewPointGuardTick();
check(!$readError->remoteOn, 'pump remains off during fresh restart interval');

// A failed attempt to confirm OFF stays UNKNOWN; no invented status.
$readError->remoteOn = true;
$readError->remoteOnline = false;
$readError->DewPointGuardTick();
check($readError->attr['DewPointGuardState'] === 'UNKNOWN',
    'unreachable Shelly never claims pump OFF');
check(str_contains($readError->values['DewPointGuardStatus'], 'NICHT BESTAETIGT'),
    'unreachable Shelly keeps a visible fault');

// T102 must override a previous efficiency pause as an explicit safety stop.
$tsupply = new EwtSimulation();
$tsupply->p['DewPointEfficiencyEnabled'] = true;
$tsupply->remoteOn = true;
$tsupply->attr['DewPointGuardState'] = 'ON';
$tsupply->attr['DewPointGuardLastRenew'] = time();
$tsupply->attr['DewPointEfficiencyPauseUntil'] = time()+600;
$GLOBALS['ewt_data'][21409]['v'] = 12.0;
$tsupply->DewPointGuardTick();
check(!$tsupply->remoteOn, 'T102 near dewpoint immediately stops even in pause');
check($tsupply->attr['DewPointGuardNotifyState'] === 'SUPPLY_STOP',
    'T102 stop is not hidden as efficiency pause');
check(str_contains($tsupply->values['DewPointGuardStatus'], 'T102 nahe Taupunkt'),
    'T102 reason preserved in status');
$GLOBALS['ewt_data'][21409]['v'] = 22.0;

// The real operating-mode contact is on the SAME Shelly that reads T100-T104,
 // but the pump plug has a different IP and is unpowered while the mode relay
 // is blocked. No unnecessary RPC or false 'offline' alarm is allowed.
$interlock = new EwtSimulation();
$interlock->p['ModeSwitchSource'] = 1;
$interlock->attr['ModeSwitchBlocked'] = true;
$interlock->remoteOn = false;
$interlock->remoteOnline = false;
$interlock->rpcCalls = [];
$interlock->DewPointGuardTick();
check(count($interlock->rpcCalls) === 0, 'blocked mode relay must not poll unpowered EWT pump plug');
check($interlock->attr['DewPointGuardState'] === 'COOLING_INTERLOCK',
    'blocked external cooling authorization has separate state');
check(str_contains($interlock->values['DewPointGuardStatus'], 'KUEHLEN GESPERRT'),
    'blocked cooling mode displays correct warning without pretending pump OFF');
$interlock->remoteOnline = true;
$interlock->attr['ModeSwitchBlocked'] = false;
$interlock->attr['ModeSwitchLastVerifiedAt'] = time(); // explicit verified COOLING relay
$interlock->DewPointGuardTick();
check(count($interlock->rpcCalls) > 0,
    'after explicit mode authorization ordinary pump guard resumes');

// Disabled automatic controller never touches the pump.
$sim->remoteOnline = true;
$sim->p['DewPointAutoPumpEnabled'] = false;
$sim->rpcCalls=[];
$sim->DewPointGuardTick();
check(count($sim->rpcCalls) === 0, 'disabled guard sends no commands');
echo "EWT stable pump guard: " . $GLOBALS['assertions'] . " assertions OK\\n";
