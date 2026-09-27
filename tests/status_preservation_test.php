<?php
declare(strict_types=1);

$php = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
if ($php === false) {
    fwrite(STDERR, "module.php konnte nicht gelesen werden.\n");
    exit(1);
}

foreach (['private function StartNextJob', 'private function StartWorkerIfNeeded'] as $function) {
    $start = strpos($php, $function);
    if ($start === false) {
        fwrite(STDERR, "$function fehlt.\n");
        exit(1);
    }
    $next = strpos($php, "\n    private function ", $start + 10);
    $block = substr($php, $start, ($next ?: strlen($php)) - $start);

    if (str_contains($block, 'SetStatus(self::STATUS_ACTIVE)')) {
        fwrite(STDERR, "$function maskiert Fehlerstatus mit STATUS_ACTIVE.\n");
        exit(1);
    }
}

echo "Status preservation OK\n";
