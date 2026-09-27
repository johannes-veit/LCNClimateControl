<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'library.json',
    'README.md',
    'LCNClimateControl/module.json',
    'LCNClimateControl/form.json',
    'LCNClimateControl/module.php'
];

foreach ($required as $file) {
    if (!is_file($root . DIRECTORY_SEPARATOR . $file)) {
        fwrite(STDERR, "Fehlt: $file\n");
        exit(1);
    }
}

foreach (['library.json','LCNClimateControl/module.json','LCNClimateControl/form.json'] as $file) {
    $raw = file_get_contents($root . DIRECTORY_SEPARATOR . $file);
    json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

$lib = json_decode(file_get_contents($root . '/library.json'), true, 512, JSON_THROW_ON_ERROR);
if (($lib['compatibility']['version'] ?? '') !== '9.0') {
    fwrite(STDERR, "Symcon-Kompatibilität muss 9.0 sein.\n");
    exit(1);
}

$php = file_get_contents($root . '/LCNClimateControl/module.php');
$forbidden = ['LCN_SetTargetValue', 'LCN_ShiftTargetValue', 'IPS_Sleep('];
foreach ($forbidden as $needle) {
    if (str_contains($php, $needle)) {
        fwrite(STDERR, "Verbotener Direkt-/Blockieraufruf gefunden: $needle\n");
        exit(1);
    }
}
if (!str_contains($php, "LCN_SendCommand(\$sendModule, 'TS', \$data)")) {
    fwrite(STDERR, "TS-Sendeweg fehlt.\n");
    exit(1);
}
if (str_contains($php, 'LCN_RequestRead(')) {
    fwrite(STDERR, "LCNClimateControl soll keine eigene LCN_RequestRead-Abfrage mehr auslösen.\n");
    exit(1);
}

echo "Repository integrity OK\n";
