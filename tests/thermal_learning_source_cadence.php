<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/LCNClimateControl/ThermalLearning.php';

function cadenceCheck(bool $success, string $message): void {
    if (!$success) { throw new RuntimeException($message); }
}

$start = 1793000000;
$audits = array_fill(0, 11, []);
$updates = array_fill(0, 11, $start);
$qualified = array_fill(0, 11, 0);
$reasons = array_fill(0, 11, []);
// 48 hours, 11 rooms, one tick every 30 minutes. No LCN control calls.
for ($tick = 0; $tick < 96; ++$tick) {
    $now = $start + 1800 * $tick;
    for ($room = 0; $room < 11; ++$room) {
        // Rooms 0-3: continuously refreshed relay and room temperature.
        // Rooms 4-7: relay is updated only at a four-hour switching event.
        // Rooms 8-10: healthy for 12 hours, then six hours without updates,
        // then healthy again (a new qualification is mandatory).
        if ($room <= 3) {
            $updates[$room] = $now;
        } elseif ($room <= 7) {
            if ($tick % 8 === 0) { $updates[$room] = $now; }
        } else {
            if ($tick < 24 || $tick >= 36) { $updates[$room] = $now; }
        }
        $reason = LCNThermalLearningModel::sourceReason($now, $now, $updates[$room]);
        $audits[$room] = LCNThermalLearningModel::auditSource(
            $audits[$room], $reason, $now);
        $label = LCNThermalLearningModel::auditLabel($audits[$room], $now);
        $reasons[$room][$label] = ($reasons[$room][$label] ?? 0) + 1;
        if ($label === 'QUELLEN_REGELMAESSIG') { ++$qualified[$room]; }
        cadenceCheck(($audits[$room]['checks'] ?? 0) <= 10000,
            'counter exceeded storage limit');
        cadenceCheck(count($audits[$room]) <= 6, 'source audit grew a new field');
    }
}
for ($room = 0; $room < 4; ++$room) {
    cadenceCheck(($qualified[$room] ?? 0) >= 90,
        'periodically refreshed relay never qualified');
}
for ($room = 4; $room < 8; ++$room) {
    cadenceCheck($qualified[$room] === 0,
        'switch-only relay must not be mistaken for regularly refreshed status');
    cadenceCheck(($reasons[$room]['QUELLEN_WECHSELND'] ?? 0) > 0,
        'switch-only relay needs a visible warning classification');
}
for ($room = 8; $room < 11; ++$room) {
    cadenceCheck(($reasons[$room]['DATENQUELLE_BLOCKIERT'] ?? 0) > 0,
        'source outage not detected');
    cadenceCheck($qualified[$room] >= 30,
        'source should requalify after recovery');
}
$previous = $audits[0];
cadenceCheck(LCNThermalLearningModel::auditLabel($previous, $start + 96*1800 + 4000)
    === 'ABNAHME_VERALTET', 'old positive readiness must expire on pause');
$restarted = LCNThermalLearningModel::auditSource(
    $previous, 'OK', $start + 96*1800 + 4000);
cadenceCheck($restarted['checks'] === 1
    && LCNThermalLearningModel::auditLabel($restarted) === 'BEOBACHTUNG (1/6)',
    'restarting after outage must reset readiness without losing thermal learning');

echo 'SMART-FBH 48h source-update cadence simulation passed' . PHP_EOL;
echo json_encode([
    'simulated_rooms'=>11,
    '30min_checks'=>96*11,
    'regular_qualified_checks'=>$qualified[0],
    'switch_only_qualified_checks'=>$qualified[4],
    'intermittent_recovered_checks'=>$qualified[8],
    'fixed_fields_per_source'=>count($audits[0])
], JSON_THROW_ON_ERROR) . PHP_EOL;
