<?php
declare(strict_types=1);
require_once __DIR__ . '/../LCNClimateControl/LockRecovery.php';

const VARIABLETYPE_FLOAT = 2;
const VARIABLETYPE_BOOLEAN = 0;

$GLOBALS['mockVariables'] = [];
$GLOBALS['mockValues'] = [];
$GLOBALS['mockParents'] = [];
$GLOBALS['mockIdents'] = [];

function IPS_SemaphoreEnter(string $key, int $timeout): bool { return true; }
function IPS_SemaphoreLeave(string $key): void {}
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['mockVariables'][$id]); }
function IPS_GetVariable(int $id): array { return $GLOBALS['mockVariables'][$id]; }
function IPS_GetParent(int $id): int { return $GLOBALS['mockParents'][$id] ?? 0; }
function IPS_GetObject(int $id): array { return ['ObjectIdent' => $GLOBALS['mockIdents'][$id] ?? '']; }
function GetValue(int $id): mixed { return $GLOBALS['mockValues'][$id] ?? null; }

final class MockClimate
{
    use LCNClimateLockRecovery;
    public int $InstanceID = 17597;
    public int $mode = 0;
    public bool $busy = false;
    public array $attrs = [];
    public array $mappings = [];
    public array $rooms = [];
    public bool $coolingDesired = false;

    public function GetMode(): int { return $this->mode; }
    private function IsBusy(): bool { return $this->busy; }
    private function M11ProbeActive(): bool { return false; }
    private function GetPendingMode(): ?int { return null; }
    private function GetPendingCoolingRequests(): array { return []; }
    private function GetBuffer(string $key): string { return $key === 'ConfigurationValid' ? '1' : ''; }
    private function GetRuntimeRooms(): array { return $this->rooms; }
    private function ReadJsonAttribute(string $key): array { return $this->attrs[$key] ?? []; }
    private function WriteJsonAttribute(string $key, array $value): void { $this->attrs[$key] = $value; }
    private function GetStoredCoolingState(int $target): bool { return $this->coolingDesired; }
    private function AutoCoolingReadOnlyDiscovery(array $room): ?array
    {
        return $this->mappings[(int) $room['TargetVariable']] ?? null;
    }
    private function DirectCoolingSetLockState(int $target, string $state): void
    {
        if ($state === '') {
            unset($this->attrs['DirectCoolingLocks'][(string) $target]);
            unset($this->attrs['DirectCoolingLockMappings'][(string) $target]);
        } else {
            $this->attrs['DirectCoolingLocks'][(string) $target] = $state;
        }
    }
    private function DirectCoolingFallbackSet(int $target, string $reason): void
    {
        unset($this->attrs['CoolingFallbackReasons'][(string) $target]);
    }
    private function ClearRoomError(int $target): void {}
    private function RefreshRuntimeStatus(): void {}
    private function UpdateChatGPTDiagnostic(): void {}
    private function PushVisualizationState(): void {}
}

function check(bool $value, string $why): void
{
    if (!$value) {
        throw new RuntimeException('TEST FEHLGESCHLAGEN: ' . $why);
    }
}

function fixture(): MockClimate
{
    $now = time();
    $m = new MockClimate();
    foreach ([
        [58217, 24239, 24994, 59365, 'OG Ankleide / HWR'],
        [15371, 31648, 49744, 55019, 'OG Flur']
    ] as [$target, $lock, $relay, $parent, $name]) {
        $m->rooms[] = ['TargetVariable' => $target, 'Name' => $name];
        $mapping = [
            'TargetVariable' => $target,
            'RelayModule' => 25610,
            'RelayUnit' => $target === 58217 ? 44871 : 25196,
            'RelayChannel' => $target === 58217 ? 1 : 5,
            'RegulatorIndex' => 2,
            'UnlockedVariable' => $lock,
            'RelayStatusVariable' => $relay,
            'LockFeedbackIsLocked' => true,
            'OpenRelayOn' => true
        ];
        $m->mappings[$target] = $mapping;
        $m->attrs['DirectCoolingLocks'][(string) $target] = 'uncertain';
        $m->attrs['DirectCoolingLockMappings'][(string) $target] = $mapping;
        $m->attrs['CoolingFallbackReasons'][(string) $target] =
            ['kind' => 'blocked', 'time' => $now - 300, 'reason' => 'Reglerzustand ungewiss'];
        foreach ([$target, $lock, $relay] as $id) {
            $GLOBALS['mockVariables'][$id] = [
                'VariableUpdated' => $now - 30,
                'VariableType' => $id === $target ? VARIABLETYPE_FLOAT : VARIABLETYPE_BOOLEAN
            ];
        }
        $GLOBALS['mockValues'][$target] = 21.0;
        $GLOBALS['mockValues'][$lock] = false;
        $GLOBALS['mockValues'][$relay] = false;
        $GLOBALS['mockParents'][$target] = $parent;
        $GLOBALS['mockParents'][$lock] = $parent;
        $GLOBALS['mockIdents'][$lock] = 'S1TargetLock';
    }
    // Proven 'locked' markers may NEVER be cleared by this action.
    $m->attrs['DirectCoolingLocks']['21099'] = 'locked';
    return $m;
}

$a = fixture();
$r = $a->ReconcileUncertainCoolingLocks();
check(str_contains($r, 'Bereinigt: 2; nicht bereinigt: 0'), 'both rooms should reconcile');
check(count($a->attrs['DirectCoolingLocks']) === 1
    && $a->attrs['DirectCoolingLocks']['21099'] === 'locked', 'other lock marker retained');
echo "PASS positive reconciliation and locked marker preservation\n";

$b = fixture();
$GLOBALS['mockVariables'][24239]['VariableUpdated'] = time() - 500;
$r = $b->ReconcileUncertainCoolingLocks();
check(str_contains($r, 'NICHT BEREINIGT'), 'stale feedback rejected');
check(isset($b->attrs['DirectCoolingLocks']['58217']), 'stale marker retained');
echo "PASS stale feedback fail-closed\n";

$c = fixture();
$GLOBALS['mockValues'][49744] = true;
$r = $c->ReconcileUncertainCoolingLocks();
check(isset($c->attrs['DirectCoolingLocks']['15371']), 'relay ON rejected');
echo "PASS energized relay fail-closed\n";

$d = fixture();
$d->mappings[58217]['RelayChannel'] = 8;
$r = $d->ReconcileUncertainCoolingLocks();
check(isset($d->attrs['DirectCoolingLocks']['58217']), 'changed relay mapping rejected');
echo "PASS mapping drift fail-closed\n";

$e = fixture();
$e->mode = 1;
$r = $e->ReconcileUncertainCoolingLocks();
check(str_starts_with($r, 'ABBRUCH'), 'cooling mode rejected');
echo "PASS only safe idle heating mode\n";

$f = fixture();
$GLOBALS['mockIdents'][24239] = 'UnrelatedBoolean';
$f->ReconcileUncertainCoolingLocks();
check(isset($f->attrs['DirectCoolingLocks']['58217']), 'wrong native feedback rejected');
echo "PASS native status identity validation\n";

$g = fixture();
$g->attrs['CoolingFallbackReasons']['58217']['time'] = time();
$g->ReconcileUncertainCoolingLocks();
check(isset($g->attrs['DirectCoolingLocks']['58217']), 'feedback older than incident rejected');
echo "PASS feedback newer than last incident\n";

echo "ALL 7 LOCK RECOVERY TESTS PASSED\n";
