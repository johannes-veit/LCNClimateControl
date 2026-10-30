<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/LCNClimateControl/ModeSwitch.php';

function ok(bool $value, string $message): void
{
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
    if (!$value) { fwrite(STDERR, 'FAIL: ' . $message . "\n"); exit(1); }
}
if (!function_exists('IPS_SemaphoreEnter')) {
    function IPS_SemaphoreEnter(string $key, int $timeout): bool { return true; }
    function IPS_SemaphoreLeave(string $key): void {}
}
class ModeSwitchMock
{
    use LCNClimateModeSwitch;
    private const MODE_HEATING = 0;
    private const MODE_COOLING = 1;
    public int $InstanceID = 123;
    public int $mode = 0;
    public array $props = [
        'ModeSwitchSource' => 0, 'ModeSwitchShellyIP' => '192.168.103.71',
        'DewPointPumpIP' => '192.168.103.67',
        'ModeSwitchWiringConfirmed' => true, 'DewPointAutoPumpEnabled' => true
    ];
    public array $attrs = ['ModeSwitchBlocked' => true];
    public array $values = [];
    public array $rpcCalls = [];
    public bool $relay = false;
    public bool $online = true;
    public bool $ignoreCommands = false;
    public bool $badReadOnce = false;
    public bool $readiness = true;
    public bool $runtimeSafe = true;
    public ?string $timer = null;
    public bool $cancelTimerOnSet = true;
    public array $config = [
        'initial_state' => 'on', 'in_mode' => 'detached',
        'auto_on' => false, 'auto_off' => false
    ];

    public function ReadPropertyInteger(string $k): int { return (int)($this->props[$k] ?? 0); }
    public function ReadPropertyString(string $k): string { return (string)($this->props[$k] ?? ''); }
    public function ReadPropertyBoolean(string $k): bool { return (bool)($this->props[$k] ?? false); }
    public function ReadAttributeBoolean(string $k): bool { return (bool)($this->attrs[$k] ?? false); }
    public function WriteAttributeBoolean(string $k, bool $v): void { $this->attrs[$k] = $v; }
    public function WriteAttributeInteger(string $k, int $v): void { $this->attrs[$k] = $v; }
    public function ReadAttributeInteger(string $k): int { return (int)($this->attrs[$k] ?? 0); }
    public function SetValueIfChanged(string $k, mixed $v): void { $this->values[$k] = $v; }
    public function SendDebug(string $k, string $msg, int $lvl): void {}
    private function GetMode(): int { return $this->mode; }
    private function CoolingCommissionReadinessErrors(): array { return $this->readiness ? [] : ['not ready']; }
    private function DirectCoolingSafe(): bool { return $this->runtimeSafe; }
    protected function ModeSwitchRpc(string $method, array $params = []): array
    {
        $this->rpcCalls[] = [$method, $params];
        if (!$this->online) throw new RuntimeException('offline');
        if ($method === 'Switch.GetConfig') return $this->config;
        if ($method === 'Switch.GetStatus') {
            if ($this->badReadOnce) { $this->badReadOnce = false; throw new RuntimeException('read failed'); }
            return ['output' => $this->relay] + ($this->timer === null ? [] : ['timer_type' => $this->timer]);
        }
        if ($method === 'Switch.Set') {
            if (!$this->ignoreCommands) {
                $this->relay = $params['on'] === 'true';
                if ($this->cancelTimerOnSet) $this->timer = null;
            }
            return ['was_on' => !$this->relay];
        }
        throw new RuntimeException('Unknown method');
    }
    public function request(int $mode): void
    {
        (new ReflectionMethod(self::class, 'ModeSwitchSelect'))->invoke($this, $mode);
        $this->mode = $mode; // Simulates module update AFTER confirmed relay
    }
    public function commands(): array
    {
        return array_values(array_filter($this->rpcCalls, fn(array $v) => $v[0] === 'Switch.Set'));
    }
}

$m = new ModeSwitchMock();
$m->request(1);
$m->ModeSwitchTick();
ok($m->mode === 1 && !$m->relay && count($m->rpcCalls) === 0,
    'wall switch selection makes no RPC calls');

$m->props['ModeSwitchSource'] = 1;
$m->mode = 0;
$m->request(0);
ok($m->relay && $m->mode === 0, 'HEIZEN closes I/O (Shelly ON)');
ok($m->ReadAttributeInteger('ModeSwitchLastVerifiedAt') === 0,
    'heating has no cached cooling authorization');
$cmds = $m->commands();
ok(count($cmds) === 1 && $cmds[0][1]['on'] === 'true'
    && !array_key_exists('toggle_after', $cmds[0][1]),
    'heating ON is permanent, never uses old dangerous 35-second lease');
$before = count($cmds);
$m->ModeSwitchTick();
ok(count($m->commands()) === $before && $m->relay,
    'heating watchdog only reads unchanged ON contact');

$m->request(1);
ok(!$m->relay && $m->mode === 1 && !$m->attrs['ModeSwitchBlocked'],
    'KUEHLEN opens I/O (Shelly OFF), unlocks separate pump guard');
ok($m->ReadAttributeInteger('ModeSwitchLastVerifiedAt') >= time() - 1,
    'cooling command stamps fresh physical contact verification');
$cmds = $m->commands();
ok(end($cmds)[1]['on'] === 'false'
    && !array_key_exists('toggle_after', end($cmds)[1]),
    'cooling OFF is likewise permanent, not time-limited');
$before = count($cmds);
$m->WriteAttributeInteger('ModeSwitchLastVerifiedAt', time() - 20);
$m->ModeSwitchTick();
ok(!$m->relay && count($m->commands()) === $before,
    'cooling watchdog reads OFF and never toggles it itself');
ok($m->ReadAttributeInteger('ModeSwitchLastVerifiedAt') >= time() - 1,
    'cooling watchdog refreshes the separate pump permission timestamp');
$m->request(0);
ok($m->relay && $m->mode === 0, 'HEIZEN again forces relay ON');
ok($m->ReadAttributeInteger('ModeSwitchLastVerifiedAt') === 0,
    'heating transition instantly removes previously cached cooling permission');

$m->relay = false; // accidental APP or secondary controller change
$m->ModeSwitchTick();
ok($m->relay && str_contains($m->values['ModeSwitchStatus'], 'HEIZEN'),
    'in HEIZEN force I/O ON when status unexpectedly OFF');

$m->timer = 'toggle_after'; // from previous 0.5.16 behavior
$m->ModeSwitchTick();
ok($m->timer === null && $m->relay,
    'migrate by clearing old 35-second auto-OFF lease');
$m->timer = 'toggle_after';
$m->cancelTimerOnSet = false;
try { $m->request(0); ok(false, 'uncancellable old auto-OFF must be rejected'); }
catch (RuntimeException $e) {}
ok(str_contains($m->values['ModeSwitchStatus'], 'nicht bestaetigt'),
    'old auto-OFF lease failure is diagnosed instead of claiming heating');
$m->cancelTimerOnSet = true;
$m->timer = null;

// A Shelly may also keep an auto_off timer from an older device setting.
$m->timer = 'auto_off';
$m->ModeSwitchTick();
ok($m->timer === null && $m->relay,
    'any active auto_off timer in HEIZEN is cleared before it can open I/O');
$m->timer = 'auto_off';
$m->cancelTimerOnSet = false;
try { $m->request(0); ok(false, 'uncancellable auto_off must be rejected'); }
catch (RuntimeException $e) {}
ok(str_contains($m->values['ModeSwitchStatus'], 'nicht bestaetigt'),
    'uncancellable automatic timer is diagnosed instead of claiming HEIZEN');
$m->cancelTimerOnSet = true;
$m->timer = null;

$target = new ReflectionMethod(ModeSwitchMock::class, 'ModeSwitchTargetIP');
ok($target->invoke($m) === '192.168.103.71',
    'combined T100-T104 and mode contact device is 192.168.103.71');
$m->props['ModeSwitchShellyIP'] = '';
ok($target->invoke($m) === '192.168.103.71', 'legacy empty IP uses correct default');
$m->props['ModeSwitchShellyIP'] = '192.168.103.67';
try { $target->invoke($m); ok(false, 'pump plug must never be mode relay'); }
catch (RuntimeException $e) {}
$m->props['ModeSwitchShellyIP'] = '192.168.103.71';

$bad = new ModeSwitchMock();
$bad->props['ModeSwitchSource'] = 1;
$bad->config['initial_state'] = 'off'; // old unsafe setup
try { $bad->request(1); ok(false, 'power-on default OFF must be rejected'); }
catch (RuntimeException $e) {}
ok($bad->relay && $bad->mode === 0 && $bad->attrs['ModeSwitchBlocked'],
    'old configuration blocks cooling and restores physical heating ON');
$bad->config['initial_state'] = 'on';
unset($bad->config['auto_on']);
try { $bad->request(1); ok(false, 'missing auto_on must be rejected'); }
catch (RuntimeException $e) {}
ok($bad->relay, 'missing config keeps heating');
$bad->config['auto_on'] = false;
unset($bad->config['auto_off']);
try { $bad->request(1); ok(false, 'missing auto_off must be rejected'); }
catch (RuntimeException $e) {}
ok($bad->relay, 'missing auto_off keeps heating');
$bad->config['auto_off'] = false;
$bad->config['in_mode'] = 'follow';
try { $bad->request(1); ok(false, 'connected switch mode must be detached'); }
catch (RuntimeException $e) {}
ok($bad->relay, 'invalid input mode keeps heating');
$bad->config['in_mode'] = 'detached';
$bad->props['ModeSwitchWiringConfirmed'] = false;
try { $bad->request(1); ok(false, 'not wired must reject'); }
catch (RuntimeException $e) {}
ok($bad->relay, 'unapproved wiring keeps heating');
$bad->props['ModeSwitchWiringConfirmed'] = true;
$bad->props['DewPointAutoPumpEnabled'] = false;
try { $bad->request(1); ok(false, 'pump dew point guard missing must reject'); }
catch (RuntimeException $e) {}
ok($bad->relay, 'pump guard missing keeps heating');
$bad->props['DewPointAutoPumpEnabled'] = true;
$bad->ignoreCommands = true;
try { $bad->request(1); ok(false, 'relay refuses OFF, no cooling'); }
catch (RuntimeException $e) {}
ok($bad->mode === 0 && $bad->relay && $bad->attrs['ModeSwitchBlocked'],
    'unconfirmed OFF cannot change Symcon mode or plant to cooling');
$bad->ignoreCommands = false;
$bad->request(1);
ok(!$bad->relay && $bad->mode === 1, 'manual retry after fix authorizes cooling');

// In normal active cooling, some direct-LCN regulators are intentionally
// locked. The startup commissioning preflight would reject that, but the
// runtime-mode contact must NOT flip to HEIZEN because of those locks.
$bad->readiness = false;
$bad->ModeSwitchTick();
ok(!$bad->relay && !$bad->attrs['ModeSwitchBlocked'],
    'active cooling continues despite expected locked regulators');
$bad->runtimeSafe = false;
$bad->ModeSwitchTick();
ok($bad->relay && $bad->attrs['ModeSwitchBlocked'],
    'cooling protection failure switches physical plant back to heating ON');
ok($bad->ReadAttributeInteger('ModeSwitchLastVerifiedAt') === 0,
    'cooling safety failure revokes last confirmed physical contact timestamp');
$bad->runtimeSafe = true;
$bad->readiness = true;
$before = count($bad->commands());
$bad->ModeSwitchTick();
ok($bad->relay && count(array_filter(array_slice($bad->commands(), $before),
    fn(array $c) => $c[1]['on'] === 'false')) === 0,
    'blocked cooling never automatically energizes cooling after guard recovers');
$bad->request(1);
ok(!$bad->relay && !$bad->attrs['ModeSwitchBlocked'],
    'only explicit Symcon cooling request releases blocked contact');
$bad->online = false;
$bad->ModeSwitchTick();
ok($bad->attrs['ModeSwitchBlocked']
    && str_contains($bad->values['ModeSwitchStatus'], 'NICHT BESTAETIGT'),
    'network fault diagnoses unknown physical state without claiming heating');
$bad->online = true;
$bad->ModeSwitchTick();
ok($bad->relay && $bad->attrs['ModeSwitchBlocked'],
    'on restored network blocked cooling drives heating ON');
$bad->request(0);
ok($bad->relay && $bad->mode === 0, 'heating request restores stable heating');
echo 'Mode-switch reversed polarity: ' . $GLOBALS['checks'] . " checks passed\n";
