<?php
declare(strict_types=1);

$php = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
if ($php === false) {
    fwrite(STDERR, "module.php konnte nicht gelesen werden.\n");
    exit(1);
}

$start = strpos($php, 'public function Worker(): void');
$end = strpos($php, 'public function Abort(): void', $start);
$block = substr($php, $start, $end - $start);

$required = [
    'catch (Throwable $e)',
    "SendDebug('Worker-Exception'",
    'SetRoomError',
    'ClearPendingMode',
    'RefreshRuntimeStatus'
];

foreach ($required as $needle) {
    if (!str_contains($block, $needle)) {
        fwrite(STDERR, "Worker-Ausnahmeschutz fehlt: $needle\n");
        exit(1);
    }
}

echo "Worker exception guard OK\n";
