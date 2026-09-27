<?php
declare(strict_types=1);

$php = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
if ($php === false) {
    fwrite(STDERR, "module.php konnte nicht gelesen werden.\n");
    exit(1);
}

if (str_contains($php, 'LCN_RequestRead(') || str_contains($php, 'RequestAllTargets')) {
    fwrite(STDERR, "Eigene LCN_RequestRead-Pfade sind wieder vorhanden.\n");
    exit(1);
}

echo "RequestRead-free runtime OK\n";
