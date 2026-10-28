<?php
declare(strict_types=1);

/**
 * EWT pump dewpoint guard. Separate from LCN valve/S1 control.
 * A Shelly Plug M Gen3 is powered ONLY by the physical KUEHLEN outlet.
 *
 * This is software-only protection; it is NOT an independent hardware
 * condensation detector and must not be reported as one.
 */
final class LCNClimateDewPointLogic
{
    public static function dewPoint(float $temperature, float $humidity): ?float
    {
        if (!is_finite($temperature) || $temperature < -20.0 || $temperature > 60.0
            || !is_finite($humidity) || $humidity < 1.0 || $humidity > 100.0) {
            return null;
        }
        // Magnus approximation, temperature in Celsius, RH in percent.
        $gamma = log($humidity / 100.0) + (17.62 * $temperature) / (243.12 + $temperature);
        $dew = 243.12 * $gamma / (17.62 - $gamma);
        return is_finite($dew) ? $dew : null;
    }

    /** No sensor or invalid sensor means STOP. ON needs 2 K to rearm;
     * an already running pump can continue until the 1.5 K limit.
     */
    public static function allow(?float $water, ?float $maxDew, bool $wasOn, float $margin = 1.5, float $hysteresis = 0.5): bool
    {
        if ($water === null || $maxDew === null || !is_finite($water)
            || !is_finite($maxDew) || $water < -10.0 || $water > 70.0
            || $margin < 0.5 || $hysteresis < 0.0) {
            return false;
        }
        return ($water - $maxDew) > ($margin + ($wasOn ? 0.0 : $hysteresis));
    }
}

trait LCNClimateDewPointGuard
{
    private function DewPointGuardSnapshot(): array
    {
        $waterID = (int) $this->ReadPropertyInteger('DewPointWaterVariable');
        $water = $this->DewPointReadFreshNumber($waterID, -10.0, 70.0);
        if ($water === null) {
            return ['ok' => false, 'reason' => 'Fühler 101 (#' . $waterID . ') fehlt, unplausibel oder veraltet'];
        }

        $maxDew = null;
        $critical = '';
        $count = 0;
        foreach ($this->GetRooms() as $room) {
            if (!(bool) ($room['Enabled'] ?? false)) {
                continue;
            }
            $name = (string) ($room['Name'] ?? 'Raum');
            $tempID = (int) ($room['TemperatureVariable'] ?? 0);
            $rhID = (int) ($room['HumidityVariable'] ?? 0);
            if ($tempID <= 0 || $rhID <= 0) {
                return ['ok' => false, 'reason' => $name . ': Raumtemperatur oder Feuchtevariable fehlt'];
            }
            $temp = $this->DewPointReadFreshNumber($tempID, -20.0, 60.0);
            $rh = $this->DewPointReadFreshNumber($rhID, 1.0, 100.0);
            if ($temp === null || $rh === null) {
                return ['ok' => false, 'reason' => $name . ': Temperatur/Feuchte fehlt oder veraltet'];
            }
            $dew = LCNClimateDewPointLogic::dewPoint($temp, $rh);
            if ($dew === null) {
                return ['ok' => false, 'reason' => $name . ': Taupunkt nicht berechenbar'];
            }
            ++$count;
            if ($maxDew === null || $dew > $maxDew) {
                $maxDew = $dew;
                $critical = $name;
            }
        }
        if ($count === 0) {
            return ['ok' => false, 'reason' => 'Keine aktiven Räume mit Feuchtemessung'];
        }
        return [
            'ok' => true, 'water' => $water, 'dew' => $maxDew,
            'room' => $critical, 'count' => $count, 'delta' => $water - $maxDew
        ];
    }

    private function DewPointReadFreshNumber(int $id, float $minimum, float $maximum): ?float
    {
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return null;
        }
        try {
            $var = IPS_GetVariable($id);
            $kind = (int) ($var['VariableType'] ?? -1);
            $updated = (int) ($var['VariableUpdated'] ?? 0);
            $ageLimit = max(60, min(7200, (int) $this->ReadPropertyInteger('DewPointMaxAgeSeconds')));
            if (!in_array($kind, [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true)
                || $updated <= 0 || $updated > time() + 30 || time() - $updated > $ageLimit) {
                return null;
            }
            $value = (float) GetValue($id);
            return is_finite($value) && $value >= $minimum && $value <= $maximum ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function DewPointPumpRpc(string $method, array $parameters = []): array
    {
        $ip = trim($this->ReadPropertyString('DewPointPumpIP'));
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || !preg_match('/^(10\\.|192\\.168\\.|172\\.(1[6-9]|2[0-9]|3[01])\\.)/', $ip)) {
            throw new RuntimeException('Keine gültige private IPv4-Adresse für die EWT-Pumpe konfiguriert');
        }
        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $url = 'http://' . $ip . '/rpc/' . $method . ($query !== '' ? '?' . $query : '');
        $ctx = stream_context_create(['http' => ['timeout' => 2, 'method' => 'GET']]);
        $answer = @file_get_contents($url, false, $ctx);
        if (!is_string($answer)) {
            throw new RuntimeException('Shelly Plug nicht per RPC erreichbar');
        }
        $response = json_decode($answer, true);
        if (!is_array($response) || isset($response['error']) || isset($response['code'])) {
            throw new RuntimeException('Shelly RPC liefert keine gültige Antwort');
        }
        return $response;
    }

    private function DewPointSetPump(bool $on): void
    {
        // Every ON is a short-lived lease. If Symcon, WLAN or timer fails,
        // Shelly switches OFF locally after the timeout (firmware permitting).
        $reply = $this->DewPointPumpRpc('Switch.Set', [
            'id' => 0, 'on' => $on ? 'true' : 'false',
            'toggle_after' => $on ? 40 : 0
        ]);
        if (!array_key_exists('was_on', $reply)) {
            throw new RuntimeException('Shelly Switch.Set ohne Bestätigung');
        }
        $status = $this->DewPointPumpRpc('Switch.GetStatus', ['id' => 0]);
        if (!array_key_exists('output', $status) || (bool) $status['output'] !== $on) {
            throw new RuntimeException('Shelly-Ausgang meldet nicht den angeforderten Zustand');
        }
    }

    /** 10-second scheduler, deliberately independent of LCN mode and valve tests. */
    public function DewPointGuardTick(): void
    {
        if (!$this->ReadPropertyBoolean('DewPointGuardEnabled')) {
            return;
        }
        if (!IPS_SemaphoreEnter('LCNC_DEW_' . $this->InstanceID, 200)) {
            return; // next tick; an ON lease will expire without renewal
        }
        try {
            $snapshot = $this->DewPointGuardSnapshot();
            $simulation = $this->ReadPropertyBoolean('DewPointGuardSimulation');
            $previousOn = $this->ReadAttributeString('DewPointGuardState') === 'ON'
                && $this->ReadAttributeInteger('DewPointGuardLastRenew') > time() - 25;
            $margin = max(0.5, min(5.0, (float) $this->ReadPropertyFloat('DewPointMarginK')));
            $hysteresis = max(0.1, min(2.0, (float) $this->ReadPropertyFloat('DewPointHysteresisK')));
            $allowed = ($snapshot['ok'] ?? false)
                && LCNClimateDewPointLogic::allow((float) $snapshot['water'], (float) $snapshot['dew'], $previousOn, $margin, $hysteresis);
            if ($simulation) {
                $this->WriteAttributeString('DewPointGuardState', 'UNKNOWN');
                $this->WriteAttributeInteger('DewPointGuardLastRenew', 0);
                $this->SetValueIfChanged('DewPointGuardStatus', 'BEOBACHTUNG – '
                    . (($snapshot['ok'] ?? false)
                        ? sprintf('Wasser %.2f °C; Taupunkt %.2f °C (%s); Abstand %.2f K; rechnerisch %s',
                            $snapshot['water'], $snapshot['dew'], $snapshot['room'], $snapshot['delta'],
                            $allowed ? 'FREI' : 'STOPP')
                        : (string) $snapshot['reason']));
                return;
            }

            $now = time();
            $safeSince = $this->ReadAttributeInteger('DewPointGuardSafeSince');
            if (!$allowed) {
                $this->WriteAttributeInteger('DewPointGuardSafeSince', 0);
                if ($previousOn) {
                    $this->WriteAttributeInteger('DewPointGuardLastStop', $now);
                }
            } elseif ($safeSince === 0) {
                $this->WriteAttributeInteger('DewPointGuardSafeSince', $now);
            }
            $minOff = max(15, min(180, (int) $this->ReadPropertyInteger('DewPointMinOffSeconds')));
            $lastStop = $this->ReadAttributeInteger('DewPointGuardLastStop');
            $canStart = $allowed && ($previousOn
                || (($now - $this->ReadAttributeInteger('DewPointGuardSafeSince')) >= 30
                    && ($lastStop <= 0 || $now - $lastStop >= $minOff)));
            try {
                $this->DewPointSetPump($canStart);
                $this->WriteAttributeString('DewPointGuardState', $canStart ? 'ON' : 'OFF');
                $this->WriteAttributeInteger('DewPointGuardLastRenew', $canStart ? $now : 0);
                $this->SetValueIfChanged('DewPointGuardStatus',
                    ($canStart ? 'EWT-PUMPE EIN' : 'EWT-PUMPE AUS') . ' – '
                    . (($snapshot['ok'] ?? false)
                        ? sprintf('FBH %.2f °C; Taupunkt %.2f °C (%s); Abstand %.2f K',
                            $snapshot['water'], $snapshot['dew'], $snapshot['room'], $snapshot['delta'])
                        : (string) ($snapshot['reason'] ?? 'Sensor ungültig')));
            } catch (Throwable $e) {
                $this->WriteAttributeString('DewPointGuardState', 'UNKNOWN');
                $this->WriteAttributeInteger('DewPointGuardLastRenew', 0);
                $this->WriteAttributeInteger('DewPointGuardSafeSince', 0);
                $this->WriteAttributeInteger('DewPointGuardLastStop', $now);
                $this->SetValueIfChanged('DewPointGuardStatus',
                    'STÖRUNG / Pumpenfreigabe nicht bestätigt: ' . $e->getMessage()
                    . '. Lokale Shelly-Zeitabschaltung erforderlich.');
                $this->SendDebug('DewPointGuard', $e->getMessage(), 0);
            }
        } finally {
            IPS_SemaphoreLeave('LCNC_DEW_' . $this->InstanceID);
        }
    }
}
