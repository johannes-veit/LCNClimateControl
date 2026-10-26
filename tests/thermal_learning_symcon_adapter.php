<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/LCNClimateControl/ThermalLearning.php';

/** Offline read-only IP-Symcon surface: no API or LCN hardware used. */
$GLOBALS['smart_fake_vars'] = [];
function IPS_VariableExists(int $id): bool {
    return isset($GLOBALS['smart_fake_vars'][$id]);
}
function IPS_GetVariable(int $id): array {
    if (!IPS_VariableExists($id)) { throw new RuntimeException('Unknown variable ' . $id); }
    return $GLOBALS['smart_fake_vars'][$id];
}
function GetValue(int $id) {
    if (!IPS_VariableExists($id)) { throw new RuntimeException('Unknown value ' . $id); }
    return $GLOBALS['smart_fake_vars'][$id]['Value'];
}
function smartAssert(bool $ok, string $description): void {
    if (!$ok) { throw new RuntimeException($description); }
}

final class ThermalTickHarness
{
    use LCNClimateThermalLearning;
    public array $rooms = [];
    public array $attrs = ['ThermalLearningState' => '{}'];
    public array $debug = [];
    public bool $enabled = true;
    public int $mode = 0;
    public bool $throwRoom = true;
    public function ReadPropertyBoolean(string $name): bool { return $this->enabled; }
    public function ReadPropertyInteger(string $name): int { return 0; }
    public function ReadAttributeString(string $name): string { return $this->attrs[$name] ?? ''; }
    public function WriteAttributeString(string $name, string $value): void {
        $this->attrs[$name] = $value;
    }
    public function GetMode(): int { return $this->mode; }
    public function GetRooms(): array { return $this->rooms; }
    public function ReadTarget(int $target): ?float {
        return $target === 1005 ? null : 21.5;
    }
    public function AutoCoolingReadOnlyDiscovery(array $room): array {
        if ($this->throwRoom && (int) $room['TargetVariable'] === 1003) {
            throw new RuntimeException('simulated discovery exception');
        }
        return ['RelayStatusVariable' => (int) $room['TargetVariable'] + 2000];
    }
    public function SendDebug(string $topic, string $message, int $format): void {
        $this->debug[] = $topic . ':' . $message;
    }
}

$now = time();
$h = new ThermalTickHarness();
for ($i=1; $i<=11; ++$i) {
    $target = 1000+$i;
    $tempId = 2000+$i;
    $relayId = 3000+$i;
    $h->rooms[] = [
        'Enabled' => true, 'Name' => 'Test room ' . $i,
        'TargetVariable' => $target, 'TemperatureVariable' => $tempId
    ];
    $GLOBALS['smart_fake_vars'][$tempId] = [
        'VariableUpdated' => $i===1 ? $now-3601 : $now,
        'VariableChanged' => $now-1200, 'Value' => 21.0
    ];
    $GLOBALS['smart_fake_vars'][$relayId] = [
        'VariableUpdated' => $i===2 ? $now-3601 : $now,
        'VariableChanged' => $now-3600, 'Value' => true
    ];
}
unset($GLOBALS['smart_fake_vars'][3004]); // missing relay status
$GLOBALS['smart_fake_vars'][3006]['Value'] = 'invalid'; // wrong type
$h->attrs['ThermalLearningState'] = json_encode([
    'schema' => 1, 'rooms' => [], 'created_at' => $now-86400,
    'unbounded_legacy_data' => str_repeat('X', 160000)
], JSON_THROW_ON_ERROR);
$h->ThermalLearningTick();
$state = json_decode($h->ReadAttributeString('ThermalLearningState'), true, 512, JSON_THROW_ON_ERROR);

smartAssert(($state['last_processed'] ?? -1) === 5, 'one bad room affected good rooms');
$want = [
    '1001' => 'TEMP_STALE',
    '1002' => 'RELAY_STALE',
    '1003' => 'READ_ERROR',
    '1004' => 'RELAY_MISSING',
    '1005' => 'TEMP_OR_TARGET_INVALID',
    '1006' => 'RELAY_INVALID'
];
smartAssert(($state['last_exclusions'] ?? []) === $want, 'wrong per-room exclusion reasons');
smartAssert(count($state['rooms']) === 5, 'successful room ingestion incomplete');
smartAssert(!isset($state['unbounded_legacy_data']), 'unbounded legacy root survived');
smartAssert(strlen($h->ReadAttributeString('ThermalLearningState')) < 131072,
    'tick exceeded state storage budget');
smartAssert(count($h->debug) === 1, 'read error must be isolated and diagnosed');
smartAssert(str_contains($h->ThermalLearningDiagnosticReport(),
    'Im letzten Lernlauf ausgeschlossen:'), 'diagnostic lacks exclusions');

$before = $h->ReadAttributeString('ThermalLearningState');
$h->enabled = false;
$h->ThermalLearningTick();
smartAssert($before === $h->ReadAttributeString('ThermalLearningState'),
    'disabled learning modified state');

$h->enabled = true;
// An old/foreign nested payload must also be pruned when learning pauses.
$legacy = json_decode($h->ReadAttributeString('ThermalLearningState'), true, 512, JSON_THROW_ON_ERROR);
$legacy['rooms']['1006']['old_nested_blob'] = str_repeat('Z', 180000);
$legacy['rooms']['99999'] = ['name'=>'orphan', 'rates'=>['unknown'=>str_repeat('x', 30000)]];
$h->attrs['ThermalLearningState'] = json_encode($legacy, JSON_THROW_ON_ERROR);
$h->mode = 1;
$h->ThermalLearningTick();
$paused = json_decode($h->ReadAttributeString('ThermalLearningState'), true, 512, JSON_THROW_ON_ERROR);
smartAssert(($paused['paused_reason'] ?? '') === 'Betriebsart nicht HEIZEN',
    'cooling mode must pause learning');
smartAssert(strlen($h->ReadAttributeString('ThermalLearningState')) < 131072,
    'pause branch skipped storage budget');
smartAssert(!isset($paused['rooms']['1006']['old_nested_blob']),
    'pause branch failed nested compaction');
smartAssert(!isset($paused['rooms']['99999']),
    'pause branch retained obsolete room');


smartAssert(LCNThermalLearningModel::sourceReason($now, $now, $now) === 'OK',
    'current values must be accepted');
smartAssert(LCNThermalLearningModel::sourceReason($now, $now-1801, $now) === 'TEMP_STALE',
    'old temperature was not detected');
smartAssert(LCNThermalLearningModel::sourceReason($now, $now, $now-1801) === 'RELAY_STALE',
    'old relay status was not detected');
smartAssert(LCNThermalLearningModel::sourceReason($now, $now+10, $now) === 'TEMP_STALE',
    'future temperature timestamp must be rejected');
smartAssert(LCNThermalLearningModel::sourceReason($now, $now, $now+1) === 'RELAY_STALE',
    'future relay timestamp must be rejected');
smartAssert(LCNThermalLearningModel::sourceReason($now, $now-1800, $now-1800) === 'OK',
    '30-minute update boundary must be accepted');

echo 'SMART-FBH read-only 11-room Symcon adapter integration passed' . PHP_EOL;
echo json_encode([
    'rooms' => 11, 'success' => $state['last_processed'],
    'exclusions' => $state['last_exclusions'],
    'state_bytes' => strlen($before),
    'independent_read_errors' => count($h->debug)
], JSON_THROW_ON_ERROR) . PHP_EOL;
