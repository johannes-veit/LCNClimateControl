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

// A >30-minute interruption invalidates a continuous learning window.
$s = sample([], $t, 20.0, true);
$s = sample($s, $t + 3601, 20.5, true);
checkLearning(($s['resets'] ?? 0) === 1, 'Gap reset missing');
echo "SMART-FBH quality checks OK\n";
