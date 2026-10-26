<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/LCNClimateControl/ThermalLearning.php';

function expectLong(bool $condition, string $reason): void {
    if (!$condition) { throw new RuntimeException($reason); }
}

$start = 1761955200;
$days = 365 * 5; // 5 synthetic calendar years; fixed 30-minute intervals.
$steps = $days * 48;
$rooms = array_fill(0, 11, []);
$temps = array_fill(0, 11, 20.5);
$previousValve = array_fill(0, 11, null);
$relayChanged = array_fill(0, 11, $start - 3600);
$history = [];
$firstYearBytes = 0;
$peakBytes = 0;
$missingWeather = 0;
$invalidSources = 0;
$heatingEvidence = 0;
$coolingEvidence = 0;

for ($step = 0; $step < $steps; ++$step) {
    $now = $start + $step * 1800;
    $season = 2 * M_PI * $step / (48 * 365);
    $outdoor = 8.0 - 12.0 * cos($season) + 1.4 * sin($step * 0.008);
    if ($step % 67 === 0) {
        $outdoor = null;
        ++$missingWeather;
    }
    $weather = LCNThermalLearningModel::outdoorContext($history, $now, $outdoor);
    $history = $weather['points'];
    expectLong(count($history) <= 6, 'weather history exceeds six entries');
    for ($room = 0; $room < 11; ++$room) {
        $valve = intdiv($step + $room * 3, 16) % 2 === 0;
        if ($previousValve[$room] !== null && $valve !== $previousValve[$room]) {
            $relayChanged[$room] = $now;
        }
        $previousValve[$room] = $valve;
        $temps[$room] += ($valve ? 0.036 : -0.036)
            + 0.001 * sin($step * 0.06 + $room * 0.4);
        $temps[$room] = max(18.0, min(25.0, $temps[$room]));
        $sensorTime = $now;
        if (($step + $room * 17) % 2399 === 0 && $step > 0) {
            $sensorTime = $now - 3601; // stale source, fail closed
            ++$invalidSources;
        }
        $rooms[$room] = LCNThermalLearningModel::observe($rooms[$room], [
            'time' => $now,
            'temp' => round($temps[$room], 3),
            'target' => 21.5 + ($room % 3) * 0.5,
            'valve' => $valve,
            'outside' => $outdoor,
            'outside_lag' => $weather['lag'],
            'temp_sample_at' => $sensorTime,
            'valve_sample_at' => $now,
            'valve_changed_at' => $relayChanged[$room]
        ]);
        $rooms[$room]['name'] = 'Test Raum ' . $room;
        $rooms[$room] = LCNThermalLearningModel::compactRoom($rooms[$room]);
    }
    if ($step % 336 === 0 || $step === $steps - 1 || $step === 48 * 365 - 1) {
        $state = json_encode([
            'schema' => 1, 'rooms' => $rooms,
            'weather_history' => $history
        ], JSON_THROW_ON_ERROR);
        $bytes = strlen($state);
        $peakBytes = max($peakBytes, $bytes);
        expectLong($bytes < 131072, 'long-term state exceeded hard memory budget: ' . $bytes);
        if ($step === 48 * 365 - 1) { $firstYearBytes = $bytes; }
    }
}
for ($room = 0; $room < 11; ++$room) {
    $heat = $rooms[$room]['rates']['heating']['all']['n'] ?? 0;
    $cool = $rooms[$room]['rates']['passive_cooling']['all']['n'] ?? 0;
    expectLong($heat > 50, 'heating evidence missing: room ' . $room);
    expectLong($cool > 50, 'cooling evidence missing: room ' . $room);
    expectLong(($rooms[$room]['rejected_stale'] ?? 0) > 0, 'stale source was not recorded');
    $heatingEvidence += $heat;
    $coolingEvidence += $cool;
}
expectLong($missingWeather > 0 && $invalidSources > 0, 'failure scenarios absent');
expectLong($firstYearBytes > 0 && $bytes - $firstYearBytes < 8192,
    'persistent state is still growing with years: ' . $firstYearBytes . ' to ' . $bytes);

// Deep compaction also purges malicious or obsolete nested statistics.
$corrupted = $rooms[0];
$corrupted['rates']['heating']['junk'] = array_fill(0, 100000, 4);
$corrupted['new_unbounded_field'] = str_repeat('x', 100000);
$corrupted['house_context_by_valve']['valve_off']['never_seen'] = 99999999;
$cleaned = LCNThermalLearningModel::compactRoom($corrupted);
expectLong(!isset($cleaned['new_unbounded_field']), 'unbounded top-level field survived');
expectLong(!isset($cleaned['rates']['heating']['junk']), 'unbounded nested field survived');
expectLong(!isset($cleaned['house_context_by_valve']['valve_off']['never_seen']),
    'unexpected house count survived');
expectLong(strlen(json_encode($cleaned, JSON_THROW_ON_ERROR)) < 10000,
    'pruned room state still too large');

echo 'SMART-FBH 5-year / 11-room regression OK' . PHP_EOL;
echo json_encode([
    'years' => 5, 'rooms' => 11, 'samples' => $steps * 11,
    'year_one_bytes' => $firstYearBytes,
    'final_bytes' => $bytes, 'peak_bytes' => $peakBytes,
    'heating_windows' => $heatingEvidence, 'passive_cooling_windows' => $coolingEvidence,
    'missing_weather_windows' => $missingWeather,
    'stale_sensor_cases' => $invalidSources
], JSON_THROW_ON_ERROR) . PHP_EOL;
