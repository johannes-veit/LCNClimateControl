<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/LCNClimateControl/ThermalLearning.php';

function check_learning(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "SMART-FBH test failed: " . $message . "\n");
        exit(1);
    }
}

function record_at(array $state, int $time, float $temp, bool $valve,
    float $setpoint = 22.0, ?float $outdoor = 4.0): array
{
    return LCNThermalLearningModel::observe($state, [
        'time' => $time, 'temp' => $temp, 'target' => $setpoint,
        'valve' => $valve, 'outside' => $outdoor
    ]);
}
$start = 1792970000;
$s = [];
$s = record_at($s, $start, 20.0, true);
for ($i = 1; $i <= 24; $i++) {
    $s = record_at($s, $start + $i * 300, 20.0 + 0.2 * ($i / 12), true);
}
$heat = $s['rates']['heating']['all'] ?? [];
check_learning((int) ($heat['n'] ?? 0) === 2, '120 min contain two non-overlapping 60-min heat windows');
check_learning(abs((float) ($heat['mean'] ?? 0) - 0.2) < 0.01, 'heat rate measured in degrees/hour');
check_learning(isset($s['rates']['heating']['cold']), 'outdoor context preserved in cold band');
check_learning(!isset($s['rates']['passive_cooling']['all']), 'no false passive cooling while valve on');

// Turning a valve off creates an afterheat episode. Stored slab heat can
// temporarily increase room temperature. Count only after a peak has passed.
$s = record_at($s, $start + 25 * 300, 20.42, false);
$s = record_at($s, $start + 26 * 300, 20.52, false);
$s = record_at($s, $start + 27 * 300, 20.60, false);
$s = record_at($s, $start + 28 * 300, 20.45, false);
$after = $s['afterheat_model']['gain'] ?? [];
check_learning((int) ($after['n'] ?? 0) === 1, 'one completed afterheat observation');
check_learning(abs((float) ($after['mean'] ?? 0) - 0.18) < 0.01, 'afterheat starts at shutdown temperature');

// The next stable off-window learns natural cooling, but not active floor cooling.
$s = record_at($s, $start + 29 * 300, 20.44, false);
for ($i = 30; $i <= 41; $i++) {
    $s = record_at($s, $start + $i * 300, 20.44 - 0.22 * (($i - 29) / 12), false);
}
check_learning((int) ($s['rates']['passive_cooling']['all']['n'] ?? 0) >= 1,
    'natural cooling estimated from stable valve-off episode');

// New target invalidates the observation window; do not fake a learning sample.
$before = (int) ($s['rates']['passive_cooling']['all']['n'] ?? 0);
$s = record_at($s, $start + 42 * 300, 20.30, false, 19.0);
check_learning((int) ($s['rates']['passive_cooling']['all']['n'] ?? 0) === $before,
    'setpoint change does not become learned passive cooling');

// Gaps, implausible temperature jumps and invalid relay feedback cannot contribute.
$s = record_at($s, $start + 60 * 300, 20.2, false, 19.0);
check_learning((int) ($s['resets'] ?? 0) >= 2, 'measurement gap forces reset');
$old = $s;
$s = record_at($s, $start + 61 * 300, 45.5, false, 19.0);
check_learning($s === $old, 'impossible temperature rejected without mutation');
$s = LCNThermalLearningModel::observe($s, [
    'time' => $start + 62 * 300, 'temp' => 20.0,
    'target' => 19.0, 'valve' => null
]);
check_learning($s === $old, 'unknown relay state rejected without mutation');

$source = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/ThermalLearning.php');
check_learning(is_string($source), 'learner source readable');
check_learning(!preg_match('/\\b(?:LCN_SendCommand|LCN_SetRelay|LCN_SwitchRelay|LCN_LockTargetValue|LCN_ReleaseTargetValue|IPS_RequestAction|RequestAction)\\s*\\(/', $source),
    'learner cannot call LCN commands or perform IPS actions');

$module = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
check_learning(is_string($module), 'module source readable');
check_learning(str_contains($module, "RegisterPropertyBoolean('ThermalLearningEnabled', false)"),
    'learning disabled by default');
check_learning(str_contains($module, "LCNC_ThermalLearningTick"),
    'learning timer correctly wired');
check_learning(!str_contains($module, "ThermalLearningTick();"),
    'learner not called from an existing control path');
echo "SMART-FBH passive thermal learning model and isolation OK\n";
