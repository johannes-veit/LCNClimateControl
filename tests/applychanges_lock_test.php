<?php
declare(strict_types=1);

$php = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
if ($php === false) {
    fwrite(STDERR, "module.php konnte nicht gelesen werden.\n");
    exit(1);
}

$start = strpos($php, 'public function ApplyChanges(): void');
$end = strpos($php, 'public function MessageSink', $start);
$block = substr($php, $start, $end - $start);

if (!str_contains($block, "IPS_SemaphoreEnter(\$applyLock, 5000)")) {
    fwrite(STDERR, "ApplyChanges besitzt keine exklusive Sperre.\n");
    exit(1);
}

if (!str_contains($block, 'IPS_SemaphoreLeave($applyLock)')) {
    fwrite(STDERR, "ApplyChanges gibt die Sperre nicht sicher frei.\n");
    exit(1);
}

echo "ApplyChanges lock OK\n";
