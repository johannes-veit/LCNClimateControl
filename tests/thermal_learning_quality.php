<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/LCNClimateControl/ThermalLearning.php';

function verify(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); }
}
function obs(array $s, int $time, float $temp, bool $valve,
             ?int $tempAt = null, ?int $relayAt = null): array {
    return LCNThermalLearningModel::observe($s, [
        'time' => $time, 'temp' => $temp, 'target' => 22.0,
        'valve' => $valve, 'outside' => 8.0,
        'temp_sample_at' => $tempAt ?? $time,
        'valve_sample_at' => $relayAt ?? $time
    ]);
}
$t = 1700000000;
// Real 30-minute rhythm, 60-minute heating learning window.
$s = obs([], $t, 20.0, true);
$s = obs($s, $t+1800, 20.2, true);
$s = obs($s, $t+3600, 20.4, true);
verify(($s['rates']['heating']['all']['n'] ?? 0) === 1, 'heating rate');
// Valve closes: initial slab afterheat must never be passive cooling.
$s = obs($s, $t+5400, 20.5, false);
$s = obs($s, $t+7200, 20.7, false);
$s = obs($s, $t+9000, 20.5, false); // afterheat ends, cooling baseline resets
verify(($s['rates']['passive_cooling']['all']['n'] ?? 0) === 0, 'afterheat isolation');
$s = obs($s, $t+10800, 20.3, false);
$s = obs($s, $t+12600, 20.1, false);
verify(($s['rates']['passive_cooling']['all']['n'] ?? 0) === 1, 'passive cooling');

// Stale source data invalidate the old phase.
$s = obs([], $t, 20.0, true);
$s = obs($s, $t+1800, 20.2, true, $t, $t);
$s = obs($s, $t+3600, 20.4, true, $t, $t);
verify(($s['rejected_stale'] ?? 0) >= 1, 'stale source rejection');
verify(($s['rates']['heating']['all']['n'] ?? 0) === 0, 'stale-source suppression');

// A 60-minute monitoring gap cannot certify stable valve state.
$s = obs([], $t, 20.0, true);
$s = obs($s, $t+3600, 20.4, true);
verify(($s['resets'] ?? 0) === 1, 'gap reset');
echo "30-minute SMART-FBH model regression tests passed\n";
