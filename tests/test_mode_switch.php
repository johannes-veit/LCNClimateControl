<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/LCNClimateControl/ModeSwitch.php';

function ok(bool $value, string $msg): void
{
    $GLOBALS['tests'] = ($GLOBALS['tests'] ?? 0) + 1;
    if (!$value) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
}
if (!function_exists('IPS_SemaphoreEnter')) {
    function IPS_SemaphoreEnter(string $key, int $ms): bool { return true; }
    function IPS_SemaphoreLeave(string $key): void {}
}
class ModeSwitchMock
{
    use LCNClimateModeSwitch;
    private const MODE_COOLING = 1;
    private const MODE_HEATING = 0;
    public int $InstanceID = 123;
    public int $mode = 0;
    public array $props = [
        'ModeSwitchSource'=>0,
        'ModeSwitchShellyIP'=>'192.168.103.71',
        'DewPointPumpIP'=>'192.168.103.67',
        'ModeSwitchWiringConfirmed'=>true,
        'DewPointAutoPumpEnabled'=>true
    ];
    public array $attrs = ['ModeSwitchBlocked'=>true];
    public array $values = [];
    public array $rpcCalls = [];
    public bool $relay = false;
    public bool $online = true;
    public bool $ignoreCommands = false;
    public bool $badReadOnce = false;
    public bool $readiness = true;
    public array $config = [
        'initial_state'=>'off', 'in_mode'=>'detached', 'auto_on'=>false, 'auto_off'=>false
    ];

    public function ReadPropertyInteger(string $k): int { return (int) ($this->props[$k] ?? 0); }
    public function ReadPropertyString(string $k): string { return (string) ($this->props[$k] ?? ''); }
    public function ReadPropertyBoolean(string $k): bool { return (bool) ($this->props[$k] ?? false); }
    public function ReadAttributeBoolean(string $k): bool { return (bool) ($this->attrs[$k] ?? false); }
    public function WriteAttributeBoolean(string $k, bool $v): void { $this->attrs[$k] = $v; }
    public function SetValueIfChanged(string $k, mixed $v): void { $this->values[$k] = $v; }
    public function SendDebug(string $k, string $m, int $l): void {}
    private function GetMode(): int { return $this->mode; }
    private function CoolingCommissionReadinessErrors(): array { return $this->readiness ? [] : ['not ready']; }

    protected function ModeSwitchRpc(string $method, array $params = []): array
    {
        $this->rpcCalls[] = [$method, $params];
        if (!$this->online) throw new RuntimeException('Shelly offline');
        if ($method === 'Switch.GetConfig') return $this->config;
        if ($method === 'Switch.GetStatus') {
            if ($this->badReadOnce) {
                $this->badReadOnce=false;
                throw new RuntimeException('readback failed');
            }
            return ['output'=>$this->relay];
        }
        if ($method === 'Switch.Set') {
            if (!$this->ignoreCommands) $this->relay = $params['on'] === 'true';
            return ['was_on'=>!$this->relay];
        }
        throw new RuntimeException('unknown RPC');
    }

    public function request(int $mode): void
    {
        $r = new ReflectionMethod(self::class, 'ModeSwitchSelect');
        $r->invoke($this, $mode);
        $this->mode = $mode; // only commit mode after confirmed I/O
    }
}
$m = new ModeSwitchMock();
$m->ModeSwitchTick();
ok($m->rpcCalls === [] && $m->relay === false,
    'wall switch default: never send commands');
$m->request(1);
ok($m->mode === 1 && !$m->relay, 'wall switch: original mode choice preserved without RPC');

$m->props['ModeSwitchSource'] = 1;
$m->mode=0;
$m->request(1);
ok($m->relay && $m->mode===1, 'Shelly: verified contact ON before cooling mode');
ok($m->attrs['ModeSwitchBlocked'] === false, 'successful explicit switch unblocks watchdog');
$on = array_values(array_filter($m->rpcCalls, fn($x) => $x[0] === 'Switch.Set' && $x[1]['on'] === 'true'));
ok(count($on)===1 && ($on[0][1]['toggle_after'] ?? null) === 35,
    'the only ON is a local 35-second automatic-OFF lease');
$before = count($m->rpcCalls);
$m->ModeSwitchTick();
ok($m->relay && count($m->rpcCalls)>$before, 'cooling timer renews local lease');
$m->request(0);
ok(!$m->relay && $m->mode===0, 'heating: output OFF before changing mode');
$before = count($m->rpcCalls);
$m->ModeSwitchTick();
ok(count($m->rpcCalls)===$before+1, 'heating poll checks status without unnecessary OFF writes');

// The operating-mode relay and all five water thermometers are the same
// already installed Shelly 1 Gen3 (192.168.103.71). The existing pump
// plug .67 MUST remain a separate device.
$target = new ReflectionMethod(ModeSwitchMock::class, 'ModeSwitchTargetIP');
ok($target->invoke($m) === '192.168.103.71',
    'real installed sensor/Shelly relay IP is 192.168.103.71');
$m->props['ModeSwitchShellyIP'] = ''; // persisted empty from version 0.5.15
ok($target->invoke($m) === '192.168.103.71',
    'existing 0.5.15 instance with blank IP uses safe default .71');
$m->props['ModeSwitchShellyIP'] = '192.168.103.99';
ok($target->invoke($m) === '192.168.103.99',
    'a deliberately set different valid relay IP remains configurable');
$m->props['ModeSwitchShellyIP'] = 'invalid-ip';
try { $target->invoke($m); ok(false, 'invalid IP must be rejected'); }
catch (RuntimeException $e) {}
$m->props['ModeSwitchShellyIP'] = '192.168.103.71';

$bad = new ModeSwitchMock();
$bad->props['ModeSwitchSource']=1;
$bad->config['initial_state']='restore_last';
try { $bad->request(1); ok(false, 'incorrect power-on config should reject'); }
catch (RuntimeException $e) {}
ok(!$bad->relay && $bad->mode===0 && $bad->attrs['ModeSwitchBlocked'],
    'unsafe power-on default refuses cooling and holds old Symcon mode');
$bad->config['initial_state']='off';
unset($bad->config['auto_on']);
try { $bad->request(1); ok(false, 'missing auto_on field cannot be treated as false'); }
catch (RuntimeException $e) {}
ok(!$bad->relay, 'missing safety config remains blocked');
$bad->config['auto_on']=false;
unset($bad->config['auto_off']);
try { $bad->request(1); ok(false, 'missing auto_off field cannot be treated as false'); }
catch (RuntimeException $e) {}
ok(!$bad->relay, 'missing auto_off field remains blocked');
$bad->config['auto_off']=false;
$bad->config['in_mode']='follow';
try { $bad->request(1); ok(false, 'SW should be detached'); }
catch (RuntimeException $e) {}
ok(!$bad->relay, 'wrong SW mode cannot energize contact');
$bad->config['in_mode']='detached';
$bad->props['ModeSwitchWiringConfirmed']=false;
try { $bad->request(1); ok(false, 'unconfirmed wiring should reject'); }
catch (RuntimeException $e) {}
ok(!$bad->relay, 'missing wiring acknowledgement blocks cooling');
$bad->props['ModeSwitchWiringConfirmed']=true;
$bad->props['DewPointAutoPumpEnabled']=false;
try { $bad->request(1); ok(false, 'missing pump guard should reject'); }
catch (RuntimeException $e) {}
ok(!$bad->relay, 'pump safety guard required when auto-switching');
$bad->props['DewPointAutoPumpEnabled']=true;
$bad->props['ModeSwitchShellyIP']='192.168.103.67';
try { $bad->request(1); ok(false, 'same as pump plug should reject'); }
catch (RuntimeException $e) {}
ok(!$bad->relay, 'independent actuator IP required');

$bad->props['ModeSwitchShellyIP']='192.168.103.88';
$bad->ignoreCommands=true;
try { $bad->request(1); ok(false, 'unverified ON should reject'); }
catch (RuntimeException $e) {}
ok($bad->mode===0 && $bad->attrs['ModeSwitchBlocked'],
    'failed readback cannot claim automatic cooling');
$bad->ignoreCommands=false;
$bad->request(1);
ok($bad->relay, 'successful user retry can arm cooling again');
$bad->readiness=false;
$bad->ModeSwitchTick();
ok(!$bad->relay && $bad->attrs['ModeSwitchBlocked'],
    'loss of cooling readiness stops renewals and verifies OFF');
$bad->readiness=true;
$before=count($bad->rpcCalls);
$bad->ModeSwitchTick();
ok(!$bad->relay && count(array_filter(array_slice($bad->rpcCalls, $before),
    fn($x)=>$x[0]==='Switch.Set' && $x[1]['on']==='true'))===0,
    'blocked watchdog cannot silently restart cooling');
$bad->request(1);
ok($bad->relay && !$bad->attrs['ModeSwitchBlocked'],
    'explicit renewed cooling request clears block');
$bad->online=false;
$bad->ModeSwitchTick();
ok($bad->attrs['ModeSwitchBlocked']
    && str_contains($bad->values['ModeSwitchStatus'], 'NICHT BESTAETIGT'),
    'communication failure halts renewal and reports unknown output');
$before=count($bad->rpcCalls);
$bad->online=true;
$bad->ModeSwitchTick();
ok(count(array_filter(array_slice($bad->rpcCalls, $before),
    fn($x)=>$x[0]==='Switch.Set' && $x[1]['on']==='true'))===0,
    'returning WLAN cannot silently restart blocked cooling');
ok(!$bad->relay, 'after communications recover blocked relay is driven OFF');
$bad->request(0);
ok($bad->mode===0 && !$bad->relay, 'explicit HEIZEN recovers to verified OFF');

echo "Mode-switch Shelly 1 Gen3: ".$GLOBALS['tests']." assertions OK\n";
