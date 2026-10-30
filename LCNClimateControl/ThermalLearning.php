<?php
declare(strict_types=1);

/**
 * SMART-FBH phase 1: passive thermal learning.
 * NO LCN commands, NO setpoint changes, NO changes to heating/cooling control.
 * Only an explicit opt-in runs the separate timer.
 */
final class LCNThermalLearningModel
{
    private static function mean(array $entry, float $value): array
    {
        $n = min(10000, max(0, (int) ($entry['n'] ?? 0)) + 1);
        $old = isset($entry['mean']) ? (float) $entry['mean'] : $value;
        $next = $n === 1 ? $value : (0.85 * $old + 0.15 * $value);
        // Fixed-size dispersion estimate; no accumulated samples or history.
        $hasVariance = isset($entry['variance']) && is_numeric($entry['variance']);
        $oldVar = $hasVariance ? max(0.0, (float) $entry['variance']) : 0.0;
        $variance = $n === 1 ? 0.0 : 0.85 * ($oldVar + 0.15 * ($value - $old) ** 2);
        // Existing legacy averages have no known historical variance. Gather
        // ten fresh observations before describing their dispersion as stable.
        $qualityN = min(10000, max(0, (int) ($entry['quality_n'] ?? 0)) + 1);
        // Two time scales: recent EWMA and persistent slowly moving baseline.
        // Older models start their baseline at the existing estimate; no old
        // samples are invented. The count is capped at 10000 for bounded state.
        $longOld = isset($entry['long_mean']) && is_numeric($entry['long_mean'])
            && is_finite((float) $entry['long_mean'])
            ? (float) $entry['long_mean'] : $old;
        $longMean = $n === 1 ? $value : $longOld + ($value - $longOld) / $n;
        return ['n' => $n, 'mean' => round($next, 4), 'variance' => round($variance, 6),
            'quality_n' => $qualityN, 'long_mean' => round($longMean, 4)];
    }

    /**
     * Constant-size 60-minute rolling source average. Each 30-minute learner
     * tick contributes at most one fresh, timestamped observation per sensor.
     * Old or missing sensors never produce an invented mean.
     */
    public static function hydraulicRolling(array $previous, ?array $reading, int $now): array
    {
        $source = (int) ($reading['source'] ?? 0);
        $value = $reading['value'] ?? null;
        $at = (int) ($reading['updated'] ?? 0);
        $valid = $source > 0 && is_numeric($value) && is_finite((float) $value)
            && (float) $value >= -5 && (float) $value <= 65
            && $at > 0 && $at <= $now && $now - $at <= 2700;
        $points = [];
        if ($valid && (int) ($previous['source'] ?? 0) === $source) {
            foreach ((array) ($previous['points'] ?? []) as $point) {
                if (!is_array($point) || count($point) !== 2
                    || !is_numeric($point[0] ?? null) || !is_numeric($point[1] ?? null)) {
                    continue;
                }
                $when = (int) $point[0];
                $temp = (float) $point[1];
                if ($when >= $now - 3600 && $when < $now
                    && is_finite($temp) && $temp >= -5 && $temp <= 65) {
                    $points[] = [$when, $temp];
                }
            }
        }
        // A second learner tick with the same VariableUpdated is NOT an
        // independent sensor observation. Do not fabricate a 60-min mean
        // from one old water-temperature reading sampled multiple times.
        $sourceChanged = (int) ($previous['source'] ?? 0) !== $source;
        $newObservation = $valid && ($sourceChanged
            || $at > (int) ($previous['updated'] ?? 0));
        if ($newObservation) {
            $points[] = [$now, round((float) $value, 3)];
        }
        $points = array_slice($points, -4);
        $n = count($points);
        // At least two independent time slots, separated by at least 20 min.
        // The 30-min sampling schedule generally supplies 2-3 points per hour.
        $avg = $n >= 2 && $points[$n-1][0] - $points[0][0] >= 1200
            ? round(array_sum(array_column($points, 1)) / $n, 3) : null;
        return [
            'source' => $valid ? $source : 0,
            'updated' => $valid ? $at : 0,
            'value' => $valid ? round((float) $value, 3) : null,
            'mean60' => $avg, 'count' => $n, 'points' => $points
        ];
    }

    private static function supplyBand(float $supply): string
    {
        return $supply < 26.0 ? 'supply_lt26'
            : ($supply < 30.0 ? 'supply_26_30' : 'supply_ge30');
    }

    private static function outdoorBand(?float $outdoor): string
    {
        if ($outdoor === null) {
            return 'unknown';
        }
        return $outdoor < 0 ? 'frost' : ($outdoor < 8 ? 'cold' : ($outdoor < 18 ? 'mild' : 'warm'));
    }

    // Difference between indoor and outdoor air, not an inferred heat-loss coefficient.
    private static function gradientBand(float $delta): string
    {
        return $delta < 10.0 ? 'delta_lt10' : ($delta < 20.0 ? 'delta_10_20' : 'delta_ge20');
    }

    private static function finishAfterheat(array &$state): void
    {
        $after = $state['afterheat'] ?? null;
        if (!is_array($after) || empty($after['valid'])) {
            unset($state['afterheat']);
            return;
        }
        $gain = (float) ($after['peak'] ?? 0) - (float) ($after['begin_temp'] ?? 0);
        $minutes = ((int) ($after['peak_at'] ?? 0) - (int) ($after['begin_at'] ?? 0)) / 60.0;
        if ($gain >= 0.05 && $gain <= 2.5 && $minutes >= 0 && $minutes <= 180) {
            $state['afterheat_model']['gain'] = self::mean((array) ($state['afterheat_model']['gain'] ?? []), $gain);
            $state['afterheat_model']['delay_minutes'] = self::mean((array) ($state['afterheat_model']['delay_minutes'] ?? []), $minutes);
        }
        unset($state['afterheat']);
    }

    /**
     * Source readiness for 30-minute snapshots. A last value change is NOT
     * the same as a refreshed status update; only VariableUpdated proves
     * that this data source was reported again.
     */
    /** 0 means all rooms; nonzero restricts thermal samples to a single S1 room. */
    public static function isReferenceRoom(int $targetID, int $referenceID): bool
    {
        return $targetID > 0 && ($referenceID === 0 || $referenceID === $targetID);
    }

    public static function sourceReason(int $now, int $tempUpdated, int $relayUpdated): string
    {
        if ($tempUpdated <= 0 || $tempUpdated > $now
            || $now - $tempUpdated > 1800) {
            return 'TEMP_STALE';
        }
        if ($relayUpdated <= 0 || $relayUpdated > $now
            || $now - $relayUpdated > 1800) {
            return 'RELAY_STALE';
        }
        return 'OK';
    }

    /**
     * Compact source-readiness trend, recorded at most once per 25 minutes.
     * It qualifies SOURCE FRESHNESS, never proves physical heating behaviour.
     */
    public static function auditSource(array $old, string $reason, int $now): array
    {
        $previousAt = (int) ($old['last_checked_at'] ?? 0);
        if ($previousAt > 0 && $previousAt <= $now && $now - $previousAt < 1500) {
            return $old;
        }
        // A long pause/outage must not inherit a previously green source status.
        // Requalify from scratch after more than three normal 30-min slots.
        if ($previousAt > $now || ($previousAt > 0 && $now - $previousAt > 5400)) {
            $old = [];
        }
        $good = $reason === 'OK';
        $checks = min(10000, max(0, (int) ($old['checks'] ?? 0)) + 1);
        $previousRate = min(1.0, max(0.0,
            (float) ($old['recent_ok'] ?? ($good ? 1.0 : 0.0))));
        $recentRate = $checks === 1 ? ($good ? 1.0 : 0.0)
            : (0.875 * $previousRate + ($good ? 0.125 : 0.0));
        return [
            'checks' => $checks,
            'recent_ok' => round($recentRate, 4),
            'ok_streak' => $good
                ? min(10000, (int) ($old['ok_streak'] ?? 0) + 1) : 0,
            'bad_streak' => $good ? 0
                : min(10000, (int) ($old['bad_streak'] ?? 0) + 1),
            'last_reason' => $good ? 'OK' : $reason,
            'last_checked_at' => $now
        ];
    }

    public static function auditLabel(array $audit, ?int $now = null): string
    {
        // Live eligibility requires a recent audit, not merely a formerly
        // green value left behind after a long stopped timer or cooling mode.
        if ($now !== null) {
            $last = (int) ($audit['last_checked_at'] ?? 0);
            if ($last <= 0 || $last > $now || $now - $last > 2700) {
                return 'ABNAHME_VERALTET';
            }
        }
        $count = (int) ($audit['checks'] ?? 0);
        if ($count < 6) { return 'BEOBACHTUNG (' . $count . '/6)'; }
        if ((int) ($audit['bad_streak'] ?? 0) >= 4) {
            return 'DATENQUELLE_BLOCKIERT';
        }
        if ((float) ($audit['recent_ok'] ?? 0.0) >= 0.8
            && (int) ($audit['ok_streak'] ?? 0) >= 6
            && ($audit['last_reason'] ?? '') === 'OK') {
            return 'QUELLEN_REGELMAESSIG';
        }
        return 'QUELLEN_WECHSELND';
    }

    /**
     * Fixed-size shared outdoor context. Never store raw weather histories.
     * Return both the updated ring and the observation about two hours ago.
     */
    public static function outdoorContext(array $history, int $now, ?float $outdoor): array
    {
        $clean = [];
        foreach (array_slice($history, -12) as $point) {
            if (!is_array($point) || !is_numeric($point['t'] ?? null)
                || !is_numeric($point['c'] ?? null)) { continue; }
            $t = (int) $point['t'];
            $c = (float) $point['c'];
            if ($t <= $now && $t > $now - 10800
                && $t > 0 && is_finite($c) && $c >= -45 && $c <= 55) {
                $clean[] = ['t' => $t, 'c' => round($c, 2)];
            }
        }
        $clean = array_slice($clean, -6);
        $lag = null;
        if ($outdoor !== null && is_finite($outdoor)
            && $outdoor >= -45 && $outdoor <= 55) {
            foreach (array_reverse($clean) as $point) {
                $age = $now - $point['t'];
                if ($age >= 6300 && $age <= 8100) {
                    $lag = $point['c'];
                    break;
                }
            }
            $clean[] = ['t' => $now, 'c' => round($outdoor, 2)];
        }
        return ['points' => array_slice($clean, -6), 'lag' => $lag];
    }

    /**
     * Discard stale nested keys before persisting the years-long learning state.
     * All allowed categories are fixed: adding samples never adds JSON array rows.
     */
    public static function compactRoom(array $room): array
    {
        $allowed = ['name', 'last', 'phase', 'afterheat', 'afterheat_model',
            'rates', 'gradient_rates', 'weather_trend_rates', 'loss_proxy',
            'last_evidence_at', 'resets', 'rejected_stale', 'hidden_switches',
            'house_context_by_valve', 'source_temp_id', 'source_relay_id',
            'source_audit', 'heating_by_supply', 'heating_drive', 'heating_spread'];
        $room = array_intersect_key($room, array_flip($allowed));
        $stat = static function ($value): array {
            if (!is_array($value) || !is_numeric($value['n'] ?? null)
                || !is_numeric($value['mean'] ?? null)
                || !is_finite((float) $value['mean'])) {
                return [];
            }
            $s = ['n' => min(10000, max(0, (int) $value['n'])),
                'mean' => round((float) $value['mean'], 4)];
            if (isset($value['variance']) && is_numeric($value['variance'])
                && is_finite((float) $value['variance'])) {
                $s['variance'] = round(min(10000.0, max(0.0, (float) $value['variance'])), 6);
            }
            if (isset($value['quality_n'])) {
                $s['quality_n'] = min(10000, max(0, (int) $value['quality_n']));
            }
            if (isset($value['long_mean']) && is_numeric($value['long_mean'])
                && is_finite((float) $value['long_mean'])) {
                $s['long_mean'] = round((float) $value['long_mean'], 4);
            }
            return $s;
        };
        $statFamilies = [
            'rates' => [
                'heating' => ['all', 'unknown', 'frost', 'cold', 'mild', 'warm'],
                'passive_cooling' => ['all', 'unknown', 'frost', 'cold', 'mild', 'warm']
            ],
            'gradient_rates' => [
                'heating' => ['delta_lt10', 'delta_10_20', 'delta_ge20'],
                'passive_cooling' => ['delta_lt10', 'delta_10_20', 'delta_ge20']
            ],
            'weather_trend_rates' => [
                'heating' => ['outside_rising', 'outside_falling'],
                'passive_cooling' => ['outside_rising', 'outside_falling']
            ],
            'afterheat_model' => ['gain' => null, 'delay_minutes' => null],
            'heating_by_supply' => ['supply_lt26' => null,
                'supply_26_30' => null, 'supply_ge30' => null]
        ];
        foreach ($statFamilies as $family => $kinds) {
            $saved = is_array($room[$family] ?? null) ? $room[$family] : [];
            $clean = [];
            foreach ($kinds as $kind => $bands) {
                if ($bands === null) {
                    $v = $stat($saved[$kind] ?? null);
                    if ($v !== []) { $clean[$kind] = $v; }
                    continue;
                }
                $sub = is_array($saved[$kind] ?? null) ? $saved[$kind] : [];
                foreach ($bands as $band) {
                    $v = $stat($sub[$band] ?? null);
                    if ($v !== []) { $clean[$kind][$band] = $v; }
                }
            }
            if ($clean === []) { unset($room[$family]); }
            else { $room[$family] = $clean; }
        }
        foreach (['heating_drive', 'heating_spread'] as $metric) {
            $v = $stat($room[$metric] ?? null);
            if ($v === []) { unset($room[$metric]); }
            else { $room[$metric] = $v; }
        }
        $loss = $stat($room['loss_proxy'] ?? null);
        if ($loss === []) { unset($room['loss_proxy']); }
        else { $room['loss_proxy'] = $loss; }

        foreach (['last', 'phase'] as $field) {
            if (!is_array($room[$field] ?? null)) { unset($room[$field]); continue; }
            $room[$field] = array_intersect_key($room[$field], array_flip([
                'time','temp','target','valve','outside','temp_sample_at',
                'valve_sample_at','valve_changed_at','outside_lag',
                'supply60','return60'
            ]));
        }
        if (isset($room['afterheat'])) {
            $room['afterheat'] = is_array($room['afterheat'])
                ? array_intersect_key($room['afterheat'], array_flip([
                    'valid','begin_at','begin_temp','peak','peak_at'
                ])) : [];
        }
        $counts = is_array($room['house_context_by_valve'] ?? null)
            ? $room['house_context_by_valve'] : [];
        $cleanCounts = [];
        foreach (['valve_on','valve_off'] as $kind) {
            $v = is_array($counts[$kind] ?? null) ? $counts[$kind] : [];
            foreach (['similar','above','below'] as $label) {
                if (isset($v[$label]) && is_numeric($v[$label])) {
                    $cleanCounts[$kind][$label] =
                        min(10000, max(0, (int) $v[$label]));
                }
            }
        }
        if ($cleanCounts === []) { unset($room['house_context_by_valve']); }
        else { $room['house_context_by_valve'] = $cleanCounts; }
        if (isset($room['name'])) {
            $room['name'] = is_string($room['name'])
                ? substr($room['name'], 0, 120) : '';
        }
        foreach (['resets','rejected_stale','hidden_switches'] as $key) {
            if (isset($room[$key])) {
                $room[$key] = min(100000, max(0, (int) $room[$key]));
            }
        }
        if (isset($room['source_audit'])) {
            $rawAudit = is_array($room['source_audit']) ? $room['source_audit'] : [];
            $audit = [];
            foreach (['checks', 'ok_streak', 'bad_streak', 'last_checked_at'] as $field) {
                if (isset($rawAudit[$field]) && is_numeric($rawAudit[$field])) {
                    $audit[$field] = max(0, (int) $rawAudit[$field]);
                    if ($field !== 'last_checked_at') {
                        $audit[$field] = min(10000, $audit[$field]);
                    }
                }
            }
            if (isset($rawAudit['recent_ok']) && is_numeric($rawAudit['recent_ok'])) {
                $audit['recent_ok'] = round(min(1.0,
                    max(0.0, (float) $rawAudit['recent_ok'])), 4);
            }
            if (is_string($rawAudit['last_reason'] ?? null)) {
                $audit['last_reason'] = substr($rawAudit['last_reason'], 0, 30);
            }
            if ($audit === []) { unset($room['source_audit']); }
            else { $room['source_audit'] = $audit; }
        }
        return $room;
    }

    /** Pure, bounded state transition; callable in the PHP offline test without Symcon. */
    public static function observe(array $state, array $input): array
    {
        $time = (int) ($input['time'] ?? 0);
        $temp = (float) ($input['temp'] ?? NAN);
        $target = (float) ($input['target'] ?? NAN);
        $valve = $input['valve'] ?? null;
        $tempSampleAt = (int) ($input['temp_sample_at'] ?? $time);
        $valveSampleAt = (int) ($input['valve_sample_at'] ?? $time);
        $valveChangedAt = (int) ($input['valve_changed_at'] ?? 0);
        // Reject future, absent or stale source readings before opening a window.
        if ($tempSampleAt <= 0 || $tempSampleAt > $time || $time - $tempSampleAt > 1800
            || $valveSampleAt <= 0 || $valveSampleAt > $time || $time - $valveSampleAt > 1800) {
            unset($state['last'], $state['phase'], $state['afterheat']);
            $state['rejected_stale'] = min(100000, 1 + (int) ($state['rejected_stale'] ?? 0));
            return $state;
        }
        if ($time <= 0 || !is_finite($temp) || !is_finite($target)
            || $temp < 0 || $temp > 45 || $target < 5 || $target > 40
            || !is_bool($valve)) {
            return $state;
        }
        $outside = isset($input['outside']) && is_numeric($input['outside'])
            && is_finite((float) $input['outside'])
            && (float) $input['outside'] >= -45 && (float) $input['outside'] <= 55
            ? (float) $input['outside'] : null;
        $hydraulic = static function (mixed $v): ?float {
            return is_numeric($v) && is_finite((float) $v)
                && (float) $v >= -5 && (float) $v <= 65 ? (float) $v : null;
        };
        $observation = [
            'time' => $time, 'temp' => $temp, 'target' => $target,
            'valve' => $valve, 'outside' => $outside,
            'temp_sample_at' => $tempSampleAt,
            'valve_sample_at' => $valveSampleAt,
            'valve_changed_at' => $valveChangedAt,
            'outside_lag' => isset($input['outside_lag']) && is_numeric($input['outside_lag'])
                ? (float) $input['outside_lag'] : null,
            'supply60' => $hydraulic($input['supply60'] ?? null),
            'return60' => $hydraulic($input['return60'] ?? null)
        ];
        $previous = $state['last'] ?? null;
        if (!is_array($previous)) {
            $state['last'] = $observation;
            $state['phase'] = $observation;
            return $state;
        }
        $gap = $time - (int) ($previous['time'] ?? 0);
        if ($gap <= 0) {
            return $state;
        }
        $changed = $valve !== (bool) ($previous['valve'] ?? false);
        // VariableChanged reveals a relay transition even if both 30-min
        // snapshots show the same state (ON -> OFF -> ON).
        $intermediateSwitch = $valveChangedAt > (int) ($previous['time'] ?? 0)
            && $valveChangedAt <= $time;
        if ($intermediateSwitch && !$changed) {
            unset($state['afterheat']);
            $state['phase'] = $observation;
            $state['last'] = $observation;
            $state['resets'] = min(100000, 1 + (int) ($state['resets'] ?? 0));
            $state['hidden_switches'] = min(100000, 1 + (int) ($state['hidden_switches'] ?? 0));
            return $state;
        }
        $targetChanged = abs($target - (float) ($previous['target'] ?? $target)) > 0.15;
        $implausibleJump = abs($temp - (float) ($previous['temp'] ?? $temp)) > max(1.2, 3.0 * $gap / 3600);
        if ($gap > 2700 || $targetChanged || $implausibleJump) {
            unset($state['afterheat']);
            $state['phase'] = $observation;
            $state['last'] = $observation;
            $state['resets'] = min(100000, 1 + (int) ($state['resets'] ?? 0));
            return $state;
        }
        if ($changed) {
            if ($valve) {
                self::finishAfterheat($state);
            } elseif (($previous['valve'] ?? false) === true) {
                $state['afterheat'] = [
                    'valid' => true, 'begin_at' => $time, 'begin_temp' => $temp,
                    'peak' => $temp, 'peak_at' => $time
                ];
            }
            $state['phase'] = $observation;
        } else {
            $after = $state['afterheat'] ?? null;
            if (is_array($after) && !$valve) {
                $age = $time - (int) ($after['begin_at'] ?? 0);
                if ($age > 180 * 60 || $temp < (float) ($after['peak'] ?? $temp) - 0.1) {
                    self::finishAfterheat($state);
                    // A new cooling window starts only AFTER residual slab heat.
                    $state['phase'] = $observation;
                } elseif ($temp > (float) ($after['peak'] ?? $temp)) {
                    $state['afterheat']['peak'] = $temp;
                    $state['afterheat']['peak_at'] = $time;
                }
            }
            $phase = $state['phase'] ?? $observation;
            $elapsed = $time - (int) ($phase['time'] ?? $time);
            // A stable valve and stable target for 60-120 minutes are needed.
            // Sensor resolution, open doors, solar gains and residual slab heat
            // make small instantaneous temperature differences unreliable.
            if ($elapsed >= 3600 && $elapsed <= 14400) {
                $rate = ($temp - (float) ($phase['temp'] ?? $temp)) * 3600 / $elapsed;
                $kind = $valve ? 'heating' : 'passive_cooling';
                $valid = $valve ? ($rate >= 0.05 && $rate <= 2.5)
                    : ($rate <= -0.05 && $rate >= -2.5);
                // Do not use cached temperature values as new endpoint evidence.
                $endSampleAt = (int) ($observation['temp_sample_at'] ?? 0);
                $startSampleAt = (int) ($phase['temp_sample_at'] ?? 0);
                $freshEvidence = $endSampleAt > $startSampleAt
                    && $endSampleAt <= $time && $time - $endSampleAt <= 1800;
                // A status unchanged for hours must not imply that a relay never switched.
                $startRelayAt = (int) ($phase['valve_sample_at'] ?? 0);
                $endRelayAt = (int) ($observation['valve_sample_at'] ?? 0);
                $freshEvidence = $freshEvidence && $endRelayAt > $startRelayAt;
                // No relay value change may have occurred since this phase began.
                // A recent VariableChanged timestamp proves interrupted exposure.
                $lastRelaySwitch = (int) ($observation['valve_changed_at'] ?? 0);
                $freshEvidence = $freshEvidence && ($lastRelaySwitch <= 0
                    || $lastRelaySwitch <= (int) ($phase['time'] ?? 0));
                // OFF does not yet mean passive cooling: the slab may still heat.
                $residualHeat = !$valve && isset($state['afterheat']);
                if ($valid && $freshEvidence && !$residualHeat) {
                    // Hydraulic context is an additional observed correlation,
                    // not a substitute for valid room/relay evidence. Legacy
                    // room rates continue learning without water sensors.
                    if ($valve) {
                        $startSupply = $phase['supply60'] ?? null;
                        $endSupply = $observation['supply60'];
                        if (is_numeric($startSupply) && is_numeric($endSupply)
                            && abs((float) $startSupply - (float) $endSupply) <= 3.0) {
                            $supply = ((float) $startSupply + (float) $endSupply) / 2.0;
                            $state['heating_by_supply'][self::supplyBand($supply)] = self::mean(
                                (array) ($state['heating_by_supply'][self::supplyBand($supply)] ?? []),
                                abs($rate));
                            $drive = $supply - (($temp + (float) $phase['temp']) / 2.0);
                            if ($drive >= 2.0 && $drive <= 35.0) {
                                $state['heating_drive'] = self::mean(
                                    (array) ($state['heating_drive'] ?? []), abs($rate) / $drive);
                            }
                            $startReturn = $phase['return60'] ?? null;
                            $endReturn = $observation['return60'];
                            if (is_numeric($startReturn) && is_numeric($endReturn)
                                && abs((float) $startReturn - (float) $endReturn) <= 3.0) {
                                $return = ((float) $startReturn + (float) $endReturn) / 2.0;
                                $spread = $supply - $return;
                                if ($spread >= 0 && $spread <= 15.0) {
                                    $state['heating_spread'] = self::mean(
                                        (array) ($state['heating_spread'] ?? []), $spread);
                                }
                            }
                        }
                    }
                    $band = self::outdoorBand($outside);
                    $state['rates'][$kind]['all'] = self::mean(
                        (array) ($state['rates'][$kind]['all'] ?? []), abs($rate));
                    $state['rates'][$kind][$band] = self::mean(
                        (array) ($state['rates'][$kind][$band] ?? []), abs($rate));
                    // Compare only stable weather windows; do not mistake rapidly
                    // changing outdoor conditions for a room's thermal constant.
                    $startOutdoor = $phase['outside'] ?? null;
                    if ($outside !== null && is_numeric($startOutdoor)
                        && abs($outside - (float) $startOutdoor) <= 3.0) {
                        $delta = (($temp + (float) $phase['temp']) / 2.0)
                            - (($outside + (float) $startOutdoor) / 2.0);
                        if ($delta >= 0.0 && $delta <= 50.0) {
                            $gradientBand = self::gradientBand($delta);
                            $state['gradient_rates'][$kind][$gradientBand] = self::mean(
                                (array) ($state['gradient_rates'][$kind][$gradientBand] ?? []), abs($rate));
                            // Observed normalized passive cooling, per 10 K difference.
                            // Comparative indicator only; open doors and free gains confound it.
                            if (!$valve && $delta >= 5.0 && $delta <= 35.0) {
                                $normalized = abs($rate) * 10.0 / $delta;
                                if ($normalized >= 0.005 && $normalized <= 3.0) {
                                    $state['loss_proxy'] = self::mean(
                                        (array) ($state['loss_proxy'] ?? []), $normalized);
                                }
                            }
                        }
                    }
                    // The outdoor air from roughly two hours earlier is a
                    // descriptive context, NOT proof of an individual room's lag.
                    $lag = $observation['outside_lag'] ?? null;
                    if ($lag !== null && is_finite((float) $lag)
                        && $outside !== null && abs($outside - (float) $lag) >= 1.0) {
                        $direction = $outside > (float) $lag ? 'outside_rising' : 'outside_falling';
                        $state['weather_trend_rates'][$kind][$direction] = self::mean(
                            (array) ($state['weather_trend_rates'][$kind][$direction] ?? []), abs($rate));
                    }
                    $state['last_evidence_at'] = $time;
                }
                // Non-overlapping windows: avoid counting one warm-up many times.
                $state['phase'] = $observation;
            } elseif ($elapsed > 14400) {
                $state['phase'] = $observation;
            }
        }
        $state['last'] = $observation;
        return $state;
    }
}

trait LCNClimateThermalLearning
{
    /**
     * Purely local Symcon read: explain whether a configured object points to
     * an actual, recently updated hydraulic sensor variable. Both direct
     * variables and bounded link chains are supported, without polling hardware.
     */
    private function ThermalHydraulicProbe(int $configuredID, int $now): array
    {
        $source = $configuredID;
        $fail = static function (int $id, string $why): array {
            return ['ok' => false, 'source' => max(0, $id), 'reason' => $why];
        };
        if ($source <= 0) {
            return $fail(0, 'Keine Objekt-ID konfiguriert');
        }
        try {
            $visited = [];
            $found = false;
            for ($depth = 0; $depth < 6; ++$depth) {
                if (IPS_VariableExists($source)) {
                    $found = true;
                    break;
                }
                if (!function_exists('IPS_ObjectExists')
                    || !function_exists('IPS_GetObject')
                    || !function_exists('IPS_GetLink')) {
                    return $fail($source, 'Symcon-Linkfunktionen nicht verfuegbar');
                }
                if (!IPS_ObjectExists($source)) {
                    return $fail($source, 'Konfiguriertes Objekt/Link fehlt');
                }
                if ((int) (IPS_GetObject($source)['ObjectType'] ?? -1) !== 6) {
                    return $fail($source, 'Objekt ist weder Variable noch Link');
                }
                if (isset($visited[$source])) {
                    return $fail($source, 'Zyklische Link-Verkettung');
                }
                $visited[$source] = true;
                $source = (int) (IPS_GetLink($source)['TargetID'] ?? 0);
                if ($source <= 0) {
                    return $fail(0, 'Link hat kein gueltiges Ziel');
                }
            }
            if (!$found) {
                return $fail($source, 'Link-Kette zu lang oder nicht aufloesbar');
            }
            $meta = IPS_GetVariable($source);
            if (!in_array((int) ($meta['VariableType'] ?? -1), [1, 2], true)) {
                return $fail($source, 'Kein numerischer Temperatur-Variablentyp');
            }
            $updated = (int) ($meta['VariableUpdated'] ?? 0);
            if ($updated <= 0 || $updated > $now) {
                return $fail($source, 'Kein gueltiger Messzeitstempel');
            }
            $age = $now - $updated;
            if ($age > 2700) {
                return $fail($source, 'Messwert zu alt: ' . $age . ' s (max. 2700 s)');
            }
            $value = GetValue($source);
            if (!is_numeric($value) || !is_finite((float) $value)
                || (float) $value < -5 || (float) $value > 65) {
                return $fail($source, 'Temperaturwert ungueltig/ausser Messbereich');
            }
            return ['ok' => true, 'source' => $source, 'updated' => $updated,
                'value' => (float) $value, 'reason' => 'OK (' . $age . ' s alt)'];
        } catch (Throwable $error) {
            return $fail($source, 'Sensor-/Link-Lesefehler: ' . $error->getMessage());
        }
    }

    private function ThermalReadHydraulicSource(int $link, int $now): ?array
    {
        $result = $this->ThermalHydraulicProbe($link, $now);
        return ($result['ok'] ?? false)
            ? ['source' => (int) $result['source'],
                'updated' => (int) $result['updated'],
                'value' => (float) $result['value']]
            : null;
    }

    private function ThermalUpdateHydraulics(array $previous, int $now): array
    {
        $mapping = [
            'supply' => 'ThermalHeatSupplyLink',
            'return' => 'ThermalHeatReturnLink',
            'fbh_after_wt' => 'ThermalFbhAfterExchangerLink',
            'ewt_before_wt' => 'ThermalEwtBeforeExchangerLink',
            'ewt_after_wt' => 'ThermalEwtAfterExchangerLink'
        ];
        $result = [];
        foreach ($mapping as $channel => $property) {
            $reading = $this->ThermalReadHydraulicSource(
                (int) $this->ReadPropertyInteger($property), $now);
            $result[$channel] = LCNThermalLearningModel::hydraulicRolling(
                (array) ($previous[$channel] ?? []), $reading, $now);
        }
        return $result;
    }

    /** The SAME compact and bounded persistence path in heating and pause. */
    private function WriteBoundedThermalLearningState(array $state): void
    {
        $state = array_intersect_key($state, array_flip([
            'schema', 'rooms', 'created_at', 'weather_history',
            'last_tick_at', 'last_processed', 'last_eligible', 'paused_reason', 'last_exclusions',
            'hydraulics'
        ]));
        $rooms = (array) ($state['rooms'] ?? []);
        foreach ($rooms as &$roomState) {
            $roomState = LCNThermalLearningModel::compactRoom((array) $roomState);
        }
        unset($roomState);
        $state['rooms'] = $rooms;
        $channels = [];
        foreach (['supply', 'return', 'fbh_after_wt',
                  'ewt_before_wt', 'ewt_after_wt'] as $name) {
            $saved = (array) ($state['hydraulics'][$name] ?? []);
            $source = (int) ($saved['source'] ?? 0);
            // Bound every series to the latest 60 minutes, 4 observations,
            // with absolute source timestamps for traceable freshness.
            $series = [];
            foreach (array_slice((array) ($saved['points'] ?? []), -4) as $p) {
                if (!is_array($p) || count($p) !== 2
                    || !is_numeric($p[0] ?? null) || !is_numeric($p[1] ?? null)) { continue; }
                $ts = (int) $p[0];
                $v = (float) $p[1];
                if ($ts >= time()-3600 && $ts <= time() && is_finite($v)
                    && $v >= -5 && $v <= 65) {
                    $series[] = [$ts, round($v, 3)];
                }
            }
            $channels[$name] = [
                'source' => max(0, $source),
                'updated' => max(0, (int) ($saved['updated'] ?? 0)),
                'value' => is_numeric($saved['value'] ?? null)
                    && is_finite((float) $saved['value'])
                    ? round((float) $saved['value'], 3) : null,
                'mean60' => is_numeric($saved['mean60'] ?? null)
                    && is_finite((float) $saved['mean60'])
                    ? round((float) $saved['mean60'], 3) : null,
                'count' => count($series), 'points' => $series
            ];
        }
        $state['hydraulics'] = $channels;
        $state['weather_history'] = LCNThermalLearningModel::outdoorContext(
            (array) ($state['weather_history'] ?? []), time(), null)['points'];
        $allowedReasons = [
            'TEMP_MISSING', 'TEMP_STALE', 'TEMP_OR_TARGET_INVALID',
            'RELAY_MISSING', 'RELAY_STALE', 'RELAY_INVALID', 'READ_ERROR'
        ];
        $reasons = [];
        foreach (array_slice((array) ($state['last_exclusions'] ?? []), 0, 30, true) as $id => $reason) {
            if (is_string($reason) && in_array($reason, $allowedReasons, true)) {
                $reasons[(string) $id] = $reason;
            }
        }
        $state['last_exclusions'] = $reasons;
        $state['paused_reason'] = substr((string) ($state['paused_reason'] ?? ''), 0, 150);
        $encoded = json_encode($state, JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || strlen($encoded) > 131072) {
            $this->SendDebug('PassiveThermalLearning',
                'State rejected: invalid JSON or over 128 KiB', 0);
            return;
        }
        $this->WriteAttributeString('ThermalLearningState', $encoded);
    }

    /** This hook is intentionally disconnected from Worker, RequestAction and MessageSink. */
    public function ThermalLearningTick(): void
    {
        try {
            $learn = $this->ReadPropertyBoolean('ThermalLearningEnabled');
            $preflight = $this->ReadPropertyBoolean('ThermalSourcePreflightEnabled');
            if (!$learn && !$preflight) { return; }
            $preflightOnly = !$learn && $preflight;
            $now = time();
            $outside = null;
            $weatherID = $this->ReadPropertyInteger('ThermalWeatherCenterInstance');
            if (!$preflightOnly && $weatherID > 0
                && function_exists('JVWC_Snapshot') && IPS_InstanceExists($weatherID)) {
                try {
                    $raw = json_decode((string) JVWC_Snapshot($weatherID), true);
                    if (is_array($raw) && (int) ($raw['issued_at'] ?? 0) > 0
                        && (int) $raw['issued_at'] <= $now
                        && $now - (int) $raw['issued_at'] <= 2100
                        && in_array((string) ($raw['quality'] ?? ''), [
                            'VERIFIED', 'MODEL_SUPPORTED', 'PWS_ONLY', 'DWD_ONLY', 'DWD_REMOTE'
                        ], true) && is_array($raw['current'] ?? null)) {
                        $value = $raw['current']['temperature'] ?? null;
                        if (is_numeric($value) && is_finite((float) $value)
                            && (float) $value >= -45 && (float) $value <= 55) {
                            $outside = (float) $value;
                        }
                    }
                } catch (Throwable) {
                    // Weather is optional: learn indoor behaviour without inventing weather.
                }
            }
            $rawState = $this->ReadAttributeString('ThermalLearningState');
            // A damaged persisted model must never silently erase years of
            // learning. Only the documented empty fresh-install state may
            // initialize schema 1. Recovery requires explicit user action.
            if ($rawState === '{}' || $rawState === '') {
                $state = ['schema' => 1, 'rooms' => [], 'created_at' => $now];
            } else {
                $state = json_decode($rawState, true);
                if (!is_array($state) || ($state['schema'] ?? null) !== 1
                    || !is_array($state['rooms'] ?? null)) {
                    $this->SendDebug('PassiveThermalLearning',
                        'Stored learning state invalid: preserved without overwrite', 0);
                    return;
                }
            }
            // Retain known state only: a broken previous schema or a legacy
            // unknown field cannot permanently prevent the 128 KiB guard.
            $state = array_intersect_key($state, array_flip([
                'schema', 'rooms', 'created_at', 'weather_history',
                'last_tick_at', 'last_processed', 'last_eligible', 'paused_reason',
                'last_exclusions', 'hydraulics'
            ]));
            $state['rooms'] = (array) ($state['rooms'] ?? []);
            // Keep the persistent model bounded even after years of room-ID changes.
            // Remove orphaned room entries; only the current configured rooms survive.
            $configuredKeys = [];
            foreach ($this->GetRooms() as $configuredRoom) {
                if (!empty($configuredRoom['Enabled'])) {
                    $id = (int) ($configuredRoom['TargetVariable'] ?? 0);
                    if ($id > 0) {
                        $configuredKeys[(string) $id] = true;
                    }
                }
            }
            $state['rooms'] = array_intersect_key((array) ($state['rooms'] ?? []), $configuredKeys);
            if ($this->GetMode() !== 0 && !$preflightOnly) {
                // Heating and active floor cooling are never mixed in one model.
                foreach ($state['rooms'] as &$roomState) {
                    $roomState = (array) $roomState;
                    unset($roomState['last'], $roomState['phase'], $roomState['afterheat']);
                }
                unset($roomState);
                $state['paused_reason'] = 'Betriebsart nicht HEIZEN';
                $state['last_eligible'] = 0;
                $state['last_processed'] = 0;
                $this->WriteBoundedThermalLearningState($state);
                return;
            }
            if ($preflightOnly) {
                // A preflight never holds a live heating phase. Preserve
                // learned rates, but make any later learning opt-in start fresh.
                foreach ($state['rooms'] as &$historicRoom) {
                    $historicRoom = (array) $historicRoom;
                    unset($historicRoom['last'], $historicRoom['phase'],
                        $historicRoom['afterheat']);
                }
                unset($historicRoom);
                $laggedOutdoor = null;
            } else {
                $weather = LCNThermalLearningModel::outdoorContext(
                    (array) ($state['weather_history'] ?? []), $now, $outside);
                $state['weather_history'] = $weather['points'];
                $laggedOutdoor = $weather['lag'];
            }
            // The original 30-minute learning clock is reused: no new
            // IPS variables, no extra timer, no bus traffic.
            if (!$preflightOnly) {
                $state['hydraulics'] = $this->ThermalUpdateHydraulics(
                    (array) ($state['hydraulics'] ?? []), $now);
            }
            $supply60 = $state['hydraulics']['supply']['mean60'] ?? null;
            $return60 = $state['hydraulics']['return']['mean60'] ?? null;
            $processed = 0;
            $eligible = 0;
            $referenceID = $this->ReadPropertyBoolean('ThermalLearnAllRooms')
                ? 0 : $this->ReadPropertyInteger('ThermalReferenceRoomTarget');
            $changes = [];
            $exclusions = [];
            foreach ($this->GetRooms() as $room) {
                if (empty($room['Enabled'])) {
                    continue;
                }
                $targetID = (int) ($room['TargetVariable'] ?? 0);
                $key = (string) $targetID;
                try {
                $tempID = (int) ($room['TemperatureVariable'] ?? 0);
                if ($targetID <= 0 || $tempID <= 0 || !IPS_VariableExists($tempID)) {
                    $exclusions[$key] = 'TEMP_MISSING';
                    continue;
                }
                $tMeta = IPS_GetVariable($tempID);
                $tempUpdated = (int) ($tMeta['VariableUpdated'] ?? 0);
                if (LCNThermalLearningModel::sourceReason($now, $tempUpdated, $now) !== 'OK') {
                    $exclusions[$key] = 'TEMP_STALE';
                    continue;
                }
                $temp = GetValue($tempID);
                $target = $this->ReadTarget($targetID);
                if (!is_numeric($temp) || !is_finite((float) $temp)
                    || (float) $temp < 0.0 || (float) $temp > 45.0
                    || $target === null || !is_finite((float) $target)
                    || (float) $target < 5.0 || (float) $target > 40.0) {
                    $exclusions[$key] = 'TEMP_OR_TARGET_INVALID';
                    continue;
                }
                // Existing native read-only mapping; neither LCN reads nor commands.
                $mapping = $this->AutoCoolingReadOnlyDiscovery($room);
                $statusID = (int) ($mapping['RelayStatusVariable'] ?? 0);
                if ($statusID <= 0 || !IPS_VariableExists($statusID)) {
                    $exclusions[$key] = 'RELAY_MISSING';
                    continue;
                }
                $meta = IPS_GetVariable($statusID);
                $relayUpdated = (int) ($meta['VariableUpdated'] ?? 0);
                $sourceReason = LCNThermalLearningModel::sourceReason(
                    $now, $tempUpdated, $relayUpdated);
                if ($sourceReason !== 'OK') {
                    $exclusions[$key] = $sourceReason;
                    continue;
                }
                // Use one coherent snapshot per room; never mix separate
                // relay reads while evaluating one temperature observation.
                $relayRaw = GetValue($statusID);
                if (!is_bool($relayRaw) && $relayRaw !== 0 && $relayRaw !== 1) {
                    $exclusions[$key] = 'RELAY_INVALID';
                    continue;
                }
                $relayValue = (bool) $relayRaw;
                // Stable target identifier survives room renaming.
                $previous = (array) ($state['rooms'][$key] ?? []);
                // Invalidate old learning when a sensor or relay is remapped.
                // The S1 key alone does not guarantee physical source identity.
                if (isset($previous['source_temp_id'], $previous['source_relay_id'])
                    && ((int) $previous['source_temp_id'] !== $tempID
                        || (int) $previous['source_relay_id'] !== $statusID)) {
                    $previous = [];
                }
                $previous['source_temp_id'] = $tempID;
                $previous['source_relay_id'] = $statusID;
                $previous['name'] = (string) ($room['Name'] ?? ('Raum #' . $targetID));
                if ($preflightOnly) {
                    // No observe(), no phase, no thermal context, no learning.
                    // The end-of-tick source-audit loop still records quality.
                    $state['rooms'][$key] = $previous;
                    ++$processed;
                    continue;
                }
                // Other rooms remain visible to source audits, but may not
                // contaminate the dedicated EG Bad reference experiment.
                if (!LCNThermalLearningModel::isReferenceRoom($targetID, $referenceID)) {
                    unset($previous['last'], $previous['phase'], $previous['afterheat']);
                    $state['rooms'][$key] = $previous;
                    ++$processed;
                    continue;
                }
                // Strict separation: verify six successful, reasonably recent
                // source updates before generating any new thermal samples.
                // Source audits still collect on the normal 30-minute tick.
                if (LCNThermalLearningModel::auditLabel(
                    (array) ($previous['source_audit'] ?? []), $now) !== 'QUELLEN_REGELMAESSIG') {
                    $state['rooms'][$key] = $previous;
                    ++$processed;
                    continue;
                }
                ++$eligible;
                $prior = (array) ($previous['last'] ?? []);
                $age = $now - (int) ($prior['time'] ?? 0);
                if ($age >= 1500 && $age <= 2700 && is_numeric($prior['temp'] ?? null)
                    && (int) ($tMeta['VariableUpdated'] ?? 0) > (int) ($prior['temp_sample_at'] ?? 0)) {
                    $delta = (float) $temp - (float) $prior['temp'];
                    if (abs($delta) <= 0.8 && !isset($previous['afterheat'])
                        && isset($prior['valve'])
                        && (bool) $prior['valve'] === $relayValue
                        && abs((float) $target - (float) ($prior['target'] ?? $target)) <= 0.15
                        && $now - (int) ($meta['VariableUpdated'] ?? 0) <= 1800
                        && (int) ($meta['VariableChanged'] ?? 0) <= (int) ($prior['time'] ?? 0)) {
                        $changes[$key] = ['delta' => $delta, 'valve' => $relayValue];
                    }
                }
                $state['rooms'][$key] = LCNThermalLearningModel::observe($previous, [
                    'time' => $now, 'temp' => (float) $temp, 'target' => $target,
                    'valve' => $relayValue, 'outside' => $outside,
                    'temp_sample_at' => (int) ($tMeta['VariableUpdated'] ?? 0),
                    'valve_sample_at' => (int) ($meta['VariableUpdated'] ?? 0),
                    'valve_changed_at' => (int) ($meta['VariableChanged'] ?? 0),
                    'outside_lag' => $laggedOutdoor,
                    'supply60' => $supply60, 'return60' => $return60
                ]);
                ++$processed;
                } catch (Throwable $roomError) {
                    $exclusions[$key] = 'READ_ERROR';
                    $this->SendDebug('PassiveThermalLearning.Room', 'Room '
                        . $key . ': ' . $roomError->getMessage(), 0);
                }
            }
            if (count($changes) >= 4) {
                $sum = array_sum(array_column($changes, 'delta'));
                $count = count($changes);
                foreach ($changes as $id => $entry) {
                    $relative = $entry['delta'] - ($sum - $entry['delta']) / ($count - 1);
                    $label = abs($relative) <= 0.05 ? 'similar' : ($relative > 0 ? 'above' : 'below');
                    $mode = $entry['valve'] ? 'valve_on' : 'valve_off';
                    $state['rooms'][$id]['house_context_by_valve'][$mode][$label] = min(10000,
                        1 + (int) ($state['rooms'][$id]['house_context_by_valve'][$mode][$label] ?? 0));
                }
            }
            // Six or more normal 30-minute checks reveal recurrent stale
            // sensor/relay inputs without storing any raw time series.
            foreach ($this->GetRooms() as $configuredRoom) {
                if (empty($configuredRoom['Enabled'])) { continue; }
                $id = (int) ($configuredRoom['TargetVariable'] ?? 0);
                if ($id <= 0) { continue; }
                $key = (string) $id;
                $roomState = (array) ($state['rooms'][$key] ?? []);
                $reason = (string) ($exclusions[$key] ?? 'OK');
                $roomState['source_audit'] = LCNThermalLearningModel::auditSource(
                    (array) ($roomState['source_audit'] ?? []), $reason, $now);
                if (!isset($roomState['name'])) {
                    $roomState['name'] = (string) ($configuredRoom['Name'] ?? ('Raum #' . $id));
                }
                $state['rooms'][$key] = $roomState;
            }
            $state['last_tick_at'] = $now;
            $state['last_processed'] = $processed;
            $state['last_eligible'] = $eligible;
            $state['last_exclusions'] = array_slice($exclusions, 0, 30, true);
            $state['paused_reason'] = $preflightOnly
                ? 'NUR QUELLEN-VORPRUEFUNG - THERMISCHES LERNEN AUS'
                : ($processed === 0 ? 'Keine aktuellen, eindeutig zugeordneten Messpaare' : '');
            $this->WriteBoundedThermalLearningState($state);
        } catch (Throwable $error) {
            // Learning failure must NEVER propagate into working regulation or instance status.
            $this->SendDebug('PassiveThermalLearning', $error->getMessage(), 0);
        }
    }

    public function ThermalLearningReport(): string
    {
        $enabled = $this->ReadPropertyBoolean('ThermalLearningEnabled');
        $preflight = $this->ReadPropertyBoolean('ThermalSourcePreflightEnabled');
        $rawState = $this->ReadAttributeString('ThermalLearningState');
        $state = json_decode($rawState, true);
        $referenceID = $this->ReadPropertyBoolean('ThermalLearnAllRooms')
            ? 0 : $this->ReadPropertyInteger('ThermalReferenceRoomTarget');
        $lines = ['SMART-FBH – passives Lernen: ' . ($enabled ? 'AKTIV' : 'AUS')
            . ' | Datenquellen-Vorpruefung: ' . ($preflight ? 'AKTIV' : 'AUS'),
            'Keine LCN-Befehle, keine Sollwertänderung, keine automatische Optimierung.'];
        $lines[] = 'Thermisches Lernobjekt: ' . ($referenceID === 0
            ? 'alle konfigurierten Räume' : 'nur S1-Referenz #' . $referenceID . ' (EG Bad: #21099)');
        if ($rawState !== '{}' && $rawState !== ''
            && (!is_array($state) || ($state['schema'] ?? null) !== 1
                || !is_array($state['rooms'] ?? null))) {
            $lines[] = 'WARNUNG: GESPEICHERTER LERNZUSTAND BESCHAEDIGT'
                . ' ODER UNBEKANNTE VERSION; NICHT UEBERSCHRIEBEN.';
            return implode("\n", $lines);
        }
        if (!is_array($state) || empty($state['rooms'])) {
            $lines[] = 'Noch keine belastbaren Messwerte vorhanden.';
            return implode("\n", $lines);
        }
        $lines[] = 'Letzte Erfassung: ' . (isset($state['last_tick_at'])
            ? date('d.m.Y H:i', (int) $state['last_tick_at']) : 'unbekannt');
        $lines[] = 'Aktuelle Datenpaare: ' . (int) ($state['last_processed'] ?? 0)
            . ' | Thermisches Lernen freigegeben: ' . (int) ($state['last_eligible'] ?? 0);
        if (($state['paused_reason'] ?? '') !== '') {
            $lines[] = 'Hinweis: ' . $state['paused_reason'];
        }
        foreach ($state['rooms'] as $data) {
            if (!is_array($data)) {
                continue;
            }
            $heat = (array) ($data['rates']['heating']['all'] ?? []);
            $cool = (array) ($data['rates']['passive_cooling']['all'] ?? []);
            $after = (array) ($data['afterheat_model']['gain'] ?? []);
            $describe = static function (array $sample): string {
                return (int) ($sample['n'] ?? 0) < 3
                    ? 'lernt (' . (int) ($sample['n'] ?? 0) . ')'
                    : number_format((float) ($sample['mean'] ?? 0), 2, ',', '.') . ' °C';
            };
            $lines[] = (string) ($data['name'] ?? 'Raum')
                . ' | Heizen: ' . $describe($heat) . '/h'
                . ' | Abkühlen: ' . $describe($cool) . '/h'
                . ' | Nachlauf: ' . $describe($after);
        }
        $lines[] = 'Lernwerte sind Beobachtungen, keine garantierten Aufheiz-/Abkühlzeiten. '
            . 'Einfluss von Sonne, offenen Türen und Vorlauftemperatur ist derzeit nicht isolierbar.';
        return implode("\n", $lines);
    }


    /**
     * Copyable, deliberately read-only diagnostic for the separate SMART-FBH learner.
     * Does not advance learning, send LCN commands, trigger refreshes or modify targets.
     */
    public function ThermalLearningDiagnosticReport(): string
    {
        $now = time();
        $enabled = $this->ReadPropertyBoolean('ThermalLearningEnabled');
        $preflight = $this->ReadPropertyBoolean('ThermalSourcePreflightEnabled');
        $weatherID = $this->ReadPropertyInteger('ThermalWeatherCenterInstance');
        $rawLearningState = $this->ReadAttributeString('ThermalLearningState');
        $state = json_decode($rawLearningState, true);
        $stateInvalid = $rawLearningState !== '{}' && $rawLearningState !== ''
            && (!is_array($state) || ($state['schema'] ?? null) !== 1
                || !is_array($state['rooms'] ?? null));
        if (!is_array($state) || $stateInvalid) {
            $state = [];
        }

        $lines = [
            '=== SMART-FBH – LERN-DIAGNOSE AN CHATGPT ===',
            'Erstellt: ' . date('d.m.Y H:i:s', $now),
            'Symcon-Instanz: #' . $this->InstanceID,
            'Referenz-Lernraum: ' . ($this->ReadPropertyBoolean('ThermalLearnAllRooms')
                || $this->ReadPropertyInteger('ThermalReferenceRoomTarget') === 0
                ? 'ALLE 11 Räume' : 'S1 #' . $this->ReadPropertyInteger('ThermalReferenceRoomTarget')),
            'Lernfunktion: ' . ($enabled ? 'AKTIV' : 'AUS (in der Modulkonfiguration aktivieren)'),
            'Quellen-Vorpruefung: ' . ($preflight ? 'AKTIV' : 'AUS')
                . ' | 30-Minuten-Takt | NUR LESEN',
            'Betriebsart: ' . ($this->GetMode() === 0 ? 'HEIZEN' : 'NICHT HEIZEN – Lernphase pausiert'),
            'SICHERHEIT: NUR LESEN. Keine A7/A8, keine Relais-/S1-Befehle und keine Sollwertänderung.',
        ];

        if ($stateInvalid) {
            $lines[] = 'KRITISCHE LERNDATEN-WARNUNG: Gespeichertes Modell ungueltig'
                . ' oder Schema unbekannt; Lernen wird angehalten, Daten NICHT ueberschrieben.';
            $lines[] = 'Keine Neuinitialisierung ohne Sicherung und ausdrueckliche Pruefung.';
        }
        $lastTick = (int) ($state['last_tick_at'] ?? 0);
        $tickAge = $lastTick > 0 ? max(0, $now - $lastTick) : null;
        $lines[] = 'Letzter Lernlauf: ' . ($lastTick > 0
            ? date('d.m.Y H:i:s', $lastTick) . ' (' . $tickAge . ' s alt)'
            : 'NOCH KEIN LERNLAUF');
        $lines[] = 'Lernlauf zuletzt erfasste Räume: ' . (int) ($state['last_processed'] ?? 0)
            . ' | im letzten Lernlauf thermisch bereit: ' . (int) ($state['last_eligible'] ?? 0);
        $auditReadyNow = 0;
        foreach ((array) ($state['rooms'] ?? []) as $roomState) {
            if (LCNThermalLearningModel::auditLabel(
                (array) ($roomState['source_audit'] ?? []), $now) === 'QUELLEN_REGELMAESSIG') {
                ++$auditReadyNow;
            }
        }
        $lines[] = 'Quellen-Abnahme jetzt: ' . $auditReadyNow
            . '/' . count($this->GetRooms()) . ' Raeume.';
        if ($auditReadyNow > (int) ($state['last_eligible'] ?? 0)) {
            $lines[] = 'HINWEIS: Quellen-Abnahme wird am Ende des 30-min-Lernlaufs'
                . ' aktualisiert; neue Freigaben gelten erst im naechsten Lernlauf.'
                . ' Dies ist noch kein bestaetigter Auf-/Abheizzyklus.';
        }
        $excluded = (array) ($state['last_exclusions'] ?? []);
        $counts = array_count_values(array_values(array_filter($excluded, 'is_string')));
        $details = [];
        foreach ($counts as $reason => $number) {
            $details[] = $reason . ': ' . $number;
        }
        $lines[] = 'Im letzten Lernlauf ausgeschlossen: '
            . ($details === [] ? 'keine' : implode(' | ', $details));
        $lines[] = 'Lernzustand: ' . strlen($this->ReadAttributeString('ThermalLearningState'))
            . ' Bytes (Speichergrenze 131072 Bytes).';
        $lines[] = 'Lernstatistik angelegt: ' . (isset($state['created_at'])
            ? date('d.m.Y H:i:s', (int) $state['created_at']) : 'noch nicht');
        if (($enabled || $preflight) && ($tickAge === null || $tickAge > 4500)) {
            $lines[] = 'WARNUNG: Lern-Timer noch nicht gelaufen oder seit mehr als 75 Minuten ohne Lauf.';
        }
        if (($state['paused_reason'] ?? '') !== '') {
            $lines[] = 'Lernpause/Hinweis: ' . (string) $state['paused_reason'];
        }

        $lines[] = '--- WETTERZENTRALE (NUR LESEN) ---';
        $lines[] = 'Konfigurierte Instanz: #' . $weatherID;
        if ($weatherID <= 0) {
            $lines[] = 'Wetter: bewusst deaktiviert; Raumlernen ohne Außentemperatur möglich.';
        } elseif (!function_exists('JVWC_Snapshot') || !IPS_InstanceExists($weatherID)) {
            $lines[] = 'Wetter: Instanz oder JVWC_Snapshot nicht verfügbar; kein Wetterbezug.';
        } else {
            try {
                $weather = json_decode((string) JVWC_Snapshot($weatherID), true);
                if (!is_array($weather)) {
                    $lines[] = 'Wetter: ungültige Snapshot-Antwort.';
                } else {
                    $issued = (int) ($weather['issued_at'] ?? 0);
                    $age = $issued > 0 ? max(0, $now - $issued) : -1;
                    $quality = (string) ($weather['quality'] ?? 'UNAVAILABLE');
                    $current = $weather['current'] ?? null;
                    $usable = $issued > 0 && $issued <= $now && $age <= 2100
                        && in_array($quality, ['VERIFIED','MODEL_SUPPORTED','PWS_ONLY','DWD_ONLY','DWD_REMOTE'], true)
                        && is_array($current)
                        && is_numeric($current['temperature'] ?? null);
                    $lines[] = 'Wetterqualität: ' . $quality . ' | Snapshot-Alter: '
                        . ($age >= 0 ? $age . ' s' : 'unbekannt')
                        . ' | Für Lernkontext: ' . ($usable ? 'JA' : 'NEIN');
                    if ($usable) {
                        $lines[] = 'Außentemperatur: ' . number_format((float) $current['temperature'], 1, ',', '.') . ' °C';
                    }
                    $lines[] = 'Wetterhinweis: ' . (string) ($weather['reason'] ?? 'kein Hinweis');
                }
            } catch (Throwable $e) {
                $lines[] = 'Wetterdiagnose nicht lesbar: ' . $e->getMessage();
            }
        }

        $lines[] = '--- HYDRAULIK (NUR LESEN, 60-MINUTEN-GLEITMITTEL) ---';
        $hydraulic = (array) ($state['hydraulics'] ?? []);
        $names = [
            'supply' => ['FBH-Vorlauf (Heizungs-Vorlauf)', 'ThermalHeatSupplyLink'],
            'return' => ['FBH-Ruecklauf vor Kuehl-WT (T103)', 'ThermalHeatReturnLink'],
            'fbh_after_wt' => ['FBH nach Kuehl-WT (T101)', 'ThermalFbhAfterExchangerLink'],
            'ewt_before_wt' => ['EWT vor Kuehl-WT (T100)', 'ThermalEwtBeforeExchangerLink'],
            'ewt_after_wt' => ['EWT nach Kuehl-WT (T104)', 'ThermalEwtAfterExchangerLink']
        ];
        foreach ($names as $key => [$caption, $property]) {
            $data = (array) ($hydraulic[$key] ?? []);
            $raw = $data['value'] ?? null;
            $avg = $data['mean60'] ?? null;
            $age = ($data['updated'] ?? 0) > 0 ? $now - (int) $data['updated'] : null;
            $fresh = $age !== null && $age >= 0 && $age <= 2700;
            $ageText = $age === null ? 'keine aktuelle Quelle' : $age . ' s alt';
            $configuredID = (int) $this->ReadPropertyInteger($property);
            $probe = $this->ThermalHydraulicProbe($configuredID, $now);
            $lines[] = $caption . ' | Link/Objekt #' . $configuredID
                . ' -> Quelle #' . (int) ($data['source'] ?? 0)
                . ' | Ist ' . ($fresh && is_numeric($raw)
                    ? number_format((float) $raw, 2, ',', '.') . ' °C' : '--')
                . ' (' . $ageText . ') | 60-min-Mittel '
                . ($fresh && is_numeric($avg)
                    ? number_format((float) $avg, 2, ',', '.') . ' °C' : 'noch nicht verfuegbar')
                . ' (' . (int) ($data['count'] ?? 0) . ' Stützpunkte)'
                . ' | LIVE: Quelle #' . (int) ($probe['source'] ?? 0)
                . ' – ' . (string) ($probe['reason'] ?? 'unbekannt');
        }
        $lines[] = 'Hydraulik-Freigabe: zwei getrennte ECHTE Sensoraktualisierungen'
            . ' (mindestens 20 min Abstand); nur Lernkontext, niemals Pumpensteuerung.';
        $supply = $hydraulic['supply']['mean60'] ?? null;
        $return = $hydraulic['return']['mean60'] ?? null;
        if (is_numeric($supply) && is_numeric($return)
            && (int) ($hydraulic['supply']['updated'] ?? 0) > $now - 2700
            && (int) ($hydraulic['return']['updated'] ?? 0) > $now - 2700) {
            $lines[] = 'FBH-VL minus FBH-RL (jeweils 60-min-Mittel): '
                . number_format((float) $supply - (float) $return, 2, ',', '.') . ' K';
        }
        $lines[] = 'T100/T104: EWT-Kuehlquelle, NICHT Heizungs-Vorlauf;'
            . ' T101: nach Kuehl-WT, nicht als FBH-Heiz-Vorlauf verwendet.';
        $lines[] = '--- RAEUME (11 ERWARTET) ---';
        $configured = 0;
        $ready = 0;
        $withEvidence = 0;
        $quality = static function (array $sample): string {
            $n = (int) ($sample['n'] ?? 0);
            $qualityN = (int) ($sample['quality_n'] ?? 0);
            if ($qualityN < 3) { return 'UNZUREICHEND'; }
            if ($qualityN < 10) { return 'VORLAEUFIG'; }
            if (!isset($sample['variance'])) { return 'STREUUNG_UNBEKANNT'; }
            $mean = abs((float) ($sample['mean'] ?? 0));
            $sigma = sqrt(max(0.0, (float) $sample['variance']));
            if ($mean < 0.001 || $sigma > max(0.05, $mean * 0.50)) {
                return 'STARK_STREUEND';
            }
            return 'STABIL_BEOBACHTET';
        };
        $describeRate = static function (array $sample, string $unit): string {
            $n = (int) ($sample['n'] ?? 0);
            if ($n < 3) {
                return 'lernt (' . $n . '/3 Fenster)';
            }
            return number_format((float) ($sample['mean'] ?? 0), 2, ',', '.') . ' ' . $unit
                . ' (' . $n . ' Fenster)';
        };
        foreach ($this->GetRooms() as $room) {
            if (empty($room['Enabled'])) {
                continue;
            }
            ++$configured;
            $name = (string) ($room['Name'] ?? 'Unbenannter Raum');
            $targetID = (int) ($room['TargetVariable'] ?? 0);
            $tempID = (int) ($room['TemperatureVariable'] ?? 0);
            $saved = (array) (($state['rooms'][(string) $targetID] ?? []));
            $reason = 'OK';
            $temp = null;
            $setpoint = null;
            $relayOn = null;
            $tempAge = null;
            $relayAge = null;
            $relayChangeAge = null;
            $relayID = 0;
            try {
                if ($tempID <= 0 || !IPS_VariableExists($tempID)) {
                    $reason = 'Isttemperatur-Variable fehlt';
                } else {
                    $meta = IPS_GetVariable($tempID);
                    $tempAge = (int) ($meta['VariableUpdated'] ?? 0) > 0
                        ? max(0, $now - (int) $meta['VariableUpdated']) : null;
                    if ($tempAge === null || (int) $meta['VariableUpdated'] > $now
                        || $tempAge > 1800) {
                        $reason = 'Isttemperatur fehlt/älter als 30 Minuten';
                    } else {
                        $temp = GetValue($tempID);
                        if (!is_numeric($temp) || !is_finite((float) $temp)
                            || (float) $temp < 0 || (float) $temp > 45) {
                            $reason = 'Isttemperatur ungültig';
                        }
                    }
                }
                if ($reason === 'OK') {
                    $setpoint = $targetID > 0 ? $this->ReadTarget($targetID) : null;
                    if ($setpoint === null || !is_finite((float) $setpoint)
                        || (float) $setpoint < 5 || (float) $setpoint > 40) {
                        $reason = 'LCN-S1-Sollwert nicht lesbar';
                    }
                }
                if ($reason === 'OK') {
                    $mapping = $this->AutoCoolingReadOnlyDiscovery($room);
                    $relayID = (int) ($mapping['RelayStatusVariable'] ?? 0);
                    if ($relayID <= 0 || !IPS_VariableExists($relayID)) {
                        $reason = 'Keine eindeutige native Relaisrückmeldung';
                    } else {
                        $meta = IPS_GetVariable($relayID);
                        $relayAge = (int) ($meta['VariableUpdated'] ?? 0) > 0
                            ? max(0, $now - (int) $meta['VariableUpdated']) : null;
                        $changedAt = (int) ($meta['VariableChanged'] ?? 0);
                        if ($changedAt > 0 && $changedAt <= $now) {
                            $relayChangeAge = $now - $changedAt;
                        }
                        if ($relayAge === null || (int) $meta['VariableUpdated'] > $now
                            || $relayAge > 1800) {
                            $reason = 'Relaisrückmeldung fehlt/älter als 30 Minuten';
                        } else {
                            $rawRelay = GetValue($relayID);
                            if (!is_bool($rawRelay) && $rawRelay !== 0 && $rawRelay !== 1) {
                                $reason = 'Relaisstatus mit ungueltigem Datentyp';
                            } else {
                                $relayOn = (bool) $rawRelay;
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                $reason = 'Lesefehler: ' . $e->getMessage();
            }
            if ($reason === 'OK') {
                ++$ready;
            }

            $heat = (array) ($saved['rates']['heating']['all'] ?? []);
            $cool = (array) ($saved['rates']['passive_cooling']['all'] ?? []);
            $after = (array) ($saved['afterheat_model']['gain'] ?? []);
            if ((int) ($heat['n'] ?? 0) >= 3 || (int) ($cool['n'] ?? 0) >= 3
                || (int) ($after['n'] ?? 0) >= 3) {
                ++$withEvidence;
            }
            $last = (array) ($saved['last'] ?? []);
            $lastAge = (int) ($last['time'] ?? 0) > 0
                ? max(0, $now - (int) $last['time']) : null;
            $gradient = (array) ($saved['gradient_rates'] ?? []);
            $gradientDetail = [];
            foreach (['delta_lt10' => '<10', 'delta_10_20' => '10–20', 'delta_ge20' => '>=20'] as $band => $label) {
                $h = (array) ($gradient['heating'][$band] ?? []);
                $p = (array) ($gradient['passive_cooling'][$band] ?? []);
                $gradientDetail[] = $label . 'K: H ' . $describeRate($h, '°C/h')
                    . ', A ' . $describeRate($p, '°C/h');
            }
            $audit = (array) ($saved['source_audit'] ?? []);
            $lines[] = $name . ' | ' . $reason;
            $lines[] = '  Datenquellen-Abnahme: '
                . LCNThermalLearningModel::auditLabel($audit, $now)
                . ' | letzte Ursache: ' . (string) ($audit['last_reason'] ?? 'unbekannt')
                . ' | aktuelle Zuverlaessigkeit: '
                . (int) round(100 * (float) ($audit['recent_ok'] ?? 0.0)) . '%';
            $lines[] = '  Ist #' . $tempID . ': '
                . ($temp === null ? '–' : number_format((float) $temp, 2, ',', '.') . ' °C')
                . ' (Alter ' . ($tempAge === null ? '?' : $tempAge . ' s') . ')'
                . ' | S1 #' . $targetID . ': '
                . ($setpoint === null ? '–' : number_format((float) $setpoint, 1, ',', '.') . ' °C');
            $lines[] = '  Relaisstatus #' . $relayID . ': '
                . ($relayOn === null ? 'unbekannt' : ($relayOn ? 'EIN' : 'AUS'))
                . ' (letzte Meldung vor ' . ($relayAge === null ? '?' : $relayAge . ' s') . ')'
                . ' | Statuswechsel vor '
                . ($relayChangeAge === null ? '?' : $relayChangeAge . ' s')
                . ' | letzte verwertete Messung: '
                . ($lastAge === null ? 'keine' : $lastAge . ' s alt');
            $lines[] = '  Heizanstieg: ' . $describeRate($heat, '°C/h')
                . ' | passives Auskühlen: ' . $describeRate($cool, '°C/h')
                . ' | Nachlauf: ' . $describeRate($after, '°C')
                . ' | Fenster-Resets: ' . (int) ($saved['resets'] ?? 0)
                . ' | stale Abweisungen: ' . (int) ($saved['rejected_stale'] ?? 0)
                . ' | erkannte Zwischenschaltungen: ' . (int) ($saved['hidden_switches'] ?? 0);
            $lines[] = '  Lernqualitaet: Heizen ' . $quality($heat)
                . ', Auskuehlen ' . $quality($cool)
                . ', Nachlauf ' . $quality($after);
            $longText = static function (array $v): string {
                if ((int) ($v['quality_n'] ?? 0) < 30 || !isset($v['long_mean'])) {
                    return 'lernt (<30 neue Fenster)';
                }
                return number_format((float) $v['long_mean'], 3, ',', '.') . ' °C/h';
            };
            $lines[] = '  Langzeitreferenz: Heizen ' . $longText($heat)
                . ' | passiv Auskuehlen ' . $longText($cool)
                . ' (getrennt vom gleitenden Kurzzeitwert)';
            $bySupply = (array) ($saved['heating_by_supply'] ?? []);
            $lines[] = '  Heizanstieg nach FBH-Vorlauf-60min: <26 °C '
                . $describeRate((array) ($bySupply['supply_lt26'] ?? []), '°C/h')
                . ' | 26-30 °C ' . $describeRate(
                    (array) ($bySupply['supply_26_30'] ?? []), '°C/h')
                . ' | >=30 °C ' . $describeRate(
                    (array) ($bySupply['supply_ge30'] ?? []), '°C/h');
            $lines[] = '  Heizen: Anstieg je K Vorlauf-Raum-Differenz '
                . $describeRate((array) ($saved['heating_drive'] ?? []), '1/h')
                . ' | FBH-VL minus FBH-RL '
                . $describeRate((array) ($saved['heating_spread'] ?? []), 'K');
            $lines[] = '  Innen-Aussen-Differenz: ' . implode(' | ', $gradientDetail);
            $trend = (array) ($saved['weather_trend_rates'] ?? []);
            $loss = (array) ($saved['loss_proxy'] ?? []);
            $context = (array) ($saved['house_context'] ?? []);
            $byValve = (array) ($saved['house_context_by_valve'] ?? []);
            foreach (['valve_on' => 'Ventil EIN', 'valve_off' => 'Ventil AUS'] as $group => $caption) {
                $values = (array) ($byValve[$group] ?? []);
                $lines[] = '  Hausvergleich ' . $caption . ': gleichlaufend '
                    . (int) ($values['similar'] ?? 0) . ', staerker '
                    . (int) ($values['above'] ?? 0) . ', schwaecher '
                    . (int) ($values['below'] ?? 0);
            }
            $lines[] = '  Auskuehlindikator pro 10 K Innen-Aussen-Differenz: '
                . $describeRate($loss, '°C/h')
                . ' (nur passiv, kein Heizlastwert)';
            $lines[] = '  Aussenwetter seit ca. 2h: steigend H '
                . $describeRate((array) ($trend['heating']['outside_rising'] ?? []), '°C/h')
                . ', A ' . $describeRate((array) ($trend['passive_cooling']['outside_rising'] ?? []), '°C/h')
                . ' | fallend H ' . $describeRate((array) ($trend['heating']['outside_falling'] ?? []), '°C/h')
                . ', A ' . $describeRate((array) ($trend['passive_cooling']['outside_falling'] ?? []), '°C/h');
        }
        $lines[] = '--- ZUSAMMENFASSUNG ---';
        $lines[] = 'Aktive Räume: ' . $configured . ' | Datenquellen jetzt verwertbar: '
            . $ready . ' | Räume mit >=3 Lernfenstern: ' . $withEvidence;
        $lines[] = 'Qualitaetsschutz: Passive Auskuehlfenster beginnen erst nach dem Estrich-Nachlauf; '
            . 'Lernraten benoetigen neuere Temperatur- UND Relaisstatusmessungen am Fensterende (max. 30 min alt).';
        $lines[] = 'Ein gueltiger Sensorwert bedeutet noch keinen gueltigen Lernzyklus. '
            . 'Sonneneinstrahlung, offene Tueren und hydraulische Vermischung beeinflussen Lernwerte.'
            . ' Vorlaufkontext nur bei stabilen 60-min-Mittelwerten, keine Wirkungskausalitaet.';
        $lines[] = 'Wettertrend ueber ca. 2h: nur bei >=1 K Aenderung; maximal sechs gemeinsame Wetterpunkte gespeichert. ';
        $lines[] = 'Innen-Aussen-Vergleich: Beobachtete Trends, keine kausale Heizlast oder Energiebedarf; '
            . 'Fenster nur bei Wetterveraenderung <=3 K, je Differenzklasse mindestens 3 Fenster.';
        $lines[] = 'Lernfreigabe: Nur bei mindestens sechs aufeinanderfolgenden guten Quellpruefungen, '
            . 'mit gleitender Verfuegbarkeit >=80 Prozent. Nach laengerer Pause neue Abnahme.';
        $lines[] = 'LCN-Rueckmeldung: VariableUpdated = letzte Statusmeldung; '
            . 'VariableChanged = letzter tatsaechlich gemeldeter Wertwechsel. '
            . 'Ein unveraenderter Wert ist kein Beleg fuer eine neue Meldung.';
        $lines[] = 'Datenquellen-Abnahme: Erst ab sechs getrennten Lernlaeufen klassifiziert; '
            . 'Quelle regelmaessig bedeutet NICHT verlaesslichen Heizwirkungsnachweis.';
        $lines[] = 'Keine aktive Fußbodenkühlung angelernt; Abkühlen bedeutet passives Auskühlen bei Ventil AUS.'
            . ' Hydraulik-Snapshots sind Beobachtungen und beeinflussen keine Schaltentscheidungen.';
        $lines[] = '--- ENDE – gesamten Bericht an ChatGPT senden ---';
        return implode("\n", $lines);
    }

    /** Own output variable, isolated from normal ChatGPTDiagnostic and all LCN control paths. */
    public function UpdateThermalLearningDiagnostic(): void
    {
        try {
            $this->SetValue('ThermalLearningDiagnostic', $this->ThermalLearningDiagnosticReport());
        } catch (Throwable $error) {
            $this->SendDebug('ThermalLearningDiagnostic', $error->getMessage(), 0);
            try {
                $this->SetValue('ThermalLearningDiagnostic',
                    'SMART-FBH – LERN-DIAGNOSE AN CHATGPT' . "\n"
                    . 'DIAGNOSEFEHLER: ' . $error->getMessage() . "\n"
                    . 'Keine LCN-Befehle gesendet.');
            } catch (Throwable) {
            }
        }
    }
}
