<?php
declare(strict_types=1);

$php = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
if ($php === false) {
    fwrite(STDERR, "module.php konnte nicht gelesen werden.\n");
    exit(1);
}

$start = strpos($php, 'private function FailCurrentJob(array $Job, string $Reason): void');
$end = strpos($php, 'private function HasJobForTarget', $start);
$block = substr($php, $start, $end - $start);

$required = [
    'SetRoomError',
    'RefreshRuntimeStatus',
    "SetBuffer('CurrentJob', '')"
];

foreach ($required as $needle) {
    if (!str_contains($block, $needle)) {
        fwrite(STDERR, "Raumfehler-Isolation fehlt: $needle\n");
        exit(1);
    }
}

if (str_contains($block, 'SetQueue([])') || str_contains($block, "SetBuffer('Queue', '[]')")) {
    fwrite(STDERR, "Ein Raumfehler löscht weiterhin die komplette Queue.\n");
    exit(1);
}

echo "Room error isolation OK\n";
