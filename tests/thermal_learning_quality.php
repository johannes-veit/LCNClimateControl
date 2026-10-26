<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/LCNClimateControl/ThermalLearning.php';

function checkLearning(bool $ok, string $reason): void {
    if (!$ok) { throw new RuntimeException($reason); }
}
function sample(array $state, int $time, float $temp, bool $valve, ?int $sensorTime = null): array {
    return LCNThermalLearningModel::observe($state, [
        'time' => $time, 'temp' => $temp, 'target' => 22.0,
        'valve' => $valve, 'outside' => 8.0,
        'temp_sample_at' => $sensorTime ?? $time,
    ]);
}

// The valve has closed, but the warm slab continues raising room temperature.
// Such a window must NOT count as passive cooling.
$t = 1700000000;
$s = sample([], $t, 20.0, true);
$s = sample($s, $t + 3600, 20.4, true);
checkLearning(($s['rates']['heating']['all']['n'] ?? 0) === 1, 'Heating window missing');
$s = sample($s, $t + 3900, 20.5, false);
$s = sample($s, $t + 7500, 20.7, false);
checkLearning(($s['rates']['passive_cooling']['all']['n'] ?? 0) === 0, 'Residual slab heat contaminated cooling');
$s = sample($s, $t + 8100, 20.5, false); // afterheat closes; reset baseline
$s = sample($s, $t + 11700, 20.2, false);
checkLearning(($s['rates']['passive_cooling']['all']['n'] ?? 0) === 1, 'Post-afterheat cooling missing');

// A cached temperature with no newer source timestamp is not fresh evidence.
$s = sample([], $t, 20.0, true, $t);
$s = sample($s, $t + 3600, 20.3, true, $t);
checkLearning(($s['rates']['heating']['all']['n'] ?? 0) === 0, 'Stale sensor created a heating rate');
$s = sample($s, $t + 7200, 20.5, true, $t + 7200);
checkLearning(($s['rates']['heating']['all']['n'] ?? 0) === 1, 'New sensor evidence was ignored');

// A stale relay reading cannot prove an uninterrupted valve state.
$s = sample([], $t, 20.0, true);
$s = LCNThermalLearningModel::observe($s, [
    'time' => $t + 900, 'temp' => 20.1, 'target' => 22.0,
    'valve' => true, 'outside' => 8.0,
    'temp_sample_at' => $t + 900, 'valve_sample_at' => $t,
]);
checkLearning(!isset($s['last']) && ($s['rejected_stale'] ?? 0) === 1,
    'Stale relay did not invalidate the learning window');

// Fresh temperatures with a cached unchanged relay timestamp
// must not be counted as a full heating period.
$s = LCNThermalLearningModel::observe([], [
    'time' => $t, 'temp' => 20.0, 'target' => 22.0,
    'valve' => true, 'outside' => 8.0,
    'temp_sample_at' => $t, 'valve_sample_at' => $t,
]);
for ($k = 1; $k <= 12; ++$k) {
    $when = $t + 300 * $k;
    $s = LCNThermalLearningModel::observe($s, [
        'time' => $when, 'temp' => 20.0 + 0.025 * $k, 'target' => 22.0,
        'valve' => true, 'outside' => 8.0,
        'temp_sample_at' => $when, 'valve_sample_at' => $t,
    ]);
}
checkLearning(($s['rates']['heating']['all']['n'] ?? 0) === 0,
    'Cached relay state created a heating rate');

// A >30-minute interruption invalidates a continuous learning window.
$s = sample([], $t, 20.0, true);
$s = sample($s, $t + 3601, 20.5, true);
checkLearning(($s['resets'] ?? 0) === 1, 'Gap reset missing');
echo "SMART-FBH quality checks OK\n";
