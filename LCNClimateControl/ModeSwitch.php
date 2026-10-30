<?php
declare(strict_types=1);

/**
 * Optional physical HEIZEN/KUEHLEN contact replacement using Shelly 1 Gen3.
 * The physical plant has REVERSED contact logic:
 *   Shelly I/O CLOSED (output ON) = HEIZEN
 *   Shelly I/O OPEN   (output OFF) = KUEHLEN
 *
 * Never arm a toggle_after/Auto-OFF timer on this relay: such a timer would
 * convert HEIZEN to KUEHLEN during a Symcon/WLAN failure.
 *
 * The separate EWT pump plug is still guarded by DewPointGuard with its own
 * short automatic-OFF lease. A power loss of the Shelly alone can OPEN I/O
 * even if the rest of the heating plant is live; software cannot make that
 * physical state intrinsically fail-safe.
 */
trait LCNClimateModeSwitch
{
    private function ModeSwitchIsShelly(): bool
    {
        return $this->ReadPropertyInteger('ModeSwitchSource') === 1;
    }

    private function ModeSwitchTargetIP(): string
    {
        $ip = trim($this->ReadPropertyString('ModeSwitchShellyIP'));
        if ($ip === '') {
            $ip = '192.168.103.71';
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || !preg_match('/^(?:10\\.|192\\.168\\.|172\\.(?:1[6-9]|2[0-9]|3[01])\\.)/', $ip)) {
            throw new RuntimeException('Betriebsart-Shelly: lokale IPv4-Adresse fehlt oder ist ungueltig');
        }
        if ($ip === trim($this->ReadPropertyString('DewPointPumpIP'))) {
            throw new RuntimeException('Betriebsart-Shelly darf nicht dieselbe IP wie der EWT-Pumpen-Plug verwenden');
        }
        return $ip;
    }

    protected function ModeSwitchRpc(string $method, array $params = []): array
    {
        $ip = $this->ModeSwitchTargetIP();
        $url = 'http://' . $ip . '/rpc/' . $method
            . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $response = @file_get_contents($url, false, stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 2, 'ignore_errors' => true]
        ]));
        if (!is_string($response)) {
            throw new RuntimeException('Betriebsart-Shelly nicht erreichbar');
        }
        $json = json_decode($response, true);
        if (!is_array($json) || isset($json['error']) || isset($json['code'])) {
            throw new RuntimeException('Betriebsart-Shelly RPC-Fehler');
        }
        return $json;
    }

    private function ModeSwitchReport(string $status): void
    {
        $this->SetValueIfChanged('ModeSwitchStatus', $status);
    }

    private function ModeSwitchVerifyConfig(): void
    {
        $this->ModeSwitchTargetIP();
        if (!$this->ReadPropertyBoolean('ModeSwitchWiringConfirmed')) {
            throw new RuntimeException('Betriebsart-Shelly: elektrische Zuordnung nicht bestaetigt');
        }
        if (!$this->ReadPropertyBoolean('DewPointAutoPumpEnabled')) {
            throw new RuntimeException('Betriebsart-Shelly: Taupunkt-Pumpenautomatik muss aktiv sein');
        }
        $cfg = $this->ModeSwitchRpc('Switch.GetConfig', ['id' => 0]);
        if (($cfg['initial_state'] ?? null) !== 'on'
            || ($cfg['in_mode'] ?? null) !== 'detached'
            || !array_key_exists('auto_on', $cfg) || $cfg['auto_on'] !== false
            || !array_key_exists('auto_off', $cfg) || $cfg['auto_off'] !== false) {
            throw new RuntimeException(
                'Shelly: Einschaltzustand EIN, Eingang DETACHED, Auto-ON AUS und Auto-OFF AUS erforderlich'
            );
        }
    }

    private function ModeSwitchReadStatus(): array
    {
        $this->ModeSwitchTargetIP();
        $v = $this->ModeSwitchRpc('Switch.GetStatus', ['id' => 0]);
        if (!isset($v['output']) || !is_bool($v['output'])) {
            throw new RuntimeException('Betriebsart-Shelly: Ausgangsrueckmeldung fehlt');
        }
        return $v;
    }

    private function ModeSwitchReadOutput(): bool
    {
        return $this->ModeSwitchReadStatus()['output'];
    }

    /**
     * Always issue a permanent state command, with NO toggle_after parameter.
     * On HEIZEN ensure no old toggle_after timer is left from v0.5.16.
     */
    private function ModeSwitchSetAndVerify(bool $on): void
    {
        $this->ModeSwitchTargetIP();
        $last = null;
        for ($n = 0; $n < 2; ++$n) {
            try {
                $this->ModeSwitchRpc('Switch.Set', ['id' => 0, 'on' => $on ? 'true' : 'false']);
                $status = $this->ModeSwitchReadStatus();
                if ($status['output'] !== $on) {
                    throw new RuntimeException('Shelly-Ausgang weicht vom Sollzustand ab');
                }
                if ($on && ($status['timer_type'] ?? '') === 'toggle_after') {
                    throw new RuntimeException('Shelly hat noch einen alten toggle_after-Auto-AUS-Timer');
                }
                return;
            } catch (Throwable $e) {
                $last = $e;
            }
        }
        throw new RuntimeException('Betriebsarten-Shelly nicht bestaetigt: ' . ($last?->getMessage() ?? 'unbekannt'));
    }

    /** Inverse physical fallback: ON closes the heating contact. */
    private function ModeSwitchTryHeat(): bool
    {
        try {
            $this->ModeSwitchSetAndVerify(true);
            return true;
        } catch (Throwable $e) {
            $this->SendDebug('ModeSwitchHeatFallback', $e->getMessage(), 0);
            return false;
        }
    }

    /**
     * Only Symcon's existing HEIZEN/KUEHLEN mode requests call this method.
     * A cooling request releases the contact only after all prerequisites.
     * A heating request closes the contact before changing Symcon mode.
     */
    private function ModeSwitchSelect(int $mode): void
    {
        if (!$this->ModeSwitchIsShelly()) {
            return;
        }
        $this->WriteAttributeBoolean('ModeSwitchBlocked', true);

        if ($mode === self::MODE_HEATING) {
            try {
                $this->ModeSwitchSetAndVerify(true);
                $this->ModeSwitchReport('HEIZEN – Shelly EIN (Kontakt geschlossen), bestaetigt');
            } catch (Throwable $e) {
                $this->ModeSwitchReport('FEHLER: HEIZEN – Shelly EIN nicht bestaetigt: ' . $e->getMessage());
                throw $e;
            }
            return;
        }

        try {
            $this->ModeSwitchVerifyConfig();
            $this->ModeSwitchSetAndVerify(false);
            $this->WriteAttributeBoolean('ModeSwitchBlocked', false);
            $this->ModeSwitchReport('KUEHLEN – Shelly AUS (Kontakt offen), bestaetigt');
        } catch (Throwable $e) {
            $heat = $this->ModeSwitchTryHeat();
            $this->ModeSwitchReport('FEHLER: KUEHLEN gesperrt (' . $e->getMessage()
                . '); Rueckschaltung HEIZEN ' . ($heat ? 'bestaetigt' : 'NICHT BESTAETIGT'));
            throw $e;
        }
    }

    /**
     * Independent of the UI: regularly verify physical mode feedback.
     * HEIZEN -> ON (no time-limited lease); KUEHLEN -> OFF.
     * After a cooling fault, block the separate pump guard and attempt
     * to force HEIZEN until a new explicit cooling request.
     */
    public function ModeSwitchTick(): void
    {
        if (!$this->ModeSwitchIsShelly()) {
            return;
        }
        if (!IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 200)) {
            return;
        }
        try {
            $cooling = $this->GetMode() === self::MODE_COOLING;
            $blocked = $this->ReadAttributeBoolean('ModeSwitchBlocked');
            if ($cooling && !$blocked) {
                try {
                    $this->ModeSwitchVerifyConfig();
                    // A commissioning preflight is ONLY valid before a
                    // mode change: in active cooling, regulator locks are
                    // EXPECTED and must not cause a false heating fallback.
                    // Runtime: respect ongoing external/manual safety veto.
                    if (!$this->DirectCoolingSafe()) {
                        throw new RuntimeException('Laufende externe Kuehlsicherheitsfreigabe fehlt');
                    }
                    // Do not send an unnecessary Set(OFF) every 10 s.
                    // A divergence is a fault, not permission to start cooling.
                    if ($this->ModeSwitchReadOutput() !== false) {
                        throw new RuntimeException('Shelly-Kontakt nicht AUS: Kuehlstellung nicht bestaetigt');
                    }
                    $this->ModeSwitchReport('KUEHLEN – Shelly AUS (Kontakt offen), bestaetigt');
                } catch (Throwable $e) {
                    $this->WriteAttributeBoolean('ModeSwitchBlocked', true);
                    $heat = $this->ModeSwitchTryHeat();
                    $this->ModeSwitchReport('FEHLER: Kuehlung gesperrt (' . $e->getMessage()
                        . '); HEIZEN ' . ($heat ? 'bestaetigt' : 'NICHT BESTAETIGT'));
                    $this->SendDebug('ModeSwitch', $e->getMessage(), 0);
                }
                return;
            }

            // HEIZEN or blocked KUEHLEN: enforce the real physical heating
            // contact, and cancel any old v0.5.16 toggle_after watchdog.
            try {
                $state = $this->ModeSwitchReadStatus();
                if ($state['output'] !== true || ($state['timer_type'] ?? '') === 'toggle_after') {
                    $this->ModeSwitchSetAndVerify(true);
                }
                $this->ModeSwitchReport($cooling
                    ? 'KUEHLEN GESPERRT – Shelly EIN / Anlage HEIZEN bestaetigt; KUEHLEN erneut anfordern'
                    : 'HEIZEN – Shelly EIN (Kontakt geschlossen), bestaetigt');
            } catch (Throwable $e) {
                $this->WriteAttributeBoolean('ModeSwitchBlocked', true);
                $heat = $this->ModeSwitchTryHeat();
                $this->ModeSwitchReport('FEHLER: HEIZEN-Kontakt '
                    . ($heat ? 'EIN bestaetigt' : 'NICHT BESTAETIGT')
                    . ' (' . $e->getMessage() . ')');
            }
        } finally {
            IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
        }
    }
}
