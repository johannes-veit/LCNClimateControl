<?php
declare(strict_types=1);

/**
 * EWT pump stop based on the FBH temperature behind the plate heat exchanger
 * and the highest dew point in the existing LCN rooms.
 *
 * Software-based protection only: a software command/readback cannot verify
 * a welded power contact or guarantee condensation-free physical conditions.
 */
final class LCNClimateDewPointLogic
{
    public static function dewPoint(float $temperature, float $humidity): ?float
    {
        if (!is_finite($temperature) || $temperature < -20 || $temperature > 60
            || !is_finite($humidity) || $humidity < 1 || $humidity > 100) {
            return null;
        }
        $gamma = log($humidity / 100) + 17.62 * $temperature / (243.12 + $temperature);
        $result = 243.12 * $gamma / (17.62 - $gamma);
        return is_finite($result) ? $result : null;
    }

    /** Exact boundary: running pump stops at <=1.5 K; stopped pump restarts above 2.0 K. */
    public static function allow(?float $water, ?float $dew, bool $outputOn, float $margin = 1.5, float $hysteresis = 0.5): bool
    {
        if ($water === null || $dew === null || !is_finite($water) || !is_finite($dew)
            || $water < -10 || $water > 70 || $margin < 0.5 || $hysteresis < 0) {
            return false;
        }
        return ($water - $dew) > $margin + ($outputOn ? 0 : $hysteresis);
    }
}

trait LCNClimateDewPointGuard
{
    /**
     * Native GUS/LCN ORIGINAL-variable IDs already verified in the SymBox
     * backup and used by johannes-veit/HeliosSmart.
     * Match on exact actual temperature variable ID, never on an Info link.
     * A manual HumidityVariable override, when configured, takes precedence.
     */
    private const EWT_LCN_HUMIDITY_BY_TEMPERATURE = [
        12812 => 53361, // EG Bad
        14055 => 19020, // EG Buero
        15825 => 16107, // EG Gaeste WC
        21585 => 52026, // EG Kueche
        25557 => 58531, // EG Schlafen
        29782 => 46360, // EG Wohnen
        17837 => 43385, // OG Ankleide
        36518 => 28802, // OG Bad
        34232 => 13394, // OG Flur
        30970 => 37079, // OG Schlafen 1
        25330 => 22436, // OG Schlafen 2
    ];

    /** Explicit name match only if existing room temperature ID was customized. */
    private const EWT_LCN_HUMIDITY_BY_ROOM = [
        'EG Bad' => 53361,
        'EG Büro' => 19020,
        'EG Gäste WC' => 16107,
        'EG Küche' => 52026,
        'EG Schlafen' => 58531,
        'EG Wohnen' => 46360,
        'OG Ankleide' => 43385,
        'OG Bad' => 28802,
        'OG Flur' => 13394,
        'OG Schlafen 1' => 37079,
        'OG Schlafen 2' => 22436,
    ];

    private function DewPointHumidityID(array $room): int
    {
        $explicit = (int) ($room['HumidityVariable'] ?? 0);
        if ($explicit > 0) {
            return $explicit;
        }
        $temp = (int) ($room['TemperatureVariable'] ?? 0);
        if (isset(self::EWT_LCN_HUMIDITY_BY_TEMPERATURE[$temp])) {
            return self::EWT_LCN_HUMIDITY_BY_TEMPERATURE[$temp];
        }
        return self::EWT_LCN_HUMIDITY_BY_ROOM[trim((string) ($room['Name'] ?? ''))] ?? 0;
    }

    private function DewPointFreshValue(int $id, float $lower, float $upper, int $ageLimit): ?float
    {
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return null;
        }
        try {
            $v = IPS_GetVariable($id);
            $type = (int) ($v['VariableType'] ?? -1);
            $updated = (int) ($v['VariableUpdated'] ?? 0);
            if (!in_array($type, [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true)
                || $updated <= 0 || $updated > time() + 30 || time() - $updated > $ageLimit) {
                return null;
            }
            $number = (float) GetValue($id);
            return is_finite($number) && $number >= $lower && $number <= $upper ? $number : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function DewPointGuardSnapshot(): array
    {
        $waterID = (int) $this->ReadPropertyInteger('DewPointWaterVariable');
        $water = $this->DewPointFreshValue($waterID, 0, 55, 900);
        if ($water === null) {
            return ['ok' => false, 'reason' => 'FBH-Fühler 101 (#' . $waterID . ') fehlt oder ist veraltet'];
        }
        $maximum = null;
        $criticalRoom = '';
        $count = 0;
        $ageSetting = (int) $this->ReadPropertyInteger('DewPointMaxAgeSeconds');
        // v0.5.6 stored 900 as its default, which would stop the EWT pump after
        // 15 minutes even though many verified LCN GUS sensors report on change.
        $humidityMaxAge = $ageSetting <= 900 ? 43200 : min(43200, $ageSetting);
        foreach ($this->GetRooms() as $room) {
            if (!(bool) ($room['Enabled'] ?? true)) {
                continue;
            }
            $name = (string) ($room['Name'] ?? 'Unbekannter Raum');
            $tempID = (int) ($room['TemperatureVariable'] ?? 0);
            $rhID = $this->DewPointHumidityID($room);
            $temperature = $this->DewPointFreshValue($tempID, -10, 45, 7200);
            $humidity = $this->DewPointFreshValue($rhID, 1, 100, $humidityMaxAge);
            if ($temperature === null || $humidity === null) {
                return ['ok' => false, 'reason' => $name
                    . ': Temperatur/Luftfeuchte ungueltig oder zu alt (T #' . $tempID . ', RH #' . $rhID . ')'];
            }
            $dew = LCNClimateDewPointLogic::dewPoint($temperature, $humidity);
            if ($dew === null) {
                return ['ok' => false, 'reason' => $name . ': Taupunkt nicht berechenbar'];
            }
            if ($maximum === null || $dew > $maximum) {
                $maximum = $dew;
                $criticalRoom = $name;
            }
            ++$count;
        }
        if ($count === 0) {
            return ['ok' => false, 'reason' => 'Keine aktiven LCN-Raeume'];
        }
        return [
            'ok' => true, 'water' => $water, 'dew' => $maximum,
            'room' => $criticalRoom, 'delta' => $water - $maximum, 'rooms' => $count
        ];
    }

    /** Protected for 100% offline mock RPC simulation. No cloud required. */
    protected function DewPointPumpRpc(string $method, array $params = []): array
    {
        $ip = trim($this->ReadPropertyString('DewPointPumpIP'));
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || !preg_match('/^(?:10\\.|192\\.168\\.|172\\.(?:1[6-9]|2[0-9]|3[01])\\.)/', $ip)) {
            throw new RuntimeException('EWT Shelly: ungueltige lokale IPv4-Adresse');
        }
        $url = 'http://' . $ip . '/rpc/' . $method
            . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 2]]);
        $response = @file_get_contents($url, false, $context);
        if (!is_string($response)) {
            throw new RuntimeException('Shelly nicht erreichbar');
        }
        $json = json_decode($response, true);
        if (!is_array($json) || isset($json['error']) || isset($json['code'])) {
            throw new RuntimeException('Shelly liefert RPC-Fehler');
        }
        return $json;
    }

    private function DewPointReadPumpState(): bool
    {
        $v = $this->DewPointPumpRpc('Switch.GetStatus', ['id' => 0]);
        if (!isset($v['output']) || !is_bool($v['output'])) {
            throw new RuntimeException('Shelly Ausgangsstatus fehlt');
        }
        return $v['output'];
    }

    private function DewPointCommandAndVerify(bool $desired): void
    {
        // One retry for a transient RPC or mismatched readback. Each attempt
        // checks the REAL Shelly output; a sent command alone is not success.
        $lastError = null;
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $response = $this->DewPointPumpRpc('Switch.Set', [
                    'id' => 0, 'on' => $desired ? 'true' : 'false',
                    // A running pump is only leased for 40 s. The 10 s
                    // watchdog renews it; loss of Symcon leaves auto-OFF.
                    'toggle_after' => $desired ? 40 : 0
                ]);
                if (!isset($response['was_on']) || !is_bool($response['was_on'])) {
                    throw new RuntimeException('Shelly bestaetigt Schaltbefehl nicht');
                }
                if ($this->DewPointReadPumpState() !== $desired) {
                    throw new RuntimeException('Shelly Rueckmeldung widerspricht Schaltbefehl');
                }
                return;
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }
        throw new RuntimeException(
            'Shelly-Schaltung nach zwei Versuchen nicht bestaetigt: '
            . ($lastError === null ? 'unbekannter Fehler' : $lastError->getMessage()),
            0, $lastError
        );
    }

    private function DewPointNotifyTransition(string $state, string $message): void
    {
        if ($this->ReadAttributeString('DewPointGuardNotifyState') === $state) {
            return;
        }
        $this->WriteAttributeString('DewPointGuardNotifyState', $state);
        $webfront = (int) $this->ReadPropertyInteger('DewPointPushWebFront');
        if ($webfront <= 0 || !function_exists('WFC_PushNotification')) {
            return;
        }
        try {
            WFC_PushNotification($webfront, 'EWT-Taupunktwaechter', $message, '', 0);
        } catch (Throwable $e) {
            $this->SendDebug('DewPointPush', $e->getMessage(), 0);
        }
    }

    /**
     * Every 10 seconds: validate sensors, compare with the highest room dew
     * point, switch the EWT pump, and verify the Shelly output.
     * Stop immediately; permit a restart only after 120 s continuously safe.
     */
    public function DewPointGuardTick(): void
    {
        if (!$this->ReadPropertyBoolean('DewPointAutoPumpEnabled')) {
            return;
        }
        if (!IPS_SemaphoreEnter('LCNC_DEW_' . $this->InstanceID, 200)) {
            return;
        }
        try {
            $snapshot = $this->DewPointGuardSnapshot();
            try {
                // If the initial status cannot be read, an unsafe sensor
                // situation still gets an explicit OFF attempt.
                try {
                    $wasOn = $this->DewPointReadPumpState();
                } catch (Throwable $e) {
                    if ((bool) ($snapshot['ok'] ?? false)) {
                        throw $e; // Never start without a trusted readback.
                    }
                    $this->DewPointCommandAndVerify(false);
                    $wasOn = false;
                }

                $now = time();
                $lastCheck = $this->ReadAttributeInteger('DewPointGuardLastRenew');
                if ($lastCheck <= 0 || $now < $lastCheck || $now - $lastCheck > 35) {
                    // After Symcon restart, a long scheduling gap or lost
                    // communication, the 120-s stable period starts afresh.
                    $this->WriteAttributeInteger('DewPointGuardSafeSince', 0);
                }
                $this->WriteAttributeInteger('DewPointGuardLastRenew', $now);

                $valid = (bool) ($snapshot['ok'] ?? false);
                $margin = max(0.5, min(5, (float) $this->ReadPropertyFloat('DewPointMarginK')));
                $hysteresis = max(0, min(2, (float) $this->ReadPropertyFloat('DewPointHysteresisK')));
                $water = $valid ? (float) $snapshot['water'] : null;
                $dew = $valid ? (float) $snapshot['dew'] : null;
                $waiting = false;
                $secondsLeft = 0;

                if ($wasOn) {
                    // No stop delay: water too close to dew point -> OFF now.
                    $desired = $valid && LCNClimateDewPointLogic::allow(
                        $water, $dew, true, $margin, $hysteresis
                    );
                    $this->WriteAttributeInteger('DewPointGuardSafeSince', 0);
                } else {
                    $safe = $valid && LCNClimateDewPointLogic::allow(
                        $water, $dew, false, $margin, $hysteresis
                    );
                    if (!$safe) {
                        $this->WriteAttributeInteger('DewPointGuardSafeSince', 0);
                        $desired = false;
                    } else {
                        $since = $this->ReadAttributeInteger('DewPointGuardSafeSince');
                        if ($since <= 0 || $since > $now) {
                            $since = $now;
                            $this->WriteAttributeInteger('DewPointGuardSafeSince', $since);
                        }
                        $secondsLeft = max(0, 120 - ($now - $since));
                        $waiting = $secondsLeft > 0;
                        $desired = !$waiting;
                    }
                }

                if ($wasOn && !$desired) {
                    $this->WriteAttributeInteger('DewPointGuardLastStop', $now);
                }
                // Avoid redundant OFF commands when the physical output
                // is already OFF. Every ON tick renews the short auto-OFF lease.
                if ($desired || $wasOn) {
                    $this->DewPointCommandAndVerify($desired);
                }

                $this->WriteAttributeString('DewPointGuardState', $desired ? 'ON' : 'OFF');
                $label = $desired ? 'EWT-PUMPE EIN' : 'EWT-PUMPE AUS';
                $detail = $valid
                    ? sprintf('FBH %.2f °C; Taupunkt %.2f °C (%s); Abstand %.2f K; %d Raeume',
                        $snapshot['water'], $snapshot['dew'], $snapshot['room'],
                        $snapshot['delta'], $snapshot['rooms'])
                    : (string) ($snapshot['reason'] ?? 'Messwerte ungueltig');
                $waitingText = $waiting ? (' – Wiederanlauf in ' . $secondsLeft . ' s') : '';
                $this->SetValueIfChanged('DewPointGuardStatus',
                    $label . ' – bestaetigt' . $waitingText . ' – ' . $detail);
                $state = $desired ? 'RUNNING'
                    : ($waiting ? 'WAIT'
                        : ($valid ? 'DEW_STOP' : 'SENSOR_STOP'));
                // One push per transition, not one per 10-second timer tick.
                $this->DewPointNotifyTransition($state, $label . ': ' . $detail . $waitingText);
            } catch (Throwable $e) {
                $this->WriteAttributeInteger('DewPointGuardSafeSince', 0);
                // A remote output can never be claimed OFF when readback fails.
                $this->WriteAttributeString('DewPointGuardState', 'UNKNOWN');
                $this->SetValueIfChanged('DewPointGuardStatus',
                    'NICHT BESTAETIGT – Shelly nicht erreichbar oder Rueckmeldung falsch: ' . $e->getMessage());
                $this->DewPointNotifyTransition('ERROR', 'EWT-Pumpe: ' . $e->getMessage());
                $this->SendDebug('DewPointGuard', $e->getMessage(), 0);
            }
        } finally {
            IPS_SemaphoreLeave('LCNC_DEW_' . $this->InstanceID);
        }
    }
}
