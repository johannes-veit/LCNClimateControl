<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/LCNClimateControl/ThermalLearning.php';
function hcheck(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "HYDRAULIC FAIL: " . $message . "\n"); exit(1); }
}
$now = time() - 5400;
$GLOBALS['hyd_meta'] = [];
$links = [47771 => 9001, 22808 => 9002, 50458 => 9003, 28351 => 9004, 16537 => 9005];
foreach ($links as $link => $source) {
    $GLOBALS['hyd_meta'][$source] = ['v'=>20.0, 'at'=>$now-10, 'type'=>2];
}
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['hyd_meta'][$id]); }
function IPS_ObjectExists(int $id): bool { return isset($GLOBALS['links'][$id]); }
function IPS_GetObject(int $id): array { return ['ObjectType' => 6]; }
function IPS_GetLink(int $id): array { return ['TargetID' => $GLOBALS['links'][$id]]; }
function IPS_GetVariable(int $id): array {
    return ['VariableUpdated' => $GLOBALS['hyd_meta'][$id]['at'],
        'VariableType' => $GLOBALS['hyd_meta'][$id]['type']];
}
function GetValue(int $id): mixed { return $GLOBALS['hyd_meta'][$id]['v']; }
$GLOBALS['links'] = $links;
final class HStub {
    use LCNClimateThermalLearning;
    public function ReadPropertyInteger(string $k): int {
        return ['ThermalHeatSupplyLink'=>47771, 'ThermalHeatReturnLink'=>22808,
            'ThermalFbhAfterExchangerLink'=>50458,
            'ThermalEwtBeforeExchangerLink'=>28351,
            'ThermalEwtAfterExchangerLink'=>16537][$k] ?? 0;
    }
    public function snapshot(array $old, int $t): array {
        return $this->ThermalUpdateHydraulics($old, $t);
    }
}
$h = new HStub();
$state = [];
$values = [
    [26.0,24.0,23.8,15.8,21.5],
    [27.0,24.5,24.0,15.5,21.3],
    [28.0,25.0,24.8,15.6,21.4],
];
for ($i=0; $i<3; $i++) {
    $t=$now + 1800*$i;
    foreach (array_values($links) as $j=>$source) {
        $GLOBALS['hyd_meta'][$source]['v']=$values[$i][$j];
        $GLOBALS['hyd_meta'][$source]['at']=$t-10;
    }
    $state = $h->snapshot($state, $t);
}
hcheck($state['supply']['source'] === 9001, 'link must resolve to actual variable, not link');
hcheck($state['supply']['count'] === 3, 'three bounded half-hour points');
hcheck(abs($state['supply']['mean60'] - 27.0) < 0.001, '60-minute heating supply average');
hcheck(abs($state['return']['mean60'] - 24.5) < 0.001, 'return average');
hcheck(abs($state['ewt_before_wt']['mean60'] - 15.633) < 0.01, 'EWT before exchanger separate');
hcheck(count($state) === 5, 'all five named channels');
hcheck(count($state['supply']['points']) <= 4, 'bounded ring');

// Stale or wrong-type water values must NEVER count as fresh observations.
$GLOBALS['hyd_meta'][9001]['at'] = $now - 10000;
$stale = $h->snapshot($state, $now+5400);
hcheck($stale['supply']['mean60'] === null && $stale['supply']['count'] === 0,
    'old supply must not be treated as valid 60m mean');
$GLOBALS['hyd_meta'][9001]['at'] = $now+5400-10;
$GLOBALS['hyd_meta'][9001]['type'] = 0;
$wrongType = $h->snapshot($state, $now+5400);
hcheck($wrongType['supply']['mean60'] === null, 'boolean supply excluded');
$GLOBALS['hyd_meta'][9001]['type'] = 2;

// Changing the source behind a link must discard old historical observations.
$GLOBALS['links'][47771] = 9011;
$GLOBALS['hyd_meta'][9011] = ['v'=>29.0,'at'=>$now+5400-10,'type'=>2];
$remapped = $h->snapshot($state, $now+5400);
hcheck($remapped['supply']['source'] === 9011 && $remapped['supply']['count'] === 1
    && $remapped['supply']['mean60'] === null, 'link remap cannot combine source histories');

// Heating rates are correlated with fresh 60-min flow/return context,
// but only when heating windows have genuine room and relay evidence.
$t = 1700000000;
$room = [];
for ($i=0;$i<3;$i++) {
    $v = LCNThermalLearningModel::observe($room, [
        'time'=>$t + $i*1800, 'temp'=>20.0+$i*0.2, 'target'=>22.0,
        'valve'=>true, 'outside'=>6.0,
        'temp_sample_at'=>$t+$i*1800, 'valve_sample_at'=>$t+$i*1800,
        'supply60'=>27.0, 'return60'=>24.5
    ]);
    $room=$v;
}
hcheck(($room['rates']['heating']['all']['n'] ?? 0) === 1,
    'legacy heating learning remains enabled');
hcheck(($room['heating_by_supply']['supply_26_30']['n'] ?? 0) === 1,
    'heating classified by real FBH supply');
hcheck(abs((float)($room['heating_drive']['mean']??0)-0.4/6.8)<0.01,
    'rate normalized by supply-room temperature difference');
hcheck(abs((float)($room['heating_spread']['mean']??0)-2.5)<0.001,
    'measured supply-return spread retained for valid window');
$compacted = LCNThermalLearningModel::compactRoom($room);
hcheck(isset($compacted['heating_drive'],$compacted['heating_spread'],
    $compacted['heating_by_supply']), 'hydraulics survives compact state persistence');
hcheck(!isset($compacted['extra_unbounded_data']), 'no unknown payload preserved');

// No valid supply -> no invented hydraulic heat-effect model, while
// baseline room-rate learning remains possible.
$room=[];
for ($i=0;$i<3;$i++) {
    $room=LCNThermalLearningModel::observe($room, [
        'time'=>$t+$i*1800,'temp'=>20+$i*0.2,
        'target'=>22,'valve'=>true,'outside'=>6,
        'supply60'=>null,'return60'=>null,
        'temp_sample_at'=>$t+$i*1800,'valve_sample_at'=>$t+$i*1800
    ]);
}
hcheck(($room['rates']['heating']['all']['n']??0)===1
    && !isset($room['heating_by_supply']),
    'missing hydraulics never block legacy safe room learning');
$module = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
hcheck(str_contains($module, "RegisterPropertyBoolean('ThermalLearnAllRooms', true)"),
    'existing EG Bad installation auto-upgrades to all-room learning by default');
hcheck(str_contains($module, "RegisterPropertyInteger('ThermalHeatSupplyLink', 47771)"),
    'heating supply is actual FBH link, not EWT source');
$learning = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/ThermalLearning.php');
hcheck(str_contains($learning, "ReadPropertyBoolean('ThermalLearnAllRooms')")
    && str_contains($learning, "'supply60' => \\$supply60"),
    'all rooms receive hydraulic learning context');
hcheck(!preg_match('/\\b(?:LCN_SendCommand|LCN_SetRelay|LCN_SwitchRelay|RequestAction|IPS_RequestAction)\\s*\\(/', $learning),
    'hydraulic reader must never send bus actions');
echo "SMART-FBH all-room and rolling hydronic read-only simulation passed\n";
