<?php
declare(strict_types=1);

/**
 * Optional physical HEIZEN/KUEHLEN contact replacement using Shelly 1 Gen3.
 * Default mode remains the existing manual wall switch.
 * The Shelly's dry contact I/O replaces ONE verified normally-open contact.
 *
 * Software watchdog: each ON request is a 35-second Shelly toggle_after
 * lease, renewed every 10 seconds, never permanent ON. Loss of the Symcon
 * timer or WLAN therefore causes the Shelly to release its own output.
 * This is not an independent hardware heating/cooling interlock.
 */
trait LCNClimateModeSwitch
{
    private function ModeSwitchIsShelly(): bool
    {
        return $this->ReadPropertyInteger('ModeSwitchSource') === 1;
    }

    /** Protected so the real RPC can be tested completely offline. */
    protected function ModeSwitchRpc(string $method, array $params = []): array
    {
        $ip = trim($this->ReadPropertyString('ModeSwitchShellyIP'));
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || !preg_match('/^(?:10\\.|192\\.168\\.|172\\.(?:1[6-9]|2[0-9]|3[01])\\.)/', $ip)) {
            throw new RuntimeException('Betriebsart-Shelly: lokale IPv4-Adresse fehlt oder ist ungueltig');
        }
        // Do not silently target the EWT-PUMP plug with operating-mode commands.
        if ($ip === trim($this->ReadPropertyString('DewPointPumpIP'))) {
            throw new RuntimeException('Betriebsart-Shelly muss ein ANDERES Geraet als der EWT-Pumpen-Plug sein');
        }
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
        if (!$this->ReadPropertyBoolean('ModeSwitchWiringConfirmed')) {
            throw new RuntimeException('Betriebsart-Shelly: elektrische Zuordnung noch nicht bestaetigt');
        }
        if (!$this->ReadPropertyBoolean('DewPointAutoPumpEnabled')) {
            throw new RuntimeException('Betriebsart-Shelly: Taupunkt-Pumpenautomatik muss aktiv sein');
        }
        $cfg = $this->ModeSwitchRpc('Switch.GetConfig', ['id' => 0]);
        if (($cfg['initial_state'] ?? null) !== 'off'
            || ($cfg['in_mode'] ?? null) !== 'detached'
            || ($cfg['auto_on'] ?? false) !== false) {
            throw new RuntimeException(
                'Shelly-Konfiguration: Einschaltzustand AUS, Eingang DETACHED und Auto-ON AUS erforderlich'
            );
        }
    }

    private function ModeSwitchReadOutput(): bool
    {
        $v = $this->ModeSwitchRpc('Switch.GetStatus', ['id' => 0]);
        if (!isset($v['output']) || !is_bool($v['output'])) {
            throw new RuntimeException('Betriebsart-Shelly: Ausgangsrueckmeldung fehlt');
        }
        return $v['output'];
    }

    private function ModeSwitchSetAndVerify(bool $on): void
    {
        $last = null;
        for ($n = 0; $n < 2; ++$n) {
            try {
                $params = ['id' => 0, 'on' => $on ? 'true' : 'false'];
                if ($on) {
                    // The independent Shelly timer runs even if Symcon crashes.
                    $params['toggle_after'] = 35;
                }
                $this->ModeSwitchRpc('Switch.Set', $params);
                if ($this->ModeSwitchReadOutput() !== $on) {
                    throw new RuntimeException('Shelly-Ausgang weicht vom Sollzustand ab');
                }
                return;
            } catch (Throwable $e) {
                $last = $e;
            }
        }
        throw new RuntimeException('Betriebsart-Relais nicht bestaetigt: ' . ($last?->getMessage() ?? 'unbekannt'));
    }

    private function ModeSwitchTryOff(): bool
    {
        try {
            $this->ModeSwitchSetAndVerify(false);
            return true;
        } catch (Throwable $e) {
            $this->SendDebug('ModeSwitchOff', $e->getMessage(), 0);
            return false;
        }
    }

    /**
     * Called from ApplyModeChange and from same-mode RequestMode while the
     * module's existing LCNC_ semaphore is held.
     * Physical switch is changed BEFORE changing the Symcon mode/LCN queue.
     */
    private function ModeSwitchSelect(int $mode): void
    {
        if (!$this->ModeSwitchIsShelly()) {
            return;
        }
        // A blocked state is not restored by a watchdog tick; it can only be
        // released by a new, explicit cooling request after full validation.
        $this->WriteAttributeBoolean('ModeSwitchBlocked', true);
        if ($mode === self::MODE_HEATING) {
            try {
                $this->ModeSwitchSetAndVerify(false);
                $this->ModeSwitchReport('HEIZEN – Shelly-Kontakt OFF bestaetigt');
            } catch (Throwable $e) {
                $this->ModeSwitchReport('FEHLER: HEIZEN – Shelly AUS nicht bestaetigt: ' . $e->getMessage());
                throw $e;
            }
            return;
        }
        try {
            $this->ModeSwitchVerifyConfig();
            $this->ModeSwitchSetAndVerify(true);
            $this->WriteAttributeBoolean('ModeSwitchBlocked', false);
            $this->ModeSwitchReport('KUEHLEN – Shelly-Kontakt ON bestaetigt; 35-s-Watchdog aktiv');
        } catch (Throwable $e) {
            $off = $this->ModeSwitchTryOff();
            $this->ModeSwitchReport('FEHLER: KUEHLEN gesperrt (' . $e->getMessage()
                . '); Rueckschaltung AUS ' . ($off ? 'bestaetigt' : 'NICHT BESTAETIGT'));
            throw $e;
        }
    }

    /** Every 10 s: renew cooling authorization or verify the output is OFF. */
    public function ModeSwitchTick(): void
    {
        if (!$this->ModeSwitchIsShelly()) {
            return;
        }
        if (!IPS_SemaphoreEnter('LCNC_' . $this->InstanceID, 200)) {
            return; // no renewal; local 35-s watchdog will release
        }
        try {
            $cooling = $this->GetMode() === self::MODE_COOLING;
            $blocked = $this->ReadAttributeBoolean('ModeSwitchBlocked');
            if ($cooling && !$blocked) {
                try {
                    $this->ModeSwitchVerifyConfig();
                    if ($this->CoolingCommissionReadinessErrors() !== []) {
                        throw new RuntimeException('Kuehlsicherheitsfreigabe fehlt');
                    }
                    $this->ModeSwitchSetAndVerify(true);
                    $this->ModeSwitchReport('KUEHLEN – Shelly-Kontakt ON bestaetigt; 35-s-Watchdog aktiv');
                } catch (Throwable $e) {
                    $this->WriteAttributeBoolean('ModeSwitchBlocked', true);
                    $off = $this->ModeSwitchTryOff();
                    $this->ModeSwitchReport('FEHLER: Kuehlfreigabe gesperrt (' . $e->getMessage()
                        . '); AUS ' . ($off ? 'bestaetigt' : 'NICHT BESTAETIGT'));
                    $this->SendDebug('ModeSwitch', $e->getMessage(), 0);
                }
                return;
            }

            // While HEIZEN or after a fault, never renew an ON lease.
            // OFF is enforced, but do not issue 6 redundant OFF commands/min.
            try {
                if ($this->ModeSwitchReadOutput()) {
                    $this->ModeSwitchSetAndVerify(false);
                }
                $this->ModeSwitchReport($blocked && $cooling
                    ? 'KUEHLEN GESPERRT – Shelly AUS bestaetigt; erneut KUEHLEN anfordern'
                    : 'HEIZEN – Shelly-Kontakt AUS bestaetigt');
            } catch (Throwable $e) {
                $this->WriteAttributeBoolean('ModeSwitchBlocked', true);
                $off = $this->ModeSwitchTryOff();
                $this->ModeSwitchReport('FEHLER: Shelly AUS '
                    . ($off ? 'bestaetigt' : 'NICHT BESTAETIGT')
                    . ' (' . $e->getMessage() . ')');
            }
        } finally {
            IPS_SemaphoreLeave('LCNC_' . $this->InstanceID);
        }
    }
}
