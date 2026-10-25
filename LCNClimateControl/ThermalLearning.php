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
            'valve' => $valve, 'outside' => $outside
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
        if ($gap > 1800 || $targetChanged || $implausibleJump) {
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
                if ($valid) {
                    $band = self::outdoorBand($outside);
                    $state['rates'][$kind]['all'] = self::mean(
                        (array) ($state['rates'][$kind]['all'] ?? []), abs($rate));
                    $state['rates'][$kind][$band] = self::mean(
                        (array) ($state['rates'][$kind][$band] ?? []), abs($rate));
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
            $processed = 0;
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
                $state['rooms'][$key] = LCNThermalLearningModel::observe($previous, [
                    'time' => $now, 'temp' => (float) $temp, 'target' => $target,
                    'valve' => (bool) GetValue($statusID), 'outside' => $outside
                ]);
                ++$processed;
            }
            $state['last_tick_at'] = $now;
            $state['last_processed'] = $processed;
            $state['paused_reason'] = $processed === 0 ? 'Keine aktuellen, eindeutig zugeordneten Messpaare' : '';
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
}
