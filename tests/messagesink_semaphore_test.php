<?php
declare(strict_types=1);

$php = file_get_contents(dirname(__DIR__) . '/LCNClimateControl/module.php');
if ($php === false) {
    fwrite(STDERR, "module.php konnte nicht gelesen werden.\n");
    exit(1);
}

$start = strpos($php, 'public function MessageSink');
$end = strpos($php, 'public function RequestAction', $start);
$block = substr($php, $start, $end - $start);

$required = [
    "IPS_SemaphoreEnter('LCNC_' . \$this->InstanceID, 2000)",
    "IPS_SemaphoreLeave('LCNC_' . \$this->InstanceID)",
    '$jobOwnsTarget = $this->HasJobForTarget($SenderID);'
];

foreach ($required as $needle) {
    if (!str_contains($block, $needle)) {
        fwrite(STDERR, "MessageSink-Synchronisierung fehlt: $needle\n");
        exit(1);
    }
}

echo "MessageSink semaphore OK\n";
