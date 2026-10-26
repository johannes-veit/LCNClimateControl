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
    public int $InstanceID = 17597;
    public bool $enabled = true;
    public bool $preflight = false;
    public int $mode = 0;
    public bool $throwRoom = true;
    public function ReadPropertyBoolean(string $name): bool {
        return $name === 'ThermalLearningEnabled' ? $this->enabled
            : ($name === 'ThermalSourcePreflightEnabled' ? $this->preflight : false);
    }
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
smartAssert(count($state['rooms']) === 11, 'source audit must track all configured rooms');
$readRooms = array_filter($state['rooms'], static function (array $r) use ($now): bool {
    return (int) ($r['last']['time'] ?? 0) === $now;
});
smartAssert(count($readRooms) === 0, 'thermal learning must wait for source qualification');
smartAssert(($state['last_eligible'] ?? -1) === 0,
    'fresh source IDs must not immediately start thermal learning');
smartAssert(count(array_filter($state['rooms'], static function (array $r): bool {
    return (int) ($r['source_audit']['checks'] ?? 0) === 1;
})) === 11, 'source audit must store one bounded status per configured room');
smartAssert(!isset($state['unbounded_legacy_data']), 'unbounded legacy root survived');
smartAssert(strlen($h->ReadAttributeString('ThermalLearningState')) < 131072,
    'tick exceeded state storage budget');
smartAssert(count($h->debug) === 1, 'read error must be isolated and diagnosed');
smartAssert(str_contains($h->ThermalLearningDiagnosticReport(),
    'Im letzten Lernlauf ausgeschlossen:'), 'diagnostic lacks exclusions');
smartAssert(str_contains($h->ThermalLearningDiagnosticReport(),
    'Datenquellen jetzt verwertbar: 5'), 'diagnostic overreports invalid relay as ready');

// Simulated sixth completed source check: previously audited, qualified
// room may now begin a read-only baseline with a NEW measurement window.
$qualified = json_decode($h->ReadAttributeString('ThermalLearningState'),
    true, 512, JSON_THROW_ON_ERROR);
$qualified['rooms']['1008']['source_audit'] = [
    'checks'=>6, 'recent_ok'=>1.0, 'ok_streak'=>6, 'bad_streak'=>0,
    'last_reason'=>'OK', 'last_checked_at'=>$now-1800
];
$h->attrs['ThermalLearningState'] = json_encode($qualified, JSON_THROW_ON_ERROR);
$h->ThermalLearningTick();
$started = json_decode($h->ReadAttributeString('ThermalLearningState'),
    true, 512, JSON_THROW_ON_ERROR);
smartAssert(($started['last_eligible'] ?? 0) === 1,
    'qualified source should become eligible for learning');
smartAssert(isset($started['rooms']['1008']['last']),
    'qualified source should begin a fresh thermal observation');

// A sensor remap on the same S1 key must invalidate learned thermal
// rates rather than silently attaching old-room behaviour to the new source.
$mappingState = json_decode($h->ReadAttributeString('ThermalLearningState'),
    true, 512, JSON_THROW_ON_ERROR);
$mappingState['rooms']['1007']['rates']['heating']['all'] = [
    'n' => 99, 'mean' => 0.25, 'variance' => 0.01,
    'quality_n' => 99, 'long_mean' => 0.22
];
$h->attrs['ThermalLearningState'] = json_encode($mappingState, JSON_THROW_ON_ERROR);
$h->rooms[6]['TemperatureVariable'] = 9007;
$GLOBALS['smart_fake_vars'][9007] = [
    'VariableUpdated' => $now, 'VariableChanged' => $now-1200,
    'Value' => 21.0
];
$h->ThermalLearningTick();
$remapped = json_decode($h->ReadAttributeString('ThermalLearningState'),
    true, 512, JSON_THROW_ON_ERROR);
smartAssert(($remapped['rooms']['1007']['source_temp_id'] ?? 0) === 9007,
    'new temperature source id not recorded');
smartAssert(!isset($remapped['rooms']['1007']['rates']),
    'historical rates wrongly transferred to remapped sensor');

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

// Invalid persisted JSON/schema must not destroy accumulated multi-year data.
$h->mode = 0;
$badState = '{"schema":1,"rooms":BROKEN';
$h->attrs['ThermalLearningState'] = $badState;
$h->ThermalLearningTick();
smartAssert($h->ReadAttributeString('ThermalLearningState') === $badState,
    'corrupted learning state must remain untouched');
smartAssert(str_contains($h->ThermalLearningDiagnosticReport(),
    'KRITISCHE LERNDATEN-WARNUNG'), 'corrupted state must be visible in diagnostic');
smartAssert(str_contains($h->ThermalLearningReport(),
    'NICHT UEBERSCHRIEBEN'), 'short report must expose protected corrupt state');
$futureSchema = '{"schema":2,"rooms":{"1001":{"name":"legacy"}}}';
$h->attrs['ThermalLearningState'] = $futureSchema;
$h->ThermalLearningTick();
smartAssert($h->ReadAttributeString('ThermalLearningState') === $futureSchema,
    'unknown learning schema must not overwrite history');
$h->attrs['ThermalLearningState'] = '{}';
$h->ThermalLearningTick();
$fresh = json_decode($h->ReadAttributeString('ThermalLearningState'), true,
    512, JSON_THROW_ON_ERROR);
smartAssert(($fresh['schema'] ?? 0) === 1 &&
    ($fresh['last_processed'] ?? -1) === 5 &&
    ($fresh['last_eligible'] ?? -1) === 0,
    'new installation must still initialize correctly');

// Source-only preflight: may collect only bounded source counters, never
// start thermal observations, even if old heating values are present.
$preflightSeed = $fresh;
$preflightSeed['rooms']['1008']['rates']['heating']['all'] = [
    'n'=>20,'mean'=>0.25,'variance'=>0.002,'quality_n'=>20,'long_mean'=>0.21
];
$preflightSeed['rooms']['1008']['last'] = ['time'=>$now-1800,'temp'=>21.0,
    'target'=>21.5,'valve'=>true,'temp_sample_at'=>$now-1800,
    'valve_sample_at'=>$now-1800];
$h->attrs['ThermalLearningState'] = json_encode($preflightSeed, JSON_THROW_ON_ERROR);
$h->enabled = false;
$h->preflight = true;
$h->mode = 0;
$h->ThermalLearningTick();
$observing = json_decode($h->ReadAttributeString('ThermalLearningState'), true,
    512, JSON_THROW_ON_ERROR);
smartAssert(($observing['last_processed'] ?? -1) === 5,
    'source-only mode did not collect valid source pairs');
smartAssert(($observing['last_eligible'] ?? -1) === 0,
    'preflight mode falsely permitted thermal learning');
smartAssert(!isset($observing['rooms']['1008']['last'])
    && !isset($observing['rooms']['1008']['phase']),
    'preflight mode kept or generated thermal windows');
smartAssert(($observing['rooms']['1008']['rates']['heating']['all']['n'] ?? 0) === 20,
    'preflight must preserve historical thermal statistics');
smartAssert(!isset($observing['weather_history']),
    'preflight mode unexpectedly requested or stored weather context');
smartAssert(str_contains($h->ThermalLearningDiagnosticReport(),
    'Quellen-Vorpruefung: AKTIV'), 'preflight status missing from diagnostic');
$h->mode = 1; // source acceptance is read-only, also outside HEIZEN
$h->ThermalLearningTick();
$preflightCooling = json_decode($h->ReadAttributeString('ThermalLearningState'),
    true, 512, JSON_THROW_ON_ERROR);
smartAssert(($preflightCooling['last_processed'] ?? -1) === 5
    && ($preflightCooling['last_eligible'] ?? -1) === 0,
    'preflight must work independently without heating or actuation');
$h->preflight = false;
$h->enabled = false;
$oldPreflight = $h->ReadAttributeString('ThermalLearningState');
$h->ThermalLearningTick();
smartAssert($h->ReadAttributeString('ThermalLearningState') === $oldPreflight,
    'disabling both opt-ins must stop all state writes');

echo 'SMART-FBH read-only 11-room Symcon adapter integration passed' . PHP_EOL;
echo json_encode([
    'rooms' => 11, 'success' => $state['last_processed'],
    'exclusions' => $state['last_exclusions'],
    'state_bytes' => strlen($before),
    'independent_read_errors' => count($h->debug)
], JSON_THROW_ON_ERROR) . PHP_EOL;
