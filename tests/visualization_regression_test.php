<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$php = file_get_contents($root . '/LCNClimateControl/module.php');
$html = file_get_contents($root . '/LCNClimateControl/module.html');

$requiredPhp = [
    '$this->SetVisualizationType(1)',
    "MaintainVariable('Mode'",
    'MaintainVariable($heatIdent',
    'MaintainVariable($coolIdent',
    'public function GetVisualizationTile(): string',
    '$this->UpdateVisualizationValue($payload)',
    'AppendRegisteredMessage($tempID)'
];

foreach ($requiredPhp as $needle) {
    if (!str_contains($php, $needle)) {
        fwrite(STDERR, "Fehlt in module.php: $needle\n");
        exit(1);
    }
}

if (str_contains($php, '$this->SetVisualizationType(2)')) {
    fwrite(STDERR, "Fehlerhafter Visualisierungstyp 2 wieder enthalten.\n");
    exit(1);
}

$requiredHtml = [
    '/*__LCNC_INITIAL_STATE__*/',
    'function handleMessage(data)',
    "makeButton('Nicht kühlen'",
    "makeButton('Kühlen'",
    'Kühlen = FHB-Ventil geöffnet'
];
foreach ($requiredHtml as $needle) {
    if (!str_contains($html, $needle)) {
        fwrite(STDERR, "Fehlt in module.html: $needle\n");
        exit(1);
    }
}

echo "Visualization regression test OK\n";
