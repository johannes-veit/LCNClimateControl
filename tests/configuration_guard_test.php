<?php
declare(strict_types=1);

$php = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
$form = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/form.json');

if ($php === false || $form === false) {
    fwrite(STDERR, "Quelldateien konnten nicht gelesen werden.\n");
    exit(1);
}

$requiredPhp = [
    "LCN_VALUE_MODULE_ID = '{0102BDC9-3B85-4A11-968D-7D314DA07C06}'",
    'ModuleType',
    'S1TargetEnabled',
    'dieselbe LCN-Sendemodul-/TS-Route',
    'ist bereits dem Raum',
    "SetBuffer('ConfigurationValid'",
    "GetBuffer('ConfigurationValid') === '0'"
];

foreach ($requiredPhp as $needle) {
    if (!str_contains($php, $needle)) {
        fwrite(STDERR, "Konfigurationsschutz fehlt: $needle\n");
        exit(1);
    }
}

if (!str_contains($form, '"validModules"')) {
    fwrite(STDERR, "SelectInstance ist nicht auf das native LCN-Modul begrenzt.\n");
    exit(1);
}

echo "Configuration guard OK\n";
