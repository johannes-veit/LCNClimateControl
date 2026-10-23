<?php
declare(strict_types=1);

/**
 * Single automatic, copy/paste ready diagnostic text for ChatGPT.
 * Strictly passive: ZERO LCN bus commands, no new IPS runtime objects
 * except the module's own one string variable "Senden an ChatGPT".
 */
trait LCNClimateChatGPTDiagnostic
{
    private function ChatGPTDiagnosticVersion(): string
    {
        // Versionsangabe immer aus der zentralen Bibliotheksdefinition lesen.
        // So kann der Diagnosebericht nach einem Release nicht veralten.
        try {
            $data = json_decode((string) file_get_contents(__DIR__ . '/../library.json'), true);
            if (is_array($data) && isset($data['version'], $data['build'])) {
                return (string) $data['version'] . ' Build ' . (int) $data['build'];
            }
        } catch (Throwable $e) {
            $this->SendDebug('DiagnosticVersion', $e->getMessage(), 0);
        }
        return 'unbekannt (library.json nicht lesbar)';
    }

    private function ChatGPTDiagnosticBoolState(int $id, bool $invert = false): string
    {
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return 'FEHLT';
        }
        try {
            $data = IPS_GetVariable($id);
            if ((int) ($data['VariableType'] ?? -1) !== VARIABLETYPE_BOOLEAN) {
                return 'KEIN BOOLEAN';
            }
            $updated = (int) ($data['VariableUpdated'] ?? 0);
            if ($updated <= 0) {
                return 'UNBESTAETIGT (keine Statusmeldung)';
            }
            $value = (bool) GetValue($id);
            $locked = $invert ? !$value : $value;
            $age = max(0, time() - $updated);
            return ($locked ? 'GESPERRT' : 'FREI')
                . ' (raw ' . ($value ? 'AN' : 'AUS') . ', '
                . $age . ' s alt' . ($age > 300 ? ', VERALTET' : '') . ')';
        } catch (Throwable $e) {
            return 'NICHT LESBAR: ' . $e->getMessage();
        }
    }

    private function ChatGPTDiagnosticRelayState(int $id): string
    {
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return 'FEHLT';
        }
        try {
            $data = IPS_GetVariable($id);
            if ((int) ($data['VariableType'] ?? -1) !== VARIABLETYPE_BOOLEAN) {
                return 'KEIN BOOLEAN';
            }
            $updated = (int) ($data['VariableUpdated'] ?? 0);
            if ($updated <= 0) {
                return 'UNBESTAETIGT';
            }
            $age = max(0, time() - $updated);
            $value = (bool) GetValue($id);
            return ($value ? 'EIN / NC-Ventil bestromt' : 'AUS / NC-Ventil stromlos')
                . ' (' . $age . ' s alt' . ($age > 300 ? ', VERALTET' : '') . ')';
        } catch (Throwable $e) {
            return 'NICHT LESBAR: ' . $e->getMessage();
        }
    }

    private function ChatGPTDiagnosticTemperature(?float $value): string
    {
        if ($value === null || !is_finite($value)) {
            return 'unbekannt';
        }
        return number_format($value, 1, ',', '') . ' °C';
    }

    /** Read-only snapshot, never actively queries or changes the LCN bus. */
    public function BuildChatGPTDiagnosticReport(): string
    {
        $mode = $this->GetMode();
        $rooms = $this->GetRuntimeRooms();
        $locks = $this->ReadJsonAttribute('DirectCoolingLocks');
        $fallbacks = $this->DirectCoolingFallbacks();
        $pendingCooling = $this->GetPendingCoolingRequests();
        $currentJob = $this->GetCurrentJob();
        $queue = $this->GetQueue();
        $isBusy = $this->IsBusy();
        $m11 = $this->M11ProbeSession();
        // Evidence is tied to the proven M11 hardware and the exact S1
        // action. A success on M11 must never certify the other ten rooms.
        $m11ActionPassed = (string) ($m11['phase'] ?? '') === 'passed'
            && (string) ($m11['commandPath'] ?? '') === 'RequestAction'
            && (int) ($m11['sender'] ?? 0) === 37617
            && (int) ($m11['target'] ?? 0) === 34107
            && (int) ($m11['lockID'] ?? 0) === 46746
            && (bool) ($m11['lockConfirmed'] ?? false)
            && (bool) ($m11['lockCommandAccepted'] ?? false)
            && (bool) ($m11['releaseCommandAccepted'] ?? false);
        $safeVar = (int) $this->ReadPropertyInteger('CoolingSafetyVariable');
        $safeLabel = 'NICHT KONFIGURIERT – KUEHLBETRIEB GESPERRT (Pflicht)';
        if ($safeVar > 0) {
            $safeLabel = 'Boolean #' . $safeVar . ' = ';
            try {
                $safeLabel .= IPS_VariableExists($safeVar)
                    ? ((bool) GetValue($safeVar) ? 'TRUE' : 'FALSE') : 'FEHLT';
            } catch (Throwable) {
                $safeLabel .= 'nicht lesbar';
            }
        }

        $lines = [
            'LCNClimateControl – SENDEN AN CHATGPT',
            'Modulstand: ' . $this->ChatGPTDiagnosticVersion(),
            'Erstellt: ' . date('d.m.Y H:i:s') . ' (Europe/Berlin)',
            'Symcon-Modulinstanz: #' . $this->InstanceID,
            'Betriebsart: ' . ($mode === self::MODE_HEATING ? 'HEIZEN' : 'KUEHLEN'),
            'DirectCoolingEnabled: ' . ($this->UsesDirectCooling() ? 'JA' : 'NEIN'),
            'M11-Sperrtest: ' . (string) ($m11['phase'] ?? 'nicht gestartet')
                . ' | ' . (string) ($m11['message'] ?? 'Keine Testfahrt.'),
            'Auftrag laeuft: ' . ($isBusy ? 'JA' : 'NEIN'),
            'Queue: ' . count($queue) . ' Eintraege'
                . (is_array($currentJob)
                    ? ', aktuell ' . (string) ($currentJob['Name'] ?? 'Raum')
                      . ' / ' . (string) ($currentJob['Type'] ?? 'Typ?')
                      . ' / ' . (string) ($currentJob['Phase'] ?? 'Phase?')
                    : ', kein aktueller Auftrag'),
            'Externe Software-Kuehlfreigabe: ' . $safeLabel,
            'Ventile: NC, stromlos ZU, Relais EIN = Ventil bestromt (bestaetigt)',
            'S1TargetLock TRUE = GESPERRT, FALSE = FREIGEGEBEN.',
            'WICHTIG: "Regler-Sperrung erlaubt" in LCN-PRO ist nicht passiv auslesbar.',
            'WICHTIG: Dies ist ein passiver Schnappschuss. Es wurden KEINE LCN-Befehle gesendet.',
            'Physischer Taupunkt-/Kondensationsschutz muss vor Kuehlwasserbetrieb vorhanden sein.',
            '--- RAEUME (' . count($rooms) . ') ---'
        ];

        $ready = 0;
        $legacy = 0;
        $blocked = 0;
        $lockedCount = 0;
        $provenRooms = 0;
        $regulatorVerified = 0;
        $relayVerified = 0;
        foreach ($rooms as $room) {
            $name = (string) ($room['Name'] ?? '(Raum ohne Name)');
            $target = (int) ($room['TargetVariable'] ?? 0);
            $sender = (int) ($room['SendModule'] ?? 0);
            try {
                $mapping = $this->DirectCoolingMapping($target);
                $eligibility = $this->DirectCoolingEligibility($room);
                $fallback = $fallbacks[(string) $target] ?? null;
                $fallbackReason = is_array($fallback) ? (string) ($fallback['reason'] ?? '') : '';
                $fallbackKind = is_array($fallback) ? (string) ($fallback['kind'] ?? '') : '';
                $persisted = (string) ($locks[(string) $target] ?? '');
                $locked = $this->AutoCoolingLockFeedbackForRoom($room);

                $roomS1Passed = $this->CoolingCommissionVerified($room);
                $roomRelayPassed = $this->CoolingCommissionRelayRoomVerified($room);
                if ($roomS1Passed) { $regulatorVerified++; }
                if ($roomRelayPassed) { $relayVerified++; }
                $directReady = $this->UsesDirectCooling() && $mapping !== null
                    && $this->DirectCoolingLockActionID($room, $mapping) > 0;
                $isBlocked = $persisted === 'uncertain' || $fallbackKind === 'blocked'
                    || str_contains($eligibility, 'bereits gesperrt');
                $m11VerifiedHere = $m11ActionPassed && $sender === 37617 && $target === 34107
                    && is_array($locked)
                    && (int) ($locked['id'] ?? 0) === 46746
                    && (bool) ($locked['lockedWhenTrue'] ?? false)
                    && !(bool) GetValue(46746);
                if ($m11VerifiedHere) {
                    $provenRooms++;
                }
                $route = $isBlocked ? 'GESTOPPT/PRUEFEN'
                    : ($persisted !== '' ? 'DIREKT / REGLER ' . strtoupper($persisted)
                    : ($directReady ? ($m11VerifiedHere
                        ? 'DIREKT VORBEREITET (M11-S1-Sperr-/Freigabetest BESTANDEN)'
                        : 'DIREKT VORBEREITET (S1-Sperr-/Freigabetest noch ausstehend)')
                    : 'DIREKT NICHT BEREIT – ZUORDNUNG PRUEFEN'));
                if ($isBlocked) {
                    $blocked++;
                } elseif ($directReady || $persisted !== '') {
                    $ready++;
                } else {
                    $legacy++;
                }

                $lines[] = '';
                $lines[] = $name . ' | Weg: ' . $route;
                $lines[] = '  ABNAHME S1: ' . ($roomS1Passed ? 'BESTANDEN' : 'OFFEN')
                    . ' | Relais EIN/AUS: ' . ($roomRelayPassed ? 'BESTANDEN' : 'OFFEN');
                $lines[] = '  LCN Sender #' . $sender . ', S1Target #' . $target
                    . ', IST ' . $this->ChatGPTDiagnosticTemperature(
                        $this->ReadFloatVariable((int) ($room['TemperatureVariable'] ?? 0)))
                    . ', Soll ' . $this->ChatGPTDiagnosticTemperature($this->ReadTarget($target))
                    . ', Kuehlzustand bestaetigt '
                    . ($this->GetStoredCoolingState($target) ? 'AN' : 'AUS');
                if (array_key_exists((string) $target, $pendingCooling)) {
                    $lines[] = '  NEUER SCHALTWUNSCH VORGEMERKT: '
                        . ((bool) $pendingCooling[(string) $target]
                            ? 'KUEHLEN' : 'NICHT KUEHLEN')
                        . ' (wartet auf Abschluss des vorherigen Raumauftrags).';
                }

                if ($mapping !== null) {
                    $lines[] = '  Relais-Modul #' . (int) ($mapping['RelayModule'] ?? 0)
                        . ', Unit #' . (int) ($mapping['RelayUnit'] ?? 0)
                        . '/R' . (int) ($mapping['RelayChannel'] ?? 0)
                        . ', Status #' . (int) ($mapping['RelayStatusVariable'] ?? 0)
                        . ': ' . $this->ChatGPTDiagnosticRelayState(
                            (int) ($mapping['RelayStatusVariable'] ?? 0));
                } else {
                    $lines[] = '  Relais: nicht eindeutig zugeordnet. '
                        . $this->AutoCoolingDiscoveryReason($room);
                }

                if ($locked !== null) {
                    $lockId = (int) $locked['id'];
                    $isLocked = (bool) ($locked['lockedWhenTrue'] ?? false)
                        ? ((bool) GetValue($lockId)) : !((bool) GetValue($lockId));
                    if ($isLocked) {
                        $lockedCount++;
                    }
                    $lines[] = '  Reglersperre ' . (string) $locked['type']
                        . ' #' . $lockId . ': '
                        . $this->ChatGPTDiagnosticBoolState(
                            $lockId, !((bool) ($locked['lockedWhenTrue'] ?? false)));
                } else {
                    $lines[] = '  Reglersperre: keine eindeutige Statusvariable';
                }
                if ($persisted !== '') {
                    $lines[] = '  Interne Sperr-Vormerkung: ' . $persisted;
                }
                if ($eligibility !== '' && !$isBlocked) {
                    $lines[] = '  Direkter Weg derzeit nicht nutzbar: ' . $eligibility;
                }
                if ($fallbackReason !== '') {
                    $lines[] = '  Rueckfall/Blockade: ' . $fallbackReason;
                }
                $roomError = $this->GetRoomError($target);
                if ($roomError !== '') {
                    $lines[] = '  FEHLER: ' . $roomError;
                }
            } catch (Throwable $e) {
                $blocked++;
                $lines[] = $name . ': DIAGNOSEFEHLER: ' . $e->getMessage();
            }
        }
        $lines[] = '';
        $lines[] = '--- ZUSAMMENFASSUNG ---';
        $lines[] = 'Direkt technisch zugeordnet: ' . $ready
            . '; S1-Sperr-/Freigabetest bestanden: ' . $provenRooms
            . '; noch nicht erfolgreich getestet: ' . max(0, count($rooms) - $provenRooms)
            . '; alte Steuerung: ' . $legacy
            . '; blockiert/Pruefung: ' . $blocked
            . '; zuletzt als gesperrt gemeldet: ' . $lockedCount . '.';
        $lines[] = 'WICHTIG: Technisch vorbereitet bedeutet NICHT betriebsbereit. '
            . 'Bis zur Gesamtfreigabe keine Kuehlschaltung und KEIN A7/A8-Rueckfall.';
        $lastError = trim((string) $this->ReadAttributeString('LastError'));
        if ($lastError !== '') {
            $lines[] = 'Modulfehler: ' . $lastError;
        }
        $lines[] = 'S1-Test (11 erforderlich): ' . $regulatorVerified
            . '/11; Einzelrelais-Test: ' . $relayVerified . '/11.';
        $coolingGate = $this->CoolingCommissionReadinessErrors();
        $lines[] = 'KUEHLEN: ' . ($coolingGate === []
            ? 'SOFTWARE-FREIGABEBEREIT (physische Abnahme vorausgesetzt)'
            : 'GESPERRT – ' . implode('; ', $coolingGate));
        $lines[] = 'LCN-PRO Sperrerlaubnis und echte mechanische Ventilstellung: durch diese Diagnose NICHT bestaetigt.';
        $lines[] = '--- ENDE – kompletten Text an ChatGPT senden ---';
        return implode("\n", $lines);
    }

    /** Invoked by its own 60-second timer; no hardware commands. */
    public function UpdateChatGPTDiagnostic(): void
    {
        try {
            $content = $this->BuildChatGPTDiagnosticReport();
            // IPSModuleStrict-Sicht: Die eigenen Variablen sind ReadOnly.
            // Globale SetValueString/SetValue sind hier unzulaessig;
            // ausschliesslich der interne Ident-basierte Modulschreiber
            // darf die Variable aktualisieren.
            $this->SetValue('ChatGPTDiagnostic', $content);
        } catch (Throwable $e) {
            $this->SendDebug('ChatGPTDiagnostic', $e->getMessage(), 0);
            // Fehler niemals nur im Symcon-Debug verstecken: Die
            // kopierbare Variable soll auch bei einer Diagnose-Ausnahme
            // eine brauchbare Rueckmeldung enthalten.
            try {
                $this->SetValue('ChatGPTDiagnostic',
                    'LCNClimateControl – SENDEN AN CHATGPT' . "\n"
                    . 'DIAGNOSEFEHLER: ' . $e->getMessage() . "\n"
                    . 'Keine LCN-Befehle durch diese Diagnose gesendet.'
                );
            } catch (Throwable $writeError) {
                $this->SendDebug('ChatGPTDiagnostic-Write', $writeError->getMessage(), 0);
            }
        }
    }
}
