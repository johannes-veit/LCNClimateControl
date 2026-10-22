<?php
declare(strict_types=1);
require_once __DIR__ . '/../LCNClimateControl/NativeInterfaceAudit.php';

const VARIABLETYPE_BOOLEAN = 0;
$GLOBALS['auditInstances'] = [
    37617=>['InstanceStatus'=>102,'ConnectionID'=>222,'ModuleInfo'=>['ModuleID'=>'LCNModule']],
    19000=>['InstanceStatus'=>102,'ConnectionID'=>37617,'ModuleInfo'=>['ModuleID'=>'LCNValue']],
    222=>['InstanceStatus'=>102,'ConnectionID'=>0,'ModuleInfo'=>['ModuleID'=>'LCNGateway']],
];
$GLOBALS['auditConfig'] = [
    37617=>['Target'=>11,'Segment'=>0],
    19000=>['S1TargetEnabled'=>true,'R1TargetEnabled'=>false,'Interval'=>60],
    222=>['Username'=>'secret-must-not-appear','Password'=>'also-secret']
];
$GLOBALS['auditVars'] = [
    34107=>['VariableType'=>2,'VariableUpdated'=>time()],
    46746=>['VariableType'=>0,'VariableUpdated'=>time()-10]
];
function IPS_InstanceExists(int $id): bool { return isset($GLOBALS['auditInstances'][$id]); }
function IPS_GetInstance(int $id): array { return $GLOBALS['auditInstances'][$id]; }
function IPS_GetConfiguration(int $id): string { return json_encode($GLOBALS['auditConfig'][$id] ?? []); }
function IPS_VariableExists(int $id): bool { return isset($GLOBALS['auditVars'][$id]); }
function IPS_GetVariable(int $id): array { return $GLOBALS['auditVars'][$id]; }
function IPS_GetParent(int $id): int { return $id===34107 ? 19000 : 0; }
function IPS_GetObjectIDByIdent(string $ident, int $parent): int
{
    if ($ident==='S1TargetLock' && $parent===19000) { return 46746; }
    throw new RuntimeException('Ident not found');
}
function GetValue(int $id): mixed { return $id===46746 ? false : 21.0; }

final class AuditMock
{
    use LCNClimateNativeInterfaceAudit;
    private function GetRuntimeRooms(): array
    {
        return [['Name'=>'EG Büro','SendModule'=>37617,'TargetVariable'=>34107]];
    }
}
$result=(new AuditMock())->AuditLCNNativeRegulatorInterfaces();
foreach ([
    'NUR LESEN','EG Büro','LCN-Sendemodul #37617','Target=11',
    'LCN-Value #19000','S1TargetEnabled=TRUE','R1TargetEnabled=FALSE',
    'S1TargetLock #46746: FALSE (frei)','R1TargetLock: NICHT VORHANDEN',
    'Sperr-/Freigabe-API lieferte bei M11 bisher zweimal FALSE'
] as $wanted) {
    if (!str_contains($result,$wanted)) {
        throw new RuntimeException('Missing audit information: '.$wanted.'\n'.$result);
    }
}
if (str_contains($result,'secret-must-not-appear') || str_contains($result,'also-secret')) {
    throw new RuntimeException('Sensitive config field was exposed');
}
echo "PASS read-only M11 R1/S1 interface topology, no secrets, absent target indicator\n";
