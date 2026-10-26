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
// Outdoor-vs-indoor gradient: classify only after a genuine heating window.
$s = obs([], $t, 21.0, true);
$s = obs($s, $t+1800, 21.2, true);
$s = obs($s, $t+3600, 21.4, true);
verify(($s['gradient_rates']['heating']['delta_10_20']['n'] ?? 0) === 1,
    'indoor-outdoor gradient heating classification');
verify(!isset($s['gradient_rates']['heating']['delta_lt10']),
    'unexpected extra gradient band');

// Missing weather may still yield heating evidence, but not gradient evidence.
$s = [];
for ($i=0; $i<=2; ++$i) {
    $s = LCNThermalLearningModel::observe($s, [
        'time'=>$t+1800*$i, 'temp'=>21.0+0.2*$i, 'target'=>22.0,
        'valve'=>true, 'outside'=>null,
        'temp_sample_at'=>$t+1800*$i, 'valve_sample_at'=>$t+1800*$i
    ]);
}
verify(($s['rates']['heating']['all']['n'] ?? 0) === 1, 'weather-independent base rate');
verify(!isset($s['gradient_rates']), 'missing weather produced gradient evidence');

// Two-hour outdoor comparison classifies only sufficiently changed weather.
$s = [];
for ($i=0; $i<=2; ++$i) {
    $now = $t + 1800*$i;
    $s = LCNThermalLearningModel::observe($s, [
        'time'=>$now, 'temp'=>20.0 + $i*0.2, 'target'=>22.0,
        'valve'=>true, 'outside'=>9.0,
        'outside_lag'=>7.5, 'temp_sample_at'=>$now,
        'valve_sample_at'=>$now
    ]);
}
verify(($s['weather_trend_rates']['heating']['outside_rising']['n'] ?? 0) === 1,
    'two-hour rising outdoor trend missing');
verify(!isset($s['weather_trend_rates']['heating']['outside_falling']),
    'incorrect weather trend classification');

// Normalized passive-cooling indicator: 0.2 C/h with a 20 K
// indoor/outdoor difference corresponds to 0.1 C/h per 10 K.
$s = [];
for ($i=0; $i<=2; ++$i) {
    $when = $t + $i * 1800;
    $s = LCNThermalLearningModel::observe($s, [
        'time'=>$when, 'temp'=>22.0 - 0.1*$i, 'target'=>22.0,
        'valve'=>false, 'outside'=>2.0,
        'temp_sample_at'=>$when, 'valve_sample_at'=>$when
    ]);
}
verify(($s['loss_proxy']['n'] ?? 0) === 1, 'normalized cooling sample missing');
verify(abs((float) $s['loss_proxy']['mean'] - 0.1005) < 0.01,
    'normalized cooling magnitude incorrect');

// A relay may toggle twice between 30-minute observations, ending in the
// original state. Its native VariableChanged timestamp must invalidate the window.
$s = LCNThermalLearningModel::observe([], [
    'time'=>$t, 'temp'=>20.0, 'target'=>22.0, 'valve'=>true,
    'outside'=>8.0, 'temp_sample_at'=>$t, 'valve_sample_at'=>$t,
    'valve_changed_at'=>$t-3600
]);
$s = LCNThermalLearningModel::observe($s, [
    'time'=>$t+1800, 'temp'=>20.2, 'target'=>22.0, 'valve'=>true,
    'outside'=>8.0, 'temp_sample_at'=>$t+1800, 'valve_sample_at'=>$t+1800,
    'valve_changed_at'=>$t+900
]);
verify(($s['hidden_switches'] ?? 0) === 1, 'intermediate relay switch not detected');
verify(($s['rates']['heating']['all']['n'] ?? 0) === 0, 'switched interval was learned');

// Long-term statistics must remain bounded and distinguish low from high spread.
$stat = [];
for ($i=0; $i<10050; ++$i) {
    // Synthetic stable 60-minute heating windows, state reset each time;
    // exercise the same aggregate by merging it into the next scenario.
    $base = ['rates'=>['heating'=>['all'=>$stat]]];
    $base = obs($base, $t, 20.0, true);
    $base = obs($base, $t+1800, 20.1, true);
    $base = obs($base, $t+3600, 20.2, true);
    $stat = $base['rates']['heating']['all'] ?? [];
}
verify(($stat['n'] ?? 0) === 10000, 'statistic counter must saturate');
verify(($stat['variance'] ?? -1) >= 0, 'variance missing');
verify(count($stat) === 5, 'statistic schema should stay constant');
verify(isset($stat['long_mean']) && is_finite((float) $stat['long_mean']),
    'long-term baseline missing');
verify(($stat['quality_n'] ?? 0) === 10000, 'quality sample count saturates');

// A legacy average cannot claim historical statistical confidence.
$s = ['rates'=>['heating'=>['all'=>['n'=>100,'mean'=>0.2]]]];
$s = obs($s, $t, 20.0, true);
$s = obs($s, $t+1800, 20.1, true);
$s = obs($s, $t+3600, 20.2, true);
verify(($s['rates']['heating']['all']['quality_n'] ?? -1) === 1,
    'legacy variance must begin with a fresh observation count');
verify(($s['rates']['heating']['all']['n'] ?? 0) === 101,
    'legacy count must be preserved');
verify(abs((float) ($s['rates']['heating']['all']['long_mean'] ?? NAN) - 0.2) < 0.001,
    'long baseline must start from the existing legacy mean');

// A switched relay in the middle of an otherwise steady two-hour phase
// must not generate a false full-duration heating rate.
$s = [];
for ($i = 0; $i <= 2; ++$i) {
    $when = $t + 1800 * $i;
    $s = LCNThermalLearningModel::observe($s, [
        'time' => $when, 'temp' => 20.0 + 0.2*$i,
        'target'=>22.0, 'valve'=>true, 'outside'=>8.0,
        'temp_sample_at'=>$when, 'valve_sample_at'=>$when,
        'valve_changed_at'=>$i === 0 ? $t-3600 : $t+900
    ]);
}
verify(($s['rates']['heating']['all']['n'] ?? 0) === 0,
    'relay interruption must not produce a full heating window');

// Fresh valid stable observations should still learn successfully.
$s = [];
for ($i=0; $i<=2; ++$i) {
    $when = $t + 1800*$i;
    $s = LCNThermalLearningModel::observe($s, [
        'time'=>$when, 'temp'=>20.0 + 0.2*$i,
        'target'=>22.0, 'valve'=>true, 'outside'=>8.0,
        'temp_sample_at'=>$when, 'valve_sample_at'=>$when,
        'valve_changed_at'=>$t-3600
    ]);
}
verify(($s['rates']['heating']['all']['n'] ?? 0) === 1,
    'continuous genuine relay phase should be learned');

// Two time scales: recent behaviour must respond to a change without
// immediately overwriting the multi-month/multi-year thermal baseline.
$trendStats = [];
for ($i = 0; $i < 150; ++$i) {
    $rate = $i < 130 ? 0.10 : 0.40; // last 20 periods have a new heating rate
    $base = ['rates' => ['heating' => ['all' => $trendStats]]];
    $base = obs($base, $t, 20.0, true);
    $base = obs($base, $t+1800, 20.0 + $rate/2, true);
    $base = obs($base, $t+3600, 20.0 + $rate, true);
    $trendStats = $base['rates']['heating']['all'] ?? [];
}
verify((float) ($trendStats['mean'] ?? 0) >
    (float) ($trendStats['long_mean'] ?? 0) + 0.15,
    'recent heating response should differ from long-term baseline');
verify((float) ($trendStats['long_mean'] ?? 0) > 0.10 &&
    (float) ($trendStats['long_mean'] ?? 0) < 0.20,
    'long baseline must not react as fast as recent EWMA');

// Sensor/relay source audit: 30-minute spacing, no raw readings retained.
$audit = [];
for ($i = 0; $i < 8; ++$i) {
    $audit = LCNThermalLearningModel::auditSource($audit, 'OK', $t + $i * 1800);
}
verify(($audit['checks'] ?? 0) === 8, 'source audit counts one 30-min window');
verify(LCNThermalLearningModel::auditLabel($audit) === 'QUELLEN_REGELMAESSIG',
    'stable read-only sources must be declared regular');
$auditDuplicate = LCNThermalLearningModel::auditSource($audit, 'RELAY_STALE', $t+7*1800+5);
verify($auditDuplicate === $audit, 'rapid manual extra run must not double-count');
for ($i = 8; $i < 13; ++$i) {
    $audit = LCNThermalLearningModel::auditSource($audit, 'RELAY_STALE', $t + $i * 1800);
}
verify(LCNThermalLearningModel::auditLabel($audit) === 'DATENQUELLE_BLOCKIERT',
    'repeated stale relay must block source readiness');
verify(($audit['bad_streak'] ?? 0) === 5, 'bad source streak not counted');
// A prolonged outage invalidates earlier preflight approval, even when
// the first post-outage reading is current and otherwise looks healthy.
$recoveredAt = $t + 13 * 1800 + 7200;
$audit = LCNThermalLearningModel::auditSource($audit, 'OK', $recoveredAt);
verify(($audit['checks'] ?? -1) === 1 &&
    LCNThermalLearningModel::auditLabel($audit) === 'BEOBACHTUNG (1/6)',
    'long-gap source readiness must be requalified');
for ($i = 1; $i < 6; ++$i) {
    $audit = LCNThermalLearningModel::auditSource(
        $audit, 'OK', $recoveredAt + 1800*$i);
}
verify(LCNThermalLearningModel::auditLabel($audit) === 'QUELLEN_REGELMAESSIG',
    'six fresh stable updates must requalify recovered relay');
verify(LCNThermalLearningModel::auditLabel($audit, $recoveredAt + 5*1800+2701)
    === 'ABNAHME_VERALTET',
    'offline preflight must expire after 45 minutes without a new audit');
verify(LCNThermalLearningModel::auditLabel($audit, $recoveredAt + 5*1800)
    === 'QUELLEN_REGELMAESSIG',
    'recent qualified sources must remain eligible');
verify(LCNThermalLearningModel::auditLabel($audit, $recoveredAt + 5*1800-1)
    === 'ABNAHME_VERALTET',
    'future-dated source audit must not authorize learning');

$compacted = LCNThermalLearningModel::compactRoom([
    'source_audit' => $audit + ['unbounded_events' => str_repeat('X', 100000)]
]);
verify(!isset($compacted['source_audit']['unbounded_events']),
    'source audit must not persist arbitrary history');
verify(strlen(json_encode($compacted, JSON_THROW_ON_ERROR)) < 350,
    'compact audit exceeds intended size');

echo "30-minute SMART-FBH model regression tests passed\n";
