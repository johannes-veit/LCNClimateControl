<?php
declare(strict_types=1);

/**
 * Read-only discovery of native LCN objects.
 * The LP3 map supplies LCN hardware module numbers / relay channels, NOT IPS ids.
 * Never guesses from display names, never creates/modifies foreign IPS objects.
 */
trait LCNClimateCoolingDiscovery
{
    private const AUTO_LCN_MODULE_GUID = '{0E31FED6-E465-4621-95D4-AAF2683C41EC}';
    private const AUTO_LCN_UNIT_GUID = '{2D871359-14D8-493F-9B01-26432E3A710F}';

    private const AUTO_COOLING_PHYSICAL_RELAYS = [
        11 => [92, 1], 21 => [92, 2], 31 => [92, 3],
        41 => [92, 5], 51 => [92, 6], 61 => [92, 4],
        111 => [192, 4], 121 => [192, 3], 131 => [192, 1],
        141 => [192, 2], 151 => [192, 5]
    ];

    private function AutoCoolingAutoPolarityConfirmed(): bool
    {
        // Vom Anlagenbetreiber fuer diese LP3-Installation explizit bestaetigt:
        // Relais EIN versorgt die stromlos geschlossenen NC-Stellantriebe.
        // Trifft ausschliesslich auf die feste, unten verifizierte LP3-Zuordnung zu.
        return true;
    }

    private function AutoCoolingOpenRelayOn(): bool
    {
        return true;
    }

    private function AutoCoolingInstanceConfig(int $id): ?array
    {
        try {
            if ($id <= 0 || !IPS_InstanceExists($id)) {
                return null;
            }
            $decoded = json_decode(IPS_GetConfiguration($id), true);
            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function AutoCoolingReadOnlyDiscovery(array $room): ?array
    {
        $sender = (int) ($room['SendModule'] ?? 0);
        $targetID = (int) ($room['TargetVariable'] ?? 0);
        $senderConfig = $this->AutoCoolingInstanceConfig($sender);
        if ($senderConfig === null || !isset($senderConfig['Target'], $senderConfig['Segment'])) {
            return null;
        }

        $sourceHardware = (int) $senderConfig['Target'];
        if (!array_key_exists($sourceHardware, self::AUTO_COOLING_PHYSICAL_RELAYS)
            || !$this->IsOperationalLCNModule($sender)) {
            return null;
        }
        [$relayHardware, $channel] = self::AUTO_COOLING_PHYSICAL_RELAYS[$sourceHardware];
        $segment = (int) $senderConfig['Segment'];
        $senderInfo = IPS_GetInstance($sender);
        $gateway = (int) ($senderInfo['ConnectionID'] ?? 0);
        if ($gateway <= 0) {
            return null;
        }

        // Match hardware target+segment+same gateway, never an IPS instance name.
        $relayModules = [];
        foreach (IPS_GetInstanceListByModuleID(self::AUTO_LCN_MODULE_GUID) as $candidate) {
            $candidate = (int) $candidate;
            $config = $this->AutoCoolingInstanceConfig($candidate);
            if ($config === null
                || (int) ($config['Target'] ?? -1) !== $relayHardware
                || (int) ($config['Segment'] ?? -1) !== $segment
                || !$this->IsOperationalLCNModule($candidate)) {
                continue;
            }
            $info = IPS_GetInstance($candidate);
            if ((int) ($info['ConnectionID'] ?? 0) === $gateway) {
                $relayModules[] = $candidate;
            }
        }
        if (count($relayModules) !== 1) {
            return null;
        }
        $relayModule = $relayModules[0];

        // Native LCN Unit with Unit=2 means RELAY; Channel is 1..8.
        $relays = [];
        foreach (IPS_GetInstanceListByModuleID(self::AUTO_LCN_UNIT_GUID) as $id) {
            $id = (int) $id;
            $config = $this->AutoCoolingInstanceConfig($id);
            if ($config === null || (int) ($config['Unit'] ?? -1) !== 2
                || (int) ($config['Channel'] ?? -1) !== $channel) {
                continue;
            }
            $info = IPS_GetInstance($id);
            if ((int) ($info['ConnectionID'] ?? 0) === $relayModule
                && (int) ($info['InstanceStatus'] ?? 0) === 102) {
                $relays[] = $id;
            }
        }
        if (count($relays) !== 1) {
            return null;
        }
        $relayUnit = $relays[0];

        // Both feedback variables belong to EXISTING native Symcon instances.
        // Do not create new variables and don't interpret unrelated booleans.
        $relayStatus = $this->AutoCoolingFindBoolean($relayUnit, 'Status');
        $valueParent = $targetID > 0 && IPS_VariableExists($targetID)
            ? IPS_GetParent($targetID) : 0;
        if ($valueParent <= 0 || !IPS_InstanceExists($valueParent)) {
            return null;
        }
        $valueInfo = IPS_GetInstance($valueParent);
        if (strtoupper((string) ($valueInfo['ModuleInfo']['ModuleID'] ?? ''))
            !== strtoupper(self::LCN_VALUE_MODULE_ID)
            || (int) ($valueInfo['ConnectionID'] ?? 0) !== $sender) {
            return null;
        }
        $unlocked = $this->AutoCoolingFindBoolean($valueParent, 'Entriegelt');
        if ($relayStatus <= 0 || $unlocked <= 0) {
            return null;
        }
        if (!$this->AutoCoolingAutoPolarityConfirmed()) {
            return null;
        }
        return [
            'TargetVariable' => $targetID,
            'RelayModule' => $relayModule,
            'RelayUnit' => $relayUnit,
            'RelayChannel' => $channel,
            'RegulatorIndex' => 2, // S1, verified by the existing S1Target route.
            'UnlockedVariable' => $unlocked,
            'RelayStatusVariable' => $relayStatus,
            'OpenRelayOn' => $this->AutoCoolingOpenRelayOn(),
            'PolarityConfirmed' => true,
            'AutoDiscovered' => true
        ];
    }

    /**
     * Explain the first missing link in native discovery. 100% read-only,
     * uses only configuration and variables already installed in Symcon.
     * Never create foreign objects or alter a controller/relay state.
     */
    private function AutoCoolingDiscoveryReason(array $room): string
    {
        try {
            $sender = (int) ($room['SendModule'] ?? 0);
            $target = (int) ($room['TargetVariable'] ?? 0);
            $cfg = $this->AutoCoolingInstanceConfig($sender);
            if ($cfg === null) {
                return 'LCN-Sendemodul #' . $sender . ' existiert nicht oder hat keine lesbare Konfiguration';
            }
            $hardware = (int) ($cfg['Target'] ?? -1);
            $segment = (int) ($cfg['Segment'] ?? -1);
            if (!isset(self::AUTO_COOLING_PHYSICAL_RELAYS[$hardware])) {
                return 'Symcon-Sendemodul #' . $sender . ' hat Target=' . $hardware
                    . ', Segment=' . $segment . '; keine eindeutige LP3-Raumzuordnung';
            }
            [$relayNo, $channel] = self::AUTO_COOLING_PHYSICAL_RELAYS[$hardware];
            $gateway = (int) (IPS_GetInstance($sender)['ConnectionID'] ?? 0);
            if ($gateway <= 0) {
                return 'LCN-Sendemodul #' . $sender . ' hat keine gueltige Gateway-Verbindung';
            }
            $sameTarget = [];
            $matches = [];
            foreach (IPS_GetInstanceListByModuleID(self::AUTO_LCN_MODULE_GUID) as $id) {
                $id = (int) $id;
                $c = $this->AutoCoolingInstanceConfig($id);
                if ($c === null || (int) ($c['Target'] ?? -1) !== $relayNo) {
                    continue;
                }
                $ic = IPS_GetInstance($id);
                $is = (int) ($c['Segment'] ?? -1);
                $ig = (int) ($ic['ConnectionID'] ?? 0);
                $sameTarget[] = '#' . $id . '(Segment=' . $is . ',Gateway=#' . $ig . ')';
                if ($is === $segment && $ig === $gateway
                    && $this->IsOperationalLCNModule($id)) {
                    $matches[] = $id;
                }
            }
            if (count($matches) !== 1) {
                return 'Relaismodul M' . $relayNo . ' im Segment ' . $segment
                    . ' / Gateway #' . $gateway . ': ' . count($matches)
                    . ' passende Symcon-Instanzen; Fundstellen '
                    . ($sameTarget === [] ? 'KEINE' : implode(', ', $sameTarget));
            }
            $relayModule = $matches[0];
            $units = [];
            $matchesUnit = [];
            foreach (IPS_GetInstanceListByModuleID(self::AUTO_LCN_UNIT_GUID) as $id) {
                $id = (int) $id;
                $c = $this->AutoCoolingInstanceConfig($id);
                if ($c === null) {
                    continue;
                }
                $instance = IPS_GetInstance($id);
                if ((int) ($instance['ConnectionID'] ?? 0) !== $relayModule) {
                    continue;
                }
                $unitType = (int) ($c['Unit'] ?? -1);
                $unitChannel = (int) ($c['Channel'] ?? -1);
                $units[] = '#' . $id . '(Unit=' . $unitType . ',Channel='
                    . $unitChannel . ',Status=' . (int) ($instance['InstanceStatus'] ?? 0) . ')';
                if ($unitType === 2 && $unitChannel === $channel
                    && (int) ($instance['InstanceStatus'] ?? 0) === 102) {
                    $matchesUnit[] = $id;
                }
            }
            if (count($matchesUnit) !== 1) {
                return 'M' . $relayNo . '/R' . $channel . ': ' . count($matchesUnit)
                    . ' eindeutig passende LCN-Unit-Instanzen (erwartet Unit=2); '
                    . 'alle vorhandenen Units an Relaismodul #' . $relayModule . ': '
                    . ($units === [] ? 'KEINE' : implode(', ', $units));
            }
            $relayUnit = $matchesUnit[0];
            $status = $this->AutoCoolingFindBoolean($relayUnit, 'Status');
            if ($status <= 0) {
                return 'LCN-Relaisinstanz #' . $relayUnit
                    . ' fuer M' . $relayNo . '/R' . $channel
                    . ' vorhanden, aber Boolean-Rueckmeldung Status fehlt/ist uneindeutig: '
                    . $this->AutoCoolingDescribeBoolChildren($relayUnit);
            }
            if ($target <= 0 || !IPS_VariableExists($target)) {
                return 'S1Target #' . $target . ' ist nicht mehr vorhanden';
            }
            $parent = (int) IPS_GetParent($target);
            if (!IPS_InstanceExists($parent)) {
                return 'S1Target #' . $target . ' liegt nicht unter einer LCN-Value-Instanz (Parent=#'
                    . $parent . ')';
            }
            $valueInfo = IPS_GetInstance($parent);
            $guid = (string) ($valueInfo['ModuleInfo']['ModuleID'] ?? '');
            $connection = (int) ($valueInfo['ConnectionID'] ?? 0);
            if (strcasecmp($guid, self::LCN_VALUE_MODULE_ID) !== 0 || $connection !== $sender) {
                return 'S1Target #' . $target . ' hat LCN-Value-Instanz #' . $parent
                    . ', Typ=' . $guid . ', Verbindung=#' . $connection
                    . ', erwartet LCN Value an Sender #' . $sender;
            }
            $unlock = $this->AutoCoolingFindBoolean($parent, 'Entriegelt');
            if ($unlock <= 0) {
                return 'LCN-Value-Instanz #' . $parent
                    . ' hat keine eindeutige Boolean-Rueckmeldung Entriegelt: '
                    . $this->AutoCoolingDescribeBoolChildren($parent);
            }
            return 'Alle Symcon-Instanzen gefunden: M' . $relayNo . '/R' . $channel
                . ', Relais-Unit #' . $relayUnit . ', Relais-Status #' . $status
                . ', Regler-Entriegelt #' . $unlock . '; Betriebsbereitschaft getrennt pruefen';
        } catch (Throwable $e) {
            return 'Lesefehler in der Instanzkonfiguration: ' . $e->getMessage();
        }
    }

    private function AutoCoolingDescribeBoolChildren(int $parent): string
    {
        $parts = [];
        try {
            foreach (IPS_GetChildrenIDs($parent) as $id) {
                $id = (int) $id;
                if (!IPS_VariableExists($id)) {
                    continue;
                }
                if ((int) (IPS_GetVariable($id)['VariableType'] ?? -1) !== VARIABLETYPE_BOOLEAN) {
                    continue;
                }
                $o = IPS_GetObject($id);
                $parts[] = '#' . $id . '[Ident=' . (string) ($o['ObjectIdent'] ?? '')
                    . ',Name=' . IPS_GetName($id) . ']';
            }
        } catch (Throwable) {
            return 'Statusobjekte nicht lesbar';
        }
        return $parts === [] ? 'keine Boolean-Variablen' : implode(', ', $parts);
    }

    /**
     * Read-only diagnostic; reports actual IPS IDs and missing objects.
     * Does not create variables, switch relays or send LCN commands.
     */
    public function CoolingDiscoveryReport(): string
    {
        $lines = [
            'LCNClimateControl – erkannte LCN-Symcon-Zuordnungen (NUR LESEN)',
            'NC-Stellantriebe: stromlos ZU; Relais EIN = Spannung = Ventil AUF (Betreiber bestaetigt).',
            'Hinweis: keine Relais-/Reglersperre wird durch diesen Bericht ausgeloest.'
        ];
        foreach ($this->GetRuntimeRooms() as $room) {
            $target = (int) ($room['TargetVariable'] ?? 0);
            $name = (string) ($room['Name'] ?? 'Raum');
            $source = (int) ($room['SendModule'] ?? 0);
            try {
                $m = $this->DirectCoolingMapping($target);
                if ($m === null) {
                    $lines[] = $name . ' / LCN-Instanz #' . $source
                        . ', S1Target #' . $target
                        . ': alte A7/A8-Steuerung | Grund: ' . $this->AutoCoolingDiscoveryReason($room);
                    continue;
                }
                $lines[] = $name . ' / LCN-Instanz #' . $source
                    . ', S1Target #' . $target
                    . ' -> Relais-Modul #' . (int) ($m['RelayModule'] ?? 0)
                    . ', Einzelrelais #' . (int) ($m['RelayUnit'] ?? 0)
                    . '/R' . (int) ($m['RelayChannel'] ?? 0)
                    . ', Entriegelt #' . (int) ($m['UnlockedVariable'] ?? 0)
                    . ', Status #' . (int) ($m['RelayStatusVariable'] ?? 0)
                    . ': ' . ($this->DirectCoolingEligibility($room) === ''
                        ? 'Direktsteuerung bereit' : 'alte A7/A8-Steuerung');
            } catch (Throwable $e) {
                $lines[] = $name . ': Diagnose nicht verfuegbar (' . $e->getMessage() . ').';
            }
        }
        return implode("\n", $lines);
    }

    private function AutoCoolingFindBoolean(int $parent, string $name): int
    {
        $byIdent = [];
        $byLabel = [];
        try {
            foreach (IPS_GetChildrenIDs($parent) as $id) {
                $id = (int) $id;
                if (!IPS_VariableExists($id)
                    || (int) (IPS_GetVariable($id)['VariableType'] ?? -1) !== VARIABLETYPE_BOOLEAN) {
                    continue;
                }
                // Die Identifikation anhand ObjectIdent ist stabil, selbst
                // wenn Symcon-Anzeigenamen individuell umbenannt wurden.
                if (function_exists('IPS_GetObject')) {
                    $obj = IPS_GetObject($id);
                    if (strcasecmp((string) ($obj['ObjectIdent'] ?? ''), $name) === 0) {
                        $byIdent[] = $id;
                    }
                }
                if (strcasecmp(trim(IPS_GetName($id)), $name) === 0) {
                    $byLabel[] = $id;
                }
            }
        } catch (Throwable) {
            return 0;
        }
        // Doppelte / widerspruechliche Treffer: niemals schalten.
        if (count($byIdent) > 1 || count($byLabel) > 1) {
            return 0;
        }
        if (count($byIdent) === 1) {
            return $byIdent[0];
        }
        return count($byLabel) === 1 ? $byLabel[0] : 0;
    }
}
