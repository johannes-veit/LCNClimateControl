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

    /**
     * Cooling pump is useful only when at least one NC floor valve is
     * electrically confirmed OPEN. A selected room alone is not proof:
     * the native LCN relay feedback must agree with the stored cooling state.
     * Unreadable feedback or unknown polarity never authorizes pump ON.
     */
    private function DewPointCoolingCircuits(): array
    {
        $wanted = 0;
        $open = 0;
        foreach ($this->GetRooms() as $room) {
            if (!(bool) ($room['Enabled'] ?? true)) {
                continue; // disabled for control, but still measured for dew point
            }
            $target = (int) ($room['TargetVariable'] ?? 0);
            if ($target <= 0 || !$this->GetStoredCoolingState($target)) {
                continue;
            }
            ++$wanted;
            $name = (string) ($room['Name'] ?? 'Raum');
            try {
                $mapping = $this->DirectCoolingMapping($target);
                if (!is_array($mapping)
                    || !array_key_exists('OpenRelayOn', $mapping)
                    || !(bool) ($mapping['PolarityConfirmed'] ?? false)) {
                    return ['ok' => false, 'open' => 0, 'reason' =>
                        $name . ': Kuehlrelais-Zuordnung/Polaritaet nicht bestaetigt'];
                }
                $statusID = (int) ($mapping['RelayStatusVariable'] ?? 0);
                if ($statusID <= 0 || !IPS_VariableExists($statusID)) {
                    return ['ok' => false, 'open' => 0, 'reason' =>
                        $name . ': LCN-Relais-Rueckmeldung fehlt'];
                }
                $meta = IPS_GetVariable($statusID);
                if ((int) ($meta['VariableType'] ?? -1) !== VARIABLETYPE_BOOLEAN
                    || (int) ($meta['VariableUpdated'] ?? 0) <= 0
                    || (int) ($meta['VariableUpdated'] ?? 0) > time() + 30) {
                    return ['ok' => false, 'open' => 0, 'reason' =>
                        $name . ': LCN-Relais-Rueckmeldung ungueltig'];
                }
                // LCN relay status may update only on CHANGE, hence no
                // short age timeout on a legitimate unchanged status.
                if ((bool) GetValue($statusID) === (bool) $mapping['OpenRelayOn']) {
                    ++$open;
                }
            } catch (Throwable $e) {
                return ['ok' => false, 'open' => 0, 'reason' =>
                    $name . ': LCN-Relais nicht lesbar: ' . $e->getMessage()];
            }
        }
        return ['ok' => true, 'open' => $open, 'wanted' => $wanted];
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
        $seenTemperatureIDs = [];
        $seenHumidityIDs = [];
        $ageSetting = (int) $this->ReadPropertyInteger('DewPointMaxAgeSeconds');
        // v0.5.6 stored 900 as its default, which would stop the EWT pump after
        // 15 minutes even though many verified LCN GUS sensors report on change.
        $humidityMaxAge = $ageSetting <= 900 ? 43200 : min(43200, $ageSetting);
        $rooms = $this->GetRooms();
        if (count($rooms) !== 11) {
            return ['ok' => false, 'reason' => '11 LCN-Raeume erforderlich (konfiguriert: '
                . count($rooms) . ')'];
        }
        // Always include ALL 11 sensor locations (also "Nicht kuehlen"
        // and controller-disabled rooms): exposed supply pipes can condense.
        foreach ($rooms as $room) {
            $name = (string) ($room['Name'] ?? 'Unbekannter Raum');
            $tempID = (int) ($room['TemperatureVariable'] ?? 0);
            if ($tempID <= 0 || isset($seenTemperatureIDs[$tempID])) {
                return ['ok' => false, 'reason' => $name . ': Raumtemperatursensor fehlt oder doppelt'];
            }
            $seenTemperatureIDs[$tempID] = true;
            $rhID = $this->DewPointHumidityID($room);
            if ($rhID <= 0 || isset($seenHumidityIDs[$rhID])) {
                return ['ok' => false, 'reason' => $name . ': Luftfeuchtesensor fehlt oder doppelt'];
            }
            $seenHumidityIDs[$rhID] = true;
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

    /** Read-only display of the installed 100-104 sensors. */
    private function DewPointThermalDiagnostic(): string
    {
        $ids = [100 => 12630, 101 => (int) $this->ReadPropertyInteger('DewPointWaterVariable'),
            102 => 21409, 103 => 14561, 104 => 23904];
        $valid = [];
        $parts = [];
        $now = time();
        foreach ($ids as $number => $id) {
            try {
                if ($id <= 0 || !IPS_VariableExists($id)) {
                    throw new RuntimeException('missing');
                }
                $meta = IPS_GetVariable($id);
                $stamp = (int) ($meta['VariableUpdated'] ?? 0);
                if (!in_array((int) ($meta['VariableType'] ?? -1),
                    [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true)
                    || $stamp <= 0 || $stamp > $now + 30 || $now - $stamp > 900) {
                    throw new RuntimeException('stale');
                }
                $v = (float) GetValue($id);
                if (!is_finite($v) || $v < 0 || $v > 55) {
                    throw new RuntimeException('range');
                }
                $valid[$number] = ['v' => $v, 'at' => $stamp];
                $parts[] = sprintf('T%d %.2f °C', $number, $v);
            } catch (Throwable) {
                $parts[] = 'T' . $number . ' --';
            }
        }
        $deltas = [];
        foreach ([[103, 101, 'FBH-WT'], [100, 104, 'EWT-WT']] as [$a, $b, $label]) {
            if (!isset($valid[$a], $valid[$b])) {
                $deltas[] = $label . ' --';
            } elseif (abs($valid[$a]['at'] - $valid[$b]['at']) > 120) {
                $deltas[] = $label . ' nicht synchron';
            } else {
                $deltas[] = sprintf('%s %+.2f K', $label, $valid[$b]['v'] - $valid[$a]['v']);
            }
        }
        return ' | WT ' . implode('; ', $parts) . ' | ' . implode('; ', $deltas);
    }


    /** Optional control state. Switching the option OFF restores the old pump logic. */
    private function DewPointEfficiencyReset(): void
    {
        foreach (['DewPointEfficiencyRunSince', 'DewPointEfficiencyWeakSince',
                  'DewPointEfficiencyPauseUntil'] as $name) {
            if ($this->ReadAttributeInteger($name) !== 0) {
                $this->WriteAttributeInteger($name, 0);
            }
        }
    }

    /** Fresh, independent water-temperature snapshot for opt-in decisions. */
    private function DewPointEfficiencyReading(int $id, int $now): ?array
    {
        try {
            if ($id <= 0 || !IPS_VariableExists($id)) {
                return null;
            }
            $meta = IPS_GetVariable($id);
            $at = (int) ($meta['VariableUpdated'] ?? 0);
            if (!in_array((int) ($meta['VariableType'] ?? -1),
                    [VARIABLETYPE_INTEGER, VARIABLETYPE_FLOAT], true)
                || $at <= 0 || $at > $now + 30 || $now - $at > 180) {
                return null;
            }
            $value = (float) GetValue($id);
            return is_finite($value) && $value >= 0 && $value <= 55
                ? ['v' => $value, 'at' => $at] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Optional efficiency veto ONLY. It can switch the EWT pump OFF, but
     * cannot authorize ON unless the original 11-room dewpoint protection,
     * the LCN relay feedback and the Shelly watchdog all authorize it.
     *
     * Weak useful cooling requires 10 min running + 5 min sustained evidence:
     * source advantage <=0.5 K, FBH cooling <=0.3 K and EWT heat gain
     * <=0.3 K. Then pause for 15 min and try again using the legacy
     * 120-s safe-temperature restart. Missing/asynchronous data never
     * cause an efficiency pause.
     */
    private function DewPointEfficiencyDecision(
        bool $wasOn, bool $coreAllows, array $snapshot,
        int $now, float $margin, float $hysteresis
    ): array {
        if (!$this->ReadPropertyBoolean('DewPointEfficiencyEnabled')) {
            $this->DewPointEfficiencyReset();
            return ['allow' => true, 'pause' => false, 'detail' => ''];
        }
        $pauseUntil = $this->ReadAttributeInteger('DewPointEfficiencyPauseUntil');
        if ($pauseUntil > $now) {
            return ['allow' => false, 'pause' => true,
                'detail' => 'Zusatzregelung EIN: Effizienzpause ' . ($pauseUntil - $now) . ' s'];
        }
        if ($pauseUntil !== 0) {
            $this->WriteAttributeInteger('DewPointEfficiencyPauseUntil', 0);
        }
        if (!$coreAllows || !(bool) ($snapshot['ok'] ?? false)) {
            $this->WriteAttributeInteger('DewPointEfficiencyRunSince', 0);
            $this->WriteAttributeInteger('DewPointEfficiencyWeakSince', 0);
            return ['allow' => true, 'pause' => false,
                'detail' => 'Zusatzregelung EIN: Grundfreigabe abwarten'];
        }
        // T102 is the actual FBH supply: if fresh, it also needs
        // the original dewpoint margin. Its absence has no effect on
        // the original sensor 101 safety logic.
        $supply = $this->DewPointEfficiencyReading(21409, $now);
        if ($supply !== null && !LCNClimateDewPointLogic::allow(
            $supply['v'], (float) $snapshot['dew'], $wasOn,
            $margin, $hysteresis
        )) {
            $this->WriteAttributeInteger('DewPointEfficiencyRunSince', 0);
            $this->WriteAttributeInteger('DewPointEfficiencyWeakSince', 0);
            return ['allow' => false, 'pause' => false,
                'detail' => 'Zusatzregelung EIN: FBH-Vorlauf T102 nahe Taupunkt'];
        }
        if (!$wasOn) {
            // Remember a fresh pump start but never shorten the existing
            // 120-second mandatory temperature-stability period.
            $this->WriteAttributeInteger('DewPointEfficiencyRunSince', $now);
            $this->WriteAttributeInteger('DewPointEfficiencyWeakSince', 0);
            return ['allow' => true, 'pause' => false,
                'detail' => 'Zusatzregelung EIN: Anlauf/Pruefphase'];
        }
        $runSince = $this->ReadAttributeInteger('DewPointEfficiencyRunSince');
        if ($runSince <= 0 || $runSince > $now) {
            $runSince = $now;
            $this->WriteAttributeInteger('DewPointEfficiencyRunSince', $runSince);
        }
        if ($now - $runSince < 600) {
            $this->WriteAttributeInteger('DewPointEfficiencyWeakSince', 0);
            return ['allow' => true, 'pause' => false,
                'detail' => 'Zusatzregelung EIN: Messphase'];
        }
        $values = [];
        foreach ([100 => 12630,
                  101 => (int) $this->ReadPropertyInteger('DewPointWaterVariable'),
                  103 => 14561, 104 => 23904] as $number => $id) {
            $v = $this->DewPointEfficiencyReading($id, $now);
            if ($v === null) {
                $this->WriteAttributeInteger('DewPointEfficiencyWeakSince', 0);
                return ['allow' => true, 'pause' => false,
                    'detail' => 'Zusatzregelung EIN: Daten fehlen, Standardbetrieb'];
            }
            $values[$number] = $v;
        }
        $times = array_column($values, 'at');
        if (max($times) - min($times) > 120) {
            $this->WriteAttributeInteger('DewPointEfficiencyWeakSince', 0);
            return ['allow' => true, 'pause' => false,
                'detail' => 'Zusatzregelung EIN: nicht synchron, Standardbetrieb'];
        }
        $source = $values[103]['v'] - $values[100]['v'];
        $fbh = $values[103]['v'] - $values[101]['v'];
        $ewt = $values[104]['v'] - $values[100]['v'];
        $weak = $source <= 0.5 && $fbh <= 0.3 && $ewt <= 0.3;
        if (!$weak) {
            $this->WriteAttributeInteger('DewPointEfficiencyWeakSince', 0);
            return ['allow' => true, 'pause' => false,
                'detail' => 'Zusatzregelung EIN: Kuehlwirkung vorhanden'];
        }
        $weakSince = $this->ReadAttributeInteger('DewPointEfficiencyWeakSince');
        if ($weakSince <= 0 || $weakSince > $now) {
            $weakSince = $now;
            $this->WriteAttributeInteger('DewPointEfficiencyWeakSince', $weakSince);
        }
        if ($now - $weakSince < 300) {
            return ['allow' => true, 'pause' => false,
                'detail' => 'Zusatzregelung EIN: geringe Wirkung, beobachten'];
        }
        $this->WriteAttributeInteger('DewPointEfficiencyPauseUntil', $now + 900);
        $this->WriteAttributeInteger('DewPointEfficiencyRunSince', 0);
        $this->WriteAttributeInteger('DewPointEfficiencyWeakSince', 0);
        return ['allow' => false, 'pause' => true,
            'detail' => 'Zusatzregelung EIN: geringe Kuehlwirkung, 15 min Effizienzpause'];
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
            // HEIZEN: the physical cooling outlet is intentionally without
            // power. The Shelly is unreachable by design; that is not a fault.
            // Do not infer or claim a confirmed plug output from this mode.
            if ($this->GetMode() !== self::MODE_COOLING) {
                $this->WriteAttributeInteger('DewPointGuardSafeSince', 0);
                $this->WriteAttributeInteger('DewPointGuardLastRenew', 0);
                $this->DewPointEfficiencyReset();
                $this->WriteAttributeString('DewPointGuardState', 'HEATING_PAUSED');
                $this->WriteAttributeString('DewPointGuardNotifyState', 'HEATING_PAUSED');
                $this->SetValueIfChanged('DewPointGuardStatus',
                    'HEIZEN – EWT-Taupunktwaechter inaktiv (Shelly stromlos erwartet)');
                return;
            }
            $snapshot = $this->DewPointGuardSnapshot();
            $circuits = $this->DewPointCoolingCircuits();
            $roomsMeasured = (bool) ($snapshot['ok'] ?? false);
            $circuitsKnown = (bool) ($circuits['ok'] ?? false);
            $openCircuits = (int) ($circuits['open'] ?? 0);
            $coolingAllowed = $roomsMeasured && $circuitsKnown && $openCircuits > 0;
            try {
                // If the initial status cannot be read, an unsafe sensor
                // situation still gets an explicit OFF attempt.
                try {
                    $wasOn = $this->DewPointReadPumpState();
                } catch (Throwable $e) {
                    if ($coolingAllowed) {
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
                    $this->WriteAttributeInteger('DewPointEfficiencyRunSince', 0);
                    $this->WriteAttributeInteger('DewPointEfficiencyWeakSince', 0);
                }
                $this->WriteAttributeInteger('DewPointGuardLastRenew', $now);

                $valid = $coolingAllowed;
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

                // Preserve the true safety reason in notifications even
                // when a prior efficiency pause happens to be active.
                $coreSafetyStop = !$desired && !$waiting;
                $efficiency = $this->DewPointEfficiencyDecision(
                    $wasOn, $desired, $snapshot, $now, $margin, $hysteresis
                );
                if (!$efficiency['allow']) {
                    $desired = false;
                    $waiting = false;
                    $secondsLeft = 0;
                    $this->WriteAttributeInteger('DewPointGuardSafeSince', 0);
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
                $detail = $roomsMeasured
                    ? sprintf('FBH %.2f °C; Taupunkt %.2f °C (%s); Abstand %.2f K; %d Raeume',
                        $snapshot['water'], $snapshot['dew'], $snapshot['room'],
                        $snapshot['delta'], $snapshot['rooms'])
                    : (string) ($snapshot['reason'] ?? 'Messwerte ungueltig');
                if (!$circuitsKnown) {
                    $detail .= ' – ' . (string) ($circuits['reason'] ?? 'Kuehlkreise unklar');
                } elseif ($openCircuits === 0) {
                    $detail .= ' – kein Kuehlventil offen';
                } else {
                    $detail .= ' – ' . $openCircuits . ' Kuehlventil(e) offen';
                }
                $waitingText = $waiting ? (' – Wiederanlauf in ' . $secondsLeft . ' s') : '';
                $this->SetValueIfChanged('DewPointGuardStatus',
                    $label . ' – bestaetigt' . $waitingText . ' – ' . $detail
                        . $this->DewPointThermalDiagnostic()
                        . ($efficiency['detail'] !== '' ? ' | ' . $efficiency['detail'] : ''));
                $state = ($efficiency['pause'] && !$desired && !$coreSafetyStop) ? 'EFFICIENCY_PAUSE'
                    : ($desired ? 'RUNNING'
                    : ($waiting ? 'WAIT'
                        : (!$circuitsKnown ? 'RELAY_STOP'
                            : ($openCircuits === 0 ? 'NO_CIRCUIT'
                                : ($roomsMeasured ? 'DEW_STOP' : 'SENSOR_STOP')))));
                // No-circuit and efficiency pause are normal, not safety alarms.
                if (in_array($state, ['NO_CIRCUIT', 'EFFICIENCY_PAUSE'], true)) {
                    $this->WriteAttributeString('DewPointGuardNotifyState', $state);
                } else {
                    $this->DewPointNotifyTransition($state, $label . ': ' . $detail . $waitingText);
                }
            } catch (Throwable $e) {
                $this->WriteAttributeInteger('DewPointGuardSafeSince', 0);
                $this->WriteAttributeInteger('DewPointEfficiencyRunSince', 0);
                $this->WriteAttributeInteger('DewPointEfficiencyWeakSince', 0);
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
