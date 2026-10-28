<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/LCNClimateControl/DewPointGuard.php';

const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_INTEGER = 1;

function check(bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: " . $message . "\n");
        exit(1);
    }
}
$dew = LCNClimateDewPointLogic::dewPoint(24.0, 60.0);
check($dew !== null && $dew > 15.0 && $dew < 17.0, 'Magnus dewpoint');
check(LCNClimateDewPointLogic::dewPoint(24.0, 0.0) === null, 'RH zero rejected');
check(LCNClimateDewPointLogic::dewPoint(24.0, 120.0) === null, 'RH too high rejected');
check(!LCNClimateDewPointLogic::allow(null, 16.0, true), 'missing water = STOP');
check(!LCNClimateDewPointLogic::allow(17.5, 16.0, true), '1.5 K boundary stops');
check(LCNClimateDewPointLogic::allow(17.51, 16.0, true), 'existing cooling continues above 1.5 K');
check(!LCNClimateDewPointLogic::allow(17.9, 16.0, false), 'restart below 2.0 K blocked');
check(!LCNClimateDewPointLogic::allow(18.0, 16.0, false), 'restart exactly 2.0 K still blocked');
check(LCNClimateDewPointLogic::allow(18.1, 16.0, false), 'restart with hysteresis');
check(!LCNClimateDewPointLogic::allow(INF, 16.0, true), 'infinite water forbidden');

$GLOBALS['dew_vars'] = [
    101 => ['v' => 18.5, 'updated' => time(), 'kind' => VARIABLETYPE_FLOAT],
    102 => ['v' => 24.0, 'updated' => time(), 'kind' => VARIABLETYPE_FLOAT],
    103 => ['v' => 60.0, 'updated' => time(), 'kind' => VARIABLETYPE_FLOAT],
    104 => ['v' => 22.0, 'updated' => time(), 'kind' => VARIABLETYPE_FLOAT],
    105 => ['v' => 50.0, 'updated' => time(), 'kind' => VARIABLETYPE_FLOAT],
];
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['dew_vars'][$id]); }
function IPS_GetVariable(int $id): array {
    return [
        'VariableUpdated' => $GLOBALS['dew_vars'][$id]['updated'],
        'VariableType' => $GLOBALS['dew_vars'][$id]['kind']
    ];
}
function GetValue(int $id): mixed { return $GLOBALS['dew_vars'][$id]['v']; }
function IPS_SemaphoreEnter(string $name, int $time): bool { return true; }
function IPS_SemaphoreLeave(string $name): void {}

class DewPointGuardStub {
    use LCNClimateDewPointGuard;
    public int $InstanceID = 123;
    public array $p = [
        'DewPointWaterVariable' => 101, 'DewPointMaxAgeSeconds' => 900,
        'DewPointGuardEnabled' => true, 'DewPointGuardSimulation' => true,
        'DewPointMarginK' => 1.5, 'DewPointHysteresisK' => 0.5,
        'DewPointMinOffSeconds' => 60
    ];
    public array $rooms = [
        ['Enabled' => true, 'Name' => 'EG', 'TemperatureVariable' => 102, 'HumidityVariable' => 103],
        ['Enabled' => true, 'Name' => 'OG', 'TemperatureVariable' => 104, 'HumidityVariable' => 105]
    ];
    public array $a = ['DewPointGuardState' => 'UNKNOWN', 'DewPointGuardSafeSince' => 0, 'DewPointGuardLastStop' => 0];
    public array $values = [];
    public function ReadPropertyBoolean(string $key): bool { return (bool) $this->p[$key]; }
    public function ReadPropertyInteger(string $key): int { return (int) $this->p[$key]; }
    public function ReadPropertyFloat(string $key): float { return (float) $this->p[$key]; }
    public function ReadPropertyString(string $key): string { return (string) ($this->p[$key] ?? ''); }
    public function ReadAttributeString(string $key): string { return (string) ($this->a[$key] ?? ''); }
    public function ReadAttributeInteger(string $key): int { return (int) ($this->a[$key] ?? 0); }
    public function WriteAttributeString(string $key, string $value): void { $this->a[$key] = $value; }
    public function WriteAttributeInteger(string $key, int $value): void { $this->a[$key] = $value; }
    public function SetValueIfChanged(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function GetRooms(): array { return $this->rooms; }
    public function SendDebug(string $kind, string $message, int $level): void {}
    public function inspect(): array { return $this->DewPointGuardSnapshot(); }
}
$guard = new DewPointGuardStub();
$s = $guard->inspect();
check(($s['ok'] ?? false) && $s['room'] === 'EG', 'hottest dewpoint selected');
$guard->DewPointGuardTick();
check(str_contains($guard->values['DewPointGuardStatus'], 'BEOBACHTUNG'), 'simulation no I/O');
$guard->rooms[1]['HumidityVariable'] = 0;
check(($guard->inspect()['ok'] ?? true) === false, 'missing one humidity blocks whole plant');
$guard->rooms[1]['HumidityVariable'] = 105;
$GLOBALS['dew_vars'][105]['updated'] = time() - 1200;
check(($guard->inspect()['ok'] ?? true) === false, 'stale humidity fails closed');
$GLOBALS['dew_vars'][105]['updated'] = time();
$GLOBALS['dew_vars'][101]['v'] = -127.0;
check(($guard->inspect()['ok'] ?? true) === false, 'invalid DS18B20 value fails closed');
echo "Dewpoint guard pure logic and input simulations OK\n";
