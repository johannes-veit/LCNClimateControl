<?php
declare(strict_types=1);

$php = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
if ($php === false) {
    fwrite(STDERR, "module.php konnte nicht gelesen werden.\n");
    exit(1);
}

$required = [
    'private function YieldCurrentJob(array $Job): void',
    'private function DiscardCurrentJob(array $Job): void',
    "SetTimerInterval('Worker', 250)",
    '$this->YieldCurrentJob($Job);',
    'Kein automatisches LCN_RequestRead mehr'
];

foreach ($required as $needle) {
    if (!str_contains($php, $needle)) {
        fwrite(STDERR, "Round-robin-Merkmal fehlt: $needle\n");
        exit(1);
    }
}

$start = strpos($php, 'private function ProcessJob(array $Job): void');
$end = strpos($php, 'private function CompleteCurrentJob', $start);
$process = substr($php, $start, $end - $start);

if (str_contains($process, 'RequestTargetRead(') || str_contains($process, 'LCN_RequestRead(')) {
    fwrite(STDERR, "Automatische Endlagenerkennung verwendet noch LCN_RequestRead.\n");
    exit(1);
}

echo "Round-robin scheduler OK\n";
