<?php
declare(strict_types=1);

/**
 * Diagnostic-only investigation of native IPS LCN S1/R1 regulator interfaces.
 * No bus commands, no timers, no object/property writes.
 */
trait LCNClimateNativeInterfaceAudit
{
    private function ClimateAuditObject(int $id, string $label): string
    {
        if ($id <= 0 || !IPS_InstanceExists($id)) {
            return $label . ': Instanz #' . $id . ' FEHLT';
        }
        try {
            $instance = IPS_GetInstance($id);
            $raw = IPS_GetConfiguration($id);
            $cfg = is_string($raw) && $raw !== ''
                ? json_decode($raw, true) : null;
            $cfg = is_array($cfg) ? $cfg : [];
            if ($cfg === [] && function_exists('IPS_GetProperty')) {
                // Display only existing native fields. The audit never
                // changes properties or guesses missing values.
                foreach (['Target', 'Segment', 'R1TargetEnabled',
                    'R2TargetEnabled', 'S1TargetEnabled', 'S2TargetEnabled',
                    'Interval', 'Unit', 'Channel'] as $name) {
                    try {
                        $value = IPS_GetProperty($id, $name);
                        if (is_scalar($value)) {
                            $cfg[$name] = $value;
                        }
                    } catch (Throwable) {
                        // Optional property does not exist on this instance.
                    }
                }
            }
            $fields = [
                'Target', 'Segment', 'R1TargetEnabled', 'R2TargetEnabled',
                'S1TargetEnabled', 'S2TargetEnabled', 'Interval',
                'Unit', 'Channel'
            ];
            $out = [$label . ' #' . $id
                . ' | IPS Status=' . (int) ($instance['InstanceStatus'] ?? -1)
                . ' | Verbindung #' . (int) ($instance['ConnectionID'] ?? 0)
                . ' | ModulGUID=' . (string) ($instance['ModuleInfo']['ModuleID'] ?? '(keine)')];
            foreach ($fields as $field) {
                if (array_key_exists($field, $cfg)) {
                    $v = $cfg[$field];
                    if (is_bool($v)) {
                        $v = $v ? 'TRUE' : 'FALSE';
                    }
                    if (is_scalar($v)) {
                        $out[] = '  ' . $field . '=' . (string) $v;
                    }
                }
            }
            return implode("\n", $out);
        } catch (Throwable $e) {
            return $label . ' #' . $id . ': Lesefehler ' . $e->getMessage();
        }
    }

    private function ClimateAuditBoolean(int $parent, string $ident): string
    {
        if ($parent <= 0 || !IPS_InstanceExists($parent)) {
            return '  ' . $ident . ': Value-Instanz fehlt.';
        }
        try {
            try {
                $id = (int) @IPS_GetObjectIDByIdent($ident, $parent);
            } catch (Throwable) {
                // IP-Symcon can throw instead of returning zero when an
                // optional R1TargetLock ident does not exist.
                return '  ' . $ident . ': NICHT VORHANDEN';
            }
            if ($id <= 0 || !IPS_VariableExists($id)) {
                return '  ' . $ident . ': NICHT VORHANDEN';
            }
            $info = IPS_GetVariable($id);
            if ((int) ($info['VariableType'] ?? -1) !== VARIABLETYPE_BOOLEAN) {
                return '  ' . $ident . ' #' . $id . ': KEIN BOOLEAN';
            }
            $when = (int) ($info['VariableUpdated'] ?? 0);
            return '  ' . $ident . ' #' . $id . ': '
                . ((bool) GetValue($id) ? 'TRUE (gesperrt)' : 'FALSE (frei)')
                . ', zuletzt ' . ($when > 0 ? date('d.m.Y H:i:s', $when) : 'nie')
                . ($when > 0 ? ' (' . max(0, time() - $when) . ' s alt)' : '');
        } catch (Throwable $e) {
            return '  ' . $ident . ': NICHT AUSLESBAR (' . $e->getMessage() . ')';
        }
    }

    public function AuditLCNNativeRegulatorInterfaces(): string
    {
        $lines = [
            'LCN KLIMA – NATIVER R1-/S1-SCHNITTSTELLEN-CHECK (NUR LESEN)',
            'LCN-PRO: Regler 1 und IPS: R1/S1 muessen fachlich zugeordnet werden.',
            'KEINE LCN-BEFEHLE, KEINE AENDERUNG VON REGELN ODER VARIABLEN.',
            'Symcon 9 – Status aller elf konfigurierten Raummodule'
        ];
        $rooms = $this->GetRuntimeRooms();
        foreach ($rooms as $room) {
            $sender = (int) ($room['SendModule'] ?? 0);
            $target = (int) ($room['TargetVariable'] ?? 0);
            $lines[] = '';
            $lines[] = '--- ' . (string) ($room['Name'] ?? '(unbenannt)') . ' ---';
            $lines[] = $this->ClimateAuditObject($sender, 'LCN-Sendemodul');
            $value = ($target > 0 && IPS_VariableExists($target)) ? (int) IPS_GetParent($target) : 0;
            $lines[] = $this->ClimateAuditObject($value, 'LCN-Value');
            $lines[] = '  S1Target #' . $target;
            $lines[] = $this->ClimateAuditBoolean($value, 'S1TargetLock');
            $lines[] = $this->ClimateAuditBoolean($value, 'R1TargetLock');
            if ($sender > 0 && IPS_InstanceExists($sender)) {
                $info = IPS_GetInstance($sender);
                $parent = (int) ($info['ConnectionID'] ?? 0);
                $lines[] = $this->ClimateAuditObject($parent, 'LCN-Anbindung');
            }
        }
        $lines[] = '';
        $lines[] = 'BEWERTUNG: Diese Diagnose belegt KEINE funktionierende Regler-Sperre.';
        $lines[] = 'Sperr-/Freigabe-API lieferte bei M11 bisher zweimal FALSE.';
        $lines[] = 'Im Fehlerzustand keine andere Reglernummer automatisch versuchen.';
        return implode("\n", $lines);
    }
}
