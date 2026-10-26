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
        return ['n' => $n, 'mean' => round($n === 1 ? $value : (0.85 * $old + 0.15 * $value), 4)];
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

    /** Pure, bounded state transition; callable in the PHP offline test without Symcon. */
    public static function observe(array $state, array $input): array
    {
        $time = (int) ($input['time'] ?? 0);
        $temp = (float) ($input['temp'] ?? NAN);
        $target = (float) ($input['target'] ?? NAN);
        $valve = $input['valve'] ?? null;
        $tempSampleAt = (int) ($input['temp_sample_at'] ?? $time);
        $valveSampleAt = (int) ($input['valve_sample_at'] ?? $time);
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
        $observation = [
            'time' => $time, 'temp' => $temp, 'target' => $target,
            'valve' => $valve, 'outside' => $outside,
            'temp_sample_at' => $tempSampleAt,
            'valve_sample_at' => $valveSampleAt,
            'outside_lag' => isset($input['outside_lag']) && is_numeric($input['outside_lag'])
                ? (float) $input['outside_lag'] : null
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
                // OFF does not yet mean passive cooling: the slab may still heat.
                $residualHeat = !$valve && isset($state['afterheat']);
                if ($valid && $freshEvidence && !$residualHeat) {
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
    /** This hook is intentionally disconnected from Worker, RequestAction and MessageSink. */
    public function ThermalLearningTick(): void
    {
        try {
            if (!$this->ReadPropertyBoolean('ThermalLearningEnabled')) {
                return;
            }
            $now = time();
            $outside = null;
            $weatherID = $this->ReadPropertyInteger('ThermalWeatherCenterInstance');
            if ($weatherID > 0 && function_exists('JVWC_Snapshot') && IPS_InstanceExists($weatherID)) {
                try {
                    $raw = json_decode((string) JVWC_Snapshot($weatherID), true);
                    if (is_array($raw) && $now - (int) ($raw['issued_at'] ?? 0) <= 2100
                        && in_array((string) ($raw['quality'] ?? ''), [
                            'VERIFIED', 'MODEL_SUPPORTED', 'PWS_ONLY', 'DWD_ONLY', 'DWD_REMOTE'
                        ], true) && is_array($raw['current'] ?? null)) {
                        $value = $raw['current']['temperature'] ?? null;
                        if (is_numeric($value)) {
                            $outside = (float) $value;
                        }
                    }
                } catch (Throwable) {
                    // Weather is optional: learn indoor behaviour without inventing weather.
                }
            }
            $state = json_decode($this->ReadAttributeString('ThermalLearningState'), true);
            if (!is_array($state) || ($state['schema'] ?? 0) !== 1) {
                $state = ['schema' => 1, 'rooms' => [], 'created_at' => $now];
            }
            if ($this->GetMode() !== 0) {
                // Heating and active floor cooling are never mixed in one model.
                foreach ($state['rooms'] as &$roomState) {
                    unset($roomState['last'], $roomState['phase'], $roomState['afterheat']);
                }
                unset($roomState);
                $state['paused_reason'] = 'Betriebsart nicht HEIZEN';
                $this->WriteAttributeString('ThermalLearningState', json_encode($state, JSON_UNESCAPED_UNICODE) ?: '{}');
                return;
            }
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
            // Fixed, six-entry outdoor history (at most 3 hours at 30-min cadence).
            // Shared by all rooms: O(1) persistent storage over months or years.
            $laggedOutdoor = null;
            $weatherHistory = (array) ($state['weather_history'] ?? []);
            $weatherHistory = array_values(array_filter($weatherHistory, static function ($v) use ($now): bool {
                return is_array($v) && isset($v['t'], $v['c'])
                    && is_numeric($v['t']) && is_numeric($v['c'])
                    && (int) $v['t'] <= $now && $now - (int) $v['t'] <= 10800;
            }));
            $weatherHistory = array_slice($weatherHistory, -6);
            if ($outside !== null) {
                foreach (array_reverse($weatherHistory) as $point) {
                    $age = $now - (int) $point['t'];
                    if ($age >= 6300 && $age <= 8100) {
                        $laggedOutdoor = (float) $point['c'];
                        break;
                    }
                }
                $weatherHistory[] = ['t' => $now, 'c' => round($outside, 2)];
            }
            $state['weather_history'] = array_slice($weatherHistory, -6);
            $processed = 0;
            $changes = [];
            foreach ($this->GetRooms() as $room) {
                if (empty($room['Enabled'])) {
                    continue;
                }
                $targetID = (int) ($room['TargetVariable'] ?? 0);
                $tempID = (int) ($room['TemperatureVariable'] ?? 0);
                if ($targetID <= 0 || $tempID <= 0 || !IPS_VariableExists($tempID)) {
                    continue;
                }
                $tMeta = IPS_GetVariable($tempID);
                if ($now - (int) ($tMeta['VariableUpdated'] ?? 0) > 30 * 60) {
                    continue;
                }
                $temp = GetValue($tempID);
                $target = $this->ReadTarget($targetID);
                if (!is_numeric($temp) || $target === null) {
                    continue;
                }
                // Existing native read-only mapping; neither LCN reads nor commands.
                $mapping = $this->AutoCoolingReadOnlyDiscovery($room);
                $statusID = (int) ($mapping['RelayStatusVariable'] ?? 0);
                if ($statusID <= 0 || !IPS_VariableExists($statusID)) {
                    continue;
                }
                $meta = IPS_GetVariable($statusID);
                if ($now - (int) ($meta['VariableUpdated'] ?? 0) > 30 * 60) {
                    continue;
                }
                $key = (string) $targetID; // Stable across room renaming.
                $previous = (array) ($state['rooms'][$key] ?? []);
                $previous['name'] = (string) ($room['Name'] ?? ('Raum #' . $targetID));
                $prior = (array) ($previous['last'] ?? []);
                $age = $now - (int) ($prior['time'] ?? 0);
                if ($age >= 1500 && $age <= 2700 && is_numeric($prior['temp'] ?? null)
                    && (int) ($tMeta['VariableUpdated'] ?? 0) > (int) ($prior['temp_sample_at'] ?? 0)) {
                    $delta = (float) $temp - (float) $prior['temp'];
                    if (abs($delta) <= 0.8) { $changes[$key] = $delta; }
                }
                $state['rooms'][$key] = LCNThermalLearningModel::observe($previous, [
                    'time' => $now, 'temp' => (float) $temp, 'target' => $target,
                    'valve' => (bool) GetValue($statusID), 'outside' => $outside,
                    'temp_sample_at' => (int) ($tMeta['VariableUpdated'] ?? 0),
                    'valve_sample_at' => (int) ($meta['VariableUpdated'] ?? 0),
                    'outside_lag' => $laggedOutdoor
                ]);
                ++$processed;
            }
            if (count($changes) >= 4) {
                $sum = array_sum($changes);
                $count = count($changes);
                foreach ($changes as $id => $change) {
                    $relative = $change - ($sum - $change) / ($count - 1);
                    $label = abs($relative) <= 0.05 ? 'similar' : ($relative > 0 ? 'above' : 'below');
                    $state['rooms'][$id]['house_context'][$label] = min(10000,
                        1 + (int) ($state['rooms'][$id]['house_context'][$label] ?? 0));
                }
            }
            $state['last_tick_at'] = $now;
            $state['last_processed'] = $processed;
            $state['paused_reason'] = $processed === 0 ? 'Keine aktuellen, eindeutig zugeordneten Messpaare' : '';
            // Do not persist transient or unknown fields from old/foreign schemas.
            // Each room has a fixed set of fields: no event history or daily samples.
            foreach ($state['rooms'] as &$compactRoom) {
                $compactRoom = array_intersect_key((array) $compactRoom, array_flip([
                    'name', 'last', 'phase', 'afterheat', 'afterheat_model', 'rates',
                    'last_evidence_at', 'resets', 'rejected_stale', 'gradient_rates',
                    'weather_trend_rates', 'loss_proxy', 'house_context'
                ]));
            }
            unset($compactRoom);
            // No unbounded raw timeseries; only last sample, current period and aggregates.
            $this->WriteAttributeString('ThermalLearningState', json_encode($state, JSON_UNESCAPED_UNICODE) ?: '{}');
        } catch (Throwable $error) {
            // Learning failure must NEVER propagate into working regulation or instance status.
            $this->SendDebug('PassiveThermalLearning', $error->getMessage(), 0);
        }
    }

    public function ThermalLearningReport(): string
    {
        $enabled = $this->ReadPropertyBoolean('ThermalLearningEnabled');
        $state = json_decode($this->ReadAttributeString('ThermalLearningState'), true);
        $lines = ['SMART-FBH – passives Lernen: ' . ($enabled ? 'AKTIV' : 'AUS'),
            'Keine LCN-Befehle, keine Sollwertänderung, keine automatische Optimierung.'];
        if (!is_array($state) || empty($state['rooms'])) {
            $lines[] = 'Noch keine belastbaren Messwerte vorhanden.';
            return implode("\n", $lines);
        }
        $lines[] = 'Letzte Erfassung: ' . (isset($state['last_tick_at'])
            ? date('d.m.Y H:i', (int) $state['last_tick_at']) : 'unbekannt');
        $lines[] = 'Auswertbare Räume zuletzt: ' . (int) ($state['last_processed'] ?? 0);
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
        $weatherID = $this->ReadPropertyInteger('ThermalWeatherCenterInstance');
        $state = json_decode($this->ReadAttributeString('ThermalLearningState'), true);
        if (!is_array($state)) {
            $state = [];
        }

        $lines = [
            '=== SMART-FBH – LERN-DIAGNOSE AN CHATGPT ===',
            'Erstellt: ' . date('d.m.Y H:i:s', $now),
            'Symcon-Instanz: #' . $this->InstanceID,
            'Lernfunktion: ' . ($enabled ? 'AKTIV' : 'AUS (in der Modulkonfiguration aktivieren)'),
            'Betriebsart: ' . ($this->GetMode() === 0 ? 'HEIZEN' : 'NICHT HEIZEN – Lernphase pausiert'),
            'SICHERHEIT: NUR LESEN. Keine A7/A8, keine Relais-/S1-Befehle und keine Sollwertänderung.',
        ];

        $lastTick = (int) ($state['last_tick_at'] ?? 0);
        $tickAge = $lastTick > 0 ? max(0, $now - $lastTick) : null;
        $lines[] = 'Letzter Lernlauf: ' . ($lastTick > 0
            ? date('d.m.Y H:i:s', $lastTick) . ' (' . $tickAge . ' s alt)'
            : 'NOCH KEIN LERNLAUF');
        $lines[] = 'Lernlauf zuletzt erfasste Räume: ' . (int) ($state['last_processed'] ?? 0);
        $lines[] = 'Lernstatistik angelegt: ' . (isset($state['created_at'])
            ? date('d.m.Y H:i:s', (int) $state['created_at']) : 'noch nicht');
        if ($enabled && ($tickAge === null || $tickAge > 4500)) {
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
                    $usable = $issued > 0 && $age <= 2100
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

        $lines[] = '--- RAEUME (11 ERWARTET) ---';
        $configured = 0;
        $ready = 0;
        $withEvidence = 0;
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
            $relayID = 0;
            try {
                if ($tempID <= 0 || !IPS_VariableExists($tempID)) {
                    $reason = 'Isttemperatur-Variable fehlt';
                } else {
                    $meta = IPS_GetVariable($tempID);
                    $tempAge = (int) ($meta['VariableUpdated'] ?? 0) > 0
                        ? max(0, $now - (int) $meta['VariableUpdated']) : null;
                    if ($tempAge === null || $tempAge > 1800) {
                        $reason = 'Isttemperatur fehlt/älter als 30 Minuten';
                    } else {
                        $temp = GetValue($tempID);
                        if (!is_numeric($temp) || !is_finite((float) $temp)) {
                            $reason = 'Isttemperatur ungültig';
                        }
                    }
                }
                if ($reason === 'OK') {
                    $setpoint = $targetID > 0 ? $this->ReadTarget($targetID) : null;
                    if ($setpoint === null) {
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
                        if ($relayAge === null || $relayAge > 1800) {
                            $reason = 'Relaisrückmeldung fehlt/älter als 30 Minuten';
                        } else {
                            $relayOn = (bool) GetValue($relayID);
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
            $lines[] = $name . ' | ' . $reason;
            $lines[] = '  Ist #' . $tempID . ': '
                . ($temp === null ? '–' : number_format((float) $temp, 2, ',', '.') . ' °C')
                . ' (Alter ' . ($tempAge === null ? '?' : $tempAge . ' s') . ')'
                . ' | S1 #' . $targetID . ': '
                . ($setpoint === null ? '–' : number_format((float) $setpoint, 1, ',', '.') . ' °C');
            $lines[] = '  Relaisstatus #' . $relayID . ': '
                . ($relayOn === null ? 'unbekannt' : ($relayOn ? 'EIN' : 'AUS'))
                . ' (Alter ' . ($relayAge === null ? '?' : $relayAge . ' s') . ')'
                . ' | letzte verwertete Messung: '
                . ($lastAge === null ? 'keine' : $lastAge . ' s alt');
            $lines[] = '  Heizanstieg: ' . $describeRate($heat, '°C/h')
                . ' | passives Auskühlen: ' . $describeRate($cool, '°C/h')
                . ' | Nachlauf: ' . $describeRate($after, '°C')
                . ' | Fenster-Resets: ' . (int) ($saved['resets'] ?? 0)
                . ' | stale Abweisungen: ' . (int) ($saved['rejected_stale'] ?? 0);
            $lines[] = '  Innen-Aussen-Differenz: ' . implode(' | ', $gradientDetail);
            $trend = (array) ($saved['weather_trend_rates'] ?? []);
            $loss = (array) ($saved['loss_proxy'] ?? []);
            $context = (array) ($saved['house_context'] ?? []);
            $lines[] = '  Hausvergleich: aehnlich ' . (int) ($context['similar'] ?? 0)
                . ', relativ staerker ' . (int) ($context['above'] ?? 0)
                . ', relativ schwaecher ' . (int) ($context['below'] ?? 0);
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
        $lines[] = 'Ein gültiger Sensorwert bedeutet noch keinen gültigen Lernzyklus. '
            . 'Sonneneinstrahlung, offene Türen und unbekannter Vorlauf können Lernwerte beeinflussen.';
        $lines[] = 'Wettertrend ueber ca. 2h: nur bei >=1 K Aenderung; maximal sechs gemeinsame Wetterpunkte gespeichert. ';
        $lines[] = 'Innen-Aussen-Vergleich: Beobachtete Trends, keine kausale Heizlast oder Energiebedarf; '
            . 'Fenster nur bei Wetterveraenderung <=3 K, je Differenzklasse mindestens 3 Fenster.';
        $lines[] = 'Keine aktive Fußbodenkühlung angelernt; Abkühlen bedeutet passives Auskühlen bei Ventil AUS.';
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
